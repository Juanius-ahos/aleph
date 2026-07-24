<?php
/**
 * Aleph ERP — Login
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$requires2fa = false;
$pendingUsername = '';

try {
    $db = getDB();
    $userCount = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM users")['c'] ?? 0);
    if ($userCount === 0) {
        $initPassword = bin2hex(random_bytes(12));
        $hash = password_hash($initPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        dbInsert($db, 'users', [
            'username' => 'admin', 'email' => 'admin@aleph.com.lb',
            'password_hash' => $hash, 'full_name' => 'System Administrator',
            'role' => 'admin', 'active' => 1, 'must_change_password' => 1, 'two_factor_enabled' => 0,
        ]);
        $success = 'Admin user auto-created. Temporary password: ' . htmlspecialchars($initPassword);
    }
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
}

if (isset($_GET['timeout'])) $error = 'Your session has expired. Please log in again.';
if (isset($_GET['created'])) $success = 'Admin user created. You can now log in.';
if (isset($_GET['setup'])) $success = 'Setup complete. You can now log in.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $twoFactorCode = $_POST['two_factor_code'] ?? null;

        if (empty($username) || empty($password)) {
            $error = 'Please enter username and password.';
        } else {
            $db = getDB();
            $user = dbFetch($db, "SELECT * FROM users WHERE username = ? AND active = 1", [$username]);

            if (!$user) {
                $error = 'Invalid username or password.';
                error_log("Login failed: user '$username' not found");
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Invalid username or password.';
                error_log("Login failed: bad password for '$username'");
            } else {
                if (!empty($user['must_change_password'])) $_SESSION['must_change_password'] = 1;
                if ($user['two_factor_enabled'] && !empty($user['two_factor_secret'])) {
                    require_once __DIR__ . '/auth.php';
                    if (!$twoFactorCode) {
                        $requires2fa = true;
                        $pendingUsername = $username;
                    } elseif (!verifyTwoFactorCode($user['two_factor_secret'], $twoFactorCode)) {
                        $error = 'Invalid 2FA code.';
                    } else {
                        loginSession($user);
                    }
                } else {
                    loginSession($user);
                }
            }
        }
    }
}

function loginSession($user) {
    $db = getDB();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_first_name'] = $user['full_name'] ?? '';
    $_SESSION['user_last_name'] = '';
    $_SESSION['full_name'] = $user['full_name'] ?? '';
    $_SESSION['email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    try { dbUpdate($db, 'users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]); } catch (Exception $e) {}
    try {
        dbInsert($db, 'login_history', [
            'user_id' => $user['id'], 'email' => $user['email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'success' => 1,
        ]);
    } catch (Exception $e) {}
    header('Location: dashboard.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=14">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="login-page">

    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-mark">
                <img src="assets/logo.png" alt="Aleph" onerror="this.style.display='none';this.parentElement.innerHTML='<span style=&quot;color:#fff;font-weight:700;font-size:20px&quot;>A</span>'">
            </div>
        </div>

        <h1>Aleph CRM</h1>
        <p class="subtitle">Sign in to continue</p>

        <?php if ($error): ?>
            <div class="login-error">
                <i data-lucide="alert-circle"></i>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="login-success-msg">
                <i data-lucide="check-circle"></i>
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

            <?php if ($requires2fa): ?>
                <input type="hidden" name="username" value="<?= h($pendingUsername) ?>">
                <input type="hidden" name="password" value="">
                <div class="form-group">
                    <label class="form-label" for="two_factor_code">Two-Factor Code</label>
                    <input type="text" id="two_factor_code" name="two_factor_code" class="form-control"
                           placeholder="Enter 6-digit code" autocomplete="one-time-code"
                           maxlength="6" pattern="[0-9]{6}" required autofocus>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control"
                           placeholder="Username" autocomplete="username" required autofocus
                           value="<?= h($_POST['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="Password" autocomplete="current-password" required>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">
                <?= $requires2fa ? 'Verify & Login' : 'Sign In' ?>
            </button>
        </form>

        <div class="login-footer">
            <a href="https://aleph.com.lb" target="_blank">aleph.com.lb</a>
            <p>Aleph Printing &amp; Graphics</p>
        </div>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
