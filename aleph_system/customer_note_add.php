<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$customerId = (int)($_POST['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customerId > 0) {
    verifyCsrf();
    $note = clean($_POST['note'] ?? '');
    if ($note) {
        dbInsert($db, 'customer_notes', [
            'customer_id' => $customerId,
            'note' => $note,
            'created_by' => currentUserId(),
        ]);
        setFlash('success', 'Note added.');
    }
}
header("Location: customer_view.php?id=$customerId");
exit;
