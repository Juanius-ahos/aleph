<?php
$pageTitle = 'Users';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$users = dbFetchAll($db, "SELECT id, username, email, full_name, role, department, active, last_login, created_at FROM users ORDER BY created_at DESC");
?>

<div class="page-header">
    <div class="page-title"><h1>Users</h1><p class="page-subtitle"><?= count($users) ?> total</p></div>
    <div class="page-actions">
        <a href="user_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New User</a>
    </div>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= h($u['full_name'] ?? '') ?></td>
                <td><?= h($u['username']) ?></td>
                <td><?= h($u['email']) ?></td>
                <td><span class="badge <?= $u['role']==='admin'?'badge-warning':'badge-info' ?>"><?= ucfirst(h($u['role'])) ?></span></td>
                <td><?= h($u['department'] ?? '—') ?></td>
                <td><span class="badge <?= $u['active']?'badge-success':'badge-secondary' ?>"><?= $u['active']?'Active':'Inactive' ?></span></td>
                <td><?= $u['last_login'] ? formatDateTime($u['last_login']) : 'Never' ?></td>
                <td><a href="user_edit.php?id=<?= $u['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="8" style="padding:0">
                <div style="padding:48px 20px;text-align:center">
                    <div class="empty-state-illustration">
                        <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="80" cy="45" r="20" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <path d="M45 95c0-19.33 15.67-35 35-35s35 15.67 35 35" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <circle cx="120" cy="35" r="12" fill="#ecfdf5" stroke="#16a34a" stroke-width="1.5"/>
                            <path d="M117 35h6M120 32v6" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="empty-state-heading">No users yet</div>
                    <div class="empty-state-desc">Add your first team member to get started with the CRM.</div>
                    <a href="user_add.php" class="btn btn-primary"><i data-lucide="user-plus"></i> Add First User</a>
                </div>
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
