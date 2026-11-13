<?php
declare(strict_types=1);

/**
 * Automatic Cloudflare failover for the primary Fare Brothers website.
 *
 * Admin-managed settings are stored in the protected status SQLite database.
 * An external config file or environment variables remain supported as fallbacks.
 */

function cloudflare_failover_setting_value(string $key): ?string
{
    if (!function_exists('get_setting')) {
        return null;
    }

    try {
        return get_setting($key, null);
    } catch (Throwable $ignored) {
        return null;
    }
}

function cloudflare_failover_config(bool $refresh = false): array
{
    static $config = null;
    if ($refresh) {
        $config = null;
    }
    if (is_array($config)) {
        return $config;
    }

    $path = trim((string)(getenv('FAREBROS_STATUS_CLOUDFLARE_CONFIG') ?: '/etc/farebros/status-cloudflare.php'));
    $loaded = [];
    if ($path !== '' && is_file($path)) {
        $value = require $path;
        if (is_array($value)) {
            $loaded = $value;
        }
    }

    $env = static function (string $name, ?string $fallback = null): ?string {
        $value = getenv($name);
        return $value === false ? $fallback : (string)$value;
    };

    $pick = static function (string $settingKey, string $fileKey, string $envName, ?string $default = null) use ($loaded, $env): ?string {
        $stored = cloudflare_failover_setting_value($settingKey);
        if ($stored !== null) {
            return $stored;
        }
        if (array_key_exists($fileKey, $loaded)) {
            if (is_bool($loaded[$fileKey])) {
                return $loaded[$fileKey] ? '1' : '0';
            }
            if (is_scalar($loaded[$fileKey])) {
                return (string)$loaded[$fileKey];
            }
        }
        return $env($envName, $default);
    };

    $dbHosts = cloudflare_failover_setting_value('cloudflare_failover_hosts');
    if ($dbHosts !== null) {
        $hosts = preg_split('/\s*,\s*/', $dbHosts, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } else {
        $hosts = $loaded['hosts'] ?? $env('CLOUDFLARE_FAILOVER_HOSTS', 'farebros.com,www.farebros.com');
        if (!is_array($hosts)) {
            $hosts = preg_split('/\s*,\s*/', (string)$hosts, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }

    $hosts = array_values(array_unique(array_filter(array_map(
        static fn($host): string => strtolower(trim((string)$host)),
        (array)$hosts
    ), static fn(string $host): bool => (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host))));

    $tokenSetting = cloudflare_failover_setting_value('cloudflare_api_token');
    $fileToken = isset($loaded['api_token']) && is_scalar($loaded['api_token']) ? trim((string)$loaded['api_token']) : '';
    $envToken = trim((string)$env('CLOUDFLARE_FAILOVER_TOKEN', ''));
    if ($tokenSetting !== null) {
        $apiToken = trim($tokenSetting);
        $tokenSource = $apiToken !== '' ? 'database' : 'none';
    } elseif ($fileToken !== '') {
        $apiToken = $fileToken;
        $tokenSource = 'config_file';
    } else {
        $apiToken = $envToken;
        $tokenSource = $apiToken !== '' ? 'environment' : 'none';
    }

    $config = [
        'enabled' => filter_var($pick('cloudflare_failover_enabled', 'enabled', 'CLOUDFLARE_FAILOVER_ENABLED', '1'), FILTER_VALIDATE_BOOL),
        'api_token' => $apiToken,
        'token_source' => $tokenSource,
        'zone_id' => trim((string)$pick('cloudflare_zone_id', 'zone_id', 'CLOUDFLARE_FAILOVER_ZONE_ID', 'f2d3aa7c24ba6f22a3fdbb2f54ae2d68')),
        'ruleset_id' => trim((string)$pick('cloudflare_ruleset_id', 'ruleset_id', 'CLOUDFLARE_FAILOVER_RULESET_ID', '85e2c55f764c4fbc9587a25c0c521094')),
        'rule_id' => trim((string)$pick('cloudflare_rule_id', 'rule_id', 'CLOUDFLARE_FAILOVER_RULE_ID', 'b7c0b0de38ef42d6b604866e09d14dae')),
        'redirect_url' => trim((string)$pick('cloudflare_redirect_url', 'redirect_url', 'CLOUDFLARE_FAILOVER_REDIRECT_URL', 'https://status.farebros.com')),
        'hosts' => $hosts ?: ['farebros.com', 'www.farebros.com'],
        'failure_threshold' => max(1, min(20, (int)$pick('cloudflare_failure_threshold', 'failure_threshold', 'CLOUDFLARE_FAILOVER_FAILURES', '3'))),
        'recovery_threshold' => max(1, min(20, (int)$pick('cloudflare_recovery_threshold', 'recovery_threshold', 'CLOUDFLARE_FAILOVER_SUCCESSES', '3'))),
        'state_refresh_seconds' => max(60, min(3600, (int)$pick('cloudflare_state_refresh_seconds', 'state_refresh_seconds', 'CLOUDFLARE_FAILOVER_STATE_REFRESH_SECONDS', '300'))),
        'monitor_user_agent_prefix' => trim((string)$pick('cloudflare_monitor_user_agent_prefix', 'monitor_user_agent_prefix', 'CLOUDFLARE_FAILOVER_MONITOR_UA', 'FareBrosStatusMonitor/')),
        'config_path' => $path,
    ];

    return $config;
}

function cloudflare_failover_credentials_configured(): bool
{
    $config = cloudflare_failover_config();
    return $config['api_token'] !== ''
        && preg_match('/^[a-f0-9]{32}$/i', (string)$config['zone_id']) === 1
        && preg_match('/^[a-f0-9]{32}$/i', (string)$config['ruleset_id']) === 1
        && preg_match('/^[a-f0-9]{32}$/i', (string)$config['rule_id']) === 1
        && filter_var((string)$config['redirect_url'], FILTER_VALIDATE_URL) !== false
        && !empty($config['hosts']);
}

function cloudflare_failover_configured(): bool
{
    return cloudflare_failover_credentials_configured();
}

function cloudflare_failover_token_summary(): string
{
    $config = cloudflare_failover_config();
    $token = (string)$config['api_token'];
    if ($token === '') {
        return 'Not saved';
    }

    $suffix = strlen($token) >= 6 ? substr($token, -6) : str_repeat('•', strlen($token));
    $source = match ((string)($config['token_source'] ?? 'none')) {
        'database' => 'Admin settings',
        'config_file' => 'Server config file',
        'environment' => 'Environment variable',
        default => 'Saved',
    };

    return $source . ' · ending ' . $suffix;
}

function cloudflare_failover_save_admin_settings(array $input, ?string $replacementToken = null, bool $clearToken = false): void
{
    if (!function_exists('set_setting')) {
        throw new RuntimeException('Status settings storage is unavailable.');
    }

    $zoneId = strtolower(trim((string)($input['zone_id'] ?? '')));
    $rulesetId = strtolower(trim((string)($input['ruleset_id'] ?? '')));
    $ruleId = strtolower(trim((string)($input['rule_id'] ?? '')));
    foreach (['Zone ID' => $zoneId, 'Ruleset ID' => $rulesetId, 'Rule ID' => $ruleId] as $label => $value) {
        if (preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new RuntimeException($label . ' must be a 32-character Cloudflare ID.');
        }
    }

    $redirectUrl = trim((string)($input['redirect_url'] ?? ''));
    $parts = parse_url($redirectUrl);
    if (
        filter_var($redirectUrl, FILTER_VALIDATE_URL) === false
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
    ) {
        throw new RuntimeException('Redirect URL must be a valid HTTPS URL.');
    }

    $hostsRaw = trim((string)($input['hosts'] ?? 'farebros.com,www.farebros.com'));
    $hosts = preg_split('/[\s,;]+/', $hostsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hosts = array_values(array_unique(array_map(static fn(string $host): string => strtolower(trim($host)), $hosts)));
    if (!$hosts || count($hosts) > 10) {
        throw new RuntimeException('Enter between 1 and 10 managed hostnames.');
    }
    foreach ($hosts as $host) {
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw new RuntimeException('Invalid managed hostname: ' . $host);
        }
    }

    $redirectHost = strtolower((string)($parts['host'] ?? ''));
    if (in_array($redirectHost, $hosts, true)) {
        throw new RuntimeException('Redirect URL cannot point to one of the managed hostnames or it would create a redirect loop.');
    }

    $failureThreshold = max(1, min(20, (int)($input['failure_threshold'] ?? 3)));
    $recoveryThreshold = max(1, min(20, (int)($input['recovery_threshold'] ?? 3)));
    $refreshSeconds = max(60, min(3600, (int)($input['state_refresh_seconds'] ?? 300)));
    $monitorPrefix = trim((string)($input['monitor_user_agent_prefix'] ?? 'FareBrosStatusMonitor/'));
    if ($monitorPrefix === '' || strlen($monitorPrefix) > 120) {
        throw new RuntimeException('Monitor user-agent prefix must be between 1 and 120 characters.');
    }

    set_setting('cloudflare_failover_enabled', !empty($input['enabled']) ? '1' : '0');
    set_setting('cloudflare_zone_id', $zoneId);
    set_setting('cloudflare_ruleset_id', $rulesetId);
    set_setting('cloudflare_rule_id', $ruleId);
    set_setting('cloudflare_redirect_url', $redirectUrl);
    set_setting('cloudflare_failover_hosts', implode(',', $hosts));
    set_setting('cloudflare_failure_threshold', (string)$failureThreshold);
    set_setting('cloudflare_recovery_threshold', (string)$recoveryThreshold);
    set_setting('cloudflare_state_refresh_seconds', (string)$refreshSeconds);
    set_setting('cloudflare_monitor_user_agent_prefix', $monitorPrefix);

    if ($clearToken) {
        set_setting('cloudflare_api_token', '');
    } elseif ($replacementToken !== null && trim($replacementToken) !== '') {
        $token = trim($replacementToken);
        if (strlen($token) < 20 || preg_match('/\s/', $token)) {
            throw new RuntimeException('Cloudflare API token does not look valid.');
        }
        set_setting('cloudflare_api_token', $token);
    }

    cloudflare_failover_config(true);
}

function ensure_cloudflare_failover_schema(): void
{
    $pdo = db();
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cloudflare_failover_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            remote_state TEXT NOT NULL DEFAULT "unknown",
            automation_owns_redirect INTEGER NOT NULL DEFAULT 0,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            consecutive_successes INTEGER NOT NULL DEFAULT 0,
            rule_prepared INTEGER NOT NULL DEFAULT 0,
            last_remote_check_at TEXT,
            last_action_at TEXT,
            last_action TEXT,
            last_error TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cloudflare_failover_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            message TEXT NOT NULL,
            remote_state TEXT,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            consecutive_successes INTEGER NOT NULL DEFAULT 0,
            details_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cloudflare_failover_log_created ON cloudflare_failover_log(created_at DESC)');
    $pdo->exec('INSERT OR IGNORE INTO cloudflare_failover_state (id) VALUES (1)');
}

function cloudflare_failover_state(): array
{
    ensure_cloudflare_failover_schema();
    $row = db()->query('SELECT * FROM cloudflare_failover_state WHERE id = 1')->fetch();
    return $row ?: [
        'id' => 1,
        'remote_state' => 'unknown',
        'automation_owns_redirect' => 0,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
        'rule_prepared' => 0,
        'last_remote_check_at' => null,
        'last_action_at' => null,
        'last_action' => null,
        'last_error' => null,
        'updated_at' => null,
    ];
}

function cloudflare_failover_log(string $eventType, string $message, array $details = []): void
{
    ensure_cloudflare_failover_schema();
    $state = cloudflare_failover_state();
    $json = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    db()->prepare('
        INSERT INTO cloudflare_failover_log
        (event_type, message, remote_state, consecutive_failures, consecutive_successes, details_json)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        substr($eventType, 0, 60),
        substr($message, 0, 1000),
        (string)($state['remote_state'] ?? 'unknown'),
        (int)($state['consecutive_failures'] ?? 0),
        (int)($state['consecutive_successes'] ?? 0),
        $json !== null ? substr($json, 0, 8000) : null,
    ]);
    error_log('[FareBros Failover] ' . $eventType . ': ' . $message);
}

function cloudflare_failover_expression(): string
{
    $config = cloudflare_failover_config();
    $hosts = [];
    foreach ((array)$config['hosts'] as $host) {
        $host = strtolower(trim((string)$host));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            continue;
        }
        $hosts[] = '(http.host eq "' . $host . '")';
    }
    if (!$hosts) {
        $hosts = ['(http.host eq "farebros.com")', '(http.host eq "www.farebros.com")'];
    }

    $prefix = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$config['monitor_user_agent_prefix']);
    return '(' . implode(' or ', $hosts) . ') and not (http.user_agent contains "' . $prefix . '")';
}

