<?php
require_once __DIR__ . '/../config.php';

$db = getDB();

echo "=== Notification Check & Cleanup ===" . PHP_EOL;
echo "Started: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

$notificationsCreated = 0;

$dueJobs = dbFetchAll($db, "
    SELECT j.id, j.title, j.assigned_to, j.estimated_delivery
    FROM jobs j
    WHERE j.status = 'active'
      AND j.estimated_delivery BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 3 DAY)
      AND j.assigned_to IS NOT NULL
      AND j.deleted_at IS NULL
");

foreach ($dueJobs as $job) {
    $exists = dbFetch($db, "SELECT id FROM notifications WHERE user_id = ? AND entity_type = 'job' AND entity_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)", [$job['assigned_to'], $job['id']]);
    if (!$exists) {
        dbQuery($db, "INSERT INTO notifications (user_id, title, message, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'job', ?, NOW())", [
            $job['assigned_to'],
            'Job #' . $job['id'] . ' Due Soon',
            'Job "' . $job['title'] . '" is due on ' . $job['estimated_delivery'] . '.',
            $job['id']
        ]);
        $notificationsCreated++;
    }
}
echo "Created {$notificationsCreated} job due soon notification(s)." . PHP_EOL;

$lowStockMaterials = dbFetchAll($db, "SELECT id, name, stock_qty, min_stock FROM materials WHERE stock_qty <= min_stock AND active = 1");
$lowStockCount = 0;

foreach ($lowStockMaterials as $material) {
    $admins = dbFetchAll($db, "SELECT id FROM users WHERE role = 'admin' AND active = 1");
    foreach ($admins as $admin) {
        $exists = dbFetch($db, "SELECT id FROM notifications WHERE user_id = ? AND entity_type = 'material' AND entity_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)", [$admin['id'], $material['id']]);
        if (!$exists) {
            dbQuery($db, "INSERT INTO notifications (user_id, title, message, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'material', ?, NOW())", [
                $admin['id'],
                'Low Stock: ' . $material['name'],
                $material['name'] . ' has ' . $material['stock_qty'] . ' units (min: ' . $material['min_stock'] . ').',
                $material['id']
            ]);
            $lowStockCount++;
        }
    }
}
echo "Created {$lowStockCount} low stock notification(s)." . PHP_EOL;

$expiringQuotes = dbFetchAll($db, "SELECT id, customer_id, total, valid_until FROM quotes WHERE status = 'sent' AND valid_until BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 3 DAY) AND deleted_at IS NULL");
$expiringCount = 0;

foreach ($expiringQuotes as $quote) {
    $admins = dbFetchAll($db, "SELECT id FROM users WHERE role = 'admin' AND active = 1");
    foreach ($admins as $admin) {
        $exists = dbFetch($db, "SELECT id FROM notifications WHERE user_id = ? AND entity_type = 'quote' AND entity_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)", [$admin['id'], $quote['id']]);
        if (!$exists) {
            dbQuery($db, "INSERT INTO notifications (user_id, title, message, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'quote', ?, NOW())", [
                $admin['id'],
                'Quote #' . $quote['id'] . ' Expiring Soon',
                'Quote #' . $quote['id'] . ' for $' . number_format($quote['total'], 2) . ' expires on ' . $quote['valid_until'] . '.',
                $quote['id']
            ]);
            $expiringCount++;
        }
    }
}
echo "Created {$expiringCount} expiring quote notification(s)." . PHP_EOL;

$deleted = dbDelete($db, "notifications", "created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
echo "Cleaned up old notifications." . PHP_EOL . PHP_EOL;

echo "Completed: " . date('Y-m-d H:i:s') . PHP_EOL;
