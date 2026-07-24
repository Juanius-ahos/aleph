<?php
$pageTitle = 'View Purchase Order';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: purchase_orders.php'); exit; }

$po = dbFetch($db, "SELECT po.*, s.company_name, s.contact_name, s.email FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id WHERE po.id=? AND po.deleted_at IS NULL", [$id]);
if (!$po) { setFlash('error', 'Not found.'); header('Location: purchase_orders.php'); exit; }

$items = dbFetchAll($db, "SELECT poi.*, m.name as material_name FROM purchase_order_items poi LEFT JOIN materials m ON poi.material_id=m.id WHERE poi.po_id=?", [$id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        if (in_array($newStatus, ['draft','sent','confirmed','partial','received','cancelled'])) {
            dbUpdate($db, 'purchase_orders', ['status' => $newStatus], 'id = ?', [$id]);
            if ($newStatus === 'received') {
                dbUpdate($db, 'purchase_orders', ['received_date' => date('Y-m-d')], 'id = ?', [$id]);
                foreach ($items as $item) {
                    if ($item['material_id']) {
                        $current = dbFetch($db, "SELECT stock_qty FROM materials WHERE id=?", [$item['material_id']]);
                        if ($current) {
                            dbUpdate($db, 'materials', ['stock_qty' => (float)$current['stock_qty'] + (float)$item['quantity']], 'id = ?', [$item['material_id']]);
                        }
                    }
                }
            }
            setFlash('success', 'PO status updated.');
        }
        header('Location: purchase_order_view.php?id='.$id); exit;
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>PO #<?= str_pad($po['po_number'], 4, '0', STR_PAD_LEFT) ?></h1></div>
    <div class="page-actions"><a href="purchase_orders.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>
<div class="detail-grid">
    <div class="detail-card">
        <h3>PO Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Supplier</span><span class="detail-value"><?= h($po['company_name'] ?? 'N/A') ?></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($po['status']) ?>"><?= ucfirst(h($po['status'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Order Date</span><span class="detail-value"><?= formatDate($po['order_date']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Expected</span><span class="detail-value"><?= $po['expected_date'] ? formatDate($po['expected_date']) : '—' ?></span></div>
            <div class="detail-row"><span class="detail-label">Total</span><span class="detail-value" style="font-weight:700;font-size:18px;"><?= formatMoney($po['total']) ?></span></div>
        </div>
    </div>
</div>
<div class="card" style="margin-top:20px;">
    <h3>Items</h3>
    <table class="data-table">
        <thead><tr><th>Description</th><th>Qty</th><th>Unit Cost</th><th>Total</th></tr></thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="4" class="empty-state">No items</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= h($item['description']) ?></td>
                    <td><?= h($item['quantity']) ?></td>
                    <td><?= formatMoney($item['unit_cost']) ?></td>
                    <td style="font-weight:600;"><?= formatMoney($item['total']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($po['status'] !== 'received' && $po['status'] !== 'cancelled'): ?>
<div class="card" style="margin-top:20px;">
    <h3>Actions</h3>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_status">
        <?php if ($po['status'] === 'draft'): ?>
            <input type="hidden" name="status" value="sent">
            <button type="submit" class="btn btn-primary"><i data-lucide="paper-plane"></i> Mark Sent</button>
        <?php elseif ($po['status'] === 'sent'): ?>
            <input type="hidden" name="status" value="confirmed">
            <button type="submit" class="btn btn-primary"><i data-lucide="check"></i> Confirm</button>
        <?php elseif ($po['status'] === 'confirmed'): ?>
            <input type="hidden" name="status" value="received">
            <button type="submit" class="btn btn-success"><i data-lucide="box-open"></i> Mark Received</button>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
