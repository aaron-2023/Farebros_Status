<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/incidents.php';

$user = require_login();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_incident') {
            $targets = [];
            foreach ((array)($_POST['service_ids'] ?? []) as $id) {
                if ((int)$id > 0) $targets[] = ['type' => 'service', 'id' => (int)$id];
            }
            foreach ((array)($_POST['website_ids'] ?? []) as $id) {
                if ((int)$id > 0) $targets[] = ['type' => 'website', 'id' => (int)$id];
            }
            $id = create_incident(
                trim((string)($_POST['title'] ?? '')),
                trim((string)($_POST['summary'] ?? '')),
                (string)($_POST['impact'] ?? 'minor'),
                $targets,
                (int)$user['id'],
                false
            );
            audit_admin_action($user, 'create_incident', 'incident', $id);
            $notice = 'Incident created and notifications sent.';
        }

        if ($action === 'add_incident_update') {
            $id = (int)($_POST['incident_id'] ?? 0);
            add_incident_update($id, (string)($_POST['status'] ?? 'investigating'), trim((string)($_POST['message'] ?? '')), (int)$user['id']);
            audit_admin_action($user, 'incident_update', 'incident', $id, (string)($_POST['status'] ?? ''));
            $notice = 'Incident timeline updated.';
        }

        if ($action === 'delete_incident') {
            $id = (int)($_POST['incident_id'] ?? 0);
            delete_incident($id);
            audit_admin_action($user, 'delete_incident', 'incident', $id);
            $notice = 'Incident deleted.';
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$services = get_services();
$websites = get_websites();
$incidents = get_recent_incidents(50);
$active = array_filter($incidents, static fn(array $i): bool => $i['status'] !== 'resolved');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Incidents - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
    <link rel="stylesheet" href="/assets/css/admin-v52.css?v=5.3.0">
</head>
<body class="admin-pro">
<aside class="pro-sidebar">
    <div class="pro-brand"><div class="pro-brand-pill">Fare Brothers</div><h2>Status Admin</h2><p>Incident response and public communication.</p></div>
    <nav class="pro-nav">
        <div class="pro-nav-group"><small>Main</small><a href="/admin/dashboard.php"><span>▣</span>Dashboard</a><a href="/" target="_blank"><span>↗</span>Public Page</a></div>
        <div class="pro-nav-group"><small>Manage</small><a href="/admin/schedules.php"><span>🗓</span>Schedules</a><a class="active" href="/admin/incidents.php"><span>⚠</span>Incidents</a><a href="/admin/analytics.php"><span>⌁</span>Analytics</a></div>
        <div class="pro-nav-group"><small>Admin</small><a href="/admin/settings.php"><span>⚙</span>Settings</a><a href="/admin/audit-log.php"><span>☷</span>Audit Log</a><a href="/admin/change-password.php"><span>🔒</span>Password</a><a href="/admin/logout.php"><span>⎋</span>Logout</a></div>
    </nav>
</aside>
<main class="pro-main">
    <header class="pro-topbar"><div><div class="pro-kicker">Incident Manager</div><h1>Incidents</h1><p>Post investigating → identified → monitoring → resolved timelines with affected systems.</p></div><div class="pro-top-actions"><a class="button ghost" href="/admin/dashboard.php">Dashboard</a><a class="button primary" href="/" target="_blank">Public Page</a></div></header>
    <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice-box danger"><?= e($error) ?></div><?php endif; ?>

    <section class="pro-status-strip pro-status-strip-three">
        <article class="<?= $active ? 'bad' : 'good' ?>"><span class="fb-dot"></span><small>Active Incidents</small><strong><?= count($active) ?></strong><em><?= $active ? 'Publicly visible' : 'All clear' ?></em></article>
        <article><span class="pro-icon">☷</span><small>Incident History</small><strong><?= count($incidents) ?></strong><em>latest 50</em></article>
        <article><span class="pro-icon">✦</span><small>Automatic Incidents</small><strong><?= count(array_filter($incidents, static fn($i) => (int)$i['is_auto'] === 1)) ?></strong><em>created by confirmed monitor changes</em></article>
    </section>

    <section class="pro-grid">
        <article class="pro-card">
            <div class="pro-card-head"><div><h2>Create Incident</h2><p>Use this for a real outage or degraded event that needs a public timeline.</p></div></div>
            <form method="post" class="pro-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_incident">
                <label>Title</label><input type="text" name="title" required placeholder="Fare Brothers login issues">
                <label>Impact</label><select name="impact"><option value="minor">Minor</option><option value="major">Major</option><option value="critical">Critical</option></select>
                <label>Initial update</label><textarea name="summary" rows="5" placeholder="We are investigating reports of..." required></textarea>
                <div class="pro-form-row">
                    <div><label>Affected services</label><select name="service_ids[]" multiple size="5"><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>"><?= e($service['service_name']) ?></option><?php endforeach; ?></select></div>
                    <div><label>Affected websites</label><select name="website_ids[]" multiple size="5"><?php foreach ($websites as $website): ?><option value="<?= (int)$website['id'] ?>"><?= e($website['website_name']) ?></option><?php endforeach; ?></select></div>
                </div>
                <small class="pro-field-hint">Ctrl/Cmd-click to choose multiple targets. Automatic monitor incidents attach the affected website for you.</small>
                <button class="button primary" type="submit">Create Incident</button>
            </form>
        </article>

        <article class="pro-card">
            <div class="pro-card-head"><div><h2>Incident Workflow</h2><p>Keep updates short and useful — each post can trigger email and Discord alerts.</p></div></div>
            <div class="pro-flow pro-flow-four"><div><small>1</small><strong>Investigating</strong><p>Issue confirmed; cause unknown.</p></div><div><small>2</small><strong>Identified</strong><p>Root cause understood.</p></div><div><small>3</small><strong>Monitoring</strong><p>Fix applied; watching recovery.</p></div><div><small>4</small><strong>Resolved</strong><p>Service back to normal.</p></div></div>
            <div class="pro-help"><strong>Automatic incidents:</strong> confirmed monitor outages/degraded states can create incidents automatically after flap protection is satisfied. Recovery resolves them automatically.</div>
        </article>
    </section>

    <section class="pro-card pro-card-full">
        <div class="pro-card-head"><div><h2>Incident Timeline</h2><p>Active incidents first, then resolved history.</p></div></div>
        <div class="incident-admin-list">
            <?php if (!$incidents): ?><p class="empty">No incidents yet.</p><?php endif; ?>
            <?php foreach ($incidents as $incident): ?>
                <details class="incident-admin-item <?= e($incident['status']) ?>" <?= $incident['status'] !== 'resolved' ? 'open' : '' ?>>
                    <summary>
                        <span class="incident-impact <?= e($incident['impact']) ?>"><?= e(incident_impact_label($incident['impact'])) ?></span>
                        <span><strong><?= e($incident['title']) ?></strong><small><?= e(incident_status_label($incident['status'])) ?> · Started <?= e(format_dt($incident['started_at'])) ?><?= (int)$incident['is_auto'] === 1 ? ' · Auto' : '' ?></small></span>
                    </summary>
                    <div class="incident-admin-body">
                        <?php if ($incident['targets']): ?><div class="incident-targets"><?php foreach ($incident['targets'] as $target): ?><span class="pro-chip"><?= e(ucfirst($target['target_type'])) ?>: <?= e($target['target_name'] ?? ('#'.$target['target_id'])) ?></span><?php endforeach; ?></div><?php endif; ?>
                        <div class="incident-timeline"><?php foreach ($incident['updates'] as $update): ?><article><span><?= e(incident_status_label($update['status'])) ?></span><p><?= nl2br(e($update['message'])) ?></p><small><?= e(format_dt($update['created_at'])) ?></small></article><?php endforeach; ?></div>
                        <?php if ($incident['status'] !== 'resolved'): ?>
                            <form method="post" class="pro-form incident-update-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_incident_update"><input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                                <div class="pro-form-row"><div><label>Next status</label><select name="status"><option value="investigating" <?= $incident['status']==='investigating'?'selected':'' ?>>Investigating</option><option value="identified" <?= $incident['status']==='identified'?'selected':'' ?>>Identified</option><option value="monitoring" <?= $incident['status']==='monitoring'?'selected':'' ?>>Monitoring</option><option value="resolved">Resolved</option></select></div><div><label>Update message</label><input type="text" name="message" required placeholder="We identified the issue and are applying a fix."></div></div>
                                <button class="button primary small" type="submit">Post Incident Update</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" class="pro-delete" onsubmit="return confirm('Delete this incident and its timeline permanently?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_incident"><input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>"><button class="button danger small" type="submit">Delete Incident</button></form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
