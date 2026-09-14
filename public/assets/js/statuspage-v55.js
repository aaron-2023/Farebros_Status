let lastPayload = null;
let currentHistoryDays = Number(window.STATUS_HISTORY_DAYS||90);

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

function incidentStatusLabel(status){
    return ({investigating:'Investigating',identified:'Identified',monitoring:'Monitoring',resolved:'Resolved'})[status]||'Investigating';
}

function incidentImpactLabel(impact){
    return ({critical:'Critical',major:'Major',minor:'Minor'})[impact]||'Minor';
}

function incidentTargetsLabel(targets=[]){
    const names=targets.map(t=>t.target_name).filter(Boolean);
    return names.length?names.join(', '):'General status';
}

function statusPageMessage(data){
    const incidents=data.incidents?.active||[];
    if(incidents.length===1) return 'One active incident is currently being tracked.';
    if(incidents.length>1) return `${incidents.length} active incidents are currently being tracked.`;
    const schedule=data.schedule;
    if(schedule?.phase==='active') return 'A scheduled maintenance window is currently active.';
    if(schedule?.phase==='upcoming') return 'A scheduled maintenance window is upcoming. Current live status is still shown below.';
    if(data.overall?.status==='operational') return 'All monitored systems are operational.';
    if(data.primary?.status!=='operational') return 'The primary Fare Brothers website is reporting an issue.';
    return 'One or more monitored systems are reporting an issue.';
}

function renderServiceRow(item,data){
    const overlay=item.schedule_overlay?`<span class="sp-maint-badge ${escapeHtml(item.schedule_overlay.phase)}">${escapeHtml(item.schedule_overlay.label)}</span>`:'';
    const dependency=item.dependency_affected?'<span class="v55-dependency-badge">Upstream dependency</span>':'';
    return `<article class="sp-row ${escapeHtml(item.class)}"><div class="sp-row-main"><span class="sp-dot"></span><div><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml(item.description)}</p>${overlay}${dependency}</div></div><div class="sp-row-status"><strong>${escapeHtml(item.label)}</strong><small>Code ${escapeHtml(item.code)}</small></div></article>`;
}

