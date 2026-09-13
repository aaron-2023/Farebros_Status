<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/functions.php';

if (file_exists(__DIR__ . '/../app/scheduler.php')) {
    require_once __DIR__ . '/../app/scheduler.php';
}

echo "Repairing stuck service statuses...\n";

db()->exec("
    UPDATE services
    SET current_status = 'operational', updated_at = CURRENT_TIMESTAMP
    WHERE current_status IN ('offline', 'major_outage', 'partial_outage', 'planned_maintenance', 'maintenance')
");

db()->exec("
    UPDATE services
    SET current_status = 'operational', updated_at = CURRENT_TIMESTAMP
    WHERE TRIM(LOWER(service_name)) = 'status page'
");

echo "Services reset to operational.\n";

if (function_exists('apply_scheduled_jobs')) {
    $actions = apply_scheduled_jobs();
    echo "Scheduler check actions: " . (empty($actions) ? 'none' : implode(', ', $actions)) . "\n";
}

echo "\nCurrent services:\n";
foreach (get_services() as $s) {
    echo "- {$s['service_name']}: {$s['current_status']}\n";
}

echo "\nCurrent websites:\n";
foreach (get_websites() as $w) {
    echo "- {$w['website_name']}: {$w['current_status']} | primary=" . ((int)$w['is_primary'] === 1 ? 'yes' : 'no') . "\n";
}

echo "\nNow run: php /var/www/status/monitor-cron.php\n";
