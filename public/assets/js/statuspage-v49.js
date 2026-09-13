async function refreshStatus(){
    try{
        const response=await fetch('/api/status.php?ts='+Date.now(),{cache:'no-store'});
        if(!response.ok)return;
        const data=await response.json();
        const serviceGrid=document.getElementById('serviceGrid');
        const websiteTable=document.getElementById('websiteTable');
        if(serviceGrid){
            serviceGrid.innerHTML=data.services.map(service=>`
                <article class="sp-row ${String(service.class||'')}">
                    <div class="sp-row-main"><span class="sp-dot"></span><div><strong>${escapeHtml(service.name)}</strong><p>${escapeHtml(service.description)}</p></div></div>
                    <div class="sp-row-status"><strong>${escapeHtml(service.label)}</strong><small>Code ${escapeHtml(service.code)}</small></div>
                </article>`).join('');
        }
        if(websiteTable){
            websiteTable.innerHTML=data.websites.map(site=>`
                <article class="sp-row ${String(site.class||'')}">
                    <div class="sp-row-main"><span class="sp-dot"></span><div><strong>${escapeHtml(site.name)}${site.is_primary?' · Primary':''}</strong><p>${escapeHtml(site.url)}</p></div></div>
                    <div class="sp-row-status"><strong>${escapeHtml(site.label)}</strong><small>${site.last_checked_at_iso?'Checked '+formatDate(site.last_checked_at_iso):'Waiting for first check'}</small></div>
                </article>`).join('');
        }
    }catch(e){console.warn('Status refresh failed',e);}
}
function escapeHtml(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
function formatDate(v){const d=new Date(v);if(Number.isNaN(d.getTime()))return v;return d.toLocaleString([],{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'})}
setInterval(refreshStatus,(window.STATUS_REFRESH_SECONDS||15)*1000);
