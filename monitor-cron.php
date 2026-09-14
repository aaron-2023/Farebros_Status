<?php
declare(strict_types=1);

require_once __DIR__ . '/app/platform.php';

set_setting('last_monitor_cron_at', gmdate('Y-m-d H:i:s'));
set_setting('last_monitor_cron_result', 'running');

try {
    // Apply schedules first so a maintenance window remains authoritative.
    $scheduleActions = apply_scheduled_jobs();
    $monitorResult = run_all_monitors();
    $housekeeping = run_status_housekeeping(false);
    $dailyTasks = (array)($housekeeping['platform_tasks'] ?? []);

    set_setting('last_monitor_cron_at', gmdate('Y-m-d H:i:s'));
    set_setting('last_monitor_cron_result', 'ok');

    echo json_encode([
        'monitor' => $monitorResult,
        'scheduled_jobs' => $scheduleActions,
        'housekeeping' => $housekeeping,
        'platform_tasks' => $dailyTasks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $ex) {
    set_setting('last_monitor_cron_at', gmdate('Y-m-d H:i:s'));
    set_setting('last_monitor_cron_result', 'error: ' . substr($ex->getMessage(), 0, 500));
    throw $ex;
}
