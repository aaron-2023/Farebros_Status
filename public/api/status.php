<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/functions.php';

$schedule = null;
if (file_exists(__DIR__ . '/../../app/scheduler.php')) {
    require_once __DIR__ . '/../../app/scheduler.php';
    $active = active_job_payload();
    $upcoming = upcoming_job_payload();

    if ($active) {
        $schedule = [
            'phase' => 'active',
            'title' => $active['title'],
            'details' => $active['details'],
            'start_at' => $active['start_at'],
            'end_at' => $active['end_at'],
            'start_at_iso' => iso_dt($active['start_at']),
            'end_at_iso' => iso_dt($active['end_at']),
        ];
    } elseif ($upcoming) {
        $schedule = [
            'phase' => 'upcoming',
            'title' => $upcoming['title'],
            'details' => $upcoming['details'],
            'start_at' => $upcoming['start_at'],
            'end_at' => $upcoming['end_at'],
            'start_at_iso' => iso_dt($upcoming['start_at']),
            'end_at_iso' => iso_dt($upcoming['end_at']),
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$payload = public_status_payload();
$payload['schedule'] = $schedule;

if ($schedule && file_exists(__DIR__ . '/../../app/scheduler.php')) {
    foreach ($payload['services'] as &$service) {
        $serviceSchedule = current_schedule_for_item('service', (int)$service['id']);
        $service['schedule_overlay'] = $serviceSchedule ? [
            'phase' => $serviceSchedule['phase'],
            'title' => $serviceSchedule['title'],
            'label' => $serviceSchedule['phase'] === 'active' ? 'Maintenance in progress' : 'Planned maintenance scheduled',
            'start_at_iso' => iso_dt($serviceSchedule['start_at']),
            'end_at_iso' => iso_dt($serviceSchedule['end_at']),
        ] : null;
    }
    unset($service);

    foreach ($payload['websites'] as &$website) {
        $websiteSchedule = current_schedule_for_item('website', (int)$website['id']);
        $website['schedule_overlay'] = $websiteSchedule ? [
            'phase' => $websiteSchedule['phase'],
            'title' => $websiteSchedule['title'],
            'label' => $websiteSchedule['phase'] === 'active' ? 'Maintenance in progress' : 'Planned maintenance scheduled',
            'start_at_iso' => iso_dt($websiteSchedule['start_at']),
            'end_at_iso' => iso_dt($websiteSchedule['end_at']),
        ] : null;
    }
    unset($website);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
