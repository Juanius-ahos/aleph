<?php
$pageTitle = 'Follow-ups';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$status = $_GET['status'] ?? 'pending';

$wheres = [];
$params = [];
if ($status) { $wheres[] = "f.status = ?"; $params[] = $status; }
$where = implode(' AND ', $wheres);
$result = paginate($db, "SELECT f.*, c.company_name, u.full_name as assigned_name FROM followups f LEFT JOIN customers c ON f.customer_id=c.id LEFT JOIN users u ON f.assigned_to=u.id WHERE $where ORDER BY f.due_date ASC", $params, 20, $page);
$followups = $result['rows'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $fid = (int)($_POST['id'] ?? 0);
    if ($action === 'complete' && $fid) {
        dbUpdate($db, 'followups', ['status' => 'done', 'completed_at' => date('Y-m-d H:i:s')], 'id = ?', [$fid]);
        setFlash('success', 'Follow-up marked as done.');
        header('Location: followups.php?status=' . urlencode($status));
        exit;
    }
}
?>

<div class="page-header">
    <div class="page-title"><h1>Follow-ups</h1><p class="page-subtitle"><?= $result['total'] ?> total</p></div>
    <div class="page-actions">
        <a href="followup_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Follow-up</a>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group">
            <select name="status" class="form-control">
                <option value="" <?= $status===''?'selected':'' ?>>All</option>
                <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
                <option value="done" <?= $status==='done'?'selected':'' ?>>Done</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Filter</button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>Title</th><th>Customer</th><th>Type</th><th>Due Date</th><th>Priority</th><th>Assigned</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($followups)): ?>
                <tr><td colspan="7" class="empty-state">No follow-ups found</td></tr>
            <?php else: ?>
                <?php foreach ($followups as $f): ?>
                <tr>
                    <td><?= h($f['task_description']) ?></td>
                    <td><?= h($f['company_name'] ?? 'N/A') ?></td>
                    <td><?= ucfirst(h($f['followup_type'])) ?></td>
                    <td><?= $f['due_date'] ? formatDate($f['due_date']) : '—' ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($f['priority']) ?>"><?= ucfirst(h($f['priority'])) ?></span></td>
                    <td><?= h($f['assigned_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($f['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn-icon" title="Complete"><i data-lucide="check"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php renderPagination($result, $_GET); ?>
<?php require_once __DIR__ . '/footer.php'; ?>
