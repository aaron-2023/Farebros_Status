<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/functions.php';

$payload = public_status_payload();

echo "Overall: {$payload['overall']['label']} / Code {$payload['overall']['code']}\n";
echo "Primary: {$payload['primary']['name']} - {$payload['primary']['label']}\n\n";

echo "Websites:\n";
foreach ($payload['websites'] as $w) {
    echo "- {$w['name']} | primary=" . ($w['is_primary'] ? 'yes' : 'no') . " | {$w['label']}\n";
}

echo "\nServices:\n";
foreach ($payload['services'] as $s) {
    echo "- {$s['name']} | {$s['label']}\n";
}
