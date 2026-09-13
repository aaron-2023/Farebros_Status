<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

start_secure_session();

if (current_user()) {
    redirect('/admin/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_user($username, $password)) {
        redirect('/admin/dashboard.php');
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Login - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-v52.css?v=5.3.0">
</head>
<body class="login-v52">
    <main class="login-panel-v52">
        <div class="login-brand-v52">
            <img src="/assets/img/fare-brothers-logo.png" alt="Fare Brothers logo">
            <span>
                <strong>Fare Brothers</strong>
                <small>Status Administration</small>
            </span>
        </div>

        <h1>Welcome back</h1>
        <p class="muted">Manage live system status, website monitoring, announcements, and scheduled maintenance.</p>

        <?php if ($error): ?>
            <div class="notice-box danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <label>Username</label>
            <input type="text" name="username" autocomplete="username" autofocus required>

            <label>Password</label>
            <input type="password" name="password" autocomplete="current-password" required>

            <button class="button primary full" type="submit">Sign In</button>
        </form>

        <div class="login-security-note">This is the administrative control panel for the independent offsite Fare Brothers status service.</div>
        <a class="sub-link" href="/">← Back to public status</a>
    </main>
</body>
</html>
