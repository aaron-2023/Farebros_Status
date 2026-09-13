<?php
declare(strict_types=1);

require_once __DIR__ . '/app/monitor.php';
require_once __DIR__ . '/app/scheduler.php';

// Run the normal website monitor first, then enforce scheduled jobs after it.
// This lets planned maintenance override normal online/offline checks.
$monitorResult = run_all_monitors();
$scheduleActions = apply_scheduled_jobs();

echo json_encode([
    'monitor' => $monitorResult,
    'scheduled_jobs' => $scheduleActions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
