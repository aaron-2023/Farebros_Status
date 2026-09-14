<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

require_once __DIR__ . '/_layout.php';

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
<?php
admin_page_start(
    'security',
    'Security',
    'Update the administrator password and protect access to the status platform.',
    'Administration',
    []
);
?>

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
<?php admin_page_end(); ?>
