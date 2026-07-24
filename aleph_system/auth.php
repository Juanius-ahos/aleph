<?php
/**
 * Aleph ERP v7 — Authentication
 * Handles login, logout, 2FA, password management
 */

// All core functions (isLoggedIn, requireLogin, currentUserId, etc.) are in config.php

function checkSessionTimeout() {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return true;
    }
    $_SESSION['last_activity'] = time();
    return false;
}

function login($username, $password, $twoFactorCode = null) {
    $db = getDB();

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = 'login_attempts_' . md5($ip);
    if (isset($_SESSION[$rateKey])) {
        $attempts = $_SESSION[$rateKey];
        if ($attempts['count'] >= MAX_LOGIN_ATTEMPTS_PER_IP && (time() - $attempts['first']) < LOGIN_RATE_WINDOW) {
            return ['success' => false, 'message' => 'Too many login attempts. Please try again later.'];
        }
        if ((time() - $attempts['first']) >= LOGIN_RATE_WINDOW) {
            unset($_SESSION[$rateKey]);
        }
    }

    $user = dbFetch($db, "SELECT * FROM users WHERE username = ? AND active = 1", [$username]);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        if (!isset($_SESSION[$rateKey])) {
            $_SESSION[$rateKey] = ['count' => 0, 'first' => time()];
        }
        $_SESSION[$rateKey]['count']++;

        try {
            dbInsert($db, 'login_history', [
                'email' => $username, 'ip_address' => $ip,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'success' => 0, 'failure_reason' => 'invalid_credentials',
                'user_id' => $user['id'] ?? null,
            ]);
        } catch (Exception $e) { /* ignore */ }

        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    if ($user['two_factor_enabled'] && !empty($user['two_factor_secret'])) {
        if (!$twoFactorCode) {
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            return ['success' => false, 'requires_2fa' => true, 'message' => 'Enter your 2FA code.'];
        }
        if (!verifyTwoFactorCode($user['two_factor_secret'], $twoFactorCode)) {
            return ['success' => false, 'message' => 'Invalid 2FA code.'];
        }
    }

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

    unset($_SESSION[$rateKey]);

    try {
        dbInsert($db, 'login_history', [
            'user_id' => $user['id'], 'email' => $user['email'],
            'ip_address' => $ip,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'success' => 1,
        ]);
    } catch (Exception $e) { /* ignore */ }

    return ['success' => true];
}

function performLogout() {
    if (isLoggedIn()) {
        try {
            $db = getDB();
            logActivity('auth', 'logout', 'user', currentUserId());
        } catch (Exception $e) { /* ignore */ }
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

function validatePassword($password) {
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.";
    if (!preg_match('/[A-Z]/', $password)) $errors[] = "Must contain an uppercase letter.";
    if (!preg_match('/[a-z]/', $password)) $errors[] = "Must contain a lowercase letter.";
    if (!preg_match('/[0-9]/', $password)) $errors[] = "Must contain a number.";
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = "Must contain a special character.";
    return $errors;
}

function checkPasswordHistory($db, $userId, $newPassword) {
    try {
        $recent = dbFetchAll($db, "SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT " . PASSWORD_HISTORY_COUNT, [$userId]);
        foreach ($recent as $row) {
            if (password_verify($newPassword, $row['password_hash'])) return false;
        }
    } catch (Exception $e) { /* ignore — table may not exist yet */ }
    return true;
}

function changePassword($userId, $currentPassword, $newPassword) {
    $db = getDB();
    $user = dbFetch($db, "SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user) return ['success' => false, 'message' => 'User not found.'];
    if (!password_verify($currentPassword, $user['password_hash'])) return ['success' => false, 'message' => 'Current password is incorrect.'];

    $errors = validatePassword($newPassword);
    if (!empty($errors)) return ['success' => false, 'message' => implode(' ', $errors)];
    if (!checkPasswordHistory($db, $userId, $newPassword)) return ['success' => false, 'message' => 'Cannot reuse recent passwords.'];

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    dbQuery($db, "UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?", [$hash, $userId]);
    dbInsert($db, 'password_history', ['user_id' => $userId, 'password_hash' => $hash]);
    logActivity('auth', 'password_change', 'user', $userId);

    return ['success' => true, 'message' => 'Password changed successfully.'];
}

function generateTwoFactorSecret() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) $secret .= $chars[random_int(0, 31)];
    return $secret;
}

function getTwoFactorQRCode($secret, $email) {
    $issuer = urlencode(APP_NAME);
    $label = urlencode($email);
    return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
}

function verifyTwoFactorCode($secret, $code) {
    $time = floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        $calculated = sprintf('%06d', (int)(totp($secret, $time + $i) % 1000000));
        if (hash_equals($calculated, str_pad($code, 6, '0', STR_PAD_LEFT))) return true;
    }
    return false;
}

function totp($secret, $time) {
    $timeHex = str_pad(dechex(intdiv($time, 16)), 16, '0', STR_PAD_LEFT);
    $hash = hash_hmac('sha1', hex2bin($timeHex), base32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    return ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
}

function base32Decode($input) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(rtrim($input, '='));
    $bytes = 0; $bits = 0; $output = '';
    for ($i = 0, $len = strlen($input); $i < $len; $i++) {
        $val = strpos($map, $input[$i]);
        if ($val === false) continue;
        $bytes = ($bytes << 5) | $val;
        $bits += 5;
        if ($bits >= 8) { $bits -= 8; $output .= chr(($bytes >> $bits) & 0xff); }
    }
    return $output;
}

function enableTwoFactor($userId, $secret) {
    $db = getDB();
    dbUpdate($db, 'users', ['two_factor_secret' => $secret, 'two_factor_enabled' => 1], 'id = ?', [$userId]);
    logActivity('auth', 'enable_2fa', 'user', $userId);
}

function disableTwoFactor($userId) {
    $db = getDB();
    dbUpdate($db, 'users', ['two_factor_secret' => null, 'two_factor_enabled' => 0], 'id = ?', [$userId]);
    logActivity('auth', 'disable_2fa', 'user', $userId);
}

function requireAdmin() {
    requireLogin();
    if (!hasRole('admin')) {
        http_response_code(403);
        die(renderErrorPage('Access denied. Admin privileges required.', 403));
    }
}

function requireManager() {
    requireLogin();
    if (!hasAnyRole(['admin', 'manager'])) {
        http_response_code(403);
        die(renderErrorPage('Access denied. Manager privileges required.', 403));
    }
}
