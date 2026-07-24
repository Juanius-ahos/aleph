<?php
$pageTitle = 'Suppliers';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');

$wheres = ['deleted_at IS NULL'];
$params = [];
if ($search) {
    $wheres[] = "(company_name LIKE ? OR contact_name LIKE ? OR email LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s]);
}
$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT * FROM suppliers WHERE $where ORDER BY company_name", $params, 20, $page);
$suppliers = $result['rows'];
?>

<div class="page-header">
    <div class="page-title"><h1>Suppliers</h1><p class="page-subtitle"><?= $result['total'] ?> total</p></div>
    <div class="page-actions">
        <a href="supplier_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Supplier</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group"><input type="text" name="search" placeholder="Search suppliers..." value="<?= h($search) ?>" class="form-control"></div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>Company</th><th>Contact</th><th>Email</th><th>Phone</th><th>City</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($suppliers)): ?>
                <tr><td colspan="6" class="empty-state">No suppliers found</td></tr>
            <?php else: ?>
                <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td><?= h($s['company_name']) ?></td>
                    <td><?= h($s['contact_name'] ?? '—') ?></td>
                    <td><?= h($s['email'] ?? '—') ?></td>
                    <td><?= h($s['phone'] ?? '—') ?></td>
                    <td><?= h($s['city'] ?? '—') ?></td>
                    <td><a href="supplier_edit.php?id=<?= $s['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php renderPagination($result, $_GET); ?>
<?php require_once __DIR__ . '/footer.php'; ?>
