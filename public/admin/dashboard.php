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

            $notice = 'Service status updated.';

            if (platform_sync_enabled()) {
                $syncNotice = sync_status_snapshot_to_platform('service_status_updated') ? 'Portal sync sent.' : 'Portal sync failed or was skipped. Check sync log.';
            }
        }
    }

    if ($action === 'add_website') {
        add_website(trim($_POST['website_name'] ?? ''), trim($_POST['website_url'] ?? ''), trim($_POST['website_description'] ?? ''), !empty($_POST['is_primary']));
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
        db()->prepare('DELETE FROM monitor_check_logs WHERE id = ?')->execute([$id]);
        $notice = 'Monitor log entry deleted.';
    }

    if ($action === 'clear_monitor_logs') {
        db()->exec('DELETE FROM monitor_check_logs');
        $notice = 'Monitor logs cleared.';
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
}

$payload = public_status_payload();
$services = get_services();
$websites = get_websites();
$announcements = db()->query('SELECT * FROM announcements ORDER BY created_at DESC LIMIT 20')->fetchAll();
$updates = get_recent_updates(20);
$monitorLogs = get_recent_monitor_logs(10);
$statusMessage = get_setting('status_message', '');
$primary = $payload['primary'];
$overall = $payload['overall'];

$activeAnnouncements = array_filter($announcements, fn($a) => (int)$a['is_active'] === 1);
$offlineWebsites = array_filter($websites, fn($w) => ($w['current_status'] ?? '') !== 'operational');
$lastMonitor = $monitorLogs[0] ?? null;

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
                <a href="#websites"><span>🌐</span>Websites</a>
                <a href="#updates"><span>📣</span>Updates</a>
            </div>

            <div class="pro-nav-group">
                <small>Admin</small>
                <a href="/admin/settings.php"><span>⚙</span>Settings</a>
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
                            <span><strong><?= e($website['website_name']) ?><?= $website['is_primary'] ? ' · Primary' : '' ?></strong><small><?= e($website['website_url']) ?></small></span>
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

        <section class="pro-grid pro-grid-three">
            <article class="pro-card">
                <div class="pro-card-head"><h2>Announcements</h2></div>
                <?php if (!$announcements): ?><p class="empty">No announcements yet.</p><?php endif; ?>
                <?php foreach ($announcements as $item): ?>
                    <div class="pro-log">
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
            </article>

            <article class="pro-card">
                <div class="pro-card-head">
                    <h2>Recent Updates</h2>
                    <form method="post" class="inline-clear" onsubmit="return confirm('Clear old status updates and keep only the latest 10?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="clear_old_updates">
                        <input type="hidden" name="keep_updates" value="10">
                        <button class="button ghost small" type="submit">Keep Latest 10</button>
                    </form>
                </div>
                <?php if (!$updates): ?><p class="empty">No recent updates.</p><?php endif; ?>
                <?php foreach ($updates as $item): ?>
                    <div class="pro-log">
                        <strong><?= e($item['update_title']) ?></strong>
                        <p><?= e($item['update_message']) ?></p>
                        <small><?= e($item['service_name'] ?? 'General') ?> · <?= e(format_dt($item['created_at'])) ?></small>
                        <form method="post" onsubmit="return confirm('Delete this status update permanently?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_update">
                            <input type="hidden" name="update_id" value="<?= (int)$item['id'] ?>">
                            <button class="button danger small" type="submit">Delete Update</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </article>

            <article class="pro-card">
                <div class="pro-card-head">
                    <h2>Monitor Log</h2>
                    <form method="post" class="inline-clear" onsubmit="return confirm('Clear all monitor logs?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="clear_monitor_logs">
                        <button class="button ghost small" type="submit">Clear Logs</button>
                    </form>
                </div>
                <?php if (!$monitorLogs): ?><p class="empty">No monitor checks yet.</p><?php endif; ?>
                <?php foreach ($monitorLogs as $item): ?>
                    <div class="pro-log">
                        <strong><?= e($item['website_name'] ?? 'Unknown Website') ?> · <?= e($item['result']) ?></strong>
                        <p>HTTP: <?= e((string)($item['http_code'] ?? 'none')) ?> · <?= e((string)($item['response_ms'] ?? '')) ?>ms<?php if ($item['error_message']): ?><br><?= e($item['error_message']) ?><?php endif; ?></p>
                        <small><?= e(format_dt($item['created_at'])) ?></small>
                    </div>
                <?php endforeach; ?>
            </article>
        </section>
    </main>
</body>
</html>
