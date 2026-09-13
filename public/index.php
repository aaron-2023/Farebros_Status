<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/monitor.php';
if (file_exists(__DIR__ . '/../app/scheduler.php')) {
    require_once __DIR__ . '/../app/scheduler.php';
}

$payload = public_monitoring_enrichment(public_status_payload());
$overall = $payload['overall'];
$primary = $payload['primary'];
$settings = $payload['settings'] ?? [];
$companyName = $settings['company_name'] ?? 'Fare Brothers, LLC';
$statusPageLabel = $settings['status_page_label'] ?? 'Status Center';
$showAdminLink = !empty($settings['public_admin_link']);
$activeIncidents = $payload['incidents']['active'] ?? [];
$recentIncidents = $payload['incidents']['recent'] ?? [];

$activeSchedule = null;
if (function_exists('active_job_payload')) {
    $activeSchedule = active_job_payload();
    if ($activeSchedule) {
        $activeSchedule['phase'] = 'active';
    } else {
        $activeSchedule = upcoming_job_payload();
        if ($activeSchedule) $activeSchedule['phase'] = 'upcoming';
    }
}

$lastWebsiteCheck = null;
foreach ($payload['websites'] as $site) {
    $check = $site['last_checked_at_iso'] ?? $site['last_checked_at'] ?? null;
    if ($check && ($lastWebsiteCheck === null || strtotime($check) > strtotime($lastWebsiteCheck))) $lastWebsiteCheck = $check;
}

function page_status_message(array $overall, array $primary, ?array $schedule, array $incidents = []): string
{
    if ($incidents) return count($incidents) === 1 ? 'One active incident is currently being tracked.' : count($incidents) . ' active incidents are currently being tracked.';
    if ($schedule && ($schedule['phase'] ?? '') === 'active') return 'A scheduled maintenance window is currently active.';
    if ($schedule && ($schedule['phase'] ?? '') === 'upcoming') return 'A scheduled maintenance window is upcoming. Current live status is still shown below.';
    if (($overall['status'] ?? '') === 'operational') return 'All monitored systems are operational.';
    if (($primary['status'] ?? '') !== 'operational') return 'The primary Fare Brothers website is reporting an issue.';
    return 'One or more monitored systems are reporting an issue.';
}

function item_schedule_overlay(string $type, int $id): ?array
{
    return function_exists('current_schedule_for_item') ? current_schedule_for_item($type, $id) : null;
}

function overlay_label(?array $overlay): string
{
    if (!$overlay) return '';
    return ($overlay['phase'] ?? '') === 'active' ? 'Maintenance in progress' : 'Planned maintenance scheduled';
}

function public_sparkline_points(array $series, int $width = 480, int $height = 72): string
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
        $y = $height - (($value / $max) * ($height - 10)) - 5;
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    return implode(' ', $points);
}

