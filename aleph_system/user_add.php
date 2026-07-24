<?php
$pageTitle = 'Add User';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();
if (!hasRole('admin')) { setFlash('error', 'Admin only.'); header('Location: dashboard.php'); exit; }

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = clean($_POST['username'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullName = clean($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'admin';

    if (empty($username) || empty($email) || empty($password)) {
        setFlash('error', 'Username, email, and password required.');
        header('Location: user_add.php'); exit;
    }
    if (!in_array($role, ['admin','user'])) $role = 'admin';

    $existing = dbFetch($db, "SELECT id FROM users WHERE username=? OR email=?", [$username, $email]);
    if ($existing) { setFlash('error', 'Username or email already exists.'); header('Location: user_add.php'); exit; }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $id = dbInsert($db, 'users', [
        'username' => $username, 'email' => $email, 'password_hash' => $hash,
        'full_name' => $fullName, 'role' => $role,
        'active' => 1,
    ]);
    if ($id) { setFlash('success', 'User created.'); header('Location: users.php'); exit; }
    setFlash('error', 'Failed.'); header('Location: user_add.php'); exit;
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Add User</h1></div><div class="page-actions"><a href="users.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-control" required></div>
        <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" required minlength="8"></div>
        <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control"></div>
        <div class="form-group"><label>Role</label>
            <select name="role" class="form-control">
                <option value="admin" selected>Admin</option>
                <option value="user">User (limited)</option>
            </select>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
