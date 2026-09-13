<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $timezone = $_POST['site_timezone'] ?? 'America/Detroit';
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('Invalid timezone selected.');
        }

        $timeFormat = $_POST['time_format'] ?? '12h';
        $timeFormat = $timeFormat === '24h' ? '24h' : '12h';
        $dateFormat = $timeFormat === '24h' ? 'M j, Y H:i' : 'M j, Y g:i A';

        $refreshSeconds = (int)($_POST['public_refresh_seconds'] ?? 15);
        $refreshSeconds = max(5, min(300, $refreshSeconds));

        set_setting('site_timezone', $timezone);
        set_setting('time_format', $timeFormat);
        set_setting('date_format', $dateFormat);
        set_setting('public_refresh_seconds', (string)$refreshSeconds);
        set_setting('support_email', trim($_POST['support_email'] ?? 'support@farebros.com'));
        set_setting('company_name', trim($_POST['company_name'] ?? 'Fare Brothers, LLC'));
        set_setting('status_page_label', trim($_POST['status_page_label'] ?? 'Status Center'));
        set_setting('public_admin_link', !empty($_POST['public_admin_link']) ? '1' : '0');

        $notice = 'Settings saved.';
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$currentTimezone = site_timezone();
$currentTimeFormat = site_time_format();
$currentRefresh = (int)get_setting('public_refresh_seconds', '15');
$currentSupport = get_setting('support_email', 'support@farebros.com');
$currentCompany = get_setting('company_name', 'Fare Brothers, LLC');
$currentLabel = get_setting('status_page_label', 'Status Center');
$currentAdminLink = get_setting('public_admin_link', '1') === '1';
$now = site_now();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Settings - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
</head>
<body class="admin-pro">
    <aside class="pro-sidebar">
        <div class="pro-brand">
            <div class="pro-brand-pill">Fare Brothers</div>
            <h2>Status Admin</h2>
            <p>Site settings and display preferences.</p>
        </div>

        <nav class="pro-nav">
            <div class="pro-nav-group">
                <small>Main</small>
                <a href="/admin/dashboard.php"><span>▣</span>Dashboard</a>
                <a href="/" target="_blank"><span>↗</span>Public Page</a>
            </div>
            <div class="pro-nav-group">
                <small>Manage</small>
                <a href="/admin/schedules.php"><span>🗓</span>Schedules</a>
            </div>
            <div class="pro-nav-group">
                <small>Admin</small>
                <a class="active" href="/admin/settings.php"><span>⚙</span>Settings</a>
                <a href="/admin/change-password.php"><span>🔒</span>Password</a>
                <a href="/admin/logout.php"><span>⎋</span>Logout</a>
            </div>
        </nav>
    </aside>

    <main class="pro-main">
        <header class="pro-topbar">
            <div>
                <div class="pro-kicker">Settings</div>
                <h1>Site Settings</h1>
                <p>Control timezone, refresh speed, company info, and public-page display options.</p>
            </div>

            <div class="pro-top-actions">
                <a class="button ghost" href="/admin/dashboard.php">Back to Dashboard</a>
                <a class="button primary" href="/" target="_blank">Preview Public Page</a>
            </div>
        </header>

        <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice-box danger"><?= e($error) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <section class="pro-grid">
                <article class="pro-card">
                    <div class="pro-card-head">
                        <div>
                            <h2>Time & Display</h2>
                            <p>Fixes the times shown across the public page, logs, schedules, and dashboard.</p>
                        </div>
                    </div>

                    <div class="pro-form">
                        <label>Timezone</label>
                        <select name="site_timezone">
                            <?php foreach ($timezoneOptions as $tz => $label): ?>
                                <option value="<?= e($tz) ?>" <?= $currentTimezone === $tz ? 'selected' : '' ?>>
                                    <?= e($label) ?> — <?= e($tz) ?>
                                </option>
                            <?php endforeach; ?>

                            <?php if (!array_key_exists($currentTimezone, $timezoneOptions)): ?>
                                <option value="<?= e($currentTimezone) ?>" selected><?= e($currentTimezone) ?></option>
                            <?php endif; ?>
                        </select>

                        <label>Time format</label>
                        <select name="time_format">
                            <option value="12h" <?= $currentTimeFormat === '12h' ? 'selected' : '' ?>>12-hour time, example: 10:00 PM</option>
                            <option value="24h" <?= $currentTimeFormat === '24h' ? 'selected' : '' ?>>24-hour time, example: 22:00</option>
                        </select>

                        <label>Public refresh speed</label>
                        <input type="number" name="public_refresh_seconds" min="5" max="300" value="<?= (int)$currentRefresh ?>">

                        <div class="pro-mini-stack">
                            <div>
                                <small>Current selected time</small>
                                <strong><?= e($now->format(site_date_format())) ?></strong>
                            </div>
                            <div>
                                <small>Timezone</small>
                                <strong><?= e(site_timezone()) ?> · <?= e($now->format('T')) ?></strong>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="pro-card">
                    <div class="pro-card-head">
                        <div>
                            <h2>Public Page Basics</h2>
                            <p>Basic branding and public display behavior.</p>
                        </div>
                    </div>

                    <div class="pro-form">
                        <label>Company name</label>
                        <input type="text" name="company_name" value="<?= e($currentCompany) ?>">

                        <label>Status page label</label>
                        <input type="text" name="status_page_label" value="<?= e($currentLabel) ?>">

                        <label>Support email</label>
                        <input type="email" name="support_email" value="<?= e($currentSupport) ?>">

                        <label class="pro-check">
                            <input type="checkbox" name="public_admin_link" value="1" <?= $currentAdminLink ? 'checked' : '' ?>>
                            Show Admin Login link on public page
                        </label>
                    </div>
                </article>
            </section>

            <section class="pro-card pro-card-full">
                <div class="pro-card-head">
                    <div>
                        <h2>Server Time Check</h2>
                        <p>The site converts stored database timestamps into the selected timezone above.</p>
                    </div>
                </div>

                <div class="pro-status-strip pro-status-strip-four">
                    <article>
                        <span class="pro-icon">⏱</span>
                        <small>Selected Timezone</small>
                        <strong><?= e(site_timezone()) ?></strong>
                        <em><?= e(site_now()->format('T')) ?></em>
                    </article>
                    <article>
                        <span class="pro-icon">🕒</span>
                        <small>Displayed Now</small>
                        <strong><?= e(site_now()->format(site_date_format())) ?></strong>
                        <em>Used on public page</em>
                    </article>
                    <article>
                        <span class="pro-icon">⚙</span>
                        <small>PHP Default</small>
                        <strong><?= e(date_default_timezone_get()) ?></strong>
                        <em>Server setting</em>
                    </article>
                    <article>
                        <span class="pro-icon">🖥</span>
                        <small>Raw Server Time</small>
                        <strong><?= e(date('M j, Y g:i A T')) ?></strong>
                        <em>Before conversion</em>
                    </article>
                </div>

                <div class="pro-actions">
                    <button class="button primary" type="submit">Save Settings</button>
                    <a class="button ghost" href="/admin/dashboard.php">Cancel</a>
                </div>
            </section>
        </form>
    </main>
</body>
</html>
