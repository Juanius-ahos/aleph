<?php
$pageTitle = 'Add Follow-up';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$customers = dbFetchAll($db, "SELECT id, company_name FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
$users = dbFetchAll($db, "SELECT id, full_name FROM users WHERE active=1 ORDER BY full_name");
$preselectedCustomer = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'customer_id' => (int)($_POST['customer_id'] ?? 0),
        'followup_type' => in_array($_POST['type'] ?? '', ['call','email','meeting','task','note']) ? $_POST['type'] : 'task',
        'task_description' => clean($_POST['title'] ?? ''),
        'description' => clean($_POST['description'] ?? ''),
        'due_date' => $_POST['due_date'] ?? null,
        'status' => 'pending',
        'priority' => in_array($_POST['priority'] ?? '', ['low','medium','high']) ? $_POST['priority'] : 'medium',
        'assigned_to' => (int)($_POST['assigned_to'] ?? null) ?: null,
        'created_by' => currentUserId(),
    ];
    if (empty($data['task_description']) || $data['customer_id'] <= 0) {
        setFlash('error', 'Title and customer required.'); header('Location: followup_add.php'); exit;
    }
    $id = dbInsert($db, 'followups', $data);
    if ($id) { setFlash('success', 'Follow-up created.'); header('Location: followups.php'); exit; }
    setFlash('error', 'Failed.'); header('Location: followup_add.php'); exit;
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Add Follow-up</h1></div><div class="page-actions"><a href="followups.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Customer *</label>
            <select name="customer_id" class="form-control" required>
                <option value="">Select</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id']===$preselectedCustomer?'selected':'' ?>><?= h($c['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Title *</label><input type="text" name="title" class="form-control" required></div>
        <div class="form-group"><label>Type</label>
            <select name="type" class="form-control"><option value="task">Task</option><option value="call">Call</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="note">Note</option></select>
        </div>
        <div class="form-group"><label>Due Date</label><input type="datetime-local" name="due_date" class="form-control"></div>
        <div class="form-group"><label>Priority</label>
            <select name="priority" class="form-control"><option value="medium">Medium</option><option value="low">Low</option><option value="high">High</option></select>
        </div>
        <div class="form-group"><label>Assigned To</label>
            <select name="assigned_to" class="form-control">
                <option value="">Unassigned</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= h($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group full-width"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
