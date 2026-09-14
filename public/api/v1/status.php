<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/platform.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=15');
if (get_setting('public_api_enabled','1') !== '1') { http_response_code(404); echo json_encode(['error'=>'Public API is disabled.']); exit; }
$payload = platform_enrich_public_payload(public_monitoring_enrichment(public_status_payload()));
unset($payload['recent_updates']);
echo json_encode(['api_version'=>'1.0','data'=>$payload], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
