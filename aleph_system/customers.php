<?php
$pageTitle = 'Customers';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$dir = $_GET['dir'] ?? 'DESC';

$wheres = ["c.deleted_at IS NULL"];
$params = [];

if ($search) {
    $wheres[] = "(c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.city LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s,$s,$s]);
}
if ($type) {
    $wheres[] = "c.customer_type = ?";
    $params[] = $type;
}

$allowedSort = ['company_name','customer_type','city','created_at'];
if (!in_array($sort, $allowedSort)) $sort = 'created_at';
$dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT c.* FROM customers c WHERE $where ORDER BY c.$sort $dir", $params, 20, $page);
$customers = $result['rows'];
?>

<div class="page-header">
    <div class="page-title">
        <h1>Customers</h1>
        <p class="page-subtitle"><?= $result['total'] ?> total customer<?= $result['total'] !== 1 ? 's' : '' ?></p>
    </div>
    <div class="page-actions">
        <a href="customer_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Customer</a>
        <a href="export.php?type=customers" class="btn btn-secondary"><i data-lucide="download"></i> Export</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group">
            <input type="text" name="search" placeholder="Search customers..." value="<?= h($search) ?>" class="form-control">
        </div>
        <div class="filter-group">
            <select name="type" class="form-control">
                <option value="">All Types</option>
                <option value="prospect" <?= $type==='prospect'?'selected':'' ?>>Prospect</option>
                <option value="new" <?= $type==='new'?'selected':'' ?>>New</option>
                <option value="regular" <?= $type==='regular'?'selected':'' ?>>Regular</option>
                <option value="vip" <?= $type==='vip'?'selected':'' ?>>VIP</option>
                <option value="inactive" <?= $type==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
        <?php if ($search || $type): ?>
            <a href="customers.php" class="btn btn-secondary"><i data-lucide="times"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th><a href="?sort=company_name&dir=<?= $sort==='company_name'&&$dir==='ASC'?'DESC':'ASC' ?>&search=<?= h($search) ?>&type=<?= h($type) ?>">Company</a></th>
                <th>Contact</th>
                <th>Email</th>
                <th>Phone</th>
                <th><a href="?sort=customer_type&dir=<?= $sort==='customer_type'&&$dir==='ASC'?'DESC':'ASC' ?>&search=<?= h($search) ?>&type=<?= h($type) ?>">Type</a></th>
                <th><a href="?sort=city&dir=<?= $sort==='city'&&$dir==='ASC'?'DESC':'ASC' ?>&search=<?= h($search) ?>&type=<?= h($type) ?>">City</a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr><td colspan="7" style="padding:0">
                    <div style="padding:48px 20px;text-align:center">
                        <div class="empty-state-illustration">
                            <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="25" y="25" width="110" height="70" rx="8" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                                <circle cx="60" cy="55" r="14" fill="#eef2ff" stroke="#818cf8" stroke-width="1.5"/>
                                <circle cx="100" cy="55" r="14" fill="#ecfdf5" stroke="#34d399" stroke-width="1.5"/>
                                <path d="M56 55h8M60 51v8" stroke="#818cf8" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M96 55h8" stroke="#34d399" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="empty-state-heading">No customers found</div>
                        <div class="empty-state-desc">Add your first customer to start building quotes and tracking jobs.</div>
                        <a href="customer_add.php" class="btn btn-primary"><i data-lucide="user-plus"></i> Add Customer</a>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><a href="customer_view.php?id=<?= $c['id'] ?>" class="table-link"><?= h($c['company_name']) ?></a></td>
                    <td><?= h($c['contact_name'] ?? '—') ?></td>
                    <td><?= h($c['email'] ?? '—') ?></td>
                    <td><?= h($c['phone'] ?? '—') ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($c['customer_type']) ?>"><?= ucfirst(h($c['customer_type'])) ?></span></td>
                    <td><?= h($c['city'] ?? '—') ?></td>
                    <td>
                        <a href="customer_view.php?id=<?= $c['id'] ?>" class="btn-icon" title="View"><i data-lucide="eye"></i></a>
                        <a href="customer_edit.php?id=<?= $c['id'] ?>" class="btn-icon" title="Edit"><i data-lucide="edit"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php renderPagination($result, $_GET); ?>

<?php require_once __DIR__ . '/footer.php'; ?>
