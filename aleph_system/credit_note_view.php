<?php
$pageTitle = 'View Credit Note';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: credit_notes.php'); exit; }

$cn = dbFetch($db, "SELECT cn.*, c.company_name, c.contact_name FROM credit_notes cn LEFT JOIN customers c ON cn.customer_id=c.id WHERE cn.id=? AND cn.deleted_at IS NULL", [$id]);
if (!$cn) { setFlash('error', 'Not found.'); header('Location: credit_notes.php'); exit; }

require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Credit Note #<?= str_pad($cn['credit_note_number'], 4, '0', STR_PAD_LEFT) ?></h1></div>
    <div class="page-actions"><a href="credit_notes.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>
<div class="detail-grid">
    <div class="detail-card">
        <h3>Credit Note Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Customer</span><span class="detail-value"><?= h($cn['company_name'] ?? 'N/A') ?></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($cn['status']) ?>"><?= ucfirst(h($cn['status'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value"><?= formatDate($cn['credit_date']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Amount</span><span class="detail-value" style="font-weight:700;color:#dc2626;font-size:18px;"><?= formatMoney($cn['total']) ?></span></div>
        </div>
    </div>
</div>
<?php if (!empty($cn['reason'])): ?>
<div class="card" style="margin-top:20px;"><h3>Reason</h3><p><?= nl2br(h($cn['reason'])) ?></p></div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
