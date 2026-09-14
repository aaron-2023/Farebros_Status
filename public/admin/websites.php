<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/integration.php';
require_once __DIR__ . '/../../app/monitor.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;
$syncNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_website') {
            $name = trim((string)($_POST['website_name'] ?? ''));
            $url = trim((string)($_POST['website_url'] ?? ''));
            if ($name === '' || $url === '') {
                throw new RuntimeException('Website name and URL are required.');
            }

            $websiteId = add_website(
                $name,
                $url,
                trim((string)($_POST['website_description'] ?? '')),
                !empty($_POST['is_primary'])
            );
            if ($websiteId <= 0) {
                throw new RuntimeException('Website could not be added.');
            }

            $_POST['monitor_enabled'] = '1';
            if (!isset($_POST['ssl_check'])) {
                $_POST['ssl_check'] = '1';
            }
            update_website_monitor_config($websiteId, $_POST);
            $notice = 'Website added and monitoring enabled.';
            audit_admin_action($user, 'add_website', 'website', $websiteId, $name);
        }

        if ($action === 'update_website') {
            $websiteId = (int)($_POST['website_id'] ?? 0);
            $name = trim((string)($_POST['website_name'] ?? ''));
            $url = trim((string)($_POST['website_url'] ?? ''));
            if ($websiteId <= 0 || $name === '' || $url === '') {
                throw new RuntimeException('Website name and URL are required.');
            }

            update_website(
                $websiteId,
                $name,
                $url,
                trim((string)($_POST['website_description'] ?? '')),
                (string)($_POST['website_status'] ?? 'operational'),
                !empty($_POST['is_primary']),
                (int)($_POST['sort_order'] ?? 100)
            );
            update_website_monitor_config($websiteId, $_POST);
            $notice = 'Website settings saved.';
            audit_admin_action($user, 'update_website', 'website', $websiteId, $name);
        }

        if ($action === 'delete_website') {
            $websiteId = (int)($_POST['website_id'] ?? 0);
            if ($websiteId <= 0) {
                throw new RuntimeException('Website not found.');
            }
            $stmt = db()->prepare('SELECT website_name FROM websites WHERE id = ? LIMIT 1');
            $stmt->execute([$websiteId]);
            $siteName = (string)($stmt->fetchColumn() ?: 'Website');
            delete_website($websiteId);
            $notice = $siteName . ' was removed.';
            audit_admin_action($user, 'delete_website', 'website', $websiteId, $siteName);
        }

        if ($action !== '' && platform_sync_enabled()) {
            $syncNotice = sync_status_snapshot_to_platform('website_management')
                ? 'Portal sync sent.'
                : 'Website change saved, but portal sync failed or was skipped.';
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$websites = get_websites();
$onlineCount = count(array_filter($websites, static fn(array $site): bool => ($site['current_status'] ?? '') === 'operational'));
$monitoredCount = count(array_filter($websites, static fn(array $site): bool => (int)($site['monitor_enabled'] ?? 1) === 1));
$primarySite = get_primary_website();

$severityClass = static function (string $class): string {
    return match ($class) {
        'bad' => 'bad',
        'warning', 'maintenance' => 'warn',
        default => 'good',
    };
};

admin_page_start(
    'websites',
    'Websites',
    'Add, organize, and monitor every website from one place.',
    'Status Management',
    [
        ['href' => '#add', 'label' => 'Add Website', 'class' => 'primary'],
        ['href' => '/admin/monitoring.php', 'label' => 'Monitor Center', 'class' => 'ghost'],
    ]
);
admin_status_tabs('websites');
admin_notice($notice);
if ($syncNotice) admin_notice($syncNotice, str_contains(strtolower($syncNotice), 'failed') ? 'danger' : 'success');
admin_notice($error, 'danger');
?>

<section class="v54-metrics">
    <article class="v54-metric"><div class="v54-metric-top"><small>Tracked Websites</small><span class="v54-status-dot <?= count($websites) ? 'good' : 'warn' ?>"></span></div><strong><?= count($websites) ?></strong><em>Configured status targets</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Operational</small><span class="v54-status-dot <?= $onlineCount === count($websites) ? 'good' : 'warn' ?>"></span></div><strong><?= $onlineCount ?> / <?= count($websites) ?></strong><em><?= count($websites) - $onlineCount ?> currently not operational</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Monitoring Enabled</small><span class="v54-status-dot <?= $monitoredCount === count($websites) ? 'good' : 'warn' ?>"></span></div><strong><?= $monitoredCount ?></strong><em>Automatic health checks</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Primary Website</small><span class="v54-status-dot <?= $primarySite ? 'good' : 'warn' ?>"></span></div><strong><?= $primarySite ? e((string)$primarySite['website_name']) : 'Not set' ?></strong><em>Controls primary public headline</em></article>
</section>

<section class="v54-grid" id="add">
    <article class="pro-card v54-create-card">
        <div class="pro-card-head"><div><h2>Add Website</h2><p>Create a tracked site and enable monitoring immediately.</p></div><span class="pro-chip">New</span></div>
        <form method="post" class="pro-form v54-card-body">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_website">
            <div class="pro-form-row">
                <div><label>Website name</label><input type="text" name="website_name" placeholder="Fare Brothers" required></div>
                <div><label>Website URL</label><input type="url" name="website_url" placeholder="https://farebros.com" required></div>
            </div>
            <label>Description</label><input type="text" name="website_description" placeholder="Short description shown in administration">
            <div class="pro-form-row">
                <div><label>Monitor type</label><select name="monitor_type"><option value="http">HTTP / HTTPS</option><option value="tcp">TCP Port</option><option value="dns">DNS Lookup</option></select></div>
                <div><label>Slow-response warning</label><div class="pro-input-suffix"><input type="number" name="response_warn_ms" min="0" value="2500"><span>ms</span></div></div>
            </div>
            <details class="v54-edit-panel">
                <summary>Advanced monitor options</summary>
                <div class="pro-form">
                    <div class="pro-form-row"><div><label>Expected HTTP code</label><input type="number" name="expected_http_code" min="100" max="599" value="200"></div><div><label>SSL warning</label><div class="pro-input-suffix"><input type="number" name="ssl_warn_days" min="1" max="365" value="21"><span>days</span></div></div></div>
                    <label>Expected page text</label><input type="text" name="expected_text" placeholder="Optional content check">
                    <div class="pro-form-row"><div><label>TCP host</label><input type="text" name="tcp_host" placeholder="server.example.com"></div><div><label>TCP port</label><input type="number" name="tcp_port" min="1" max="65535"></div></div>
                    <label>DNS hostname</label><input type="text" name="dns_host" placeholder="example.com">
                    <label class="pro-check"><input type="checkbox" name="ssl_check" value="1" checked>Check SSL certificate expiration</label>
                </div>
            </details>
            <label class="pro-check"><input type="checkbox" name="is_primary" value="1">Make this the primary website</label>
            <div class="pro-actions"><button class="button primary" type="submit">Add Website</button></div>
        </form>
    </article>

    <article class="pro-card">
        <div class="pro-card-head"><div><h2>How Website Status Works</h2><p>Keep the headline accurate without micromanaging every check.</p></div></div>
        <div class="v54-card-body v54-compact-list">
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Primary website</strong><small>A primary outage directly affects the overall public status.</small></div></div></div>
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Secondary websites</strong><small>A secondary outage becomes a partial outage instead of taking the entire platform offline.</small></div></div></div>
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Flap protection</strong><small>Monitoring waits for confirmed failures/recoveries before changing public status.</small></div></div></div>
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Scheduled maintenance</strong><small>Maintenance windows override automated status transitions while active.</small></div></div></div>
        </div>
    </article>
</section>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Tracked Websites</h2><p>Edit a site only when needed. The everyday health details stay visible without opening the form.</p></div><span class="v54-count"><?= count($websites) ?> website<?= count($websites) === 1 ? '' : 's' ?></span></div>
    <div class="v54-card-body">
        <div class="v54-toolbar">
            <div class="v54-search"><input type="search" placeholder="Search websites…" data-filter-input="#websiteList" aria-label="Search websites"></div>
            <span class="v54-count">Primary site is listed first</span>
        </div>
        <div class="v54-site-list" id="websiteList">
            <?php if (!$websites): ?><div class="v54-empty v54-full">No websites have been added yet.</div><?php endif; ?>
            <?php foreach ($websites as $website):
                $meta = status_meta((string)$website['current_status']);
                $sev = $severityClass((string)$meta['class']);
                $uptime30 = website_uptime_percent((int)$website['id'], 30);
            ?>
                <article class="v54-site-card" data-filter-row="<?= e((string)$website['website_name'] . ' ' . (string)$website['website_url']) ?>">
                    <div class="v54-site-card-head">
                        <div class="v54-site-title">
                            <span class="v54-status-dot <?= e($sev) ?>"></span>
                            <div><strong><?= e((string)$website['website_name']) ?><?= (int)$website['is_primary'] === 1 ? ' · Primary' : '' ?></strong><small><?= e((string)$website['website_url']) ?></small></div>
                        </div>
                        <span class="v54-status-pill <?= e($sev === 'good' ? '' : $sev) ?>"><?= e((string)$meta['label']) ?></span>
                    </div>
                    <div class="v54-site-meta">
                        <div><small>30-Day Uptime</small><strong><?= $uptime30 !== null ? e(number_format($uptime30, 3)) . '%' : 'No data' ?></strong></div>
                        <div><small>Response</small><strong><?= $website['last_response_ms'] !== null ? (int)$website['last_response_ms'] . ' ms' : 'Pending' ?></strong></div>
                        <div><small>SSL</small><strong><?= $website['last_ssl_days'] !== null ? (int)$website['last_ssl_days'] . ' days' : 'n/a' ?></strong></div>
                        <div><small>Monitor</small><strong><?= (int)($website['monitor_enabled'] ?? 1) === 1 ? e(strtoupper((string)($website['monitor_type'] ?? 'http'))) : 'Disabled' ?></strong></div>
                    </div>
                    <div class="v54-site-actions">
                        <span class="v54-count">Last check: <?= $website['last_checked_at'] ? e(format_dt($website['last_checked_at'])) : 'Pending' ?> · ID #<?= (int)$website['id'] ?></span>
                    </div>

                    <details class="v54-edit-panel">
                        <summary>Edit website & monitoring</summary>
                        <form method="post" class="pro-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_website">
                            <input type="hidden" name="website_id" value="<?= (int)$website['id'] ?>">
                            <div class="pro-form-row"><div><label>Name</label><input type="text" name="website_name" value="<?= e((string)$website['website_name']) ?>" required></div><div><label>URL</label><input type="url" name="website_url" value="<?= e((string)$website['website_url']) ?>" required></div></div>
                            <label>Description</label><input type="text" name="website_description" value="<?= e((string)($website['description'] ?? '')) ?>">
                            <div class="pro-form-row">
                                <div><label>Manual status</label><select name="website_status"><?php foreach (STATUS_OPTIONS as $key => $option): ?><option value="<?= e($key) ?>" <?= $website['current_status'] === $key ? 'selected' : '' ?>><?= e((string)$option['label']) ?></option><?php endforeach; ?></select></div>
                                <div><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$website['sort_order'] ?>"></div>
                            </div>
                            <div class="pro-monitor-config">
                                <h4>Monitor Configuration</h4>
                                <div class="pro-form-row"><div><label>Monitor type</label><select name="monitor_type"><option value="http" <?= ($website['monitor_type'] ?? 'http') === 'http' ? 'selected' : '' ?>>HTTP / HTTPS</option><option value="tcp" <?= ($website['monitor_type'] ?? '') === 'tcp' ? 'selected' : '' ?>>TCP Port</option><option value="dns" <?= ($website['monitor_type'] ?? '') === 'dns' ? 'selected' : '' ?>>DNS Lookup</option></select></div><div><label>Response warning</label><div class="pro-input-suffix"><input type="number" name="response_warn_ms" min="0" value="<?= e((string)($website['response_warn_ms'] ?? '')) ?>" placeholder="2500"><span>ms</span></div></div></div>
                                <div class="pro-form-row"><div><label>Expected HTTP code</label><input type="number" name="expected_http_code" min="100" max="599" value="<?= e((string)($website['expected_http_code'] ?? '')) ?>" placeholder="200"></div><div><label>SSL warning</label><div class="pro-input-suffix"><input type="number" name="ssl_warn_days" min="1" max="365" value="<?= (int)($website['ssl_warn_days'] ?? 21) ?>"><span>days</span></div></div></div>
                                <label>Expected page text</label><input type="text" name="expected_text" value="<?= e((string)($website['expected_text'] ?? '')) ?>" placeholder="Optional content check">
                                <div class="pro-form-row"><div><label>TCP host</label><input type="text" name="tcp_host" value="<?= e((string)($website['tcp_host'] ?? '')) ?>" placeholder="server.example.com"></div><div><label>TCP port</label><input type="number" name="tcp_port" min="1" max="65535" value="<?= e((string)($website['tcp_port'] ?? '')) ?>"></div></div>
                                <label>DNS hostname</label><input type="text" name="dns_host" value="<?= e((string)($website['dns_host'] ?? '')) ?>" placeholder="example.com">
                                <div class="pro-check-row"><label class="pro-check"><input type="checkbox" name="monitor_enabled" value="1" <?= (int)($website['monitor_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>Automatic monitoring</label><label class="pro-check"><input type="checkbox" name="ssl_check" value="1" <?= (int)($website['ssl_check'] ?? 1) === 1 ? 'checked' : '' ?>>SSL expiration check</label></div>
                            </div>
                            <label class="pro-check"><input type="checkbox" name="is_primary" value="1" <?= (int)$website['is_primary'] === 1 ? 'checked' : '' ?>>Primary website</label>
                            <div class="pro-actions"><button class="button primary" type="submit">Save Website</button></div>
                        </form>
                        <form method="post" class="pro-delete" data-confirm="Remove <?= e((string)$website['website_name']) ?>? This removes the website from the status page.">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_website"><input type="hidden" name="website_id" value="<?= (int)$website['id'] ?>">
                            <button class="button danger small" type="submit">Delete Website</button>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php admin_page_end(); ?>
