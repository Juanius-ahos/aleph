<?php
$pageTitle = 'Materials & Inventory';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';

$wheres = ["active = 1"];
$params = [];
if ($search) {
    $wheres[] = "(name LIKE ? OR category LIKE ? OR sku LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s]);
}
if ($category) { $wheres[] = "category = ?"; $params[] = $category; }
$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT * FROM materials WHERE $where ORDER BY name", $params, 20, $page);
$materials = $result['rows'];
$categories = dbFetchAll($db, "SELECT DISTINCT category FROM materials WHERE category IS NOT NULL AND category != '' AND active=1 ORDER BY category");
?>

<div class="page-header">
    <div class="page-title"><h1>Materials & Inventory</h1><p class="page-subtitle"><?= $result['total'] ?> items</p></div>
    <div class="page-actions">
        <a href="material_add.php" class="btn btn-primary"><i data-lucide="plus"></i> Add Material</a>
        <a href="export.php?type=materials" class="btn btn-secondary"><i data-lucide="download"></i> Export</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group"><input type="text" name="search" placeholder="Search materials..." value="<?= h($search) ?>" class="form-control"></div>
        <div class="filter-group">
            <select name="category" class="form-control">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['category']) ?>" <?= $category===$cat['category']?'selected':'' ?>><?= h($cat['category']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>Name</th><th>SKU</th><th>Category</th><th>Stock</th><th>Min Stock</th><th>Unit Cost</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($materials)): ?>
                <tr><td colspan="8" class="empty-state">No materials found</td></tr>
            <?php else: ?>
                <?php foreach ($materials as $m): ?>
                <tr>
                    <td><?= h($m['name']) ?></td>
                    <td><?= h($m['sku'] ?? '—') ?></td>
                    <td><?= h($m['category'] ?? '—') ?></td>
                    <td style="font-weight:600;"><?= h($m['stock_qty']) ?></td>
                    <td><?= h($m['min_stock']) ?></td>
                    <td><?= formatMoney($m['unit_cost']) ?></td>
                    <td>
                        <?php if ($m['stock_qty'] <= $m['min_stock']): ?>
                            <span class="badge badge-danger">Low Stock</span>
                        <?php else: ?>
                            <span class="badge badge-success">In Stock</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="material_edit.php?id=<?= $m['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php renderPagination($result, $_GET); ?>
<?php require_once __DIR__ . '/footer.php'; ?>
