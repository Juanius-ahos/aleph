<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }

$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['quote', 'invoice']) || !$id) {
    http_response_code(400);
    echo 'Invalid parameters';
    exit;
}

$db = getDB();
if ($type === 'quote') {
    header('Location: quote_pdf.php?id=' . $id);
    exit;
} elseif ($type === 'invoice') {
    header('Location: invoice_pdf.php?id=' . $id);
    exit;
}
