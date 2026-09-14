<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/scheduler.php';

require_once __DIR__ . '/_layout.php';

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
        if ($action === 'save_template') {
            $templateId = save_maintenance_template($_POST, (int)$user['id']);
            $notice = 'Maintenance template saved.';
            audit_admin_action($user, 'save_maintenance_template', 'maintenance_template', $templateId);
        }
        if ($action === 'delete_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            delete_maintenance_template($templateId);
            $notice = 'Maintenance template deleted.';
            audit_admin_action($user, 'delete_maintenance_template', 'maintenance_template', $templateId);
        }
        if ($action !== '' && !in_array($action, ['save_template','delete_template'], true)) {
            audit_admin_action($user, $action, 'schedule', isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : null);
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$schedules = get_scheduled_jobs();
$activeJob = active_job_payload();
$upcomingJob = upcoming_job_payload();
$services = get_services();
$websites = get_websites();
$templates = get_maintenance_templates();

function schedule_phase(array $job): string
{
    $utc = new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now', $utc);
    $start = new DateTimeImmutable((string)$job['start_at'], $utc);
    $end = new DateTimeImmutable((string)$job['end_at'], $utc);

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

function schedule_scope_label(array $job, array $services, array $websites): string
{
    $scope = (string)($job['scope'] ?? 'all');

    if ($scope === 'all') return 'Everything';
    if ($scope === 'services') return 'All services';
    if ($scope === 'websites') return 'All websites';

    if ($scope === 'single_service') {
        foreach ($services as $service) {
            if ((int)$service['id'] === (int)($job['target_id'] ?? 0)) {
                return 'Service: ' . $service['service_name'];
            }
        }
        return 'Single service';
    }

    if ($scope === 'single_website') {
        foreach ($websites as $website) {
            if ((int)$website['id'] === (int)($job['target_id'] ?? 0)) {
                return 'Website: ' . $website['website_name'];
            }
        }
        return 'Single website';
    }

    return ucfirst(str_replace('_', ' ', $scope));
}
?>
<?php
admin_page_start(
    'maintenance',
    'Maintenance',
    'Plan maintenance windows and control how scheduled work changes the public status.',
    'Status Management',
    [['href' => '/admin/incidents.php', 'label' => 'Incidents', 'class' => 'ghost'],
        ['href' => '/', 'label' => 'View Public Page', 'class' => 'primary', 'external' => true]]
);
?>

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

                    <?php if ($templates): ?>
                    <label>Start from template <span style="color:#607b97">(optional)</span></label>
                    <select data-maint-template-select>
                        <option value="">Blank maintenance window</option>
                        <?php foreach ($templates as $template): $templateData = [
                            'title' => $template['title'], 'details' => $template['details'], 'scope' => $template['scope'],
                            'target_id' => $template['target_id'], 'active_status' => $template['active_status'], 'after_status' => $template['after_status'],
                            'duration_minutes' => (int)$template['duration_minutes']
                        ]; ?>
                            <option value="<?= (int)$template['id'] ?>" data-template="<?= e(json_encode($templateData, JSON_UNESCAPED_SLASHES)) ?>"><?= e($template['template_name']) ?> · <?= (int)$template['duration_minutes'] ?> min</option>
                        <?php endforeach; ?>
                    </select>
                    <small class="pro-field-hint">Templates fill the title, details, scope, target, duration, and recovery behavior. Review the start/end time before saving.</small>
                    <?php endif; ?>

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
                    <select name="scope" id="scheduleScope">
                        <option value="all">Everything: all services and websites</option>
                        <option value="services">All services only</option>
                        <option value="websites">All websites only</option>
                        <option value="single_service">One specific service</option>
                        <option value="single_website">One specific website</option>
                    </select>
                    <small class="pro-field-hint">Upcoming maintenance is shown as an overlay only. Live status changes when the maintenance window actually begins.</small>

                    <div class="pro-target-panel" id="serviceTargetPanel">
                        <label>Target service</label>
                        <select name="target_id" id="serviceTarget" disabled>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= (int)$service['id'] ?>"><?= e($service['service_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pro-target-panel" id="websiteTargetPanel">
                        <label>Target website</label>
                        <select name="target_id" id="websiteTarget" disabled>
                            <?php foreach ($websites as $website): ?>
                                <option value="<?= (int)$website['id'] ?>"><?= e($website['website_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="target_type" id="targetType" value="">

                    <input type="hidden" name="scheduled_status" value="planned_maintenance">

                    <div class="pro-form-row">
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

                        <div>
                            <label>Status after event</label>
                            <select name="after_status">
                                <?php foreach (STATUS_OPTIONS as $key => $meta): ?>
                                    <option value="<?= e($key) ?>" <?= $key === 'operational' ? 'selected' : '' ?>>
                                        Code <?= (int)$meta['code'] ?> - <?= e($meta['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

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

                <div class="pro-flow">
                    <div>
                        <small>Before start</small>
                        <strong>Live status stays unchanged</strong>
                    </div>
                    <div>
                        <small>During event</small>
                        <strong>Selected maintenance status is applied</strong>
                    </div>
                    <div>
                        <small>After event</small>
                        <strong>Selected recovery status is applied</strong>
                    </div>
                </div>

                <p class="pro-help"><strong>Targeted maintenance is supported.</strong> You can schedule everything, all services, all websites, one service, or one website.</p>
            </article>
        </section>

        <section class="pro-card pro-card-full">
            <div class="pro-card-head"><div><h2>Maintenance Templates</h2><p>Save repeatable maintenance plans so common work takes seconds to schedule.</p></div><span class="v54-count"><?= count($templates) ?> template<?= count($templates) === 1 ? '' : 's' ?></span></div>
            <div class="v55-card-body v55-section-grid">
                <form method="post" class="pro-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_template">
                    <label>Template name</label><input type="text" name="template_name" placeholder="Monthly Server Updates" required>
                    <label>Public maintenance title</label><input type="text" name="title" placeholder="Scheduled Infrastructure Maintenance" required>
                    <label>Details</label><textarea name="details" rows="3" placeholder="Brief visitor-facing description"></textarea>
                    <div class="pro-form-row"><div><label>Scope</label><select name="scope" id="templateScope"><option value="all">Everything</option><option value="services">All services</option><option value="websites">All websites</option><option value="single_service">One service</option><option value="single_website">One website</option></select></div><div><label>Default duration</label><div class="pro-input-suffix"><input type="number" name="duration_minutes" min="5" max="10080" value="60"><span>min</span></div></div></div>
                    <div class="pro-target-panel" id="templateServicePanel"><label>Target service</label><select name="target_id" id="templateServiceTarget" disabled><?php foreach($services as $service): ?><option value="<?= (int)$service['id'] ?>"><?= e($service['service_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="pro-target-panel" id="templateWebsitePanel"><label>Target website</label><select name="target_id" id="templateWebsiteTarget" disabled><?php foreach($websites as $website): ?><option value="<?= (int)$website['id'] ?>"><?= e($website['website_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="pro-form-row"><div><label>Status during</label><select name="active_status"><?php foreach(STATUS_OPTIONS as $key=>$meta): ?><option value="<?= e($key) ?>" <?= $key==='maintenance'?'selected':'' ?>><?= e($meta['label']) ?></option><?php endforeach; ?></select></div><div><label>Status after</label><select name="after_status"><?php foreach(STATUS_OPTIONS as $key=>$meta): ?><option value="<?= e($key) ?>" <?= $key==='operational'?'selected':'' ?>><?= e($meta['label']) ?></option><?php endforeach; ?></select></div></div>
                    <div class="pro-actions"><button class="button primary" type="submit">Save Template</button></div>
                </form>
                <div class="v55-template-list">
                    <?php if(!$templates): ?><div class="v55-empty-state"><strong>No templates yet</strong><p>Create a template for maintenance work you repeat.</p></div><?php endif; ?>
                    <?php foreach($templates as $template): ?><article class="v55-template-row"><div class="v55-row-between"><div><h3><?= e($template['template_name']) ?></h3><p><?= e($template['title']) ?> · <?= (int)$template['duration_minutes'] ?> min · <?= e(ucwords(str_replace('_',' ',$template['scope']))) ?></p></div><form method="post" data-confirm="Delete this maintenance template?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>"><button class="button danger small" type="submit">Delete</button></form></div></article><?php endforeach; ?>
                </div>
            </div>
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
                            <small><?= e(format_dt($job['start_at'])) ?> → <?= e(format_dt($job['end_at'])) ?></small>
                            <div class="pro-schedule-meta">
                                <span class="pro-chip"><?= e(schedule_scope_label($job, $services, $websites)) ?></span>
                                <span class="pro-chip">During: <?= e(status_meta($job['active_status'])['label']) ?></span>
                                <span class="pro-chip">After: <?= e(status_meta($job['after_status'])['label']) ?></span>
                            </div>
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
    <script>
    (function () {
        const scope = document.getElementById('scheduleScope');
        const servicePanel = document.getElementById('serviceTargetPanel');
        const websitePanel = document.getElementById('websiteTargetPanel');
        const serviceTarget = document.getElementById('serviceTarget');
        const websiteTarget = document.getElementById('websiteTarget');
        const targetType = document.getElementById('targetType');

        function syncTargets() {
            const value = scope ? scope.value : 'all';
            const serviceMode = value === 'single_service';
            const websiteMode = value === 'single_website';

            servicePanel?.classList.toggle('show', serviceMode);
            websitePanel?.classList.toggle('show', websiteMode);
            if (serviceTarget) serviceTarget.disabled = !serviceMode;
            if (websiteTarget) websiteTarget.disabled = !websiteMode;
            if (targetType) targetType.value = serviceMode ? 'service' : (websiteMode ? 'website' : '');
        }

        scope?.addEventListener('change', syncTargets);
        syncTargets();

        const templateScope = document.getElementById('templateScope');
        const templateServicePanel = document.getElementById('templateServicePanel');
        const templateWebsitePanel = document.getElementById('templateWebsitePanel');
        const templateServiceTarget = document.getElementById('templateServiceTarget');
        const templateWebsiteTarget = document.getElementById('templateWebsiteTarget');
        function syncTemplateTargets() {
            const value = templateScope ? templateScope.value : 'all';
            const serviceMode = value === 'single_service';
            const websiteMode = value === 'single_website';
            templateServicePanel?.classList.toggle('show', serviceMode);
            templateWebsitePanel?.classList.toggle('show', websiteMode);
            if (templateServiceTarget) templateServiceTarget.disabled = !serviceMode;
            if (templateWebsiteTarget) templateWebsiteTarget.disabled = !websiteMode;
        }
        templateScope?.addEventListener('change', syncTemplateTargets);
        syncTemplateTargets();
    })();
    </script>
<?php admin_page_end(); ?>
