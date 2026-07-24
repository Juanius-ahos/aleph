<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$customerId = (int)($_POST['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customerId > 0) {
    verifyCsrf();
    dbInsert($db, 'customer_contacts', [
        'customer_id' => $customerId,
        'first_name' => clean($_POST['first_name'] ?? ''),
        'last_name' => clean($_POST['last_name'] ?? ''),
        'email' => clean($_POST['email'] ?? ''),
        'phone' => clean($_POST['phone'] ?? ''),
        'mobile' => clean($_POST['mobile'] ?? ''),
        'job_title' => clean($_POST['job_title'] ?? ''),
        'is_primary' => (int)($_POST['is_primary'] ?? 0),
    ]);
    setFlash('success', 'Contact added.');
}
header("Location: customer_view.php?id=$customerId");
exit;
