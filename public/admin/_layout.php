<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/platform.php';

/** Shared FareBros Status v5.5.4 administration shell. */
function admin_nav_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>',
        'external' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9"/><path d="M19 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6"/></svg>',
        'websites' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M4.5 9h15M4.5 15h15M12 4c2 2.2 3 4.9 3 8s-1 5.8-3 8c-2-2.2-3-4.9-3-8s1-5.8 3-8Z"/></svg>',
        'groups' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="9" y="14" width="6" height="6" rx="1"/><path d="M7 10v2h10v-2M12 12v2"/></svg>',
        'announcements' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13V9l12-4v12L4 13Z"/><path d="M8 14l1.5 5h3L11 13.8M19 8v6"/></svg>',
        'incidents' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 4 9 16H3L12 4Z"/><path d="M12 9v5M12 17h.01"/></svg>',
        'maintenance' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5a4 4 0 0 0-5-5L12 4 9 7 6.5 4.5a4 4 0 0 0 5 5L19 17l2-2-6.5-8.5Z"/><path d="m5 19 4-4"/></svg>',
        'monitoring' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l2-5 4 10 2-5h6"/><path d="M4 20h16"/></svg>',
        'analytics' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19V9M10 19V5M15 19v-7M20 19V3"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6V3Z"/><path d="M15 3v5h5M9 12h7M9 16h7"/></svg>',
        'health' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-8-4.8-8-10a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.2-8 10-8 10Z"/><path d="M7.5 12h2l1-2 2 4 1-2h3"/></svg>',
        'subscribers' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l-2-.7a7 7 0 0 0-.8-1.8l.9-1.9-2.2-2.2-1.9.9a7 7 0 0 0-1.8-.8L10.5 2h-3l-.7 2a7 7 0 0 0-1.8.8l-1.9-.9L.9 6.1 1.8 8a7 7 0 0 0-.8 1.8l-2 .7v3l2 .7a7 7 0 0 0 .8 1.8l-.9 1.9 2.2 2.2 1.9-.9a7 7 0 0 0 1.8.8l.7 2h3l.7-2a7 7 0 0 0 1.8-.8l1.9.9 2.2-2.2-.9-1.9a7 7 0 0 0 .8-1.8l2-.7Z" transform="translate(2.5 0) scale(.8)"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
        'security' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 20 6v5c0 5-3.4 8.2-8 10-4.6-1.8-8-5-8-10V6l8-3Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg>',
    ];
    return $icons[$name] ?? $icons['dashboard'];
}

function admin_nav_item(string $key, string $active, string $href, string $icon, string $label, bool $external = false): void
{
    $class = $key === $active ? ' class="active"' : '';
    $target = $external ? ' target="_blank" rel="noopener"' : '';
    echo '<a' . $class . ' href="' . e($href) . '"' . $target . '><span class="v54-nav-icon v55-nav-icon" aria-hidden="true">' . admin_nav_icon($icon) . '</span><span class="v55-nav-label">' . e($label) . '</span></a>';
}

