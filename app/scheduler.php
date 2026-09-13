<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function ensure_scheduler_schema(): void
{
    ensure_core_schema();

    db()->exec('
        CREATE TABLE IF NOT EXISTS scheduled_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            details TEXT,
            scope TEXT NOT NULL DEFAULT "all",
            target_type TEXT,
            target_id INTEGER,
            scheduled_status TEXT NOT NULL DEFAULT "planned_maintenance",
            active_status TEXT NOT NULL DEFAULT "offline",
            after_status TEXT NOT NULL DEFAULT "operational",
            start_at TEXT NOT NULL,
            end_at TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            has_started INTEGER NOT NULL DEFAULT 0,
            has_completed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

function normalize_datetime_local(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (function_exists('db_datetime_from_local')) return db_datetime_from_local($value);
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
}

function get_scheduled_jobs(int $limit = 50): array
{
    ensure_scheduler_schema();
    $stmt = db()->prepare('SELECT * FROM scheduled_jobs ORDER BY has_completed ASC, start_at ASC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function add_scheduled_job(array $data): void
{
    ensure_scheduler_schema();

    $title = trim((string)($data['title'] ?? ''));
    $details = trim((string)($data['details'] ?? ''));
    $scope = (string)($data['scope'] ?? 'all');
    $targetType = $data['target_type'] ?? null;
    $targetId = !empty($data['target_id']) ? (int)$data['target_id'] : null;
    $scheduledStatus = (string)($data['scheduled_status'] ?? 'planned_maintenance');
    $activeStatus = (string)($data['active_status'] ?? 'offline');
    $afterStatus = (string)($data['after_status'] ?? 'operational');
    $startAt = normalize_datetime_local((string)($data['start_at'] ?? ''));
    $endAt = normalize_datetime_local((string)($data['end_at'] ?? ''));

    if ($title === '' || $startAt === '' || $endAt === '') {
        throw new RuntimeException('Title, start time, and end time are required.');
    }

    if (strtotime($endAt) <= strtotime($startAt)) {
        throw new RuntimeException('End time must be after start time.');
    }

    if (!isset(STATUS_OPTIONS[$scheduledStatus])) $scheduledStatus = 'planned_maintenance';
    if (!isset(STATUS_OPTIONS[$activeStatus])) $activeStatus = 'offline';
    if (!isset(STATUS_OPTIONS[$afterStatus])) $afterStatus = 'operational';
    if (!in_array($scope, ['all', 'services', 'websites', 'single_service', 'single_website'], true)) $scope = 'all';

    $stmt = db()->prepare('
        INSERT INTO scheduled_jobs
        (title, details, scope, target_type, target_id, scheduled_status, active_status, after_status, start_at, end_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$title, $details, $scope, $targetType, $targetId, $scheduledStatus, $activeStatus, $afterStatus, $startAt, $endAt]);
}

function update_scheduled_job_active(int $id, bool $active): void
{
    ensure_scheduler_schema();
    db()->prepare('UPDATE scheduled_jobs SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

function delete_scheduled_job(int $id): void
{
    ensure_scheduler_schema();
    db()->prepare('DELETE FROM scheduled_jobs WHERE id = ?')->execute([$id]);
}

function set_all_services_status(string $status): void
{
    // The offsite Status Page should stay operational. Local outages should not mark it offline.
    if (in_array($status, ['offline', 'major_outage', 'partial_outage'], true)) {
        db()->prepare("
            UPDATE services
            SET current_status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE TRIM(LOWER(service_name)) <> 'status page'
        ")->execute([$status]);

        db()->exec("
            UPDATE services
            SET current_status = 'operational', updated_at = CURRENT_TIMESTAMP
            WHERE TRIM(LOWER(service_name)) = 'status page'
        ");
        return;
    }

    db()->prepare('UPDATE services SET current_status = ?, updated_at = CURRENT_TIMESTAMP')->execute([$status]);
}

function set_all_websites_status(string $status): void
{
    db()->prepare('UPDATE websites SET current_status = ?, updated_at = CURRENT_TIMESTAMP')->execute([$status]);
}

function set_single_service_status(int $id, string $status): void
{
    $stmt = db()->prepare('SELECT service_name FROM services WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $name = strtolower(trim((string)$stmt->fetchColumn()));

    if ($name === 'status page' && in_array($status, ['offline', 'major_outage', 'partial_outage'], true)) {
        $status = 'operational';
    }

    db()->prepare('UPDATE services SET current_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, $id]);
}

function set_single_website_status(int $id, string $status): void
{
    db()->prepare('UPDATE websites SET current_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, $id]);
}

function apply_job_status(array $job, string $status): void
{
    $scope = $job['scope'];

    if ($scope === 'all') {
        set_all_services_status($status);
        set_all_websites_status($status);
        return;
    }

    if ($scope === 'services') {
        set_all_services_status($status);
        return;
    }

    if ($scope === 'websites') {
        set_all_websites_status($status);
        return;
    }

    if ($scope === 'single_service' && !empty($job['target_id'])) {
        set_single_service_status((int)$job['target_id'], $status);
        return;
    }

    if ($scope === 'single_website' && !empty($job['target_id'])) {
        set_single_website_status((int)$job['target_id'], $status);
    }
}

function write_scheduled_update(array $job, string $newStatus, string $phase): void
{
    $title = match ($phase) {
        'started' => 'Scheduled maintenance started: ' . $job['title'],
        'completed' => 'Scheduled maintenance completed: ' . $job['title'],
        default => $job['title'],
    };

    $message = trim((string)$job['details']);
    if ($message === '') $message = 'This status was applied automatically by the scheduled maintenance system.';

    db()->prepare('
        INSERT INTO status_updates
        (service_id, old_status, new_status, update_title, update_message, created_by)
        VALUES (NULL, NULL, ?, ?, ?, NULL)
    ')->execute([$newStatus, $title, $message]);
}

function active_job_payload(): ?array
{
    ensure_scheduler_schema();
    $now = gmdate('Y-m-d H:i:s');

    $stmt = db()->prepare('
        SELECT * FROM scheduled_jobs
        WHERE is_active = 1 AND has_completed = 0 AND start_at <= ? AND end_at > ?
        ORDER BY start_at ASC LIMIT 1
    ');
    $stmt->execute([$now, $now]);
    $job = $stmt->fetch();

    return $job ?: null;
}

function upcoming_job_payload(): ?array
{
    ensure_scheduler_schema();
    $now = gmdate('Y-m-d H:i:s');

    $stmt = db()->prepare('
        SELECT * FROM scheduled_jobs
        WHERE is_active = 1 AND has_completed = 0 AND start_at > ?
        ORDER BY start_at ASC LIMIT 1
    ');
    $stmt->execute([$now]);
    $job = $stmt->fetch();

    return $job ?: null;
}

function schedule_applies_to_item(array $job, string $itemType, int $itemId): bool
{
    $scope = $job['scope'];

    if ($scope === 'all') return true;
    if ($scope === 'services' && $itemType === 'service') return true;
    if ($scope === 'websites' && $itemType === 'website') return true;
    if ($scope === 'single_service' && $itemType === 'service' && (int)$job['target_id'] === $itemId) return true;
    if ($scope === 'single_website' && $itemType === 'website' && (int)$job['target_id'] === $itemId) return true;

    return false;
}

function current_schedule_for_item(string $itemType, int $itemId): ?array
{
    $active = active_job_payload();
    if ($active && schedule_applies_to_item($active, $itemType, $itemId)) {
        $active['phase'] = 'active';
        return $active;
    }

    $upcoming = upcoming_job_payload();
    if ($upcoming && schedule_applies_to_item($upcoming, $itemType, $itemId)) {
        $upcoming['phase'] = 'upcoming';
        return $upcoming;
    }

    return null;
}

function apply_scheduled_jobs(): array
{
    ensure_scheduler_schema();
    $now = gmdate('Y-m-d H:i:s');

    $stmt = db()->prepare('
        SELECT * FROM scheduled_jobs
        WHERE is_active = 1 AND has_completed = 0
        ORDER BY start_at ASC
    ');
    $stmt->execute();
    $jobs = $stmt->fetchAll();

    $actions = [];

    foreach ($jobs as $job) {
        $start = $job['start_at'];
        $end = $job['end_at'];

        if ($now < $start) {
            // Upcoming jobs are overlay-only. They do not change live status early.
            $actions[] = 'upcoming-overlay-only:' . $job['id'];
            continue;
        }

        if ($now >= $start && $now < $end) {
            apply_job_status($job, $job['active_status']);

            if ((int)$job['has_started'] === 0) {
                write_scheduled_update($job, $job['active_status'], 'started');
                db()->prepare('UPDATE scheduled_jobs SET has_started = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$job['id']]);
                $actions[] = 'started:' . $job['id'];
            } else {
                $actions[] = 'active:' . $job['id'];
            }
            continue;
        }

        if ($now >= $end) {
            apply_job_status($job, $job['after_status']);
            write_scheduled_update($job, $job['after_status'], 'completed');

            db()->prepare('UPDATE scheduled_jobs SET has_completed = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$job['id']]);
            $actions[] = 'completed:' . $job['id'];
        }
    }

    // Safety guard: offsite Status Page should never remain offline from a local outage.
    db()->exec("
        UPDATE services
        SET current_status = 'operational', updated_at = CURRENT_TIMESTAMP
        WHERE TRIM(LOWER(service_name)) = 'status page'
          AND current_status IN ('offline', 'major_outage', 'partial_outage')
    ");

    return $actions;
}
