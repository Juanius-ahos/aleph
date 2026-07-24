<?php
$pageTitle = 'Sales Report';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$totalQuotes = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM quotes WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL", [$startDate.' 00:00:00', $endDate.' 23:59:59'])['c'] ?? 0);
$acceptedQuotes = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM quotes WHERE status='accepted' AND created_at BETWEEN ? AND ? AND deleted_at IS NULL", [$startDate.' 00:00:00', $endDate.' 23:59:59'])['c'] ?? 0);
$totalRevenue = (float)(dbFetch($db, "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE payment_date BETWEEN ? AND ? AND voided = 0", [$startDate, $endDate])['t'] ?? 0);
$avgQuoteValue = (float)(dbFetch($db, "SELECT COALESCE(AVG(total),0) as t FROM quotes WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL", [$startDate.' 00:00:00', $endDate.' 23:59:59'])['t'] ?? 0);
$topCustomers = dbFetchAll($db, "SELECT c.company_name, COUNT(q.id) as quote_count, SUM(q.total) as total_value FROM quotes q JOIN customers c ON q.customer_id=c.id WHERE q.created_at BETWEEN ? AND ? AND q.deleted_at IS NULL GROUP BY q.customer_id ORDER BY total_value DESC LIMIT 10", [$startDate.' 00:00:00', $endDate.' 23:59:59']);
?>

<div class="page-header">
    <div class="page-title"><h1>Sales Report</h1></div>
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
    <div class="stat-card"><div class="stat-icon blue"><i data-lucide="file-invoice-dollar"></i></div><div class="stat-info"><div class="stat-label">Total Quotes</div><div class="stat-value"><?= $totalQuotes ?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i data-lucide="check-circle"></i></div><div class="stat-info"><div class="stat-label">Accepted</div><div class="stat-value"><?= $acceptedQuotes ?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i data-lucide="dollar-sign"></i></div><div class="stat-info"><div class="stat-label">Revenue</div><div class="stat-value"><?= formatMoney($totalRevenue) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i data-lucide="calculator"></i></div><div class="stat-info"><div class="stat-label">Avg Quote Value</div><div class="stat-value"><?= formatMoney($avgQuoteValue) ?></div></div></div>
</div>

<div class="card" style="margin-top:20px;">
    <h3>Top Customers by Quote Value</h3>
    <?php if (empty($topCustomers)): ?>
        <div class="empty-state">No data for this period</div>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Customer</th><th>Quotes</th><th>Total Value</th></tr></thead>
            <tbody>
                <?php foreach ($topCustomers as $c): ?>
                <tr><td><?= h($c['company_name']) ?></td><td><?= $c['quote_count'] ?></td><td><?= formatMoney($c['total_value']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
