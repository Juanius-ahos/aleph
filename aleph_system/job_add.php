<?php
$pageTitle = 'Create Job';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$customers = dbFetchAll($db, "SELECT id, company_name FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
$quotes = dbFetchAll($db, "SELECT id, quote_number, title, customer_id, total FROM quotes WHERE status IN ('draft','sent') AND deleted_at IS NULL ORDER BY quote_number DESC");
$preselectedCustomer = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$preselectedQuote = (int)($_GET['quote_id'] ?? $_POST['quote_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $title = clean($_POST['title'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $dueDate = $_POST['due_date'] ?? null;
    $quoteId = (int)($_POST['quote_id'] ?? null) ?: null;
    $notes = clean($_POST['notes'] ?? '');
    $sellingPrice = (float)($_POST['selling_price'] ?? 0);

    if (empty($title) || $customerId <= 0) {
        setFlash('error', 'Customer and title are required.');
        header('Location: job_add.php');
        exit;
    }

    $jobNumber = generateJobNumber($db);
    $jobId = dbInsert($db, 'jobs', [
        'job_number' => $jobNumber, 'quote_id' => $quoteId,
        'customer_id' => $customerId, 'title' => $title, 'description' => $description,
        'status' => 'pending', 'stage' => 'design', 'priority' => $priority,
        'quantity' => $quantity, 'due_date' => $dueDate ?: null,
        'selling_price' => $sellingPrice, 'created_by' => currentUserId(),
    ]);

    if ($jobId) {
        $stages = ['design','prepress','printing','finishing','qc','packaging','delivered','completed'];
        foreach ($stages as $s) {
            dbInsert($db, 'job_stage_progress', ['job_id'=>$jobId, 'stage'=>$s, 'status'=>'pending', 'completion_percentage'=>0]);
        }
        logActivity('jobs', 'create', 'job', $jobId, "Created job #$jobNumber");
        setFlash('success', "Job #$jobNumber created.");
        header('Location: job_view.php?id=' . $jobId);
    } else {
        setFlash('error', 'Failed to create job.');
        header('Location: job_add.php');
    }
    exit;
}
?>

<div class="page-header">
    <div class="page-title"><h1>Create Job</h1></div>
    <div class="page-actions"><a href="jobs.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>

<form method="POST">
    <?= csrfField() ?>
    <div class="card">
        <div class="form-grid">
            <div class="form-group"><label>Customer <span class="required">*</span></label>
                <select name="customer_id" class="form-control" required>
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id']===$preselectedCustomer?'selected':'' ?>><?= h($c['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>From Quote</label>
                <select name="quote_id" class="form-control">
                    <option value="">None</option>
                    <?php foreach ($quotes as $q): ?>
                        <option value="<?= $q['id'] ?>" <?= $q['id']===$preselectedQuote?'selected':'' ?>>Q-<?= str_pad($q['quote_number'],4,'0',STR_PAD_LEFT) ?> - <?= h($q['title']) ?> (<?= formatMoney($q['total']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width"><label>Title <span class="required">*</span></label><input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>"></div>
            <div class="form-group full-width"><label>Description</label><textarea name="description" class="form-control" rows="2"><?= h($_POST['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Priority</label>
                <select name="priority" class="form-control"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
            </div>
            <div class="form-group"><label>Quantity</label><input type="number" name="quantity" class="form-control" value="1" min="1"></div>
            <div class="form-group"><label>Estimated Delivery</label><input type="date" name="due_date" class="form-control" value="<?= h($_POST['due_date'] ?? '') ?>"></div>
            <div class="form-group"><label>Amount</label><input type="number" name="selling_price" class="form-control" step="0.01" value="<?= h($_POST['selling_price'] ?? '0') ?>"></div>
            <div class="form-group full-width"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
    </div>
    <div style="margin-top:20px;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create Job</button>
        <a href="jobs.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<?php require_once __DIR__ . '/footer.php'; ?>
