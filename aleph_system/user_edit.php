<?php
$pageTitle = 'Edit User';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();
if (!hasRole('admin')) { setFlash('error', 'Admin only.'); header('Location: dashboard.php'); exit; }

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: users.php'); exit; }

$user = dbFetch($db, "SELECT * FROM users WHERE id=?", [$id]);
if (!$user) { setFlash('error', 'Not found.'); header('Location: users.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'email' => clean($_POST['email'] ?? ''),
        'full_name' => clean($_POST['full_name'] ?? ''),
        'phone' => clean($_POST['phone'] ?? ''),
        'department' => clean($_POST['department'] ?? ''),
        'active' => isset($_POST['active']) ? 1 : 0,
    ];
    $role = $_POST['role'] ?? $user['role'];
    if (in_array($role, ['admin','user'])) $data['role'] = $role;

    dbUpdate($db, 'users', $data, 'id = ?', [$id]);
    setFlash('success', 'User updated.');
    header("Location: users.php"); exit;
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Edit User: <?= h($user['username']) ?></h1></div><div class="page-actions"><a href="users.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Username</label><input type="text" class="form-control" value="<?= h($user['username']) ?>" disabled></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-control" required value="<?= h($user['email']) ?>"></div>
        <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" value="<?= h($user['full_name']) ?>"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= h($user['phone']) ?>"></div>
        <div class="form-group"><label>Role</label>
            <select name="role" class="form-control">
                <?php foreach (['user','admin'] as $r): ?>
                    <option value="<?= $r ?>" <?= $user['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Department</label><input type="text" name="department" class="form-control" value="<?= h($user['department']) ?>"></div>
        <div class="form-group"><label>Status</label>
            <label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= $user['active']?'checked':'' ?>> Active</label>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
