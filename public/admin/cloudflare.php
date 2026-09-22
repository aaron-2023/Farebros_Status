<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/platform.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;

$savePostedSettings = static function (bool $clearToken = false): void {
    $replacementToken = trim((string)($_POST['api_token'] ?? ''));
    cloudflare_failover_save_admin_settings([
        'enabled' => !empty($_POST['enabled']),
        'zone_id' => (string)($_POST['zone_id'] ?? ''),
        'ruleset_id' => (string)($_POST['ruleset_id'] ?? ''),
        'rule_id' => (string)($_POST['rule_id'] ?? ''),
        'redirect_url' => (string)($_POST['redirect_url'] ?? ''),
        'hosts' => (string)($_POST['hosts'] ?? ''),
        'failure_threshold' => (int)($_POST['failure_threshold'] ?? 3),
        'recovery_threshold' => (int)($_POST['recovery_threshold'] ?? 3),
        'state_refresh_seconds' => (int)($_POST['state_refresh_seconds'] ?? 300),
        'monitor_user_agent_prefix' => (string)($_POST['monitor_user_agent_prefix'] ?? 'FareBrosStatusMonitor/'),
    ], $replacementToken !== '' ? $replacementToken : null, $clearToken);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');

    try {
        if (in_array($action, ['save', 'test_connection', 'redirect_on', 'redirect_off'], true)) {
            $savePostedSettings(false);
        } elseif ($action === 'clear_token') {
            $savePostedSettings(true);
        }

        if ($action === 'save') {
            $notice = 'Cloudflare failover settings saved.';
        } elseif ($action === 'test_connection') {
            if (!cloudflare_failover_credentials_configured()) {
                throw new RuntimeException('Enter and save a Cloudflare API token first.');
            }
            $rule = cloudflare_failover_get_remote_rule();
            cloudflare_failover_refresh_remote_state(true);
            $notice = 'Cloudflare connection successful. Redirect rule found and current state is ' . (!empty($rule['enabled']) ? 'ON.' : 'OFF.');
        } elseif ($action === 'redirect_on') {
            if (!cloudflare_failover_credentials_configured()) {
                throw new RuntimeException('Cloudflare is not fully configured yet.');
            }
            cloudflare_failover_set_remote(true, false, 'manual_admin_enable');
            cloudflare_failover_log('manual_enable', 'An administrator manually enabled the Fare Brothers redirect from the admin dashboard.', ['user_id' => (int)$user['id']]);
            $notice = 'Emergency redirect enabled. farebros.com now redirects to the configured status URL.';
        } elseif ($action === 'redirect_off') {
            if (!cloudflare_failover_credentials_configured()) {
                throw new RuntimeException('Cloudflare is not fully configured yet.');
            }
            cloudflare_failover_set_remote(false, false, 'manual_admin_disable');
            cloudflare_failover_log('manual_disable', 'An administrator manually disabled the Fare Brothers redirect from the admin dashboard.', ['user_id' => (int)$user['id']]);
            $notice = 'Emergency redirect disabled. farebros.com is back to normal Cloudflare routing.';
        } elseif ($action === 'clear_token') {
            $notice = 'Stored Cloudflare API token cleared. Automatic failover and manual redirect controls will remain unavailable until a new token is saved.';
        } elseif ($action === 'refresh_status') {
            $notice = 'Cloudflare state refreshed.';
        }

        audit_admin_action($user, 'cloudflare_' . $action, 'cloudflare_failover');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        audit_admin_action($user, 'cloudflare_' . $action . '_failed', 'cloudflare_failover', null, $ex->getMessage());
    }
}

$config = cloudflare_failover_config(true);
$canRefresh = cloudflare_failover_credentials_configured();
$status = cloudflare_failover_status($canRefresh);
$state = (array)($status['state'] ?? []);
$remoteState = (string)($state['remote_state'] ?? 'unknown');
$remoteEnabled = $remoteState === 'enabled';
$remoteKnown = in_array($remoteState, ['enabled', 'disabled'], true);
$automationEnabled = !empty($status['enabled']);
$configured = !empty($status['configured']);
$tokenSaved = !empty($status['token_saved']);
$failureCount = (int)($state['consecutive_failures'] ?? 0);
$successCount = (int)($state['consecutive_successes'] ?? 0);
$failThreshold = (int)($status['failure_threshold'] ?? 3);
$recoveryThreshold = (int)($status['recovery_threshold'] ?? 3);

