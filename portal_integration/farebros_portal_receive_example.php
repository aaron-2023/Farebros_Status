<?php
/**
 * OPTIONAL EXAMPLE FILE FOR YOUR MAIN FARE BROS PLATFORM / PORTAL
 *
 * Suggested location on your portal:
 * /api/status/receive.php
 *
 * This receives push updates from the offsite status page.
 * The offsite status page does NOT depend on this. If this endpoint is down,
 * the status page simply logs the failed sync and keeps working.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$expectedKey = 'change-this-long-random-secret'; // Must match PLATFORM_API_KEY in offsite status config.php
$providedKey = $_SERVER['HTTP_X_FAREBROS_STATUS_KEY'] ?? '';

if (!$expectedKey || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

/*
    At this point, you can store the payload in your Fare Bros platform database.

    Recommended table:

    CREATE TABLE platform_status_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(100) NOT NULL,
        event_type VARCHAR(100) NOT NULL,
        payload_json MEDIUMTEXT NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    Example PDO insert:

    $stmt = $pdo->prepare("
        INSERT INTO platform_status_cache (source, event_type, payload_json)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $data['source'] ?? 'farebros_status_offsite',
        $data['event_type'] ?? 'status_snapshot',
        json_encode($data, JSON_UNESCAPED_SLASHES)
    ]);
*/

echo json_encode([
    'ok' => true,
    'message' => 'Status payload received.',
    'event_type' => $data['event_type'] ?? null,
    'overall' => $data['overall']['label'] ?? null,
]);
