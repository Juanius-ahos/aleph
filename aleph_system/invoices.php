<?php
$pageTitle = 'Invoices';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$wheres = ["i.deleted_at IS NULL"];
$params = [];

if ($search) {
    $wheres[] = "(c.company_name LIKE ? OR CAST(i.invoice_number AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s]);
}
if ($status) { $wheres[] = "i.status = ?"; $params[] = $status; }

$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT i.*, c.company_name FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id WHERE $where ORDER BY i.created_at DESC", $params, 20, $page);
$invoices = $result['rows'];
?>

<div class="page-header">
    <div class="page-title">
        <h1>Invoices</h1>
        <p class="page-subtitle"><?= $result['total'] ?> total invoice<?= $result['total'] !== 1 ? 's' : '' ?></p>
    </div>
    <div class="page-actions">
        <a href="invoice_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Invoice</a>
        <a href="export.php?type=invoices" class="btn btn-secondary"><i data-lucide="download"></i> Export</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group">
            <input type="text" name="search" placeholder="Search invoices..." value="<?= h($search) ?>" class="form-control">
        </div>
        <div class="filter-group">
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="draft" <?= $status==='draft'?'selected':'' ?>>Draft</option>
                <option value="sent" <?= $status==='sent'?'selected':'' ?>>Sent</option>
                <option value="partial" <?= $status==='partial'?'selected':'' ?>>Partial</option>
                <option value="paid" <?= $status==='paid'?'selected':'' ?>>Paid</option>
                <option value="overdue" <?= $status==='overdue'?'selected':'' ?>>Overdue</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Number</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Balance Due</th>
                <th>Status</th>
                <th>Due Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
                <tr><td colspan="7" class="empty-state">No invoices found</td></tr>
            <?php else: ?>
                <?php foreach ($invoices as $i): ?>
                <tr>
                    <td><a href="invoice_view.php?id=<?= $i['id'] ?>" class="table-link">#<?= str_pad($i['invoice_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                    <td><?= h($i['company_name'] ?? 'N/A') ?></td>
                    <td><?= formatMoney($i['total']) ?></td>
                    <td><span class="<?= (float)$i['balance_due'] > 0 ? 'badge badge-danger' : '' ?>"><?= formatMoney($i['balance_due']) ?></span></td>
                    <td><span class="badge <?= getStatusBadgeClass($i['status']) ?>"><?= ucfirst(h($i['status'])) ?></span></td>
                    <td><?= $i['due_date'] ? formatDate($i['due_date']) : '—' ?></td>
                    <td>
                        <a href="invoice_view.php?id=<?= $i['id'] ?>" class="btn-icon" title="View"><i data-lucide="eye"></i></a>
                        <a href="invoice_pdf.php?id=<?= $i['id'] ?>" target="_blank" class="btn-icon" title="Print"><i data-lucide="print"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php renderPagination($result, $_GET); ?>

<?php require_once __DIR__ . '/footer.php'; ?>
