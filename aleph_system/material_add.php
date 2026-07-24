<?php
$pageTitle = 'Add Material';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
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
    if (empty($data['name'])) { setFlash('error', 'Name required.'); header('Location: material_add.php'); exit; }
    $id = dbInsert($db, 'materials', $data);
    if ($id) { setFlash('success', 'Material created.'); header('Location: materials.php'); exit; }
    setFlash('error', 'Failed.'); header('Location: material_add.php'); exit;
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Add Material</h1></div><div class="page-actions"><a href="materials.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label>SKU</label><input type="text" name="sku" class="form-control"></div>
        <div class="form-group"><label>Category</label><input type="text" name="category" class="form-control"></div>
        <div class="form-group"><label>Unit</label><input type="text" name="unit" class="form-control" value="kg"></div>
        <div class="form-group"><label>Unit Cost</label><input type="number" name="unit_cost" class="form-control" step="0.01" value="0"></div>
        <div class="form-group"><label>Stock Qty</label><input type="number" name="stock_qty" class="form-control" step="0.01" value="0"></div>
        <div class="form-group"><label>Min Stock</label><input type="number" name="min_stock" class="form-control" step="0.01" value="0"></div>
        <div class="form-group full-width"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
