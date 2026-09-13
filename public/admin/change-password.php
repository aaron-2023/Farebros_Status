<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

$user = require_login();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 10) {
        $error = 'New password must be at least 10 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, (int)$user['id']]);
        $notice = 'Password changed successfully.';
        audit_admin_action($user, 'password_changed', 'user', (int)$user['id']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Change Password - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
    <link rel="stylesheet" href="/assets/css/admin-v52.css?v=5.3.0">
</head>
<body class="admin-pro">
    <aside class="pro-sidebar">
        <div class="pro-brand">
            <div class="pro-brand-pill">Fare Brothers</div>
            <h2>Status Admin</h2>
            <p>Account security and administrator access.</p>
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
                <a href="/admin/incidents.php"><span>⚠</span>Incidents</a>
                <a href="/admin/analytics.php"><span>⌁</span>Analytics</a>
            </div>
            <div class="pro-nav-group">
                <small>Admin</small>
                <a href="/admin/settings.php"><span>⚙</span>Settings</a>
                <a href="/admin/audit-log.php"><span>☷</span>Audit Log</a>
                <a class="active" href="/admin/change-password.php"><span>🔒</span>Password</a>
                <a href="/admin/logout.php"><span>⎋</span>Logout</a>
            </div>
        </nav>
    </aside>

    <main class="pro-main">
        <header class="pro-topbar">
            <div>
                <div class="pro-kicker">Account Security</div>
                <h1>Change Password</h1>
                <p>Update the administrator password for <strong><?= e($user['username']) ?></strong>.</p>
            </div>
            <div class="pro-top-actions">
                <a class="button ghost" href="/admin/dashboard.php">Back to Dashboard</a>
            </div>
        </header>

        <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice-box danger"><?= e($error) ?></div><?php endif; ?>

        <section class="pro-card password-card">
            <div class="pro-card-head">
                <div>
                    <h2>Administrator Password</h2>
                    <p>Use at least 10 characters. A longer unique passphrase is recommended.</p>
                </div>
            </div>

            <form method="post" class="pro-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label>Current password</label>
                <input type="password" name="current_password" autocomplete="current-password" required>

                <div class="pro-form-row">
                    <div>
                        <label>New password</label>
                        <input type="password" name="new_password" autocomplete="new-password" minlength="10" required>
                    </div>
                    <div>
                        <label>Confirm new password</label>
                        <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
                    </div>
                </div>

                <div class="pro-help"><strong>Tip:</strong> Avoid reusing a password from the main Fare Brothers portal or other systems.</div>

                <div class="pro-actions password-actions">
                    <button class="button primary" type="submit">Change Password</button>
                    <a class="button ghost" href="/admin/dashboard.php">Cancel</a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
