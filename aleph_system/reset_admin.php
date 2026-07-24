<?php
/**
 * Emergency admin password reset — DELETE THIS FILE AFTER USE
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

requireLogin();
if (!hasRole('admin')) {
    http_response_code(403);
    die(renderErrorPage('Access denied. Admin only.', 403));
}

$db = getDB();

$newPassword = bin2hex(random_bytes(12));

$admin = dbFetch($db, "SELECT id, username FROM users WHERE username='admin'");

if (!$admin) {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $id = dbInsert($db, 'users', [
        'username' => 'admin',
        'email' => 'admin@aleph.com.lb',
        'password_hash' => $hash,
        'full_name' => 'System Administrator',
        'role' => 'admin',
        'active' => 1,
        'must_change_password' => 1,
        'two_factor_enabled' => 0,
    ]);
    echo "<h2>Admin user CREATED (ID: $id)</h2>";
    echo "<p>Username: <b>admin</b></p>";
    echo "<p>Temporary Password: <b>" . htmlspecialchars($newPassword) . "</b></p>";
} else {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    dbQuery($db, "UPDATE users SET password_hash=?, active=1 WHERE username='admin'", [$hash]);
    echo "<h2>Admin password RESET</h2>";
    echo "<p>Username: <b>admin</b></p>";
    echo "<p>Temporary Password: <b>" . htmlspecialchars($newPassword) . "</b></p>";
}

echo "<p style='color:red;font-weight:bold;'>DELETE THIS FILE (reset_admin.php) FROM YOUR SERVER NOW!</p>";
echo "<p><a href='login.php'>Go to Login</a></p>";
