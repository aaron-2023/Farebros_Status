<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/functions.php';
if (file_exists(__DIR__ . '/../app/scheduler.php')) {
    require_once __DIR__ . '/../app/scheduler.php';
}

$payload = public_status_payload();
$overall = $payload['overall'];
$primary = $payload['primary'];
$settings = $payload['settings'] ?? [];
$companyName = $settings['company_name'] ?? 'Fare Brothers, LLC';
$statusPageLabel = $settings['status_page_label'] ?? 'Status Center';
$showAdminLink = !empty($settings['public_admin_link']);

$activeSchedule = null;
if (function_exists('active_job_payload')) {
    $activeSchedule = active_job_payload();
    if ($activeSchedule) {
        $activeSchedule['phase'] = 'active';
    } else {
        $activeSchedule = upcoming_job_payload();
        if ($activeSchedule) {
            $activeSchedule['phase'] = 'upcoming';
        }
    }
}

$lastWebsiteCheck = null;
foreach ($payload['websites'] as $site) {
    $check = $site['last_checked_at_iso'] ?? $site['last_checked_at'] ?? null;
    if ($check && ($lastWebsiteCheck === null || strtotime($check) > strtotime($lastWebsiteCheck))) {
        $lastWebsiteCheck = $check;
    }
}

function page_status_message(array $overall, array $primary, ?array $schedule): string
{
    if ($schedule && ($schedule['phase'] ?? '') === 'active') {
        return 'A scheduled maintenance window is currently active.';
    }

    if ($schedule && ($schedule['phase'] ?? '') === 'upcoming') {
        return 'A scheduled maintenance window is upcoming. Current live status is still shown below.';
    }

    if (($overall['status'] ?? '') === 'operational') {
        return 'All monitored systems are operational.';
    }

    if (($primary['status'] ?? '') !== 'operational') {
        return 'The primary Fare Brothers website is reporting an issue.';
    }

    return 'One or more monitored systems are reporting an issue.';
}

function item_schedule_overlay(string $type, int $id): ?array
{
    if (!function_exists('current_schedule_for_item')) {
        return null;
    }

    return current_schedule_for_item($type, $id);
}

