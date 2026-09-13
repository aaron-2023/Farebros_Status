<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/notifications.php';
require_once __DIR__ . '/../../app/maintenance.php';

$user = require_login();
$notice = null;
$error = null;

$timezoneOptions = [
    'America/Detroit' => 'Eastern Time - Detroit / Michigan',
    'America/New_York' => 'Eastern Time - New York',
    'America/Chicago' => 'Central Time',
    'America/Denver' => 'Mountain Time',
    'America/Phoenix' => 'Arizona Time',
    'America/Los_Angeles' => 'Pacific Time',
    'UTC' => 'UTC',
];

function save_settings_from_post(): void
{
    $timezone = $_POST['site_timezone'] ?? 'America/Detroit';
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new RuntimeException('Invalid timezone selected.');
    }
    $timeFormat = ($_POST['time_format'] ?? '12h') === '24h' ? '24h' : '12h';
    $refreshSeconds = max(5, min(300, (int)($_POST['public_refresh_seconds'] ?? 15)));

    $simple = [
        'site_timezone' => $timezone,
        'time_format' => $timeFormat,
        'date_format' => $timeFormat === '24h' ? 'M j, Y H:i' : 'M j, Y g:i A',
        'public_refresh_seconds' => (string)$refreshSeconds,
        'support_email' => trim((string)($_POST['support_email'] ?? 'support@farebros.com')),
        'company_name' => trim((string)($_POST['company_name'] ?? 'Fare Brothers, LLC')),
        'status_page_label' => trim((string)($_POST['status_page_label'] ?? 'Status Center')),
        'public_admin_link' => !empty($_POST['public_admin_link']) ? '1' : '0',
        'monitor_failures_to_offline' => (string)max(1, min(20, (int)($_POST['monitor_failures_to_offline'] ?? 3))),
        'monitor_successes_to_online' => (string)max(1, min(20, (int)($_POST['monitor_successes_to_online'] ?? 2))),
        'monitor_degraded_confirmations' => (string)max(1, min(20, (int)($_POST['monitor_degraded_confirmations'] ?? 2))),
        'monitor_consensus_window_minutes' => (string)max(1, min(30, (int)($_POST['monitor_consensus_window_minutes'] ?? 5))),
        'monitor_log_retention_days' => (string)max(1, min(365, (int)($_POST['monitor_log_retention_days'] ?? 30))),
        'backup_retention_days' => (string)max(1, min(365, (int)($_POST['backup_retention_days'] ?? 7))),
        'backup_enabled' => !empty($_POST['backup_enabled']) ? '1' : '0',
        'smtp_enabled' => !empty($_POST['smtp_enabled']) ? '1' : '0',
        'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
        'smtp_port' => (string)max(1, min(65535, (int)($_POST['smtp_port'] ?? 587))),
        'smtp_security' => in_array(($_POST['smtp_security'] ?? 'tls'), ['tls', 'ssl', 'none'], true) ? (string)$_POST['smtp_security'] : 'tls',
        'smtp_username' => trim((string)($_POST['smtp_username'] ?? '')),
        'smtp_from_email' => trim((string)($_POST['smtp_from_email'] ?? 'status@farebros.com')),
        'smtp_from_name' => trim((string)($_POST['smtp_from_name'] ?? 'Fare Brothers Status')),
        'alert_email_enabled' => !empty($_POST['alert_email_enabled']) ? '1' : '0',
        'alert_email_to' => trim((string)($_POST['alert_email_to'] ?? '')),
        'discord_enabled' => !empty($_POST['discord_enabled']) ? '1' : '0',
        'discord_webhook_url' => trim((string)($_POST['discord_webhook_url'] ?? '')),
        'discord_username' => trim((string)($_POST['discord_username'] ?? 'Fare Brothers Status')),
        'external_monitor_enabled' => !empty($_POST['external_monitor_enabled']) ? '1' : '0',
        'external_monitor_stale_minutes' => (string)max(1, min(120, (int)($_POST['external_monitor_stale_minutes'] ?? 5))),
        'public_history_days' => (string)max(30, min(90, (int)($_POST['public_history_days'] ?? 90))),
    ];

    foreach ($simple as $key => $value) {
        set_setting($key, $value);
    }

    $password = (string)($_POST['smtp_password'] ?? '');
    if ($password !== '') {
        set_setting('smtp_password', $password);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save_settings');
    try {
        if (in_array($action, ['save_settings', 'test_smtp', 'test_discord', 'generate_external_key'], true)) {
            save_settings_from_post();
        }

        if ($action === 'save_settings') {
            $notice = 'Settings saved.';
        } elseif ($action === 'test_smtp') {
            $to = trim((string)get_setting('alert_email_to', ''));
            $firstTo = preg_split('/[;,\s]+/', $to, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
            if ($firstTo === '') {
                throw new RuntimeException('Enter at least one Alert recipient email before testing SMTP.');
            }
            $result = smtp_send($firstTo, 'Fare Brothers Status SMTP Test', "SMTP is configured correctly.\n\nThis is a test from " . APP_URL);
            notification_log_write('email', 'test', 'SMTP Test', $firstTo, true, $result['message']);
            $notice = 'Test email sent to ' . $firstTo . '.';
        } elseif ($action === 'test_discord') {
            $result = discord_send('Fare Brothers Status Test', 'Discord alerts are configured correctly. This is a test notification.', 'operational');
            notification_log_write('discord', 'test', 'Discord Test', 'Discord webhook', true, $result['message']);
            $notice = 'Test Discord notification sent.';
        } elseif ($action === 'generate_external_key') {
            set_setting('external_monitor_key', bin2hex(random_bytes(24)));
            $notice = 'New external monitor key generated. Update any secondary monitor agents with the new key.';
        } elseif ($action === 'run_housekeeping') {
            $result = run_status_housekeeping(true);
            $notice = 'Housekeeping complete: ' . number_format((int)($result['monitor_logs_deleted'] ?? 0)) . ' old monitor logs removed; backup ' . (($result['backup_created'] ?? null) ?: 'not created') . '.';
        }
        audit_admin_action($user, $action, 'settings');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        try {
            if ($action === 'test_smtp') {
                $to = trim((string)get_setting('alert_email_to', ''));
                notification_log_write('email', 'test', 'SMTP Test', $to !== '' ? $to : 'not configured', false, $ex->getMessage());
            } elseif ($action === 'test_discord') {
                notification_log_write('discord', 'test', 'Discord Test', 'Discord webhook', false, $ex->getMessage());
            }
        } catch (Throwable $ignored) {
            // Do not hide the original delivery error if logging itself fails.
        }
        audit_admin_action($user, $action . '_failed', 'settings', null, $ex->getMessage());
    }
}

$g = static fn(string $key, string $default = ''): string => (string)get_setting($key, $default);
$currentTimezone = site_timezone();
$currentTimeFormat = site_time_format();
$now = site_now();
$dbHealth = status_database_health();
$monitorSources = get_monitor_source_health();
$websites = get_websites();
$externalKey = $g('external_monitor_key');
$notificationLog = get_recent_notification_log(8);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Settings - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
    <link rel="stylesheet" href="/assets/css/admin-v52.css?v=5.3.0">
</head>
<body class="admin-pro">
<aside class="pro-sidebar">
    <div class="pro-brand"><div class="pro-brand-pill">Fare Brothers</div><h2>Status Admin</h2><p>Monitoring, alerts, retention, and site preferences.</p></div>
    <nav class="pro-nav">
        <div class="pro-nav-group"><small>Main</small><a href="/admin/dashboard.php"><span>▣</span>Dashboard</a><a href="/" target="_blank"><span>↗</span>Public Page</a></div>
        <div class="pro-nav-group"><small>Manage</small><a href="/admin/schedules.php"><span>🗓</span>Schedules</a><a href="/admin/incidents.php"><span>⚠</span>Incidents</a><a href="/admin/analytics.php"><span>⌁</span>Analytics</a></div>
        <div class="pro-nav-group"><small>Admin</small><a class="active" href="/admin/settings.php"><span>⚙</span>Settings</a><a href="/admin/audit-log.php"><span>☷</span>Audit Log</a><a href="/admin/change-password.php"><span>🔒</span>Password</a><a href="/admin/logout.php"><span>⎋</span>Logout</a></div>
    </nav>
</aside>
<main class="pro-main">
    <header class="pro-topbar"><div><div class="pro-kicker">Status Platform v5.3</div><h1>Settings</h1><p>Configure monitoring behavior, notifications, redundancy, and automatic database housekeeping.</p></div><div class="pro-top-actions"><a class="button ghost" href="/admin/dashboard.php">Dashboard</a><a class="button primary" href="/" target="_blank">Public Page</a></div></header>
    <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice-box danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post" id="settingsForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <section class="pro-grid">
            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Time & Public Page</h2><p>Display and branding basics.</p></div></div>
                <div class="pro-form">
                    <label>Timezone</label><select name="site_timezone"><?php foreach ($timezoneOptions as $tz => $label): ?><option value="<?= e($tz) ?>" <?= $currentTimezone === $tz ? 'selected' : '' ?>><?= e($label) ?> — <?= e($tz) ?></option><?php endforeach; ?></select>
                    <label>Time format</label><select name="time_format"><option value="12h" <?= $currentTimeFormat === '12h' ? 'selected' : '' ?>>12-hour</option><option value="24h" <?= $currentTimeFormat === '24h' ? 'selected' : '' ?>>24-hour</option></select>
                    <label>Public refresh speed</label><input type="number" name="public_refresh_seconds" min="5" max="300" value="<?= (int)$g('public_refresh_seconds', '15') ?>">
                    <label>Public history range</label><select name="public_history_days"><option value="30" <?= $g('public_history_days','90') === '30' ? 'selected' : '' ?>>30 days</option><option value="60" <?= $g('public_history_days','90') === '60' ? 'selected' : '' ?>>60 days</option><option value="90" <?= $g('public_history_days','90') === '90' ? 'selected' : '' ?>>90 days</option></select>
                    <label>Company name</label><input type="text" name="company_name" value="<?= e($g('company_name','Fare Brothers, LLC')) ?>">
                    <label>Status page label</label><input type="text" name="status_page_label" value="<?= e($g('status_page_label','Status Center')) ?>">
                    <label>Support email</label><input type="email" name="support_email" value="<?= e($g('support_email','support@farebros.com')) ?>">
                    <label class="pro-check"><input type="checkbox" name="public_admin_link" value="1" <?= $g('public_admin_link','1') === '1' ? 'checked' : '' ?>>Show Admin Login on public page</label>
                    <small class="pro-field-hint">Current: <?= e($now->format(site_date_format())) ?> · <?= e($now->format('T')) ?></small>
                </div>
            </article>

            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Flap Protection</h2><p>Prevents one bad request from turning into a public outage.</p></div></div>
                <div class="pro-form">
                    <label>Failures before Offline</label><input type="number" name="monitor_failures_to_offline" min="1" max="20" value="<?= (int)$g('monitor_failures_to_offline','3') ?>">
                    <label>Successes before recovery</label><input type="number" name="monitor_successes_to_online" min="1" max="20" value="<?= (int)$g('monitor_successes_to_online','2') ?>">
                    <label>Slow/degraded checks before Degraded</label><input type="number" name="monitor_degraded_confirmations" min="1" max="20" value="<?= (int)$g('monitor_degraded_confirmations','2') ?>">
                    <label>Multi-monitor consensus window</label><input type="number" name="monitor_consensus_window_minutes" min="1" max="30" value="<?= (int)$g('monitor_consensus_window_minutes','5') ?>">
                    <div class="pro-help"><strong>Recommended:</strong> 3 failures / 2 recoveries. With two monitor locations, a split result becomes Degraded instead of falsely declaring an outage.</div>
                </div>
            </article>
        </section>

        <section class="pro-grid">
            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Email / SMTP Alerts</h2><p>Send alerts for confirmed incidents and recoveries.</p></div><span class="pro-chip">Optional</span></div>
                <div class="pro-form">
                    <label class="pro-check"><input type="checkbox" name="smtp_enabled" value="1" <?= $g('smtp_enabled') === '1' ? 'checked' : '' ?>>Enable SMTP</label>
                    <div class="pro-form-row"><div><label>SMTP host</label><input type="text" name="smtp_host" value="<?= e($g('smtp_host')) ?>" placeholder="smtp.office365.com"></div><div><label>Port</label><input type="number" name="smtp_port" value="<?= (int)$g('smtp_port','587') ?>"></div></div>
                    <label>Security</label><select name="smtp_security"><option value="tls" <?= $g('smtp_security','tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (usually port 587)</option><option value="ssl" <?= $g('smtp_security') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (usually port 465)</option><option value="none" <?= $g('smtp_security') === 'none' ? 'selected' : '' ?>>None</option></select>
                    <label>Username</label><input type="text" name="smtp_username" autocomplete="off" value="<?= e($g('smtp_username')) ?>">
                    <label>Password / app password</label><input type="password" name="smtp_password" autocomplete="new-password" placeholder="Leave blank to keep saved password"><small class="pro-field-hint">The password is stored in the protected SQLite settings database. Use an app-specific SMTP password when your provider supports one.</small>
                    <div class="pro-help"><strong>Common SMTP setups:</strong><br><strong>Microsoft 365:</strong> <code>smtp.office365.com</code> · port <code>587</code> · STARTTLS · full email address as username. Your tenant must allow authenticated SMTP for that mailbox.<br><strong>Gmail:</strong> <code>smtp.gmail.com</code> · port <code>587</code> · STARTTLS · full Gmail address · use an App Password when 2-Step Verification is enabled.<br><strong>Other mail providers:</strong> use the SMTP host, port, security type, username, and password supplied by that provider. Then use the test button below — a failed test will show the server's actual SMTP error.</div>
                    <div class="pro-form-row"><div><label>From email</label><input type="email" name="smtp_from_email" value="<?= e($g('smtp_from_email','status@farebros.com')) ?>"></div><div><label>From name</label><input type="text" name="smtp_from_name" value="<?= e($g('smtp_from_name','Fare Brothers Status')) ?>"></div></div>
                    <label class="pro-check"><input type="checkbox" name="alert_email_enabled" value="1" <?= $g('alert_email_enabled') === '1' ? 'checked' : '' ?>>Send incident alerts by email</label>
                    <label>Alert recipient(s)</label><input type="text" name="alert_email_to" value="<?= e($g('alert_email_to')) ?>" placeholder="you@example.com; second@example.com">
                    <div class="pro-actions"><button class="button ghost" type="submit" name="action" value="test_smtp">Save + Send Test Email</button></div>
                </div>
            </article>

            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Discord Alerts</h2><p>Discord webhooks are free and don't require a bot account.</p></div><span class="pro-chip">Easy setup</span></div>
                <div class="pro-form">
                    <label class="pro-check"><input type="checkbox" name="discord_enabled" value="1" <?= $g('discord_enabled') === '1' ? 'checked' : '' ?>>Enable Discord notifications</label>
                    <label>Webhook URL</label><input type="password" name="discord_webhook_url" value="<?= e($g('discord_webhook_url')) ?>" autocomplete="off" placeholder="https://discord.com/api/webhooks/..."><small class="pro-field-hint">Treat the webhook URL like a password — anyone with it can post to that channel.</small>
                    <label>Display name</label><input type="text" name="discord_username" value="<?= e($g('discord_username','Fare Brothers Status')) ?>">
                    <div class="pro-help"><strong>How to get the Discord URL:</strong><br>1. Open the Discord channel you want alerts in.<br>2. Edit Channel → Integrations → Webhooks.<br>3. Create/New Webhook and choose that channel.<br>4. Click <strong>Copy Webhook URL</strong> and paste it above.<br>5. Click the test button here. That's it — no bot coding needed.</div>
                    <div class="pro-actions"><button class="button ghost" type="submit" name="action" value="test_discord">Save + Send Test Discord Alert</button></div>
                </div>
            </article>
        </section>

        <section class="pro-grid">
            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Log Retention & Backups</h2><p>Stops raw monitor checks from growing forever while keeping daily uptime history.</p></div></div>
                <div class="pro-form">
                    <label>Keep raw monitor logs</label><div class="pro-input-suffix"><input type="number" name="monitor_log_retention_days" min="1" max="365" value="<?= (int)$g('monitor_log_retention_days','30') ?>"><span>days</span></div>
                    <label class="pro-check"><input type="checkbox" name="backup_enabled" value="1" <?= $g('backup_enabled','1') === '1' ? 'checked' : '' ?>>Create automatic daily SQLite backup</label>
                    <label>Keep backups</label><div class="pro-input-suffix"><input type="number" name="backup_retention_days" min="1" max="365" value="<?= (int)$g('backup_retention_days','7') ?>"><span>days</span></div>
                    <div class="pro-mini-stack"><div><small>Database size</small><strong><?= e(number_format($dbHealth['database_bytes']/1048576, 1)) ?> MB</strong></div><div><small>Raw checks</small><strong><?= number_format($dbHealth['monitor_logs']) ?></strong></div><div><small>Daily rollups</small><strong><?= number_format($dbHealth['daily_rollups']) ?></strong></div><div><small>Backups</small><strong><?= number_format($dbHealth['backup_count']) ?></strong></div></div>
                    <small class="pro-field-hint">Housekeeping runs once per day from the existing monitor cron. Weekly VACUUM reclaims SQLite file space.</small>
                    <div class="pro-actions"><button class="button ghost" type="submit" name="action" value="run_housekeeping">Run Housekeeping Now</button></div>
                </div>
            </article>

            <article class="pro-card">
                <div class="pro-card-head"><div><h2>Secondary Monitor / Redundancy</h2><p>Let another server report checks so one network path can't create a false outage.</p></div></div>
                <div class="pro-form">
                    <label class="pro-check"><input type="checkbox" name="external_monitor_enabled" value="1" <?= $g('external_monitor_enabled') === '1' ? 'checked' : '' ?>>Accept authenticated external monitor results</label>
                    <label>External monitor secret</label><div class="pro-secret-row"><input type="text" readonly value="<?= e($externalKey ?: 'Generate a key first') ?>"><button class="button ghost small" type="submit" name="action" value="generate_external_key">Generate New Key</button></div>
                    <label>Mark external source stale after</label><div class="pro-input-suffix"><input type="number" name="external_monitor_stale_minutes" min="1" max="120" value="<?= (int)$g('external_monitor_stale_minutes','5') ?>"><span>minutes</span></div>
                    <div class="pro-help"><strong>Setup:</strong> copy <code>tools/external-monitor-agent.php</code> to a second web server/VPS, enter this status URL + secret key + the Monitor IDs shown below, then run it every minute with cron. Results post to <code><?= e(rtrim(APP_URL, '/')) ?>/api/external-monitor.php</code>. When two locations disagree, the public status becomes Degraded instead of immediately Offline.</div>
                    <div class="pro-monitor-id-list"><strong>Monitor IDs for the secondary agent</strong><?php if (!$websites): ?><p>No websites configured yet.</p><?php endif; ?><?php foreach ($websites as $website): ?><div><code>#<?= (int)$website['id'] ?></code><span><?= e($website['website_name']) ?></span><small><?= e($website['website_url']) ?></small></div><?php endforeach; ?></div>
                    <div class="pro-mini-stack"><?php if (!$monitorSources): ?><div><small>Sources</small><strong>None recorded yet</strong></div><?php endif; ?><?php foreach ($monitorSources as $source): ?><div><small><?= e($source['display_name']) ?></small><strong><?= !empty($source['is_stale']) ? 'Stale' : 'Active' ?></strong><em><?= e(format_dt($source['last_seen_at'])) ?></em></div><?php endforeach; ?></div>
                </div>
            </article>
        </section>

        <section class="pro-card pro-card-full">
            <div class="pro-card-head"><div><h2>Recent Notification Deliveries</h2><p>Quick check that email and Discord alerts are actually leaving the status server.</p></div></div>
            <div class="pro-log-table"><?php if (!$notificationLog): ?><p class="empty">No notifications sent yet.</p><?php endif; ?><?php foreach ($notificationLog as $log): ?><div class="pro-log"><span class="pro-log-status <?= (int)$log['success'] === 1 ? 'online' : 'offline' ?>"><i></i><?= (int)$log['success'] === 1 ? 'Sent' : 'Failed' ?></span><strong><?= e(strtoupper($log['channel'])) ?> · <?= e($log['subject']) ?></strong><p><?= e($log['response']) ?></p><small><?= e(format_dt($log['created_at'])) ?></small></div><?php endforeach; ?></div>
            <div class="pro-actions"><button class="button primary" type="submit" name="action" value="save_settings">Save All Settings</button><a class="button ghost" href="/admin/dashboard.php">Cancel</a></div>
        </section>
    </form>
</main>
</body>
</html>
