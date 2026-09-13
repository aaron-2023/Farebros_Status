<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';

    if (!$posted || !$session || !hash_equals($session, $posted)) {
        http_response_code(419);
        exit('Security check failed. Please go back and try again.');
    }
}

function status_meta(string $status): array
{
    return STATUS_OPTIONS[$status] ?? STATUS_OPTIONS['offline'];
}

function normalize_url(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function ensure_core_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = db();

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_name TEXT NOT NULL,
            description TEXT,
            current_status TEXT NOT NULL DEFAULT "operational",
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS websites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            website_name TEXT NOT NULL,
            website_url TEXT NOT NULL,
            description TEXT,
            current_status TEXT NOT NULL DEFAULT "operational",
            is_primary INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            last_checked_at TEXT,
            last_http_code INTEGER,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS status_updates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_id INTEGER,
            old_status TEXT,
            new_status TEXT NOT NULL,
            update_title TEXT NOT NULL,
            update_message TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    seed_default_services();
    seed_default_websites();
    seed_default_settings();

    $done = true;
}

function seed_default_services(): void
{
    $pdo = db();

    $defaults = [
        ['Fare Brothers Core', 'Primary Fare Brothers web presence and public brand services.', 'operational', 10],
        ['Status Page', 'Offsite public status website and incident communication.', 'operational', 20],
        [defined('PORTAL_SERVICE_LABEL') ? PORTAL_SERVICE_LABEL : 'Fare Brothers Account Center', 'Login, account access, support tools, and customer-facing systems.', 'operational', 30],
        ['Network / Power Infrastructure', 'Power, internet, hosting, tunnels, and infrastructure dependencies.', 'operational', 40],
    ];

    foreach ($defaults as $service) {
        $stmt = $pdo->prepare('SELECT id FROM services WHERE service_name = ? LIMIT 1');
        $stmt->execute([$service[0]]);
        if (!$stmt->fetch()) {
            $insert = $pdo->prepare('
                INSERT INTO services (service_name, description, current_status, sort_order)
                VALUES (?, ?, ?, ?)
            ');
            $insert->execute($service);
        }
    }

    $pdo->prepare('DELETE FROM services WHERE service_name = ?')->execute(['Game Servers']);
}

function seed_default_websites(): void
{
    $pdo = db();

    $count = (int)$pdo->query('SELECT COUNT(*) FROM websites')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO websites
        (website_name, website_url, description, current_status, is_primary, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        'Fare Brothers',
        defined('FAREBROS_PRIMARY_URL') ? FAREBROS_PRIMARY_URL : 'https://farebros.com/',
        'Main Fare Brothers website.',
        'operational',
        1,
        10
    ]);
}

function seed_default_settings(): void
{
    $defaults = [
        'status_message' => 'All systems are currently operational.',
        'public_banner' => '',
        'site_timezone' => 'America/Detroit',
        'time_format' => '12h',
        'date_format' => 'M j, Y g:i A',
        'support_email' => 'support@farebros.com',
        'public_refresh_seconds' => (string)(defined('PUBLIC_REFRESH_SECONDS') ? PUBLIC_REFRESH_SECONDS : 15),
        'company_name' => 'Fare Brothers, LLC',
        'public_admin_link' => '1',
        'status_page_label' => 'Status Center',
    ];

    foreach ($defaults as $key => $value) {
        $stmt = db()->prepare('
            INSERT OR IGNORE INTO settings (setting_key, setting_value)
            VALUES (?, ?)
        ');
        $stmt->execute([$key, $value]);
    }
}

function get_setting(string $key, ?string $default = null): ?string
{
    ensure_core_schema();

    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return $row ? $row['setting_value'] : $default;
}

function set_setting(string $key, string $value): void
{
    ensure_core_schema();

    $stmt = db()->prepare('
        INSERT INTO settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(setting_key)
        DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$key, $value]);
}

function valid_timezone_or_default(?string $tz): string
{
    $tz = trim((string)$tz);

    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        return $tz;
    }

    return 'America/Detroit';
}

function site_timezone(): string
{
    return valid_timezone_or_default(get_setting('site_timezone', 'America/Detroit'));
}

function site_datetime_zone(): DateTimeZone
{
    return new DateTimeZone(site_timezone());
}

function site_time_format(): string
{
    $format = get_setting('time_format', '12h');
    return $format === '24h' ? '24h' : '12h';
}

function site_date_format(): string
{
    $stored = get_setting('date_format', '');
    if ($stored) {
        return $stored;
    }

    return site_time_format() === '24h' ? 'M j, Y H:i' : 'M j, Y g:i A';
}

