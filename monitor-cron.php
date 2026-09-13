<?php
declare(strict_types=1);

require_once __DIR__ . '/app/monitor.php';
require_once __DIR__ . '/app/scheduler.php';
require_once __DIR__ . '/app/maintenance.php';

// Apply schedules first so a maintenance window is authoritative before health checks run.
$scheduleActions = apply_scheduled_jobs();
$monitorResult = run_all_monitors();
$housekeeping = run_status_housekeeping(false);

echo json_encode([
    'monitor' => $monitorResult,
    'scheduled_jobs' => $scheduleActions,
    'housekeeping' => $housekeeping,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
