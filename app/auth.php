<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function current_user(): ?array
{
    start_secure_session();

    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, username, display_name, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['admin_user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        redirect('/admin/login.php');
    }

    return $user;
}

function login_user(string $username, string $password): bool
{
    start_secure_session();

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int)$user['id'];

    $stmt = db()->prepare('UPDATE users SET last_login_at = datetime("now") WHERE id = ?');
    $stmt->execute([(int)$user['id']]);

    return true;
}

function logout_user(): void
{
    start_secure_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }

    session_destroy();
}
