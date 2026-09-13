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
    <link rel="stylesheet" href="/assets/css/status.css">
</head>
<body class="admin-body">
    <main class="login-card">
        <div class="brand-pill">Fare Brothers Status</div>
        <h1>Admin Login</h1>
        <p class="muted">Update service status, announcements, and incident notices.</p>

        <?php if ($error): ?>
            <div class="notice-box danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <label>Username</label>
            <input type="text" name="username" autocomplete="username" required>

            <label>Password</label>
            <input type="password" name="password" autocomplete="current-password" required>

            <button class="button primary full" type="submit">Login</button>
        </form>

        <a class="sub-link" href="/">Back to public status</a>
    </main>
</body>
</html>
