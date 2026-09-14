<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function ensure_notification_schema(): void
{
    ensure_core_schema();
    db()->exec('
        CREATE TABLE IF NOT EXISTS notification_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL,
            event_type TEXT,
            subject TEXT,
            destination TEXT,
            success INTEGER NOT NULL DEFAULT 0,
            response TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_notification_log_created ON notification_log(created_at DESC)');
}

function smtp_read_response($socket): array
{
    $lines = [];
    $code = 0;
    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return [$code, implode("\n", $lines)];
}

function smtp_command($socket, string $command, array $expected): string
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP error ' . $code . ': ' . $response);
    }
    return $response;
}

function smtp_safe_header(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function smtp_send(string $to, string $subject, string $body): array
{
    $host = trim((string)get_setting('smtp_host', ''));
    $port = max(1, (int)get_setting('smtp_port', '587'));
    $security = strtolower(trim((string)get_setting('smtp_security', 'tls')));
    $username = trim((string)get_setting('smtp_username', ''));
    $password = (string)get_setting('smtp_password', '');
    $fromEmail = trim((string)get_setting('smtp_from_email', 'status@farebros.com'));
    $fromName = trim((string)get_setting('smtp_from_name', 'Fare Brothers Status'));

    if ($host === '' || $to === '' || $fromEmail === '') {
        throw new RuntimeException('SMTP host, From address, and destination address are required.');
    }

    $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException('Could not connect to SMTP server: ' . ($errstr ?: 'connection failed'));
    }

    stream_set_timeout($socket, 15);

    try {
        smtp_command($socket, '', [220]);
        $helloHost = parse_url(APP_URL, PHP_URL_HOST) ?: 'status.farebros.com';
        smtp_command($socket, 'EHLO ' . $helloHost, [250]);

        if ($security === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . $helloHost, [250]);
        }

        if ($username !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($username), [334]);
            smtp_command($socket, base64_encode($password), [235]);
        }

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . trim($to) . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $safeSubject = smtp_safe_header($subject);
        $safeFromName = smtp_safe_header($fromName);
        $safeTo = smtp_safe_header($to);
        $hostname = parse_url(APP_URL, PHP_URL_HOST) ?: 'status.farebros.com';
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $hostname . '>';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($safeFromName !== '' ? '"' . addcslashes($safeFromName, '"\\') . '" ' : '') . '<' . $fromEmail . '>',
            'To: ' . $safeTo,
            'Subject: ' . $safeSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $normalizedBody = str_replace("\n.", "\n..", $normalizedBody);
        $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $normalizedBody) . "\r\n.";
        smtp_command($socket, $data, [250]);
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        return ['ok' => true, 'message' => 'Email accepted by SMTP server.'];
    } catch (Throwable $ex) {
        @fclose($socket);
        throw $ex;
    }
}

function discord_send(string $subject, string $message, string $severity = 'info'): array
{
    $url = trim((string)get_setting('discord_webhook_url', ''));
    if ($url === '') {
        throw new RuntimeException('Discord webhook URL is not configured.');
    }
    if (!preg_match('~^https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api/webhooks/~i', $url)) {
        throw new RuntimeException('Discord webhook URL does not look valid.');
    }

    $username = trim((string)get_setting('discord_username', 'Fare Brothers Status')) ?: 'Fare Brothers Status';
    $color = match ($severity) {
        'critical', 'offline' => 15158332,
        'warning', 'degraded' => 16753920,
        'resolved', 'operational' => 3066993,
        default => 3447003,
    };

    $payload = [
        'username' => $username,
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => mb_substr($subject, 0, 250),
            'description' => mb_substr($message, 0, 3900),
            'color' => $color,
            'timestamp' => gmdate(DateTimeInterface::ATOM),
            'footer' => ['text' => 'Fare Brothers Status'],
        ]],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_USERAGENT => 'FareBrosStatus/5.5',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '' || $code < 200 || $code >= 300) {
        throw new RuntimeException('Discord webhook failed' . ($code ? ' (HTTP ' . $code . ')' : '') . ($error ? ': ' . $error : '.'));
    }

    return ['ok' => true, 'message' => 'Discord webhook delivered.'];
}

function notification_log_write(string $channel, string $eventType, string $subject, string $destination, bool $success, string $response): void
{
    ensure_notification_schema();
    db()->prepare('INSERT INTO notification_log (channel, event_type, subject, destination, success, response) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$channel, $eventType, $subject, $destination, $success ? 1 : 0, substr($response, 0, 2000)]);
}

function send_status_notifications(string $subject, string $message, string $severity = 'info', string $eventType = 'status_change', array $context = []): array
{
    ensure_notification_schema();
    $results = [];

    if (get_setting('alert_email_enabled', '0') === '1' && get_setting('smtp_enabled', '0') === '1') {
        $destinations = preg_split('/[;,\s]+/', (string)get_setting('alert_email_to', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_unique($destinations) as $to) {
            try {
                $result = smtp_send($to, $subject, $message . "\n\nStatus page: " . APP_URL);
                notification_log_write('email', $eventType, $subject, $to, true, $result['message']);
                $results[] = ['channel' => 'email', 'destination' => $to, 'ok' => true];
            } catch (Throwable $ex) {
                notification_log_write('email', $eventType, $subject, $to, false, $ex->getMessage());
                $results[] = ['channel' => 'email', 'destination' => $to, 'ok' => false, 'error' => $ex->getMessage()];
            }
        }
    }

    if (get_setting('discord_enabled', '0') === '1') {
        $destination = 'Discord webhook';
        try {
            $result = discord_send($subject, $message . "\n\n" . APP_URL, $severity);
            notification_log_write('discord', $eventType, $subject, $destination, true, $result['message']);
            $results[] = ['channel' => 'discord', 'destination' => $destination, 'ok' => true];
        } catch (Throwable $ex) {
            notification_log_write('discord', $eventType, $subject, $destination, false, $ex->getMessage());
            $results[] = ['channel' => 'discord', 'destination' => $destination, 'ok' => false, 'error' => $ex->getMessage()];
        }
    }

    // v5.5 optional public subscriber delivery and general outbound webhook.
    // These functions live in app/platform.php; function_exists keeps v5.3/v5.4
    // compatibility for code paths that load notifications independently.
    if (function_exists('dispatch_subscriber_notifications')) {
        foreach (dispatch_subscriber_notifications($subject, $message, $eventType, $context) as $subscriberResult) {
            $results[] = ['channel' => 'subscriber_email'] + $subscriberResult;
        }
    }
    if (function_exists('dispatch_outbound_webhook')) {
        $webhookResult = dispatch_outbound_webhook($subject, $message, $severity, $eventType, $context);
        if ($webhookResult) $results[] = ['channel' => 'outbound_webhook'] + $webhookResult;
    }

    return $results;
}

function get_recent_notification_log(int $limit = 50): array
{
    ensure_notification_schema();
    $stmt = db()->prepare('SELECT * FROM notification_log ORDER BY created_at DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
