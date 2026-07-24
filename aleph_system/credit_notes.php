<?php
$pageTitle = 'Credit Notes';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$wheres = ["cn.deleted_at IS NULL"];
$params = [];
$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT cn.*, c.company_name FROM credit_notes cn LEFT JOIN customers c ON cn.customer_id=c.id WHERE $where ORDER BY cn.created_at DESC", $params, 20, $page);
$creditNotes = $result['rows'];
?>

<div class="page-header">
    <div class="page-title"><h1>Credit Notes</h1><p class="page-subtitle"><?= $result['total'] ?> total</p></div>
    <div class="page-actions">
        <a href="credit_note_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Credit Note</a>
    </div>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>Number</th><th>Customer</th><th>Invoice</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($creditNotes)): ?>
                <tr><td colspan="7" class="empty-state">No credit notes found</td></tr>
            <?php else: ?>
                <?php foreach ($creditNotes as $cn): ?>
                <tr>
                    <td>#<?= str_pad($cn['credit_note_number'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td><?= h($cn['company_name'] ?? 'N/A') ?></td>
                    <td><?= $cn['invoice_id'] ? '#'.str_pad($cn['invoice_id'], 4, '0', STR_PAD_LEFT) : '—' ?></td>
                    <td><?= formatMoney($cn['total']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($cn['status']) ?>"><?= ucfirst(h($cn['status'])) ?></span></td>
                    <td><?= formatDate($cn['credit_date']) ?></td>
                    <td><a href="credit_note_view.php?id=<?= $cn['id'] ?>" class="btn-icon"><i data-lucide="eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php renderPagination($result, $_GET); ?>
<?php require_once __DIR__ . '/footer.php'; ?>