function sparklinePoints(series=[],width=480,height=72){
    if(!series.length) return '';
    const values=series.map(row=>Number(row.avg_ms||0));
    const max=Math.max(1,...values);
    return values.map((value,i)=>{
        const x=values.length<=1?0:(i/(values.length-1))*width;
        const y=height-((value/max)*(height-10))-5;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
}

function historyBars(history=[]){
    return history.map((day,i)=>{
        const uptime=day.uptime===null||day.uptime===undefined?'No data':`${Number(day.uptime).toFixed(3)}% uptime`;
        const avg=day.avg_ms!==null&&day.avg_ms!==undefined?` · ${day.avg_ms}ms avg`:'';
        return `<span class="${escapeHtml(day.class||'empty')}" data-history-index="${i}" title="${escapeHtml(day.date)} · ${escapeHtml(uptime)}${escapeHtml(avg)}"></span>`;
    }).join('');
}

function renderWebsiteRow(item,data){
    const overlay=item.schedule_overlay?`<span class="sp-maint-badge ${escapeHtml(item.schedule_overlay.phase)}">${escapeHtml(item.schedule_overlay.label)}</span>`:'';
    const dependency=item.dependency_affected?'<span class="v55-dependency-badge">Upstream dependency</span>':'';
    const points=sparklinePoints(item.response_series||[]);
    const graph=points?`<svg viewBox="0 0 480 72" preserveAspectRatio="none"><polyline points="${points}" fill="none" vector-effect="non-scaling-stroke"/></svg>`:'<span>No response data yet</span>';
    const checked=item.last_checked_at_iso?`Checked ${escapeHtml(formatDate(item.last_checked_at_iso,data))}`:'Waiting for first check';
    const uptime30=item.uptime_30===null||item.uptime_30===undefined?'—':`${Number(item.uptime_30).toFixed(3)}%`;
    const uptime90=item.uptime_90===null||item.uptime_90===undefined?'—':`${Number(item.uptime_90).toFixed(3)}%`;
    const response=item.last_response_ms===null||item.last_response_ms===undefined?'—':`${Number(item.last_response_ms)}ms`;
    return `<article class="sp-row sp-row-monitor ${escapeHtml(item.class)}">
        <div class="sp-row-monitor-top"><div class="sp-row-main"><span class="sp-dot"></span><div><strong>${escapeHtml(item.name)}${item.is_primary?' · Primary':''}</strong><p>${escapeHtml(item.url)}</p>${overlay}${dependency}</div></div><div class="sp-row-status"><strong>${escapeHtml(item.label)}</strong><small>${checked}</small></div></div>
        <div class="sp-monitor-detail">
            <div class="sp-monitor-metrics"><span><small>30d uptime</small><strong>${escapeHtml(uptime30)}</strong></span><span><small>90d uptime</small><strong>${escapeHtml(uptime90)}</strong></span><span><small>Last response</small><strong>${escapeHtml(response)}</strong></span></div>
            <div class="sp-response-chart"><div><small>Response time · 24h</small>${graph}</div></div>
            <div class="sp-uptime-bars" data-history-bars>${historyBars(item.history||[])}</div>
        </div>
    </article>`;
}


function renderGroups(data){
    const target=document.getElementById('statusGroupGrid');
    if(!target) return;
    const section=document.getElementById('groups');
    const count=document.getElementById('statusGroupCount');
    const groups=data.groups||[];
    if(section) section.hidden=groups.length===0;
    if(count) count.textContent=`${groups.length} group${groups.length===1?'':'s'}`;
    if(!groups.length){target.innerHTML='';return;}
    target.innerHTML=groups.map(group=>{
        const meta=group.status_meta||{class:'good',label:'Operational'};
        const members=(group.members||[]).map(member=>`<div class="v55-group-member"><span class="sp-dot ${escapeHtml(member.status_meta?.class||'good')}"></span><div><strong>${escapeHtml(member.name)}</strong><small>${escapeHtml(member.status_meta?.label||member.status||'Operational')}${member.dependency_affected?' · upstream dependency':''}</small></div></div>`).join('');
        return `<article class="v55-public-group ${escapeHtml(meta.class||'good')}"><div class="v55-public-group-head"><div><h3>${escapeHtml(group.group_name)}</h3><p>${escapeHtml(group.description||'')}</p></div><span>${escapeHtml(meta.label||'Operational')}</span></div><div class="v55-group-members-public">${members||'<small>No members</small>'}</div></article>`;
    }).join('');
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

function renderIncidents(data){
    const activeTarget=document.getElementById('incidentRegion');
    const historyTarget=document.getElementById('incidentHistory');
    const active=data.incidents?.active||[];
    const recent=data.incidents?.recent||[];
    if(activeTarget){
        activeTarget.innerHTML=active.map(incident=>`<article class="sp-incident ${escapeHtml(incident.impact)}"><div class="sp-incident-head"><div><span class="sp-incident-badge">${escapeHtml(incidentImpactLabel(incident.impact))} incident</span><h2>${escapeHtml(incident.title)}</h2><p>${escapeHtml(incidentTargetsLabel(incident.targets))}</p></div><strong>${escapeHtml(incidentStatusLabel(incident.status))}</strong></div><div class="sp-incident-timeline">${(incident.updates||[]).slice(0,4).map(update=>`<div><span>${escapeHtml(incidentStatusLabel(update.status))}</span><p>${escapeHtml(update.message).replaceAll('\n','<br>')}</p><small>${escapeHtml(formatDate(update.created_at,data))}</small></div>`).join('')}</div></article>`).join('');
    }
    if(historyTarget){
        historyTarget.innerHTML=recent.length?recent.map(incident=>`<article><span class="sp-incident-badge ${escapeHtml(incident.impact)}">${escapeHtml(incidentStatusLabel(incident.status))}</span><div><strong>${escapeHtml(incident.title)}</strong><p>${escapeHtml(incidentTargetsLabel(incident.targets))}</p><small>Started ${escapeHtml(formatDate(incident.started_at,data))}${incident.resolved_at?` · Resolved ${escapeHtml(formatDate(incident.resolved_at,data))}`:''}</small></div></article>`).join(''):'<p class="sp-empty">No incidents recorded.</p>';
    }
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
    set('incidentCount',String(data.incidents?.active?.length||0));
    set('monitorSourceCount',String(data.monitoring?.active_source_count||0));
    set('monitorSourceSub',data.monitoring?.redundant?'redundant monitoring':'single source');
    set('liveRefreshLabel',`Live monitoring · refreshes every ${data.refresh_seconds||window.STATUS_REFRESH_SECONDS||15}s`);
}

function applyHistoryRange(days=currentHistoryDays){
    currentHistoryDays=days;
    document.querySelectorAll('[data-history-days]').forEach(btn=>btn.classList.toggle('active',Number(btn.dataset.historyDays)===days));
    document.querySelectorAll('[data-history-bars]').forEach(container=>{
        const bars=Array.from(container.querySelectorAll('[data-history-index]'));
        const showFrom=Math.max(0,bars.length-days);
        bars.forEach((bar,index)=>bar.classList.toggle('history-hidden',index<showFrom));
    });
}

function wireHistoryControls(){
    document.querySelectorAll('[data-history-days]').forEach(btn=>{
        btn.addEventListener('click',()=>applyHistoryRange(Number(btn.dataset.historyDays)||90));
    });
}

function applyPayload(data){
    lastPayload=data;
    updateSummary(data);
    renderNotice(data);
    renderSchedule(data);
    renderIncidents(data);
    renderGroups(data);
    renderAnnouncements(data);
    renderUpdates(data);
    const serviceGrid=document.getElementById('serviceGrid');
    const websiteTable=document.getElementById('websiteTable');
    if(serviceGrid) serviceGrid.innerHTML=(data.services||[]).map(service=>renderServiceRow(service,data)).join('');
    if(websiteTable) websiteTable.innerHTML=(data.websites||[]).map(site=>renderWebsiteRow(site,data)).join('');
    applyHistoryRange(currentHistoryDays);
}

async function refreshStatus(){
    try{
        const response=await fetch('/api/status.php?ts='+Date.now(),{cache:'no-store',headers:{'Accept':'application/json'}});
        if(!response.ok) return;
        applyPayload(await response.json());
    }catch(e){console.warn('Status refresh failed',e);}
}

wireHistoryControls();
applyHistoryRange(currentHistoryDays);
const interval=Math.max(5,Number(window.STATUS_REFRESH_SECONDS||15))*1000;
setInterval(refreshStatus,interval);
