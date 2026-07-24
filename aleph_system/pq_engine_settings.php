<?php
$pageTitle = 'Quote Engine Settings';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/quote_engine.php';

$db = getDB();
if (currentUserRole() !== 'admin') { setFlash('error', 'Admin access required.'); header('Location: dashboard.php'); exit; }

// key => [label, type, category]
$fields = [
    // Engine pricing
    'pq_currency_symbol'    => ['Currency symbol', 'text', 'quote_engine'],
    'pq_markup_pct'         => ['Default markup on cost (%)', 'number', 'quote_engine'],
    'pq_waste_pct'          => ['Running waste (%)', 'number', 'quote_engine'],
    'pq_setup_waste_sheets' => ['Make-ready spoilage (sheets)', 'number', 'quote_engine'],
    'pq_vat_pct'            => ['Default VAT (%)', 'number', 'quote_engine'],
    'pq_bleed_mm'           => ['Bleed (mm)', 'number', 'quote_engine'],
    'pq_gutter_mm'          => ['Gutter between pieces (mm)', 'number', 'quote_engine'],
    'pq_price_rounding'     => ['Round quote total up to nearest', 'number', 'quote_engine'],
    // Quotation document
    'pq_intro'              => ['Intro line', 'text', 'quote_doc'],
    'pq_payment_terms'      => ['Default payment terms', 'text', 'quote_doc'],
    'pq_signatories'        => ['Signatories (comma separated)', 'text', 'quote_doc'],
    // Company letterhead
    'company_name'          => ['Company name', 'text', 'company'],
    'company_email'         => ['Email', 'text', 'company'],
    'company_phone'         => ['Phone / tel-Fax', 'text', 'company'],
    'company_website'       => ['Website', 'text', 'company'],
    'company_address'       => ['Address', 'text', 'company'],
    'company_rcb'           => ['R.C.B.', 'text', 'company'],
    'company_tva'           => ['TVA', 'text', 'company'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach ($fields as $key => $meta) {
        $val = clean($_POST[$key] ?? '');
        $existing = dbFetch($db, "SELECT id FROM settings WHERE setting_key=?", [$key]);
        if ($existing) dbUpdate($db, 'settings', ['setting_value' => $val], 'setting_key = ?', [$key]);
        else dbInsert($db, 'settings', ['setting_key' => $key, 'setting_value' => $val, 'setting_type' => 'string', 'category' => $meta[2]]);
    }
    logActivity('quote_builder', 'update', 'settings', null, 'Updated quote engine settings');
    setFlash('success', 'Settings saved.');
    header('Location: pq_engine_settings.php'); exit;
}

$vals = [];
foreach (dbFetchAll($db, "SELECT setting_key, setting_value FROM settings WHERE category IN ('quote_engine','quote_doc','company')") as $r) $vals[$r['setting_key']] = $r['setting_value'];
require_once __DIR__ . '/header.php';

$groups = [
    'quote_engine' => 'Pricing Engine',
    'quote_doc' => 'Quotation Document',
    'company' => 'Company Letterhead',
];
?>
<div class="page-header">
    <div class="page-title"><h1>Quote Engine Settings</h1><p class="page-subtitle">Pricing rules, quotation wording &amp; letterhead</p></div>
    <div class="page-actions"><a href="quote_add.php" class="btn btn-primary"><i data-lucide="calculator"></i> Open Builder</a></div>
</div>
<form method="POST" style="max-width:760px;">
    <?= csrfField() ?>
    <?php foreach ($groups as $cat => $label): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3><?= $label ?></h3>
        <div class="form-grid">
            <?php foreach ($fields as $key => $meta): if ($meta[2] !== $cat) continue; ?>
            <div class="form-group <?= in_array($key,['pq_intro','pq_payment_terms','company_address'])?'full-width':'' ?>">
                <label><?= h($meta[0]) ?></label>
                <input type="<?= $meta[1] ?>" <?= $meta[1]==='number'?'step="0.01"':'' ?> name="<?= $key ?>" class="form-control" value="<?= h($vals[$key] ?? '') ?>">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Settings</button>
</form>
<?php require_once __DIR__ . '/footer.php'; ?>
