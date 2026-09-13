<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/maintenance.php';
$result = run_status_housekeeping(true);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
