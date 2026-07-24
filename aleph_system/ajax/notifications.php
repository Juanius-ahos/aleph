<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$db = getDB();
$userId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }

    $notifications = dbFetchAll($db, "SELECT id, title, message, entity_type, entity_id, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50", [$userId]);
    $unread = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND read_at IS NULL", [$userId])['c'] ?? 0);

    echo json_encode(['notifications' => $notifications, 'unread' => $unread]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $id = (int)($input['id'] ?? 0);

    if ($action === 'read' && $id) {
        dbQuery($db, "UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?", [$id, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'read_all') {
        dbQuery($db, "UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [$userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
