<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/integration.php';
require_once __DIR__ . '/../../app/monitor.php';
require_once __DIR__ . '/../../app/maintenance.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;
$syncNotice = null;

function monitoring_posted_ids(string $arrayKey, string $csvKey): array
{
    $ids = [];
    foreach ((array)($_POST[$arrayKey] ?? []) as $value) {
        $id = (int)$value;
        if ($id > 0) $ids[] = $id;
    }
    $csv = trim((string)($_POST[$csvKey] ?? ''));
    if ($csv !== '') {
        foreach (explode(',', $csv) as $value) {
            $id = (int)trim($value);
            if ($id > 0) $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function monitoring_delete_verified(string $table, array $ids): array
{
    if (!in_array($table, ['status_updates', 'monitor_check_log'], true)) {
        throw new InvalidArgumentException('Unsupported delete table.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return ['matched' => 0, 'deleted' => 0, 'remaining' => 0];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $before = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})");
    $before->execute($ids);
    $matched = (int)$before->fetchColumn();
    if ($matched > 0) {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
        $delete->execute($ids);
    }
    $after = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})");
    $after->execute($ids);
    $remaining = (int)$after->fetchColumn();
    return ['matched' => $matched, 'deleted' => max(0, $matched - $remaining), 'remaining' => $remaining];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'manual_run_monitor') {
            $result = run_all_monitors();
            if (empty($result['ok'])) {
                throw new RuntimeException((string)($result['message'] ?? 'Monitor run failed.'));
            }
            $notice = 'Monitor completed successfully.';
        }

        if ($action === 'delete_selected_updates') {
            $result = monitoring_delete_verified('status_updates', monitoring_posted_ids('update_ids', 'selected_update_ids'));
            if ($result['remaining'] > 0) throw new RuntimeException($result['remaining'] . ' selected update(s) could not be deleted.');
            $notice = number_format($result['deleted']) . ' status update(s) deleted.';
            if ($result['deleted'] > 0 && platform_sync_enabled()) {
                $syncNotice = sync_status_snapshot_to_platform('status_updates_bulk_deleted') ? 'Portal sync sent.' : 'Updates were deleted, but portal sync failed or was skipped.';
            }
        }

        if ($action === 'keep_latest_updates') {
            $keep = max(0, (int)($_POST['keep_updates'] ?? 25));
            $stmt = db()->prepare('DELETE FROM status_updates WHERE id NOT IN (SELECT id FROM status_updates ORDER BY created_at DESC, id DESC LIMIT ?)');
            $stmt->bindValue(1, $keep, PDO::PARAM_INT);
            $stmt->execute();
            $notice = 'Old status updates cleared. Kept the latest ' . $keep . '.';
        }

        if ($action === 'delete_selected_monitor_logs') {
            $result = monitoring_delete_verified('monitor_check_log', monitoring_posted_ids('monitor_log_ids', 'selected_monitor_log_ids'));
            if ($result['remaining'] > 0) throw new RuntimeException($result['remaining'] . ' selected monitor log(s) could not be deleted.');
            $notice = number_format($result['deleted']) . ' monitor log entr' . ($result['deleted'] === 1 ? 'y' : 'ies') . ' deleted.';
        }

        if ($action === 'clear_monitor_logs') {
            $before = (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn();
            db()->exec('DELETE FROM monitor_check_log');
            $after = (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn();
            $notice = number_format(max(0, $before - $after)) . ' raw monitor logs deleted. New checks will begin appearing again automatically.';
        }

        if ($action !== '') audit_admin_action($user, $action, 'monitoring');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$monitorLogs = get_recent_monitor_logs(100);
$updates = get_recent_updates(100);
$totalMonitorLogs = (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn();
$totalUpdates = (int)db()->query('SELECT COUNT(*) FROM status_updates')->fetchColumn();
$monitorSources = get_monitor_source_health();
$healthySources = array_values(array_filter($monitorSources, static fn(array $source): bool => empty($source['is_stale'])));
$websites = get_websites();
$enabledSites = array_values(array_filter($websites, static fn(array $site): bool => (int)($site['monitor_enabled'] ?? 1) === 1));
$dbHealth = status_database_health();
$retentionDays = (int)get_setting('monitor_log_retention_days', '30');
$lastMonitor = $monitorLogs[0] ?? null;

admin_page_start(
    'monitoring',
    'Monitor & Activity',
    'Run health checks, review monitoring sources, and clean up system activity without cluttering the main dashboard.',
    'Monitoring',
    [
        ['href' => '/admin/analytics.php', 'label' => 'View Analytics', 'class' => 'ghost'],
        ['href' => '/admin/settings.php', 'label' => 'Monitoring Settings', 'class' => 'ghost'],
    ]
);
admin_status_tabs('monitoring');
admin_notice($notice);
if ($syncNotice) admin_notice($syncNotice, str_contains(strtolower($syncNotice), 'failed') ? 'danger' : 'success');
admin_notice($error, 'danger');
?>

<section class="v54-metrics">
    <article class="v54-metric"><div class="v54-metric-top"><small>Automatic Monitor</small><span class="v54-status-dot <?= MONITORING_ENABLED ? 'good' : 'bad' ?>"></span></div><strong><?= MONITORING_ENABLED ? 'Enabled' : 'Disabled' ?></strong><em>Runs every minute</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Monitored Websites</small><span class="v54-status-dot <?= count($enabledSites) ? 'good' : 'warn' ?>"></span></div><strong><?= count($enabledSites) ?></strong><em><?= count($websites) ?> total configured</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Healthy Sources</small><span class="v54-status-dot <?= count($healthySources) >= 2 ? 'good' : 'warn' ?>"></span></div><strong><?= count($healthySources) ?></strong><em><?= count($monitorSources) ?> source<?= count($monitorSources) === 1 ? '' : 's' ?> known</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Raw Monitor Logs</small><span class="v54-status-dot"></span></div><strong><?= number_format($totalMonitorLogs) ?></strong><em><?= $retentionDays ?>-day retention</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Last Check</small><span class="v54-status-dot <?= $lastMonitor ? 'good' : 'warn' ?>"></span></div><strong><?= $lastMonitor ? e(format_dt($lastMonitor['created_at'])) : 'Pending' ?></strong><em><?= $lastMonitor ? e((string)($lastMonitor['website_name'] ?? 'Website')) : 'No monitor data' ?></em></article>
</section>

<section class="v54-grid">
    <article class="pro-card">
        <div class="pro-card-head"><div><h2>Monitor Control</h2><p>Automatic checks continue through cron; use this only when you want an immediate run.</p></div></div>
        <div class="v54-card-body">
            <form method="post" class="pro-actions">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="manual_run_monitor">
                <button class="button primary" type="submit">Run Monitor Now</button>
                <a class="button ghost" href="/admin/websites.php">Website Monitor Settings</a>
            </form>
            <div class="pro-help"><strong>Retention:</strong> Raw checks are retained for <?= $retentionDays ?> days. Daily uptime rollups are preserved after raw logs are pruned.</div>
        </div>
    </article>
    <article class="pro-card">
        <div class="pro-card-head"><div><h2>Database & Housekeeping</h2><p>Quick health view for monitoring storage.</p></div><a class="v54-card-link" href="/admin/settings.php">Settings</a></div>
        <div class="v54-card-body v54-health-grid">
            <div class="v54-health-item"><small>Database</small><strong><?= e(number_format($dbHealth['database_bytes'] / 1048576, 1)) ?> MB</strong><span>Current SQLite file</span></div>
            <div class="v54-health-item"><small>Daily Rollups</small><strong><?= number_format($dbHealth['daily_rollups']) ?></strong><span>Long-term history</span></div>
            <div class="v54-health-item"><small>Backups</small><strong><?= number_format($dbHealth['backup_count']) ?></strong><span><?= e((string)($dbHealth['latest_backup'] ?? 'None yet')) ?></span></div>
        </div>
    </article>
</section>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Monitor Sources</h2><p>Local and offsite monitor heartbeats. Redundancy is healthy when two or more sources are active.</p></div><span class="v54-count"><?= count($healthySources) ?> active</span></div>
    <div class="v54-card-body v54-health-grid">
        <?php if (!$monitorSources): ?><div class="v54-empty v54-full">No monitor source heartbeat has been recorded yet.</div><?php endif; ?>
        <?php foreach ($monitorSources as $source): ?>
            <div class="v54-health-item"><small><?= e((string)$source['display_name']) ?></small><strong><?= !empty($source['is_stale']) ? 'Stale' : 'Active' ?> · <?= e(strtoupper((string)($source['last_result'] ?? 'unknown'))) ?></strong><span>Last seen <?= e(format_dt($source['last_seen_at'] ?? null)) ?></span></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="v54-grid equal" style="margin-top:16px">
    <article class="pro-card" id="updates">
        <div class="pro-card-head"><div><h2>Recent Status Updates</h2><p>Showing <?= number_format(count($updates)) ?> of <?= number_format($totalUpdates) ?> public update records.</p></div><form method="post" data-confirm="Delete all but the latest 25 status updates?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="keep_latest_updates"><input type="hidden" name="keep_updates" value="25"><button class="button ghost small" type="submit">Keep Latest 25</button></form></div>
        <div class="v54-card-body">
            <?php if (!$updates): ?><div class="v54-empty">No status updates recorded.</div><?php else: ?>
            <form method="post" class="v54-log-shell" data-bulk-form data-bulk-noun="status update">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_selected_updates"><input type="hidden" name="selected_update_ids" data-bulk-hidden value="">
                <div class="v54-log-toolbar"><label><input type="checkbox" data-bulk-all> Select all shown</label><span data-bulk-count>0 selected</span><button class="button danger small" type="submit" data-bulk-delete disabled>Delete Selected</button></div>
                <div class="v54-log-list">
                    <?php foreach ($updates as $item): ?>
                        <label class="v54-log-row" data-bulk-row><span><input type="checkbox" name="update_ids[]" value="<?= (int)$item['id'] ?>" data-bulk-check></span><span class="v54-log-copy"><strong><?= e((string)$item['update_title']) ?></strong><?php if (trim((string)$item['update_message']) !== ''): ?><p><?= e((string)$item['update_message']) ?></p><?php endif; ?><small><?= e((string)($item['service_name'] ?? 'General')) ?> · <?= e(format_dt($item['created_at'])) ?></small></span><span class="v54-log-type"><?= e((string)$item['new_status']) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </article>

    <article class="pro-card" id="monitor-log">
        <div class="pro-card-head"><div><h2>Monitor Log</h2><p>Showing <?= number_format(count($monitorLogs)) ?> of <?= number_format($totalMonitorLogs) ?> raw health checks.</p></div><form method="post" data-confirm="Clear ALL raw monitor logs? Daily rollups remain, but the raw checks cannot be restored."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear_monitor_logs"><button class="button ghost small" type="submit">Clear All Logs</button></form></div>
        <div class="v54-card-body">
            <?php if (!$monitorLogs): ?><div class="v54-empty">No monitor checks recorded.</div><?php else: ?>
            <form method="post" class="v54-log-shell" data-bulk-form data-bulk-noun="monitor log entry">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_selected_monitor_logs"><input type="hidden" name="selected_monitor_log_ids" data-bulk-hidden value="">
                <div class="v54-log-toolbar"><label><input type="checkbox" data-bulk-all> Select all shown</label><span data-bulk-count>0 selected</span><button class="button danger small" type="submit" data-bulk-delete disabled>Delete Selected</button></div>
                <div class="v54-log-list">
                    <?php foreach ($monitorLogs as $item): ?>
                        <label class="v54-log-row" data-bulk-row><span><input type="checkbox" name="monitor_log_ids[]" value="<?= (int)$item['id'] ?>" data-bulk-check></span><span class="v54-log-copy"><strong><?= e((string)($item['website_name'] ?? $item['service_name'] ?? 'Unknown Target')) ?></strong><p><?= e(strtoupper((string)($item['monitor_type'] ?? 'http'))) ?> · <?= e((string)($item['source'] ?? 'local')) ?> · HTTP <?= e((string)($item['http_code'] ?? '—')) ?><?= $item['response_ms'] !== null ? ' · ' . (int)$item['response_ms'] . 'ms' : '' ?><?= !empty($item['error_message']) ? ' · ' . e((string)$item['error_message']) : '' ?></p><small><?= e(format_dt($item['created_at'])) ?></small></span><span class="v54-log-type"><?= e(strtoupper((string)($item['result'] ?? 'unknown'))) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </article>
</section>

<script>
(() => {
    document.querySelectorAll('[data-bulk-form]').forEach((form) => {
        const all = form.querySelector('[data-bulk-all]');
        const checks = Array.from(form.querySelectorAll('[data-bulk-check]'));
        const count = form.querySelector('[data-bulk-count]');
        const button = form.querySelector('[data-bulk-delete]');
        const hidden = form.querySelector('[data-bulk-hidden]');
        const noun = form.dataset.bulkNoun || 'record';
        if (!all || !checks.length || !count || !button || !hidden) return;

        const refresh = () => {
            const selected = checks.filter((check) => check.checked);
            count.textContent = `${selected.length} selected`;
            button.disabled = selected.length === 0;
            all.checked = selected.length === checks.length;
            all.indeterminate = selected.length > 0 && selected.length < checks.length;
            hidden.value = selected.map((check) => check.value).join(',');
            checks.forEach((check) => check.closest('[data-bulk-row]')?.classList.toggle('selected', check.checked));
        };
        all.addEventListener('change', () => { checks.forEach((check) => { check.checked = all.checked; }); refresh(); });
        checks.forEach((check) => check.addEventListener('change', refresh));
        form.addEventListener('submit', (event) => {
            const selected = checks.filter((check) => check.checked).length;
            if (!selected || !confirm(`Delete ${selected} selected ${noun}${selected === 1 ? '' : 's'}? This cannot be undone.`)) event.preventDefault();
        });
        refresh();
    });
})();
</script>

<?php admin_page_end(); ?>
