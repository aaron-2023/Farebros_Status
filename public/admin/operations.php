<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$notice = null;
$error = null;

function parse_target_ref(string $value): array
{
    [$type, $id] = array_pad(explode(':', $value, 2), 2, '0');
    return [valid_target_type($type) ? $type : '', (int)$id];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_group') {
            $id = save_status_group(
                (int)($_POST['group_id'] ?? 0),
                (string)($_POST['group_name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                (int)($_POST['sort_order'] ?? 100),
                !empty($_POST['is_public'])
            );
            $notice = 'Service group saved.';
            audit_admin_action($user, 'save_group', 'status_group', $id);
        } elseif ($action === 'delete_group') {
            $id = (int)($_POST['group_id'] ?? 0);
            delete_status_group($id);
            $notice = 'Service group deleted. Services and websites were not deleted.';
            audit_admin_action($user, 'delete_group', 'status_group', $id);
        } elseif ($action === 'add_group_member') {
            $groupId = (int)($_POST['group_id'] ?? 0);
            [$type,$targetId] = parse_target_ref((string)($_POST['target_ref'] ?? ''));
            if ($type === '' || $targetId < 1) throw new RuntimeException('Choose a service or website to add.');
            add_status_group_member($groupId, $type, $targetId);
            $notice = 'Member added to group.';
            audit_admin_action($user, 'add_group_member', $type, $targetId, 'Group #' . $groupId);
        } elseif ($action === 'remove_group_member') {
            $groupId = (int)($_POST['group_id'] ?? 0);
            $type = (string)($_POST['target_type'] ?? '');
            $targetId = (int)($_POST['target_id'] ?? 0);
            remove_status_group_member($groupId, $type, $targetId);
            $notice = 'Member removed from group.';
            audit_admin_action($user, 'remove_group_member', $type, $targetId, 'Group #' . $groupId);
        } elseif ($action === 'add_dependency') {
            [$targetType,$targetId] = parse_target_ref((string)($_POST['target_ref'] ?? ''));
            [$upstreamType,$upstreamId] = parse_target_ref((string)($_POST['upstream_ref'] ?? ''));
            $id = add_status_dependency($targetType, $targetId, $upstreamType, $upstreamId, (string)($_POST['behavior'] ?? 'degraded'));
            $notice = 'Dependency added.';
            audit_admin_action($user, 'add_dependency', 'dependency', $id, target_label($targetType,$targetId) . ' depends on ' . target_label($upstreamType,$upstreamId));
        } elseif ($action === 'delete_dependency') {
            $id = (int)($_POST['dependency_id'] ?? 0);
            delete_status_dependency($id);
            $notice = 'Dependency removed.';
            audit_admin_action($user, 'delete_dependency', 'dependency', $id);
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$groups = get_status_groups(false);
$dependencies = get_status_dependencies();
$services = get_services();
$websites = get_websites();
$targets = [];
foreach ($services as $s) $targets[] = ['ref'=>'service:'.(int)$s['id'],'type'=>'Service','name'=>$s['service_name'],'status'=>$s['current_status']];
foreach ($websites as $w) $targets[] = ['ref'=>'website:'.(int)$w['id'],'type'=>'Website','name'=>$w['website_name'],'status'=>$w['current_status']];
$groupedKeys = [];
foreach ($groups as $g) foreach ($g['members'] as $m) $groupedKeys[dependency_key((string)$m['target_type'],(int)$m['target_id'])] = true;
$ungrouped = array_values(array_filter($targets, static function(array $t) use ($groupedKeys): bool { [$type,$id]=explode(':',$t['ref']); return empty($groupedKeys[dependency_key($type,(int)$id)]); }));

admin_page_start('operations','Groups & Dependencies','Organize the public status page and define upstream relationships without changing the underlying monitors.','Status Management',[
    ['href'=>'/admin/websites.php','label'=>'Websites','class'=>'ghost'],
    ['href'=>'/','label'=>'View Public Page','class'=>'primary','external'=>true],
]);
admin_status_tabs('operations');
admin_notice($notice);
admin_notice($error,'danger');
?>
<section class="v55-summary-grid">
    <article class="v55-summary-card"><small>Groups</small><strong><?= count($groups) ?></strong><span><?= count(array_filter($groups,fn($g)=>(int)$g['is_public']===1)) ?> public</span></article>
    <article class="v55-summary-card"><small>Dependencies</small><strong><?= count($dependencies) ?></strong><span>upstream relationships</span></article>
    <article class="v55-summary-card"><small>Tracked Items</small><strong><?= count($targets) ?></strong><span><?= count($services) ?> services · <?= count($websites) ?> websites</span></article>
    <article class="v55-summary-card <?= $ungrouped ? 'warn':'good' ?>"><small>Ungrouped</small><strong><?= count($ungrouped) ?></strong><span><?= $ungrouped ? 'available to organize' : 'everything organized' ?></span></article>
</section>

<div class="v55-page-intro"><div><strong>Groups control presentation. Dependencies control effective status.</strong><p>A dependency never overwrites the monitor's actual result. It only changes the effective public status when an upstream component is unhealthy.</p></div></div>

<section class="v55-section-grid">
    <article class="pro-card">
        <div class="pro-card-head"><div><h2>Create Service Group</h2><p>Group related websites and services into clean public sections.</p></div></div>
        <div class="v55-card-body">
            <form method="post" class="pro-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_group">
                <label>Group name</label><input type="text" name="group_name" placeholder="Customer Services" required>
                <label>Description</label><input type="text" name="description" placeholder="Public websites and customer-facing systems">
                <div class="pro-form-row"><div><label>Sort order</label><input type="number" name="sort_order" value="100"></div><div><label>&nbsp;</label><label class="pro-check"><input type="checkbox" name="is_public" value="1" checked>Show on public status page</label></div></div>
                <div class="pro-actions"><button class="button primary" type="submit">Create Group</button></div>
            </form>
        </div>
    </article>

    <article class="pro-card">
        <div class="pro-card-head"><div><h2>Add Dependency</h2><p>Example: Website depends on Database. If Database fails, Website can automatically appear degraded.</p></div></div>
        <div class="v55-card-body">
            <form method="post" class="pro-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_dependency">
                <label>Dependent item</label><select name="target_ref" required><option value="">Choose item…</option><?php foreach($targets as $t): ?><option value="<?= e($t['ref']) ?>"><?= e($t['type'].' · '.$t['name']) ?></option><?php endforeach; ?></select>
                <label>Depends on</label><select name="upstream_ref" required><option value="">Choose upstream item…</option><?php foreach($targets as $t): ?><option value="<?= e($t['ref']) ?>"><?= e($t['type'].' · '.$t['name']) ?></option><?php endforeach; ?></select>
                <label>When upstream is unhealthy</label><select name="behavior"><option value="degraded">Mark dependent as Degraded</option><option value="inherit">Inherit the upstream severity</option></select>
                <small class="pro-field-hint">Circular dependencies are blocked automatically.</small>
                <div class="pro-actions"><button class="button primary" type="submit">Add Dependency</button></div>
            </form>
        </div>
    </article>
</section>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Service Groups</h2><p>Manage group visibility and membership without editing the underlying service or website.</p></div><span class="v54-count"><?= count($groups) ?> groups</span></div>
    <div class="v55-card-body v55-group-list">
        <?php if(!$groups): ?><div class="v55-empty-state"><strong>No groups yet</strong><p>Create the first group above. Ungrouped items still appear normally on the public status page.</p></div><?php endif; ?>
        <?php foreach($groups as $group): $meta=$group['status_meta']; ?>
            <article class="v55-group-card">
                <div class="v55-group-head"><div><h3><?= e($group['group_name']) ?></h3><p><?= e($group['description'] ?: 'No description') ?></p></div><div class="v55-inline-actions"><span class="v55-pill <?= $meta['class']==='bad'?'bad':($meta['class']==='good'?'':'warn') ?>"><?= e($meta['label']) ?></span><span class="v55-pill muted"><?= (int)$group['is_public']===1?'Public':'Admin only' ?></span></div></div>
                <div class="v55-group-members">
                    <?php foreach($group['members'] as $member): ?>
                        <span class="v55-member-chip"><b><?= e($member['name']) ?></b><small><?= e(ucfirst($member['target_type'])) ?></small><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_group_member"><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><input type="hidden" name="target_type" value="<?= e($member['target_type']) ?>"><input type="hidden" name="target_id" value="<?= (int)$member['target_id'] ?>"><button title="Remove from group" type="submit">×</button></form></span>
                    <?php endforeach; ?>
                    <?php if(!$group['members']): ?><span class="v55-card-note">No members yet.</span><?php endif; ?>
                </div>
                <details class="v54-edit-panel"><summary>Edit group & members</summary>
                    <div class="v55-section-grid">
                        <form method="post" class="pro-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_group"><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><label>Name</label><input type="text" name="group_name" value="<?= e($group['group_name']) ?>" required><label>Description</label><input type="text" name="description" value="<?= e($group['description']) ?>"><div class="pro-form-row"><div><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$group['sort_order'] ?>"></div><div><label>&nbsp;</label><label class="pro-check"><input type="checkbox" name="is_public" value="1" <?= (int)$group['is_public']===1?'checked':'' ?>>Public group</label></div></div><div class="pro-actions"><button class="button primary" type="submit">Save Group</button></div></form>
                        <div><form method="post" class="pro-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_group_member"><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><label>Add member</label><select name="target_ref" required><option value="">Choose item…</option><?php foreach($targets as $t): ?><option value="<?= e($t['ref']) ?>"><?= e($t['type'].' · '.$t['name']) ?></option><?php endforeach; ?></select><div class="pro-actions"><button class="button ghost" type="submit">Add to Group</button></div></form><form method="post" data-confirm="Delete this group? Services and websites will remain intact."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><button class="button danger small" type="submit">Delete Group</button></form></div>
                    </div>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="pro-card pro-card-full">
    <div class="pro-card-head"><div><h2>Dependencies</h2><p>Effective status relationships currently enforced on the public status payload.</p></div><span class="v54-count"><?= count($dependencies) ?> rules</span></div>
    <div class="v55-card-body v55-dependency-list">
        <?php if(!$dependencies): ?><div class="v55-empty-state"><strong>No dependencies configured</strong><p>That is perfectly fine. Add one only when a service genuinely relies on another tracked component.</p></div><?php endif; ?>
        <?php foreach($dependencies as $dep): ?>
            <div class="v55-dependency-row"><div><strong><?= e($dep['target_name']) ?></strong><small><?= e(ucfirst($dep['target_type'])) ?></small></div><div class="arrow">depends on →<br><span class="v55-pill muted"><?= e($dep['behavior']==='inherit'?'Inherit':'Degrade') ?></span></div><div><strong><?= e($dep['upstream_name']) ?></strong><small><?= e(ucfirst($dep['upstream_type'])) ?></small></div><form method="post" data-confirm="Remove this dependency?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_dependency"><input type="hidden" name="dependency_id" value="<?= (int)$dep['id'] ?>"><button class="button danger small" type="submit">Remove</button></form></div>
        <?php endforeach; ?>
    </div>
</section>
<?php admin_page_end(); ?>
