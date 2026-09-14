<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/integration.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;
$syncNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_announcement') {
            $title = trim((string)($_POST['announcement_title'] ?? ''));
            $body = trim((string)($_POST['announcement_body'] ?? ''));
            if ($title === '' || $body === '') {
                throw new RuntimeException('Announcement title and message are required.');
            }
            $stmt = db()->prepare('INSERT INTO announcements (title, body, is_active, created_by) VALUES (?, ?, 1, ?)');
            $stmt->execute([$title, $body, (int)$user['id']]);
            $id = (int)db()->lastInsertId();
            $notice = 'Announcement published.';
            audit_admin_action($user, 'add_announcement', 'announcement', $id, $title);
        }

        if ($action === 'toggle_announcement') {
            $id = (int)($_POST['announcement_id'] ?? 0);
            $stmt = db()->prepare('SELECT title, is_active FROM announcements WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Announcement not found.');
            }
            $newState = (int)$row['is_active'] === 1 ? 0 : 1;
            db()->prepare('UPDATE announcements SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$newState, $id]);
            $notice = $newState === 1 ? 'Announcement is visible again.' : 'Announcement hidden from the public page.';
            audit_admin_action($user, $newState === 1 ? 'show_announcement' : 'hide_announcement', 'announcement', $id, (string)$row['title']);
        }

        if ($action === 'delete_announcement') {
            $id = (int)($_POST['announcement_id'] ?? 0);
            $stmt = db()->prepare('SELECT title FROM announcements WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $title = (string)($stmt->fetchColumn() ?: 'Announcement');
            db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
            $notice = 'Announcement deleted permanently.';
            audit_admin_action($user, 'delete_announcement', 'announcement', $id, $title);
        }

        if ($action !== '' && platform_sync_enabled()) {
            $syncNotice = sync_status_snapshot_to_platform('announcement_management')
                ? 'Portal sync sent.'
                : 'Announcement change saved, but portal sync failed or was skipped.';
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$announcements = db()->query('SELECT * FROM announcements ORDER BY created_at DESC, id DESC')->fetchAll();
$activeAnnouncements = array_values(array_filter($announcements, static fn(array $row): bool => (int)$row['is_active'] === 1));
$hiddenAnnouncements = array_values(array_filter($announcements, static fn(array $row): bool => (int)$row['is_active'] !== 1));

admin_page_start(
    'announcements',
    'Announcements',
    'Publish visitor-facing notices and keep old announcements organized instead of deleting useful history.',
    'Status Management',
    [
        ['href' => '#add', 'label' => 'New Announcement', 'class' => 'primary'],
        ['href' => '/', 'label' => 'View Public Page', 'class' => 'ghost', 'external' => true],
    ]
);
admin_status_tabs('announcements');
admin_notice($notice);
if ($syncNotice) admin_notice($syncNotice, str_contains(strtolower($syncNotice), 'failed') ? 'danger' : 'success');
admin_notice($error, 'danger');
?>

<section class="v54-metrics">
    <article class="v54-metric"><div class="v54-metric-top"><small>Total Announcements</small><span class="v54-status-dot good"></span></div><strong><?= count($announcements) ?></strong><em>Full announcement history</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Visible Now</small><span class="v54-status-dot <?= $activeAnnouncements ? 'good' : 'warn' ?>"></span></div><strong><?= count($activeAnnouncements) ?></strong><em>Currently shown publicly</em></article>
    <article class="v54-metric"><div class="v54-metric-top"><small>Hidden History</small><span class="v54-status-dot"></span></div><strong><?= count($hiddenAnnouncements) ?></strong><em>Available to show again</em></article>
</section>

<section class="v54-grid" id="add">
    <article class="pro-card v54-create-card">
        <div class="pro-card-head"><div><h2>Publish Announcement</h2><p>New announcements are shown immediately on the public status page.</p></div><span class="pro-chip">Public</span></div>
        <form method="post" class="pro-form v54-card-body">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_announcement">
            <label>Title</label><input type="text" name="announcement_title" maxlength="180" placeholder="Scheduled network maintenance" required>
            <label>Message</label><textarea name="announcement_body" rows="5" placeholder="Give visitors the information they need to know…" required></textarea>
            <div class="pro-actions"><button class="button primary" type="submit">Publish Announcement</button></div>
        </form>
    </article>

    <article class="pro-card">
        <div class="pro-card-head"><div><h2>Announcement Workflow</h2><p>Keep old notices without cluttering the public page.</p></div></div>
        <div class="v54-card-body v54-compact-list">
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Show</strong><small>The announcement is visible to visitors.</small></div><span class="v54-status-pill">Visible</span></div></div>
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Hide</strong><small>Removes it from the public page but keeps it in history.</small></div><span class="v54-status-pill warn">Hidden</span></div></div>
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Show Again</strong><small>Any hidden announcement can be restored with one click.</small></div></div></div>
            <div class="v54-compact-row"><div class="v54-row-main"><div><strong>Delete</strong><small>Permanently removes the announcement. Use Hide when you may need it later.</small></div></div></div>
        </div>
    </article>
</section>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Visible Announcements</h2><p>These announcements are currently displayed on the public status page.</p></div><span class="v54-count"><?= count($activeAnnouncements) ?> visible</span></div>
    <div class="v54-card-body v54-announcement-list">
        <?php if (!$activeAnnouncements): ?><div class="v54-empty">Nothing is currently being announced.</div><?php endif; ?>
        <?php foreach ($activeAnnouncements as $item): ?>
            <article class="v54-announcement-row">
                <div><h3><?= e((string)$item['title']) ?></h3><p><?= nl2br(e((string)$item['body'])) ?></p><small>Published <?= e(format_dt($item['created_at'])) ?><?= $item['updated_at'] !== $item['created_at'] ? ' · Updated ' . e(format_dt($item['updated_at'])) : '' ?></small></div>
                <div class="v54-row-actions">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_announcement"><input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>"><button class="button ghost small" type="submit">Hide</button></form>
                    <form method="post" data-confirm="Delete this announcement permanently?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>"><button class="button danger small" type="submit">Delete</button></form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="pro-card pro-card-full" style="margin-top:16px">
    <div class="pro-card-head"><div><h2>Previous / Hidden Announcements</h2><p>Old notices stay here until you choose to show them again or delete them permanently.</p></div><span class="v54-count"><?= count($hiddenAnnouncements) ?> hidden</span></div>
    <div class="v54-card-body">
        <div class="v54-toolbar">
            <div class="v54-search"><input type="search" placeholder="Search announcement history…" data-filter-input="#announcementHistory" aria-label="Search announcement history"></div>
        </div>
        <div class="v54-announcement-list" id="announcementHistory">
            <?php if (!$hiddenAnnouncements): ?><div class="v54-empty">No hidden announcement history yet.</div><?php endif; ?>
            <?php foreach ($hiddenAnnouncements as $item): ?>
                <article class="v54-announcement-row" data-filter-row="<?= e((string)$item['title'] . ' ' . (string)$item['body']) ?>">
                    <div><h3><?= e((string)$item['title']) ?></h3><p><?= nl2br(e((string)$item['body'])) ?></p><small>Originally published <?= e(format_dt($item['created_at'])) ?> · Hidden <?= e(format_dt($item['updated_at'])) ?></small></div>
                    <div class="v54-row-actions">
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_announcement"><input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>"><button class="button primary small" type="submit">Show Again</button></form>
                        <form method="post" data-confirm="Delete this old announcement permanently?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>"><button class="button danger small" type="submit">Delete</button></form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php admin_page_end(); ?>
