<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    safeRedirect(APP_URL . '/followups.php');
}

verifyCsrf();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    setFlash('error', 'Invalid follow-up ID.');
    safeRedirect(APP_URL . '/followups.php');
}

$followup = dbFetch($db, "SELECT * FROM followups WHERE id = ? AND assigned_to = ?", [$id, currentUserId()]);

if (!$followup) {
    setFlash('error', 'Follow-up not found.');
    safeRedirect(APP_URL . '/followups.php');
}

if ($followup['status'] === 'done') {
    setFlash('error', 'Follow-up is already completed.');
    safeRedirect(APP_URL . '/followups.php');
}

try {
    dbUpdate($db, 'followups', [
        'status' => 'done',
        'completed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    logActivity('followups', 'update', 'followup', $id, 'Completed follow-up', ['status' => $followup['status']], ['status' => 'done']);
    setFlash('success', 'Follow-up marked as done.');
} catch (Exception $e) {
    error_log("Followup complete error: " . $e->getMessage());
    setFlash('error', 'Failed to complete follow-up.');
}

safeRedirect(APP_URL . '/followups.php');
