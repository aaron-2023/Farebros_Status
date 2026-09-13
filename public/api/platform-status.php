<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/integration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_status_read_key();

echo json_encode([
    'ok' => true,
    'data' => public_status_payload(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
