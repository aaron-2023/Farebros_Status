<?php
declare(strict_types=1);

require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/notifications.php';

/**
 * FareBros Status v5.5 platform services.
 * Groups/dependencies, subscriptions, reports, dashboard preferences,
 * maintenance templates, outbound webhooks, and platform self-health.
 */
function ensure_platform_schema(): void
{
    static $done = false;
    if ($done) return;

    ensure_core_schema();
    ensure_monitor_schema();
    ensure_incident_schema();
    ensure_scheduler_schema();
    ensure_notification_schema();

    $pdo = db();

    $pdo->exec('CREATE TABLE IF NOT EXISTS status_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_name TEXT NOT NULL,
        description TEXT,
        sort_order INTEGER NOT NULL DEFAULT 100,
        is_public INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS status_group_members (
        group_id INTEGER NOT NULL,
        target_type TEXT NOT NULL,
        target_id INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 100,
        PRIMARY KEY (group_id, target_type, target_id),
        FOREIGN KEY (group_id) REFERENCES status_groups(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_status_group_members_target ON status_group_members(target_type, target_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS status_dependencies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target_type TEXT NOT NULL,
        target_id INTEGER NOT NULL,
        upstream_type TEXT NOT NULL,
        upstream_id INTEGER NOT NULL,
        behavior TEXT NOT NULL DEFAULT "degraded",
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(target_type, target_id, upstream_type, upstream_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dependencies_target ON status_dependencies(target_type, target_id, is_active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dependencies_upstream ON status_dependencies(upstream_type, upstream_id, is_active)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS status_subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        confirm_token TEXT NOT NULL,
        unsubscribe_token TEXT NOT NULL,
        is_verified INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        verified_at TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS status_subscriber_preferences (
        subscriber_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        target_type TEXT NOT NULL DEFAULT "all",
        target_id INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (subscriber_id, event_type, target_type, target_id),
        FOREIGN KEY (subscriber_id) REFERENCES status_subscribers(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscribers_active ON status_subscribers(is_active, is_verified)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS monthly_uptime_reports (
        report_month TEXT NOT NULL,
        website_id INTEGER NOT NULL,
        website_name TEXT NOT NULL,
        total_checks INTEGER NOT NULL DEFAULT 0,
        successful_checks INTEGER NOT NULL DEFAULT 0,
        degraded_checks INTEGER NOT NULL DEFAULT 0,
        failed_checks INTEGER NOT NULL DEFAULT 0,
        uptime_percent REAL,
        avg_response_ms INTEGER,
        min_response_ms INTEGER,
        max_response_ms INTEGER,
        outage_events INTEGER NOT NULL DEFAULT 0,
        incident_count INTEGER NOT NULL DEFAULT 0,
        generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (report_month, website_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monthly_reports_month ON monthly_uptime_reports(report_month DESC)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_preferences (
        user_id INTEGER NOT NULL,
        preference_key TEXT NOT NULL,
        preference_value TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, preference_key)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS maintenance_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_name TEXT NOT NULL,
        title TEXT NOT NULL,
        details TEXT,
        scope TEXT NOT NULL DEFAULT "all",
        target_type TEXT,
        target_id INTEGER,
        duration_minutes INTEGER NOT NULL DEFAULT 60,
        active_status TEXT NOT NULL DEFAULT "maintenance",
        after_status TEXT NOT NULL DEFAULT "operational",
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS outbound_webhook_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT,
        destination TEXT,
        http_code INTEGER,
        success INTEGER NOT NULL DEFAULT 0,
        response TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_outbound_webhook_log_created ON outbound_webhook_log(created_at DESC)');

    $defaults = [
        'public_subscriptions_enabled' => '1',
        'subscriber_from_name' => 'Fare Brothers Status',
        'outbound_webhook_enabled' => '0',
        'outbound_webhook_url' => '',
        'outbound_webhook_secret' => '',
        'public_api_enabled' => '1',
        'public_api_allow_history' => '1',
        'uptime_sla_target' => '99.900',
        'dashboard_customization_enabled' => '1',
        'last_monitor_cron_at' => '',
        'last_monitor_cron_result' => '',
        'last_smtp_test_at' => '',
        'last_smtp_test_ok' => '',
        'last_discord_test_at' => '',
        'last_discord_test_ok' => '',
        'last_monthly_report_at' => '',
    ];
    foreach ($defaults as $key => $value) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }

    $done = true;
}

function valid_target_type(string $type): bool
{
    return in_array($type, ['service', 'website'], true);
}

function status_target(string $type, int $id): ?array
{
    ensure_platform_schema();
    if (!valid_target_type($type) || $id < 1) return null;
    if ($type === 'service') {
        $stmt = db()->prepare('SELECT id, service_name AS name, description, current_status AS status FROM services WHERE id = ? LIMIT 1');
    } else {
        $stmt = db()->prepare('SELECT id, website_name AS name, description, current_status AS status FROM websites WHERE id = ? LIMIT 1');
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['type'] = $type;
    return $row;
}

function target_label(string $type, int $id): string
{
    $target = status_target($type, $id);
    return $target ? (string)$target['name'] : ucfirst($type) . ' #' . $id;
}

function get_status_groups(bool $publicOnly = false): array
{
    ensure_platform_schema();
    $sql = 'SELECT * FROM status_groups' . ($publicOnly ? ' WHERE is_public = 1' : '') . ' ORDER BY sort_order, group_name';
    $groups = db()->query($sql)->fetchAll();
    foreach ($groups as &$group) {
        $group['members'] = get_status_group_members((int)$group['id']);
        $statuses = array_map(static fn(array $m): string => (string)$m['status'], $group['members']);
        $group['status'] = $statuses ? most_severe_status($statuses) : 'operational';
        $group['status_meta'] = status_meta((string)$group['status']);
    }
    unset($group);
    return $groups;
}

function get_status_group_members(int $groupId): array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT gm.*,
        CASE WHEN gm.target_type = "service" THEN s.service_name ELSE w.website_name END AS name,
        CASE WHEN gm.target_type = "service" THEN s.description ELSE w.description END AS description,
        CASE WHEN gm.target_type = "service" THEN s.current_status ELSE w.current_status END AS status
        FROM status_group_members gm
        LEFT JOIN services s ON gm.target_type = "service" AND s.id = gm.target_id
        LEFT JOIN websites w ON gm.target_type = "website" AND w.id = gm.target_id
        WHERE gm.group_id = ?
        ORDER BY gm.sort_order, name');
    $stmt->execute([$groupId]);
    return array_values(array_filter($stmt->fetchAll(), static fn(array $r): bool => !empty($r['name'])));
}

function save_status_group(int $id, string $name, string $description, int $sortOrder, bool $isPublic): int
{
    ensure_platform_schema();
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Group name is required.');
    if ($id > 0) {
        db()->prepare('UPDATE status_groups SET group_name = ?, description = ?, sort_order = ?, is_public = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$name, trim($description), $sortOrder, $isPublic ? 1 : 0, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO status_groups (group_name, description, sort_order, is_public) VALUES (?, ?, ?, ?)')
        ->execute([$name, trim($description), $sortOrder, $isPublic ? 1 : 0]);
    return (int)db()->lastInsertId();
}

function delete_status_group(int $id): void
{
    ensure_platform_schema();
    db()->prepare('DELETE FROM status_groups WHERE id = ?')->execute([$id]);
}

function add_status_group_member(int $groupId, string $type, int $targetId): void
{
    ensure_platform_schema();
    if (!status_target($type, $targetId)) throw new RuntimeException('The selected group member no longer exists.');
    db()->prepare('INSERT OR IGNORE INTO status_group_members (group_id, target_type, target_id, sort_order) VALUES (?, ?, ?, 100)')
        ->execute([$groupId, $type, $targetId]);
}

function remove_status_group_member(int $groupId, string $type, int $targetId): void
{
    ensure_platform_schema();
    db()->prepare('DELETE FROM status_group_members WHERE group_id = ? AND target_type = ? AND target_id = ?')
        ->execute([$groupId, $type, $targetId]);
}

function get_status_dependencies(): array
{
    ensure_platform_schema();
    $rows = db()->query('SELECT * FROM status_dependencies ORDER BY id DESC')->fetchAll();
    foreach ($rows as &$row) {
        $row['target_name'] = target_label((string)$row['target_type'], (int)$row['target_id']);
        $row['upstream_name'] = target_label((string)$row['upstream_type'], (int)$row['upstream_id']);
    }
    unset($row);
    return $rows;
}

function dependency_key(string $type, int $id): string
{
    return $type . ':' . $id;
}

function dependency_would_cycle(string $targetType, int $targetId, string $upstreamType, int $upstreamId): bool
{
    ensure_platform_schema();
    $targetKey = dependency_key($targetType, $targetId);
    $start = dependency_key($upstreamType, $upstreamId);
    if ($targetKey === $start) return true;

    $rows = db()->query('SELECT target_type, target_id, upstream_type, upstream_id FROM status_dependencies WHERE is_active = 1')->fetchAll();
    // Edge target -> upstream. Starting from proposed upstream, follow its upstream dependencies.
    $graph = [];
    foreach ($rows as $row) {
        $graph[dependency_key((string)$row['target_type'], (int)$row['target_id'])][] = dependency_key((string)$row['upstream_type'], (int)$row['upstream_id']);
    }
    $queue = [$start];
    $seen = [];
    while ($queue) {
        $node = array_shift($queue);
        if ($node === $targetKey) return true;
        if (isset($seen[$node])) continue;
        $seen[$node] = true;
        foreach ($graph[$node] ?? [] as $next) $queue[] = $next;
    }
    return false;
}

function add_status_dependency(string $targetType, int $targetId, string $upstreamType, int $upstreamId, string $behavior): int
{
    ensure_platform_schema();
    if (!status_target($targetType, $targetId) || !status_target($upstreamType, $upstreamId)) {
        throw new RuntimeException('Both dependency targets must exist.');
    }
    if (dependency_would_cycle($targetType, $targetId, $upstreamType, $upstreamId)) {
        throw new RuntimeException('That dependency would create a circular dependency.');
    }
    $behavior = in_array($behavior, ['degraded', 'inherit'], true) ? $behavior : 'degraded';
    $stmt = db()->prepare('INSERT OR IGNORE INTO status_dependencies (target_type, target_id, upstream_type, upstream_id, behavior, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $stmt->execute([$targetType, $targetId, $upstreamType, $upstreamId, $behavior]);
    return (int)db()->lastInsertId();
}

function delete_status_dependency(int $id): void
{
    ensure_platform_schema();
    db()->prepare('DELETE FROM status_dependencies WHERE id = ?')->execute([$id]);
}

function dependency_effective_statuses(array $services, array $websites): array
{
    ensure_platform_schema();
    $actual = [];
    foreach ($services as $row) $actual[dependency_key('service', (int)$row['id'])] = (string)($row['current_status'] ?? $row['status'] ?? 'offline');
    foreach ($websites as $row) $actual[dependency_key('website', (int)$row['id'])] = (string)($row['current_status'] ?? $row['status'] ?? 'offline');
    $effective = $actual;
    $deps = db()->query('SELECT * FROM status_dependencies WHERE is_active = 1')->fetchAll();

    // Resolve repeatedly to propagate chained dependencies. Cycles are prevented on insert.
    for ($pass = 0; $pass < max(1, count($deps) + 1); $pass++) {
        $changed = false;
        foreach ($deps as $dep) {
            $target = dependency_key((string)$dep['target_type'], (int)$dep['target_id']);
            $upstream = dependency_key((string)$dep['upstream_type'], (int)$dep['upstream_id']);
            if (!isset($effective[$target], $effective[$upstream])) continue;
            // Planned/active maintenance is authoritative; an upstream dependency should not
            // turn an intentionally maintained component into an unrelated degraded state.
            if (in_array($effective[$target], ['maintenance', 'planned_maintenance'], true)) continue;
            $upStatus = $effective[$upstream];
            if ($upStatus === 'operational') continue;
            $candidate = (string)$dep['behavior'] === 'inherit' ? $upStatus : 'degraded';
            if (status_score($candidate) > status_score($effective[$target])) {
                $effective[$target] = $candidate;
                $changed = true;
            }
        }
        if (!$changed) break;
    }
    return $effective;
}

function public_incident_view(array $incident): array
{
    unset($incident['created_by']);
    if (isset($incident['updates']) && is_array($incident['updates'])) {
        foreach ($incident['updates'] as &$update) unset($update['created_by']);
        unset($update);
    }
    return $incident;
}

function platform_enrich_public_payload(array $payload): array
{
    ensure_platform_schema();
    $servicesRaw = get_services();
    $websitesRaw = get_websites();
    $effective = dependency_effective_statuses($servicesRaw, $websitesRaw);

    foreach ($payload['services'] as &$service) {
        $key = dependency_key('service', (int)$service['id']);
        $actual = (string)$service['status'];
        $eff = $effective[$key] ?? $actual;
        $service['actual_status'] = $actual;
        $service['effective_status'] = $eff;
        $service['dependency_affected'] = $eff !== $actual;
        if ($eff !== $actual) {
            $meta = status_meta($eff);
            $service['status'] = $eff; $service['label'] = $meta['label']; $service['code'] = $meta['code']; $service['class'] = $meta['class'];
        }
    }
    unset($service);
    foreach ($payload['websites'] as &$website) {
        $key = dependency_key('website', (int)$website['id']);
        $actual = (string)$website['status'];
        $eff = $effective[$key] ?? $actual;
        $website['actual_status'] = $actual;
        $website['effective_status'] = $eff;
        $website['dependency_affected'] = $eff !== $actual;
        if ($eff !== $actual) {
            $meta = status_meta($eff);
            $website['status'] = $eff; $website['label'] = $meta['label']; $website['code'] = $meta['code']; $website['class'] = $meta['class'];
        }
    }
    unset($website);
    foreach ($payload['websites'] as $website) {
        if (!empty($website['is_primary'])) {
            $payload['primary']['status'] = $website['status'];
            $payload['primary']['label'] = $website['label'];
            $payload['primary']['code'] = $website['code'];
            $payload['primary']['class'] = $website['class'];
            $payload['primary']['is_online'] = $website['status'] === 'operational';
            break;
        }
    }

    $effectiveServices = array_map(static fn(array $s): array => ['current_status' => $s['status']], $payload['services']);
    $effectiveWebsites = array_map(static fn(array $w): array => ['current_status' => $w['status'], 'is_primary' => $w['is_primary'] ? 1 : 0], $payload['websites']);
    $overallStatus = get_overall_status($effectiveServices, $effectiveWebsites);
    // Incidents may already have escalated overall beyond computed dependency status.
    if (status_score($overallStatus) > status_score((string)($payload['overall']['status'] ?? 'operational'))) {
        $meta = status_meta($overallStatus);
        $payload['overall'] = ['status' => $overallStatus, 'label' => $meta['label'], 'code' => $meta['code'], 'class' => $meta['class']];
    }

    $groups = get_status_groups(true);
    foreach ($groups as &$group) {
        foreach ($group['members'] as &$member) {
            $key = dependency_key((string)$member['target_type'], (int)$member['target_id']);
            $member['actual_status'] = (string)$member['status'];
            $member['status'] = $effective[$key] ?? (string)$member['status'];
            $member['status_meta'] = status_meta((string)$member['status']);
            $member['dependency_affected'] = $member['status'] !== $member['actual_status'];
        }
        unset($member);
        $groupStatuses = array_map(static fn(array $m): string => (string)$m['status'], $group['members']);
        $group['status'] = $groupStatuses ? most_severe_status($groupStatuses) : 'operational';
        $group['status_meta'] = status_meta((string)$group['status']);
    }
    unset($group);
    $payload['groups'] = $groups;
    $payload['dependencies'] = array_map(static function (array $d): array {
        return [
            'id' => (int)$d['id'],
            'target_type' => $d['target_type'], 'target_id' => (int)$d['target_id'], 'target_name' => $d['target_name'],
            'upstream_type' => $d['upstream_type'], 'upstream_id' => (int)$d['upstream_id'], 'upstream_name' => $d['upstream_name'],
            'behavior' => $d['behavior'],
        ];
    }, array_values(array_filter(get_status_dependencies(), static fn(array $d): bool => (int)$d['is_active'] === 1)));
    if (!empty($payload['incidents']['active'])) $payload['incidents']['active'] = array_map('public_incident_view', $payload['incidents']['active']);
    if (!empty($payload['incidents']['recent'])) $payload['incidents']['recent'] = array_map('public_incident_view', $payload['incidents']['recent']);
    return $payload;
}

function normalize_subscription_event(string $event): string
{
    return in_array($event, ['incidents', 'maintenance', 'status'], true) ? $event : 'incidents';
}

function subscription_event_for_notification(string $eventType): string
{
    if (str_starts_with($eventType, 'incident_')) return 'incidents';
    if (str_starts_with($eventType, 'scheduled_maintenance_')) return 'maintenance';
    return 'status';
}

function create_or_update_subscription(string $email, array $events, array $websiteIds = []): array
{
    ensure_platform_schema();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
    $events = array_values(array_unique(array_map('normalize_subscription_event', $events ?: ['incidents'])));
    $websiteIds = array_values(array_unique(array_filter(array_map('intval', $websiteIds), static fn(int $id): bool => $id > 0)));

    $stmt = db()->prepare('SELECT * FROM status_subscribers WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    $confirmToken = bin2hex(random_bytes(24));
    $unsubscribeToken = $existing['unsubscribe_token'] ?? bin2hex(random_bytes(24));
    $verified = $existing ? (int)$existing['is_verified'] : 0;

    if ($existing) {
        $id = (int)$existing['id'];
        db()->prepare('UPDATE status_subscribers SET confirm_token = ?, unsubscribe_token = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$confirmToken, $unsubscribeToken, $id]);
    } else {
        db()->prepare('INSERT INTO status_subscribers (email, confirm_token, unsubscribe_token, is_verified, is_active) VALUES (?, ?, ?, 0, 1)')
            ->execute([$email, $confirmToken, $unsubscribeToken]);
        $id = (int)db()->lastInsertId();
    }

    db()->prepare('DELETE FROM status_subscriber_preferences WHERE subscriber_id = ?')->execute([$id]);
    $pref = db()->prepare('INSERT INTO status_subscriber_preferences (subscriber_id, event_type, target_type, target_id) VALUES (?, ?, ?, ?)');
    foreach ($events as $event) {
        if ($websiteIds) {
            foreach ($websiteIds as $websiteId) $pref->execute([$id, $event, 'website', $websiteId]);
        } else {
            $pref->execute([$id, $event, 'all', 0]);
        }
    }

    return ['id' => $id, 'email' => $email, 'confirm_token' => $confirmToken, 'unsubscribe_token' => $unsubscribeToken, 'already_verified' => $verified === 1];
}

function send_subscription_confirmation(array $subscription): void
{
    if (get_setting('smtp_enabled', '0') !== '1') throw new RuntimeException('Email subscriptions are unavailable until SMTP is configured.');
    $url = rtrim(APP_URL, '/') . '/subscribe.php?action=confirm&token=' . rawurlencode((string)$subscription['confirm_token']);
    $body = "Confirm your Fare Brothers Status subscription:\n\n" . $url . "\n\nIf you did not request this, you can ignore this email.";
    smtp_send((string)$subscription['email'], 'Confirm your Fare Brothers Status subscription', $body);
}

function confirm_subscription(string $token): ?array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT * FROM status_subscribers WHERE confirm_token = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([trim($token)]);
    $row = $stmt->fetch();
    if (!$row) return null;
    db()->prepare('UPDATE status_subscribers SET is_verified = 1, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([(int)$row['id']]);
    $row['is_verified'] = 1;
    return $row;
}

function unsubscribe_status_email(string $token): ?array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT * FROM status_subscribers WHERE unsubscribe_token = ? LIMIT 1');
    $stmt->execute([trim($token)]);
    $row = $stmt->fetch();
    if (!$row) return null;
    db()->prepare('UPDATE status_subscribers SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$row['id']]);
    return $row;
}

function get_status_subscribers(int $limit = 500): array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT s.*,
        (SELECT COUNT(*) FROM status_subscriber_preferences p WHERE p.subscriber_id = s.id) AS preference_count
        FROM status_subscribers s ORDER BY s.created_at DESC LIMIT ?');
    $stmt->bindValue(1, max(1, min(2000, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function subscriber_preferences(int $subscriberId): array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT * FROM status_subscriber_preferences WHERE subscriber_id = ? ORDER BY event_type, target_type, target_id');
    $stmt->execute([$subscriberId]);
    return $stmt->fetchAll();
}

function delete_status_subscriber(int $id): void
{
    ensure_platform_schema();
    db()->prepare('DELETE FROM status_subscribers WHERE id = ?')->execute([$id]);
}

function dispatch_subscriber_notifications(string $subject, string $message, string $eventType, array $context = []): array
{
    ensure_platform_schema();
    if (get_setting('public_subscriptions_enabled', '1') !== '1' || get_setting('smtp_enabled', '0') !== '1') return [];
    $category = subscription_event_for_notification($eventType);
    $targetType = (string)($context['target_type'] ?? '');
    $targetId = (int)($context['target_id'] ?? 0);
    $contextTargets = [];
    $contextScope = (string)($context['scope'] ?? '');
    foreach ((array)($context['targets'] ?? []) as $target) {
        $type = (string)($target['type'] ?? $target['target_type'] ?? '');
        $id = (int)($target['id'] ?? $target['target_id'] ?? 0);
        if (valid_target_type($type) && $id > 0) $contextTargets[dependency_key($type,$id)] = true;
    }
    if ($targetType !== '' && $targetId > 0) $contextTargets[dependency_key($targetType,$targetId)] = true;
    $rows = db()->query('SELECT * FROM status_subscribers WHERE is_verified = 1 AND is_active = 1 ORDER BY id')->fetchAll();
    $results = [];

    foreach ($rows as $subscriber) {
        $prefs = subscriber_preferences((int)$subscriber['id']);
        $matched = false;
        foreach ($prefs as $pref) {
            if ((string)$pref['event_type'] !== $category) continue;
            if ((string)$pref['target_type'] === 'all') { $matched = true; break; }
            if (!$contextTargets) {
                // Scope-aware broad events: "all" affects everybody; a websites-only
                // maintenance window affects all website-specific subscriptions but not
                // unrelated service-only subscriptions.
                if ($contextScope === '' || $contextScope === 'all') { $matched = true; break; }
                if ($contextScope === 'websites' && (string)$pref['target_type'] === 'website') { $matched = true; break; }
                if ($contextScope === 'services' && (string)$pref['target_type'] === 'service') { $matched = true; break; }
            }
            $prefKey = dependency_key((string)$pref['target_type'], (int)$pref['target_id']);
            if (isset($contextTargets[$prefKey])) { $matched = true; break; }
        }
        if (!$matched) continue;
        $unsubscribe = rtrim(APP_URL, '/') . '/subscribe.php?action=unsubscribe&token=' . rawurlencode((string)$subscriber['unsubscribe_token']);
        $body = $message . "\n\nStatus page: " . APP_URL . "\nManage subscription: " . $unsubscribe;
        try {
            smtp_send((string)$subscriber['email'], $subject, $body);
            notification_log_write('subscriber_email', $eventType, $subject, (string)$subscriber['email'], true, 'Subscriber email accepted by SMTP server.');
            $results[] = ['email' => $subscriber['email'], 'ok' => true];
        } catch (Throwable $ex) {
            notification_log_write('subscriber_email', $eventType, $subject, (string)$subscriber['email'], false, $ex->getMessage());
            $results[] = ['email' => $subscriber['email'], 'ok' => false, 'error' => $ex->getMessage()];
        }
    }
    return $results;
}

function get_admin_preference(int $userId, string $key, string $default = ''): string
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT preference_value FROM admin_preferences WHERE user_id = ? AND preference_key = ? LIMIT 1');
    $stmt->execute([$userId, $key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['preference_value'] : $default;
}

function set_admin_preference(int $userId, string $key, string $value): void
{
    ensure_platform_schema();
    db()->prepare('INSERT INTO admin_preferences (user_id, preference_key, preference_value, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(user_id, preference_key) DO UPDATE SET preference_value = excluded.preference_value, updated_at = CURRENT_TIMESTAMP')
        ->execute([$userId, $key, $value]);
}

function get_dashboard_layout(int $userId): array
{
    $default = ['status', 'services', 'quick_actions', 'system_health', 'cloudflare', 'maintenance', 'incidents', 'announcements', 'monitoring', 'primary'];
    $stored = get_admin_preference($userId, 'dashboard_layout', '');
    if ($stored === '') return ['order' => $default, 'hidden' => []];
    $decoded = json_decode($stored, true);
    if (!is_array($decoded)) return ['order' => $default, 'hidden' => []];
    $allowed = $default;
    $order = array_values(array_unique(array_filter($decoded['order'] ?? [], static fn($v): bool => in_array($v, $allowed, true))));
    foreach ($allowed as $key) if (!in_array($key, $order, true)) $order[] = $key;
    $hidden = array_values(array_unique(array_filter($decoded['hidden'] ?? [], static fn($v): bool => in_array($v, $allowed, true))));
    return ['order' => $order, 'hidden' => $hidden];
}

function save_dashboard_layout(int $userId, array $order, array $hidden): void
{
    $allowed = ['status', 'services', 'quick_actions', 'system_health', 'cloudflare', 'maintenance', 'incidents', 'announcements', 'monitoring', 'primary'];
    $order = array_values(array_unique(array_filter($order, static fn($v): bool => is_string($v) && in_array($v, $allowed, true))));
    foreach ($allowed as $key) if (!in_array($key, $order, true)) $order[] = $key;
    $hidden = array_values(array_unique(array_filter($hidden, static fn($v): bool => is_string($v) && in_array($v, $allowed, true))));
    set_admin_preference($userId, 'dashboard_layout', json_encode(['order' => $order, 'hidden' => $hidden], JSON_UNESCAPED_SLASHES));
}

function get_maintenance_templates(): array
{
    ensure_platform_schema();
    return db()->query('SELECT * FROM maintenance_templates ORDER BY template_name, id')->fetchAll();
}

function save_maintenance_template(array $data, ?int $userId = null): int
{
    ensure_platform_schema();
    $name = trim((string)($data['template_name'] ?? ''));
    $title = trim((string)($data['title'] ?? ''));
    if ($name === '' || $title === '') throw new RuntimeException('Template name and maintenance title are required.');
    $scope = (string)($data['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'services', 'websites', 'single_service', 'single_website'], true)) $scope = 'all';
    $active = (string)($data['active_status'] ?? 'maintenance');
    $after = (string)($data['after_status'] ?? 'operational');
    if (!isset(STATUS_OPTIONS[$active])) $active = 'maintenance';
    if (!isset(STATUS_OPTIONS[$after])) $after = 'operational';
    $duration = max(5, min(10080, (int)($data['duration_minutes'] ?? 60)));
    $targetType = $scope === 'single_service' ? 'service' : ($scope === 'single_website' ? 'website' : null);
    $targetId = $targetType ? max(0, (int)($data['target_id'] ?? 0)) : null;
    if ($targetType !== null && (!$targetId || !status_target($targetType, $targetId))) {
        throw new RuntimeException('Choose a valid target for this single-item maintenance template.');
    }
    $id = (int)($data['template_id'] ?? 0);
    if ($id > 0) {
        db()->prepare('UPDATE maintenance_templates SET template_name=?, title=?, details=?, scope=?, target_type=?, target_id=?, duration_minutes=?, active_status=?, after_status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$name, $title, trim((string)($data['details'] ?? '')), $scope, $targetType, $targetId, $duration, $active, $after, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO maintenance_templates (template_name,title,details,scope,target_type,target_id,duration_minutes,active_status,after_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $title, trim((string)($data['details'] ?? '')), $scope, $targetType, $targetId, $duration, $active, $after, $userId]);
    return (int)db()->lastInsertId();
}

function delete_maintenance_template(int $id): void
{
    ensure_platform_schema();
    db()->prepare('DELETE FROM maintenance_templates WHERE id = ?')->execute([$id]);
}

function generate_monthly_uptime_report(string $month): array
{
    ensure_platform_schema();
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new RuntimeException('Report month must be YYYY-MM.');
    $start = $month . '-01';
    $startDt = new DateTimeImmutable($start . ' 00:00:00', new DateTimeZone('UTC'));
    $end = $startDt->modify('+1 month')->format('Y-m-d');
    $websites = get_websites();
    $upsert = db()->prepare('INSERT INTO monthly_uptime_reports
        (report_month, website_id, website_name, total_checks, successful_checks, degraded_checks, failed_checks, uptime_percent, avg_response_ms, min_response_ms, max_response_ms, outage_events, incident_count, generated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(report_month, website_id) DO UPDATE SET website_name=excluded.website_name,total_checks=excluded.total_checks,successful_checks=excluded.successful_checks,degraded_checks=excluded.degraded_checks,failed_checks=excluded.failed_checks,uptime_percent=excluded.uptime_percent,avg_response_ms=excluded.avg_response_ms,min_response_ms=excluded.min_response_ms,max_response_ms=excluded.max_response_ms,outage_events=excluded.outage_events,incident_count=excluded.incident_count,generated_at=CURRENT_TIMESTAMP');

    foreach ($websites as $website) {
        $stmt = db()->prepare('SELECT
            COALESCE(SUM(total_checks),0) total,
            COALESCE(SUM(successful_checks),0) success,
            COALESCE(SUM(degraded_checks),0) degraded,
            COALESCE(SUM(failed_checks),0) failed,
            COALESCE(SUM(response_ms_count),0) response_count,
            COALESCE(SUM(response_ms_sum),0) response_sum,
            MIN(min_response_ms) min_ms,
            MAX(max_response_ms) max_ms,
            COALESCE(SUM(outage_events),0) outages
            FROM daily_monitor_stats WHERE website_id = ? AND stat_date >= ? AND stat_date < ?');
        $stmt->execute([(int)$website['id'], $start, $end]);
        $s = $stmt->fetch() ?: [];
        $total = (int)($s['total'] ?? 0);
        $up = (int)($s['success'] ?? 0) + (int)($s['degraded'] ?? 0);
        $uptime = $total > 0 ? round(($up / $total) * 100, 4) : null;
        $avg = (int)($s['response_count'] ?? 0) > 0 ? (int)round((int)$s['response_sum'] / (int)$s['response_count']) : null;

        $inc = db()->prepare('SELECT COUNT(DISTINCT i.id) FROM incidents i JOIN incident_targets it ON it.incident_id=i.id WHERE it.target_type="website" AND it.target_id=? AND i.started_at >= ? AND i.started_at < ?');
        $inc->execute([(int)$website['id'], $start . ' 00:00:00', $end . ' 00:00:00']);
        $incidentCount = (int)$inc->fetchColumn();

        $upsert->execute([$month, (int)$website['id'], (string)$website['website_name'], $total, (int)($s['success'] ?? 0), (int)($s['degraded'] ?? 0), (int)($s['failed'] ?? 0), $uptime, $avg, $s['min_ms'] !== null ? (int)$s['min_ms'] : null, $s['max_ms'] !== null ? (int)$s['max_ms'] : null, (int)($s['outages'] ?? 0), $incidentCount]);
    }
    set_setting('last_monthly_report_at', gmdate('Y-m-d H:i:s'));
    return get_monthly_uptime_report($month);
}

function get_monthly_uptime_report(string $month): array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT * FROM monthly_uptime_reports WHERE report_month = ? ORDER BY website_name');
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

function available_report_months(): array
{
    ensure_platform_schema();
    return array_map(static fn(array $r): string => (string)$r['report_month'], db()->query('SELECT DISTINCT report_month FROM monthly_uptime_reports ORDER BY report_month DESC LIMIT 36')->fetchAll());
}

function ensure_previous_monthly_report(): array
{
    $month = gmdate('Y-m', strtotime('first day of last month'));
    $existing = get_monthly_uptime_report($month);
    return $existing ?: generate_monthly_uptime_report($month);
}

function outbound_webhook_send(string $subject, string $message, string $severity, string $eventType, array $context = []): array
{
    ensure_platform_schema();
    $url = trim((string)get_setting('outbound_webhook_url', ''));
    if ($url === '') throw new RuntimeException('Outbound webhook URL is not configured.');
    if (!preg_match('~^https?://~i', $url)) throw new RuntimeException('Outbound webhook URL must begin with http:// or https://.');
    $secret = (string)get_setting('outbound_webhook_secret', '');
    $payload = [
        'event' => $eventType,
        'subject' => $subject,
        'message' => $message,
        'severity' => $severity,
        'context' => $context,
        'status_url' => APP_URL,
        'sent_at' => gmdate(DateTimeInterface::ATOM),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'User-Agent: FareBrosStatus/5.5'];
    if ($secret !== '') $headers[] = 'X-FareBros-Signature: sha256=' . hash_hmac('sha256', (string)$json, $secret);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $json]);
    $body = curl_exec($ch); $error = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $ok = $body !== false && $error === '' && $code >= 200 && $code < 300;
    db()->prepare('INSERT INTO outbound_webhook_log (event_type,destination,http_code,success,response) VALUES (?,?,?,?,?)')
        ->execute([$eventType, $url, $code ?: null, $ok ? 1 : 0, substr($error !== '' ? $error : (string)$body, 0, 2000)]);
    if (!$ok) throw new RuntimeException('Outbound webhook failed' . ($code ? ' (HTTP ' . $code . ')' : '') . ($error ? ': ' . $error : '.'));
    return ['ok' => true, 'http_code' => $code];
}

function dispatch_outbound_webhook(string $subject, string $message, string $severity, string $eventType, array $context = []): array
{
    if (get_setting('outbound_webhook_enabled', '0') !== '1') return [];
    try {
        return outbound_webhook_send($subject, $message, $severity, $eventType, $context);
    } catch (Throwable $ex) {
        return ['ok' => false, 'error' => $ex->getMessage()];
    }
}

function get_recent_outbound_webhook_log(int $limit = 20): array
{
    ensure_platform_schema();
    $stmt = db()->prepare('SELECT * FROM outbound_webhook_log ORDER BY created_at DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function status_system_health(): array
{
    ensure_platform_schema();
    $health = status_database_health();
    $now = time();
    $cron = (string)get_setting('last_monitor_cron_at', '');
    $cronTs = $cron !== '' ? strtotime($cron . ' UTC') : 0;
    $house = (string)get_setting('last_housekeeping_at', '');
    $houseTs = $house !== '' ? strtotime($house . ' UTC') : 0;
    $smtpEnabled = get_setting('smtp_enabled', '0') === '1';
    $discordEnabled = get_setting('discord_enabled', '0') === '1';
    $webhookEnabled = get_setting('outbound_webhook_enabled', '0') === '1';
    $apiEnabled = get_setting('public_api_enabled', '1') === '1';
    $lastWebhook = get_recent_outbound_webhook_log(1)[0] ?? null;
    $sources = get_monitor_source_health();
    $healthySources = array_values(array_filter($sources, static fn(array $s): bool => empty($s['is_stale'])));
    $dbDir = dirname(DB_PATH);
    $free = @disk_free_space($dbDir);
    $total = @disk_total_space($dbDir);
    $diskPct = ($free !== false && $total && $total > 0) ? (($free / $total) * 100) : null;
    $checks = [];
    $checks[] = ['key'=>'cron','label'=>'Monitor Cron','status'=>$cronTs && $cronTs > $now - 180 ? 'good' : ($cronTs ? 'warning' : 'bad'),'value'=>$cron ? format_dt($cron) : 'Never seen','detail'=>$cronTs && $cronTs > $now - 180 ? 'Heartbeat is current.' : 'Cron has not checked in within 3 minutes.'];
    $checks[] = ['key'=>'database','label'=>'Database','status'=>is_file(DB_PATH) && is_writable(DB_PATH) ? 'good' : 'bad','value'=>number_format(($health['database_bytes'] ?? 0)/1048576, 1).' MB','detail'=>number_format((int)($health['monitor_logs'] ?? 0)).' raw monitor rows retained.'];
    $checks[] = ['key'=>'backup','label'=>'Backups','status'=>!empty($health['latest_backup']) && !empty($health['latest_backup_at']) && strtotime((string)$health['latest_backup_at'].' UTC') > $now - 172800 ? 'good' : 'warning','value'=>$health['latest_backup'] ?? 'No backup','detail'=>!empty($health['latest_backup_at']) ? 'Latest: '.format_dt((string)$health['latest_backup_at']) : 'No database backup has been recorded.'];
    $checks[] = ['key'=>'housekeeping','label'=>'Housekeeping','status'=>$houseTs && $houseTs > $now - 172800 ? 'good' : 'warning','value'=>$house ? format_dt($house) : 'Never','detail'=>'Retention, rollups, reports, backups, and vacuum maintenance.'];
    $checks[] = ['key'=>'smtp','label'=>'Email','status'=>$smtpEnabled ? (get_setting('last_smtp_test_ok','') === '1' ? 'good' : 'warning') : 'muted','value'=>$smtpEnabled ? 'Configured' : 'Disabled','detail'=>$smtpEnabled ? 'Last test: '.(get_setting('last_smtp_test_at','') ?: 'not tested') : 'SMTP alerts and subscriptions are off.'];
    $checks[] = ['key'=>'discord','label'=>'Discord','status'=>$discordEnabled ? (get_setting('last_discord_test_ok','') === '1' ? 'good' : 'warning') : 'muted','value'=>$discordEnabled ? 'Configured' : 'Disabled','detail'=>$discordEnabled ? 'Last test: '.(get_setting('last_discord_test_at','') ?: 'not tested') : 'Discord alerts are off.'];
    $webhookStatus = !$webhookEnabled ? 'muted' : (!$lastWebhook ? 'warning' : ((int)$lastWebhook['success'] === 1 ? 'good' : 'bad'));
    $checks[] = ['key'=>'webhook','label'=>'Outbound Webhook','status'=>$webhookStatus,'value'=>$webhookEnabled ? ($lastWebhook ? ((int)$lastWebhook['success']===1?'Delivering':'Last delivery failed') : 'Configured') : 'Disabled','detail'=>$webhookEnabled ? ($lastWebhook ? 'Last delivery: '.format_dt((string)$lastWebhook['created_at']).($lastWebhook['http_code']?' · HTTP '.(int)$lastWebhook['http_code']:'') : 'Configured but no delivery has been recorded yet.') : 'Outbound event delivery is off.'];
    $checks[] = ['key'=>'api','label'=>'Public API','status'=>$apiEnabled ? 'good' : 'muted','value'=>$apiEnabled ? 'Enabled' : 'Disabled','detail'=>$apiEnabled ? 'Read-only v1 status integration is available.' : 'Public API endpoints are disabled.'];
    $checks[] = ['key'=>'redundancy','label'=>'Monitor Redundancy','status'=>count($healthySources) >= 2 ? 'good' : 'warning','value'=>count($healthySources).' active source'.(count($healthySources)===1?'':'s'),'detail'=>count($healthySources) >= 2 ? 'Multi-location monitoring is active.' : 'Add a second offsite monitor for consensus.'];
    $checks[] = ['key'=>'disk','label'=>'Disk Space','status'=>$diskPct === null ? 'muted' : ($diskPct < 10 ? 'bad' : ($diskPct < 20 ? 'warning' : 'good')),'value'=>$diskPct === null ? 'Unavailable' : number_format($diskPct,1).'% free','detail'=>$free !== false ? number_format($free/1073741824,1).' GB free near the database.' : 'Filesystem free space could not be read.'];

    $bad = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'bad'));
    $warn = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'warning'));
    return ['overall' => $bad ? 'bad' : ($warn ? 'warning' : 'good'), 'bad_count'=>$bad, 'warning_count'=>$warn, 'checks'=>$checks, 'database'=>$health, 'monitor_sources'=>$sources];
}

function platform_run_daily_tasks(): array
{
    ensure_platform_schema();
    $previous = null;
    $current = null;
    try { $previous = ensure_previous_monthly_report(); } catch (Throwable $ignored) {}
    try { $current = generate_monthly_uptime_report(gmdate('Y-m')); } catch (Throwable $ignored) {}
    return [
        'previous_month_report_rows' => is_array($previous) ? count($previous) : 0,
        'current_month_report_rows' => is_array($current) ? count($current) : 0,
    ];
}

ensure_platform_schema();
