(() => {
  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));

  // Ctrl/Cmd + K command palette.
  const palette = qs('[data-command]');
  const backdrop = qs('[data-command-close]');
  const input = qs('[data-command-input]');
  const items = qsa('[data-command-item]');
  const empty = qs('[data-command-empty]');
  let activeIndex = 0;
  const visibleItems = () => items.filter((x) => !x.hidden);
  const paintActive = () => {
    const visible = visibleItems();
    visible.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
    visible[activeIndex]?.scrollIntoView({block:'nearest'});
  };
  const filterCommands = () => {
    const query = (input?.value || '').trim().toLowerCase();
    items.forEach((item) => item.hidden = query !== '' && !(item.dataset.commandSearch || '').includes(query));
    activeIndex = 0;
    if (empty) empty.hidden = visibleItems().length !== 0;
    paintActive();
  };
  const openPalette = () => {
    if (!palette) return;
    palette.hidden = false; if (backdrop) backdrop.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(() => { input?.focus(); input?.select(); filterCommands(); }, 0);
  };
  const closePalette = () => {
    if (!palette) return;
    palette.hidden = true; if (backdrop) backdrop.hidden = true;
    document.body.style.overflow = '';
  };
  qsa('[data-command-open]').forEach((b) => b.addEventListener('click', openPalette));
  backdrop?.addEventListener('click', closePalette);
  input?.addEventListener('input', filterCommands);
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); palette?.hidden ? openPalette() : closePalette(); return; }
    if (!palette || palette.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); closePalette(); }
    if (event.key === 'ArrowDown') { event.preventDefault(); const v=visibleItems(); activeIndex=v.length ? (activeIndex+1)%v.length : 0; paintActive(); }
    if (event.key === 'ArrowUp') { event.preventDefault(); const v=visibleItems(); activeIndex=v.length ? (activeIndex-1+v.length)%v.length : 0; paintActive(); }
    if (event.key === 'Enter') { const item=visibleItems()[activeIndex]; if (item) { event.preventDefault(); item.click(); } }
  });

  // Dashboard widget customization.
  const grid = qs('[data-dashboard-grid]');
  const customizer = qs('[data-dashboard-customizer]');
  const customizeButton = qs('[data-dashboard-customize]');
  const saveButton = qs('[data-dashboard-save]');
  const resetButton = qs('[data-dashboard-reset]');
  const widgetChecks = qsa('[data-widget-toggle]');
  let customizing = false;
  let dragging = null;

  const syncWidgetVisibility = () => {
    widgetChecks.forEach((cb) => {
      const widget = qs(`[data-dashboard-widget="${CSS.escape(cb.value)}"]`);
      widget?.classList.toggle('v55-widget-hidden', !cb.checked && !customizing);
      if (customizing && widget) widget.style.display = cb.checked ? '' : 'none';
    });
  };
  const setCustomizing = (on) => {
    customizing = on;
    customizer?.classList.toggle('is-open', on);
    grid?.classList.toggle('v55-dashboard-customizing', on);
    qsa('[data-dashboard-widget]').forEach((w) => w.draggable = on);
    syncWidgetVisibility();
    if (!on) qsa('[data-dashboard-widget]').forEach((w) => w.style.display = '');
    syncWidgetVisibility();
  };
  customizeButton?.addEventListener('click', () => setCustomizing(!customizing));
  widgetChecks.forEach((cb) => cb.addEventListener('change', syncWidgetVisibility));

  if (grid) {
    grid.addEventListener('dragstart', (e) => {
      const w = e.target.closest('[data-dashboard-widget]'); if (!customizing || !w) return;
      dragging = w; w.classList.add('is-dragging'); e.dataTransfer.effectAllowed='move';
    });
    grid.addEventListener('dragend', () => { dragging?.classList.remove('is-dragging'); dragging=null; qsa('.v55-drag-target').forEach(x=>x.classList.remove('v55-drag-target')); });
    grid.addEventListener('dragover', (e) => {
      if (!dragging) return; e.preventDefault();
      const target = e.target.closest('[data-dashboard-widget]');
      qsa('.v55-drag-target').forEach(x=>x.classList.remove('v55-drag-target'));
      if (target && target !== dragging) target.classList.add('v55-drag-target');
    });
    grid.addEventListener('drop', (e) => {
      if (!dragging) return; e.preventDefault();
      const target = e.target.closest('[data-dashboard-widget]'); if (!target || target===dragging) return;
      const rect = target.getBoundingClientRect();
      grid.insertBefore(dragging, e.clientY < rect.top + rect.height/2 ? target : target.nextSibling);
    });
  }

  const persistLayout = async (reset=false) => {
    if (!grid) return;
    const order = qsa('[data-dashboard-widget]', grid).map((w)=>w.dataset.dashboardWidget);
    const hidden = widgetChecks.filter((cb)=>!cb.checked).map((cb)=>cb.value);
    const token = qs('[data-dashboard-csrf]')?.value || '';
    const body = new URLSearchParams({csrf_token:token, action:reset?'reset':'save', order:JSON.stringify(order), hidden:JSON.stringify(hidden)});
    const response = await fetch('/admin/dashboard-layout.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});
    if (!response.ok) throw new Error('Could not save dashboard layout.');
    const data = await response.json(); if (!data.ok) throw new Error(data.error || 'Could not save dashboard layout.');
    if (reset) window.location.reload();
    setCustomizing(false);
    if (saveButton) { const old=saveButton.textContent; saveButton.textContent='Saved'; setTimeout(()=>saveButton.textContent=old,1300); }
  };
  saveButton?.addEventListener('click', () => persistLayout(false).catch((e)=>alert(e.message)));
  resetButton?.addEventListener('click', () => { if (confirm('Reset dashboard widgets to the default layout?')) persistLayout(true).catch((e)=>alert(e.message)); });

  // Maintenance template helper.
  const templateSelect = qs('[data-maint-template-select]');
  templateSelect?.addEventListener('change', () => {
    const opt = templateSelect.selectedOptions[0]; if (!opt || !opt.dataset.template) return;
    try {
      const data = JSON.parse(opt.dataset.template);
      const form = templateSelect.closest('form') || document;
      const scope = qs('[name="scope"]', form);
      if (scope && data.scope != null) { scope.value = data.scope; scope.dispatchEvent(new Event('change',{bubbles:true})); }
      ['title','details','active_status','after_status'].forEach((name) => {
        const field = qs(`[name="${CSS.escape(name)}"]`, form); if (!field || data[name] == null) return;
        field.value = data[name]; field.dispatchEvent(new Event('change',{bubbles:true}));
      });
      if (data.target_id) {
        const target = qs('[name="target_id"]:not(:disabled)', form);
        if (target) target.value = String(data.target_id);
      }
      const start = qs('[name="start_at"]', form); const end = qs('[name="end_at"]', form);
      const duration = Number(data.duration_minutes) || 0;
      if (form instanceof HTMLElement) form.dataset.templateDuration = String(duration);
      const setTemplateEnd = () => {
        if (!start || !end || !start.value || duration <= 0) return;
        const d = new Date(start.value); d.setMinutes(d.getMinutes()+duration);
        const pad=(n)=>String(n).padStart(2,'0');
        end.value=`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
      };
      setTemplateEnd();
    } catch (_) {}
  });
  const scheduleForm = templateSelect?.closest('form');
  const scheduleStart = scheduleForm ? qs('[name="start_at"]', scheduleForm) : null;
  scheduleStart?.addEventListener('change', () => {
    const duration = Number(scheduleForm?.dataset.templateDuration || 0);
    const end = scheduleForm ? qs('[name="end_at"]', scheduleForm) : null;
    if (!end || !scheduleStart.value || duration <= 0) return;
    const d = new Date(scheduleStart.value); d.setMinutes(d.getMinutes()+duration);
    const pad=(n)=>String(n).padStart(2,'0');
    end.value=`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  });
})();

// v5.5.2 compact sidebar section state.
(() => {
  const sections = Array.from(document.querySelectorAll('[data-nav-section]'));
  if (!sections.length) return;
  const storageKey = 'farebros-status-admin-nav-v552';
  let saved = {};
  try { saved = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (_) { saved = {}; }

  const apply = (section, open) => {
    section.classList.toggle('is-open', !!open);
    const button = section.querySelector('.v55-nav-section-toggle');
    if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  const persist = () => {
    const state = {};
    sections.forEach((section) => { state[section.dataset.navSection || 'section'] = section.classList.contains('is-open'); });
    try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) {}
  };

  sections.forEach((section) => {
    const key = section.dataset.navSection || 'section';
    const hasActive = !!section.querySelector('a.active');
    const defaultOpen = section.dataset.navDefaultOpen === '1';
    // Active pages always win over stored state. Server-rendered state is also
    // respected so the menu remains usable before JavaScript initializes.
    const serverOpen = section.classList.contains('is-open');
    const desired = hasActive || serverOpen || (Object.prototype.hasOwnProperty.call(saved, key) ? !!saved[key] : defaultOpen);
    apply(section, desired);
    section.querySelector('.v55-nav-section-toggle')?.addEventListener('click', () => {
      apply(section, !section.classList.contains('is-open'));
      persist();
    });
  });
})();
