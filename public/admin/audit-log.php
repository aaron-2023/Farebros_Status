<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/_layout.php';
$user = require_login();
$rows = get_audit_log(300);

admin_page_start(
    'audit',
    'Audit Log',
    'Review administrative changes and automatic housekeeping activity.',
    'Administration'
);
?>
<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Recent Activity</h2><p>Latest <?= count($rows) ?> audit events. Passwords, SMTP secrets, webhook URLs, and form contents are never written here.</p></div><span class="v54-count"><?= count($rows) ?> events</span></div>
    <div class="v54-card-body">
        <div class="audit-table">
            <div class="audit-row audit-head"><span>Time</span><span>User</span><span>Action</span><span>Target</span><span>Details</span><span>IP</span></div>
            <?php if (!$rows): ?><p class="empty">No audit events yet.</p><?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <div class="audit-row"><span><?= e(format_dt($row['created_at'])) ?></span><span><?= e($row['username'] ?: 'system') ?></span><span><strong><?= e($row['action']) ?></strong></span><span><?= e(trim(($row['target_type'] ?? '') . ($row['target_id'] ? ' #' . $row['target_id'] : ''))) ?></span><span><?= e($row['details'] ?? '') ?></span><span><?= e($row['ip_address'] ?? '') ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php admin_page_end(); ?>
