<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/incidents.php';

function ensure_monitor_schema(): void
{
    ensure_core_schema();
    $pdo = db();
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS monitor_check_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            website_id INTEGER,
            service_id INTEGER,
            result TEXT NOT NULL,
            http_code INTEGER,
            error_message TEXT,
            response_ms INTEGER,
            checked_url TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "local",
            monitor_type TEXT NOT NULL DEFAULT "http",
            ssl_days_remaining INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $columns = array_map(static fn(array $r): string => (string)$r['name'], $pdo->query('PRAGMA table_info(monitor_check_log)')->fetchAll());
    $add = static function (string $name, string $definition) use ($pdo, &$columns): void {
        if (!in_array($name, $columns, true)) {
            $pdo->exec('ALTER TABLE monitor_check_log ADD COLUMN ' . $name . ' ' . $definition);
            $columns[] = $name;
        }
    };
    $add('source', 'TEXT NOT NULL DEFAULT "local"');
    $add('monitor_type', 'TEXT NOT NULL DEFAULT "http"');
    $add('ssl_days_remaining', 'INTEGER');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS daily_monitor_stats (
            website_id INTEGER NOT NULL,
            stat_date TEXT NOT NULL,
            total_checks INTEGER NOT NULL DEFAULT 0,
            successful_checks INTEGER NOT NULL DEFAULT 0,
            degraded_checks INTEGER NOT NULL DEFAULT 0,
            failed_checks INTEGER NOT NULL DEFAULT 0,
            response_ms_count INTEGER NOT NULL DEFAULT 0,
            response_ms_sum INTEGER NOT NULL DEFAULT 0,
            min_response_ms INTEGER,
            max_response_ms INTEGER,
            outage_events INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (website_id, stat_date)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS monitor_sources (
            source_name TEXT PRIMARY KEY,
            display_name TEXT NOT NULL,
            last_seen_at TEXT,
            last_result TEXT,
            last_website_id INTEGER,
            last_ip TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monitor_log_created ON monitor_check_log(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monitor_log_website_created ON monitor_check_log(website_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monitor_log_source_created ON monitor_check_log(source, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_monitor_date ON daily_monitor_stats(stat_date DESC)');
}

function create_monitor_tables_if_needed(): void
{
    ensure_monitor_schema();
}

function looks_like_cloudflare_down_page(string $body): bool
{
    $needles = ['web server is down', 'host error', 'origin is unreachable', 'connection timed out', 'bad gateway', 'gateway timeout', 'error 521', 'error 522', 'error 523', 'error 524', 'error 525', 'error 526', 'cf-error-code'];
    $body = strtolower($body);
    foreach ($needles as $needle) {
        if (str_contains($body, $needle)) {
            return true;
        }
    }
    return false;
}

function ssl_certificate_days_remaining(string $url): ?int
{
    $parts = parse_url(normalize_url($url));
    $host = (string)($parts['host'] ?? '');
    if ($host === '' || strtolower((string)($parts['scheme'] ?? 'https')) !== 'https') {
        return null;
    }
    $port = (int)($parts['port'] ?? 443);
    $context = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer' => true,
        'verify_peer_name' => true,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $errno = 0;
    $errstr = '';
    $client = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $context);
    if (!$client) {
        return null;
    }
    $params = stream_context_get_params($client);
    @fclose($client);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) {
        return null;
    }
    $parsed = @openssl_x509_parse($cert);
    if (!$parsed || empty($parsed['validTo_time_t'])) {
        return null;
    }
    return (int)floor(((int)$parsed['validTo_time_t'] - time()) / 86400);
}

function check_http_health(array $website): array
{
    $url = normalize_url((string)$website['website_url']);
    $started = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => MONITOR_HTTP_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => 'FareBrosStatusMonitor/5.3',
        CURLOPT_NOBODY => false,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $started) * 1000);

    if ($raw === false || $error !== '') {
        return ['online' => false, 'result' => 'offline', 'http_code' => $code ?: null, 'error' => $error ?: 'Request failed.', 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'http'];
    }

    $body = substr((string)$raw, $headerSize);
    $expectedCode = (int)($website['expected_http_code'] ?? 0);
    if ($expectedCode > 0 && $code !== $expectedCode) {
        return ['online' => false, 'result' => 'offline', 'http_code' => $code, 'error' => 'Expected HTTP ' . $expectedCode . ', received HTTP ' . $code . '.', 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'http'];
    }
    if ($expectedCode <= 0 && ($code >= 500 || $code === 0)) {
        return ['online' => false, 'result' => 'offline', 'http_code' => $code, 'error' => 'HTTP ' . $code, 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'http'];
    }
    if (looks_like_cloudflare_down_page($body)) {
        return ['online' => false, 'result' => 'offline', 'http_code' => $code, 'error' => 'Cloudflare/origin error page detected.', 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'http'];
    }

    $expectedText = trim((string)($website['expected_text'] ?? ''));
    if ($expectedText !== '' && !str_contains($body, $expectedText)) {
        return ['online' => false, 'result' => 'offline', 'http_code' => $code, 'error' => 'Expected page text was not found.', 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'http'];
    }

    $isHealthyCode = $expectedCode > 0 ? $code === $expectedCode : ($code >= 200 && $code < 400);
    if (!$isHealthyCode) {
        return ['online' => false, 'result' => 'offline', 'http_code' => $code, 'error' => 'Unexpected HTTP ' . $code, 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'http'];
    }

    $sslDays = null;
    if ((int)($website['ssl_check'] ?? 1) === 1 && str_starts_with(strtolower($url), 'https://')) {
        $sslDays = ssl_certificate_days_remaining($url);
        if ($sslDays !== null && $sslDays < 0) {
            return ['online' => false, 'result' => 'offline', 'http_code' => $code, 'error' => 'SSL certificate is expired.', 'response_ms' => $ms, 'ssl_days_remaining' => $sslDays, 'monitor_type' => 'http'];
        }
        $warnDays = max(1, (int)($website['ssl_warn_days'] ?? 21));
        if ($sslDays !== null && $sslDays <= $warnDays) {
            return ['online' => true, 'result' => 'degraded', 'http_code' => $code, 'error' => 'SSL certificate expires in ' . $sslDays . ' day(s).', 'response_ms' => $ms, 'ssl_days_remaining' => $sslDays, 'monitor_type' => 'http'];
        }
    }

    $warnMs = max(0, (int)($website['response_warn_ms'] ?? 0));
    if ($warnMs > 0 && $ms >= $warnMs) {
        return ['online' => true, 'result' => 'degraded', 'http_code' => $code, 'error' => 'Response time ' . $ms . 'ms exceeded warning threshold of ' . $warnMs . 'ms.', 'response_ms' => $ms, 'ssl_days_remaining' => $sslDays, 'monitor_type' => 'http'];
    }

    return ['online' => true, 'result' => 'online', 'http_code' => $code, 'error' => null, 'response_ms' => $ms, 'ssl_days_remaining' => $sslDays, 'monitor_type' => 'http'];
}

function check_tcp_health(array $website): array
{
    $host = trim((string)($website['tcp_host'] ?? ''));
    if ($host === '') {
        $host = (string)(parse_url(normalize_url((string)$website['website_url']), PHP_URL_HOST) ?: '');
    }
    $port = (int)($website['tcp_port'] ?? 0);
    if ($host === '' || $port < 1 || $port > 65535) {
        return ['online' => false, 'result' => 'offline', 'http_code' => null, 'error' => 'TCP host/port is not configured.', 'response_ms' => 0, 'ssl_days_remaining' => null, 'monitor_type' => 'tcp'];
    }
    $started = microtime(true);
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 6);
    $ms = (int)round((microtime(true) - $started) * 1000);
    if (!$socket) {
        return ['online' => false, 'result' => 'offline', 'http_code' => null, 'error' => $errstr !== '' ? $errstr : 'TCP connection failed.', 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'tcp'];
    }
    fclose($socket);
    $warnMs = max(0, (int)($website['response_warn_ms'] ?? 0));
    return ['online' => true, 'result' => ($warnMs > 0 && $ms >= $warnMs) ? 'degraded' : 'online', 'http_code' => null, 'error' => ($warnMs > 0 && $ms >= $warnMs) ? 'TCP response exceeded warning threshold.' : null, 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'tcp'];
}

