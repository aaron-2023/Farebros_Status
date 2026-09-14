<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/platform.php';
require_login();
$month=(string)($_GET['month']??'');
if(!preg_match('/^\d{4}-\d{2}$/',$month)){http_response_code(400);exit('Invalid month');}
$rows=get_monthly_uptime_report($month);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="farebros-status-'.$month.'.csv"');
$out=fopen('php://output','wb');
fputcsv($out,['Month','Website','Monitor ID','Uptime %','Total Checks','Successful','Degraded','Failed','Average Response ms','Minimum Response ms','Maximum Response ms','Outage Events','Incident Count','Generated At']);
foreach($rows as $r)fputcsv($out,[$r['report_month'],$r['website_name'],$r['website_id'],$r['uptime_percent'],$r['total_checks'],$r['successful_checks'],$r['degraded_checks'],$r['failed_checks'],$r['avg_response_ms'],$r['min_response_ms'],$r['max_response_ms'],$r['outage_events'],$r['incident_count'],$r['generated_at']]);
fclose($out);