function overlay_label(?array $overlay): string
{
    if (!$overlay) {
        return '';
    }

    return ($overlay['phase'] ?? '') === 'active'
        ? 'Maintenance in progress'
        : 'Planned maintenance scheduled';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($companyName) ?> Status</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <link rel="stylesheet" href="/assets/css/statuspage-v49.css?v=5.0.0">
    <link rel="stylesheet" href="/assets/css/statuspage-v50.css?v=5.0.0">
</head>
<body>
    <header class="sp-top">
        <div class="sp-wrap sp-top-inner">
            <a class="sp-brand" href="/">
                <img src="/assets/img/fare-brothers-logo.png" alt="Fare Brothers logo">
                <span>
                    <strong>Fare Brothers</strong>
                    <small><?= e($statusPageLabel) ?></small>
                </span>
            </a>

            <nav class="sp-nav">
                <a href="https://farebros.com">Main Website</a>
                <a href="#systems">Systems</a>
                <a href="#websites">Websites</a>
                <a href="#updates">Updates</a>
                <?php if ($showAdminLink): ?><a class="sp-admin" href="/admin/login.php">Admin</a><?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="sp-wrap">
        <section class="sp-status-card <?= e($overall['class']) ?>">
            <div class="sp-status-main">
                <span class="sp-dot"></span>
                <div>
                    <div class="sp-label">Current Status</div>
                    <h1><?= e($overall['label']) ?></h1>
                    <p><?= e(page_status_message($overall, $primary, $activeSchedule)) ?></p>
                </div>
            </div>

            <div class="sp-meta">
                <div><small>Status Code</small><strong><?= e((string)$overall['code']) ?></strong></div>
                <div><small>Last Updated</small><strong><?= e(format_dt($payload['generated_at'])) ?></strong></div>
                <div><small>Timezone</small><strong><?= e($payload['timezone_label'] ?? '') ?></strong></div>
            </div>
        </section>

        <?php if (!empty($payload['message'])): ?>
            <section class="sp-notice">
                <strong>Status Message</strong>
                <p><?= e($payload['message']) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($activeSchedule): ?>
            <section class="sp-schedule <?= e($activeSchedule['phase']) ?>">
                <div>
                    <strong><?= ($activeSchedule['phase'] ?? '') === 'active' ? 'Maintenance In Progress' : 'Upcoming Maintenance' ?></strong>
                    <h2><?= e($activeSchedule['title'] ?? 'Scheduled Maintenance') ?></h2>
                    <p><?= e($activeSchedule['details'] ?? '') ?></p>
                </div>
                <div class="sp-schedule-time">
                    <span><?= e(format_dt($activeSchedule['start_at'] ?? '')) ?></span>
                    <em>to</em>
                    <span><?= e(format_dt($activeSchedule['end_at'] ?? '')) ?></span>
                </div>
            </section>
        <?php endif; ?>

        <section class="sp-summary">
            <article>
                <small>Primary Website</small>
                <strong><?= e($primary['name']) ?></strong>
                <span class="sp-pill <?= e($primary['class']) ?>"><i></i><?= e($primary['label']) ?></span>
            </article>
            <article><small>Systems</small><strong><?= count($payload['services']) ?></strong><span>services monitored</span></article>
            <article><small>Websites</small><strong><?= count($payload['websites']) ?></strong><span>websites monitored</span></article>
            <article><small>Last Website Check</small><strong><?= $lastWebsiteCheck ? e(format_dt($lastWebsiteCheck)) : 'Pending' ?></strong><span>offsite monitor</span></article>
        </section>

        <section class="sp-section" id="systems">
            <div class="sp-section-head">
                <div><h2>Systems</h2><p>Current live status is shown first. Scheduled maintenance appears as a separate notice.</p></div>
            </div>

            <div class="sp-list" id="serviceGrid">
                <?php foreach ($payload['services'] as $service): $overlay = item_schedule_overlay('service', (int)$service['id']); ?>
                    <article class="sp-row <?= e($service['class']) ?>">
                        <div class="sp-row-main">
                            <span class="sp-dot"></span>
                            <div>
                                <strong><?= e($service['name']) ?></strong>
                                <p><?= e($service['description']) ?></p>
                                <?php if ($overlay): ?>
                                    <span class="sp-maint-badge <?= e($overlay['phase']) ?>">
                                        <?= e(overlay_label($overlay)) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="sp-row-status">
                            <strong><?= e($service['label']) ?></strong>
                            <small>Code <?= e((string)$service['code']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="sp-section" id="websites">
            <div class="sp-section-head">
                <div><h2>Websites</h2><p>Websites checked by the offsite monitor. Scheduled maintenance does not hide the current result.</p></div>
            </div>

            <div class="sp-list" id="websiteTable">
                <?php foreach ($payload['websites'] as $website): $overlay = item_schedule_overlay('website', (int)$website['id']); ?>
                    <article class="sp-row <?= e($website['class']) ?>">
                        <div class="sp-row-main">
                            <span class="sp-dot"></span>
                            <div>
                                <strong><?= e($website['name']) ?><?= $website['is_primary'] ? ' · Primary' : '' ?></strong>
                                <p><?= e($website['url']) ?></p>
                                <?php if ($overlay): ?>
                                    <span class="sp-maint-badge <?= e($overlay['phase']) ?>">
                                        <?= e(overlay_label($overlay)) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="sp-row-status">
                            <strong><?= e($website['label']) ?></strong>
                            <small><?= $website['last_checked_at'] ? 'Checked ' . e(format_dt($website['last_checked_at'])) : 'Waiting for first check' ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="sp-two" id="updates">
            <div class="sp-section">
                <div class="sp-section-head"><div><h2>Announcements</h2><p>Active public notices.</p></div></div>
                <div id="announcementList">
                    <?php if (!$payload['announcements']): ?><p class="sp-empty">No active announcements.</p><?php endif; ?>
                    <?php foreach ($payload['announcements'] as $item): ?>
                        <article class="sp-update">
                            <strong><?= e($item['title']) ?></strong>
                            <p><?= nl2br(e($item['body'])) ?></p>
                            <small><?= e(format_dt($item['created_at'])) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sp-section">
                <div class="sp-section-head"><div><h2>Recent Updates</h2><p>Status history and changes.</p></div></div>
                <div id="updateList">
                    <?php if (!$payload['recent_updates']): ?><p class="sp-empty">No recent updates.</p><?php endif; ?>
                    <?php foreach ($payload['recent_updates'] as $item): ?>
                        <article class="sp-update">
                            <strong><?= e($item['update_title']) ?></strong>
                            <p><?= nl2br(e($item['update_message'])) ?></p>
                            <small><?= e($item['service_name'] ?? 'General') ?> · <?= e(format_dt($item['created_at'])) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <footer class="sp-footer sp-wrap">
        <span>© <?= date('Y') ?> <?= e($companyName) ?></span>
        <span>Independent offsite status page</span>
    </footer>

    <script>window.STATUS_REFRESH_SECONDS = <?= (int)($payload['refresh_seconds'] ?? 15) ?>;</script>
    <script src="/assets/js/statuspage-v50.js?v=5.0.0"></script>
</body>
</html>