function admin_page_start(string $active, string $title, string $subtitle, string $kicker = 'FareBros Status', array $actions = []): void
{
    $pageTitle = $title . ' - ' . APP_NAME;
    $user = current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <link rel="stylesheet" href="/assets/css/status-v43.css?v=4.3.0">
    <link rel="stylesheet" href="/assets/css/admin-pro-v48.css?v=4.8.0">
    <link rel="stylesheet" href="/assets/css/admin-controls-v51.css?v=5.1.0">
    <link rel="stylesheet" href="/assets/css/admin-v52.css?v=5.3.0">
    <link rel="stylesheet" href="/assets/css/admin-v54.css?v=5.4.0">
    <link rel="stylesheet" href="/assets/css/admin-v55.css?v=5.5.4">
</head>
<body class="admin-pro v54-admin v55-admin">
<button class="v54-mobile-menu" type="button" data-admin-menu-toggle aria-label="Open navigation" aria-expanded="false">☰</button>
<div class="v54-sidebar-scrim" data-admin-menu-close></div>
<aside class="pro-sidebar v54-sidebar" id="adminSidebar">
    <div class="v54-brand">
        <div class="v54-brand-mark">FB</div>
        <div class="v55-brand-copy"><strong>FareBros Status</strong><span>Operations Center</span></div>
        <a class="v55-brand-public" href="/" target="_blank" rel="noopener" title="Open public status page" aria-label="Open public status page"><?= admin_nav_icon('external') ?></a>
    </div>

    <nav class="pro-nav v54-nav v554-nav" aria-label="Administration navigation">
        <div class="v554-nav-group">
            <div class="v554-nav-heading">Overview</div>
            <?php admin_nav_item('dashboard', $active, '/admin/dashboard.php', 'dashboard', 'Dashboard'); ?>
        </div>

        <div class="v554-nav-group">
            <div class="v554-nav-heading">Status Management</div>
            <?php admin_nav_item('websites', $active, '/admin/websites.php', 'websites', 'Websites'); ?>
            <?php admin_nav_item('operations', $active, '/admin/operations.php', 'groups', 'Groups & Dependencies'); ?>
            <?php admin_nav_item('announcements', $active, '/admin/announcements.php', 'announcements', 'Announcements'); ?>
            <?php admin_nav_item('incidents', $active, '/admin/incidents.php', 'incidents', 'Incidents'); ?>
            <?php admin_nav_item('maintenance', $active, '/admin/schedules.php', 'maintenance', 'Maintenance'); ?>
        </div>

        <div class="v554-nav-group">
            <div class="v554-nav-heading">Monitoring & Reporting</div>
            <?php admin_nav_item('monitoring', $active, '/admin/monitoring.php', 'monitoring', 'Monitor & Activity'); ?>
            <?php admin_nav_item('analytics', $active, '/admin/analytics.php', 'analytics', 'Analytics'); ?>
            <?php admin_nav_item('reports', $active, '/admin/reports.php', 'reports', 'Monthly Reports'); ?>
            <?php admin_nav_item('health', $active, '/admin/system-health.php', 'health', 'System Health'); ?>
        </div>

        <div class="v554-nav-group">
            <div class="v554-nav-heading">Communication</div>
            <?php admin_nav_item('subscribers', $active, '/admin/subscribers.php', 'subscribers', 'Subscribers'); ?>
        </div>

        <div class="v554-nav-group">
            <div class="v554-nav-heading">Administration</div>
            <?php admin_nav_item('settings', $active, '/admin/settings.php', 'settings', 'Settings'); ?>
            <?php admin_nav_item('audit', $active, '/admin/audit-log.php', 'audit', 'Audit Log'); ?>
            <?php admin_nav_item('security', $active, '/admin/change-password.php', 'security', 'Security'); ?>
        </div>
    </nav>

    <div class="v54-sidebar-footer">
        <button class="v55-command-open" type="button" data-command-open><span class="v55-footer-icon"><?= admin_nav_icon('search') ?></span><span>Quick Search</span><kbd>Ctrl K</kbd></button>
        <div class="v55-sidebar-meta">
            <a class="v55-signout" href="/admin/logout.php"><span class="v54-nav-icon v55-nav-icon"><?= admin_nav_icon('logout') ?></span><span>Sign Out</span></a>
            <span class="v55-version">v5.5.4</span>
        </div>
    </div>
</aside>

<main class="pro-main v54-main">
    <header class="pro-topbar v54-topbar">
        <div class="v54-heading">
            <div class="pro-kicker"><?= e($kicker) ?></div>
            <h1><?= e($title) ?></h1>
            <p><?= e($subtitle) ?></p>
        </div>
        <div class="v55-top-right">
            <button type="button" class="v55-command-pill" data-command-open><span>⌕</span> Search <kbd>Ctrl K</kbd></button>
            <?php if ($actions): ?>
                <div class="pro-top-actions v54-top-actions">
                    <?php foreach ($actions as $action):
                        $href = (string)($action['href'] ?? '#'); $label = (string)($action['label'] ?? 'Open'); $class = (string)($action['class'] ?? 'ghost');
                        $target = !empty($action['external']) ? ' target="_blank" rel="noopener"' : '';
                    ?><a class="button <?= e($class) ?>" href="<?= e($href) ?>"<?= $target ?>><?= e($label) ?></a><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($user): ?><div class="v55-user-chip"><span><?= e(strtoupper(substr((string)$user['display_name'], 0, 1))) ?></span><div><strong><?= e((string)$user['display_name']) ?></strong><small><?= e((string)$user['role']) ?></small></div></div><?php endif; ?>
        </div>
    </header>
<?php
}

