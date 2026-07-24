<?php
$pageTitle = 'Purchase Orders';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$wheres = ["po.deleted_at IS NULL"];
$params = [];
if ($search) {
    $wheres[] = "(s.company_name LIKE ? OR CAST(po.po_number AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s]);
}
if ($status) { $wheres[] = "po.status = ?"; $params[] = $status; }
$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT po.*, s.company_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id WHERE $where ORDER BY po.created_at DESC", $params, 20, $page);
$pos = $result['rows'];
?>

<div class="page-header">
    <div class="page-title"><h1>Purchase Orders</h1><p class="page-subtitle"><?= $result['total'] ?> total</p></div>
    <div class="page-actions">
        <a href="purchase_order_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New PO</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group"><input type="text" name="search" placeholder="Search POs..." value="<?= h($search) ?>" class="form-control"></div>
        <div class="filter-group">
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="draft" <?= $status==='draft'?'selected':'' ?>>Draft</option>
                <option value="sent" <?= $status==='sent'?'selected':'' ?>>Sent</option>
                <option value="confirmed" <?= $status==='confirmed'?'selected':'' ?>>Confirmed</option>
                <option value="received" <?= $status==='received'?'selected':'' ?>>Received</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>PO Number</th><th>Supplier</th><th>Status</th><th>Order Date</th><th>Expected</th><th>Total</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($pos)): ?>
                <tr><td colspan="7" class="empty-state">No purchase orders found</td></tr>
            <?php else: ?>
                <?php foreach ($pos as $po): ?>
                <tr>
                    <td><a href="purchase_order_view.php?id=<?= $po['id'] ?>" class="table-link">#<?= str_pad($po['po_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                    <td><?= h($po['company_name'] ?? 'N/A') ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($po['status']) ?>"><?= ucfirst(h($po['status'])) ?></span></td>
                    <td><?= formatDate($po['order_date']) ?></td>
                    <td><?= $po['expected_date'] ? formatDate($po['expected_date']) : '—' ?></td>
                    <td><?= formatMoney($po['total']) ?></td>
                    <td><a href="purchase_order_view.php?id=<?= $po['id'] ?>" class="btn-icon"><i data-lucide="eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php renderPagination($result, $_GET); ?>
<?php require_once __DIR__ . '/footer.php'; ?>
