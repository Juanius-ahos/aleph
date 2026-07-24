<?php
$pageTitle = 'View Quote';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/quote_engine.php';
requireLogin();

$db = getDB();
$cfg = pq_load_config($db);
$cur = $cfg['currency_symbol'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid quote ID.'); header('Location: quotes.php'); exit; }

$quote = dbFetch($db, "SELECT q.*, c.company_name, c.contact_name, c.email,
    CONCAT_WS(' ', u.first_name, u.last_name) as created_by_name
    FROM quotes q LEFT JOIN customers c ON q.customer_id=c.id LEFT JOIN users u ON q.created_by=u.id
    WHERE q.id=? AND q.deleted_at IS NULL", [$id]);
if (!$quote) { setFlash('error', 'Quote not found.'); header('Location: quotes.php'); exit; }

$items = dbFetchAll($db, "SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order", [$id]);
$specsByItem = [];
foreach (dbFetchAll($db, "SELECT * FROM pq_quote_specs WHERE quote_id=?", [$id]) as $s) $specsByItem[$s['quote_item_id']] = $s;
$tiersByItem = [];
foreach (dbFetchAll($db, "SELECT * FROM pq_quote_tiers WHERE quote_id=? ORDER BY sort_order", [$id]) as $t) $tiersByItem[$t['quote_item_id']][] = $t;
$artwork = dbFetchAll($db, "SELECT * FROM documents WHERE entity_type='quote' AND entity_id=? AND category='artwork' AND deleted_at IS NULL", [$id]);
$isAdmin = currentUserRole() === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        if (in_array($newStatus, ['draft','sent','accepted','rejected','expired','archived'])) {
            dbUpdate($db, 'quotes', ['status' => $newStatus], 'id = ?', [$id]);
            logActivity('quotes', 'status_change', 'quote', $id, "Quote status to $newStatus");
            setFlash('success', 'Quote status updated.');
        }
        header('Location: quote_view.php?id=' . $id); exit;
    }
    if ($action === 'delete') {
        dbUpdate($db, 'quotes', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        logActivity('quotes', 'delete', 'quote', $id, "Deleted quote #{$quote['quote_number']}");
        setFlash('success', 'Quote moved to trash.');
        header('Location: quotes.php'); exit;
    }
    if ($action === 'create_job') {
        $totalCost = (float)(dbFetch($db, "SELECT COALESCE(SUM(unit_cost*quantity),0) c FROM pq_quote_tiers WHERE quote_id=? AND is_primary=1", [$id])['c'] ?? 0);
        $qtySum = 0; foreach ($items as $it) $qtySum += (int)$it['quantity'];
        $jobNumber = generateJobNumber($db);
        $jobId = dbInsert($db, 'jobs', [
            'job_number' => $jobNumber, 'quote_id' => $id, 'customer_id' => $quote['customer_id'],
            'title' => $quote['title'], 'description' => $quote['notes'] ?? null, 'status' => 'pending',
            'stage' => 'design', 'priority' => $quote['priority'], 'quantity' => max(1, $qtySum),
            'total_cost' => round($totalCost, 2), 'selling_price' => $quote['total'], 'created_by' => currentUserId(),
        ]);
        if ($jobId) {
            dbUpdate($db, 'quotes', ['status' => 'accepted'], 'id = ?', [$id]);
            logActivity('jobs', 'create_from_quote', 'job', $jobId, "Created job from quote #{$quote['quote_number']}");
            setFlash('success', "Job #$jobNumber created from quote.");
            header('Location: job_view.php?id=' . $jobId); exit;
        }
        setFlash('error', 'Failed to create job.');
        header('Location: quote_view.php?id=' . $id); exit;
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Quote #<?= str_pad($quote['quote_number'], 4, '0', STR_PAD_LEFT) ?></h1><p class="page-subtitle"><?= h($quote['title']) ?></p></div>
    <div class="page-actions">
        <a href="quote_edit.php?id=<?= $id ?>" class="btn btn-primary"><i data-lucide="pencil"></i> Edit</a>
        <a href="quote_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-secondary"><i data-lucide="printer"></i> Print / PDF</a>
        <a href="quotes.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3>Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Customer</span><span class="detail-value"><?= $quote['customer_id'] ? '<a href="customer_view.php?id=' . $quote['customer_id'] . '">' . h($quote['company_name'] ?? 'N/A') . '</a>' : '<span style="color:var(--gray-400)">Walk-in / No customer</span>' ?></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($quote['status']) ?>"><?= ucfirst(h($quote['status'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Valid Until</span><span class="detail-value"><?= $quote['valid_until'] ? formatDate($quote['valid_until']) : '—' ?></span></div>
            <div class="detail-row"><span class="detail-label">Created By</span><span class="detail-value"><?= h(trim($quote['created_by_name']) ?: 'Unknown') ?></span></div>
            <div class="detail-row"><span class="detail-label">Created</span><span class="detail-value"><?= formatDateTime($quote['created_at']) ?></span></div>
        </div>
    </div>
    <div class="detail-card">
        <h3>Summary</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Items</span><span class="detail-value"><?= count($items) ?></span></div>
            <div class="detail-row"><span class="detail-label">VAT shown</span><span class="detail-value"><?= rtrim(rtrim(number_format($quote['tax_rate'],2),'0'),'.') ?>% (+ VAT per line)</span></div>
            <div class="detail-row"><span class="detail-label" style="font-weight:700;">Primary total</span><span class="detail-value" style="font-weight:700;color:var(--accent);font-size:18px;"><?= $cur . number_format($quote['total'], 2) ?> + VAT</span></div>
        </div>
    </div>
</div>

<?php $paperOrder = [];
foreach ($items as $n => $item): $s = $specsByItem[$item['id']] ?? null; $tiers = $tiersByItem[$item['id']] ?? [];
    $lines = $s && $s['spec_lines'] ? (json_decode($s['spec_lines'], true) ?: []) : [];
    $opts = $s && $s['options'] ? (json_decode($s['options'], true) ?: []) : [];
    $bd = $s && $s['breakdown'] ? json_decode($s['breakdown'], true) : null;
    $depth = $s ? ($s['depth_cm'] ?? null) : null;
    $pages = $s ? (int)($s['pages'] ?? 1) : 1;
    $coverPaper = $s ? ($s['cover_paper_name'] ?? null) : null; ?>
<div class="card" style="margin-top:20px;">
    <h3><?= ($n+1) ?> — <?= h($s['title'] ?? $item['description']) ?></h3>
    <?php if ($pages > 1): ?><p style="font-size:13px;color:var(--gray-500);margin:0 0 8px;"><strong><?= $pages ?> pages</strong> — <?= $pages % 4 === 0 ? 'Perfect for ' . $pages . '-page saddle stitch' : ($pages <= 64 ? 'Saddle stitch or perfect bound' : 'Perfect bound recommended') ?></p><?php endif;
       if ($coverPaper): ?><p style="font-size:13px;color:var(--gray-500);margin:0 0 8px;"><strong>Cover:</strong> <?= h($coverPaper) ?></p><?php endif; ?>
    <div class="detail-grid" style="grid-template-columns:1fr 1fr;">
        <div>
            <table class="data-table" style="font-size:14px;">
                <?php foreach ($lines as $l): if ($l['value']==='') continue; ?>
                <tr><td style="width:120px;color:var(--gray-500);"><?= h($l['label']) ?></td><td><?= h($l['value']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div>
            <table class="data-table" style="font-size:14px;">
                <thead><tr><th>Quantity</th><th>Price</th></tr></thead>
                <tbody>
                    <?php foreach ($tiers as $t): ?>
                    <tr <?= $t['is_primary']?'style="font-weight:600;"':'' ?>>
                        <td><?= h($t['label']) ?><?= $t['is_primary']?' <span class="badge badge-info" style="font-size:10px;">primary</span>':'' ?></td>
                        <td><?= $cur . number_format($t['total_price'], 2) ?> + VAT<?php if ($isAdmin && $t['price_mode']==='manual'): ?> <span class="badge badge-warning" style="font-size:10px;">manual</span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($opts as $o): ?>
                    <tr><td style="color:var(--gray-500);">Option: <?= h($o['label']) ?></td><td>+ <?= $cur . number_format($o['price'], 2) ?> + VAT</td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    $isCostSheetFmt = $bd && isset($bd['cost_total']);
    if ($isAdmin && $isCostSheetFmt):
        foreach (($bd['components'] ?? []) as $bc) {
            $pk = trim(($bc['paper_name'] ?: $bc['label']));
            if (!isset($paperOrder[$pk])) $paperOrder[$pk] = ['sheets' => 0, 'cost' => 0];
            $paperOrder[$pk]['sheets'] += (int)$bc['sheets_total'];
            $paperOrder[$pk]['cost'] += (float)$bc['cost'];
        }
    ?>
    <details class="cs-block" style="margin-top:10px;">
        <summary>
            <span>Cost worksheet (internal)</span>
            <span class="cs-head-total"></span>
        </summary>
        <?php foreach ($tiers as $t): $tbd = $t['breakdown'] ? json_decode($t['breakdown'], true) : null; ?>
            <div style="margin-top:10px;">
                <div style="font-weight:700;font-size:13px;">Qty <?= h($t['label']) ?><?= $t['is_primary'] ? ' (primary)' : '' ?> — <?= $t['price_mode'] === 'manual' ? 'priced manually at ' . $cur . number_format($t['total_price'], 2) : 'from worksheet' ?></div>
                <?php if ($tbd && isset($tbd['cost_total'])): ?>
                <div class="cs-scroll"><table class="cs-table" style="font-size:12.5px;">
                    <?php foreach (($tbd['lines'] ?? []) as $l): ?>
                    <tr><td><?= h($l['label']) ?></td><td style="color:var(--gray-500);"><?= h($l['math'] ?? '') ?></td><td style="text-align:right;font-weight:600;white-space:nowrap;"><?= $cur . number_format($l['amount'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:700;"><td>Total cost</td><td style="color:var(--gray-500);"><?= $cur . number_format($tbd['fixed_total'] ?? 0, 2) ?> fixed + <?= $cur . number_format($tbd['variable_total'] ?? 0, 2) ?> variable</td><td style="text-align:right;white-space:nowrap;"><?= $cur . number_format($tbd['cost_total'], 2) ?></td></tr>
                    <tr style="font-weight:700;color:var(--accent);"><td>Sell</td><td style="color:var(--gray-500);">markup <?= number_format($tbd['markup_pct'] ?? 0, 1) ?>% — margin <?= number_format($tbd['margin_pct'] ?? 0, 1) ?>%</td><td style="text-align:right;white-space:nowrap;"><?= $cur . number_format($tbd['sell'], 2) ?> (<?= $cur . number_format($tbd['unit_price'], 2) ?>/pc)</td></tr>
                </table></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </details>
    <?php elseif ($isAdmin && $bd && !empty($bd['lines'])): ?>
    <div style="margin-top:8px;background:var(--gray-50);padding:8px 12px;border-radius:var(--radius);">
        <small style="color:var(--gray-500);">Primary-tier cost breakdown (legacy estimate):
        <?php foreach ($bd['lines'] as $l): ?><span class="pq-chip"><?= h($l['label']) ?>: <?= $cur . number_format($l['amount'],2) ?></span><?php endforeach; ?>
        <span class="pq-chip" style="background:var(--accent-subtle);">Cost: <?= $cur . number_format($bd['total_cost'] ?? 0,2) ?></span>
        <span class="pq-chip" style="background:var(--accent-subtle);">Margin: <?= number_format($bd['margin_pct'] ?? 0,1) ?>%</span>
        <?php if (!empty($bd['effective_markup']) && $bd['effective_markup'] > 0): ?>
        <span class="pq-chip" style="background:var(--accent-subtle);">Eff. markup: <?= number_format($bd['effective_markup'],1) ?>%</span>
        <?php endif; ?>
        </small>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($isAdmin && !empty($paperOrder)): ?>
<div class="card" style="margin-top:20px;">
    <h3>Paper Purchase Summary (primary quantities, internal)</h3>
    <table class="data-table" style="font-size:13.5px;">
        <thead><tr><th>Paper</th><th style="text-align:right;">Sheets to order</th><th style="text-align:right;">Paper cost</th></tr></thead>
        <tbody>
            <?php foreach ($paperOrder as $pName => $po): ?>
            <tr><td><?= h($pName) ?></td><td style="text-align:right;font-weight:600;"><?= number_format($po['sheets']) ?></td><td style="text-align:right;"><?= $cur . number_format($po['cost'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($artwork)): ?>
<div class="card" style="margin-top:20px;">
    <h3>Artwork / Design Files</h3>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach ($artwork as $a): $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION)); $isImg = in_array($ext, ['png','jpg','jpeg','gif','svg']); ?>
        <a href="<?= h($a['file_path']) ?>" target="_blank" class="pq-preset-btn" style="display:flex;gap:8px;align-items:center;text-decoration:none;color:inherit;">
            <?php if ($isImg): ?><img src="<?= h($a['file_path']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;"><?php else: ?><i data-lucide="file"></i><?php endif; ?>
            <span><strong style="font-size:13px;"><?= h($a['name']) ?></strong><br><small><?= strtoupper($ext) ?> · <?= formatFileSize($a['file_size']) ?></small></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($quote['notes']) || !empty($quote['terms'])): ?>
<div class="card" style="margin-top:20px;">
    <?php if (!empty($quote['notes'])): ?><h3>Notes</h3><p><?= nl2br(h($quote['notes'])) ?></p><?php endif; ?>
    <?php if (!empty($quote['terms'])): ?><h3 style="margin-top:12px;">Payment Terms</h3><p style="color:var(--gray-600);"><?= nl2br(h($quote['terms'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin && !empty($quote['internal_notes'])): ?>
<div class="card" style="margin-top:20px;border-left:3px solid var(--amber);"><h3>Internal Notes</h3><p style="color:var(--amber);white-space:pre-line;"><?= h($quote['internal_notes']) ?></p></div>
<?php endif; ?>

<div class="card" style="margin-top:20px;">
    <h3>Actions</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($quote['status'] === 'draft'): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="status" value="sent"><button type="submit" class="btn btn-primary"><i data-lucide="send"></i> Mark as Sent</button></form>
        <?php elseif ($quote['status'] === 'sent'): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="status" value="accepted"><button type="submit" class="btn btn-success"><i data-lucide="check"></i> Mark Accepted</button></form>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="status" value="rejected"><button type="submit" class="btn btn-danger"><i data-lucide="x"></i> Reject</button></form>
        <?php else: ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="status" value="draft"><button type="submit" class="btn btn-secondary"><i data-lucide="rotate-ccw"></i> Reopen as Draft</button></form>
        <?php endif; ?>
        <?php if ($quote['customer_id']): ?><a href="quote_add.php?customer_id=<?= $quote['customer_id'] ?>" class="btn btn-secondary"><i data-lucide="copy"></i> New Quote for Customer</a><?php endif; ?>
        <form method="POST" onsubmit="return confirm('Move this quote to trash?');" style="margin-left:auto;"><?= csrfField() ?><input type="hidden" name="action" value="delete"><button type="submit" class="btn btn-danger"><i data-lucide="trash-2"></i> Delete</button></form>
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--gray-100);flex-wrap:wrap;">
        <span style="font-size:13px;color:var(--gray-500);">Set status to:</span>
        <form method="POST" style="display:flex;gap:8px;align-items:center;">
            <?= csrfField() ?><input type="hidden" name="action" value="update_status">
            <select name="status" class="form-control" style="max-width:220px;">
                <?php foreach (['draft'=>'Draft','sent'=>'Sent','accepted'=>'Accepted (won)','rejected'=>'Rejected (lost)','expired'=>'Expired','archived'=>'Archived (pre-system)'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= $quote['status']===$sv?'selected':'' ?>><?= $sl ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">Update</button>
        </form>
        <span style="font-size:12px;color:var(--gray-400);">"Archived" = imported/old quote, not counted as revenue.</span>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
