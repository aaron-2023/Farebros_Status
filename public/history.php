<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/platform.php';

$payload = platform_enrich_public_payload(public_monitoring_enrichment(public_status_payload()));
$websites = get_websites();
$company = (string)get_setting('company_name','Fare Brothers, LLC');
$months = [];
for ($i=0;$i<12;$i++) $months[] = gmdate('Y-m', strtotime('first day of -'.$i.' month'));
$month = (string)($_GET['month'] ?? $months[0]);
if (!preg_match('/^\d{4}-\d{2}$/',$month) || !in_array($month,$months,true)) $month=$months[0];
$start=$month.'-01 00:00:00';
$end=(new DateTimeImmutable($month.'-01 00:00:00',new DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d H:i:s');
$monthIncidents=get_incidents_between($start,$end);
$report=get_monthly_uptime_report($month);
if(!$report) { try{$report=generate_monthly_uptime_report($month);}catch(Throwable $ignored){} }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Status History - <?= e($company) ?></title><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><link rel="stylesheet" href="/assets/css/statuspage-v52.css?v=5.2.0"><link rel="stylesheet" href="/assets/css/statuspage-v53.css?v=5.3.0"><link rel="stylesheet" href="/assets/css/statuspage-v55.css?v=5.5.0"></head>
<body class="status-page v55-public"><header class="sp-top"><div class="sp-wrap sp-top-inner"><a href="/" class="sp-brand"><img src="/assets/img/fare-brothers-logo.png" alt="Fare Brothers logo"><span><strong>Fare Brothers</strong><small>Incident History</small></span></a><nav class="v55-public-nav"><a href="/">Current Status</a><a class="active" href="/history.php">History</a><a href="/subscribe.php">Subscribe</a></nav></div></header>
<main class="sp-wrap v55-history-shell">
<section class="v55-history-hero"><div><span class="v55-eyebrow">TRANSPARENCY & UPTIME</span><h1>Incident & uptime history</h1><p>Review historical incidents, monthly uptime, response performance, and resolution timelines.</p></div><form method="get"><label>Month<select name="month" onchange="this.form.submit()"><?php foreach($months as $m): ?><option value="<?= e($m) ?>" <?= $m===$month?'selected':'' ?>><?= e((new DateTimeImmutable($m.'-01'))->format('F Y')) ?></option><?php endforeach; ?></select></label></form></section>

<section class="v55-history-metrics">
<?php if($report): $avg=array_values(array_filter(array_map(fn($r)=>$r['uptime_percent']!==null?(float)$r['uptime_percent']:null,$report),fn($v)=>$v!==null)); $monthUptime=$avg?array_sum($avg)/count($avg):null; $outages=array_sum(array_map(fn($r)=>(int)$r['outage_events'],$report)); $checks=array_sum(array_map(fn($r)=>(int)$r['total_checks'],$report)); ?>
<article><small>Monthly Uptime</small><strong><?= $monthUptime!==null?e(number_format($monthUptime,3)).'%':'No data' ?></strong><span>average across websites</span></article><article><small>Incidents</small><strong><?= count($monthIncidents) ?></strong><span>started this month</span></article><article><small>Outage Events</small><strong><?= number_format($outages) ?></strong><span>monitor rollups</span></article><article><small>Health Checks</small><strong><?= number_format($checks) ?></strong><span>included in report</span></article>
<?php else: ?><article><small>Monthly Uptime</small><strong>Pending</strong><span>report will populate after monitoring data is available</span></article><?php endif; ?>
</section>

<section class="sp-section"><div class="sp-section-head"><div><h2>Website Uptime</h2><p>Monthly performance summary for each tracked website.</p></div><span class="sp-section-tag"><?= e((new DateTimeImmutable($month.'-01'))->format('F Y')) ?></span></div>
<div class="v55-public-table-wrap"><table class="v55-public-table"><thead><tr><th>Website</th><th>Uptime</th><th>Checks</th><th>Avg Response</th><th>Outages</th></tr></thead><tbody><?php if(!$report): ?><tr><td colspan="5">No monthly rollup is available yet.</td></tr><?php endif; ?><?php foreach($report as $row): ?><tr><td><strong><?= e($row['website_name']) ?></strong></td><td><strong><?= $row['uptime_percent']!==null?e(number_format((float)$row['uptime_percent'],3)).'%':'—' ?></strong></td><td><?= number_format((int)$row['total_checks']) ?></td><td><?= $row['avg_response_ms']!==null?(int)$row['avg_response_ms'].' ms':'—' ?></td><td><?= number_format((int)$row['outage_events']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="sp-section"><div class="sp-section-head"><div><h2>Incident Timeline</h2><p>Incidents that began during the selected month.</p></div><span class="sp-section-tag"><?= count($monthIncidents) ?> incident<?= count($monthIncidents)===1?'':'s' ?></span></div><div class="sp-incident-history v55-history-list">
<?php if(!$monthIncidents): ?><div class="v55-public-empty"><strong>No incidents for this month.</strong><p>No incident records started during the selected month.</p></div><?php endif; ?>
<?php foreach($monthIncidents as $incident): ?><article><span class="sp-incident-badge <?= e($incident['impact']) ?>"><?= e(incident_status_label($incident['status'])) ?></span><div><strong><?= e($incident['title']) ?></strong><p><?= e($incident['summary'] ?: 'No summary provided.') ?></p><small>Started <?= e(format_dt($incident['started_at'])) ?><?= $incident['resolved_at']?' · Resolved '.e(format_dt($incident['resolved_at'])):'' ?></small><?php if(!empty($incident['updates'])): ?><details class="v55-public-details"><summary><?= count($incident['updates']) ?> timeline update<?= count($incident['updates'])===1?'':'s' ?></summary><?php foreach(array_reverse($incident['updates']) as $update): ?><div><strong><?= e(incident_status_label($update['status'])) ?></strong><p><?= nl2br(e($update['message'])) ?></p><small><?= e(format_dt($update['created_at'])) ?></small></div><?php endforeach; ?></details><?php endif; ?></div></article><?php endforeach; ?>
</div></section>
</main><footer class="sp-footer sp-wrap"><span>© <?= date('Y') ?> <?= e($company) ?></span><span><a href="/">Current Status</a> · <a href="/subscribe.php">Subscribe</a></span></footer></body></html>
