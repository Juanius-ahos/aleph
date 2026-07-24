<?php
$pageTitle = 'Create Invoice';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$customers = dbFetchAll($db, "SELECT id, company_name FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
$products = dbFetchAll($db, "SELECT id, name, unit_price FROM products WHERE active=1 AND deleted_at IS NULL ORDER BY name");
$jobs = dbFetchAll($db, "SELECT j.id, j.job_number, j.title, j.customer_id, j.selling_price, c.company_name FROM jobs j LEFT JOIN customers c ON j.customer_id=c.id WHERE j.status IN ('active','completed') AND j.deleted_at IS NULL ORDER BY j.job_number DESC");
$preselectedCustomer = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$preselectedJob = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $jobId = (int)($_POST['job_id'] ?? 0) ?: null;
    $invoiceDate = $_POST['invoice_date'] ?? date('Y-m-d');
    $dueDate = $_POST['due_date'] ?? null;
    $notes = clean($_POST['notes'] ?? '');
    $terms = clean($_POST['terms'] ?? '');
    $itemDescriptions = $_POST['item_description'] ?? [];
    $itemQuantities = $_POST['item_quantity'] ?? [];
    $itemPrices = $_POST['item_unit_price'] ?? [];

    if ($customerId <= 0 || empty($itemDescriptions)) {
        setFlash('error', 'Customer and at least one item are required.');
        header('Location: invoice_add.php');
        exit;
    }

    $subtotal = 0;
    $items = [];
    for ($i = 0; $i < count($itemDescriptions); $i++) {
        $desc = clean($itemDescriptions[$i] ?? '');
        $qty = (float)($itemQuantities[$i] ?? 1);
        $price = (float)($itemPrices[$i] ?? 0);
        if ($desc && $qty > 0) {
            $total = $qty * $price;
            $items[] = ['description'=>$desc, 'quantity'=>$qty, 'unit_price'=>$price, 'total'=>$total];
            $subtotal += $total;
        }
    }

    $taxRate = (float)($_POST['tax_rate'] ?? 0);
    $taxAmount = $subtotal * ($taxRate / 100);
    $discountType = $_POST['discount_type'] ?? null;
    $discountValue = (float)($_POST['discount_value'] ?? 0);
    $discountAmount = ($discountType === 'percentage') ? $subtotal * ($discountValue / 100) : ($discountType === 'fixed' ? $discountValue : 0);
    $total = $subtotal - $discountAmount + $taxAmount;

    $db->beginTransaction();
    try {
        $invoiceNumber = generateInvoiceNumber($db);
        $invoiceId = dbInsert($db, 'invoices', [
            'invoice_number' => $invoiceNumber, 'job_id' => $jobId,
            'customer_id' => $customerId, 'status' => 'draft',
            'invoice_date' => $invoiceDate, 'due_date' => $dueDate ?: null,
            'subtotal' => $subtotal, 'discount_type' => $discountType,
            'discount_value' => $discountValue, 'discount_amount' => $discountAmount,
            'tax_rate' => $taxRate, 'tax_amount' => $taxAmount,
            'total' => $total, 'balance_due' => $total,
            'notes' => $notes, 'terms' => $terms, 'created_by' => currentUserId(),
        ]);

        if (!$invoiceId) throw new Exception('Failed to create invoice');

        $sortOrder = 0;
        foreach ($items as $item) {
            dbInsert($db, 'invoice_items', [
                'invoice_id' => $invoiceId, 'description' => $item['description'],
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
                'total' => $item['total'], 'sort_order' => $sortOrder++,
            ]);
        }

        $db->commit();
        logActivity('invoices', 'create', 'invoice', $invoiceId, "Created invoice #$invoiceNumber");
        setFlash('success', "Invoice #$invoiceNumber created.");
        header('Location: invoice_view.php?id=' . $invoiceId);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed: ' . $e->getMessage());
        header('Location: invoice_add.php');
        exit;
    }
}
?>

<div class="page-header">
    <div class="page-title"><h1>Create Invoice</h1></div>
    <div class="page-actions"><a href="invoices.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>

<form method="POST" id="invoiceForm">
    <?= csrfField() ?>
    <div class="card">
        <h3>Invoice Details</h3>
        <div class="form-grid">
            <div class="form-group"><label>Customer <span class="required">*</span></label>
                <select name="customer_id" class="form-control" required>
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id']===$preselectedCustomer?'selected':'' ?>><?= h($c['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>From Job</label>
                <select name="job_id" class="form-control">
                    <option value="">None</option>
                    <?php foreach ($jobs as $j): ?>
                        <option value="<?= $j['id'] ?>" <?= $j['id']===$preselectedJob?'selected':'' ?>>J-<?= str_pad($j['job_number'],4,'0',STR_PAD_LEFT) ?> - <?= h($j['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Invoice Date</label><input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>Due Date</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'))) ?>"></div>
            <div class="form-group"><label>Tax Rate (%)</label><input type="number" name="tax_rate" class="form-control" step="0.01" value="0"></div>
            <div class="form-group"><label>Discount Type</label>
                <select name="discount_type" class="form-control"><option value="">None</option><option value="percentage">Percentage</option><option value="fixed">Fixed</option></select>
            </div>
            <div class="form-group"><label>Discount Value</label><input type="number" name="discount_value" class="form-control" step="0.01" value="0"></div>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3>Line Items</h3>
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

    <div class="card" style="margin-top:20px;">
        <div class="form-grid">
            <div class="form-group full-width"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <div class="form-group full-width"><label>Terms</label><textarea name="terms" class="form-control" rows="2"></textarea></div>
        </div>
    </div>

    <div style="margin-top:20px;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create Invoice</button>
        <a href="invoices.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<script>
function addItem() {
    var c = document.getElementById('itemsContainer');
    var r = document.createElement('div');
    r.className = 'item-row';
    r.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end;';
    r.innerHTML = '<div><input type="text" name="item_description[]" class="form-control" required placeholder="Description"></div><div><input type="number" name="item_quantity[]" class="form-control" value="1" min="0.01" step="0.01" required></div><div><input type="number" name="item_unit_price[]" class="form-control" step="0.01" required></div><div><button type="button" class="btn btn-danger btn-sm remove-item"><i data-lucide="times"></i></button></div>';
    c.appendChild(r);
    r.querySelector('.remove-item').addEventListener('click', function() { r.remove(); });
}
document.querySelectorAll('.remove-item').forEach(function(b) { b.addEventListener('click', function() { b.closest('.item-row').remove(); }); });
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