function site_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', site_datetime_zone());
}

function db_datetime_from_local(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $dt = new DateTimeImmutable($value, site_datetime_zone());
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function local_datetime_value(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $ex) {
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Throwable $ex2) {
            return '';
        }
    }

    return $dt->setTimezone(site_datetime_zone())->format('Y-m-d\TH:i');
}

function format_dt(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } else {
            $dt = new DateTimeImmutable($value);
        }

        return $dt->setTimezone(site_datetime_zone())->format(site_date_format());
    } catch (Throwable $ex) {
        return e($value);
    }
}

function iso_dt(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } else {
            $dt = new DateTimeImmutable($value);
        }

        return $dt->setTimezone(site_datetime_zone())->format(DateTimeInterface::ATOM);
    } catch (Throwable $ex) {
        return null;
    }
}

function get_services(): array
{
    ensure_core_schema();

    $stmt = db()->query('SELECT * FROM services ORDER BY sort_order ASC, service_name ASC');
    return $stmt->fetchAll();
}

function get_websites(bool $includeInactive = true): array
{
    ensure_core_schema();

    $stmt = db()->query('SELECT * FROM websites ORDER BY is_primary DESC, sort_order ASC, website_name ASC');
    return $stmt->fetchAll();
}

function get_primary_website(): ?array
{
    ensure_core_schema();

    $stmt = db()->query('SELECT * FROM websites WHERE is_primary = 1 ORDER BY sort_order ASC LIMIT 1');
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    $stmt = db()->query('SELECT * FROM websites ORDER BY sort_order ASC LIMIT 1');
    $row = $stmt->fetch();

    return $row ?: null;
}

