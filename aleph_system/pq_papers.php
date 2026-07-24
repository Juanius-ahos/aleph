<?php
$pageTitle = 'Paper Stocks';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$db = getDB();
if (currentUserRole() !== 'admin') { setFlash('error', 'Admin access required.'); header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => clean($_POST['name'] ?? ''),
            'gsm' => (int)($_POST['gsm'] ?? 0),
            'type' => clean($_POST['type'] ?? ''),
            'sheet_w_cm' => (float)($_POST['sheet_w_cm'] ?? 0),
            'sheet_h_cm' => (float)($_POST['sheet_h_cm'] ?? 0),
            'price_mode' => ($_POST['price_mode'] ?? 'per_sheet') === 'per_ton' ? 'per_ton' : 'per_sheet',
            'price_per_ton' => (float)($_POST['price_per_ton'] ?? 0),
            'cost_per_sheet' => (float)($_POST['cost_per_sheet'] ?? 0),
            'notes' => clean($_POST['notes'] ?? ''),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        // per-ton papers: keep cost_per_sheet in sync (weight from gsm x area)
        if ($data['price_mode'] === 'per_ton' && $data['gsm'] > 0 && $data['sheet_w_cm'] > 0 && $data['sheet_h_cm'] > 0) {
            $data['cost_per_sheet'] = round($data['sheet_w_cm'] * $data['sheet_h_cm'] * $data['gsm'] * $data['price_per_ton'] / 1e10, 4);
        }
        if ($data['name'] === '') { setFlash('error', 'Name is required.'); }
        elseif ($id > 0) { dbUpdate($db, 'pq_papers', $data, 'id = ?', [$id]); logActivity('quote_builder', 'update', 'paper', $id, "Updated paper {$data['name']}"); setFlash('success', 'Paper updated.'); }
        else { $nid = dbInsert($db, 'pq_papers', $data); logActivity('quote_builder', 'create', 'paper', $nid, "Added paper {$data['name']}"); setFlash('success', 'Paper added.'); }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = dbFetch($db, "SELECT active FROM pq_papers WHERE id=?", [$id]);
        if ($cur) dbUpdate($db, 'pq_papers', ['active' => $cur['active'] ? 0 : 1], 'id = ?', [$id]);
    }
    header('Location: pq_papers.php'); exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? dbFetch($db, "SELECT * FROM pq_papers WHERE id=?", [$editId]) : null;
$rows = dbFetchAll($db, "SELECT * FROM pq_papers ORDER BY active DESC, gsm, name");
$cfg = null; require_once __DIR__ . '/lib/quote_engine.php'; $cfg = pq_load_config($db); $cur = $cfg['currency_symbol'];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Paper Stocks</h1><p class="page-subtitle">Parent sheet sizes &amp; cost per sheet used by the quote engine</p></div>
    <div class="page-actions"><a href="pq_engine_settings.php" class="btn btn-secondary"><i data-lucide="sliders"></i> Engine Settings</a></div>
</div>