function check_dns_health(array $website): array
{
    $host = trim((string)($website['dns_host'] ?? ''));
    if ($host === '') {
        $host = (string)(parse_url(normalize_url((string)$website['website_url']), PHP_URL_HOST) ?: '');
    }
    if ($host === '') {
        return ['online' => false, 'result' => 'offline', 'http_code' => null, 'error' => 'DNS hostname is not configured.', 'response_ms' => 0, 'ssl_days_remaining' => null, 'monitor_type' => 'dns'];
    }
    $started = microtime(true);
    $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
    $ms = (int)round((microtime(true) - $started) * 1000);
    if (!$records) {
        return ['online' => false, 'result' => 'offline', 'http_code' => null, 'error' => 'DNS lookup returned no A, AAAA, or CNAME records.', 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'dns'];
    }
    $warnMs = max(0, (int)($website['response_warn_ms'] ?? 0));
    return ['online' => true, 'result' => ($warnMs > 0 && $ms >= $warnMs) ? 'degraded' : 'online', 'http_code' => null, 'error' => ($warnMs > 0 && $ms >= $warnMs) ? 'DNS lookup exceeded warning threshold.' : null, 'response_ms' => $ms, 'ssl_days_remaining' => null, 'monitor_type' => 'dns'];
}

/** Backward-compatible helper retained for any older callers. */
function check_url_health(string $url, ?string $expectedText = null): array
{
    return check_http_health([
        'website_url' => $url,
        'expected_text' => $expectedText ?? '',
        'expected_http_code' => null,
        'response_warn_ms' => 0,
        'ssl_check' => 1,
        'ssl_warn_days' => 21,
    ]);
}

