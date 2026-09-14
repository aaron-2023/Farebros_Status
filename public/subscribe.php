<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/platform.php';

$notice = null;
$error = null;
$action = (string)($_GET['action'] ?? '');
$token = (string)($_GET['token'] ?? '');

try {
    if ($action === 'confirm' && $token !== '') {
        $row = confirm_subscription($token);
        if (!$row) throw new RuntimeException('That confirmation link is invalid or has expired.');
        $notice = 'Subscription confirmed. You will now receive the status alerts you selected.';
    } elseif ($action === 'unsubscribe' && $token !== '') {
        $row = unsubscribe_status_email($token);
        if (!$row) throw new RuntimeException('That unsubscribe link is invalid.');
        $notice = 'You have been unsubscribed from Fare Brothers Status alerts.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (trim((string)($_POST['website'] ?? '')) !== '') throw new RuntimeException('Unable to process this request.'); // honeypot
        if (get_setting('public_subscriptions_enabled','1') !== '1') throw new RuntimeException('Public email subscriptions are currently disabled.');
        if (get_setting('smtp_enabled','0') !== '1') throw new RuntimeException('Email subscriptions are temporarily unavailable because email delivery is not configured.');
        $email = trim((string)($_POST['email'] ?? ''));
        $events = array_values(array_filter((array)($_POST['events'] ?? []), 'is_string'));
        $websiteIds = array_map('intval', (array)($_POST['website_ids'] ?? []));
        $subscription = create_or_update_subscription($email, $events, $websiteIds);
        if (!empty($subscription['already_verified'])) {
            $notice = 'Your subscription preferences have been updated.';
        } else {
            send_subscription_confirmation($subscription);
            $notice = 'Check your email for a confirmation link. Your subscription will not activate until you confirm it.';
        }
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

$websites = get_websites();
$enabled = get_setting('public_subscriptions_enabled','1') === '1';
$smtpReady = get_setting('smtp_enabled','0') === '1';
$company = (string)get_setting('company_name','Fare Brothers, LLC');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Status Alerts - <?= e($company) ?></title><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark">
<link rel="stylesheet" href="/assets/css/statuspage-v52.css?v=5.2.0"><link rel="stylesheet" href="/assets/css/statuspage-v53.css?v=5.3.0"><link rel="stylesheet" href="/assets/css/statuspage-v55.css?v=5.5.0">
</head>
<body class="status-page v55-public">
<header class="sp-top"><div class="sp-wrap sp-top-inner"><a href="/" class="sp-brand"><img src="/assets/img/fare-brothers-logo.png" alt="Fare Brothers logo"><span><strong>Fare Brothers</strong><small>Email Alerts</small></span></a><nav class="v55-public-nav"><a href="/">Current Status</a><a href="/history.php">History</a><a class="active" href="/subscribe.php">Subscribe</a></nav></div></header>
<main class="sp-wrap v55-subscribe-shell">
    <section class="v55-public-card">
        <div class="v55-public-card-head"><div><span class="v55-eyebrow">STATUS NOTIFICATIONS</span><h1>Get status alerts by email</h1><p>Choose the updates that matter to you. You can unsubscribe at any time from any alert email.</p></div><span class="v55-public-icon">✉</span></div>
        <?php if($notice): ?><div class="v55-public-notice good"><?= e($notice) ?></div><?php endif; ?>
        <?php if($error): ?><div class="v55-public-notice bad"><?= e($error) ?></div><?php endif; ?>
        <?php if(!$enabled || !$smtpReady): ?>
            <div class="v55-public-empty"><strong>Email subscriptions are not currently available.</strong><p><?= !$enabled ? 'Public subscriptions have been disabled by the status administrator.' : 'Email delivery is still being configured.' ?></p></div>
        <?php else: ?>
        <form method="post" class="v55-subscribe-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="v55-honeypot" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <label>Email address</label><input type="email" name="email" placeholder="you@example.com" required autocomplete="email">
            <fieldset><legend>Notify me about</legend><div class="v55-public-options">
                <label><input type="checkbox" name="events[]" value="incidents" checked><span><strong>Incidents</strong><small>New incidents, updates, and resolutions.</small></span></label>
                <label><input type="checkbox" name="events[]" value="maintenance" checked><span><strong>Maintenance</strong><small>Scheduled maintenance starting and completing.</small></span></label>
                <label><input type="checkbox" name="events[]" value="status"><span><strong>Status changes</strong><small>Other published service status changes.</small></span></label>
            </div></fieldset>
            <?php if($websites): ?><fieldset><legend>Website scope <small>Leave all unchecked for every website/service</small></legend><div class="v55-public-options"><?php foreach($websites as $website): ?><label><input type="checkbox" name="website_ids[]" value="<?= (int)$website['id'] ?>"><span><strong><?= e($website['website_name']) ?></strong><small><?= e($website['website_url']) ?></small></span></label><?php endforeach; ?></div></fieldset><?php endif; ?>
            <button type="submit" class="v55-public-button">Send confirmation email</button>
            <p class="v55-privacy-note">We use this address only for the status notifications you select. Confirmation is required before alerts begin.</p>
        </form>
        <?php endif; ?>
    </section>
</main>
<footer class="sp-footer sp-wrap"><span>© <?= date('Y') ?> <?= e($company) ?></span><span>FareBros Status</span></footer>
</body></html>
