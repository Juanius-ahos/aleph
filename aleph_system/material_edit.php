<?php
$pageTitle = 'Edit Material';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid ID.'); header('Location: materials.php'); exit; }

$material = dbFetch($db, "SELECT * FROM materials WHERE id=?", [$id]);
if (!$material) { setFlash('error', 'Not found.'); header('Location: materials.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'name' => clean($_POST['name'] ?? ''),
        'sku' => clean($_POST['sku'] ?? ''),
        'category' => clean($_POST['category'] ?? ''),
        'description' => clean($_POST['description'] ?? ''),
        'unit' => clean($_POST['unit'] ?? 'kg'),
        'unit_cost' => (float)($_POST['unit_cost'] ?? 0),
        'stock_qty' => (float)($_POST['stock_qty'] ?? 0),
        'min_stock' => (float)($_POST['min_stock'] ?? 0),
    ];
    dbUpdate($db, 'materials', $data, 'id = ?', [$id]);
    setFlash('success', 'Material updated.');
    header("Location: materials.php"); exit;
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Edit Material</h1></div><div class="page-actions"><a href="materials.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" required value="<?= h($material['name']) ?>"></div>
        <div class="form-group"><label>SKU</label><input type="text" name="sku" class="form-control" value="<?= h($material['sku']) ?>"></div>
        <div class="form-group"><label>Category</label><input type="text" name="category" class="form-control" value="<?= h($material['category']) ?>"></div>
        <div class="form-group"><label>Unit</label><input type="text" name="unit" class="form-control" value="<?= h($material['unit']) ?>"></div>
        <div class="form-group"><label>Unit Cost</label><input type="number" name="unit_cost" class="form-control" step="0.01" value="<?= h($material['unit_cost']) ?>"></div>
        <div class="form-group"><label>Stock Qty</label><input type="number" name="stock_qty" class="form-control" step="0.01" value="<?= h($material['stock_qty']) ?>"></div>
        <div class="form-group"><label>Min Stock</label><input type="number" name="min_stock" class="form-control" step="0.01" value="<?= h($material['min_stock']) ?>"></div>
        <div class="form-group full-width"><label>Description</label><textarea name="description" class="form-control" rows="2"><?= h($material['description']) ?></textarea></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