function run_website_health_check(array $website): array
{
    return match (strtolower((string)($website['monitor_type'] ?? 'http'))) {
        'tcp' => check_tcp_health($website),
        'dns' => check_dns_health($website),
        default => check_http_health($website),
    };
}

function get_farebros_core_service_id(): ?int
{
    $stmt = db()->prepare('SELECT id FROM services WHERE service_name = ? LIMIT 1');
    $stmt->execute(['Fare Brothers Core']);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function record_monitor_source(string $source, string $result, int $websiteId): void
{
    ensure_monitor_schema();
    $source = preg_replace('/[^a-zA-Z0-9_.-]/', '-', trim($source)) ?: 'unknown';
    $display = $source === 'local' ? 'Status Server (local)' : ucwords(str_replace(['-', '_'], ' ', $source));
    db()->prepare('
        INSERT INTO monitor_sources (source_name, display_name, last_seen_at, last_result, last_website_id, last_ip, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(source_name) DO UPDATE SET
            display_name = excluded.display_name,
            last_seen_at = CURRENT_TIMESTAMP,
            last_result = excluded.last_result,
            last_website_id = excluded.last_website_id,
            last_ip = excluded.last_ip,
            updated_at = CURRENT_TIMESTAMP
    ')->execute([$source, $display, $result, $websiteId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
}

function increment_daily_monitor_rollup(int $websiteId, array $result, bool $outageEvent = false): void
{
    ensure_monitor_schema();
    $date = gmdate('Y-m-d');
    $resultName = (string)($result['result'] ?? 'offline');
    $responseMs = isset($result['response_ms']) ? max(0, (int)$result['response_ms']) : null;
    $success = $resultName === 'online' ? 1 : 0;
    $degraded = $resultName === 'degraded' ? 1 : 0;
    $failed = $resultName === 'offline' ? 1 : 0;
    $hasResponse = $responseMs !== null ? 1 : 0;

    $stmt = db()->prepare('
        INSERT INTO daily_monitor_stats
        (website_id, stat_date, total_checks, successful_checks, degraded_checks, failed_checks, response_ms_count, response_ms_sum, min_response_ms, max_response_ms, outage_events, updated_at)
        VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(website_id, stat_date) DO UPDATE SET
            total_checks = total_checks + 1,
            successful_checks = successful_checks + excluded.successful_checks,
            degraded_checks = degraded_checks + excluded.degraded_checks,
            failed_checks = failed_checks + excluded.failed_checks,
            response_ms_count = response_ms_count + excluded.response_ms_count,
            response_ms_sum = response_ms_sum + excluded.response_ms_sum,
            min_response_ms = CASE
                WHEN excluded.min_response_ms IS NULL THEN min_response_ms
                WHEN min_response_ms IS NULL THEN excluded.min_response_ms
                ELSE MIN(min_response_ms, excluded.min_response_ms)
            END,
            max_response_ms = CASE
                WHEN excluded.max_response_ms IS NULL THEN max_response_ms
                WHEN max_response_ms IS NULL THEN excluded.max_response_ms
                ELSE MAX(max_response_ms, excluded.max_response_ms)
            END,
            outage_events = outage_events + excluded.outage_events,
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        $websiteId,
        $date,
        $success,
        $degraded,
        $failed,
        $hasResponse,
        $responseMs ?? 0,
        $responseMs,
        $responseMs,
        $outageEvent ? 1 : 0,
    ]);
}

function log_monitor_result(array $website, array $result, string $source): int
{
    ensure_monitor_schema();
    $serviceId = (int)($website['is_primary'] ?? 0) === 1 ? get_farebros_core_service_id() : null;
    $checkedUrl = (string)($website['website_url'] ?? '');
    db()->prepare('
        INSERT INTO monitor_check_log
        (website_id, service_id, result, http_code, error_message, response_ms, checked_url, source, monitor_type, ssl_days_remaining)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        (int)$website['id'],
        $serviceId,
        (string)($result['result'] ?? 'offline'),
        $result['http_code'] ?? null,
        $result['error'] ?? null,
        $result['response_ms'] ?? null,
        $checkedUrl,
        $source,
        (string)($result['monitor_type'] ?? ($website['monitor_type'] ?? 'http')),
        $result['ssl_days_remaining'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();
    increment_daily_monitor_rollup((int)$website['id'], $result, false);
    record_monitor_source($source, (string)($result['result'] ?? 'offline'), (int)$website['id']);
    return $id;
}

function consensus_monitor_result(int $websiteId): array
{
    ensure_monitor_schema();
    $window = max(1, min(30, (int)get_setting('monitor_consensus_window_minutes', '5')));
    $stmt = db()->prepare('
        SELECT m.*
        FROM monitor_check_log m
        JOIN (
            SELECT source, MAX(id) AS max_id
            FROM monitor_check_log
            WHERE website_id = ? AND created_at >= datetime("now", ?)
            GROUP BY source
        ) latest ON latest.max_id = m.id
        ORDER BY m.id DESC
    ');
    $stmt->execute([$websiteId, '-' . $window . ' minutes']);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return ['result' => 'offline', 'sources' => 0, 'detail' => 'No recent monitor results.'];
    }

    $states = array_map(static fn(array $r): string => (string)$r['result'], $rows);
    $offline = count(array_filter($states, static fn(string $s): bool => $s === 'offline'));
    $online = count(array_filter($states, static fn(string $s): bool => $s === 'online'));
    $degraded = count($states) - $offline - $online;

    if ($offline === count($states)) {
        $result = 'offline';
    } elseif ($online === count($states)) {
        $result = 'online';
    } else {
        $result = 'degraded';
    }

    $sourceNames = array_values(array_unique(array_map(static fn(array $r): string => (string)$r['source'], $rows)));
    return [
        'result' => $result,
        'sources' => count($sourceNames),
        'source_names' => $sourceNames,
        'detail' => count($sourceNames) > 1 ? ('Consensus from ' . implode(', ', $sourceNames) . '.') : ('Result from ' . ($sourceNames[0] ?? 'local') . '.'),
        'latest' => $rows[0],
    ];
}

function status_from_monitor_result(string $result): string
{
    return match ($result) {
        'online' => 'operational',
        'degraded' => 'degraded',
        default => 'offline',
    };
}

function confirmation_threshold_for_status(string $status): int
{
    return match ($status) {
        'offline' => max(1, min(20, (int)get_setting('monitor_failures_to_offline', '3'))),
        'operational' => max(1, min(20, (int)get_setting('monitor_successes_to_online', '2'))),
        'degraded' => max(1, min(20, (int)get_setting('monitor_degraded_confirmations', '2'))),
        default => 1,
    };
}

function website_has_active_scheduled_status(int $websiteId): bool
{
    // Keep monitoring/logging during maintenance, but never let monitor recovery fight an active schedule.
    $table = db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'scheduled_jobs' LIMIT 1")->fetchColumn();
    if (!$table) {
        return false;
    }

    $now = gmdate('Y-m-d H:i:s');
    $stmt = db()->prepare('\n        SELECT 1 FROM scheduled_jobs\n        WHERE is_active = 1\n          AND has_completed = 0\n          AND start_at <= ?\n          AND end_at > ?\n          AND (\n              scope = "all"\n              OR scope = "websites"\n              OR (scope = "single_website" AND target_id = ?)\n          )\n        LIMIT 1\n    ');
    $stmt->execute([$now, $now, $websiteId]);
    return (bool)$stmt->fetchColumn();
}

function apply_website_monitor_result(array $website, array $rawResult, string $source = 'local'): array
{
    ensure_monitor_schema();
    $websiteId = (int)$website['id'];
    log_monitor_result($website, $rawResult, $source);
    $consensus = consensus_monitor_result($websiteId);
    $candidate = status_from_monitor_result((string)$consensus['result']);
    $oldStatus = (string)$website['current_status'];

    $stmt = db()->prepare('SELECT * FROM websites WHERE id = ? LIMIT 1');
    $stmt->execute([$websiteId]);
    $currentRow = $stmt->fetch() ?: $website;
    $oldStatus = (string)$currentRow['current_status'];

    db()->prepare('UPDATE websites SET last_http_code = ?, last_error = ?, last_response_ms = ?, last_ssl_days = ?, last_monitor_source = ?, last_checked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([
            $rawResult['http_code'] ?? null,
            $rawResult['error'] ?? null,
            $rawResult['response_ms'] ?? null,
            $rawResult['ssl_days_remaining'] ?? null,
            $source,
            $websiteId,
        ]);

    // An active scheduled job is authoritative even when its active status happens to be "offline".
    if (website_has_active_scheduled_status($websiteId)) {
        db()->prepare('UPDATE websites SET pending_status = NULL, pending_count = 0 WHERE id = ?')->execute([$websiteId]);
        return ['website_id' => $websiteId, 'website_name' => $website['website_name'], 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'candidate_status' => $candidate, 'changed' => false, 'suppressed' => 'active maintenance schedule', 'consensus' => $consensus, 'result' => $rawResult];
    }

    // Non-monitor/manual maintenance states are authoritative until an admin or scheduler returns them to a monitor-managed state.
    if (!in_array($oldStatus, ['operational', 'degraded', 'offline'], true)) {
        db()->prepare('UPDATE websites SET pending_status = NULL, pending_count = 0 WHERE id = ?')->execute([$websiteId]);
        return ['website_id' => $websiteId, 'website_name' => $website['website_name'], 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'candidate_status' => $candidate, 'changed' => false, 'suppressed' => 'maintenance/manual status active', 'consensus' => $consensus, 'result' => $rawResult];
    }

    if ($candidate === $oldStatus) {
        db()->prepare('UPDATE websites SET pending_status = NULL, pending_count = 0 WHERE id = ?')->execute([$websiteId]);
        return ['website_id' => $websiteId, 'website_name' => $website['website_name'], 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'candidate_status' => $candidate, 'changed' => false, 'pending_count' => 0, 'consensus' => $consensus, 'result' => $rawResult];
    }

    $pendingStatus = (string)($currentRow['pending_status'] ?? '');
    $pendingCount = (int)($currentRow['pending_count'] ?? 0);
    if ($pendingStatus === $candidate) {
        $pendingCount++;
    } else {
        $pendingStatus = $candidate;
        $pendingCount = 1;
    }
    $threshold = confirmation_threshold_for_status($candidate);

    if ($pendingCount < $threshold) {
        db()->prepare('UPDATE websites SET pending_status = ?, pending_count = ? WHERE id = ?')->execute([$pendingStatus, $pendingCount, $websiteId]);
        return ['website_id' => $websiteId, 'website_name' => $website['website_name'], 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'candidate_status' => $candidate, 'changed' => false, 'pending_count' => $pendingCount, 'threshold' => $threshold, 'consensus' => $consensus, 'result' => $rawResult];
    }

    db()->prepare('UPDATE websites SET current_status = ?, pending_status = NULL, pending_count = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$candidate, $websiteId]);
    $serviceId = null;
    if ((int)($website['is_primary'] ?? 0) === 1) {
        $serviceId = get_farebros_core_service_id();
        if ($serviceId) {
            db()->prepare('UPDATE services SET current_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$candidate, $serviceId]);
        }
    }

    $title = $website['website_name'] . ' is ' . ($candidate === 'operational' ? 'online' : ($candidate === 'degraded' ? 'degraded' : 'offline'));
    $detail = $candidate === 'operational'
        ? $website['website_name'] . ' is responding normally from the monitor.'
        : $website['website_name'] . ' is not responding normally. ' . trim((string)($rawResult['error'] ?? ''));
    if (($consensus['sources'] ?? 1) > 1) {
        $detail .= ' ' . $consensus['detail'];
    }
    db()->prepare('INSERT INTO status_updates (service_id, old_status, new_status, update_title, update_message, created_by) VALUES (?, ?, ?, ?, ?, NULL)')
        ->execute([$serviceId, $oldStatus, $candidate, $title, trim($detail)]);

    if ($candidate === 'offline') {
        db()->prepare('UPDATE daily_monitor_stats SET outage_events = outage_events + 1, updated_at = CURRENT_TIMESTAMP WHERE website_id = ? AND stat_date = ?')
            ->execute([$websiteId, gmdate('Y-m-d')]);
    }

    sync_auto_incident_for_website($website, $oldStatus, $candidate, trim($detail));

    return ['website_id' => $websiteId, 'website_name' => $website['website_name'], 'old_status' => $oldStatus, 'new_status' => $candidate, 'candidate_status' => $candidate, 'changed' => true, 'pending_count' => 0, 'threshold' => $threshold, 'consensus' => $consensus, 'result' => $rawResult];
}

function run_all_monitors(): array
{
    ensure_monitor_schema();
    if (!MONITORING_ENABLED) {
        return ['ok' => false, 'message' => 'Monitoring is disabled.', 'results' => []];
    }

    $out = [];
    foreach (get_websites() as $website) {
        if ((int)($website['monitor_enabled'] ?? 1) !== 1) {
            continue;
        }
        $out[] = apply_website_monitor_result($website, run_website_health_check($website), 'local');
    }

    return ['ok' => true, 'checked_at' => date('c'), 'results' => $out];
}

function ingest_external_monitor_result(int $websiteId, array $result, string $source): array
{
    ensure_monitor_schema();
    $stmt = db()->prepare('SELECT * FROM websites WHERE id = ? LIMIT 1');
    $stmt->execute([$websiteId]);
    $website = $stmt->fetch();
    if (!$website) {
        throw new RuntimeException('Website/monitor not found.');
    }
    $normalized = strtolower((string)($result['result'] ?? 'offline'));
    if (!in_array($normalized, ['online', 'degraded', 'offline'], true)) {
        $normalized = 'offline';
    }
    $payload = [
        'result' => $normalized,
        'online' => $normalized !== 'offline',
        'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : null,
        'error' => trim((string)($result['error'] ?? '')) ?: null,
        'response_ms' => isset($result['response_ms']) ? max(0, (int)$result['response_ms']) : null,
        'ssl_days_remaining' => isset($result['ssl_days_remaining']) ? (int)$result['ssl_days_remaining'] : null,
        'monitor_type' => trim((string)($result['monitor_type'] ?? $website['monitor_type'] ?? 'http')),
    ];
    return apply_website_monitor_result($website, $payload, $source);
}

function get_recent_monitor_logs(int $limit = 25): array
{
    ensure_monitor_schema();
    $stmt = db()->prepare('SELECT mcl.*, w.website_name, s.service_name FROM monitor_check_log mcl LEFT JOIN websites w ON w.id = mcl.website_id LEFT JOIN services s ON s.id = mcl.service_id ORDER BY mcl.created_at DESC, mcl.id DESC LIMIT ?');
    $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_monitor_source_health(): array
{
    ensure_monitor_schema();
    $staleMinutes = max(1, min(120, (int)get_setting('external_monitor_stale_minutes', '5')));
    $rows = db()->query('SELECT * FROM monitor_sources ORDER BY source_name')->fetchAll();
    foreach ($rows as &$row) {
        $seen = !empty($row['last_seen_at']) ? strtotime((string)$row['last_seen_at'] . ' UTC') : 0;
        $row['is_stale'] = !$seen || $seen < time() - ($staleMinutes * 60);
    }
    unset($row);
    return $rows;
}

function get_daily_uptime_history(int $websiteId, int $days = 90): array
{
    ensure_monitor_schema();
    $days = max(1, min(365, $days));
    $start = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $stmt = db()->prepare('SELECT * FROM daily_monitor_stats WHERE website_id = ? AND stat_date >= ? ORDER BY stat_date ASC');
    $stmt->execute([$websiteId, $start]);
    $byDate = [];
    foreach ($stmt->fetchAll() as $row) {
        $byDate[(string)$row['stat_date']] = $row;
    }

    $history = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
        $row = $byDate[$date] ?? null;
        if (!$row) {
            $history[] = ['date' => $date, 'total' => 0, 'uptime' => null, 'avg_ms' => null, 'min_ms' => null, 'max_ms' => null, 'outages' => 0, 'class' => 'empty'];
            continue;
        }
        $total = (int)$row['total_checks'];
        $up = (int)$row['successful_checks'] + (int)$row['degraded_checks'];
        $uptime = $total > 0 ? round(($up / $total) * 100, 3) : null;
        $avg = (int)$row['response_ms_count'] > 0 ? (int)round((int)$row['response_ms_sum'] / (int)$row['response_ms_count']) : null;
        $class = (int)$row['outage_events'] > 0 ? 'bad' : (((int)$row['failed_checks'] > 0 || (int)$row['degraded_checks'] > 0) ? 'warning' : 'good');
        $history[] = ['date' => $date, 'total' => $total, 'uptime' => $uptime, 'avg_ms' => $avg, 'min_ms' => $row['min_response_ms'] !== null ? (int)$row['min_response_ms'] : null, 'max_ms' => $row['max_response_ms'] !== null ? (int)$row['max_response_ms'] : null, 'outages' => (int)$row['outage_events'], 'class' => $class];
    }
    return $history;
}

function get_response_series(int $websiteId, int $hours = 24): array
{
    ensure_monitor_schema();
    $hours = max(1, min(168, $hours));
    $stmt = db()->prepare('
        SELECT strftime("%Y-%m-%d %H:00", created_at) AS bucket,
               CAST(AVG(response_ms) AS INTEGER) AS avg_ms,
               MIN(response_ms) AS min_ms,
               MAX(response_ms) AS max_ms,
               COUNT(response_ms) AS samples
        FROM monitor_check_log
        WHERE website_id = ? AND created_at >= datetime("now", ?) AND response_ms IS NOT NULL
        GROUP BY bucket
        ORDER BY bucket ASC
    ');
    $stmt->execute([$websiteId, '-' . $hours . ' hours']);
    return $stmt->fetchAll();
}

function website_uptime_percent(int $websiteId, int $days = 30): ?float
{
    ensure_monitor_schema();
    $start = gmdate('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
    $stmt = db()->prepare('SELECT SUM(total_checks) total, SUM(successful_checks + degraded_checks) up FROM daily_monitor_stats WHERE website_id = ? AND stat_date >= ?');
    $stmt->execute([$websiteId, $start]);
    $row = $stmt->fetch();
    $total = (int)($row['total'] ?? 0);
    return $total > 0 ? round(((int)$row['up'] / $total) * 100, 3) : null;
}

function backfill_monitor_rollups_once(): int
{
    ensure_monitor_schema();
    if (get_setting('monitor_rollup_backfill_v53', '0') === '1') {
        return 0;
    }
    $before = (int)db()->query('SELECT COUNT(*) FROM daily_monitor_stats')->fetchColumn();
    db()->exec('
        INSERT OR REPLACE INTO daily_monitor_stats
        (website_id, stat_date, total_checks, successful_checks, degraded_checks, failed_checks, response_ms_count, response_ms_sum, min_response_ms, max_response_ms, outage_events, updated_at)
        SELECT website_id,
               date(created_at),
               COUNT(*),
               SUM(CASE WHEN result = "online" THEN 1 ELSE 0 END),
               SUM(CASE WHEN result = "degraded" THEN 1 ELSE 0 END),
               SUM(CASE WHEN result = "offline" THEN 1 ELSE 0 END),
               SUM(CASE WHEN response_ms IS NOT NULL THEN 1 ELSE 0 END),
               COALESCE(SUM(response_ms), 0),
               MIN(response_ms),
               MAX(response_ms),
               0,
               CURRENT_TIMESTAMP
        FROM monitor_check_log
        WHERE website_id IS NOT NULL
        GROUP BY website_id, date(created_at)
    ');
    set_setting('monitor_rollup_backfill_v53', '1');
    $after = (int)db()->query('SELECT COUNT(*) FROM daily_monitor_stats')->fetchColumn();
    return max(0, $after - $before);
}

function public_monitoring_enrichment(array $payload): array
{
    ensure_monitor_schema();
    $defaultHistoryDays = max(30, min(90, (int)get_setting('public_history_days', '90')));
    $days = 90;
    foreach ($payload['websites'] as &$website) {
        $websiteId = (int)$website['id'];
        $website['history'] = get_daily_uptime_history($websiteId, $days);
        $website['response_series'] = get_response_series($websiteId, 24);
        $website['uptime_30'] = website_uptime_percent($websiteId, 30);
        $website['uptime_90'] = website_uptime_percent($websiteId, 90);
    }
    unset($website);

    $sources = get_monitor_source_health();
    $activeSources = array_values(array_filter($sources, static fn(array $source): bool => empty($source['is_stale'])));
    $payload['monitoring'] = [
        'history_days' => $days,
        'default_history_days' => $defaultHistoryDays,
        'source_count' => count($sources),
        'active_source_count' => count($activeSources),
        'redundant' => count($activeSources) >= 2,
    ];
    $activeIncidents = get_active_incidents();
    $payload['incidents'] = [
        'active' => $activeIncidents,
        'recent' => get_recent_incidents(5),
    ];

    if ($activeIncidents && (($payload['overall']['status'] ?? 'operational') === 'operational')) {
        $impacts = array_map(static fn(array $incident): string => (string)($incident['impact'] ?? 'minor'), $activeIncidents);
        $incidentStatus = in_array('critical', $impacts, true)
            ? 'major_outage'
            : (in_array('major', $impacts, true) ? 'partial_outage' : 'degraded');
        $meta = status_meta($incidentStatus);
        $payload['overall'] = [
            'status' => $incidentStatus,
            'label' => $meta['label'],
            'code' => $meta['code'],
            'class' => $meta['class'],
        ];
    }
    return $payload;
}
