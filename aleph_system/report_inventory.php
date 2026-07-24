<?php
$pageTitle = 'Inventory Report';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$allMaterials = dbFetchAll($db, "SELECT * FROM materials WHERE active=1 AND deleted_at IS NULL ORDER BY name");
$lowStock = dbFetchAll($db, "SELECT * FROM materials WHERE stock_qty <= min_stock AND active=1 AND deleted_at IS NULL ORDER BY name");
$totalValue = (float)(dbFetch($db, "SELECT COALESCE(SUM(stock_qty * unit_cost),0) as t FROM materials WHERE active=1 AND deleted_at IS NULL")['t'] ?? 0);
?>

<div class="page-header">
    <div class="page-title"><h1>Inventory Report</h1></div>
    <div class="page-actions"><a href="reports.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i data-lucide="boxes"></i></div><div class="stat-info"><div class="stat-label">Total Items</div><div class="stat-value"><?= count($allMaterials) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i data-lucide="exclamation-triangle"></i></div><div class="stat-info"><div class="stat-label">Low Stock</div><div class="stat-value"><?= count($lowStock) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i data-lucide="dollar-sign"></i></div><div class="stat-info"><div class="stat-label">Total Value</div><div class="stat-value"><?= formatMoney($totalValue) ?></div></div></div>
</div>

<?php if (!empty($lowStock)): ?>
<div class="card" style="margin-top:20px;">
    <h3>Low Stock Alerts</h3>
    <table class="data-table">
        <thead><tr><th>Material</th><th>Stock</th><th>Min Stock</th><th>Category</th></tr></thead>
        <tbody>
            <?php foreach ($lowStock as $m): ?>
            <tr>
                <td><?= h($m['name']) ?></td>
                <td style="font-weight:600;color:#dc2626;"><?= h($m['stock_qty']) ?></td>
                <td><?= h($m['min_stock']) ?></td>
                <td><?= h($m['category'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px;">
    <h3>All Materials</h3>
    <table class="data-table">
        <thead><tr><th>Name</th><th>SKU</th><th>Category</th><th>Stock</th><th>Min Stock</th><th>Unit Cost</th><th>Value</th></tr></thead>
        <tbody>
            <?php foreach ($allMaterials as $m): ?>
            <tr>
                <td><?= h($m['name']) ?></td>
                <td><?= h($m['sku'] ?? '—') ?></td>
                <td><?= h($m['category'] ?? '—') ?></td>
                <td><?= h($m['stock_qty']) ?></td>
                <td><?= h($m['min_stock']) ?></td>
                <td><?= formatMoney($m['unit_cost']) ?></td>
                <td style="font-weight:600;"><?= formatMoney($m['stock_qty'] * $m['unit_cost']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
