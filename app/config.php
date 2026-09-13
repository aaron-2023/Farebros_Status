<?php
// Fare Brothers Status - Config

declare(strict_types=1);

define('APP_NAME', 'Fare Brothers Status');
define('APP_BRAND', 'Fare Brothers');
define('APP_TIMEZONE', 'America/Detroit');

// Change this once installed if your domain is different.
define('APP_URL', 'https://status.farebros.com');

// SQLite database location.
// Keep this OUTSIDE public web root if possible.
// In this starter package, /data is protected by .htaccess for Apache.
define('DB_PATH', __DIR__ . '/../data/status.sqlite');

// Session security
define('SESSION_NAME', 'farebros_status_admin');

// Default public refresh speed in seconds.
define('PUBLIC_REFRESH_SECONDS', 15);

// Optional Fare Bros Platform / Portal integration.
// Keep this OFF until your main portal endpoint is ready.
// The status site must never depend on the portal being online.
define('PLATFORM_SYNC_ENABLED', false);

// Example:
// define('PLATFORM_API_URL', 'https://farebros.com/api/status/receive.php');
define('PLATFORM_API_URL', '');

// Must match the key set inside the Fare Bros portal receiver.
// Generate a strong random value before enabling sync.
define('PLATFORM_API_KEY', 'change-this-long-random-secret');

// Public/widget API key for the Fare Bros portal to read this offsite status.
// This allows your portal to display status without needing database access.
// Generate a strong random value before using private endpoints.
define('STATUS_READ_API_KEY', 'change-this-read-secret');


// Automatic service monitoring.
// These checks run from the offsite status server, not from your home network.
// Use cron or the web-based monitor endpoint to call the checker.
define('MONITORING_ENABLED', true);

// Secret key required to run /api/run-monitor.php from a cron service.
define('MONITOR_RUN_KEY', 'change-this-monitor-secret');

// How often a service may be automatically updated by the monitor, in seconds.
// This prevents writing a new status update every single check when nothing changed.
define('MONITOR_MIN_UPDATE_SECONDS', 60);

// Timeout for each monitored website check.
define('MONITOR_HTTP_TIMEOUT_SECONDS', 8);

// Optional custom text that must be present on the healthy website.
// Leave blank for now unless you want a stronger check.
define('FAREBROS_HEALTHY_TEXT', '');

// Status options
const STATUS_OPTIONS = [
    'operational' => [
        'label' => 'Operational',
        'code' => 99,
        'class' => 'good'
    ],
    'planned_maintenance' => [
        'label' => 'Planned Maintenance',
        'code' => 90,
        'class' => 'maintenance'
    ],
    'maintenance' => [
        'label' => 'Maintenance in Progress',
        'code' => 80,
        'class' => 'maintenance'
    ],
    'degraded' => [
        'label' => 'Degraded Performance',
        'code' => 70,
        'class' => 'warning'
    ],
    'partial_outage' => [
        'label' => 'Partial Outage',
        'code' => 50,
        'class' => 'warning'
    ],
    'major_outage' => [
        'label' => 'Major Outage',
        'code' => 10,
        'class' => 'bad'
    ],
    'offline' => [
        'label' => 'Offline',
        'code' => 0,
        'class' => 'bad'
    ],
];

date_default_timezone_set(APP_TIMEZONE);

define('FAREBROS_PRIMARY_URL', 'https://farebros.com/');
define('PORTAL_SERVICE_LABEL', 'Fare Brothers Account Center');
