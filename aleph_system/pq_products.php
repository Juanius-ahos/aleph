<?php
$pageTitle = 'Product Presets';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$db = getDB();
if (currentUserRole() !== 'admin') { setFlash('error', 'Admin access required.'); header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $finIds = array_values(array_filter(array_map('intval', (array)($_POST['finishing_ids'] ?? []))));
        $data = [
            'name' => clean($_POST['name'] ?? ''),
            'category' => clean($_POST['category'] ?? ''),
            'description' => clean($_POST['description'] ?? ''),
            'default_size_id' => (int)($_POST['default_size_id'] ?? 0) ?: null,
            'default_paper_id' => (int)($_POST['default_paper_id'] ?? 0) ?: null,
            'default_method' => ($_POST['default_method'] ?? 'offset') === 'digital' ? 'digital' : 'offset',
            'default_sides' => (int)($_POST['default_sides'] ?? 1) >= 2 ? 2 : 1,
            'default_colors_front' => (int)($_POST['default_colors_front'] ?? 4),
            'default_colors_back' => (int)($_POST['default_colors_back'] ?? 0),
            'default_qty' => (int)($_POST['default_qty'] ?? 1000),
            'finishing_ids' => json_encode($finIds),
            'bleed_mm' => (float)($_POST['bleed_mm'] ?? 3),
            'gutter_mm' => (float)($_POST['gutter_mm'] ?? 5),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['name'] === '') { setFlash('error', 'Name is required.'); }
        elseif ($id > 0) { dbUpdate($db, 'pq_products', $data, 'id = ?', [$id]); setFlash('success', 'Preset updated.'); }
        else { dbInsert($db, 'pq_products', $data); setFlash('success', 'Preset added.'); }
        logActivity('quote_builder', $id ? 'update' : 'create', 'product', $id, "Preset {$data['name']}");
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $c = dbFetch($db, "SELECT active FROM pq_products WHERE id=?", [$id]);
        if ($c) dbUpdate($db, 'pq_products', ['active' => $c['active'] ? 0 : 1], 'id = ?', [$id]);
    }
    header('Location: pq_products.php'); exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? dbFetch($db, "SELECT * FROM pq_products WHERE id=?", [$editId]) : null;
$editFin = $edit ? (json_decode($edit['finishing_ids'] ?? '[]', true) ?: []) : [];
$rows = dbFetchAll($db, "SELECT * FROM pq_products ORDER BY active DESC, category, name");
$sizes = dbFetchAll($db, "SELECT id,name FROM pq_sizes WHERE active=1 ORDER BY name");
$papers = dbFetchAll($db, "SELECT id,name FROM pq_papers WHERE active=1 ORDER BY gsm,name");
$finishing = dbFetchAll($db, "SELECT id,name FROM pq_finishing WHERE active=1 ORDER BY name");
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Product Presets</h1><p class="page-subtitle">One-click starting points for common jobs in the quote builder</p></div>
    <div class="page-actions"><a href="quote_add.php" class="btn btn-primary"><i data-lucide="calculator"></i> Open Builder</a></div>
</div>
<div class="detail-grid" style="grid-template-columns: 1fr 1.4fr; align-items:start;">
    <div class="card">
        <h3><?= $edit ? 'Edit Preset' : 'Add Preset' ?></h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
            <div class="form-group"><label>Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Business Cards"></div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>Category</label><input type="text" name="category" class="form-control" value="<?= h($edit['category'] ?? '') ?>"></div>
                <div class="form-group"><label>Default Qty</label><input type="number" name="default_qty" class="form-control" value="<?= h($edit['default_qty'] ?? '1000') ?>"></div>
            </div>
            <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"><?= h($edit['description'] ?? '') ?></textarea></div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>Default Size</label>
                    <select name="default_size_id" class="form-control"><option value="">—</option>
                        <?php foreach ($sizes as $s): ?><option value="<?= $s['id'] ?>" <?= ($edit['default_size_id'] ?? 0)==$s['id']?'selected':'' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Default Paper</label>
                    <select name="default_paper_id" class="form-control"><option value="">—</option>
                        <?php foreach ($papers as $p): ?><option value="<?= $p['id'] ?>" <?= ($edit['default_paper_id'] ?? 0)==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Method</label>
                    <select name="default_method" class="form-control">
                        <option value="offset" <?= ($edit['default_method'] ?? '')==='offset'?'selected':'' ?>>Offset</option>
                        <option value="digital" <?= ($edit['default_method'] ?? '')==='digital'?'selected':'' ?>>Digital</option>
                    </select>
                </div>
                <div class="form-group"><label>Sides</label>
                    <select name="default_sides" class="form-control"><option value="1" <?= ($edit['default_sides'] ?? 1)==1?'selected':'' ?>>1 (single)</option><option value="2" <?= ($edit['default_sides'] ?? 1)==2?'selected':'' ?>>2 (double)</option></select>
                </div>
                <div class="form-group"><label>Colors F / B</label>
                    <div style="display:flex;gap:6px;"><input type="number" name="default_colors_front" class="form-control" value="<?= h($edit['default_colors_front'] ?? '4') ?>"><input type="number" name="default_colors_back" class="form-control" value="<?= h($edit['default_colors_back'] ?? '0') ?>"></div>
                </div>
                <div class="form-group"><label>Bleed (mm)</label><input type="number" step="0.5" name="bleed_mm" class="form-control" value="<?= h($edit['bleed_mm'] ?? '3') ?>"></div>
                <div class="form-group"><label>Gutter (mm)</label><input type="number" step="0.5" name="gutter_mm" class="form-control" value="<?= h($edit['gutter_mm'] ?? '5') ?>"></div>
            </div>
            <div class="form-group"><label>Default Finishing</label>
                <div style="display:flex;flex-wrap:wrap;gap:10px;padding:8px;border:1px solid var(--gray-200,#e2e8f0);border-radius:8px;">
                    <?php if (empty($finishing)): ?><small>No finishing options — add some first.</small><?php endif; ?>
                    <?php foreach ($finishing as $f): ?>
                        <label style="display:flex;gap:5px;align-items:center;font-weight:400;"><input type="checkbox" name="finishing_ids[]" value="<?= $f['id'] ?>" <?= in_array($f['id'], $editFin)?'checked':'' ?>> <?= h($f['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group"><label><input type="checkbox" name="active" <?= (!$edit || $edit['active']) ? 'checked' : '' ?>> Active</label></div>
            <div style="display:flex;gap:8px;"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $edit ? 'Update' : 'Add' ?></button><?php if ($edit): ?><a href="pq_products.php" class="btn btn-secondary">Cancel</a><?php endif; ?></div>
        </form>
    </div>
    <div class="card">
        <h3><?= count($rows) ?> Presets</h3>
        <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Category</th><th>Method</th><th>Default Qty</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="6" class="empty-state">No presets yet</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr style="<?= $r['active'] ? '' : 'opacity:.5;' ?>">
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><?= h($r['category'] ?? '—') ?></td>
                    <td><span class="badge <?= $r['default_method']==='offset'?'badge-info':'badge-warning' ?>"><?= ucfirst($r['default_method']) ?></span></td>
                    <td><?= (int)$r['default_qty'] ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="pq_products.php?edit=<?= $r['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a>
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
