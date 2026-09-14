<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        $id = (int)($_POST['subscriber_id'] ?? 0);
        if ($action === 'delete_subscriber') {
            delete_status_subscriber($id);
            $notice = 'Subscriber deleted.';
            audit_admin_action($user, 'delete_subscriber', 'subscriber', $id);
        } elseif ($action === 'toggle_subscriber') {
            $makeActive = !empty($_POST['make_active']) ? 1 : 0;
            db()->prepare('UPDATE status_subscribers SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$makeActive, $id]);
            $notice = $makeActive ? 'Subscriber re-enabled.' : 'Subscriber disabled.';
            audit_admin_action($user, 'toggle_subscriber', 'subscriber', $id, 'active=' . $makeActive);
        } elseif ($action === 'resend_confirmation') {
            $stmt = db()->prepare('SELECT * FROM status_subscribers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $subscriber = $stmt->fetch();
            if (!$subscriber) throw new RuntimeException('Subscriber not found.');
            $token = bin2hex(random_bytes(24));
            db()->prepare('UPDATE status_subscribers SET confirm_token = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$token, $id]);
            $subscriber['confirm_token'] = $token;
            send_subscription_confirmation($subscriber);
            $notice = 'Confirmation email resent.';
            audit_admin_action($user, 'resend_subscription_confirmation', 'subscriber', $id);
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$subscribers = get_status_subscribers();
$verified = count(array_filter($subscribers, static fn(array $s): bool => (int)$s['is_verified'] === 1 && (int)$s['is_active'] === 1));
$pending = count(array_filter($subscribers, static fn(array $s): bool => (int)$s['is_verified'] !== 1 && (int)$s['is_active'] === 1));
$disabled = count(array_filter($subscribers, static fn(array $s): bool => (int)$s['is_active'] !== 1));
$smtpReady = get_setting('smtp_enabled','0') === '1';
$publicEnabled = get_setting('public_subscriptions_enabled','1') === '1';

admin_page_start('subscribers','Subscribers','Manage public email subscriptions for incidents, maintenance, and status changes.','Communication',[
    ['href'=>'/subscribe.php','label'=>'Public Subscribe Page','class'=>'ghost','external'=>true],
    ['href'=>'/admin/settings.php','label'=>'Notification Settings','class'=>'primary'],
]);
admin_notice($notice);
admin_notice($error,'danger');
?>
<section class="v55-summary-grid">
    <article class="v55-summary-card <?= $verified?'good':'' ?>"><small>Active Subscribers</small><strong><?= $verified ?></strong><span>verified and enabled</span></article>
    <article class="v55-summary-card <?= $pending?'warn':'' ?>"><small>Pending</small><strong><?= $pending ?></strong><span>awaiting email confirmation</span></article>
    <article class="v55-summary-card"><small>Disabled</small><strong><?= $disabled ?></strong><span>unsubscribed or paused</span></article>
    <article class="v55-summary-card <?= $smtpReady && $publicEnabled?'good':'warn' ?>"><small>Public Signup</small><strong><?= $publicEnabled?'Enabled':'Disabled' ?></strong><span><?= $smtpReady?'SMTP ready':'SMTP must be configured' ?></span></article>
</section>

<div class="v55-page-intro"><div><strong>Subscriptions use email confirmation and one-click unsubscribe links.</strong><p>Visitors can subscribe to incidents, maintenance, general status changes, and optionally narrow notifications to specific websites.</p></div><a class="button ghost" href="/admin/settings.php">Configure SMTP</a></div>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Subscriber Directory</h2><p>Search, pause, resend confirmation, or permanently remove subscribers.</p></div><span class="v54-count"><?= count($subscribers) ?> total</span></div>
    <div class="v55-card-body">
        <div class="v54-toolbar"><div class="v54-search"><input type="search" placeholder="Search subscribers…" data-filter-input="#subscriberTable"></div><span class="v54-count">Private admin-only list</span></div>
        <div class="v55-table-wrap"><table class="v55-table" id="subscriberTable"><thead><tr><th>Email</th><th>Status</th><th>Preferences</th><th>Joined</th><th>Actions</th></tr></thead><tbody>
        <?php if(!$subscribers): ?><tr><td colspan="5"><div class="v55-empty-state"><strong>No subscribers yet</strong><p>Once SMTP is configured and public subscriptions are enabled, visitors can sign up from the public status page.</p></div></td></tr><?php endif; ?>
        <?php foreach($subscribers as $subscriber): $prefs=subscriber_preferences((int)$subscriber['id']); $prefLabels=[]; foreach($prefs as $pref){$label=ucfirst($pref['event_type']); if($pref['target_type']==='website')$label.=' · '.target_label('website',(int)$pref['target_id']); $prefLabels[]=$label;} ?>
            <tr data-filter-row="<?= e(strtolower((string)$subscriber['email'].' '.implode(' ',$prefLabels))) ?>">
                <td class="v55-email"><strong><?= e($subscriber['email']) ?></strong><small>ID #<?= (int)$subscriber['id'] ?></small></td>
                <td><div class="v55-subscriber-status"><?php if((int)$subscriber['is_active']!==1): ?><span class="v55-pill muted">Disabled</span><?php elseif((int)$subscriber['is_verified']===1): ?><span class="v55-pill">Verified</span><?php else: ?><span class="v55-pill warn">Pending</span><?php endif; ?></div></td>
                <td><strong><?= count($prefs) ?> rule<?= count($prefs)===1?'':'s' ?></strong><small><?= e(implode(' · ', array_slice($prefLabels,0,3))) ?><?= count($prefLabels)>3?' …':'' ?></small></td>
                <td><strong><?= e(format_dt($subscriber['created_at'])) ?></strong><?php if($subscriber['verified_at']): ?><small>Verified <?= e(format_dt($subscriber['verified_at'])) ?></small><?php endif; ?></td>
                <td><div class="v55-inline-actions">
                    <?php if((int)$subscriber['is_verified']!==1 && (int)$subscriber['is_active']===1): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resend_confirmation"><input type="hidden" name="subscriber_id" value="<?= (int)$subscriber['id'] ?>"><button class="button ghost small" type="submit">Resend</button></form><?php endif; ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_subscriber"><input type="hidden" name="subscriber_id" value="<?= (int)$subscriber['id'] ?>"><input type="hidden" name="make_active" value="<?= (int)$subscriber['is_active']===1?'0':'1' ?>"><button class="button ghost small" type="submit"><?= (int)$subscriber['is_active']===1?'Disable':'Enable' ?></button></form>
                    <form method="post" data-confirm="Permanently delete this subscriber and all preferences?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_subscriber"><input type="hidden" name="subscriber_id" value="<?= (int)$subscriber['id'] ?>"><button class="button danger small" type="submit">Delete</button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</section>
<?php admin_page_end(); ?>
