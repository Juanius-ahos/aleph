<?php
require_once __DIR__ . '/../config.php';

$db = getDB();

echo "=== Check Overdue Invoices ===" . PHP_EOL;
echo "Started: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

$overdueInvoices = dbFetchAll($db, "SELECT id, customer_id, total, balance_due, due_date FROM invoices WHERE status NOT IN ('paid','cancelled','overdue') AND due_date < CURRENT_DATE() AND deleted_at IS NULL");

$count = 0;
foreach ($overdueInvoices as $invoice) {
    $updated = dbUpdate($db, 'invoices', ['status' => 'overdue'], "id = ? AND status != 'overdue'", [$invoice['id']]);
    if ($updated > 0) {
        $admins = dbFetchAll($db, "SELECT id FROM users WHERE role = 'admin' AND active = 1");
        foreach ($admins as $admin) {
            dbQuery($db, "INSERT INTO notifications (user_id, title, message, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'invoice', ?, NOW())", [
                $admin['id'],
                'Invoice #' . $invoice['id'] . ' Overdue',
                'Invoice #' . $invoice['id'] . ' for $' . number_format($invoice['balance_due'], 2) . ' was due on ' . $invoice['due_date'] . '.',
                $invoice['id']
            ]);
        }
        $count++;
    }
}

echo "Updated {$count} invoice(s) to overdue status." . PHP_EOL;
echo "Completed: " . date('Y-m-d H:i:s') . PHP_EOL;
