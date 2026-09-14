<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

function ensure_incident_schema(): void
{
    ensure_core_schema();
    db()->exec('
        CREATE TABLE IF NOT EXISTS incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "investigating",
            impact TEXT NOT NULL DEFAULT "minor",
            summary TEXT,
            is_auto INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
    db()->exec('
        CREATE TABLE IF NOT EXISTS incident_updates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            incident_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            message TEXT NOT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
        )
    ');
    db()->exec('
        CREATE TABLE IF NOT EXISTS incident_targets (
            incident_id INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            PRIMARY KEY (incident_id, target_type, target_id),
            FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
        )
    ');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_incidents_status ON incidents(status, updated_at DESC)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_incident_updates_incident ON incident_updates(incident_id, created_at DESC)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_incident_targets_target ON incident_targets(target_type, target_id)');
}

function valid_incident_status(string $status): string
{
    return in_array($status, ['investigating', 'identified', 'monitoring', 'resolved'], true) ? $status : 'investigating';
}

function valid_incident_impact(string $impact): string
{
    return in_array($impact, ['minor', 'major', 'critical'], true) ? $impact : 'minor';
}

function incident_status_label(string $status): string
{
    return match ($status) {
        'identified' => 'Identified',
        'monitoring' => 'Monitoring',
        'resolved' => 'Resolved',
        default => 'Investigating',
    };
}

function incident_impact_label(string $impact): string
{
    return match ($impact) {
        'critical' => 'Critical',
        'major' => 'Major',
        default => 'Minor',
    };
}

function create_incident(string $title, string $summary, string $impact, array $targets = [], ?int $createdBy = null, bool $auto = false): int
{
    ensure_incident_schema();
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Incident title is required.');
    }
    $impact = valid_incident_impact($impact);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO incidents (title, status, impact, summary, is_auto, created_by) VALUES (?, "investigating", ?, ?, ?, ?)')
            ->execute([$title, $impact, trim($summary), $auto ? 1 : 0, $createdBy]);
        $id = (int)$pdo->lastInsertId();
        $message = trim($summary) !== '' ? trim($summary) : 'We are investigating an issue affecting this service.';
        $pdo->prepare('INSERT INTO incident_updates (incident_id, status, message, created_by) VALUES (?, "investigating", ?, ?)')
            ->execute([$id, $message, $createdBy]);
        $targetStmt = $pdo->prepare('INSERT OR IGNORE INTO incident_targets (incident_id, target_type, target_id) VALUES (?, ?, ?)');
        foreach ($targets as $target) {
            $type = (string)($target['type'] ?? '');
            $targetId = (int)($target['id'] ?? 0);
            if (in_array($type, ['service', 'website'], true) && $targetId > 0) {
                $targetStmt->execute([$id, $type, $targetId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    
    $notifyTargets = [];
    foreach ($targets as $target) {
        $type = (string)($target['type'] ?? '');
        $targetId = (int)($target['id'] ?? 0);
        if (in_array($type, ['service','website'], true) && $targetId > 0) $notifyTargets[] = ['type'=>$type,'id'=>$targetId];
    }
    $notifyContext = ['incident_id' => $id, 'targets' => $notifyTargets];
    if (!empty($notifyTargets[0])) { $notifyContext['target_type'] = $notifyTargets[0]['type']; $notifyContext['target_id'] = $notifyTargets[0]['id']; }
    send_status_notifications('Incident: ' . $title, $message, $impact === 'critical' ? 'critical' : 'warning', 'incident_created', $notifyContext);
    return $id;
}

function add_incident_update(int $incidentId, string $status, string $message, ?int $createdBy = null): void
{
    ensure_incident_schema();
    $status = valid_incident_status($status);
    $message = trim($message);
    if ($message === '') {
        throw new RuntimeException('Incident update message is required.');
    }

    $stmt = db()->prepare('SELECT * FROM incidents WHERE id = ? LIMIT 1');
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch();
    if (!$incident) {
        throw new RuntimeException('Incident not found.');
    }

    db()->prepare('INSERT INTO incident_updates (incident_id, status, message, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$incidentId, $status, $message, $createdBy]);
    if ($status === 'resolved') {
        db()->prepare('UPDATE incidents SET status = ?, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$status, $incidentId]);
    } else {
        db()->prepare('UPDATE incidents SET status = ?, resolved_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$status, $incidentId]);
    }

    $severity = $status === 'resolved' ? 'resolved' : (($incident['impact'] ?? 'minor') === 'critical' ? 'critical' : 'warning');
    $targets = get_incident_targets($incidentId);
    $notifyTargets = array_map(static fn(array $t): array => ['type'=>(string)$t['target_type'],'id'=>(int)$t['target_id']], $targets);
    $notifyContext = ['incident_id' => $incidentId, 'targets' => $notifyTargets];
    if (!empty($notifyTargets[0])) { $notifyContext['target_type'] = $notifyTargets[0]['type']; $notifyContext['target_id'] = $notifyTargets[0]['id']; }
    send_status_notifications('Incident update: ' . $incident['title'], incident_status_label($status) . "\n\n" . $message, $severity, 'incident_update', $notifyContext);
}

function delete_incident(int $incidentId): void
{
    ensure_incident_schema();
    db()->prepare('DELETE FROM incidents WHERE id = ?')->execute([$incidentId]);
}

function get_incident_targets(int $incidentId): array
{
    ensure_incident_schema();
    $stmt = db()->prepare('
        SELECT it.*,
               CASE WHEN it.target_type = "service" THEN s.service_name ELSE w.website_name END AS target_name
        FROM incident_targets it
        LEFT JOIN services s ON it.target_type = "service" AND s.id = it.target_id
        LEFT JOIN websites w ON it.target_type = "website" AND w.id = it.target_id
        WHERE it.incident_id = ?
        ORDER BY it.target_type, target_name
    ');
    $stmt->execute([$incidentId]);
    return $stmt->fetchAll();
}

function get_incident_updates(int $incidentId): array
{
    ensure_incident_schema();
    $stmt = db()->prepare('SELECT * FROM incident_updates WHERE incident_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$incidentId]);
    return $stmt->fetchAll();
}

function hydrate_incident(array $incident): array
{
    $incident['targets'] = get_incident_targets((int)$incident['id']);
    $incident['updates'] = get_incident_updates((int)$incident['id']);
    return $incident;
}

function get_active_incidents(): array
{
    ensure_incident_schema();
    $rows = db()->query('SELECT * FROM incidents WHERE status <> "resolved" ORDER BY started_at DESC, id DESC')->fetchAll();
    return array_map('hydrate_incident', $rows);
}

function get_recent_incidents(int $limit = 20): array
{
    ensure_incident_schema();
    $stmt = db()->prepare('SELECT * FROM incidents ORDER BY started_at DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return array_map('hydrate_incident', $stmt->fetchAll());
}

function get_incidents_between(string $startAt, string $endAt): array
{
    ensure_incident_schema();
    $stmt = db()->prepare('SELECT * FROM incidents WHERE started_at >= ? AND started_at < ? ORDER BY started_at DESC, id DESC');
    $stmt->execute([$startAt, $endAt]);
    return array_map('hydrate_incident', $stmt->fetchAll());
}

function get_active_auto_incident_for_website(int $websiteId): ?array
{
    ensure_incident_schema();
    $stmt = db()->prepare('
        SELECT i.* FROM incidents i
        JOIN incident_targets it ON it.incident_id = i.id
        WHERE i.status <> "resolved" AND i.is_auto = 1 AND it.target_type = "website" AND it.target_id = ?
        ORDER BY i.id DESC LIMIT 1
    ');
    $stmt->execute([$websiteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sync_auto_incident_for_website(array $website, string $oldStatus, string $newStatus, string $detail): void
{
    ensure_incident_schema();
    $websiteId = (int)$website['id'];
    $name = (string)$website['website_name'];
    $existing = get_active_auto_incident_for_website($websiteId);

    if (in_array($newStatus, ['offline', 'major_outage', 'partial_outage'], true)) {
        if (!$existing) {
            create_incident(
                $name . ' outage',
                $detail !== '' ? $detail : $name . ' is not responding normally.',
                $newStatus === 'offline' || $newStatus === 'major_outage' ? 'major' : 'minor',
                [['type' => 'website', 'id' => $websiteId]],
                null,
                true
            );
        } elseif ($oldStatus !== $newStatus) {
            add_incident_update((int)$existing['id'], 'investigating', $detail !== '' ? $detail : 'The outage is still being investigated.', null);
        }
        return;
    }

    if ($newStatus === 'degraded') {
        if (!$existing) {
            create_incident(
                $name . ' degraded performance',
                $detail !== '' ? $detail : $name . ' is responding more slowly than expected.',
                'minor',
                [['type' => 'website', 'id' => $websiteId]],
                null,
                true
            );
        } elseif ($oldStatus !== $newStatus) {
            add_incident_update((int)$existing['id'], 'monitoring', $detail !== '' ? $detail : 'The service is reachable but performance remains degraded.', null);
        }
        return;
    }

    if ($newStatus === 'operational' && $existing) {
        add_incident_update((int)$existing['id'], 'resolved', $name . ' has recovered and monitoring confirms normal operation.', null);
    }
}
