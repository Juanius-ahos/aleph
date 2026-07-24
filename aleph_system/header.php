<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'login.php' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'setup.php') {
    requireLogin();
}
$db = getDB();
$currentUser = null;
if (isLoggedIn()) {
    $currentUser = dbFetch($db, "SELECT id, username, email, full_name, role, avatar FROM users WHERE id=? AND active=1", [currentUserId()]);
}
$moduleName = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');

// ── Stripped build: only Quote Builder, Customers, Users, Settings are enabled ──
$ENABLED_MODULES = [
    'dashboard',
    'customers', 'customer_view', 'customer_add', 'customer_edit', 'customer_note_add', 'customer_contact_add',
    'quotes', 'quote_add', 'quote_edit', 'quote_view', 'quote_pdf',
    'pq_products', 'pq_papers', 'pq_finishing', 'pq_sizes', 'pq_engine_settings',
    'users', 'user_add', 'user_edit', 'settings', 'change_password',
    'login', 'logout', 'pq_setup', 'pq_import_quotes', 'pq_dedup_quotes', 'export', '404',
];
if (isLoggedIn() && !in_array($moduleName, $ENABLED_MODULES, true)) {
    setFlash('info', 'That module is not part of this build.');
    header('Location: dashboard.php');
    exit;
}

// Nav helpers
$pqPages   = ['pq_products','pq_papers','pq_finishing','pq_sizes','pq_engine_settings'];
$navOn     = fn($mods) => in_array($moduleName, (array)$mods, true) ? 'on' : '';
$moreOn    = in_array($moduleName, array_merge($pqPages, ['users','settings','change_password','user_add','user_edit']), true) ? 'on' : '';
$dispName  = $currentUser['full_name'] ?? ($_SESSION['full_name'] ?? ($currentUser['username'] ?? 'User'));
$dispRole  = ucfirst($currentUser['role'] ?? currentUserRole());
$parts     = preg_split('/\s+/', trim($dispName));
$initials  = strtoupper(substr($parts[0] ?? 'U', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Dashboard') ?> — <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=14">
    <link rel="stylesheet" href="assets/appshell.css?v=2">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="app">

<?php if (isLoggedIn()): ?>
<header class="app-header">
    <div class="app-header-inner">
        <a class="app-logo" href="dashboard.php"><img src="assets/logo.png" alt="Aleph Printing &amp; Graphics" onerror="this.replaceWith(Object.assign(document.createElement('span'),{textContent:'aleph',style:'font-weight:800;font-size:20px;color:#f25424'}))"></a>
        <div class="app-right">
            <a class="app-cta" href="quote_add.php">New Quote</a>
            <div class="app-id">
                <div class="meta"><div class="nm"><?= h($dispName) ?></div><div class="rl"><?= h($dispRole) ?></div></div>
                <div class="ava"><?= h($initials) ?></div>
            </div>
        </div>
    </div>
</header>
<?php endif; ?>

<main class="app-main">
    <div class="app-container">
        <div class="content">
            <?php if (isset($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
                <div class="alert alert-<?= h($flash['type']) ?>">
                    <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($flash['message']) ?>
                    <button class="alert-close" onclick="this.parentElement.remove()"><i data-lucide="x"></i></button>
                </div>
            <?php endif; ?>