function cloudflare_failover_rule_payload(bool $enabled): array
{
    $config = cloudflare_failover_config();
    return [
        'action' => 'redirect',
        'action_parameters' => [
            'from_value' => [
                'preserve_query_string' => false,
                'status_code' => 302,
                'target_url' => [
                    'value' => (string)$config['redirect_url'],
                ],
            ],
        ],
        'description' => 'Redirect to Status Page',
        'enabled' => $enabled,
        'expression' => cloudflare_failover_expression(),
        'ref' => (string)$config['rule_id'],
    ];
}

function cloudflare_failover_api(string $method, string $path, ?array $payload = null): array
{
    $config = cloudflare_failover_config();
    if (!cloudflare_failover_credentials_configured()) {
        throw new RuntimeException('Cloudflare API credentials and redirect IDs are not fully configured.');
    }

    $url = 'https://api.cloudflare.com/client/v4' . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . (string)$config['api_token'],
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: FareBrosStatusFailover/1.0',
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ];
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode Cloudflare request payload.');
        }
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Cloudflare API request failed: ' . ($error ?: 'Unknown cURL error.'));
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Cloudflare API returned an invalid response (HTTP ' . $status . ').');
    }
    if ($status < 200 || $status >= 300 || empty($decoded['success'])) {
        $messages = [];
        foreach ((array)($decoded['errors'] ?? []) as $item) {
            if (is_array($item) && isset($item['message'])) {
                $messages[] = (string)$item['message'];
            }
        }
        $message = $messages ? implode('; ', $messages) : ('HTTP ' . $status);
        throw new RuntimeException('Cloudflare API rejected the request: ' . $message);
    }

    return $decoded;
}

