<?php
$pageTitle = 'View Invoice';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid invoice ID.'); header('Location: invoices.php'); exit; }

$invoice = dbFetch($db, "SELECT i.*, c.company_name, c.contact_name, c.email FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id WHERE i.id=? AND i.deleted_at IS NULL", [$id]);
if (!$invoice) { setFlash('error', 'Invoice not found.'); header('Location: invoices.php'); exit; }

$items = dbFetchAll($db, "SELECT ii.*, p.name as product_name FROM invoice_items ii LEFT JOIN products p ON ii.product_id=p.id WHERE ii.invoice_id=?", [$id]);
$payments = dbFetchAll($db, "SELECT * FROM payments WHERE invoice_id=? AND voided = 0 ORDER BY payment_date ASC", [$id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $method = $_POST['payment_method'] ?? 'cash';
        $payDate = $_POST['payment_date'] ?? date('Y-m-d');
        $ref = clean($_POST['reference_number'] ?? '');

        if ($amount <= 0) {
            setFlash('error', 'Invalid payment amount.');
            header('Location: invoice_view.php?id=' . $id);
            exit;
        }

        $db->beginTransaction();
        try {
            $fresh = dbFetch($db, "SELECT total, amount_paid FROM invoices WHERE id=? FOR UPDATE", [$id]);
            if (!$fresh) throw new Exception('Invoice not found');
            $totalPaid = (float)$fresh['amount_paid'] + $amount;
            $newBalance = max(0, (float)$fresh['total'] - $totalPaid);
            $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

            $paymentId = dbInsert($db, 'payments', [
                'invoice_id' => $id, 'amount' => $amount, 'payment_method' => $method,
                'payment_date' => $payDate, 'reference_number' => $ref, 'created_by' => currentUserId(),
            ]);

            if ($paymentId) {
                dbUpdate($db, 'invoices', [
                    'amount_paid' => $totalPaid, 'balance_due' => $newBalance, 'status' => $newStatus
                ], 'id = ?', [$id]);
                $db->commit();
                logActivity('payments', 'create', 'payment', $paymentId, "Payment of $amount for invoice #{$invoice['invoice_number']}");
                setFlash('success', 'Payment recorded successfully.');
            } else {
                $db->rollBack();
                setFlash('error', 'Failed to record payment.');
            }
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Payment recording error: " . $e->getMessage());
            setFlash('error', 'Failed to record payment: ' . $e->getMessage());
        }
        header('Location: invoice_view.php?id=' . $id);
        exit;
    }

    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        if (in_array($newStatus, ['draft','sent','partial','paid','overdue','cancelled'])) {
            dbUpdate($db, 'invoices', ['status' => $newStatus], 'id = ?', [$id]);
            setFlash('success', 'Invoice status updated.');
        }
        header('Location: invoice_view.php?id=' . $id);
        exit;
    }
}

$totalPaid = (float)$invoice['amount_paid'];
$balanceDue = (float)$invoice['balance_due'];

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1>Invoice #<?= str_pad($invoice['invoice_number'], 4, '0', STR_PAD_LEFT) ?></h1>
        <p class="page-subtitle"><?= h($invoice['company_name'] ?? 'N/A') ?></p>
    </div>
    <div class="page-actions">
        <a href="invoice_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-secondary"><i data-lucide="print"></i> Print</a>
        <a href="invoices.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3>Invoice Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Customer</span><span class="detail-value"><a href="customer_view.php?id=<?= $invoice['customer_id'] ?>"><?= h($invoice['company_name'] ?? 'N/A') ?></a></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($invoice['status']) ?>"><?= ucfirst(h($invoice['status'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Invoice Date</span><span class="detail-value"><?= formatDate($invoice['invoice_date']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Due Date</span><span class="detail-value"><?= $invoice['due_date'] ? formatDate($invoice['due_date']) : '—' ?></span></div>
        </div>
    </div>
    <div class="detail-card">
        <h3>Amounts</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Subtotal</span><span class="detail-value"><?= formatMoney($invoice['subtotal']) ?></span></div>
            <?php if ((float)$invoice['discount_amount'] > 0): ?>
            <div class="detail-row"><span class="detail-label">Discount</span><span class="detail-value" style="color:#dc2626;">-<?= formatMoney($invoice['discount_amount']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$invoice['tax_amount'] > 0): ?>
            <div class="detail-row"><span class="detail-label">Tax</span><span class="detail-value"><?= formatMoney($invoice['tax_amount']) ?></span></div>
            <?php endif; ?>
            <div class="detail-row"><span class="detail-label" style="font-weight:700;">Total</span><span class="detail-value" style="font-weight:700;font-size:18px;"><?= formatMoney($invoice['total']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Paid</span><span class="detail-value" style="color:#059669;"><?= formatMoney($totalPaid) ?></span></div>
            <div class="detail-row"><span class="detail-label" style="font-weight:700;">Balance Due</span><span class="detail-value" style="font-weight:700;color:<?= $balanceDue > 0 ? '#dc2626' : '#059669' ?>;font-size:18px;"><?= formatMoney($balanceDue) ?></span></div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h3>Line Items</h3>
    <table class="data-table">
        <thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" class="empty-state">No items</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= h($item['product_name'] ?? 'N/A') ?></td>
                    <td><?= h($item['description']) ?></td>
                    <td><?= h($item['quantity']) ?></td>
                    <td><?= formatMoney($item['unit_price']) ?></td>
                    <td style="font-weight:600;"><?= formatMoney($item['total']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($payments)): ?>
<div class="card" style="margin-top:20px;">
    <h3>Payment History</h3>
    <table class="data-table">
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead>
        <tbody>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= formatDate($p['payment_date']) ?></td>
                <td style="color:#059669;font-weight:600;"><?= formatMoney($p['amount']) ?></td>
                <td><?= ucfirst(h($p['payment_method'])) ?></td>
                <td><?= h($p['reference_number'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($balanceDue > 0 && !in_array($invoice['status'], ['paid','cancelled'])): ?>
<div class="card" style="margin-top:20px;">
    <h3>Record Payment</h3>
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_payment">
        <div class="form-group">
            <label>Amount <span class="required">*</span></label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?= $balanceDue ?>" required value="<?= $balanceDue ?>">
        </div>
        <div class="form-group">
            <label>Payment Method</label>
            <select name="payment_method" class="form-control">
                <option value="cash">Cash</option>
                <option value="check">Check</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="online">Online</option>
            </select>
        </div>
        <div class="form-group">
            <label>Payment Date</label>
            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
            <label>Reference Number</label>
            <input type="text" name="reference_number" class="form-control" placeholder="Check #, Transaction ID, etc.">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success"><i data-lucide="dollar-sign"></i> Record Payment</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
