<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/platform.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
if (get_setting('public_api_enabled','1') !== '1' || get_setting('public_api_allow_history','1') !== '1') { http_response_code(404); echo json_encode(['error'=>'This API endpoint is disabled.']); exit; }
$limit=max(1,min(100,(int)($_GET['limit']??25)));
$incidents=array_map('public_incident_view', get_recent_incidents($limit));
echo json_encode(['api_version'=>'1.0','generated_at'=>site_now()->format(DateTimeInterface::ATOM),'incidents'=>$incidents], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
