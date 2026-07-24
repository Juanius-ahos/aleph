<?php
$pageTitle = 'Finishing Options';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$db = getDB();
if (currentUserRole() !== 'admin') { setFlash('error', 'Admin access required.'); header('Location: dashboard.php'); exit; }

$models = ['per_sheet' => 'Per sheet', 'per_piece' => 'Per piece', 'per_1000' => 'Per 1000 pieces', 'flat' => 'Flat fee', 'per_sqm' => 'Per m²'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => clean($_POST['name'] ?? ''),
            'pricing_model' => isset($models[$_POST['pricing_model'] ?? '']) ? $_POST['pricing_model'] : 'per_sheet',
            'unit_cost' => (float)($_POST['unit_cost'] ?? 0),
            'setup_cost' => (float)($_POST['setup_cost'] ?? 0),
            'min_charge' => (float)($_POST['min_charge'] ?? 0),
            'notes' => clean($_POST['notes'] ?? ''),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['name'] === '') { setFlash('error', 'Name is required.'); }
        elseif ($id > 0) { dbUpdate($db, 'pq_finishing', $data, 'id = ?', [$id]); logActivity('quote_builder', 'update', 'finishing', $id, "Updated finishing {$data['name']}"); setFlash('success', 'Finishing updated.'); }
        else { $nid = dbInsert($db, 'pq_finishing', $data); logActivity('quote_builder', 'create', 'finishing', $nid, "Added finishing {$data['name']}"); setFlash('success', 'Finishing added.'); }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $c = dbFetch($db, "SELECT active FROM pq_finishing WHERE id=?", [$id]);
        if ($c) dbUpdate($db, 'pq_finishing', ['active' => $c['active'] ? 0 : 1], 'id = ?', [$id]);
    }
    header('Location: pq_finishing.php'); exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? dbFetch($db, "SELECT * FROM pq_finishing WHERE id=?", [$editId]) : null;
$rows = dbFetchAll($db, "SELECT * FROM pq_finishing ORDER BY active DESC, name");
require_once __DIR__ . '/lib/quote_engine.php'; $cfg = pq_load_config($db); $cur = $cfg['currency_symbol'];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Finishing Options</h1><p class="page-subtitle">Lamination, foiling, die-cutting, binding &amp; more — with how each is priced</p></div>
    <div class="page-actions"><a href="pq_engine_settings.php" class="btn btn-secondary"><i data-lucide="sliders"></i> Engine Settings</a></div>
</div>
<div class="detail-grid" style="grid-template-columns: 1fr 2fr; align-items:start;">
    <div class="card">
        <h3><?= $edit ? 'Edit Finishing' : 'Add Finishing' ?></h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
            <div class="form-group"><label>Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Matte Lamination"></div>
            <div class="form-group"><label>Pricing model</label>
                <select name="pricing_model" class="form-control">
                    <?php foreach ($models as $k => $label): ?>
                    <option value="<?= $k ?>" <?= ($edit['pricing_model'] ?? 'per_sheet')===$k?'selected':'' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>Unit cost (<?= h($cur) ?>)</label><input type="number" step="0.0001" name="unit_cost" class="form-control" value="<?= h($edit['unit_cost'] ?? '0') ?>"></div>
                <div class="form-group"><label>Setup fee (<?= h($cur) ?>)</label><input type="number" step="0.01" name="setup_cost" class="form-control" value="<?= h($edit['setup_cost'] ?? '0') ?>"></div>
                <div class="form-group full-width"><label>Minimum charge (<?= h($cur) ?>)</label><input type="number" step="0.01" name="min_charge" class="form-control" value="<?= h($edit['min_charge'] ?? '0') ?>"></div>
            </div>
            <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" value="<?= h($edit['notes'] ?? '') ?>"></div>
            <div class="form-group"><label><input type="checkbox" name="active" <?= (!$edit || $edit['active']) ? 'checked' : '' ?>> Active</label></div>
            <div style="display:flex;gap:8px;"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $edit ? 'Update' : 'Add' ?></button><?php if ($edit): ?><a href="pq_finishing.php" class="btn btn-secondary">Cancel</a><?php endif; ?></div>
        </form>
    </div>
    <div class="card">
        <h3><?= count($rows) ?> Finishing Options</h3>
        <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Model</th><th>Unit</th><th>Setup</th><th>Min</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="7" class="empty-state">No finishing options yet</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr style="<?= $r['active'] ? '' : 'opacity:.5;' ?>">
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><small><?= h($models[$r['pricing_model']] ?? $r['pricing_model']) ?></small></td>
                    <td><?= h($cur).number_format($r['unit_cost'],4) ?></td>
                    <td><?= h($cur).number_format($r['setup_cost'],2) ?></td>
                    <td><?= h($cur).number_format($r['min_charge'],2) ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="pq_finishing.php?edit=<?= $r['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a>
                        <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button type="submit" class="btn-icon" title="Toggle"><i data-lucide="power"></i></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
