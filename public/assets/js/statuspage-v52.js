let lastPayload = null;

function parseStatusDate(value){
    if(!value) return null;
    const raw=String(value).trim();
    const normalized=/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? raw.replace(' ','T')+'Z' : raw;
    const d=new Date(normalized);
    return Number.isNaN(d.getTime()) ? null : d;
}

function formatDate(value,data=lastPayload){
    const d=parseStatusDate(value);
    if(!d) return value || '';
    const opts={month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'};
    if(data?.timezone) opts.timeZone=data.timezone;
    opts.hour12=(data?.settings?.time_format||'12h')!=='24h';
    try{return new Intl.DateTimeFormat([],opts).format(d);}catch(_){return d.toLocaleString();}
}

function escapeHtml(v){
    return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}

function statusPageMessage(data){
    const schedule=data.schedule;
    if(schedule?.phase==='active') return 'A scheduled maintenance window is currently active.';
    if(schedule?.phase==='upcoming') return 'A scheduled maintenance window is upcoming. Current live status is still shown below.';
    if(data.overall?.status==='operational') return 'All monitored systems are operational.';
    if(data.primary?.status!=='operational') return 'The primary Fare Brothers website is reporting an issue.';
    return 'One or more monitored systems are reporting an issue.';
}

function renderRow(item,isWebsite,data){
    const name=isWebsite?`${escapeHtml(item.name)}${item.is_primary?' · Primary':''}`:escapeHtml(item.name);
    const desc=isWebsite?escapeHtml(item.url):escapeHtml(item.description);
    const small=isWebsite?(item.last_checked_at_iso?'Checked '+formatDate(item.last_checked_at_iso,data):'Waiting for first check'):`Code ${escapeHtml(item.code)}`;
    const overlay=item.schedule_overlay?`<span class="sp-maint-badge ${escapeHtml(item.schedule_overlay.phase)}">${escapeHtml(item.schedule_overlay.label)}</span>`:'';
    return `<article class="sp-row ${escapeHtml(item.class)}"><div class="sp-row-main"><span class="sp-dot"></span><div><strong>${name}</strong><p>${desc}</p>${overlay}</div></div><div class="sp-row-status"><strong>${escapeHtml(item.label)}</strong><small>${small}</small></div></article>`;
}

function renderNotice(data){
    const region=document.getElementById('statusNoticeRegion');
    if(!region) return;
    region.innerHTML=data.message?`<section class="sp-notice"><strong>Status Message</strong><p>${escapeHtml(data.message)}</p></section>`:'';
}

function renderSchedule(data){
    const region=document.getElementById('scheduleRegion');
    if(!region) return;
    const s=data.schedule;
    if(!s){region.innerHTML='';return;}
    const title=s.phase==='active'?'Maintenance In Progress':'Upcoming Maintenance';
    region.innerHTML=`<section class="sp-schedule ${escapeHtml(s.phase)}"><div><strong>${title}</strong><h2>${escapeHtml(s.title||'Scheduled Maintenance')}</h2><p>${escapeHtml(s.details||'')}</p></div><div class="sp-schedule-time"><span>${escapeHtml(formatDate(s.start_at_iso||s.start_at,data))}</span><em>to</em><span>${escapeHtml(formatDate(s.end_at_iso||s.end_at,data))}</span></div></section>`;
}

function renderAnnouncements(data){
    const target=document.getElementById('announcementList');
    if(!target) return;
    if(!data.announcements?.length){target.innerHTML='<p class="sp-empty">No active announcements.</p>';return;}
    target.innerHTML=data.announcements.map(item=>`<article class="sp-update"><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.body).replaceAll('\n','<br>')}</p><small>${escapeHtml(formatDate(item.created_at,data))}</small></article>`).join('');
}

function renderUpdates(data){
    const target=document.getElementById('updateList');
    if(!target) return;
    if(!data.recent_updates?.length){target.innerHTML='<p class="sp-empty">No recent updates.</p>';return;}
    target.innerHTML=data.recent_updates.map(item=>`<article class="sp-update"><strong>${escapeHtml(item.update_title)}</strong><p>${escapeHtml(item.update_message||'').replaceAll('\n','<br>')}</p><small>${escapeHtml(item.service_name||'General')} · ${escapeHtml(formatDate(item.created_at,data))}</small></article>`).join('');
}

function updateSummary(data){
    const card=document.getElementById('overallStatusCard');
    if(card) card.className=`sp-status-card ${data.overall.class}`;
    const set=(id,value)=>{const el=document.getElementById(id);if(el) el.textContent=value;};
    set('overallStatusLabel',data.overall.label);
    set('overallStatusMessage',statusPageMessage(data));
    set('overallStatusCode',String(data.overall.code));
    set('overallUpdatedAt',formatDate(data.generated_at,data));
    set('overallTimezone',data.timezone_label||data.timezone||'');
    set('primaryWebsiteName',data.primary.name);
    const primaryPill=document.getElementById('primaryWebsiteStatus');
    if(primaryPill){primaryPill.className=`sp-pill ${data.primary.class}`;primaryPill.innerHTML=`<i></i>${escapeHtml(data.primary.label)}`;}
    const operationalServices=(data.services||[]).filter(x=>x.status==='operational').length;
    const operationalWebsites=(data.websites||[]).filter(x=>x.status==='operational').length;
    set('systemsCount',String((data.services||[]).length));
    set('systemsSub',`${operationalServices} operational`);
    set('websitesCount',String((data.websites||[]).length));
    set('websitesSub',`${operationalWebsites} operational`);
    const checks=(data.websites||[]).map(x=>x.last_checked_at_iso).filter(Boolean).map(v=>parseStatusDate(v)).filter(Boolean).sort((a,b)=>b-a);
    set('lastWebsiteCheck',checks.length?formatDate(checks[0].toISOString(),data):'Pending');
    set('liveRefreshLabel',`Live monitoring · refreshes every ${data.refresh_seconds||window.STATUS_REFRESH_SECONDS||15}s`);
}

function applyPayload(data){
    lastPayload=data;
    updateSummary(data);
    renderNotice(data);
    renderSchedule(data);
    renderAnnouncements(data);
    renderUpdates(data);
    const serviceGrid=document.getElementById('serviceGrid');
    const websiteTable=document.getElementById('websiteTable');
    if(serviceGrid) serviceGrid.innerHTML=(data.services||[]).map(service=>renderRow(service,false,data)).join('');
    if(websiteTable) websiteTable.innerHTML=(data.websites||[]).map(site=>renderRow(site,true,data)).join('');
}

async function refreshStatus(){
    try{
        const response=await fetch('/api/status.php?ts='+Date.now(),{cache:'no-store',headers:{'Accept':'application/json'}});
        if(!response.ok) return;
        applyPayload(await response.json());
    }catch(e){console.warn('Status refresh failed',e);}
}

const interval=Math.max(5,Number(window.STATUS_REFRESH_SECONDS||15))*1000;
setInterval(refreshStatus,interval);