function cloudflare_failover_get_remote_rule(): array
{
    $config = cloudflare_failover_config();
    $response = cloudflare_failover_api(
        'GET',
        '/zones/' . rawurlencode((string)$config['zone_id']) . '/rulesets/' . rawurlencode((string)$config['ruleset_id'])
    );

    foreach ((array)($response['result']['rules'] ?? []) as $rule) {
        if (is_array($rule) && (string)($rule['id'] ?? '') === (string)$config['rule_id']) {
            return $rule;
        }
    }
    throw new RuntimeException('Configured Cloudflare redirect rule was not found in the ruleset.');
}

function cloudflare_failover_patch_rule(bool $enabled): array
{
    $config = cloudflare_failover_config();
    $response = cloudflare_failover_api(
        'PATCH',
        '/zones/' . rawurlencode((string)$config['zone_id'])
            . '/rulesets/' . rawurlencode((string)$config['ruleset_id'])
            . '/rules/' . rawurlencode((string)$config['rule_id']),
        cloudflare_failover_rule_payload($enabled)
    );

    foreach ((array)($response['result']['rules'] ?? []) as $rule) {
        if (is_array($rule) && (string)($rule['id'] ?? '') === (string)$config['rule_id']) {
            return $rule;
        }
    }

    return ['id' => (string)$config['rule_id'], 'enabled' => $enabled, 'expression' => cloudflare_failover_expression()];
}

