<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function platform_sync_enabled(): bool
{
    return PLATFORM_SYNC_ENABLED && PLATFORM_API_URL !== '' && PLATFORM_API_KEY !== '';
}

function create_sync_table_if_needed(): void
{
    db()->exec('
        CREATE TABLE IF NOT EXISTS platform_sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            http_code INTEGER,
            success INTEGER NOT NULL DEFAULT 0,
            response_body TEXT,
            error_message TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime("now"))
        )
    ');
}

function log_platform_sync(string $eventType, string $endpoint, ?int $httpCode, bool $success, ?string $responseBody, ?string $errorMessage): void
{
    create_sync_table_if_needed();

    $stmt = db()->prepare('
        INSERT INTO platform_sync_log
        (event_type, endpoint, http_code, success, response_body, error_message)
        VALUES (?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $eventType,
        $endpoint,
        $httpCode,
        $success ? 1 : 0,
        $responseBody,
        $errorMessage,
    ]);
}

function post_json_to_platform(string $eventType, array $payload): bool
{
    if (!platform_sync_enabled()) {
        return false;
    }

    $endpoint = PLATFORM_API_URL;

    $payload['event_type'] = $eventType;
    $payload['source'] = 'farebros_status_offsite';
    $payload['sent_at'] = date('c');

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        log_platform_sync($eventType, $endpoint, null, false, null, 'Failed to encode JSON payload.');
        return false;
    }

    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-FareBros-Status-Key: ' . PLATFORM_API_KEY,
            'User-Agent: FareBrosStatus/1.0',
        ],
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $success = $curlError === '' && $httpCode >= 200 && $httpCode < 300;

    log_platform_sync(
        $eventType,
        $endpoint,
        $httpCode ?: null,
        $success,
        is_string($responseBody) ? mb_substr($responseBody, 0, 2000) : null,
        $curlError !== '' ? $curlError : null
    );

    return $success;
}

function sync_status_snapshot_to_platform(string $eventType = 'status_snapshot'): bool
{
    return post_json_to_platform($eventType, public_status_payload());
}

function get_sync_logs(int $limit = 25): array
{
    create_sync_table_if_needed();

    $stmt = db()->prepare('
        SELECT *
        FROM platform_sync_log
        ORDER BY created_at DESC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function require_status_read_key(): void
{
    $provided = $_SERVER['HTTP_X_FAREBROS_STATUS_READ_KEY'] ?? ($_GET['key'] ?? '');

    if (!STATUS_READ_API_KEY || STATUS_READ_API_KEY === 'change-this-read-secret') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'STATUS_READ_API_KEY is not configured yet.'
        ]);
        exit;
    }

    if (!hash_equals(STATUS_READ_API_KEY, (string)$provided)) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Unauthorized.'
        ]);
        exit;
    }
}
