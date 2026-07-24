<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid invoice ID.'); header('Location: invoices.php'); exit; }

$invoice = dbFetch($db, "SELECT i.*, c.company_name, c.contact_name, c.email, c.phone, c.address, c.city, c.country, e.full_name AS employee_name FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id LEFT JOIN users e ON i.created_by=e.id WHERE i.id=? AND i.deleted_at IS NULL", [$id]);

if (!$invoice) { setFlash('error', 'Invoice not found.'); header('Location: invoices.php'); exit; }

$items = dbFetchAll($db, "SELECT ii.*, p.name AS product_name FROM invoice_items ii LEFT JOIN products p ON ii.product_id=p.id WHERE ii.invoice_id=?", [$id]);
$payments = dbFetchAll($db, "SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date ASC", [$id]);

function calculateInvoiceWeightedCompletion($invoiceId, $db) {
    $job = dbFetch($db, "SELECT id, stage FROM jobs WHERE id=(SELECT job_id FROM invoices WHERE id=?) AND deleted_at IS NULL", [$invoiceId]);
    if (!$job) return null;

    $stages = ['design','prepress','printing','finishing','qc','packaging','delivered','completed'];
    $completionByStage = [0,14,28,42,56,70,85,100];
    $stageIndex = array_search($job['stage'], $stages);
    if ($stageIndex === false) return 0;

    $completionPercentages = dbFetchAll($db, "SELECT stage, completion_percentage FROM job_stage_progress WHERE job_id=? AND deleted_at IS NULL", [$job['id']]);
    $percentages = [];
    foreach ($completionPercentages as $record) {
        $percentages[$record['stage']] = $record['completion_percentage'];
    }

    $totalWeight = 0;
    $totalCompletion = 0;
    for ($i = 0; $i < count($stages); $i++) {
        if (isset($percentages[$stages[$i]])) {
            $totalWeight += $completionByStage[$i];
            $totalCompletion += $percentages[$stages[$i]] * $completionByStage[$i];
        }
    }

    if ($totalWeight > 0) {
        return round($totalCompletion / $totalWeight);
    }
    return $completionByStage[$stageIndex];
}

