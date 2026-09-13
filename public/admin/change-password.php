<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/auth.php';
$user = require_login();
$notice = null; $error = null;
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
</head>
<body class="admin-shell">
    <aside class="admin-sidebar">
        <div class="brand-pill">Fare Brothers</div>
        <h2>Status Admin</h2>
        <nav>
            <a href="/admin/dashboard.php">Dashboard</a>
            <a href="/admin/change-password.php" class="active">Change Password</a>
            <a href="/" target="_blank">View Public Page</a>
            <a href="/admin/logout.php">Logout</a>
        </nav>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar"><div><h1>Change Password</h1><p class="muted">Update the password for <?= e($user['username']) ?>.</p></div></header>
        <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice-box danger"><?= e($error) ?></div><?php endif; ?>
        <section class="admin-panel">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Current password</label>
                <input type="password" name="current_password" autocomplete="current-password" required>
                <label>New password</label>
                <input type="password" name="new_password" autocomplete="new-password" required>
                <label>Confirm new password</label>
                <input type="password" name="confirm_password" autocomplete="new-password" required>
                <button class="button primary" type="submit">Change Password</button>
                <a class="button ghost" href="/admin/dashboard.php">Back to Dashboard</a>
            </form>
        </section>
    </main>
</body>
</html>
