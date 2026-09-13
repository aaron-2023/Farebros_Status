<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/functions.php';

// This is safe after installing v5.0 if an upcoming schedule already changed everything to Planned Maintenance.
// It resets service rows to Operational. Website rows should be refreshed by the monitor immediately after.

db()->exec("UPDATE services SET current_status = 'operational', updated_at = CURRENT_TIMESTAMP WHERE current_status = 'planned_maintenance'");
db()->exec("UPDATE websites SET current_status = 'operational', updated_at = CURRENT_TIMESTAMP WHERE current_status = 'planned_maintenance'");

echo "Reset planned-maintenance current statuses back to operational.\n";
echo "Now run: php /var/www/status/monitor-cron.php\n";
