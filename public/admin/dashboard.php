<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/integration.php';
require_once __DIR__ . '/../../app/platform.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$syncNotice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'update_status_message') {
            set_setting('status_message', trim((string)($_POST['status_message'] ?? '')));
            $notice = 'Public status message updated.';
            if (platform_sync_enabled()) {
                $syncNotice = sync_status_snapshot_to_platform('status_message_updated')
                    ? 'Portal sync sent.'
                    : 'Status saved, but the portal sync failed or was skipped.';
            }
        } elseif ($action === 'update_service') {
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $newStatus = (string)($_POST['new_status'] ?? 'operational');
            $title = trim((string)($_POST['update_title'] ?? ''));
            $message = trim((string)($_POST['update_message'] ?? ''));
            if (!isset(STATUS_OPTIONS[$newStatus])) $newStatus = 'offline';

            $stmt = db()->prepare('SELECT current_status, service_name FROM services WHERE id = ? LIMIT 1');
            $stmt->execute([$serviceId]);
            $service = $stmt->fetch();
            if (!$service) throw new RuntimeException('Service not found.');

            $oldStatus = (string)$service['current_status'];
            db()->prepare('UPDATE services SET current_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$newStatus, $serviceId]);
            if ($title === '') $title = (string)$service['service_name'] . ' changed to ' . status_meta($newStatus)['label'];
            db()->prepare('INSERT INTO status_updates (service_id, old_status, new_status, update_title, update_message, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$serviceId, $oldStatus, $newStatus, $title, $message, (int)$user['id']]);
            send_status_notifications($title, $message !== '' ? $message : ('Status changed from ' . $oldStatus . ' to ' . $newStatus . '.'), $newStatus === 'operational' ? 'resolved' : 'warning', 'manual_status_change', ['target_type' => 'service', 'target_id' => $serviceId]);
            $notice = 'Service status updated.';
            if (platform_sync_enabled()) {
                $syncNotice = sync_status_snapshot_to_platform('service_status_updated')
                    ? 'Portal sync sent.'
                    : 'Status saved, but the portal sync failed or was skipped.';
            }
        }
        if ($action !== '') audit_admin_action($user, $action, 'dashboard');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$payload = platform_enrich_public_payload(public_monitoring_enrichment(public_status_payload()));
$services = get_services();
$websites = get_websites();
$activeAnnouncements = get_active_announcements();
$updates = get_recent_updates(5);
$monitorLogs = get_recent_monitor_logs(1);
$activeIncidents = get_active_incidents();
$monitorSources = get_monitor_source_health();
$healthySources = array_values(array_filter($monitorSources, static fn(array $source): bool => empty($source['is_stale'])));
$statusMessage = (string)get_setting('status_message', '');
$overall = $payload['overall'];
$primary = $payload['primary'];
$lastMonitor = $monitorLogs[0] ?? null;
$systemHealth = status_system_health();
$dashboardLayout = get_dashboard_layout((int)$user['id']);
$dashboardCustomizable = get_setting('dashboard_customization_enabled', '1') === '1';

$operationalWebsites = count(array_filter($websites, static fn(array $site): bool => ($site['current_status'] ?? '') === 'operational'));
$problemWebsites = count($websites) - $operationalWebsites;
$uptimeValues = [];
foreach ($websites as $website) {
    $uptime = website_uptime_percent((int)$website['id'], 30);
    if ($uptime !== null) $uptimeValues[] = $uptime;
}
$averageUptime = $uptimeValues ? array_sum($uptimeValues) / count($uptimeValues) : null;

$activeJob = $upcomingJob = null;
try { $activeJob = active_job_payload(); $upcomingJob = upcoming_job_payload(); } catch (Throwable $ignored) {}

$severityClass = static fn(string $class): string => match ($class) {
    'bad' => 'bad', 'warning', 'maintenance' => 'warn', default => 'good',
};
$widgetLabels = [
    'status'=>'Public Status','services'=>'Service Status','quick_actions'=>'Quick Actions','system_health'=>'System Health',
    'maintenance'=>'Maintenance','incidents'=>'Active Incidents','announcements'=>'Announcements','monitoring'=>'Monitoring','primary'=>'Primary Website',
];
$hiddenWidgets = array_flip($dashboardLayout['hidden']);

admin_page_start('dashboard','Dashboard','Live operations overview with the controls you need most.','Operations Center',[
    ['href'=>'/admin/incidents.php','label'=>'New Incident','class'=>'ghost'],
    ['href'=>'/admin/websites.php#add','label'=>'Add Website','class'=>'primary'],
]);
admin_status_tabs('dashboard');
admin_notice($notice);
if ($syncNotice) admin_notice($syncNotice, str_contains(strtolower($syncNotice), 'failed') ? 'danger' : 'success');
admin_notice($error, 'danger');
?>

<section class="v54-metrics">
    <article class="v54-metric"><div class="v54-metric-top"><small>Overall Status</small><span class="v54-status-dot <?= e($severityClass((string)$overall['class'])) ?>"></span></div><strong><?= e((string)$overall['label']) ?></strong><em>Public code <?= e((string)$overall['code']) ?></em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Websites</small><span class="v54-status-dot <?= $problemWebsites === 0 ? 'good' : 'warn' ?>"></span></div><strong><?= $operationalWebsites ?> / <?= count($websites) ?> Online</strong><em><?= $problemWebsites ? $problemWebsites . ' need attention' : 'All tracked sites healthy' ?></em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Active Incidents</small><span class="v54-status-dot <?= $activeIncidents ? 'bad' : 'good' ?>"></span></div><strong><?= count($activeIncidents) ?></strong><em><?= $activeIncidents ? 'Open incident work' : 'No active incidents' ?></em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>30-Day Uptime</small><span class="v54-status-dot <?= $averageUptime !== null && $averageUptime >= 99.9 ? 'good' : 'warn' ?>"></span></div><strong><?= $averageUptime !== null ? e(number_format($averageUptime, 3)) . '%' : 'No data' ?></strong><em>Average tracked availability</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Platform Health</small><span class="v54-status-dot <?= e($systemHealth['overall'] === 'good' ? 'good' : ($systemHealth['overall'] === 'bad' ? 'bad' : 'warn')) ?>"></span></div><strong><?= $systemHealth['overall'] === 'good' ? 'Healthy' : ($systemHealth['overall'] === 'bad' ? 'Problem' : 'Attention') ?></strong><em><?= (int)$systemHealth['bad_count'] ?> critical · <?= (int)$systemHealth['warning_count'] ?> warnings</em></article>
</section>

<div class="v55-dashboard-toolbar">
    <div><strong>Operations workspace</strong><span>Keep the dashboard focused. Detailed administration stays in its dedicated section.</span></div>
    <?php if ($dashboardCustomizable): ?><button class="button ghost" type="button" data-dashboard-customize>Customize Dashboard</button><?php endif; ?>
</div>
<?php if ($dashboardCustomizable): ?>
<section class="v55-dashboard-customizer" data-dashboard-customizer>
    <input type="hidden" value="<?= e(csrf_token()) ?>" data-dashboard-csrf>
    <div><strong>Dashboard widgets</strong><p>Choose what appears here, then drag visible widgets into your preferred order.</p></div>
    <div class="v55-widget-options">
        <?php foreach ($widgetLabels as $key=>$label): ?><label><input type="checkbox" value="<?= e($key) ?>" data-widget-toggle <?= isset($hiddenWidgets[$key]) ? '' : 'checked' ?>> <span><?= e($label) ?></span></label><?php endforeach; ?>
    </div>
    <div class="v55-inline-actions"><button type="button" class="button primary" data-dashboard-save>Save Layout</button><button type="button" class="button ghost" data-dashboard-reset>Reset Default</button></div>
</section>
<?php endif; ?>

<section class="v55-dashboard-grid" data-dashboard-grid>
<?php foreach ($dashboardLayout['order'] as $widget): $isHidden = isset($hiddenWidgets[$widget]); ?>

<?php if ($widget === 'status'): ?>
<article class="pro-card v55-dashboard-widget v55-widget-wide <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="status">
    <div class="pro-card-head"><div><h2>Public Status</h2><p>The headline visitors see right now.</p></div><a class="v54-card-link" href="/" target="_blank" rel="noopener">Open Public Page</a></div>
    <div class="v54-card-body">
        <div class="v54-overall <?= e($severityClass((string)$overall['class'])) ?>"><div class="v54-overall-copy"><span class="v54-overall-icon">✓</span><div><h2><?= e((string)$overall['label']) ?></h2><p><?= $statusMessage !== '' ? e($statusMessage) : 'No custom public status message is currently set.' ?></p></div></div><span class="v54-overall-code">Primary: <?= e((string)$primary['name']) ?> · <?= e((string)$primary['label']) ?></span></div>
        <form method="post" class="pro-form v55-dashboard-message"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_status_message"><label>Public status message</label><div class="pro-form-row"><div><input type="text" name="status_message" value="<?= e($statusMessage) ?>" placeholder="All systems are operating normally."></div><div class="v55-form-action"><button class="button ghost" type="submit">Save Message</button></div></div></form>
    </div>
</article>
<?php elseif ($widget === 'services'): ?>
<article class="pro-card v55-dashboard-widget v55-widget-wide <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="services">
    <div class="pro-card-head"><div><h2>Service Status</h2><p>Core platform services. Expand only when you need to publish a manual change.</p></div><a class="v54-card-link" href="/admin/operations.php">Groups & Dependencies</a></div>
    <div class="v54-card-body v54-service-list">
        <?php foreach ($services as $service): $meta=status_meta((string)$service['current_status']); $sev=$severityClass((string)$meta['class']); ?>
        <div class="v54-service-row" id="service-<?= (int)$service['id'] ?>"><div class="v54-service-summary"><div class="v54-service-name"><span class="v54-status-dot <?= e($sev) ?>"></span><div><strong><?= e((string)$service['service_name']) ?></strong><small><?= e((string)($service['description'] ?? '')) ?></small></div></div><span class="v54-status-pill <?= e($sev === 'good' ? '' : $sev) ?>"><?= e((string)$meta['label']) ?></span></div>
        <details class="v54-service-edit"><summary>Change public status</summary><form method="post" class="pro-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_service"><input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>"><div class="pro-form-row"><div><label>Status</label><select name="new_status"><?php foreach (STATUS_OPTIONS as $value=>$option): ?><option value="<?= e($value) ?>" <?= $value===$service['current_status']?'selected':'' ?>><?= e((string)$option['label']) ?></option><?php endforeach; ?></select></div><div><label>Public update title</label><input type="text" name="update_title" placeholder="Optional; generated automatically"></div></div><label>Public details</label><input type="text" name="update_message" placeholder="Brief detail for visitors"><div class="pro-actions"><button class="button primary" type="submit">Publish Status Change</button></div></form></details></div>
        <?php endforeach; ?>
    </div>
</article>
<?php elseif ($widget === 'quick_actions'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="quick_actions"><div class="pro-card-head"><div><h2>Quick Actions</h2><p>Common tasks, one click away.</p></div></div><div class="v54-card-body v54-quick-actions v55-quick-actions"><a href="/admin/websites.php#add"><span class="v54-quick-icon">＋</span>Add Website</a><a href="/admin/announcements.php#add"><span class="v54-quick-icon">✦</span>Announcement</a><a href="/admin/incidents.php"><span class="v54-quick-icon">!</span>Incident</a><a href="/admin/schedules.php"><span class="v54-quick-icon">◷</span>Maintenance</a><a href="/admin/operations.php"><span class="v54-quick-icon">◇</span>Dependencies</a><a href="/admin/reports.php"><span class="v54-quick-icon">▤</span>Reports</a></div></article>
<?php elseif ($widget === 'system_health'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="system_health"><div class="pro-card-head"><div><h2>System Health</h2><p>The status platform monitoring itself.</p></div><a class="v54-card-link" href="/admin/system-health.php">Full Health</a></div><div class="v54-card-body"><div class="v55-dashboard-health <?= e($systemHealth['overall']) ?>"><span><?= $systemHealth['overall']==='good'?'✓':($systemHealth['overall']==='warning'?'!':'×') ?></span><div><strong><?= $systemHealth['overall']==='good'?'Platform healthy':($systemHealth['overall']==='warning'?'Needs attention':'Platform problem') ?></strong><small><?= (int)$systemHealth['bad_count'] ?> critical · <?= (int)$systemHealth['warning_count'] ?> warnings</small></div></div><div class="v55-mini-checks"><?php foreach(array_slice($systemHealth['checks'],0,4) as $check): ?><div><span class="v55-mini-dot <?= e($check['status']) ?>"></span><strong><?= e($check['label']) ?></strong><small><?= e($check['value']) ?></small></div><?php endforeach; ?></div></div></article>
<?php elseif ($widget === 'maintenance'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="maintenance"><div class="pro-card-head"><div><h2>Maintenance</h2><p>Current and next planned window.</p></div><a class="v54-card-link" href="/admin/schedules.php">Manage</a></div><div class="v54-card-body v54-compact-list"><?php if($activeJob): ?><div class="v54-compact-row"><div class="v54-row-main"><div><strong><?= e((string)$activeJob['title']) ?></strong><small>Ends <?= e(format_dt($activeJob['end_at']??null)) ?></small></div><span class="v54-status-pill warn">In Progress</span></div></div><?php elseif($upcomingJob): ?><div class="v54-compact-row"><div class="v54-row-main"><div><strong><?= e((string)$upcomingJob['title']) ?></strong><small>Starts <?= e(format_dt($upcomingJob['start_at']??null)) ?></small></div><span class="v54-status-pill warn">Upcoming</span></div></div><?php else: ?><div class="v54-empty">No active or upcoming maintenance.</div><?php endif; ?></div></article>
<?php elseif ($widget === 'incidents'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="incidents"><div class="pro-card-head"><div><h2>Active Incidents</h2><p>Open incident communication.</p></div><a class="v54-card-link" href="/admin/incidents.php">Manage</a></div><div class="v54-card-body v54-compact-list"><?php if(!$activeIncidents): ?><div class="v54-empty">No active incidents. Everything is clear.</div><?php endif; ?><?php foreach(array_slice($activeIncidents,0,4) as $incident): ?><div class="v54-compact-row"><div class="v54-row-main"><div><strong><?= e((string)$incident['title']) ?></strong><small><?= e(ucwords(str_replace('_',' ',(string)$incident['status']))) ?> · <?= e(format_dt($incident['updated_at']??$incident['created_at'])) ?></small></div><span class="v54-status-pill bad"><?= e(ucfirst((string)$incident['impact'])) ?></span></div></div><?php endforeach; ?></div></article>
<?php elseif ($widget === 'announcements'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="announcements"><div class="pro-card-head"><div><h2>Announcements</h2><p>Messages currently visible to visitors.</p></div><a class="v54-card-link" href="/admin/announcements.php">Manage</a></div><div class="v54-card-body v54-compact-list"><?php if(!$activeAnnouncements): ?><div class="v54-empty">No announcement is currently shown.</div><?php endif; ?><?php foreach(array_slice($activeAnnouncements,0,3) as $announcement): ?><div class="v54-compact-row"><div class="v54-row-main"><div><strong><?= e((string)$announcement['title']) ?></strong><small><?= e(format_dt($announcement['updated_at']??$announcement['created_at'])) ?></small></div><span class="v54-status-pill">Visible</span></div></div><?php endforeach; ?></div></article>
<?php elseif ($widget === 'monitoring'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="monitoring"><div class="pro-card-head"><div><h2>Monitoring</h2><p>Heartbeat, redundancy, and latest activity.</p></div><a class="v54-card-link" href="/admin/monitoring.php">Monitor Center</a></div><div class="v54-card-body v54-health-grid"><div class="v54-health-item"><small>Last Check</small><strong><?= $lastMonitor?e(format_dt($lastMonitor['created_at'])):'Pending' ?></strong><span><?= $lastMonitor?e((string)($lastMonitor['website_name']??'Website')):'No checks yet' ?></span></div><div class="v54-health-item"><small>Healthy Sources</small><strong><?= count($healthySources) ?></strong><span><?= count($healthySources)>=2?'Redundant monitoring':'Add second source' ?></span></div><div class="v54-health-item"><small>Recent Activity</small><strong><?= count($updates) ?></strong><span><a href="/admin/monitoring.php#updates">View status updates</a></span></div></div></article>
<?php elseif ($widget === 'primary'): ?>
<article class="pro-card v55-dashboard-widget <?= $isHidden ? 'v55-widget-hidden':'' ?>" data-dashboard-widget="primary"><div class="pro-card-head"><div><h2>Primary Website</h2><p>Headline website and current effective state.</p></div><a class="v54-card-link" href="/admin/websites.php">Manage</a></div><div class="v54-card-body"><div class="v54-overall <?= e($severityClass((string)$primary['class'])) ?>"><div class="v54-overall-copy"><span class="v54-overall-icon">◉</span><div><h2><?= e((string)$primary['name']) ?></h2><p><?= e((string)$primary['label']) ?></p></div></div><span class="v54-overall-code">Code <?= e((string)$primary['code']) ?></span></div></div></article>
<?php endif; ?>

<?php endforeach; ?>
</section>

<?php admin_page_end(); ?>