$weightedCompletion = calculateInvoiceWeightedCompletion($id, $db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Invoice #<?= str_pad($invoice['invoice_number'], 4, '0', STR_PAD_LEFT) ?> - <?= h(APP_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=10">
    <style>
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .invoice-container { box-shadow: none; border-radius: 0; max-width: none; width: 100%; margin: 0; padding: 20px; border: none; }
            .no-print, .header-nav, .mobile-nav-toggle, .sidebar { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; padding-top: 0 !important; }
            .progress-section, .progress-bar-fill, .completion-percentage { display: none !important; }
            .progress-section * { display: none !important; }
        }
        .main-content { padding: 20px; padding-top: 0 !important; }
        .header-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header-nav .back-btn { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: #6b7280; font-weight: 500; transition: color 0.2s; }
        .header-nav .back-btn:hover { color: #f25424; }
        .header-nav .back-btn i { font-size: 14px; }
        .header-nav h1 { margin: 0; font-size: 20px; font-weight: 600; }
        .progress-section { background: linear-gradient(135deg, #fef3e2 0%, #fde2b4 100%); border: 1px solid #f25424; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .progress-section h3 { margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #2d3748; }
        .progress-bar-container { display: flex; align-items: center; gap: 12px; }
        .progress-bar { flex: 1; height: 12px; background: white; border-radius: 8px; overflow: hidden; border: 1px solid rgba(242, 84, 36, 0.3); }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, #f25424 0%, #ff7b54 100%); border-radius: 8px; transition: width 0.6s ease; position: relative; }
        .progress-bar-fill::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(90deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 100%); }
        .completion-percentage { font-size: 14px; font-weight: 700; color: #f25424; min-width: 40px; }
        .invoice-container { max-width: 800px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; }
        .invoice-header { background: linear-gradient(135deg, #1a2332 0%, #2d3748 100%); color: white; padding: 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .invoice-header .company-info h2 { margin: 0 0 4px 0; font-size: 18px; font-weight: 700; color: #f25424; }
        .invoice-header .company-info p { margin: 0; font-size: 12px; color: #9ca3af; }
        .invoice-header .invoice-title { text-align: right; }
        .invoice-header .invoice-title h3 { margin: 0 0 4px 0; font-size: 24px; font-weight: 700; }
        .invoice-header .invoice-title p { margin: 0; font-size: 12px; color: #9ca3af; }
        .invoice-body { padding: 24px; }
        .invoice-meta { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        .meta-group label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
        .meta-group span { display: block; font-size: 14px; color: #2d3748; font-weight: 500; }
        .meta-group .status-badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .meta-group .status-pending { background: #fef3c7; color: #92400e; }
        .meta-group .status-paid { background: #d1fae5; color: #065f46; }
        .meta-group .status-overdue { background: #fee2e2; color: #991b1b; }
        .meta-group .status-partial { background: #fff7ed; color: #c2410c; }
        .meta-group .status-cancelled { background: #f3f4f6; color: #374151; }
        .meta-group .status-sent { background: #e0f2fe; color: #075985; }
        .meta-group .status-draft { background: #f3f4f6; color: #374151; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .items-table th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; }
        .items-table td { border: 1px solid #e5e7eb; padding: 10px 12px; font-size: 13px; }
        .items-table tr:nth-child(even) td { background: #fafafa; }
        .invoice-summary { display: flex; justify-content: flex-end; }
        .summary-box { width: 250px; }
        .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .summary-row.total { border-bottom: 2px solid #f25424; font-weight: 700; color: #f25424; font-size: 16px; }
        .notes { margin-top: 24px; padding: 16px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb; }
        .notes h4 { margin: 0 0 8px 0; font-size: 13px; color: #6b7280; font-weight: 600; }
        .notes p { margin: 0; font-size: 13px; color: #374151; }
        .payment-history { margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e7eb; }
        .payment-history h3 { margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #2d3748; }
        .payment-history .payment-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
        .payment-history .payment-item:last-child { border-bottom: none; }
        .payment-history .payment-date { color: #6b7280; }
        .payment-history .payment-method { color: #9ca3af; font-size: 12px; }
        .payment-history .payment-amount { font-weight: 600; color: #059669; }
        .invoice-footer { margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: flex-end; font-size: 12px; color: #6b7280; }
        .invoice-footer .terms h4 { margin: 0 0 4px 0; color: #374151; }
        .invoice-footer .terms p { margin: 0; line-height: 1.4; }
        .invoice-footer .contact { text-align: right; }
        .invoice-footer .contact p { margin: 0 0 4px 0; }
        .invoice-footer .contact .website { color: #f25424; font-weight: 500; }
        .print-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #f25424; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.2s; text-decoration: none; }
        .print-btn:hover { background: #e04a1e; }
        @media (max-width: 768px) {
            .invoice-header { flex-direction: column; text-align: center; gap: 16px; }
            .invoice-header .invoice-title { text-align: center; }
            .invoice-meta { grid-template-columns: 1fr 1fr; }
            .invoice-summary { justify-content: flex-end; }
        }
    </style>
</head>
<body class="login-page">
    <div class="main-content" style="margin-left:0; padding-top:20px;">
        <header class="header-nav no-print">
            <a href="invoices.php" class="back-btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                Back to Invoices
            </a>
            <h1>Invoice <?= str_pad($invoice['invoice_number'], 4, '0', STR_PAD_LEFT) ?></h1>
            <button class="print-btn" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Invoice
            </button>
        </header>

        <?php if ($weightedCompletion !== null): ?>
        <div class="progress-section no-print" style="margin-bottom:20px;">
            <h3><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Job Completion Progress</h3>
            <div class="progress-bar-container">
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width: <?= $weightedCompletion ?>%"></div>
                </div>
                <span class="completion-percentage"><?= $weightedCompletion ?>%</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="invoice-container" style="max-width:800px;margin:0 auto;">
            <div class="invoice-header">
                <div class="company-info">
                    <h2><?= h(APP_NAME) ?></h2>
                    <p>Printing & Graphics</p>
                </div>
                <div class="invoice-title">
                    <h3>INVOICE</h3>
                    <p>#<?= str_pad($invoice['invoice_number'], 4, '0', STR_PAD_LEFT) ?></p>
                </div>
            </div>

            <div class="invoice-body">
                <div class="invoice-meta">
                    <div class="meta-group">
                        <label>Bill To</label>
                        <span><?= h($invoice['company_name'] ?? $invoice['contact_name'] ?? 'N/A') ?></span>
                        <?php if (!empty($invoice['address'])): ?>
                            <span style="font-size:12px;color:#6b7280;"><?= h($invoice['address']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($invoice['city']) || !empty($invoice['country'])): ?>
                            <span style="font-size:12px;color:#6b7280;"><?= h($invoice['city'] ?? '') ?><?= !empty($invoice['city']) && !empty($invoice['country']) ? ', ' : '' ?><?= h($invoice['country'] ?? '') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="meta-group">
                        <label>Status</label>
                        <span class="status-badge status-<?= h($invoice['status']) ?>"><?= ucfirst(h($invoice['status'])) ?></span>
                    </div>
                    <div class="meta-group">
                        <label>Details</label>
                        <span>Invoice Date: <?= h(formatDate($invoice['invoice_date'])) ?></span>
                        <?php if (!empty($invoice['due_date'])): ?>
                            <span>Due Date: <?= h(formatDate($invoice['due_date'])) ?></span>
                        <?php endif; ?>
                        <span>Created By: <?= h($invoice['employee_name'] ?? '') ?></span>
                    </div>
                </div>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center;color:#9ca3af;padding:20px;">No line items</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td style="font-weight:500;"><?= h($item['product_name'] ?? 'N/A') ?></td>
                                <td><?= h($item['description']) ?></td>
                                <td><?= h($item['quantity']) ?></td>
                                <td><?= h(formatMoney($item['unit_price'])) ?></td>
                                <td style="font-weight:600;"><?= h(formatMoney($item['quantity'] * $item['unit_price'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="invoice-summary">
                    <div class="summary-box">
                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span><?= formatMoney($invoice['subtotal']) ?></span>
                        </div>
                        <?php if ((float)($invoice['discount_amount'] ?? 0) > 0): ?>
                        <div class="summary-row">
                            <span>Discount:</span>
                            <span style="color:#dc2626;">-<?= formatMoney($invoice['discount_amount']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ((float)($invoice['tax_amount'] ?? 0) > 0): ?>
                        <div class="summary-row">
                            <span>Tax:</span>
                            <span><?= formatMoney($invoice['tax_amount']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-row total">
                            <span>Total:</span>
                            <span><?= formatMoney($invoice['total']) ?></span>
                        </div>
                        <?php if ((float)($invoice['amount_paid'] ?? 0) > 0): ?>
                        <div class="summary-row" style="color:#059669;">
                            <span>Paid:</span>
                            <span>-<?= formatMoney($invoice['amount_paid']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ((float)($invoice['balance_due'] ?? 0) > 0): ?>
                        <div class="summary-row total">
                            <span>Balance Due:</span>
                            <span><?= formatMoney($invoice['balance_due']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($invoice['notes'])): ?>
                <div class="notes">
                    <h4>Notes</h4>
                    <p><?= nl2br(h($invoice['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($payments)): ?>
                <div class="payment-history">
                    <h3>Payment History</h3>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-item">
                        <div>
                            <span class="payment-date"><?= formatDate($payment['payment_date']) ?></span>
                            <span class="payment-method"><?= h($payment['payment_method']) ?></span>
                            <?php if (!empty($payment['reference_number'])): ?>
                                <span style="color:#9ca3af;font-size:11px;">Ref: <?= h($payment['reference_number']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="payment-amount"><?= formatMoney($payment['amount']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="invoice-footer">
                <div class="terms">
                    <h4>Terms & Conditions</h4>
                    <p>Thank you for your business!</p>
                </div>
                <div class="contact">
                    <p>Questions? Contact us at <?= h(APP_NAME) ?></p>
                    <p class="website">www.<?= h(str_replace(['https://','http://'], '', APP_URL)) ?></p>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/app.js"></script>
    <script>
        window.print();
    </script>
</body>
</html>
