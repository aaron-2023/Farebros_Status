<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (get_setting('external_monitor_enabled', '0') !== '1') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'External monitor ingestion is disabled.']);
    exit;
}

$configuredKey = (string)get_setting('external_monitor_key', '');
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$providedKey = (string)($_SERVER['HTTP_X_FAREBROS_EXTERNAL_KEY'] ?? ($data['key'] ?? ''));
if ($configuredKey === '' || !hash_equals($configuredKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

$websiteId = (int)($data['website_id'] ?? 0);
$source = preg_replace('/[^a-zA-Z0-9_.-]/', '-', trim((string)($data['source'] ?? 'external')));
$source = $source !== '' ? substr($source, 0, 80) : 'external';

try {
    if ($websiteId <= 0) {
        throw new RuntimeException('website_id is required.');
    }
    $result = ingest_external_monitor_result($websiteId, $data, $source);
    echo json_encode(['ok' => true, 'applied' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $ex) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_PRETTY_PRINT);
}
