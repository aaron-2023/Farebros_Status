<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/platform.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $user = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'reset') {
        set_admin_preference((int)$user['id'], 'dashboard_layout', '');
        audit_admin_action($user, 'dashboard_layout_reset', 'dashboard');
        echo json_encode(['ok' => true]);
        exit;
    }
    $order = json_decode((string)($_POST['order'] ?? '[]'), true);
    $hidden = json_decode((string)($_POST['hidden'] ?? '[]'), true);
    if (!is_array($order) || !is_array($hidden)) throw new RuntimeException('Invalid dashboard layout payload.');
    save_dashboard_layout((int)$user['id'], $order, $hidden);
    audit_admin_action($user, 'dashboard_layout_saved', 'dashboard');
    echo json_encode(['ok' => true]);
} catch (Throwable $ex) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
