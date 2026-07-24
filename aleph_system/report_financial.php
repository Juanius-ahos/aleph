<?php
$pageTitle = 'Financial Report';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$totalInvoiced = (float)(dbFetch($db, "SELECT COALESCE(SUM(total),0) as t FROM invoices WHERE invoice_date BETWEEN ? AND ? AND deleted_at IS NULL", [$startDate, $endDate])['t'] ?? 0);
$totalPaid = (float)(dbFetch($db, "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE payment_date BETWEEN ? AND ? AND voided = 0", [$startDate, $endDate])['t'] ?? 0);
$totalOutstanding = (float)(dbFetch($db, "SELECT COALESCE(SUM(balance_due),0) as t FROM invoices WHERE status IN ('sent','partial','overdue') AND deleted_at IS NULL")['t'] ?? 0);
$totalOverdue = (float)(dbFetch($db, "SELECT COALESCE(SUM(balance_due),0) as t FROM invoices WHERE status='overdue' AND deleted_at IS NULL")['t'] ?? 0);
?>

<div class="page-header">
    <div class="page-title"><h1>Financial Report</h1></div>
    <div class="page-actions"><a href="reports.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group"><label>From</label><input type="date" name="start_date" class="form-control" value="<?= h($startDate) ?>"></div>
        <div class="filter-group"><label>To</label><input type="date" name="end_date" class="form-control" value="<?= h($endDate) ?>"></div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
    </form>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i data-lucide="file-invoice"></i></div><div class="stat-info"><div class="stat-label">Total Invoiced</div><div class="stat-value"><?= formatMoney($totalInvoiced) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i data-lucide="check-circle"></i></div><div class="stat-info"><div class="stat-label">Total Paid</div><div class="stat-value"><?= formatMoney($totalPaid) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i data-lucide="clock"></i></div><div class="stat-info"><div class="stat-label">Outstanding</div><div class="stat-value"><?= formatMoney($totalOutstanding) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i data-lucide="exclamation-triangle"></i></div><div class="stat-info"><div class="stat-label">Overdue</div><div class="stat-value"><?= formatMoney($totalOverdue) ?></div></div></div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
