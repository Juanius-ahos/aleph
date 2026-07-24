<?php
$pageTitle = 'Jobs';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$stage = $_GET['stage'] ?? '';

$wheres = ["j.deleted_at IS NULL"];
$params = [];

if ($search) {
    $wheres[] = "(j.title LIKE ? OR c.company_name LIKE ? OR CAST(j.job_number AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s]);
}
if ($status) { $wheres[] = "j.status = ?"; $params[] = $status; }
if ($stage) { $wheres[] = "j.stage = ?"; $params[] = $stage; }

$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT j.*, c.company_name FROM jobs j LEFT JOIN customers c ON j.customer_id=c.id WHERE $where ORDER BY j.created_at DESC", $params, 20, $page);
$jobs = $result['rows'];
?>

<div class="page-header">
    <div class="page-title">
        <h1>Jobs</h1>
        <p class="page-subtitle"><?= $result['total'] ?> total job<?= $result['total'] !== 1 ? 's' : '' ?></p>
    </div>
    <div class="page-actions">
        <a href="job_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Job</a>
        <a href="export.php?type=jobs" class="btn btn-secondary"><i data-lucide="download"></i> Export</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group">
            <input type="text" name="search" placeholder="Search jobs..." value="<?= h($search) ?>" class="form-control">
        </div>
        <div class="filter-group">
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
                <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
                <option value="completed" <?= $status==='completed'?'selected':'' ?>>Completed</option>
                <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
        </div>
        <div class="filter-group">
            <select name="stage" class="form-control">
                <option value="">All Stages</option>
                <option value="design" <?= $stage==='design'?'selected':'' ?>>Design</option>
                <option value="prepress" <?= $stage==='prepress'?'selected':'' ?>>Prepress</option>
                <option value="printing" <?= $stage==='printing'?'selected':'' ?>>Printing</option>
                <option value="finishing" <?= $stage==='finishing'?'selected':'' ?>>Finishing</option>
                <option value="qc" <?= $stage==='qc'?'selected':'' ?>>QC</option>
                <option value="packaging" <?= $stage==='packaging'?'selected':'' ?>>Packaging</option>
                <option value="delivered" <?= $stage==='delivered'?'selected':'' ?>>Delivered</option>
                <option value="completed" <?= $stage==='completed'?'selected':'' ?>>Completed</option>
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
                <th>Title</th>
                <th>Customer</th>
                <th>Stage</th>
                <th>Status</th>
                <th>Estimated Delivery</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="7" class="empty-state">No jobs found</td></tr>
            <?php else: ?>
                <?php foreach ($jobs as $j): ?>
                <tr>
                    <td><a href="job_view.php?id=<?= $j['id'] ?>" class="table-link">#<?= str_pad($j['job_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                    <td><?= h($j['title']) ?></td>
                    <td><?= h($j['company_name'] ?? 'N/A') ?></td>
                    <td><span class="badge badge-info"><?= getStageLabel($j['stage']) ?></span></td>
                    <td><span class="badge <?= getStatusBadgeClass($j['status']) ?>"><?= ucfirst(h($j['status'])) ?></span></td>
                    <td><?= $j['estimated_delivery'] ? formatDate($j['estimated_delivery']) : '—' ?></td>
                    <td>
                        <a href="job_view.php?id=<?= $j['id'] ?>" class="btn-icon" title="View"><i data-lucide="eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php renderPagination($result, $_GET); ?>

<?php require_once __DIR__ . '/footer.php'; ?>
