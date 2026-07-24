<?php
$pageTitle = 'Size Presets';
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
            'width_cm' => (float)($_POST['width_cm'] ?? 0),
            'height_cm' => (float)($_POST['height_cm'] ?? 0),
            'category' => clean($_POST['category'] ?? ''),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['name'] === '') { setFlash('error', 'Name is required.'); }
        elseif ($id > 0) { dbUpdate($db, 'pq_sizes', $data, 'id = ?', [$id]); setFlash('success', 'Size updated.'); }
        else { dbInsert($db, 'pq_sizes', $data); setFlash('success', 'Size added.'); }
        logActivity('quote_builder', $id ? 'update' : 'create', 'size', $id, "Size {$data['name']}");
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $c = dbFetch($db, "SELECT active FROM pq_sizes WHERE id=?", [$id]);
        if ($c) dbUpdate($db, 'pq_sizes', ['active' => $c['active'] ? 0 : 1], 'id = ?', [$id]);
    }
    header('Location: pq_sizes.php'); exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? dbFetch($db, "SELECT * FROM pq_sizes WHERE id=?", [$editId]) : null;
$rows = dbFetchAll($db, "SELECT * FROM pq_sizes ORDER BY active DESC, category, name");
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Size Presets</h1><p class="page-subtitle">Standard trim sizes selectable in the quote builder</p></div>
</div>
<div class="detail-grid" style="grid-template-columns: 1fr 2fr; align-items:start;">
    <div class="card">
        <h3><?= $edit ? 'Edit Size' : 'Add Size' ?></h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
            <div class="form-group"><label>Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Business Card"></div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>Width (cm)</label><input type="number" step="0.01" name="width_cm" class="form-control" value="<?= h($edit['width_cm'] ?? '') ?>"></div>
                <div class="form-group"><label>Height (cm)</label><input type="number" step="0.01" name="height_cm" class="form-control" value="<?= h($edit['height_cm'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label>Category</label><input type="text" name="category" class="form-control" value="<?= h($edit['category'] ?? '') ?>" placeholder="Card / Flyer / Poster…"></div>
            <div class="form-group"><label><input type="checkbox" name="active" <?= (!$edit || $edit['active']) ? 'checked' : '' ?>> Active</label></div>
            <div style="display:flex;gap:8px;"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $edit ? 'Update' : 'Add' ?></button><?php if ($edit): ?><a href="pq_sizes.php" class="btn btn-secondary">Cancel</a><?php endif; ?></div>
        </form>
    </div>
    <div class="card">
        <h3><?= count($rows) ?> Sizes</h3>
        <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Dimensions</th><th>Category</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="5" class="empty-state">No sizes yet</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr style="<?= $r['active'] ? '' : 'opacity:.5;' ?>">
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><?= rtrim(rtrim($r['width_cm'],'0'),'.') ?> × <?= rtrim(rtrim($r['height_cm'],'0'),'.') ?> cm</td>
                    <td><?= h($r['category'] ?? '—') ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $r['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="pq_sizes.php?edit=<?= $r['id'] ?>" class="btn-icon"><i data-lucide="edit"></i></a>
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
