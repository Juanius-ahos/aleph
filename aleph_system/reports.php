<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$stats = getSystemStats();
?>

<div class="page-header">
    <div class="page-title"><h1>Reports & Analytics</h1><p class="page-subtitle">Business intelligence overview</p></div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon green"><i data-lucide="dollar-sign"></i></div><div class="stat-info"><div class="stat-label">Total Customers</div><div class="stat-value"><?= $stats['total_customers'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon blue"><i data-lucide="project-diagram"></i></div><div class="stat-info"><div class="stat-label">Total Jobs</div><div class="stat-value"><?= $stats['total_jobs'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i data-lucide="file-invoice"></i></div><div class="stat-info"><div class="stat-label">Total Invoices</div><div class="stat-value"><?= $stats['total_invoices'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i data-lucide="exclamation-circle"></i></div><div class="stat-info"><div class="stat-label">Overdue Invoices</div><div class="stat-value"><?= $stats['overdue_invoices'] ?></div></div></div>
</div>

<div class="dashboard-grid">
    <a href="report_sales.php" class="dashboard-card" style="text-decoration:none;">
        <div class="card-header"><h3><i data-lucide="chart-line"></i> Sales Report</h3></div>
        <div class="card-content"><p>Revenue, quotes, conversion rates, and sales performance.</p></div>
    </a>
    <a href="report_financial.php" class="dashboard-card" style="text-decoration:none;">
        <div class="card-header"><h3><i data-lucide="chart-pie"></i> Financial Report</h3></div>
        <div class="card-content"><p>Invoices, payments, outstanding balances, and aging.</p></div>
    </a>
    <a href="report_cashflow.php" class="dashboard-card" style="text-decoration:none;">
        <div class="card-header"><h3><i data-lucide="money-bill-wave"></i> Cash Flow</h3></div>
        <div class="card-content"><p>Income vs expenses, payment trends.</p></div>
    </a>
    <a href="report_aging.php" class="dashboard-card" style="text-decoration:none;">
        <div class="card-header"><h3><i data-lucide="clock"></i> Aging Report</h3></div>
        <div class="card-content"><p>Outstanding invoices by age bracket.</p></div>
    </a>
    <a href="report_inventory.php" class="dashboard-card" style="text-decoration:none;">
        <div class="card-header"><h3><i data-lucide="boxes"></i> Inventory Report</h3></div>
        <div class="card-content"><p>Stock levels, low stock alerts, material usage.</p></div>
    </a>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
