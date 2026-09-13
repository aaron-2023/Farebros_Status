<?php
/**
 * Fare Brothers Status v5.3 - Secondary Monitor Agent
 *
 * Copy this single file to a SECOND server/VPS that can run PHP + cURL.
 * Edit the four settings below, then run it every minute with cron:
 *   * * * * * /usr/bin/php /path/external-monitor-agent.php >/dev/null 2>&1
 */

$STATUS_ENDPOINT = 'https://status.farebros.com/api/external-monitor.php';
$EXTERNAL_KEY = 'PASTE-KEY-FROM-ADMIN-SETTINGS-HERE';
$SOURCE_NAME = 'secondary-vps';
$MONITORS = [
    // Match these IDs to the Website IDs shown in your Fare Brothers Status database/admin.
    // ['website_id' => 1, 'url' => 'https://farebros.com/'],
    // ['website_id' => 2, 'url' => 'https://example.com/'],
];

function check_url(string $url): array
{
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'FareBrosExternalMonitor/5.3',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $start) * 1000);
    $online = $body !== false && $error === '' && $code >= 200 && $code < 400;
    return [
        'result' => $online ? 'online' : 'offline',
        'http_code' => $code ?: null,
        'response_ms' => $ms,
        'error' => $online ? null : ($error ?: 'HTTP ' . $code),
        'monitor_type' => 'http',
    ];
}

function post_result(string $endpoint, string $key, array $payload): array
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-FareBros-External-Key: ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['http_code' => $code, 'body' => $body, 'error' => $error];
}

if ($EXTERNAL_KEY === '' || str_contains($EXTERNAL_KEY, 'PASTE-KEY') || !$MONITORS) {
    fwrite(STDERR, "Configure EXTERNAL_KEY and MONITORS before running this agent.\n");
    exit(1);
}

$results = [];
foreach ($MONITORS as $monitor) {
    $check = check_url((string)$monitor['url']);
    $payload = array_merge($check, [
        'website_id' => (int)$monitor['website_id'],
        'source' => $SOURCE_NAME,
    ]);
    $results[] = [
        'website_id' => (int)$monitor['website_id'],
        'check' => $check,
        'server' => post_result($STATUS_ENDPOINT, $EXTERNAL_KEY, $payload),
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
