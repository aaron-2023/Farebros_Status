function fbEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function fbFormatDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value || 'now';
    }

    return date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function renderStatus(data) {
    const primary = data.primary || {};
    const heroTitle = document.getElementById('heroTitle');
    const statusMessage = document.getElementById('statusMessage');
    const primaryStatusCard = document.getElementById('primaryStatusCard');
    const primaryStatusText = document.getElementById('primaryStatusText');
    const primaryStatusCode = document.getElementById('primaryStatusCode');
    const primaryWebsiteName = document.getElementById('primaryWebsiteName');
    const primaryWebsiteLine = document.getElementById('primaryWebsiteLine');
    const lastUpdated = document.getElementById('lastUpdated');
    const overallCard = document.getElementById('overallCard');
    const overallLabel = document.getElementById('overallLabel');
    const serviceGrid = document.getElementById('serviceGrid');
    const websiteTable = document.getElementById('websiteTable');
    const websiteDropdownList = document.getElementById('websiteDropdownList');
    const announcementList = document.getElementById('announcementList');
    const updateList = document.getElementById('updateList');

    if (!heroTitle) return;

    heroTitle.textContent = primary.is_online ? 'Fare Brothers is online.' : 'Fare Brothers is offline.';
    statusMessage.textContent = data.message || '';

    primaryStatusCard.className = `fb-hero-card ${primary.class || 'bad'}`;
    primaryStatusText.textContent = primary.label || 'Unknown';
    primaryStatusCode.textContent = primary.code ?? '0';
    primaryWebsiteName.textContent = primary.name || 'Fare Brothers';
    primaryWebsiteLine.textContent = primary.is_online
        ? 'The primary Fare Brothers website is responding normally from the offsite monitor.'
        : 'The primary Fare Brothers website is not responding normally from the offsite monitor.';
    lastUpdated.textContent = fbFormatDate(data.generated_at);

    overallCard.className = `fb-overall ${data.overall.class}`;
    overallLabel.textContent = data.overall.label;

    serviceGrid.innerHTML = data.services.map(service => `
        <article class="fb-service-card ${fbEscape(service.class)}">
            <div>
                <h3>${fbEscape(service.name)}</h3>
                <p>${fbEscape(service.description)}</p>
            </div>
            <div class="fb-service-status">
                <span class="fb-dot"></span>
                <strong>${fbEscape(service.label)}</strong>
                <small>Code ${fbEscape(service.code)}</small>
            </div>
        </article>
    `).join('');

    websiteTable.innerHTML = data.websites.map(site => `
        <article class="fb-website-row ${fbEscape(site.class)}">
            <div class="fb-website-name">
                <span class="fb-dot"></span>
                <div>
                    <strong>${fbEscape(site.name)}</strong>
                    <small>${fbEscape(site.url)}</small>
                </div>
            </div>
            <div class="fb-website-status">
                <strong>${fbEscape(site.label)}</strong>
                <small>${site.last_checked_at ? 'Checked ' + fbFormatDate(site.last_checked_at) : 'Waiting for first check'}</small>
            </div>
        </article>
    `).join('');

    websiteDropdownList.innerHTML = data.websites.map(site => `
        <a class="fb-dropdown-site ${fbEscape(site.class)}" href="${fbEscape(site.url)}" target="_blank" rel="noopener">
            <span class="fb-dot"></span>
            <span>
                <strong>${fbEscape(site.name)}</strong>
                <small>${fbEscape(site.label)}${site.is_primary ? ' · Primary' : ''}</small>
            </span>
        </a>
    `).join('');

    if (!data.announcements.length) {
        announcementList.innerHTML = '<p class="fb-empty">No active announcements.</p>';
    } else {
        announcementList.innerHTML = data.announcements.map(item => `
            <article class="fb-timeline-item">
                <h3>${fbEscape(item.title)}</h3>
                <p>${fbEscape(item.body).replaceAll('\n', '<br>')}</p>
                <small>${fbFormatDate(item.created_at)}</small>
            </article>
        `).join('');
    }

    if (!data.recent_updates.length) {
        updateList.innerHTML = '<p class="fb-empty">No recent updates.</p>';
    } else {
        updateList.innerHTML = data.recent_updates.map(item => `
            <article class="fb-timeline-item">
                <h3>${fbEscape(item.update_title)}</h3>
                <p>${fbEscape(item.update_message || '').replaceAll('\n', '<br>')}</p>
                <small>${fbEscape(item.service_name || 'General')} · ${fbFormatDate(item.created_at)}</small>
            </article>
        `).join('');
    }
}

async function refreshStatus() {
    try {
        const response = await fetch('/api/status.php?ts=' + Date.now(), { cache: 'no-store' });
        if (!response.ok) return;
        const data = await response.json();
        renderStatus(data);
    } catch (error) {
        console.warn('Status refresh failed', error);
    }
}

const dropdownButton = document.getElementById('websiteDropdownButton');
const dropdown = document.getElementById('websiteDropdown');

if (dropdownButton && dropdown) {
    dropdownButton.addEventListener('click', () => dropdown.classList.toggle('show'));

    document.addEventListener('click', event => {
        if (!dropdown.contains(event.target) && !dropdownButton.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });
}

setInterval(refreshStatus, (window.STATUS_REFRESH_SECONDS || 15) * 1000);
