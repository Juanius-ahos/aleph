<?php
$pageTitle = 'View Job';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid job ID.'); header('Location: jobs.php'); exit; }

$job = dbFetch($db, "SELECT j.*, c.company_name, c.contact_name FROM jobs j LEFT JOIN customers c ON j.customer_id=c.id WHERE j.id=? AND j.deleted_at IS NULL", [$id]);
if (!$job) { setFlash('error', 'Job not found.'); header('Location: jobs.php'); exit; }

$materials = dbFetchAll($db, "SELECT jm.*, m.name as material_name FROM job_materials jm LEFT JOIN materials m ON jm.material_id=m.id WHERE jm.job_id=?", [$id]);
$stages = ['design','prepress','printing','finishing','qc','packaging','delivered','completed'];
$progress = dbFetchAll($db, "SELECT * FROM job_stage_progress WHERE job_id=? AND deleted_at IS NULL", [$id]);
$progressMap = [];
foreach ($progress as $p) { $progressMap[$p['stage']] = $p; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $updates = ['status' => $newStatus];
        if ($newStatus === 'completed') $updates['completed_at'] = date('Y-m-d H:i:s');
        dbUpdate($db, 'jobs', $updates, 'id = ?', [$id]);
        logActivity('jobs', 'status_change', 'job', $id, "Job status changed to $newStatus");
        setFlash('success', 'Job status updated.');
        header('Location: job_view.php?id=' . $id);
        exit;
    }

    if ($action === 'update_stage') {
        $newStage = $_POST['stage'] ?? '';
        if (in_array($newStage, $stages)) {
            dbUpdate($db, 'jobs', ['stage' => $newStage], 'id = ?', [$id]);
            logActivity('jobs', 'stage_change', 'job', $id, "Job stage changed to $newStage");
            setFlash('success', 'Job stage updated.');
        }
        header('Location: job_view.php?id=' . $id);
        exit;
    }
}

$stageIndex = array_search($job['stage'], $stages);
$completionPct = $stageIndex !== false ? round(($stageIndex / (count($stages) - 1)) * 100) : 0;

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1>Job #<?= str_pad($job['job_number'], 4, '0', STR_PAD_LEFT) ?></h1>
        <p class="page-subtitle"><?= h($job['title']) ?></p>
    </div>
    <div class="page-actions">
        <a href="jobs.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a>
    </div>
</div>

<div class="progress-section" style="margin-bottom:20px;">
    <h3><i data-lucide="chart-line"></i> Production Progress</h3>
    <div class="progress-bar-container">
        <div class="progress-bar">
            <div class="progress-bar-fill" style="width: <?= $completionPct ?>%"></div>
        </div>
        <span class="completion-percentage"><?= $completionPct ?>%</span>
    </div>
    <div class="stage-flow" style="display:flex;gap:4px;margin-top:12px;flex-wrap:wrap;">
        <?php foreach ($stages as $i => $s): ?>
            <div style="padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;background:<?= $i <= $stageIndex ? '#f25424' : '#e5e7eb' ?>;color:<?= $i <= $stageIndex ? 'white' : '#6b7280' ?>;">
                <?= getStageLabel($s) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3>Job Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Customer</span><span class="detail-value"><a href="customer_view.php?id=<?= $job['customer_id'] ?>"><?= h($job['company_name'] ?? 'N/A') ?></a></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($job['status']) ?>"><?= ucfirst(h($job['status'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Stage</span><span class="detail-value"><span class="badge badge-info"><?= getStageLabel($job['stage']) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($job['priority']) ?>"><?= ucfirst(h($job['priority'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Quantity</span><span class="detail-value"><?= h($job['quantity']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Estimated Delivery</span><span class="detail-value"><?= $job['estimated_delivery'] ? formatDate($job['estimated_delivery']) : '—' ?></span></div>
            <div class="detail-row"><span class="detail-label">Amount</span><span class="detail-value"><?= formatMoney($job['amount']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Cost</span><span class="detail-value"><?= formatMoney($job['cost']) ?></span></div>
        </div>
    </div>
    <div class="detail-card">
        <h3>Stage Progress</h3>
        <div class="detail-rows">
            <?php foreach ($stages as $s): ?>
            <div class="detail-row">
                <span class="detail-label"><?= getStageLabel($s) ?></span>
                <span class="detail-value">
                    <?php if (isset($progressMap[$s])): ?>
                        <span class="badge <?= $progressMap[$s]['status']==='completed' ? 'badge-success' : ($progressMap[$s]['status']==='in_progress' ? 'badge-info' : 'badge-secondary') ?>"><?= ucfirst(str_replace('_',' ',$progressMap[$s]['status'])) ?></span>
                        <span style="font-size:12px;color:#6b7280;margin-left:4px;"><?= $progressMap[$s]['completion_percentage'] ?>%</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">Pending</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (!empty($materials)): ?>
<div class="card" style="margin-top:20px;">
    <h3>Materials Used</h3>
    <table class="data-table">
        <thead><tr><th>Material</th><th>Quantity</th><th>Unit Cost</th><th>Total</th></tr></thead>
        <tbody>
            <?php foreach ($materials as $m): ?>
            <tr>
                <td><?= h($m['material_name'] ?? 'N/A') ?></td>
                <td><?= h($m['quantity_used']) ?></td>
                <td><?= formatMoney($m['unit_cost']) ?></td>
                <td><?= formatMoney($m['total_cost']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($job['notes'])): ?>
<div class="card" style="margin-top:20px;">
    <h3>Notes</h3>
    <p><?= nl2br(h($job['notes'])) ?></p>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px;">
    <h3>Actions</h3>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_status">
        <?php if ($job['status'] === 'pending'): ?>
            <input type="hidden" name="status" value="active">
            <button type="submit" class="btn btn-primary"><i data-lucide="play"></i> Start Job</button>
        <?php elseif ($job['status'] === 'active'): ?>
            <input type="hidden" name="status" value="completed">
            <button type="submit" class="btn btn-success"><i data-lucide="check"></i> Complete Job</button>
        <?php endif; ?>
    </form>
    <?php if ($job['status'] === 'active'): ?>
    <form method="POST" style="margin-top:12px;display:flex;gap:8px;align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_stage">
        <select name="stage" class="form-control" style="width:auto;">
            <?php foreach ($stages as $s): ?>
                <option value="<?= $s ?>" <?= $job['stage']===$s?'selected':'' ?>><?= getStageLabel($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary"><i data-lucide="arrow-right"></i> Update Stage</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
