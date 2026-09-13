<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$key = $_GET['key'] ?? ($_SERVER['HTTP_X_FAREBROS_MONITOR_KEY'] ?? '');

if (!MONITOR_RUN_KEY || MONITOR_RUN_KEY === 'change-this-monitor-secret') {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'MONITOR_RUN_KEY is not configured yet in app/config.php.'
    ], JSON_PRETTY_PRINT);
    exit;
}

if (!hash_equals(MONITOR_RUN_KEY, (string)$key)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized.'
    ], JSON_PRETTY_PRINT);
    exit;
}

$result = run_all_monitors();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
