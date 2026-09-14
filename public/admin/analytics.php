<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/monitor.php';
require_once __DIR__ . '/../../app/maintenance.php';

require_once __DIR__ . '/_layout.php';

$user = require_login();
$websites = get_websites();
$sources = get_monitor_source_health();
$dbHealth = status_database_health();

function sparkline_points(array $series, int $width = 560, int $height = 120): string
{
    if (!$series) return '';
    $values = array_values(array_filter(array_map(static fn($r) => isset($r['avg_ms']) ? (int)$r['avg_ms'] : null, $series), static fn($v) => $v !== null));
    if (!$values) return '';
    $max = max(max($values), 1);
    $count = count($series);
    $points = [];
    foreach ($series as $i => $row) {
        $value = max(0, (int)($row['avg_ms'] ?? 0));
        $x = $count <= 1 ? 0 : ($i / ($count - 1)) * $width;
        $y = $height - (($value / $max) * ($height - 12)) - 6;
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    return implode(' ', $points);
}
?>
<?php
admin_page_start(
    'analytics',
    'Uptime & Performance',
    'Review uptime history, response times, monitor sources, and database health.',
    'Monitoring',
    [['href' => '/admin/monitoring.php', 'label' => 'Monitor Center', 'class' => 'ghost'],
        ['href' => '/admin/settings.php', 'label' => 'Retention Settings', 'class' => 'ghost']]
);
?>
<section class="pro-status-strip pro-status-strip-four"><article><span class="pro-icon">◴</span><small>Raw Checks</small><strong><?= number_format($dbHealth['monitor_logs']) ?></strong><em>retained detail</em></article><article><span class="pro-icon">▥</span><small>Daily Rollups</small><strong><?= number_format($dbHealth['daily_rollups']) ?></strong><em>long-term history</em></article><article><span class="pro-icon">◎</span><small>Monitor Sources</small><strong><?= count($sources) ?></strong><em><?= count(array_filter($sources, static fn($s)=>empty($s['is_stale']))) ?> active</em></article><article><span class="pro-icon">◫</span><small>Database</small><strong><?= e(number_format($dbHealth['database_bytes']/1048576, 1)) ?> MB</strong><em><?= number_format($dbHealth['backup_count']) ?> backup(s)</em></article></section>

<section class="analytics-grid">
<?php foreach ($websites as $website):
    $uptime30 = website_uptime_percent((int)$website['id'], 30);
    $uptime90 = website_uptime_percent((int)$website['id'], 90);
    $series = get_response_series((int)$website['id'], 24);
    $avg24 = $series ? (int)round(array_sum(array_map(static fn($r)=>(int)$r['avg_ms'],$series))/count($series)) : null;
    $history = get_daily_uptime_history((int)$website['id'], 30);
    $points = sparkline_points($series);
?>
<article class="pro-card analytics-card">
    <div class="pro-card-head"><div><h2><?= e($website['website_name']) ?></h2><p><?= e(strtoupper((string)($website['monitor_type'] ?? 'http'))) ?> · <?= e($website['website_url']) ?></p></div><span class="pro-chip"><?= e(status_meta($website['current_status'])['label']) ?></span></div>
    <div class="analytics-metrics"><div><small>30-day uptime</small><strong><?= $uptime30 !== null ? e(number_format($uptime30, 3)) . '%' : 'No data' ?></strong></div><div><small>90-day uptime</small><strong><?= $uptime90 !== null ? e(number_format($uptime90, 3)) . '%' : 'No data' ?></strong></div><div><small>24h avg response</small><strong><?= $avg24 !== null ? $avg24 . 'ms' : 'No data' ?></strong></div><div><small>Last check</small><strong><?= $website['last_checked_at'] ? e(format_dt($website['last_checked_at'])) : 'Pending' ?></strong></div></div>
    <div class="analytics-chart"><div class="analytics-chart-head"><strong>Response time · last 24 hours</strong><span><?= count($series) ?> hourly bucket<?= count($series)===1?'':'s' ?></span></div><?php if ($points): ?><svg viewBox="0 0 560 120" preserveAspectRatio="none" role="img" aria-label="24 hour response time graph"><polyline points="<?= e($points) ?>" fill="none" vector-effect="non-scaling-stroke"/></svg><?php else: ?><p class="empty">Not enough response-time data yet.</p><?php endif; ?></div>
    <div class="uptime-bars" title="30-day uptime history"><?php foreach ($history as $day): ?><span class="<?= e($day['class']) ?>" title="<?= e($day['date']) ?> · <?= $day['uptime'] !== null ? e(number_format((float)$day['uptime'],3)).'% uptime' : 'No data' ?><?= $day['avg_ms'] !== null ? ' · '.$day['avg_ms'].'ms avg' : '' ?>"></span><?php endforeach; ?></div>
</article>
<?php endforeach; ?>
</section>

<section class="pro-grid">
<article class="pro-card"><div class="pro-card-head"><div><h2>Monitor Sources</h2><p>Redundant checker heartbeat and latest result.</p></div></div><div class="pro-mini-stack"><?php if(!$sources):?><div><small>Sources</small><strong>No checks recorded</strong></div><?php endif;?><?php foreach($sources as $source):?><div><small><?=e($source['display_name'])?></small><strong><?=!empty($source['is_stale'])?'Stale':'Active'?> · <?=e(strtoupper((string)$source['last_result']))?></strong><em><?=e(format_dt($source['last_seen_at']))?></em></div><?php endforeach;?></div></article>
<article class="pro-card"><div class="pro-card-head"><div><h2>Database Maintenance</h2><p>Automatic housekeeping is tied to the minute monitor cron.</p></div></div><div class="pro-mini-stack"><div><small>Last housekeeping</small><strong><?= $dbHealth['last_housekeeping_at'] ? e(format_dt($dbHealth['last_housekeeping_at'])) : 'Not yet' ?></strong></div><div><small>Last VACUUM</small><strong><?= $dbHealth['last_vacuum_at'] ? e(format_dt($dbHealth['last_vacuum_at'])) : 'Not yet' ?></strong></div><div><small>Latest backup</small><strong><?= e($dbHealth['latest_backup'] ?? 'None yet') ?></strong></div></div><div class="pro-actions"><a class="button ghost" href="/admin/settings.php">Housekeeping Settings</a></div></article>
</section>
<?php admin_page_end(); ?>
