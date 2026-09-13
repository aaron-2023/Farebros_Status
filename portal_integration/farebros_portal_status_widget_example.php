<?php
/**
 * OPTIONAL EXAMPLE WIDGET FOR YOUR MAIN FARE BROS PLATFORM / PORTAL
 *
 * This reads directly from the offsite status page.
 * If the offsite status page cannot be reached, it fails quietly and does not break the portal.
 */

$statusUrl = 'https://status.farebros.com/api/platform-status.php';
$readKey = 'change-this-read-secret'; // Must match STATUS_READ_API_KEY in offsite status config.php

function farebros_fetch_status_widget(string $statusUrl, string $readKey): ?array
{
    $ch = curl_init($statusUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'X-FareBros-Status-Read-Key: ' . $readKey,
            'User-Agent: FareBrosPortal/1.0',
        ],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($error || $code < 200 || $code >= 300 || !$body) {
        return null;
    }

    $json = json_decode($body, true);

    if (!is_array($json) || empty($json['ok'])) {
        return null;
    }

    return $json['data'] ?? null;
}

$status = farebros_fetch_status_widget($statusUrl, $readKey);

if ($status):
    $overall = $status['overall'];
?>
    <div class="farebros-status-widget farebros-status-<?= htmlspecialchars($overall['class']) ?>">
        <strong>System Status:</strong>
        <span><?= htmlspecialchars($overall['label']) ?></span>
        <small>Code <?= (int)$overall['code'] ?></small>
        <a href="https://status.farebros.com" target="_blank" rel="noopener">View details</a>
    </div>
<?php else: ?>
    <div class="farebros-status-widget farebros-status-unknown">
        <strong>System Status:</strong>
        <span>Status currently unavailable</span>
        <a href="https://status.farebros.com" target="_blank" rel="noopener">View status page</a>
    </div>
<?php endif; ?>
