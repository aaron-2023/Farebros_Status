async function refreshStatus(){
    try{
        const response=await fetch('/api/status.php?ts='+Date.now(),{cache:'no-store'});
        if(!response.ok)return;
        const data=await response.json();
        const serviceGrid=document.getElementById('serviceGrid');
        const websiteTable=document.getElementById('websiteTable');

        if(serviceGrid){
            serviceGrid.innerHTML=data.services.map(service=>renderRow(service, false)).join('');
        }

        if(websiteTable){
            websiteTable.innerHTML=data.websites.map(site=>renderRow(site, true)).join('');
        }
    }catch(e){console.warn('Status refresh failed',e);}
}

function renderRow(item, isWebsite){
    const name = isWebsite ? `${escapeHtml(item.name)}${item.is_primary ? ' · Primary' : ''}` : escapeHtml(item.name);
    const desc = isWebsite ? escapeHtml(item.url) : escapeHtml(item.description);
    const small = isWebsite
        ? (item.last_checked_at_iso ? 'Checked ' + formatDate(item.last_checked_at_iso) : 'Waiting for first check')
        : `Code ${escapeHtml(item.code)}`;

    const overlay = item.schedule_overlay
        ? `<span class="sp-maint-badge ${escapeHtml(item.schedule_overlay.phase)}">${escapeHtml(item.schedule_overlay.label)}</span>`
        : '';

    return `
        <article class="sp-row ${escapeHtml(item.class)}">
            <div class="sp-row-main">
                <span class="sp-dot"></span>
                <div>
                    <strong>${name}</strong>
                    <p>${desc}</p>
                    ${overlay}
                </div>
            </div>
            <div class="sp-row-status">
                <strong>${escapeHtml(item.label)}</strong>
                <small>${small}</small>
            </div>
        </article>`;
}

function escapeHtml(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;")}
function formatDate(v){const d=new Date(v);if(Number.isNaN(d.getTime()))return v;return d.toLocaleString([],{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'})}
setInterval(refreshStatus,(window.STATUS_REFRESH_SECONDS||15)*1000);
