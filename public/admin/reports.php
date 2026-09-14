<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;
$month = (string)($_GET['month'] ?? $_POST['report_month'] ?? gmdate('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = gmdate('Y-m', strtotime('first day of last month'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate_report') {
            $rows = generate_monthly_uptime_report($month);
            $notice = 'Monthly report regenerated with ' . count($rows) . ' website record' . (count($rows) === 1 ? '' : 's') . '.';
            audit_admin_action($user, 'generate_monthly_report', 'report', null, $month);
        }
    } catch (Throwable $ex) { $error = $ex->getMessage(); }
}

$report = get_monthly_uptime_report($month);
$months = available_report_months();
for ($i=0;$i<18;$i++) {
    $m = gmdate('Y-m', strtotime('first day of -'.$i.' month'));
    if (!in_array($m,$months,true)) $months[]=$m;
}
rsort($months);
$sla = (float)get_setting('uptime_sla_target','99.900');
$uptimeVals = array_values(array_filter(array_map(static fn(array $r) => $r['uptime_percent'] !== null ? (float)$r['uptime_percent'] : null, $report), static fn($v) => $v !== null));
$avgUptime = $uptimeVals ? array_sum($uptimeVals)/count($uptimeVals) : null;
$totalChecks = array_sum(array_map(static fn(array $r): int => (int)$r['total_checks'],$report));
$totalFailures = array_sum(array_map(static fn(array $r): int => (int)$r['failed_checks'],$report));
$totalOutages = array_sum(array_map(static fn(array $r): int => (int)$r['outage_events'],$report));
$totalIncidents = array_sum(array_map(static fn(array $r): int => (int)$r['incident_count'],$report));

admin_page_start('reports','Monthly Reports','Generate professional monthly uptime summaries from permanent daily rollups.','Monitoring & Reporting',[
    ['href'=>'/admin/analytics.php','label'=>'Analytics','class'=>'ghost'],
    ['href'=>'/history.php?month='.rawurlencode($month),'label'=>'Public History','class'=>'primary','external'=>true],
]);
admin_notice($notice); admin_notice($error,'danger');
?>
<div class="v55-page-intro"><div><strong>Monthly reports are built from permanent daily rollups.</strong><p>Raw minute-by-minute logs can expire after 30 days without losing long-term uptime, latency, outage, and incident reporting.</p></div></div>
<section class="pro-card pro-card-full">
<div class="v55-card-body v55-report-head">
    <form method="get" class="pro-form" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><div><label>Report month</label><select name="month" onchange="this.form.submit()"><?php foreach($months as $m): ?><option value="<?= e($m) ?>" <?= $m===$month?'selected':'' ?>><?= e((new DateTimeImmutable($m.'-01'))->format('F Y')) ?></option><?php endforeach; ?></select></div></form>
    <div class="v55-inline-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="generate_report"><input type="hidden" name="report_month" value="<?= e($month) ?>"><button class="button ghost" type="submit"><?= $report?'Regenerate':'Generate' ?> Report</button></form><?php if($report): ?><a class="button primary" href="/admin/report-export.php?month=<?= e(rawurlencode($month)) ?>">Download CSV</a><?php endif; ?></div>
</div></section>

<section class="v55-summary-grid">
    <article class="v55-summary-card <?= $avgUptime!==null && $avgUptime >= $sla?'good':($avgUptime!==null?'bad':'') ?>"><small>Average Uptime</small><strong class="v55-report-score <?= $avgUptime!==null && $avgUptime >= $sla?'v55-sla-good':'v55-sla-bad' ?>"><?= $avgUptime!==null?e(number_format($avgUptime,3)).'%':'No data' ?></strong><span>SLA target <?= e(number_format($sla,3)) ?>%</span></article>
    <article class="v55-summary-card"><small>Health Checks</small><strong><?= number_format($totalChecks) ?></strong><span><?= number_format($totalFailures) ?> failed checks</span></article>
    <article class="v55-summary-card <?= $totalOutages?'warn':'good' ?>"><small>Outage Events</small><strong><?= number_format($totalOutages) ?></strong><span>confirmed transitions in rollups</span></article>
    <article class="v55-summary-card <?= $totalIncidents?'warn':'good' ?>"><small>Incident Links</small><strong><?= number_format($totalIncidents) ?></strong><span>website incident records</span></article>
</section>

<section class="pro-card pro-card-full">
<div class="pro-card-head"><div><h2><?= e((new DateTimeImmutable($month.'-01'))->format('F Y')) ?> Website Report</h2><p>Uptime is calculated as successful + degraded checks divided by total checks.</p></div><span class="v54-count"><?= count($report) ?> website<?= count($report)===1?'':'s' ?></span></div>
<div class="v55-card-body">
<?php if(!$report): ?><div class="v55-empty-state"><strong>No report generated yet</strong><p>Click Generate Report. Future months are generated automatically by housekeeping after a month closes.</p></div><?php else: ?>
<div class="v55-table-wrap"><table class="v55-table"><thead><tr><th>Website</th><th>Uptime</th><th>SLA</th><th>Checks</th><th>Failures</th><th>Avg / Max</th><th>Outages</th><th>Incidents</th></tr></thead><tbody><?php foreach($report as $row): $pass=$row['uptime_percent']!==null && (float)$row['uptime_percent'] >= $sla; ?><tr><td><strong><?= e($row['website_name']) ?></strong><small>Monitor ID #<?= (int)$row['website_id'] ?></small></td><td><strong class="<?= $pass?'v55-sla-good':'v55-sla-bad' ?>"><?= $row['uptime_percent']!==null?e(number_format((float)$row['uptime_percent'],3)).'%':'—' ?></strong></td><td><span class="v55-pill <?= $row['uptime_percent']===null?'muted':($pass?'':'bad') ?>"><?= $row['uptime_percent']===null?'No data':($pass?'Met':'Missed') ?></span></td><td><?= number_format((int)$row['total_checks']) ?></td><td><?= number_format((int)$row['failed_checks']) ?></td><td><?= $row['avg_response_ms']!==null?(int)$row['avg_response_ms'].' / '.(int)$row['max_response_ms'].' ms':'—' ?></td><td><?= number_format((int)$row['outage_events']) ?></td><td><?= number_format((int)$row['incident_count']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</div></section>
<?php admin_page_end(); ?>
