<?php
$pageTitle = 'Cash Flow Report';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$income = dbFetchAll($db, "SELECT payment_date, SUM(amount) as total FROM payments WHERE payment_date BETWEEN ? AND ? AND voided = 0 GROUP BY payment_date ORDER BY payment_date", [$startDate, $endDate]);
$totalIncome = (float)(dbFetch($db, "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE payment_date BETWEEN ? AND ? AND voided = 0", [$startDate, $endDate])['t'] ?? 0);
$materialCosts = (float)(dbFetch($db, "SELECT COALESCE(SUM(jm.total_cost),0) as t FROM job_materials jm JOIN jobs j ON jm.job_id=j.id WHERE jm.added_at BETWEEN ? AND ? AND j.deleted_at IS NULL", [$startDate, $endDate . ' 23:59:59'])['t'] ?? 0);
?>

<div class="page-header">
    <div class="page-title"><h1>Cash Flow Report</h1></div>
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
    <div class="stat-card"><div class="stat-icon green"><i data-lucide="arrow-up"></i></div><div class="stat-info"><div class="stat-label">Total Income</div><div class="stat-value"><?= formatMoney($totalIncome) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i data-lucide="arrow-down"></i></div><div class="stat-info"><div class="stat-label">Material Costs</div><div class="stat-value"><?= formatMoney($materialCosts) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon blue"><i data-lucide="balance-scale"></i></div><div class="stat-info"><div class="stat-label">Net</div><div class="stat-value"><?= formatMoney($totalIncome - $materialCosts) ?></div></div></div>
</div>

<?php if (!empty($income)): ?>
<div class="card" style="margin-top:20px;">
    <h3>Daily Income</h3>
    <table class="data-table">
        <thead><tr><th>Date</th><th>Amount</th></tr></thead>
        <tbody>
            <?php foreach ($income as $row): ?>
            <tr><td><?= formatDate($row['payment_date']) ?></td><td style="color:#059669;font-weight:600;"><?= formatMoney($row['total']) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
