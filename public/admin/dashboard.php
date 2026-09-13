<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/integration.php';
require_once __DIR__ . '/../../app/monitor.php';

$hasScheduler = file_exists(__DIR__ . '/../../app/scheduler.php');
if ($hasScheduler) {
    require_once __DIR__ . '/../../app/scheduler.php';
}

$user = require_login();

$notice = null;
$syncNotice = null;
$monitorNotice = null;
$schedulerNotice = null;
$schedulerError = null;

/**
 * Collect positive integer IDs from both the normal checkbox array and the
 * JS-maintained CSV fallback. The fallback makes bulk deletion reliable even
 * if a browser/theme interferes with normal checkbox serialization.
 */
function posted_record_ids(string $arrayKey, string $csvKey): array
{
    $ids = [];

    foreach ((array)($_POST[$arrayKey] ?? []) as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $csv = trim((string)($_POST[$csvKey] ?? ''));
    if ($csv !== '') {
        foreach (explode(',', $csv) as $value) {
            $id = (int)trim($value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Delete the exact requested IDs and verify that those IDs no longer exist
 * before reporting success.
 */
function delete_records_verified(string $table, array $ids): array
{
    $allowedTables = ['status_updates', 'monitor_check_log'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported delete table.');
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id): bool => $id > 0
    )));

    if (!$ids) {
        return ['requested' => 0, 'matched' => 0, 'deleted' => 0, 'remaining' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();

    $beforeStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})");
    $beforeStmt->execute($ids);
    $matched = (int)$beforeStmt->fetchColumn();

    if ($matched > 0) {
        $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
        $deleteStmt->execute($ids);
    }

    $afterStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})");
    $afterStmt->execute($ids);
    $remaining = (int)$afterStmt->fetchColumn();

    return [
        'requested' => count($ids),
        'matched' => $matched,
        'deleted' => max(0, $matched - $remaining),
        'remaining' => $remaining,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_status_message') {
        set_setting('status_message', trim($_POST['status_message'] ?? ''));
        $notice = 'Public status message updated.';

        if (platform_sync_enabled()) {
            $syncNotice = sync_status_snapshot_to_platform('status_message_updated') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
        }
    }

    if ($action === 'update_service') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'operational';
        $title = trim($_POST['update_title'] ?? '');
        $message = trim($_POST['update_message'] ?? '');

        if (!isset(STATUS_OPTIONS[$newStatus])) {
            $newStatus = 'offline';
        }

        $stmt = db()->prepare('SELECT current_status, service_name FROM services WHERE id = ? LIMIT 1');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();

        if ($service) {
            $oldStatus = $service['current_status'];

            $stmt = db()->prepare('UPDATE services SET current_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$newStatus, $serviceId]);

            if ($title === '') {
                $title = $service['service_name'] . ' changed to ' . status_meta($newStatus)['label'];
            }

            $stmt = db()->prepare('
                INSERT INTO status_updates
                (service_id, old_status, new_status, update_title, update_message, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$serviceId, $oldStatus, $newStatus, $title, $message, (int)$user['id']]);
            send_status_notifications($title, $message !== '' ? $message : ('Status changed from ' . $oldStatus . ' to ' . $newStatus . '.'), $newStatus === 'operational' ? 'resolved' : 'warning', 'manual_status_change');

            $notice = 'Service status updated.';

            if (platform_sync_enabled()) {
                $syncNotice = sync_status_snapshot_to_platform('service_status_updated') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
            }
        }
    }

    if ($action === 'add_website') {
        $newWebsiteId = add_website(trim($_POST['website_name'] ?? ''), trim($_POST['website_url'] ?? ''), trim($_POST['website_description'] ?? ''), !empty($_POST['is_primary']));
        if ($newWebsiteId > 0) {
            $_POST['monitor_enabled'] = '1';
            if (!isset($_POST['ssl_check'])) $_POST['ssl_check'] = '1';
            update_website_monitor_config($newWebsiteId, $_POST);
        }
        $notice = 'Website added.';
    }

    if ($action === 'update_website') {
        update_website(
            (int)($_POST['website_id'] ?? 0),
            trim($_POST['website_name'] ?? ''),
            trim($_POST['website_url'] ?? ''),
            trim($_POST['website_description'] ?? ''),
            $_POST['website_status'] ?? 'operational',
            !empty($_POST['is_primary']),
            (int)($_POST['sort_order'] ?? 100)
        );
        update_website_monitor_config((int)($_POST['website_id'] ?? 0), $_POST);
        $notice = 'Website updated.';
    }

    if ($action === 'delete_website') {
        delete_website((int)($_POST['website_id'] ?? 0));
        $notice = 'Website removed.';
    }

    if ($action === 'add_announcement') {
        $title = trim($_POST['announcement_title'] ?? '');
        $body = trim($_POST['announcement_body'] ?? '');

        if ($title !== '' && $body !== '') {
            $stmt = db()->prepare('INSERT INTO announcements (title, body, is_active, created_by) VALUES (?, ?, 1, ?)');
            $stmt->execute([$title, $body, (int)$user['id']]);
            $notice = 'Announcement added.';

            if (platform_sync_enabled()) {
                $syncNotice = sync_status_snapshot_to_platform('announcement_added') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
            }
        }
    }

    if ($action === 'toggle_announcement') {
        $id = (int)($_POST['announcement_id'] ?? 0);
        $stmt = db()->prepare('UPDATE announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$id]);
        $notice = 'Announcement updated.';

        if (platform_sync_enabled()) {
            $syncNotice = sync_status_snapshot_to_platform('announcement_toggled') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
        }
    }

    if ($action === 'delete_announcement') {
        $id = (int)($_POST['announcement_id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM announcements WHERE id = ?');
        $stmt->execute([$id]);
        $notice = 'Announcement deleted.';

        if (platform_sync_enabled()) {
            $syncNotice = sync_status_snapshot_to_platform('announcement_deleted') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
        }
    }

    if ($action === 'delete_update') {
        $id = (int)($_POST['update_id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM status_updates WHERE id = ?');
        $stmt->execute([$id]);
        $notice = 'Status update deleted.';

        if (platform_sync_enabled()) {
            $syncNotice = sync_status_snapshot_to_platform('status_update_deleted') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
        }
    }

    if ($action === 'delete_selected_updates') {
        $ids = posted_record_ids('update_ids', 'selected_update_ids');

        if ($ids) {
            $result = delete_records_verified('status_updates', $ids);

            if ($result['remaining'] > 0) {
                $notice = "Delete verification failed: {$result['remaining']} selected status update(s) are still in the database.";
            } elseif ($result['deleted'] > 0) {
                $deleted = (int)$result['deleted'];
                $notice = $deleted === 1
                    ? '1 status update permanently deleted and verified.'
                    : number_format($deleted) . ' status updates permanently deleted and verified.';

                if (platform_sync_enabled()) {
                    $syncNotice = sync_status_snapshot_to_platform('status_updates_bulk_deleted') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
                }
            } else {
                $notice = 'The selected status updates were already gone; nothing remained to delete.';
            }
        } else {
            $notice = 'No status updates were selected.';
        }
    }

    if ($action === 'clear_old_updates') {
        $keep = max(0, (int)($_POST['keep_updates'] ?? 10));
        $stmt = db()->prepare("
            DELETE FROM status_updates
            WHERE id NOT IN (
                SELECT id FROM status_updates ORDER BY created_at DESC LIMIT ?
            )
        ");
        $stmt->bindValue(1, $keep, PDO::PARAM_INT);
        $stmt->execute();
        $notice = "Old status updates cleared. Kept latest {$keep}.";
    }

    if ($action === 'delete_monitor_log') {
        $id = (int)($_POST['monitor_log_id'] ?? 0);
        db()->prepare('DELETE FROM monitor_check_log WHERE id = ?')->execute([$id]);
        $notice = 'Monitor log entry deleted.';
    }

    if ($action === 'delete_selected_monitor_logs') {
        $ids = posted_record_ids('monitor_log_ids', 'selected_monitor_log_ids');

        if ($ids) {
            $result = delete_records_verified('monitor_check_log', $ids);

            if ($result['remaining'] > 0) {
                $notice = "Delete verification failed: {$result['remaining']} selected monitor log entry/entries are still in the database.";
            } elseif ($result['deleted'] > 0) {
                $deleted = (int)$result['deleted'];
                $notice = $deleted === 1
                    ? '1 monitor log entry permanently deleted and verified.'
                    : number_format($deleted) . ' monitor log entries permanently deleted and verified.';
            } else {
                $notice = 'The selected monitor log entries were already gone; nothing remained to delete.';
            }
        } else {
            $notice = 'No monitor log entries were selected.';
        }
    }

    if ($action === 'clear_monitor_logs') {
        $before = (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn();
        db()->exec('DELETE FROM monitor_check_log');
        $after = (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn();
        $deleted = max(0, $before - $after);
        $notice = number_format($deleted) . ' monitor log entries permanently deleted. New checks may appear immediately while automatic monitoring is enabled.';
    }

    if ($action === 'manual_run_monitor') {
        $monitorResult = run_all_monitors();
        $monitorNotice = !empty($monitorResult['ok']) ? 'Monitor ran successfully.' : ($monitorResult['message'] ?? 'Monitor failed.');
    }

    if ($hasScheduler && $action === 'apply_schedules_now') {
        try {
            $actions = apply_scheduled_jobs();
            $schedulerNotice = 'Scheduler checked. Actions: ' . (empty($actions) ? 'none' : implode(', ', $actions));
        } catch (Throwable $ex) {
            $schedulerError = $ex->getMessage();
        }
    }

    if ($action !== '') {
        audit_admin_action($user, $action, 'dashboard');
    }
}

$payload = public_status_payload();
$services = get_services();
$websites = get_websites();
$announcements = db()->query('SELECT * FROM announcements ORDER BY created_at DESC LIMIT 20')->fetchAll();
$updates = get_recent_updates(50);
$monitorLogs = get_recent_monitor_logs(50);
$totalUpdates = (int)db()->query('SELECT COUNT(*) FROM status_updates')->fetchColumn();
$totalMonitorLogs = (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn();
$statusMessage = get_setting('status_message', '');
$primary = $payload['primary'];
$overall = $payload['overall'];

$activeAnnouncements = array_filter($announcements, fn($a) => (int)$a['is_active'] === 1);
$offlineWebsites = array_filter($websites, fn($w) => ($w['current_status'] ?? '') !== 'operational');
$lastMonitor = $monitorLogs[0] ?? null;
$activeIncidents = get_active_incidents();
$monitorSources = get_monitor_source_health();
$healthyMonitorSources = array_filter($monitorSources, static fn($source) => empty($source['is_stale']));

$schedules = [];
$activeJob = null;
$upcomingJob = null;
if ($hasScheduler) {
    try {
        $schedules = get_scheduled_jobs(5);
        $activeJob = active_job_payload();
        $upcomingJob = upcoming_job_payload();
    } catch (Throwable $ex) {
        $schedulerError = $schedulerError ?: $ex->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
    <link rel="stylesheet" href="/assets/css/admin-controls-v51.css?v=5.1.0">
    <link rel="stylesheet" href="/assets/css/admin-v52.css?v=5.3.0">
</head>
<body class="admin-pro">
    <aside class="pro-sidebar">
        <div class="pro-brand">
            <div class="pro-brand-pill">Fare Brothers</div>
            <h2>Status Admin</h2>
            <p>Offsite system status and planned maintenance control.</p>
        </div>

        <nav class="pro-nav">
            <div class="pro-nav-group">
                <small>Main</small>
                <a class="active" href="/admin/dashboard.php"><span>▣</span>Dashboard</a>
                <a href="/" target="_blank"><span>↗</span>Public Page</a>
            </div>

            <div class="pro-nav-group">
                <small>Manage</small>
                <?php if ($hasScheduler): ?><a href="/admin/schedules.php"><span>🗓</span>Schedules</a><?php endif; ?>
                <a href="/admin/incidents.php"><span>⚠</span>Incidents</a>
                <a href="/admin/analytics.php"><span>⌁</span>Analytics</a>
                <a href="#websites"><span>🌐</span>Websites</a>
                <a href="#updates"><span>📣</span>Updates</a>
            </div>

            <div class="pro-nav-group">
                <small>Admin</small>
                <a href="/admin/settings.php"><span>⚙</span>Settings</a>
                <a href="/admin/audit-log.php"><span>☷</span>Audit Log</a>
                <a href="/admin/change-password.php"><span>🔒</span>Password</a>
                <a href="/admin/logout.php"><span>⎋</span>Logout</a>
            </div>
        </nav>
    </aside>

    <main class="pro-main">
        <header class="pro-topbar">
            <div>
                <div class="pro-kicker">Status Center</div>
                <h1>Dashboard</h1>
                <p>Manage public status, monitored websites, planned maintenance, announcements, and historical updates.</p>
            </div>

            <div class="pro-top-actions">
                <a class="button ghost" href="/admin/incidents.php">Incidents</a>
                <a class="button ghost" href="/admin/analytics.php">Analytics</a>
                <a class="button ghost" href="/admin/settings.php">Settings</a>
                <?php if ($hasScheduler): ?><a class="button ghost" href="/admin/schedules.php">Schedule Manager</a><?php endif; ?>
                <a class="button primary" href="/" target="_blank">View Public Page</a>
            </div>
        </header>

        <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($syncNotice): ?><div class="notice-box <?= str_contains(strtolower($syncNotice), 'failed') ? 'danger' : 'success' ?>"><?= e($syncNotice) ?></div><?php endif; ?>
        <?php if ($monitorNotice): ?><div class="notice-box success"><?= e($monitorNotice) ?></div><?php endif; ?>
        <?php if ($schedulerNotice): ?><div class="notice-box success"><?= e($schedulerNotice) ?></div><?php endif; ?>
        <?php if ($schedulerError): ?><div class="notice-box danger"><?= e($schedulerError) ?></div><?php endif; ?>

        <section class="pro-status-strip">
            <article class="<?= e($overall['class']) ?>">
                <span class="fb-dot"></span>
                <small>Overall</small>
                <strong><?= e($overall['label']) ?></strong>
                <em>Code <?= e((string)$overall['code']) ?></em>
            </article>

            <article class="<?= e($primary['class']) ?>">
                <span class="fb-dot"></span>
                <small>Primary Website</small>
                <strong><?= e($primary['name']) ?></strong>
                <em><?= e($primary['label']) ?></em>
            </article>

            <article class="<?= $activeJob ? 'bad' : ($upcomingJob ? 'maintenance' : 'good') ?>">
                <span class="fb-dot"></span>
                <small>Schedules</small>
                <strong><?= $activeJob ? 'Active Now' : ($upcomingJob ? 'Upcoming' : 'Clear') ?></strong>
                <em><?= $activeJob ? e($activeJob['title']) : ($upcomingJob ? e(format_dt($upcomingJob['start_at'])) : 'No active outage') ?></em>
            </article>

            <article>
                <span class="pro-icon">🌐</span>
                <small>Websites</small>
                <strong><?= count($websites) ?> Tracked</strong>
                <em><?= count($offlineWebsites) ?> not operational</em>
            </article>

            <article>
                <span class="pro-icon">↻</span>
                <small>Last Check</small>
                <strong><?= $lastMonitor ? e(format_dt($lastMonitor['created_at'])) : 'Pending' ?></strong>
                <em><?= $lastMonitor ? e($lastMonitor['website_name'] ?? 'Website') : 'No checks yet' ?></em>
            </article>
            <article class="<?= $activeIncidents ? 'bad' : 'good' ?>">
                <span class="pro-icon">⚠</span><small>Active Incidents</small><strong><?= count($activeIncidents) ?></strong><em><?= $activeIncidents ? 'Needs attention' : 'No active incidents' ?></em>
            </article>
            <article class="<?= count($healthyMonitorSources) >= 2 ? 'good' : 'maintenance' ?>">
                <span class="pro-icon">◎</span><small>Monitor Sources</small><strong><?= count($healthyMonitorSources) ?> Active</strong><em><?= count($healthyMonitorSources) >= 2 ? 'Redundant checks online' : 'Single-source monitoring' ?></em>
            </article>
        </section>

        <section class="pro-grid">
            <article class="pro-card pro-card-large">
                <div class="pro-card-head">
                    <div>
                        <h2>Public Status Message</h2>
                        <p>Shown on the public page under the main status headline.</p>
                    </div>
                </div>

                <form method="post" class="pro-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status_message">
                    <textarea name="status_message" rows="3"><?= e($statusMessage) ?></textarea>
                    <div class="pro-actions">
                        <button class="button primary" type="submit">Save Message</button>
                    </div>
                </form>
            </article>

            <article class="pro-card">
                <div class="pro-card-head">
                    <div>
                        <h2>Planned Maintenance</h2>
                        <p>Create and manage events from the Schedule Manager.</p>
                    </div>
                </div>

                <div class="pro-mini-stack">
                    <div><small>Active</small><strong><?= $activeJob ? e($activeJob['title']) : 'None' ?></strong></div>
                    <div><small>Next</small><strong><?= $upcomingJob ? e(format_dt($upcomingJob['start_at'])) : 'None scheduled' ?></strong></div>
                </div>

                <div class="pro-actions">
                    <?php if ($hasScheduler): ?><a class="button primary" href="/admin/schedules.php">Open Schedule Manager</a><?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="apply_schedules_now">
                        <button class="button ghost" type="submit">Check Now</button>
                    </form>
                </div>
            </article>
        </section>

        <section class="pro-grid" id="websites">
            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Add Website</h2><p>Add a site to the offsite monitor.</p></div></div>
                <form method="post" class="pro-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_website">
                    <label>Website Name</label>
                    <input type="text" name="website_name" placeholder="Fare Brothers Diagnostics" required>
                    <label>Website URL</label>
                    <input type="url" name="website_url" placeholder="https://example.com" required>
                    <label>Description</label>
                    <input type="text" name="website_description" placeholder="Short public note">
                    <label>Monitor type</label>
                    <select name="monitor_type"><option value="http">HTTP / HTTPS</option><option value="tcp">TCP port</option><option value="dns">DNS lookup</option></select>
                    <div class="pro-form-row"><div><label>Response warning (ms)</label><input type="number" name="response_warn_ms" min="0" placeholder="2500"></div><div><label>Expected HTTP code</label><input type="number" name="expected_http_code" min="100" max="599" placeholder="200"></div></div>
                    <label>Expected page text (optional)</label><input type="text" name="expected_text" placeholder="Text that must appear on a healthy HTTP page">
                    <div class="pro-form-row"><div><label>TCP host (for TCP monitor)</label><input type="text" name="tcp_host" placeholder="server.example.com"></div><div><label>TCP port</label><input type="number" name="tcp_port" min="1" max="65535" placeholder="443"></div></div>
                    <label>DNS hostname (for DNS monitor)</label><input type="text" name="dns_host" placeholder="example.com">
                    <div class="pro-form-row"><div><label>SSL warning (days)</label><input type="number" name="ssl_warn_days" min="1" max="365" value="21"></div><div></div></div>
                    <label class="pro-check"><input type="checkbox" name="ssl_check" value="1" checked>Check SSL certificate expiration</label>
                    <label class="pro-check"><input type="checkbox" name="is_primary" value="1">Make primary website</label>
                    <button class="button primary" type="submit">Add Website</button>
                </form>
            </article>

            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Monitor</h2><p>Runs from cron. Scheduled events override normal checks during active windows only.</p></div></div>
                <div class="pro-mini-stack">
                    <div><small>Status</small><strong><?= MONITORING_ENABLED ? 'Enabled' : 'Disabled' ?></strong></div>
                    <div><small>Cron</small><strong>Every minute</strong></div>
                </div>
                <form method="post" class="pro-actions">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="manual_run_monitor">
                    <button class="button primary" type="submit">Run Monitor Now</button>
                </form>
            </article>
        </section>

        <section class="pro-card pro-card-full">
            <div class="pro-card-head"><div><h2>Tracked Websites</h2><p>Primary website outages affect the headline. Secondary website outages become Partial Outage.</p></div></div>
            <div class="pro-website-list">
                <?php foreach ($websites as $website): $meta = status_meta($website['current_status']); ?>
                    <details class="pro-website <?= e($meta['class']) ?>">
                        <summary>
                            <span class="fb-dot"></span>
                            <span><strong><?= e($website['website_name']) ?><?= $website['is_primary'] ? ' · Primary' : '' ?> <small class="pro-inline-id">#<?= (int)$website['id'] ?></small></strong><small><?= e($website['website_url']) ?></small></span>
                            <em><?= e($meta['label']) ?></em>
                        </summary>

                        <form method="post" class="pro-form pro-website-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_website">
                            <input type="hidden" name="website_id" value="<?= (int)$website['id'] ?>">

                            <div class="pro-form-row">
                                <div><label>Name</label><input type="text" name="website_name" value="<?= e($website['website_name']) ?>" required></div>
                                <div><label>URL</label><input type="url" name="website_url" value="<?= e($website['website_url']) ?>" required></div>
                            </div>

                            <label>Description</label>
                            <input type="text" name="website_description" value="<?= e($website['description']) ?>">

                            <div class="pro-form-row">
                                <div>
                                    <label>Status</label>
                                    <select name="website_status">
                                        <?php foreach (STATUS_OPTIONS as $key => $optionMeta): ?>
                                            <option value="<?= e($key) ?>" <?= $website['current_status'] === $key ? 'selected' : '' ?>>Code <?= (int)$optionMeta['code'] ?> - <?= e($optionMeta['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$website['sort_order'] ?>"></div>
                            </div>

                            <div class="pro-monitor-config">
                                <h4>Monitor Configuration</h4>
                                <div class="pro-form-row">
                                    <div><label>Monitor type</label><select name="monitor_type"><option value="http" <?= ($website['monitor_type'] ?? 'http') === 'http' ? 'selected' : '' ?>>HTTP / HTTPS</option><option value="tcp" <?= ($website['monitor_type'] ?? '') === 'tcp' ? 'selected' : '' ?>>TCP Port</option><option value="dns" <?= ($website['monitor_type'] ?? '') === 'dns' ? 'selected' : '' ?>>DNS Lookup</option></select></div>
                                    <div><label>Response warning (ms)</label><input type="number" name="response_warn_ms" min="0" value="<?= e((string)($website['response_warn_ms'] ?? '')) ?>" placeholder="2500"></div>
                                </div>
                                <div class="pro-form-row"><div><label>Expected HTTP code</label><input type="number" name="expected_http_code" min="100" max="599" value="<?= e((string)($website['expected_http_code'] ?? '')) ?>" placeholder="200"></div><div><label>SSL warning (days)</label><input type="number" name="ssl_warn_days" min="1" max="365" value="<?= (int)($website['ssl_warn_days'] ?? 21) ?>"></div></div>
                                <label>Expected page text</label><input type="text" name="expected_text" value="<?= e((string)($website['expected_text'] ?? '')) ?>" placeholder="Optional content check">
                                <div class="pro-form-row"><div><label>TCP host</label><input type="text" name="tcp_host" value="<?= e((string)($website['tcp_host'] ?? '')) ?>" placeholder="server.example.com"></div><div><label>TCP port</label><input type="number" name="tcp_port" min="1" max="65535" value="<?= e((string)($website['tcp_port'] ?? '')) ?>"></div></div>
                                <label>DNS hostname</label><input type="text" name="dns_host" value="<?= e((string)($website['dns_host'] ?? '')) ?>" placeholder="example.com">
                                <div class="pro-check-row"><label class="pro-check"><input type="checkbox" name="monitor_enabled" value="1" <?= (int)($website['monitor_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>Automatic monitoring enabled</label><label class="pro-check"><input type="checkbox" name="ssl_check" value="1" <?= (int)($website['ssl_check'] ?? 1) === 1 ? 'checked' : '' ?>>SSL expiration check</label></div>
                                <small class="pro-field-hint">Current: <?= e(strtoupper((string)($website['monitor_type'] ?? 'http'))) ?> · Last response <?= isset($website['last_response_ms']) && $website['last_response_ms'] !== null ? (int)$website['last_response_ms'] . 'ms' : 'n/a' ?><?= isset($website['last_ssl_days']) && $website['last_ssl_days'] !== null ? ' · SSL ' . (int)$website['last_ssl_days'] . ' days' : '' ?></small>
                            </div>
                            <label class="pro-check"><input type="checkbox" name="is_primary" value="1" <?= $website['is_primary'] ? 'checked' : '' ?>>Primary Fare Brothers website</label>
                            <div class="pro-actions"><button class="button primary" type="submit">Save Website</button></div>
                        </form>

                        <form method="post" class="pro-delete" onsubmit="return confirm('Remove this website from the status list?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_website">
                            <input type="hidden" name="website_id" value="<?= (int)$website['id'] ?>">
                            <button class="button ghost small" type="submit">Remove Website</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="pro-grid" id="updates">
            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Manual Service Update</h2><p>Use this for support systems that are not website checks.</p></div></div>
                <form method="post" class="pro-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_service">
                    <label>Service</label>
                    <select name="service_id" required><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>"><?= e($service['service_name']) ?></option><?php endforeach; ?></select>
                    <label>New Status</label>
                    <select name="new_status" required><?php foreach (STATUS_OPTIONS as $key => $meta): ?><option value="<?= e($key) ?>">Code <?= (int)$meta['code'] ?> - <?= e($meta['label']) ?></option><?php endforeach; ?></select>
                    <label>Update title</label>
                    <input type="text" name="update_title" placeholder="Example: Planned infrastructure maintenance scheduled">
                    <label>Update message / reason</label>
                    <textarea name="update_message" rows="4" placeholder="Explain what changed and what users may notice."></textarea>
                    <button class="button primary" type="submit">Post Update</button>
                </form>
            </article>

            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Add Announcement</h2><p>Public notice shown until hidden.</p></div></div>
                <form method="post" class="pro-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_announcement">
                    <label>Title</label>
                    <input type="text" name="announcement_title" required>
                    <label>Body</label>
                    <textarea name="announcement_body" rows="5" required></textarea>
                    <button class="button primary" type="submit">Publish Announcement</button>
                </form>
            </article>
        </section>

        <section class="pro-grid pro-grid-three activity-grid">
            <article class="pro-card activity-card">
                <div class="pro-card-head activity-card-head">
                    <div>
                        <h2>Announcements</h2>
                        <p><?= count($announcements) ?> recent announcement<?= count($announcements) === 1 ? '' : 's' ?></p>
                    </div>
                </div>
                <?php if (!$announcements): ?><p class="empty">No announcements yet.</p><?php endif; ?>
                <?php if ($announcements): ?>
                    <div class="activity-scroll announcement-list">
                        <?php foreach ($announcements as $item): ?>
                            <div class="pro-log activity-row announcement-row">
                                <strong><?= e($item['title']) ?></strong>
                                <p><?= e(mb_strimwidth($item['body'], 0, 120, '...')) ?></p>
                                <small><?= $item['is_active'] ? 'Active' : 'Hidden' ?> · <?= e(format_dt($item['created_at'])) ?></small>
                                <div class="control-row">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>">
                                        <button class="button ghost small" type="submit"><?= $item['is_active'] ? 'Hide' : 'Show' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Delete this announcement permanently?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>">
                                        <button class="button danger small" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="pro-card activity-card">
                <div class="pro-card-head activity-card-head">
                    <div>
                        <h2>Recent Updates</h2>
                        <p>Showing <?= number_format(count($updates)) ?> of <?= number_format($totalUpdates) ?> total updates. Select multiple entries for fast cleanup.</p>
                    </div>
                    <form method="post" class="inline-clear" onsubmit="return confirm('Clear old status updates and keep only the latest 10?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="clear_old_updates">
                        <input type="hidden" name="keep_updates" value="10">
                        <button class="button ghost small nowrap" type="submit">Keep Latest 10</button>
                    </form>
                </div>
                <?php if (!$updates): ?><p class="empty">No recent updates.</p><?php endif; ?>
                <?php if ($updates): ?>
                    <form method="post" id="updatesBulkForm" class="bulk-delete-form" onsubmit="return confirmUpdatesBulkDelete();">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_selected_updates">
                        <input type="hidden" name="selected_update_ids" id="selectedUpdateIds" value="">

                        <div class="bulk-toolbar">
                            <label class="bulk-select-all">
                                <input type="checkbox" id="updatesSelectAll">
                                <span>Select all shown</span>
                            </label>
                            <span class="bulk-selected-count" id="updatesSelectedCount">0 selected</span>
                            <button class="button danger small nowrap" id="updatesDeleteSelected" type="submit" disabled>Delete Selected</button>
                        </div>

                        <div class="activity-scroll bulk-list" id="updatesList">
                            <?php foreach ($updates as $item): ?>
                                <label class="pro-log activity-row bulk-row update-row">
                                    <span class="bulk-check">
                                        <input class="updates-checkbox" type="checkbox" name="update_ids[]" value="<?= (int)$item['id'] ?>" data-record-id="<?= (int)$item['id'] ?>">
                                    </span>
                                    <span class="bulk-content">
                                        <strong><?= e($item['update_title']) ?></strong>
                                        <?php if (trim((string)$item['update_message']) !== ''): ?><p><?= e($item['update_message']) ?></p><?php endif; ?>
                                        <small><?= e($item['service_name'] ?? 'General') ?> · <?= e(format_dt($item['created_at'])) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </article>

            <article class="pro-card activity-card monitor-card">
                <div class="pro-card-head activity-card-head monitor-log-head">
                    <div>
                        <h2>Monitor Log</h2>
                        <p>Showing <?= number_format(count($monitorLogs)) ?> of <?= number_format($totalMonitorLogs) ?> total checks. Select multiple entries for fast cleanup.</p>
                    </div>
                    <form method="post" class="inline-clear" onsubmit="return confirm('Clear ALL monitor logs? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="clear_monitor_logs">
                        <button class="button ghost small nowrap" type="submit">Clear All Logs</button>
                    </form>
                </div>
                <?php if (!$monitorLogs): ?><p class="empty">No monitor checks yet.</p><?php endif; ?>
                <?php if ($monitorLogs): ?>
                    <form method="post" id="monitorBulkForm" class="bulk-delete-form" onsubmit="return confirmMonitorBulkDelete();">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_selected_monitor_logs">
                        <input type="hidden" name="selected_monitor_log_ids" id="selectedMonitorLogIds" value="">

                        <div class="bulk-toolbar">
                            <label class="bulk-select-all">
                                <input type="checkbox" id="monitorSelectAll">
                                <span>Select all shown</span>
                            </label>
                            <span class="bulk-selected-count" id="monitorSelectedCount">0 selected</span>
                            <button class="button danger small nowrap" id="monitorDeleteSelected" type="submit" disabled>Delete Selected</button>
                        </div>

                        <div class="activity-scroll bulk-list" id="monitorLogList">
                            <?php foreach ($monitorLogs as $item): ?>
                                <label class="pro-log activity-row bulk-row monitor-log-row">
                                    <span class="bulk-check">
                                        <input class="monitor-log-checkbox" type="checkbox" name="monitor_log_ids[]" value="<?= (int)$item['id'] ?>" data-record-id="<?= (int)$item['id'] ?>">
                                    </span>
                                    <span class="bulk-content monitor-log-content">
                                        <span class="pro-log-status <?= e(($item['result'] ?? '') === 'online' ? 'online' : 'offline') ?>"><i></i><?= e($item['result'] ?? 'unknown') ?></span>
                                        <strong><?= e($item['website_name'] ?? $item['service_name'] ?? 'Unknown Target') ?></strong>
                                        <p><?= e(strtoupper((string)($item['monitor_type'] ?? 'http'))) ?> · <?= e((string)($item['source'] ?? 'local')) ?> · HTTP: <?= e((string)($item['http_code'] ?? 'none')) ?> · <?= e((string)($item['response_ms'] ?? '')) ?>ms<?php if ($item['error_message']): ?><br><?= e($item['error_message']) ?><?php endif; ?></p>
                                        <small><?= e(format_dt($item['created_at'])) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </article>
        </section>
    </main>
    <script>
    (() => {
        const wireBulkDelete = ({
            selectAllId,
            checkboxSelector,
            countId,
            deleteId,
            rowSelector,
            confirmName,
            noun,
            hiddenId
        }) => {
            const selectAll = document.getElementById(selectAllId);
            const checkboxes = Array.from(document.querySelectorAll(checkboxSelector));
            const countLabel = document.getElementById(countId);
            const deleteButton = document.getElementById(deleteId);
            const hiddenIds = document.getElementById(hiddenId);

            if (!selectAll || !checkboxes.length || !countLabel || !deleteButton || !hiddenIds) return;

            const refresh = () => {
                const selectedBoxes = checkboxes.filter((box) => box.checked);
                const selected = selectedBoxes.length;
                hiddenIds.value = selectedBoxes.map((box) => box.dataset.recordId || box.value).join(',');
                countLabel.textContent = `${selected} selected`;
                deleteButton.disabled = selected === 0;
                selectAll.checked = selected === checkboxes.length;
                selectAll.indeterminate = selected > 0 && selected < checkboxes.length;

                checkboxes.forEach((box) => {
                    box.closest(rowSelector)?.classList.toggle('selected', box.checked);
                });
            };

            selectAll.addEventListener('change', () => {
                checkboxes.forEach((box) => { box.checked = selectAll.checked; });
                refresh();
            });

            checkboxes.forEach((box) => box.addEventListener('change', refresh));
            refresh();

            window[confirmName] = () => {
                const selected = checkboxes.filter((box) => box.checked).length;
                if (!selected) return false;
                return confirm(`Delete ${selected} selected ${noun}${selected === 1 ? '' : 's'}? This cannot be undone.`);
            };
        };

        wireBulkDelete({
            selectAllId: 'updatesSelectAll',
            checkboxSelector: '.updates-checkbox',
            countId: 'updatesSelectedCount',
            deleteId: 'updatesDeleteSelected',
            rowSelector: '.update-row',
            confirmName: 'confirmUpdatesBulkDelete',
            noun: 'status update',
            hiddenId: 'selectedUpdateIds'
        });

        wireBulkDelete({
            selectAllId: 'monitorSelectAll',
            checkboxSelector: '.monitor-log-checkbox',
            countId: 'monitorSelectedCount',
            deleteId: 'monitorDeleteSelected',
            rowSelector: '.monitor-log-row',
            confirmName: 'confirmMonitorBulkDelete',
            noun: 'monitor log entry',
            hiddenId: 'selectedMonitorLogIds'
        });
    })();
    </script>
</body>
</html>
