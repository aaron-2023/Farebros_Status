<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/platform.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
if (get_setting('public_api_enabled','1') !== '1' || get_setting('public_api_allow_history','1') !== '1') { http_response_code(404); echo json_encode(['error'=>'This API endpoint is disabled.']); exit; }
$websiteId=(int)($_GET['website_id']??0);$days=max(1,min(365,(int)($_GET['days']??90)));
$website=status_target('website',$websiteId);
if(!$website){http_response_code(404);echo json_encode(['error'=>'Website not found.']);exit;}
echo json_encode(['api_version'=>'1.0','generated_at'=>site_now()->format(DateTimeInterface::ATOM),'website'=>['id'=>$websiteId,'name'=>$website['name']],'days'=>$days,'uptime_percent'=>website_uptime_percent($websiteId,$days),'history'=>get_daily_uptime_history($websiteId,$days)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