<div class="detail-grid" style="grid-template-columns: 1fr 2fr; align-items:start;">
    <div class="card">
        <h3><?= $edit ? 'Edit Paper' : 'Add Paper' ?></h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
            <div class="form-group"><label>Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. 350gsm Artboard C2S"></div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>GSM</label><input type="number" name="gsm" class="form-control" value="<?= h($edit['gsm'] ?? '') ?>"></div>
                <div class="form-group"><label>Type</label><input type="text" name="type" class="form-control" value="<?= h($edit['type'] ?? '') ?>" placeholder="coated / kraft…"></div>
                <div class="form-group"><label>Sheet W (cm)</label><input type="number" step="0.01" name="sheet_w_cm" class="form-control" value="<?= h($edit['sheet_w_cm'] ?? '70') ?>"></div>
                <div class="form-group"><label>Sheet H (cm)</label><input type="number" step="0.01" name="sheet_h_cm" class="form-control" value="<?= h($edit['sheet_h_cm'] ?? '100') ?>"></div>
            </div>
            <div class="form-group"><label>Pricing basis</label>
                <select name="price_mode" id="ppMode" class="form-control" onchange="ppToggle()">
                    <option value="per_sheet" <?= ($edit['price_mode'] ?? 'per_sheet')==='per_sheet'?'selected':'' ?>>Per sheet (direct)</option>
                    <option value="per_ton" <?= ($edit['price_mode'] ?? '')==='per_ton'?'selected':'' ?>>Per ton (auto-converts to sheet cost)</option>
                </select>
            </div>
            <div class="form-group pp-ton"><label>Price per ton (<?= h($cur) ?>)</label><input type="number" step="0.01" name="price_per_ton" id="ppTon" class="form-control" value="<?= h($edit['price_per_ton'] ?? '0') ?>" oninput="ppPreview()"></div>
            <div class="form-group"><label>Cost per sheet (<?= h($cur) ?>)</label><input type="number" step="0.0001" name="cost_per_sheet" id="ppSheet" class="form-control" value="<?= h($edit['cost_per_sheet'] ?? '0') ?>">
                <small id="ppCalc" style="color:var(--gray-500);display:none;margin-top:4px;"></small>
            </div>
            <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" value="<?= h($edit['notes'] ?? '') ?>"></div>
            <div class="form-group"><label><input type="checkbox" name="active" <?= (!$edit || $edit['active']) ? 'checked' : '' ?>> Active</label></div>
            <div style="display:flex;gap:8px;"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $edit ? 'Update' : 'Add' ?></button><?php if ($edit): ?><a href="pq_papers.php" class="btn btn-secondary">Cancel</a><?php endif; ?></div>
        </form>
    </div>
    <div class="card">
        <h3><?= count($rows) ?> Paper Stocks</h3>
        <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Name</th><th>GSM</th><th>Sheet</th><th>Cost/Sheet</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="6" class="empty-state">No papers yet</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr style="<?= $r['active'] ? '' : 'opacity:.5;' ?>">
                    <td><strong><?= h($r['name']) ?></strong><?php if ($r['type']): ?><br><small><?= h($r['type']) ?></small><?php endif; ?></td>
                    <td><?= h($r['gsm']) ?></td>
                    <td><?= rtrim(rtrim($r['sheet_w_cm'],'0'),'.') ?>×<?= rtrim(rtrim($r['sheet_h_cm'],'0'),'.') ?></td>
                    <td><?= h($cur) . number_format($r['cost_per_sheet'], 4) ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="pq_papers.php?edit=<?= $r['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a>
                        <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button type="submit" class="btn-icon" title="Toggle"><i data-lucide="power"></i></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<script>
function ppToggle(){
    var ton = document.getElementById('ppMode').value === 'per_ton';
    document.querySelectorAll('.pp-ton').forEach(function(e){ e.style.display = ton ? '' : 'none'; });
    document.getElementById('ppSheet').readOnly = ton;
    document.getElementById('ppCalc').style.display = ton ? 'block' : 'none';
    if (ton) ppPreview();
}
function ppPreview(){
    var w = parseFloat(document.querySelector('[name=sheet_w_cm]').value) || 0;
    var h = parseFloat(document.querySelector('[name=sheet_h_cm]').value) || 0;
    var gsm = parseFloat(document.querySelector('[name=gsm]').value) || 0;
    var ton = parseFloat(document.getElementById('ppTon').value) || 0;
    var kg = w * h * gsm / 1e7;
    var cost = w * h * gsm * ton / 1e10;
    document.getElementById('ppSheet').value = cost.toFixed(4);
    document.getElementById('ppCalc').textContent = w && h && gsm
        ? 'Sheet weight ' + kg.toFixed(3) + ' kg  ->  ' + cost.toFixed(4) + ' per sheet'
        : 'Enter GSM and sheet size to compute';
}
['sheet_w_cm','sheet_h_cm','gsm'].forEach(function(n){
    var el = document.querySelector('[name='+n+']');
    if (el) el.addEventListener('input', function(){ if (document.getElementById('ppMode').value === 'per_ton') ppPreview(); });
});
ppToggle();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
