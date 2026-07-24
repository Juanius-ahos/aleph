<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

$db = getDB();
$now = new DateTime();

$revenue = (float)(dbFetch($db, "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE()) AND voided = 0")['t'] ?? 0);
$outstanding = (float)(dbFetch($db, "SELECT COALESCE(SUM(balance_due),0) as t FROM invoices WHERE status IN ('sent','partial','overdue') AND deleted_at IS NULL")['t'] ?? 0);
$activeJobs = (int)(dbFetch($db, "SELECT COUNT(*) as t FROM jobs WHERE status='active' AND deleted_at IS NULL")['t'] ?? 0);
$completedJobs = (int)(dbFetch($db, "SELECT COUNT(*) as t FROM jobs WHERE status='completed' AND MONTH(completed_at)=MONTH(CURDATE()) AND deleted_at IS NULL")['t'] ?? 0);
$customers = (int)(dbFetch($db, "SELECT COUNT(*) as t FROM customers WHERE deleted_at IS NULL")['t'] ?? 0);
$pendingQuotes = (int)(dbFetch($db, "SELECT COUNT(*) as t FROM quotes WHERE status IN ('draft','sent') AND deleted_at IS NULL")['t'] ?? 0);
$lowStock = (int)(dbFetch($db, "SELECT COUNT(*) as t FROM materials WHERE stock_qty<=min_stock AND active=1")['t'] ?? 0);
$overdueInvoices = (int)(dbFetch($db, "SELECT COUNT(*) as t FROM invoices WHERE status='overdue' AND deleted_at IS NULL")['t'] ?? 0);

echo json_encode([
    'revenue' => $revenue, 'outstanding' => $outstanding,
    'active_jobs' => $activeJobs, 'completed_jobs' => $completedJobs,
    'active_customers' => $customers, 'pending_quotes' => $pendingQuotes,
    'low_stock' => $lowStock, 'overdue_invoices' => $overdueInvoices
]);
