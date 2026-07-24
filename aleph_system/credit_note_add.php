<?php
$pageTitle = 'Add Credit Note';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$customers = dbFetchAll($db, "SELECT id, company_name FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
$invoices = dbFetchAll($db, "SELECT i.id, i.invoice_number, i.total, i.balance_due, c.company_name FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id WHERE i.status NOT IN ('paid','cancelled') AND i.deleted_at IS NULL ORDER BY i.invoice_number DESC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $invoiceId = (int)($_POST['invoice_id'] ?? 0) ?: null;
    $creditDate = $_POST['credit_date'] ?? date('Y-m-d');
    $reason = clean($_POST['reason'] ?? '');
    $total = (float)($_POST['total'] ?? 0);

    if ($customerId <= 0 || $total <= 0) {
        setFlash('error', 'Customer and amount required.'); header('Location: credit_note_add.php'); exit;
    }

    $db->beginTransaction();
    try {
        $cnNumber = generateCreditNoteNumber($db);
        $cnId = dbInsert($db, 'credit_notes', [
            'credit_note_number' => $cnNumber, 'invoice_id' => $invoiceId,
            'customer_id' => $customerId, 'status' => 'issued',
            'credit_date' => $creditDate, 'reason' => $reason,
            'subtotal' => $total, 'total' => $total,
            'created_by' => currentUserId(),
        ]);
        if ($cnId && $invoiceId) {
            $inv = dbFetch($db, "SELECT balance_due FROM invoices WHERE id=?", [$invoiceId]);
            if ($inv) {
                $newBalance = max(0, (float)$inv['balance_due'] - $total);
                $newStatus = $newBalance <= 0 ? 'paid' : 'partial';
                dbUpdate($db, 'invoices', ['balance_due' => $newBalance, 'status' => $newStatus], 'id = ?', [$invoiceId]);
            }
        }
        $db->commit();
        setFlash('success', "Credit note #$cnNumber created.");
        header('Location: credit_notes.php'); exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed: ' . $e->getMessage()); header('Location: credit_note_add.php'); exit;
    }
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Add Credit Note</h1></div><div class="page-actions"><a href="credit_notes.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Customer *</label>
            <select name="customer_id" class="form-control" required>
                <option value="">Select</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Related Invoice</label>
            <select name="invoice_id" class="form-control">
                <option value="">None</option>
                <?php foreach ($invoices as $i): ?>
                    <option value="<?= $i['id'] ?>">INV-<?= str_pad($i['invoice_number'],4,'0',STR_PAD_LEFT) ?> - <?= h($i['company_name']) ?> (<?= formatMoney($i['balance_due']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Credit Date</label><input type="date" name="credit_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label>Amount *</label><input type="number" name="total" class="form-control" step="0.01" min="0.01" required></div>
        <div class="form-group full-width"><label>Reason</label><textarea name="reason" class="form-control" rows="3"></textarea></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