function admin_status_tabs(string $active): void
{
    $tabs = [
        'dashboard' => ['/admin/dashboard.php', 'Overview'],
        'websites' => ['/admin/websites.php', 'Websites'],
        'operations' => ['/admin/operations.php', 'Groups'],
        'announcements' => ['/admin/announcements.php', 'Announcements'],
        'monitoring' => ['/admin/monitoring.php', 'Monitoring'],
    ];
    ?><nav class="v54-tabs" aria-label="Status management"><?php foreach ($tabs as $key => [$href, $label]): ?><a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></nav><?php
}

function admin_notice(?string $message, string $type = 'success'): void
{
    if ($message === null || trim($message) === '') return;
    echo '<div class="notice-box ' . e($type) . '">' . e($message) . '</div>';
}

function admin_command_palette(): void
{
    $commands = [
        ['Dashboard','Current operations overview','/admin/dashboard.php','Overview'],
        ['Add Website','Create a new monitored website','/admin/websites.php#add','Status'],
        ['Create Announcement','Publish a public notice','/admin/announcements.php#add','Status'],
        ['Create Incident','Open incident manager','/admin/incidents.php','Status'],
        ['Schedule Maintenance','Plan a maintenance window','/admin/schedules.php','Status'],
        ['Groups & Dependencies','Organize services and upstream relationships','/admin/operations.php','Operations'],
        ['Monitor & Activity','Run checks and inspect logs','/admin/monitoring.php','Monitoring'],
        ['Analytics','Response time and uptime analytics','/admin/analytics.php','Monitoring'],
        ['Monthly Reports','Generate monthly uptime reports','/admin/reports.php','Reports'],
        ['System Health','Check cron, backups, database and integrations','/admin/system-health.php','System'],
        ['Subscribers','Manage public email subscribers','/admin/subscribers.php','Communication'],
        ['Settings','SMTP, Discord, API, webhooks and monitoring settings','/admin/settings.php','Administration'],
        ['Audit Log','Administrative activity history','/admin/audit-log.php','Administration'],
    ];
    foreach (get_websites() as $website) $commands[] = ['Website: ' . $website['website_name'], (string)$website['website_url'], '/admin/websites.php#website-' . (int)$website['id'], 'Website'];
    foreach (get_services() as $service) $commands[] = ['Service: ' . $service['service_name'], (string)($service['description'] ?? ''), '/admin/dashboard.php#service-' . (int)$service['id'], 'Service'];
    ?>
    <div class="v55-command-backdrop" data-command-close hidden></div>
    <section class="v55-command" role="dialog" aria-modal="true" aria-label="Quick search" data-command hidden>
        <div class="v55-command-head"><span>⌕</span><input type="search" placeholder="Search pages, websites, services, or actions…" data-command-input autocomplete="off"><kbd>Esc</kbd></div>
        <div class="v55-command-results" data-command-results>
            <?php foreach ($commands as [$label,$description,$href,$group]): ?><a href="<?= e($href) ?>" data-command-item data-command-search="<?= e(strtolower($label . ' ' . $description . ' ' . $group)) ?>"><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span><em><?= e($group) ?></em></a><?php endforeach; ?>
            <div class="v55-command-empty" data-command-empty hidden>No matching command.</div>
        </div>
    </section>
    <?php
}

function admin_page_end(): void
{
    admin_command_palette();
    ?>
</main>
<script src="/assets/js/admin-v54.js?v=5.4.0"></script>
<script src="/assets/js/admin-v55.js?v=5.5.4"></script>
</body>
</html>
<?php
}
