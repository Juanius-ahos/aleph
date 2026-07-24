<?php
$pageTitle = 'Change Password';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        setFlash('error', 'New passwords do not match.');
        header('Location: change_password.php'); exit;
    }

    $result = changePassword(currentUserId(), $current, $new);
    if ($result['success']) {
        setFlash('success', $result['message']);
        header('Location: settings.php'); exit;
    } else {
        setFlash('error', $result['message']);
        header('Location: change_password.php'); exit;
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Change Password</h1></div><div class="page-actions"><a href="settings.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card" style="max-width:500px;">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group full-width"><label>Current Password *</label><input type="password" name="current_password" class="form-control" required></div>
        <div class="form-group full-width"><label>New Password *</label><input type="password" name="new_password" class="form-control" required minlength="8"></div>
        <div class="form-group full-width"><label>Confirm New Password *</label><input type="password" name="confirm_password" class="form-control" required minlength="8"></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="key"></i> Change Password</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