ensure_cloudflare_failover_schema();
$logStmt = db()->query('SELECT * FROM cloudflare_failover_log ORDER BY id DESC LIMIT 12');
$failoverLogs = $logStmt ? $logStmt->fetchAll() : [];

admin_page_start(
    'cloudflare',
    'Cloudflare Failover',
    'Manage automatic outage failover, Cloudflare credentials, and the live emergency redirect.',
    'Monitoring & Failover',
    [['href' => 'https://status.farebros.com', 'label' => 'Open Status Site', 'class' => 'ghost', 'external' => true]]
);
admin_notice($notice);
admin_notice($error, 'danger');
?>

<section class="v54-metrics">
    <article class="v54-metric"><div class="v54-metric-top"><small>API Configuration</small><span class="v54-status-dot <?= $configured ? 'good' : 'bad' ?>"></span></div><strong><?= $configured ? 'Ready' : 'Incomplete' ?></strong><em><?= e((string)$status['token_summary']) ?></em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Automatic Failover</small><span class="v54-status-dot <?= $automationEnabled ? 'good' : 'warn' ?>"></span></div><strong><?= $automationEnabled ? 'Enabled' : 'Disabled' ?></strong><em><?= $failThreshold ?> failures → ON · <?= $recoveryThreshold ?> successes → OFF</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Emergency Redirect</small><span class="v54-status-dot <?= !$remoteKnown ? 'warn' : ($remoteEnabled ? 'bad' : 'good') ?>"></span></div><strong><?= !$remoteKnown ? 'Unknown' : ($remoteEnabled ? 'ON' : 'OFF') ?></strong><em><?= $remoteEnabled ? 'farebros.com is on status failover' : 'Normal FareBros routing' ?></em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Monitor Streak</small><span class="v54-status-dot <?= $failureCount > 0 ? 'warn' : 'good' ?>"></span></div><strong><?= $failureCount > 0 ? $failureCount . ' failed' : $successCount . ' healthy' ?></strong><em>Current consecutive primary-site checks</em></article>
</section>

