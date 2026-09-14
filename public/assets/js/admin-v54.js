(() => {
    const body = document.body;
    const toggle = document.querySelector('[data-admin-menu-toggle]');
    const closeTargets = document.querySelectorAll('[data-admin-menu-close]');

    const setOpen = (open) => {
        body.classList.toggle('v54-menu-open', open);
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle?.addEventListener('click', () => setOpen(!body.classList.contains('v54-menu-open')));
    closeTargets.forEach((el) => el.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-filter-input]').forEach((input) => {
        const target = document.querySelector(input.getAttribute('data-filter-input'));
        if (!target) return;
        const rows = Array.from(target.querySelectorAll('[data-filter-row]'));
        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            rows.forEach((row) => {
                const haystack = (row.getAttribute('data-filter-row') || row.textContent || '').toLowerCase();
                row.hidden = query !== '' && !haystack.includes(query);
            });
        });
    });
})();
