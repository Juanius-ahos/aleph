<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

if (!hasRole('admin')) {
    setFlash('error', 'Access denied. Admin only.');
    header('Location: dashboard.php');
    exit;
}

$db = getDB();
$stats = getSystemStats();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        $settings = [
            'company_name' => clean($_POST['company_name'] ?? APP_NAME),
            'company_email' => clean($_POST['company_email'] ?? ''),
            'company_phone' => clean($_POST['company_phone'] ?? ''),
            'currency_symbol' => clean($_POST['currency_symbol'] ?? '$'),
            'timezone' => clean($_POST['timezone'] ?? 'Asia/Beirut'),
            'date_format' => clean($_POST['date_format'] ?? 'M d, Y'),
            'tax_rate' => (float)($_POST['tax_rate'] ?? 0),
        ];
        foreach ($settings as $key => $value) {
            $existing = dbFetch($db, "SELECT id FROM settings WHERE setting_key=?", [$key]);
            if ($existing) {
                dbUpdate($db, 'settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
            } else {
                dbInsert($db, 'settings', ['setting_key' => $key, 'setting_value' => $value]);
            }
        }
        setFlash('success', 'Settings updated.');
        header('Location: settings.php');
        exit;
    }
}

$settingsData = dbFetchAll($db, "SELECT setting_key, setting_value FROM settings");
$settings = [];
foreach ($settingsData as $s) { $settings[$s['setting_key']] = $s['setting_value']; }

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title"><h1>System Settings</h1><p class="page-subtitle">Configure your ERP system</p></div>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card"><div class="stat-icon blue"><i data-lucide="users"></i></div><div class="stat-info"><div class="stat-label">Users</div><div class="stat-value"><?= $stats['total_users'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i data-lucide="user-check"></i></div><div class="stat-info"><div class="stat-label">Active Users</div><div class="stat-value"><?= $stats['active_users'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i data-lucide="users"></i></div><div class="stat-info"><div class="stat-label">Customers</div><div class="stat-value"><?= $stats['total_customers'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i data-lucide="project-diagram"></i></div><div class="stat-info"><div class="stat-label">Active Jobs</div><div class="stat-value"><?= $stats['active_jobs'] ?></div></div></div>
</div>

<div class="card">
    <h3>Company Settings</h3>
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_settings">
        <div class="form-group"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="<?= h($settings['company_name'] ?? APP_NAME) ?>"></div>
        <div class="form-group"><label>Company Email</label><input type="email" name="company_email" class="form-control" value="<?= h($settings['company_email'] ?? '') ?>"></div>
        <div class="form-group"><label>Company Phone</label><input type="text" name="company_phone" class="form-control" value="<?= h($settings['company_phone'] ?? '') ?>"></div>
        <div class="form-group"><label>Currency Symbol</label><input type="text" name="currency_symbol" class="form-control" value="<?= h($settings['currency_symbol'] ?? '$') ?>"></div>
        <div class="form-group"><label>Timezone</label>
            <select name="timezone" class="form-control">
                <option value="Asia/Beirut" <?= ($settings['timezone'] ?? '')==='Asia/Beirut'?'selected':'' ?>>Asia/Beirut (GMT+2)</option>
                <option value="UTC" <?= ($settings['timezone'] ?? '')==='UTC'?'selected':'' ?>>UTC</option>
                <option value="America/New_York" <?= ($settings['timezone'] ?? '')==='America/New_York'?'selected':'' ?>>America/New_York</option>
            </select>
        </div>
        <div class="form-group"><label>Date Format</label><input type="text" name="date_format" class="form-control" value="<?= h($settings['date_format'] ?? 'M d, Y') ?>"></div>
        <div class="form-group"><label>Default Tax Rate (%)</label><input type="number" name="tax_rate" class="form-control" step="0.01" value="<?= h($settings['tax_rate'] ?? '0') ?>"></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Settings</button></div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <h3>System Information</h3>
    <div class="detail-rows">
        <div class="detail-row"><span class="detail-label">Version</span><span class="detail-value"><?= APP_VERSION ?></span></div>
        <div class="detail-row"><span class="detail-label">PHP Version</span><span class="detail-value"><?= phpversion() ?></span></div>
        <div class="detail-row"><span class="detail-label">Server</span><span class="detail-value"><?= php_uname('s') . ' ' . php_uname('r') ?></span></div>
        <div class="detail-row"><span class="detail-label">Database</span><span class="detail-value">MySQL <?= $db->getAttribute(PDO::ATTR_SERVER_VERSION) ?></span></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