function incident_targets_label(array $targets): string
{
    $names = array_values(array_filter(array_map(static fn($t) => $t['target_name'] ?? null, $targets)));
    return $names ? implode(', ', $names) : 'General status';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($companyName) ?> Status</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <link rel="stylesheet" href="/assets/css/statuspage-v52.css?v=5.2.0">
    <link rel="stylesheet" href="/assets/css/statuspage-v53.css?v=5.3.0">
</head>
<body>
<header class="sp-top"><div class="sp-wrap sp-top-inner"><a class="sp-brand" href="/"><img src="/assets/img/fare-brothers-logo.png" alt="Fare Brothers logo"><span><strong>Fare Brothers</strong><small><?= e($statusPageLabel) ?></small></span></a><nav class="sp-nav"><a href="https://farebros.com">Main Website</a><a href="#systems">Systems</a><a href="#websites">Websites</a><a href="#incidents">Incidents</a><a href="#updates">Updates</a><?php if ($showAdminLink): ?><a class="sp-admin" href="/admin/login.php">Admin</a><?php endif; ?></nav></div></header>

<main class="sp-wrap">
    <section class="sp-status-card <?= e($overall['class']) ?>" id="overallStatusCard" aria-live="polite">
        <div class="sp-status-main"><span class="sp-dot"></span><div><div class="sp-label">Current Status</div><h1 id="overallStatusLabel"><?= e($overall['label']) ?></h1><p id="overallStatusMessage"><?= e(page_status_message($overall, $primary, $activeSchedule, $activeIncidents)) ?></p><span class="sp-live"><i></i><span id="liveRefreshLabel">Live monitoring · refreshes every <?= (int)($payload['refresh_seconds'] ?? 15) ?>s</span></span></div></div>
        <div class="sp-meta"><div><small>Status Code</small><strong id="overallStatusCode"><?= e((string)$overall['code']) ?></strong></div><div><small>Last Updated</small><strong id="overallUpdatedAt"><?= e(format_dt($payload['generated_at'])) ?></strong></div><div><small>Timezone</small><strong id="overallTimezone"><?= e($payload['timezone_label'] ?? '') ?></strong></div></div>
    </section>

    <div id="statusNoticeRegion"><?php if (!empty($payload['message'])): ?><section class="sp-notice"><strong>Status Message</strong><p><?= e($payload['message']) ?></p></section><?php endif; ?></div>

    <div id="scheduleRegion"><?php if ($activeSchedule): ?><section class="sp-schedule <?= e($activeSchedule['phase']) ?>"><div><strong><?= ($activeSchedule['phase'] ?? '') === 'active' ? 'Maintenance In Progress' : 'Upcoming Maintenance' ?></strong><h2><?= e($activeSchedule['title'] ?? 'Scheduled Maintenance') ?></h2><p><?= e($activeSchedule['details'] ?? '') ?></p></div><div class="sp-schedule-time"><span><?= e(format_dt($activeSchedule['start_at'] ?? '')) ?></span><em>to</em><span><?= e(format_dt($activeSchedule['end_at'] ?? '')) ?></span></div></section><?php endif; ?></div>

    <section id="incidentRegion" class="sp-incident-region">
        <?php foreach ($activeIncidents as $incident): ?>
            <article class="sp-incident <?= e($incident['impact']) ?>">
                <div class="sp-incident-head"><div><span class="sp-incident-badge"><?= e(incident_impact_label($incident['impact'])) ?> incident</span><h2><?= e($incident['title']) ?></h2><p><?= e(incident_targets_label($incident['targets'])) ?></p></div><strong><?= e(incident_status_label($incident['status'])) ?></strong></div>
                <div class="sp-incident-timeline"><?php foreach (array_slice($incident['updates'], 0, 4) as $update): ?><div><span><?= e(incident_status_label($update['status'])) ?></span><p><?= nl2br(e($update['message'])) ?></p><small><?= e(format_dt($update['created_at'])) ?></small></div><?php endforeach; ?></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="sp-summary sp-summary-six">
        <article><small>Primary Website</small><strong id="primaryWebsiteName"><?= e($primary['name']) ?></strong><span class="sp-pill <?= e($primary['class']) ?>" id="primaryWebsiteStatus"><i></i><?= e($primary['label']) ?></span></article>
        <article><small>Systems</small><strong id="systemsCount"><?= count($payload['services']) ?></strong><span id="systemsSub"><?= count(array_filter($payload['services'], fn($s) => ($s['status'] ?? '') === 'operational')) ?> operational</span></article>
        <article><small>Websites</small><strong id="websitesCount"><?= count($payload['websites']) ?></strong><span id="websitesSub"><?= count(array_filter($payload['websites'], fn($w) => ($w['status'] ?? '') === 'operational')) ?> operational</span></article>
        <article><small>Last Website Check</small><strong id="lastWebsiteCheck"><?= $lastWebsiteCheck ? e(format_dt($lastWebsiteCheck)) : 'Pending' ?></strong><span>offsite monitor</span></article>
        <article><small>Active Incidents</small><strong id="incidentCount"><?= count($activeIncidents) ?></strong><span><?= $activeIncidents ? 'being tracked' : 'all clear' ?></span></article>
        <article><small>Monitor Locations</small><strong id="monitorSourceCount"><?= (int)($payload['monitoring']['active_source_count'] ?? 0) ?></strong><span id="monitorSourceSub"><?= !empty($payload['monitoring']['redundant']) ? 'redundant monitoring' : 'single source' ?></span></article>
    </section>

    <section class="sp-section" id="systems"><div class="sp-section-head"><div><h2>Systems</h2><p>Core services and platform components monitored by the status center.</p></div><span class="sp-section-tag">Live status</span></div><div class="sp-list" id="serviceGrid"><?php foreach ($payload['services'] as $service): $overlay = item_schedule_overlay('service', (int)$service['id']); ?><article class="sp-row <?= e($service['class']) ?>"><div class="sp-row-main"><span class="sp-dot"></span><div><strong><?= e($service['name']) ?></strong><p><?= e($service['description']) ?></p><?php if ($overlay): ?><span class="sp-maint-badge <?= e($overlay['phase']) ?>"><?= e(overlay_label($overlay)) ?></span><?php endif; ?></div></div><div class="sp-row-status"><strong><?= e($service['label']) ?></strong><small>Code <?= e((string)$service['code']) ?></small></div></article><?php endforeach; ?></div></section>

    <section class="sp-section" id="websites">
        <div class="sp-section-head"><div><h2>Websites & Performance</h2><p>Independent checks, response time, SSL health, and long-term uptime history.</p></div><div class="sp-history-controls"><button class="<?= (int)($payload['monitoring']['default_history_days'] ?? 90) === 30 ? 'active' : '' ?>" type="button" data-history-days="30">30d</button><button class="<?= (int)($payload['monitoring']['default_history_days'] ?? 90) === 60 ? 'active' : '' ?>" type="button" data-history-days="60">60d</button><button class="<?= (int)($payload['monitoring']['default_history_days'] ?? 90) === 90 ? 'active' : '' ?>" type="button" data-history-days="90">90d</button></div></div>
        <div class="sp-list" id="websiteTable">
            <?php foreach ($payload['websites'] as $website): $overlay = item_schedule_overlay('website', (int)$website['id']); $points = public_sparkline_points($website['response_series'] ?? []); ?>
                <article class="sp-row sp-row-monitor <?= e($website['class']) ?>">
                    <div class="sp-row-monitor-top"><div class="sp-row-main"><span class="sp-dot"></span><div><strong><?= e($website['name']) ?><?= $website['is_primary'] ? ' · Primary' : '' ?></strong><p><?= e($website['url']) ?></p><?php if ($overlay): ?><span class="sp-maint-badge <?= e($overlay['phase']) ?>"><?= e(overlay_label($overlay)) ?></span><?php endif; ?></div></div><div class="sp-row-status"><strong><?= e($website['label']) ?></strong><small><?= $website['last_checked_at'] ? 'Checked ' . e(format_dt($website['last_checked_at'])) : 'Waiting for first check' ?></small></div></div>
                    <div class="sp-monitor-detail">
                        <div class="sp-monitor-metrics"><span><small>30d uptime</small><strong><?= $website['uptime_30'] !== null ? e(number_format((float)$website['uptime_30'], 3)) . '%' : '—' ?></strong></span><span><small>90d uptime</small><strong><?= $website['uptime_90'] !== null ? e(number_format((float)$website['uptime_90'], 3)) . '%' : '—' ?></strong></span><span><small>Last response</small><strong><?= isset($website['last_response_ms']) && $website['last_response_ms'] !== null ? (int)$website['last_response_ms'] . 'ms' : '—' ?></strong></span></div>
                        <div class="sp-response-chart"><div><small>Response time · 24h</small><?php if ($points): ?><svg viewBox="0 0 480 72" preserveAspectRatio="none"><polyline points="<?= e($points) ?>" fill="none" vector-effect="non-scaling-stroke"/></svg><?php else: ?><span>No response data yet</span><?php endif; ?></div></div>
                        <div class="sp-uptime-bars" data-history-bars><?php foreach (($website['history'] ?? []) as $i => $day): ?><span class="<?= e($day['class']) ?>" data-history-index="<?= (int)$i ?>" title="<?= e($day['date']) ?> · <?= $day['uptime'] !== null ? e(number_format((float)$day['uptime'], 3)) . '% uptime' : 'No data' ?><?= $day['avg_ms'] !== null ? ' · ' . (int)$day['avg_ms'] . 'ms avg' : '' ?>"></span><?php endforeach; ?></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="sp-two" id="updates">
        <div class="sp-section"><div class="sp-section-head"><div><h2>Announcements</h2><p>Active public notices.</p></div></div><div id="announcementList"><?php if (!$payload['announcements']): ?><p class="sp-empty">No active announcements.</p><?php endif; ?><?php foreach ($payload['announcements'] as $item): ?><article class="sp-update"><strong><?= e($item['title']) ?></strong><p><?= nl2br(e($item['body'])) ?></p><small><?= e(format_dt($item['created_at'])) ?></small></article><?php endforeach; ?></div></div>
        <div class="sp-section"><div class="sp-section-head"><div><h2>Recent Updates</h2><p>Status history and changes.</p></div></div><div id="updateList"><?php if (!$payload['recent_updates']): ?><p class="sp-empty">No recent updates.</p><?php endif; ?><?php foreach ($payload['recent_updates'] as $item): ?><article class="sp-update"><strong><?= e($item['update_title']) ?></strong><p><?= nl2br(e($item['update_message'])) ?></p><small><?= e($item['service_name'] ?? 'General') ?> · <?= e(format_dt($item['created_at'])) ?></small></article><?php endforeach; ?></div></div>
    </section>

    <section class="sp-section" id="incidents"><div class="sp-section-head"><div><h2>Incident History</h2><p>Recent incidents and their resolution state.</p></div><span class="sp-section-tag">Incident timeline</span></div><div id="incidentHistory" class="sp-incident-history"><?php if (!$recentIncidents): ?><p class="sp-empty">No incidents recorded.</p><?php endif; ?><?php foreach ($recentIncidents as $incident): ?><article><span class="sp-incident-badge <?= e($incident['impact']) ?>"><?= e(incident_status_label($incident['status'])) ?></span><div><strong><?= e($incident['title']) ?></strong><p><?= e(incident_targets_label($incident['targets'])) ?></p><small>Started <?= e(format_dt($incident['started_at'])) ?><?= $incident['resolved_at'] ? ' · Resolved ' . e(format_dt($incident['resolved_at'])) : '' ?></small></div></article><?php endforeach; ?></div></section>
</main>

<footer class="sp-footer sp-wrap"><span>© <?= date('Y') ?> <?= e($companyName) ?></span><span>Independent offsite status page</span></footer>
<script>window.STATUS_REFRESH_SECONDS = <?= (int)($payload['refresh_seconds'] ?? 15) ?>; window.STATUS_HISTORY_DAYS = <?= (int)($payload['monitoring']['default_history_days'] ?? 90) ?>;</script>
<script src="/assets/js/statuspage-v53.js?v=5.3.0"></script>
</body>
</html>
