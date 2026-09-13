<?php
declare(strict_types=1);

require_once __DIR__ . '/monitor.php';

function status_backup_directory(): string
{
    return dirname(DB_PATH) . '/backups';
}

function create_status_database_backup(): ?string
{
    if (get_setting('backup_enabled', '1') !== '1') {
        return null;
    }
    $dir = status_backup_directory();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create backup directory.');
    }
    $path = $dir . '/status-' . gmdate('Ymd-His') . '.sqlite';

    // VACUUM INTO produces a clean, transactionally consistent SQLite backup.
    $escaped = str_replace("'", "''", $path);
    try {
        db()->exec("VACUUM INTO '" . $escaped . "'");
    } catch (Throwable $ex) {
        // Fallback for older SQLite builds.
        try {
            db()->exec('PRAGMA wal_checkpoint(FULL)');
        } catch (Throwable $ignored) {
        }
        if (!@copy(DB_PATH, $path)) {
            throw new RuntimeException('Database backup failed: ' . $ex->getMessage());
        }
    }
    return $path;
}

function prune_old_status_backups(): int
{
    $dir = status_backup_directory();
    if (!is_dir($dir)) {
        return 0;
    }
    $days = max(1, min(365, (int)get_setting('backup_retention_days', '7')));
    $cutoff = time() - ($days * 86400);
    $deleted = 0;
    foreach (glob($dir . '/status-*.sqlite') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
            $deleted++;
        }
    }
    return $deleted;
}

function purge_old_monitor_logs(): int
{
    ensure_monitor_schema();
    $days = max(1, min(365, (int)get_setting('monitor_log_retention_days', '30')));
    $stmt = db()->prepare('DELETE FROM monitor_check_log WHERE created_at < datetime("now", ?)');
    $stmt->execute(['-' . $days . ' days']);
    return $stmt->rowCount();
}

function maybe_vacuum_status_database(bool $force = false): bool
{
    $last = (string)get_setting('last_vacuum_at', '');
    $lastTs = $last !== '' ? strtotime($last . ' UTC') : 0;
    if (!$force && $lastTs && $lastTs > time() - (7 * 86400)) {
        return false;
    }
    db()->exec('VACUUM');
    set_setting('last_vacuum_at', gmdate('Y-m-d H:i:s'));
    return true;
}

function run_status_housekeeping(bool $force = false): array
{
    ensure_monitor_schema();
    $today = gmdate('Y-m-d');
    $last = (string)get_setting('last_housekeeping_at', '');
    if (!$force && str_starts_with($last, $today)) {
        return ['ran' => false, 'message' => 'Housekeeping already ran today.'];
    }

    $backfilled = backfill_monitor_rollups_once();
    $backupPath = null;
    $backupError = null;
    try {
        $backupPath = create_status_database_backup();
    } catch (Throwable $ex) {
        $backupError = $ex->getMessage();
    }
    $deletedLogs = purge_old_monitor_logs();
    $deletedBackups = prune_old_status_backups();
    $vacuumed = false;
    try {
        $vacuumed = maybe_vacuum_status_database($force);
    } catch (Throwable $ignored) {
    }

    set_setting('last_housekeeping_at', gmdate('Y-m-d H:i:s'));
    audit_admin_action(null, 'system_housekeeping', 'database', null, 'Backfilled ' . $backfilled . ' rollups; purged ' . $deletedLogs . ' monitor logs; pruned ' . $deletedBackups . ' backups; vacuum=' . ($vacuumed ? 'yes' : 'no') . ($backupError ? '; backup error=' . $backupError : ''));

    return [
        'ran' => true,
        'rollups_backfilled' => $backfilled,
        'monitor_logs_deleted' => $deletedLogs,
        'backup_created' => $backupPath ? basename($backupPath) : null,
        'backup_error' => $backupError,
        'old_backups_deleted' => $deletedBackups,
        'vacuumed' => $vacuumed,
    ];
}

function status_database_health(): array
{
    ensure_monitor_schema();
    ensure_incident_schema();
    $backupDir = status_backup_directory();
    $backups = is_dir($backupDir) ? (glob($backupDir . '/status-*.sqlite') ?: []) : [];
    usort($backups, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return [
        'database_bytes' => is_file(DB_PATH) ? (int)filesize(DB_PATH) : 0,
        'monitor_logs' => (int)db()->query('SELECT COUNT(*) FROM monitor_check_log')->fetchColumn(),
        'daily_rollups' => (int)db()->query('SELECT COUNT(*) FROM daily_monitor_stats')->fetchColumn(),
        'status_updates' => (int)db()->query('SELECT COUNT(*) FROM status_updates')->fetchColumn(),
        'incidents' => (int)db()->query('SELECT COUNT(*) FROM incidents')->fetchColumn(),
        'audit_events' => (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
        'backup_count' => count($backups),
        'latest_backup' => $backups ? basename($backups[0]) : null,
        'latest_backup_at' => $backups ? gmdate('Y-m-d H:i:s', filemtime($backups[0])) : null,
        'last_housekeeping_at' => get_setting('last_housekeeping_at', ''),
        'last_vacuum_at' => get_setting('last_vacuum_at', ''),
    ];
}
