<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/scheduler.php';

$user = require_login();

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_schedule') {
            add_scheduled_job($_POST);
            $notice = 'Scheduled maintenance job added.';
        }

        if ($action === 'toggle_schedule') {
            update_scheduled_job_active((int)($_POST['schedule_id'] ?? 0), !empty($_POST['make_active']));
            $notice = 'Schedule updated.';
        }

        if ($action === 'delete_schedule') {
            delete_scheduled_job((int)($_POST['schedule_id'] ?? 0));
            $notice = 'Schedule deleted.';
        }

        if ($action === 'apply_schedules_now') {
            $actions = apply_scheduled_jobs();
            $notice = 'Scheduler checked. Actions: ' . (empty($actions) ? 'none' : implode(', ', $actions));
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$schedules = get_scheduled_jobs();
$activeJob = active_job_payload();
$upcomingJob = upcoming_job_payload();

function schedule_phase(array $job): string
{
    $now = time();
    $start = strtotime($job['start_at']);
    $end = strtotime($job['end_at']);

    if ((int)$job['has_completed'] === 1) {
        return 'Completed';
    }

    if (!(int)$job['is_active']) {
        return 'Disabled';
    }

    if ($now < $start) {
        return 'Upcoming';
    }

    if ($now >= $start && $now < $end) {
        return 'Active Now';
    }

    return 'Ending';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Scheduled Maintenance - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
</head>
<body class="admin-pro">
    <aside class="pro-sidebar">
        <div class="pro-brand">
            <div class="pro-brand-pill">Fare Brothers</div>
            <h2>Status Admin</h2>
            <p>Plan maintenance windows without hardcoded buttons.</p>
        </div>

        <nav class="pro-nav">
            <div class="pro-nav-group">
                <small>Main</small>
                <a href="/admin/dashboard.php"><span>▣</span>Dashboard</a>
                <a href="/" target="_blank"><span>↗</span>Public Page</a>
            </div>
            <div class="pro-nav-group">
                <small>Manage</small>
                <a class="active" href="/admin/schedules.php"><span>🗓</span>Schedules</a>
            </div>
            <div class="pro-nav-group">
                <small>Admin</small>
                <a href="/admin/settings.php"><span>⚙</span>Settings</a>
                <a href="/admin/change-password.php"><span>🔒</span>Password</a>
                <a href="/admin/logout.php"><span>⎋</span>Logout</a>
            </div>
        </nav>
    </aside>

    <main class="pro-main">
        <header class="pro-topbar">
            <div>
                <div class="pro-kicker">Schedule Manager</div>
                <h1>Scheduled Maintenance</h1>
                <p>Create planned outages, upgrades, maintenance windows, or known service interruptions.</p>
            </div>

            <div class="pro-top-actions">
                <a class="button ghost" href="/admin/dashboard.php">Back to Dashboard</a>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="apply_schedules_now">
                    <button class="button primary" type="submit">Check Now</button>
                </form>
            </div>
        </header>

        <?php if ($notice): ?><div class="notice-box success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice-box danger"><?= e($error) ?></div><?php endif; ?>

        <section class="pro-status-strip pro-status-strip-three">
            <article class="<?= $activeJob ? 'bad' : 'good' ?>">
                <span class="fb-dot"></span>
                <small>Active Job</small>
                <strong><?= $activeJob ? e($activeJob['title']) : 'None' ?></strong>
                <em><?= $activeJob ? e(format_dt($activeJob['end_at'])) : 'No active outage' ?></em>
            </article>

            <article class="<?= $upcomingJob ? 'maintenance' : 'good' ?>">
                <span class="fb-dot"></span>
                <small>Next Upcoming</small>
                <strong><?= $upcomingJob ? e($upcomingJob['title']) : 'None' ?></strong>
                <em><?= $upcomingJob ? e(format_dt($upcomingJob['start_at'])) : 'Nothing scheduled' ?></em>
            </article>

            <article>
                <span class="pro-icon">🗓</span>
                <small>Total Jobs</small>
                <strong><?= count($schedules) ?></strong>
                <em>including completed history</em>
            </article>
        </section>

        <section class="pro-grid">
            <article class="pro-card">
                <div class="pro-card-head">
                    <div>
                        <h2>Create Scheduled Job</h2>
                        <p>Example: create your June 20 electrical upgrade here manually, then it will be treated like any other scheduled event.</p>
                    </div>
                </div>

                <form method="post" class="pro-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_schedule">

                    <label>Title</label>
                    <input type="text" name="title" placeholder="Planned Electrical Infrastructure Upgrade" required>

                    <label>Details</label>
                    <textarea name="details" rows="4" placeholder="During this window, all primary services are expected to be offline. The offsite status page will remain available."></textarea>

                    <div class="pro-form-row">
                        <div>
                            <label>Start</label>
                            <input type="datetime-local" name="start_at" required>
                        </div>
                        <div>
                            <label>End</label>
                            <input type="datetime-local" name="end_at" required>
                        </div>
                    </div>

                    <label>Scope</label>
                    <select name="scope">
                        <option value="all">Everything: all services and websites</option>
                        <option value="services">All services only</option>
                        <option value="websites">All websites only</option>
                    </select>

                    <div class="pro-form-row">
                        <div>
                            <label>Status before start</label>
                            <select name="scheduled_status">
                                <?php foreach (STATUS_OPTIONS as $key => $meta): ?>
                                    <option value="<?= e($key) ?>" <?= $key === 'planned_maintenance' ? 'selected' : '' ?>>
                                        Code <?= (int)$meta['code'] ?> - <?= e($meta['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>Status during event</label>
                            <select name="active_status">
                                <?php foreach (STATUS_OPTIONS as $key => $meta): ?>
                                    <option value="<?= e($key) ?>" <?= $key === 'offline' ? 'selected' : '' ?>>
                                        Code <?= (int)$meta['code'] ?> - <?= e($meta['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label>Status after end</label>
                    <select name="after_status">
                        <?php foreach (STATUS_OPTIONS as $key => $meta): ?>
                            <option value="<?= e($key) ?>" <?= $key === 'operational' ? 'selected' : '' ?>>
                                Code <?= (int)$meta['code'] ?> - <?= e($meta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="button primary" type="submit">Create Schedule</button>
                </form>
            </article>

            <article class="pro-card">
                <div class="pro-card-head">
                    <div>
                        <h2>Scheduler Rules</h2>
                        <p>The existing cron monitor enforces the event every minute.</p>
                    </div>
                </div>

                <div class="pro-mini-stack">
                    <div>
                        <small>Before start</small>
                        <strong>Planned maintenance</strong>
                    </div>
                    <div>
                        <small>During event</small>
                        <strong>Offline / selected active status</strong>
                    </div>
                    <div>
                        <small>After event</small>
                        <strong>Operational / selected after status</strong>
                    </div>
                </div>
            </article>
        </section>

        <section class="pro-card pro-card-full">
            <div class="pro-card-head">
                <div>
                    <h2>Scheduled Jobs</h2>
                    <p>Completed jobs stay here as history. Disable or delete anything you no longer need.</p>
                </div>
            </div>

            <div class="pro-schedule-list">
                <?php if (!$schedules): ?><p class="empty">No scheduled jobs yet.</p><?php endif; ?>

                <?php foreach ($schedules as $job): ?>
                    <article>
                        <div>
                            <span><?= e(schedule_phase($job)) ?></span>
                            <h3><?= e($job['title']) ?></h3>
                            <p><?= e($job['details']) ?></p>
                            <small>
                                <?= e(format_dt($job['start_at'])) ?> → <?= e(format_dt($job['end_at'])) ?>
                                · Scope: <?= e($job['scope']) ?>
                                · Before: <?= e(status_meta($job['scheduled_status'])['label']) ?>
                                · During: <?= e(status_meta($job['active_status'])['label']) ?>
                                · After: <?= e(status_meta($job['after_status'])['label']) ?>
                            </small>
                        </div>

                        <div class="pro-actions">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_schedule">
                                <input type="hidden" name="schedule_id" value="<?= (int)$job['id'] ?>">
                                <input type="hidden" name="make_active" value="<?= $job['is_active'] ? '0' : '1' ?>">
                                <button class="button ghost small" type="submit"><?= $job['is_active'] ? 'Disable' : 'Enable' ?></button>
                            </form>

                            <form method="post" onsubmit="return confirm('Delete this scheduled job?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_id" value="<?= (int)$job['id'] ?>">
                                <button class="button ghost small" type="submit">Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
