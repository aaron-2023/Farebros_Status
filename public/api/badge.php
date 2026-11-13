<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/platform.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$payload = platform_enrich_public_payload(public_monitoring_enrichment(public_status_payload()));

echo json_encode([
    'label' => $payload['overall']['label'],
    'code' => $payload['overall']['code'],
    'class' => $payload['overall']['class'],
    'message' => $payload['message'],
    'generated_at' => $payload['generated_at'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
