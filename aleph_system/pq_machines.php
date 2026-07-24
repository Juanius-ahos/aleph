<?php
$pageTitle = 'Press Machines';
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
            'method' => ($_POST['method'] ?? 'offset') === 'digital' ? 'digital' : 'offset',
            'max_sheet_w_cm' => (float)($_POST['max_sheet_w_cm'] ?? 0),
            'max_sheet_h_cm' => (float)($_POST['max_sheet_h_cm'] ?? 0),
            'colors' => (int)($_POST['colors'] ?? 4),
            'plate_cost' => (float)($_POST['plate_cost'] ?? 0),
            'makeready_cost_per_color' => (float)($_POST['makeready_cost_per_color'] ?? 0),
            'run_cost_per_1000' => (float)($_POST['run_cost_per_1000'] ?? 0),
            'click_cost_per_sheet' => (float)($_POST['click_cost_per_sheet'] ?? 0),
            'min_charge' => (float)($_POST['min_charge'] ?? 0),
            'notes' => clean($_POST['notes'] ?? ''),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['name'] === '') { setFlash('error', 'Name is required.'); }
        elseif ($id > 0) { dbUpdate($db, 'pq_machines', $data, 'id = ?', [$id]); logActivity('quote_builder', 'update', 'machine', $id, "Updated machine {$data['name']}"); setFlash('success', 'Machine updated.'); }
        else { $nid = dbInsert($db, 'pq_machines', $data); logActivity('quote_builder', 'create', 'machine', $nid, "Added machine {$data['name']}"); setFlash('success', 'Machine added.'); }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $c = dbFetch($db, "SELECT active FROM pq_machines WHERE id=?", [$id]);
        if ($c) dbUpdate($db, 'pq_machines', ['active' => $c['active'] ? 0 : 1], 'id = ?', [$id]);
    }
    header('Location: pq_machines.php'); exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? dbFetch($db, "SELECT * FROM pq_machines WHERE id=?", [$editId]) : null;
$rows = dbFetchAll($db, "SELECT * FROM pq_machines ORDER BY active DESC, method, name");
require_once __DIR__ . '/lib/quote_engine.php'; $cfg = pq_load_config($db); $cur = $cfg['currency_symbol'];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Press Machines</h1><p class="page-subtitle">Offset &amp; digital presses with their plate, make-ready and run rates</p></div>
    <div class="page-actions"><a href="pq_engine_settings.php" class="btn btn-secondary"><i data-lucide="sliders"></i> Engine Settings</a></div>
</div>
<div class="detail-grid" style="grid-template-columns: 1fr 2fr; align-items:start;">
    <div class="card">
        <h3><?= $edit ? 'Edit Machine' : 'Add Machine' ?></h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
            <div class="form-group"><label>Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Roland 700"></div>
            <div class="form-group"><label>Method</label>
                <select name="method" class="form-control" id="methodSel" onchange="pqToggle()">
                    <option value="offset" <?= ($edit['method'] ?? '')==='offset'?'selected':'' ?>>Offset</option>
                    <option value="digital" <?= ($edit['method'] ?? '')==='digital'?'selected':'' ?>>Digital</option>
                </select>
            </div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>Max Sheet W (cm)</label><input type="number" step="0.01" name="max_sheet_w_cm" class="form-control" value="<?= h($edit['max_sheet_w_cm'] ?? '70') ?>"></div>
                <div class="form-group"><label>Max Sheet H (cm)</label><input type="number" step="0.01" name="max_sheet_h_cm" class="form-control" value="<?= h($edit['max_sheet_h_cm'] ?? '100') ?>"></div>
                <div class="form-group"><label>Max Colors</label><input type="number" name="colors" class="form-control" value="<?= h($edit['colors'] ?? '4') ?>"></div>
                <div class="form-group"><label>Min Charge (<?= h($cur) ?>)</label><input type="number" step="0.01" name="min_charge" class="form-control" value="<?= h($edit['min_charge'] ?? '0') ?>"></div>
            </div>
            <div class="pq-offset">
                <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label>Plate cost (<?= h($cur) ?>)</label><input type="number" step="0.0001" name="plate_cost" class="form-control" value="<?= h($edit['plate_cost'] ?? '0') ?>"></div>
                    <div class="form-group"><label>Make-ready / color</label><input type="number" step="0.0001" name="makeready_cost_per_color" class="form-control" value="<?= h($edit['makeready_cost_per_color'] ?? '0') ?>"></div>
                    <div class="form-group full-width"><label>Run cost / 1000 impressions</label><input type="number" step="0.0001" name="run_cost_per_1000" class="form-control" value="<?= h($edit['run_cost_per_1000'] ?? '0') ?>"></div>
                </div>
            </div>
            <div class="pq-digital">
                <div class="form-group"><label>Click cost / sheet side</label><input type="number" step="0.0001" name="click_cost_per_sheet" class="form-control" value="<?= h($edit['click_cost_per_sheet'] ?? '0') ?>"></div>
            </div>
            <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" value="<?= h($edit['notes'] ?? '') ?>"></div>
            <div class="form-group"><label><input type="checkbox" name="active" <?= (!$edit || $edit['active']) ? 'checked' : '' ?>> Active</label></div>
            <div style="display:flex;gap:8px;"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $edit ? 'Update' : 'Add' ?></button><?php if ($edit): ?><a href="pq_machines.php" class="btn btn-secondary">Cancel</a><?php endif; ?></div>
        </form>
    </div>
    <div class="card">
        <h3><?= count($rows) ?> Machines</h3>
        <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Method</th><th>Max Sheet</th><th>Rates</th><th>Min</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="7" class="empty-state">No machines yet</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr style="<?= $r['active'] ? '' : 'opacity:.5;' ?>">
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><span class="badge <?= $r['method']==='offset'?'badge-info':'badge-warning' ?>"><?= ucfirst($r['method']) ?></span></td>
                    <td><?= rtrim(rtrim($r['max_sheet_w_cm'],'0'),'.') ?>×<?= rtrim(rtrim($r['max_sheet_h_cm'],'0'),'.') ?></td>
                    <td><small><?php if ($r['method']==='offset'): ?>Plate <?= h($cur).$r['plate_cost'] ?> · MR <?= h($cur).$r['makeready_cost_per_color'] ?>/c · Run <?= h($cur).$r['run_cost_per_1000'] ?>/1k<?php else: ?>Click <?= h($cur).$r['click_cost_per_sheet'] ?>/sheet<?php endif; ?></small></td>
                    <td><?= h($cur).number_format($r['min_charge'],2) ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="pq_machines.php?edit=<?= $r['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a>
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
function pqToggle(){var m=document.getElementById('methodSel').value;
document.querySelectorAll('.pq-offset').forEach(function(e){e.style.display=m==='offset'?'':'none';});
document.querySelectorAll('.pq-digital').forEach(function(e){e.style.display=m==='digital'?'':'none';});}
pqToggle();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
