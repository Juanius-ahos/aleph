<?php
$pageTitle = 'Add Purchase Order';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$suppliers = dbFetchAll($db, "SELECT id, company_name FROM suppliers WHERE active=1 ORDER BY company_name");
$materials = dbFetchAll($db, "SELECT id, name, unit_cost FROM materials WHERE active=1 ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $expectedDate = $_POST['expected_date'] ?? null;
    $notes = clean($_POST['notes'] ?? '');
    $itemDescriptions = $_POST['item_description'] ?? [];
    $itemQuantities = $_POST['item_quantity'] ?? [];
    $itemPrices = $_POST['item_unit_price'] ?? [];

    if ($supplierId <= 0 || empty($itemDescriptions)) {
        setFlash('error', 'Supplier and items required.'); header('Location: purchase_order_add.php'); exit;
    }

    $subtotal = 0; $items = [];
    for ($i = 0; $i < count($itemDescriptions); $i++) {
        $desc = clean($itemDescriptions[$i] ?? '');
        $qty = (float)($itemQuantities[$i] ?? 1);
        $price = (float)($itemPrices[$i] ?? 0);
        if ($desc && $qty > 0) { $items[] = ['description'=>$desc,'quantity'=>$qty,'unit_cost'=>$price,'total'=>$qty*$price]; $subtotal += $qty*$price; }
    }

    $db->beginTransaction();
    try {
        $poNumber = generatePoNumber($db);
        $poId = dbInsert($db, 'purchase_orders', [
            'po_number' => $poNumber, 'supplier_id' => $supplierId,
            'status' => 'draft', 'order_date' => $orderDate,
            'expected_date' => $expectedDate ?: null,
            'subtotal' => $subtotal, 'total' => $subtotal,
            'notes' => $notes, 'created_by' => currentUserId(),
        ]);
        foreach ($items as $item) {
            dbInsert($db, 'purchase_order_items', [
                'po_id' => $poId, 'description' => $item['description'],
                'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost'],
                'total' => $item['total'],
            ]);
        }
        $db->commit();
        setFlash('success', "PO #$poNumber created.");
        header('Location: purchase_orders.php'); exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed: '.$e->getMessage()); header('Location: purchase_order_add.php'); exit;
    }
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Add Purchase Order</h1></div><div class="page-actions"><a href="purchase_orders.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<form method="POST">
    <?= csrfField() ?>
    <div class="card">
        <div class="form-grid">
            <div class="form-group"><label>Supplier *</label>
                <select name="supplier_id" class="form-control" required>
                    <option value="">Select</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= h($s['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Order Date</label><input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>Expected Date</label><input type="date" name="expected_date" class="form-control"></div>
            <div class="form-group full-width"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
    </div>
    <div class="card" style="margin-top:20px;">
        <h3>Items</h3>
        <div id="itemsContainer">
            <div class="item-row" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end;">
                <div><label>Description *</label><input type="text" name="item_description[]" class="form-control" required></div>
                <div><label>Qty *</label><input type="number" name="item_quantity[]" class="form-control" value="1" min="0.01" step="0.01" required></div>
                <div><label>Price *</label><input type="number" name="item_unit_price[]" class="form-control" step="0.01" required></div>
                <div><button type="button" class="btn btn-danger btn-sm remove-item" style="margin-top:24px;"><i data-lucide="times"></i></button></div>
            </div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="addItem()"><i data-lucide="plus"></i> Add Item</button>
    </div>
    <div style="margin-top:20px;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create PO</button>
        <a href="purchase_orders.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<script>
function addItem(){var c=document.getElementById('itemsContainer'),r=document.createElement('div');r.className='item-row';r.style.cssText='display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end;';r.innerHTML='<div><input type="text" name="item_description[]" class="form-control" required placeholder="Description"></div><div><input type="number" name="item_quantity[]" class="form-control" value="1" min="0.01" step="0.01" required></div><div><input type="number" name="item_unit_price[]" class="form-control" step="0.01" required></div><div><button type="button" class="btn btn-danger btn-sm remove-item"><i data-lucide="times"></i></button></div>';c.appendChild(r);r.querySelector('.remove-item').addEventListener('click',function(){r.remove();});}
document.querySelectorAll('.remove-item').forEach(function(b){b.addEventListener('click',function(){b.closest('.item-row').remove();});});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
