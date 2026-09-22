<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/platform.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$command = strtolower(trim((string)($argv[1] ?? 'status')));

try {
    switch ($command) {
        case 'status':
            $result = cloudflare_failover_status(true);
            break;

        case 'prepare':
            if (!cloudflare_failover_configured()) {
                throw new RuntimeException('Cloudflare failover is not fully configured.');
            }
            $before = cloudflare_failover_get_remote_rule();
            $enabled = !empty($before['enabled']);
            $after = cloudflare_failover_patch_rule($enabled);
            cloudflare_failover_refresh_remote_state(true);
            $result = [
                'ok' => true,
                'message' => 'Redirect rule prepared. Existing enabled/disabled state was preserved.',
                'enabled' => !empty($after['enabled']),
                'expression' => (string)($after['expression'] ?? ''),
                'redirect_url' => (string)($after['action_parameters']['from_value']['target_url']['value'] ?? ''),
            ];
            break;

        case 'logs':
            ensure_cloudflare_failover_schema();
            $limit = max(1, min(100, (int)($argv[2] ?? 20)));
            $stmt = db()->prepare('SELECT * FROM cloudflare_failover_log ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $result = ['ok' => true, 'logs' => $stmt->fetchAll()];
            break;

        default:
            throw new InvalidArgumentException('Usage: php tools/cloudflare-failover.php [status|prepare|logs]');
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $ex) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $ex->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