function cloudflare_failover_rule_is_prepared(array $rule): bool
{
    $config = cloudflare_failover_config();
    $target = (string)($rule['action_parameters']['from_value']['target_url']['value'] ?? '');
    $statusCode = (int)($rule['action_parameters']['from_value']['status_code'] ?? 0);
    $preserve = (bool)($rule['action_parameters']['from_value']['preserve_query_string'] ?? true);

    return (string)($rule['action'] ?? '') === 'redirect'
        && trim((string)($rule['expression'] ?? '')) === cloudflare_failover_expression()
        && $target === (string)$config['redirect_url']
        && $statusCode === 302
        && $preserve === false;
}

function cloudflare_failover_refresh_remote_state(bool $force = false): array
{
    ensure_cloudflare_failover_schema();
    $state = cloudflare_failover_state();
    $config = cloudflare_failover_config();

    if (!cloudflare_failover_credentials_configured()) {
        return $state;
    }

    $lastCheck = !empty($state['last_remote_check_at']) ? strtotime((string)$state['last_remote_check_at'] . ' UTC') : 0;
    if (!$force && $lastCheck > 0 && $lastCheck >= time() - (int)$config['state_refresh_seconds']) {
        return $state;
    }

    $rule = cloudflare_failover_get_remote_rule();
    $enabled = !empty($rule['enabled']);
    $prepared = cloudflare_failover_rule_is_prepared($rule);

    // The monitor must bypass the redirect or it could mistake the status page for a recovered origin.
    // Repair the rule definition in place while preserving its current enabled/disabled state.
    if (!$prepared) {
        $rule = cloudflare_failover_patch_rule($enabled);
        $prepared = cloudflare_failover_rule_is_prepared($rule);
        cloudflare_failover_log('rule_prepared', 'Cloudflare redirect rule was updated with the monitor bypass and expected redirect definition.', [
            'enabled' => $enabled,
        ]);
    }

    $remoteState = $enabled ? 'enabled' : 'disabled';
    $owns = (int)($state['automation_owns_redirect'] ?? 0);
    if (!$enabled && $owns === 1) {
        // Someone manually disabled a redirect that automation previously enabled. Respect it.
        $owns = 0;
        cloudflare_failover_log('manual_override', 'Cloudflare redirect was disabled outside the automatic failover controller; automatic ownership was cleared.');
    }

    db()->prepare('
        UPDATE cloudflare_failover_state
        SET remote_state = ?, automation_owns_redirect = ?, rule_prepared = ?, last_remote_check_at = CURRENT_TIMESTAMP, last_error = NULL, updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ')->execute([$remoteState, $owns, $prepared ? 1 : 0]);

    return cloudflare_failover_state();
}

function cloudflare_failover_set_remote(bool $enabled, bool $claimOwnership, string $action): array
{
    ensure_cloudflare_failover_schema();
    $rule = cloudflare_failover_patch_rule($enabled);
    $remoteState = $enabled ? 'enabled' : 'disabled';
    $owns = $enabled && $claimOwnership ? 1 : 0;

    db()->prepare('
        UPDATE cloudflare_failover_state
        SET remote_state = ?, automation_owns_redirect = ?, rule_prepared = 1,
            last_remote_check_at = CURRENT_TIMESTAMP, last_action_at = CURRENT_TIMESTAMP,
            last_action = ?, last_error = NULL, updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ')->execute([$remoteState, $owns, $action]);

    return $rule;
}

function cloudflare_failover_process_result(array $website, array $rawResult): array
{
    ensure_cloudflare_failover_schema();
    $config = cloudflare_failover_config();

    if ((int)($website['is_primary'] ?? 0) !== 1) {
        return ['active' => false, 'reason' => 'not_primary'];
    }
    if (strtolower((string)($website['monitor_type'] ?? 'http')) !== 'http') {
        return ['active' => false, 'reason' => 'primary_monitor_must_be_http'];
    }
    $websiteHost = strtolower((string)(parse_url(normalize_url((string)($website['website_url'] ?? '')), PHP_URL_HOST) ?: ''));
    if ($websiteHost === '' || !in_array($websiteHost, (array)$config['hosts'], true)) {
        return ['active' => false, 'reason' => 'primary_host_not_managed', 'host' => $websiteHost];
    }
    if (!(bool)$config['enabled']) {
        return ['active' => false, 'reason' => 'disabled'];
    }
    if (!cloudflare_failover_configured()) {
        return [
            'active' => false,
            'reason' => 'not_configured',
            'config_path' => (string)$config['config_path'],
        ];
    }

    try {
        // Count health results first. If the Cloudflare API is temporarily unreachable,
        // the outage/recovery streak still advances and the pending action can happen
        // immediately when API access returns.
        $state = cloudflare_failover_state();
        $healthy = (bool)($rawResult['online'] ?? false);
        $failures = $healthy ? 0 : ((int)($state['consecutive_failures'] ?? 0) + 1);
        $successes = $healthy ? ((int)($state['consecutive_successes'] ?? 0) + 1) : 0;

        db()->prepare('
            UPDATE cloudflare_failover_state
            SET consecutive_failures = ?, consecutive_successes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ')->execute([$failures, $successes]);

        $state = cloudflare_failover_refresh_remote_state(false);
        $remoteEnabled = (string)($state['remote_state'] ?? 'unknown') === 'enabled';
        $owns = (int)($state['automation_owns_redirect'] ?? 0) === 1;
        $action = 'none';

        if (!$healthy && $failures >= (int)$config['failure_threshold'] && !$remoteEnabled) {
            cloudflare_failover_set_remote(true, true, 'automatic_failover');
            $action = 'enabled_redirect';
            cloudflare_failover_log(
                'failover_enabled',
                'Primary Fare Brothers monitor failed ' . $failures . ' consecutive checks. Redirect to the status site was enabled automatically.',
                ['website_id' => (int)$website['id'], 'http_code' => $rawResult['http_code'] ?? null, 'error' => $rawResult['error'] ?? null]
            );
            $state = cloudflare_failover_state();
            $remoteEnabled = true;
            $owns = true;
        } elseif ($healthy && $successes >= (int)$config['recovery_threshold'] && $remoteEnabled && $owns) {
            cloudflare_failover_set_remote(false, false, 'automatic_failback');
            $action = 'disabled_redirect';
            cloudflare_failover_log(
                'failback_disabled',
                'Primary Fare Brothers monitor passed ' . $successes . ' consecutive checks. Redirect to the status site was disabled automatically.',
                ['website_id' => (int)$website['id'], 'http_code' => $rawResult['http_code'] ?? null]
            );
            $state = cloudflare_failover_state();
            $remoteEnabled = false;
            $owns = false;
        }

        return [
            'active' => true,
            'healthy' => $healthy,
            'remote_state' => $remoteEnabled ? 'enabled' : (string)($state['remote_state'] ?? 'disabled'),
            'automation_owns_redirect' => $owns,
            'rule_prepared' => (int)($state['rule_prepared'] ?? 0) === 1,
            'consecutive_failures' => (int)($state['consecutive_failures'] ?? 0),
            'failure_threshold' => (int)$config['failure_threshold'],
            'consecutive_successes' => (int)($state['consecutive_successes'] ?? 0),
            'recovery_threshold' => (int)$config['recovery_threshold'],
            'action' => $action,
        ];
    } catch (Throwable $ex) {
        $message = substr($ex->getMessage(), 0, 1000);
        $beforeError = cloudflare_failover_state();
        $sameError = trim((string)($beforeError['last_error'] ?? '')) === $message;
        db()->prepare('UPDATE cloudflare_failover_state SET last_error = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1')->execute([$message]);
        if (!$sameError) {
            cloudflare_failover_log('error', $message, ['website_id' => (int)($website['id'] ?? 0)]);
        }
        return [
            'active' => true,
            'error' => $message,
            'action' => 'none',
            'monitor_continues' => true,
        ];
    }
}

function cloudflare_failover_status(bool $refreshRemote = true): array
{
    ensure_cloudflare_failover_schema();
    $config = cloudflare_failover_config();
    $state = cloudflare_failover_state();

    if ($refreshRemote && cloudflare_failover_credentials_configured()) {
        try {
            $state = cloudflare_failover_refresh_remote_state(true);
        } catch (Throwable $ex) {
            $state['last_error'] = $ex->getMessage();
        }
    }

    return [
        'configured' => cloudflare_failover_credentials_configured(),
        'enabled' => (bool)$config['enabled'],
        'token_saved' => (string)$config['api_token'] !== '',
        'token_source' => (string)($config['token_source'] ?? 'none'),
        'token_summary' => cloudflare_failover_token_summary(),
        'config_path' => (string)$config['config_path'],
        'zone_id' => (string)$config['zone_id'],
        'ruleset_id' => (string)$config['ruleset_id'],
        'rule_id' => (string)$config['rule_id'],
        'redirect_url' => (string)$config['redirect_url'],
        'hosts' => (array)$config['hosts'],
        'state_refresh_seconds' => (int)$config['state_refresh_seconds'],
        'monitor_user_agent_prefix' => (string)$config['monitor_user_agent_prefix'],
        'failure_threshold' => (int)$config['failure_threshold'],
        'recovery_threshold' => (int)$config['recovery_threshold'],
        'required_expression' => cloudflare_failover_expression(),
        'state' => $state,
    ];
}
