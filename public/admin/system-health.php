<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/_layout.php';

$user=require_login();
$notice=null;$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='run_housekeeping'){
            $r=run_status_housekeeping(true);$notice='Housekeeping completed. '.number_format((int)($r['monitor_logs_deleted']??0)).' old raw checks removed.';
            audit_admin_action($user,'run_housekeeping','system_health');
        }elseif($action==='generate_report'){
            $month=gmdate('Y-m',strtotime('first day of last month'));$rows=generate_monthly_uptime_report($month);$notice='Regenerated '.$month.' report with '.count($rows).' rows.';
            audit_admin_action($user,'generate_monthly_report','system_health',null,$month);
        }
    }catch(Throwable $ex){$error=$ex->getMessage();}
}
$health=status_system_health();
$database=$health['database'];
$severity=$health['overall'];
$headline=$severity==='good'?'Platform health looks good':($severity==='warning'?'Platform needs attention':'Platform health has a problem');
admin_page_start('health','System Health','Monitor the status platform itself: cron, database, backups, integrations, disk space, and redundancy.','Monitoring & Reporting',[
    ['href'=>'/admin/settings.php','label'=>'Settings','class'=>'ghost'],['href'=>'/admin/monitoring.php','label'=>'Monitor Center','class'=>'primary']
]);
admin_notice($notice);admin_notice($error,'danger');
?>
<section class="v55-health-overview <?= e($severity==='warning'?'warn':$severity) ?>"><div class="v55-health-score"><?= $severity==='good'?'✓':($severity==='warning'?'!':'×') ?></div><div><h2><?= e($headline) ?></h2><p><?= (int)$health['bad_count'] ?> critical · <?= (int)$health['warning_count'] ?> warning checks. This page evaluates the status server, not only the websites it monitors.</p></div></section>
<section class="v55-health-checks"><?php foreach($health['checks'] as $check): ?><article class="v55-health-check <?= e($check['status']) ?>"><small><?= e($check['label']) ?></small><strong><?= e($check['value']) ?></strong><span><?= e($check['detail']) ?></span></article><?php endforeach; ?></section>

<section class="v55-section-grid" style="margin-top:14px">
<article class="pro-card"><div class="pro-card-head"><div><h2>Database & Retention</h2><p>Storage and historical-data housekeeping.</p></div></div><div class="v55-card-body"><div class="v55-table-wrap"><table class="v55-table"><tbody><tr><th>Database size</th><td><?= e(number_format((int)$database['database_bytes']/1048576,1)) ?> MB</td></tr><tr><th>Raw monitor logs</th><td><?= number_format((int)$database['monitor_logs']) ?></td></tr><tr><th>Daily rollups</th><td><?= number_format((int)$database['daily_rollups']) ?></td></tr><tr><th>Status updates</th><td><?= number_format((int)$database['status_updates']) ?></td></tr><tr><th>Incidents</th><td><?= number_format((int)$database['incidents']) ?></td></tr><tr><th>Audit events</th><td><?= number_format((int)$database['audit_events']) ?></td></tr><tr><th>Backups</th><td><?= number_format((int)$database['backup_count']) ?><?= $database['latest_backup']?' · '.e($database['latest_backup']):'' ?></td></tr></tbody></table></div><div class="pro-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="run_housekeeping"><button class="button ghost" type="submit">Run Housekeeping Now</button></form></div></div></article>
<article class="pro-card"><div class="pro-card-head"><div><h2>Monitor Sources</h2><p>Heartbeat health for local and external monitoring locations.</p></div></div><div class="v55-card-body v54-compact-list"><?php if(!$health['monitor_sources']): ?><div class="v55-empty-state"><strong>No source heartbeats yet</strong><p>The next monitor run will create the local source heartbeat.</p></div><?php endif; ?><?php foreach($health['monitor_sources'] as $source): ?><div class="v54-compact-row"><div class="v54-row-main"><div><strong><?= e($source['display_name']) ?></strong><small>Last seen <?= e(format_dt($source['last_seen_at'])) ?> · <?= e($source['last_result']??'unknown') ?></small></div><span class="v55-pill <?= !empty($source['is_stale'])?'bad':'' ?>"><?= !empty($source['is_stale'])?'Stale':'Healthy' ?></span></div></div><?php endforeach; ?></div></article>
</section>

<section class="v55-section-grid">
<article class="pro-card"><div class="pro-card-head"><div><h2>Automation Heartbeats</h2><p>Last known system-maintenance activity.</p></div></div><div class="v55-card-body"><div class="v55-table-wrap"><table class="v55-table"><tbody><tr><th>Monitor cron</th><td><?= e((string)(get_setting('last_monitor_cron_at','')?:'Never')) ?><small><?= e((string)get_setting('last_monitor_cron_result','')) ?></small></td></tr><tr><th>Housekeeping</th><td><?= e((string)(get_setting('last_housekeeping_at','')?:'Never')) ?></td></tr><tr><th>VACUUM</th><td><?= e((string)(get_setting('last_vacuum_at','')?:'Never')) ?></td></tr><tr><th>Monthly report</th><td><?= e((string)(get_setting('last_monthly_report_at','')?:'Never')) ?></td></tr></tbody></table></div><div class="pro-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="generate_report"><button class="button ghost" type="submit">Regenerate Last Month Report</button></form></div></div></article>
<article class="pro-card"><div class="pro-card-head"><div><h2>Integration State</h2><p>Configured communication and API surfaces.</p></div></div><div class="v55-card-body v54-health-grid"><div class="v54-health-item"><small>SMTP</small><strong><?= get_setting('smtp_enabled','0')==='1'?'Enabled':'Disabled' ?></strong><span><?= get_setting('last_smtp_test_ok','')==='1'?'Last test succeeded':'Test from Settings' ?></span></div><div class="v54-health-item"><small>Discord</small><strong><?= get_setting('discord_enabled','0')==='1'?'Enabled':'Disabled' ?></strong><span><?= get_setting('last_discord_test_ok','')==='1'?'Last test succeeded':'Test from Settings' ?></span></div><div class="v54-health-item"><small>Outbound Webhook</small><strong><?= get_setting('outbound_webhook_enabled','0')==='1'?'Enabled':'Disabled' ?></strong><span>Signed JSON event delivery</span></div></div></article>
</section>
<?php admin_page_end(); ?>