function add_website(string $name, string $url, string $description = '', bool $isPrimary = false): void
{
    ensure_core_schema();

    $url = normalize_url($url);
    if ($name === '' || $url === '') {
        return;
    }

    if ($isPrimary) {
        db()->exec('UPDATE websites SET is_primary = 0');
    }

    $stmt = db()->prepare('
        INSERT INTO websites
        (website_name, website_url, description, current_status, is_primary, sort_order)
        VALUES (?, ?, ?, "operational", ?, ?)
    ');
    $stmt->execute([$name, $url, $description, $isPrimary ? 1 : 0, 100]);
}

function update_website(int $id, string $name, string $url, string $description, string $status, bool $isPrimary, int $sortOrder): void
{
    ensure_core_schema();

    if (!isset(STATUS_OPTIONS[$status])) {
        $status = 'offline';
    }

    if ($isPrimary) {
        db()->exec('UPDATE websites SET is_primary = 0');
    }

    $stmt = db()->prepare('
        UPDATE websites
        SET website_name = ?, website_url = ?, description = ?, current_status = ?, is_primary = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $stmt->execute([
        trim($name),
        normalize_url($url),
        trim($description),
        $status,
        $isPrimary ? 1 : 0,
        $sortOrder,
        $id
    ]);
}

function delete_website(int $id): void
{
    ensure_core_schema();

    $stmt = db()->prepare('DELETE FROM websites WHERE id = ?');
    $stmt->execute([$id]);

    $primary = get_primary_website();
    if (!$primary) {
        return;
    }

    $stmt = db()->prepare('UPDATE websites SET is_primary = 1 WHERE id = ?');
    $stmt->execute([(int)$primary['id']]);
}

function get_active_announcements(): array
{
    ensure_core_schema();

    $stmt = db()->query('
        SELECT *
        FROM announcements
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 5
    ');
    return $stmt->fetchAll();
}

function get_recent_updates(int $limit = 20): array
{
    ensure_core_schema();

    $stmt = db()->prepare('
        SELECT su.*, s.service_name
        FROM status_updates su
        LEFT JOIN services s ON s.id = su.service_id
        ORDER BY su.created_at DESC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function status_score(string $status): int
{
    $priority = [
        'offline' => 7,
        'major_outage' => 6,
        'partial_outage' => 5,
        'degraded' => 4,
        'maintenance' => 3,
        'planned_maintenance' => 2,
        'operational' => 1,
    ];

    return $priority[$status] ?? 7;
}

function most_severe_status(array $statuses, string $fallback = 'operational'): string
{
    $highest = 0;
    $selected = $fallback;

    foreach ($statuses as $status) {
        $score = status_score($status);
        if ($score > $highest) {
            $highest = $score;
            $selected = $status;
        }
    }

    return $selected;
}

function is_core_service_name(string $name): bool
{
    $name = strtolower(trim($name));

    return str_contains($name, 'fare brothers core')
        || str_contains($name, 'network')
        || str_contains($name, 'power infrastructure')
        || str_contains($name, 'account center')
        || str_contains($name, 'status page');
}

function get_overall_status(array $services, array $websites = []): string
{
    $coreStatuses = [];
    $secondaryWebsiteIssues = [];

    foreach ($services as $service) {
        $status = $service['current_status'] ?? 'offline';
        if ($status === 'operational') {
            continue;
        }

        // Services are treated as core by default. If a service is down, it matters.
        $coreStatuses[] = $status;
    }

    foreach ($websites as $website) {
        $status = $website['current_status'] ?? 'offline';
        $isPrimary = (int)($website['is_primary'] ?? 0) === 1;

        if ($status === 'operational') {
            continue;
        }

        if ($isPrimary) {
            $coreStatuses[] = $status;
        } else {
            $secondaryWebsiteIssues[] = $status;
        }
    }

    if ($coreStatuses) {
        return most_severe_status($coreStatuses);
    }

    if ($secondaryWebsiteIssues) {
        // Secondary/add-on website outages should not turn the whole page into "Offline".
        // They are visible in the Websites table, while the overall headline becomes Partial Outage.
        return 'partial_outage';
    }

    return 'operational';
}

function public_status_payload(): array
{
    ensure_core_schema();

    $services = get_services();
    $websites = get_websites();
    $primaryWebsite = get_primary_website();

    $overall = get_overall_status($services, $websites);
    $overallMeta = status_meta($overall);

    $primaryStatus = $primaryWebsite['current_status'] ?? $overall;
    $primaryMeta = status_meta($primaryStatus);

    $refreshSeconds = (int)get_setting('public_refresh_seconds', (string)(defined('PUBLIC_REFRESH_SECONDS') ? PUBLIC_REFRESH_SECONDS : 15));
    if ($refreshSeconds < 5) {
        $refreshSeconds = 5;
    }

    return [
        'app' => APP_NAME,
        'brand' => APP_BRAND,
        'generated_at' => site_now()->format(DateTimeInterface::ATOM),
        'timezone' => site_timezone(),
        'timezone_label' => site_now()->format('T'),
        'overall' => [
            'status' => $overall,
            'label' => $overallMeta['label'],
            'code' => $overallMeta['code'],
            'class' => $overallMeta['class'],
        ],
        'primary' => [
            'name' => $primaryWebsite['website_name'] ?? 'Fare Brothers',
            'url' => $primaryWebsite['website_url'] ?? (defined('FAREBROS_PRIMARY_URL') ? FAREBROS_PRIMARY_URL : 'https://farebros.com/'),
            'status' => $primaryStatus,
            'label' => $primaryMeta['label'],
            'code' => $primaryMeta['code'],
            'class' => $primaryMeta['class'],
            'is_online' => $primaryStatus === 'operational',
        ],
        'settings' => [
            'company_name' => get_setting('company_name', 'Fare Brothers, LLC'),
            'support_email' => get_setting('support_email', 'support@farebros.com'),
            'public_admin_link' => get_setting('public_admin_link', '1') === '1',
            'status_page_label' => get_setting('status_page_label', 'Status Center'),
            'time_format' => site_time_format(),
        ],
        'message' => get_setting('status_message', 'All systems are currently operational.'),
        'services' => array_map(function ($service) {
            $meta = status_meta($service['current_status']);

            return [
                'id' => (int)$service['id'],
                'name' => $service['service_name'],
                'description' => $service['description'],
                'status' => $service['current_status'],
                'label' => $meta['label'],
                'code' => $meta['code'],
                'class' => $meta['class'],
                'updated_at' => $service['updated_at'],
                'updated_at_iso' => iso_dt($service['updated_at']),
            ];
        }, $services),
        'websites' => array_map(function ($website) {
            $meta = status_meta($website['current_status']);

            return [
                'id' => (int)$website['id'],
                'name' => $website['website_name'],
                'url' => $website['website_url'],
                'description' => $website['description'],
                'status' => $website['current_status'],
                'label' => $meta['label'],
                'code' => $meta['code'],
                'class' => $meta['class'],
                'is_primary' => (int)$website['is_primary'] === 1,
                'updated_at' => $website['updated_at'],
                'updated_at_iso' => iso_dt($website['updated_at']),
                'last_checked_at' => $website['last_checked_at'],
                'last_checked_at_iso' => iso_dt($website['last_checked_at']),
                'last_http_code' => $website['last_http_code'],
            ];
        }, $websites),
        'announcements' => get_active_announcements(),
        'recent_updates' => get_recent_updates(10),
        'refresh_seconds' => $refreshSeconds,
    ];
}
