<?php
$pageTitle = 'View Supplier';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: suppliers.php'); exit; }

$supplier = dbFetch($db, "SELECT * FROM suppliers WHERE id=?", [$id]);
if (!$supplier) { setFlash('error', 'Not found.'); header('Location: suppliers.php'); exit; }

require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1><?= h($supplier['company_name']) ?></h1><p class="page-subtitle">Supplier Details</p></div>
    <div class="page-actions">
        <a href="supplier_edit.php?id=<?= $id ?>" class="btn btn-primary"><i data-lucide="edit"></i> Edit</a>
        <a href="suppliers.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a>
    </div>
</div>
<div class="detail-grid">
    <div class="detail-card">
        <h3>Contact Information</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Contact</span><span class="detail-value"><?= h($supplier['contact_name'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value"><?= h($supplier['email'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value"><?= h($supplier['phone'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Mobile</span><span class="detail-value"><?= h($supplier['mobile'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value"><?= h($supplier['address'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">City</span><span class="detail-value"><?= h($supplier['city'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Country</span><span class="detail-value"><?= h($supplier['country'] ?? '—') ?></span></div>
        </div>
    </div>
    <div class="detail-card">
        <h3>Business Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Tax ID</span><span class="detail-value"><?= h($supplier['tax_id'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Payment Terms</span><span class="detail-value"><?= h($supplier['payment_terms'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Website</span><span class="detail-value"><?= h($supplier['website'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="badge <?= $supplier['active']?'badge-success':'badge-secondary' ?>"><?= $supplier['active']?'Active':'Inactive' ?></span></span></div>
        </div>
    </div>
</div>
<?php if (!empty($supplier['notes'])): ?>
<div class="card" style="margin-top:20px;"><h3>Notes</h3><p><?= nl2br(h($supplier['notes'])) ?></p></div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
