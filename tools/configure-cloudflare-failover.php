<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run this setup tool as root so it can write /etc/farebros/status-cloudflare.php.\n");
    exit(1);
}

$path = '/etc/farebros/status-cloudflare.php';
$dir = dirname($path);

fwrite(STDOUT, "FareBros Status - Cloudflare Auto Failover Setup\n");
fwrite(STDOUT, "This stores the API token outside /var/www/status.\n\n");
fwrite(STDOUT, 'Cloudflare API token: ');

$echoDisabled = false;
if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
    @shell_exec('stty -echo 2>/dev/null');
    $echoDisabled = true;
    register_shutdown_function(static function (): void {
        @shell_exec('stty echo 2>/dev/null');
    });
}
$token = trim((string)fgets(STDIN));
if ($echoDisabled) {
    @shell_exec('stty echo 2>/dev/null');
    $echoDisabled = false;
}
fwrite(STDOUT, PHP_EOL);

if ($token === '') {
    fwrite(STDERR, "No token entered. Nothing was changed.\n");
    exit(1);
}

if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Unable to create {$dir}.\n");
    exit(1);
}
if (function_exists('posix_getgrnam')) {
    $group = posix_getgrnam('www-data');
    if (is_array($group) && isset($group['gid'])) {
        @chgrp($dir, (int)$group['gid']);
    }
}
@chmod($dir, 0750);

$config = [
    'enabled' => true,
    'api_token' => $token,
    'zone_id' => 'f2d3aa7c24ba6f22a3fdbb2f54ae2d68',
    'ruleset_id' => '85e2c55f764c4fbc9587a25c0c521094',
    'rule_id' => 'b7c0b0de38ef42d6b604866e09d14dae',
    'redirect_url' => 'https://status.farebros.com',
    'hosts' => ['farebros.com', 'www.farebros.com'],
    'failure_threshold' => 3,
    'recovery_threshold' => 3,
    'state_refresh_seconds' => 300,
    'monitor_user_agent_prefix' => 'FareBrosStatusMonitor/',
];

$content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
$temp = $path . '.tmp';
if (file_put_contents($temp, $content, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write temporary configuration file.\n");
    exit(1);
}
@chmod($temp, 0640);
if (function_exists('posix_getgrnam')) {
    $group = posix_getgrnam('www-data');
    if (is_array($group) && isset($group['gid'])) {
        @chgrp($temp, (int)$group['gid']);
    }
}
if (!rename($temp, $path)) {
    @unlink($temp);
    fwrite(STDERR, "Unable to install {$path}.\n");
    exit(1);
}
@chmod($path, 0640);

fwrite(STDOUT, "Configuration installed: {$path}\n");
fwrite(STDOUT, "Token was not printed or stored in the website directory.\n");
fwrite(STDOUT, "Next: php /var/www/status/tools/cloudflare-failover.php prepare\n");