<form method="post" class="pro-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <section class="pro-grid">
        <article class="pro-card">
            <div class="pro-card-head"><div><h2>Emergency Redirect</h2><p>Manual control of the Cloudflare rule right now.</p></div><span class="v54-status-pill <?= $remoteEnabled ? 'bad' : ($remoteKnown ? '' : 'warn') ?>"><?= e(strtoupper($remoteState)) ?></span></div>
            <div class="v54-card-body">
                <div class="pro-help"><strong>Current target:</strong> <code><?= e((string)$status['redirect_url']) ?></code><br><strong>Managed hosts:</strong> <?= e(implode(', ', (array)$status['hosts'])) ?><br><strong>Automation ownership:</strong> <?= !empty($state['automation_owns_redirect']) ? 'Automatic failover enabled this redirect' : 'Manual/external state' ?></div>
                <div class="pro-actions">
                    <button class="button danger" type="submit" name="action" value="redirect_on" <?= !$configured ? 'disabled' : '' ?> onclick="return confirm('Turn ON the emergency redirect? Visitors to farebros.com will be sent to the status site.')">Turn Redirect ON</button>
                    <button class="button primary" type="submit" name="action" value="redirect_off" <?= !$configured ? 'disabled' : '' ?>>Turn Redirect OFF</button>
                    <button class="button ghost" type="submit" name="action" value="refresh_status" <?= !$configured ? 'disabled' : '' ?>>Refresh State</button>
                </div>
                <small class="pro-field-hint">A manually enabled redirect stays on until you turn it off. If automatic failover is enabled and the primary site is still failing, a manually disabled redirect can be enabled again on a later failed monitor check.</small>
            </div>
        </article>

        <article class="pro-card">
            <div class="pro-card-head"><div><h2>Automatic Failover</h2><p>Controls when the monitor is allowed to operate the redirect.</p></div></div>
            <div class="pro-form">
                <label class="pro-check"><input type="checkbox" name="enabled" value="1" <?= $automationEnabled ? 'checked' : '' ?>>Enable automatic Cloudflare failover</label>
                <div class="pro-form-row"><div><label>Failures before redirect ON</label><input type="number" name="failure_threshold" min="1" max="20" value="<?= $failThreshold ?>"></div><div><label>Successes before automatic recovery</label><input type="number" name="recovery_threshold" min="1" max="20" value="<?= $recoveryThreshold ?>"></div></div>
                <label>Cloudflare state refresh interval</label><div class="pro-input-suffix"><input type="number" name="state_refresh_seconds" min="60" max="3600" value="<?= (int)$status['state_refresh_seconds'] ?>"><span>seconds</span></div>
                <label>Monitor user-agent bypass prefix</label><input type="text" name="monitor_user_agent_prefix" maxlength="120" value="<?= e((string)$status['monitor_user_agent_prefix']) ?>">
                <div class="pro-help">The redirect rule automatically excludes the status monitor's own user-agent. That lets the monitor keep checking the real Fare Bros site while visitors are being redirected to the status page.</div>
            </div>
        </article>
    </section>

    <section class="pro-grid">
        <article class="pro-card">
            <div class="pro-card-head"><div><h2>Cloudflare API</h2><p>Credentials used only to manage the configured Single Redirect rule.</p></div><span class="pro-chip"><?= $tokenSaved ? 'Token saved' : 'Token required' ?></span></div>
            <div class="pro-form">
                <label>API token</label><input type="password" name="api_token" autocomplete="new-password" placeholder="<?= $tokenSaved ? e('Leave blank to keep saved token · ' . $status['token_summary']) : 'Paste Cloudflare API token' ?>">
                <small class="pro-field-hint">The full token is never displayed back in the admin panel. Paste a new token here only when you want to replace it.</small>
                <label>Zone ID</label><input type="text" name="zone_id" maxlength="32" value="<?= e((string)$status['zone_id']) ?>">
                <label>Ruleset ID</label><input type="text" name="ruleset_id" maxlength="32" value="<?= e((string)$status['ruleset_id']) ?>">
                <label>Redirect Rule ID</label><input type="text" name="rule_id" maxlength="32" value="<?= e((string)$status['rule_id']) ?>">
                <div class="pro-actions"><button class="button ghost" type="submit" name="action" value="test_connection">Save + Test Connection</button><?php if ($tokenSaved): ?><button class="button danger" type="submit" name="action" value="clear_token" onclick="return confirm('Clear the saved Cloudflare API token? Automatic failover will stop until another token is saved.')">Clear Token</button><?php endif; ?></div>
            </div>
        </article>

        <article class="pro-card">
            <div class="pro-card-head"><div><h2>Redirect Definition</h2><p>Hosts controlled by the emergency redirect and where visitors are sent.</p></div></div>
            <div class="pro-form">
                <label>Redirect destination</label><input type="url" name="redirect_url" value="<?= e((string)$status['redirect_url']) ?>" placeholder="https://status.farebros.com">
                <label>Managed hostnames</label><input type="text" name="hosts" value="<?= e(implode(', ', (array)$status['hosts'])) ?>" placeholder="farebros.com, www.farebros.com">
                <small class="pro-field-hint">Do not include <code>status.farebros.com</code> here. The admin prevents saving a destination that would create a redirect loop.</small>
                <div class="pro-help"><strong>Cloudflare rule:</strong><br><code><?= e((string)$status['required_expression']) ?></code></div>
                <div class="pro-actions"><button class="button primary" type="submit" name="action" value="save">Save Cloudflare Settings</button></div>
            </div>
        </article>
    </section>
</form>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Failover Activity</h2><p>Automatic and manual redirect events from this status server.</p></div><a class="v54-card-link" href="/admin/audit-log.php">Admin Audit Log</a></div>
    <div class="pro-log-table">
        <?php if (!$failoverLogs): ?><p class="empty">No Cloudflare failover activity has been recorded yet.</p><?php endif; ?>
        <?php foreach ($failoverLogs as $log): ?>
            <?php $event = (string)$log['event_type']; $isProblem = $event === 'error'; $isEnable = str_contains($event, 'enable'); ?>
            <div class="pro-log">
                <span class="pro-log-status <?= $isProblem || $isEnable ? 'offline' : 'online' ?>"><i></i><?= e(str_replace('_', ' ', strtoupper($event))) ?></span>
                <strong><?= e((string)$log['message']) ?></strong>
                <p>Remote: <?= e((string)($log['remote_state'] ?? 'unknown')) ?> · Failures: <?= (int)$log['consecutive_failures'] ?> · Successes: <?= (int)$log['consecutive_successes'] ?></p>
                <small><?= e(format_dt($log['created_at'])) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php admin_page_end(); ?>
