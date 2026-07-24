<?php
$pageTitle = 'Quotes';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$db = getDB();
$cur = dbFetch($db, "SELECT setting_value v FROM settings WHERE setting_key IN ('pq_currency_symbol','currency_symbol') ORDER BY setting_key='pq_currency_symbol' DESC LIMIT 1")['v'] ?? '$';

// ── Actions (delete / restore / duplicate) — handled before any output ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $qid = (int)($_POST['id'] ?? 0);
    if ($qid > 0 && $action === 'delete') {
        dbUpdate($db, 'quotes', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$qid]);
        logActivity('quotes', 'delete', 'quote', $qid, "Deleted quote #$qid");
        setFlash('success', 'Quote moved to trash.');
    } elseif ($qid > 0 && $action === 'restore') {
        dbUpdate($db, 'quotes', ['deleted_at' => null], 'id = ?', [$qid]);
        setFlash('success', 'Quote restored.');
    } elseif ($action === 'bulk_status') {
        $ALLOWED = ['draft','sent','accepted','rejected','expired','archived'];
        $ns = $_POST['bulk_status'] ?? '';
        if (in_array($ns, $ALLOWED, true)) {
            $ids = [];
            if (!empty($_POST['all_matching'])) {
                // Reclassify every quote matching the current filter (e.g. all "Accepted")
                $fSearch = trim($_POST['f_search'] ?? ''); $fStatus = $_POST['f_status'] ?? '';
                $w = ['q.deleted_at IS NULL']; $p = [];
                if ($fSearch !== '') { $w[] = "(q.title LIKE ? OR c.company_name LIKE ? OR CAST(q.quote_number AS CHAR) LIKE ?)"; $s = "%$fSearch%"; $p = array_merge($p, [$s,$s,$s]); }
                if ($fStatus !== '' && in_array($fStatus, $ALLOWED, true)) { $w[] = "q.status = ?"; $p[] = $fStatus; }
                foreach (dbFetchAll($db, "SELECT q.id FROM quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE " . implode(' AND ', $w), $p) as $r) $ids[] = (int)$r['id'];
            } else {
                $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            }
            if ($ids) {
                $place = implode(',', array_fill(0, count($ids), '?'));
                dbQuery($db, "UPDATE quotes SET status=? WHERE id IN ($place)", array_merge([$ns], $ids));
                logActivity('quotes', 'bulk_status', 'quote', null, 'Set ' . count($ids) . " quote(s) to $ns");
                setFlash('success', count($ids) . ' quote(s) marked as ' . ucfirst($ns) . '.');
            } else {
                setFlash('info', 'No quotes were selected.');
            }
        }
    }
    $qs = $_POST['return'] ?? '';
    header('Location: quotes.php' . ($qs ? '?' . $qs : '')); exit;
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$sort   = $_GET['sort'] ?? 'newest';
$trash  = !empty($_GET['trash']);

$orderBy = match ($sort) {
    'oldest'   => 'q.created_at ASC',
    'value'    => 'q.total DESC',
    'number'   => 'q.quote_number DESC',
    default    => 'q.created_at DESC',
};

$wheres = [$trash ? 'q.deleted_at IS NOT NULL' : 'q.deleted_at IS NULL'];
$params = [];
if ($search) {
    $wheres[] = "(q.title LIKE ? OR c.company_name LIKE ? OR CAST(q.quote_number AS CHAR) LIKE ?)";
    $s = "%$search%"; $params = array_merge($params, [$s, $s, $s]);
}
if ($status && in_array($status, ['draft','sent','accepted','rejected','expired','archived'])) {
    $wheres[] = "q.status = ?"; $params[] = $status;
}
$where = implode(' AND ', $wheres);

$result = paginate($db, "SELECT q.*, c.company_name FROM quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE $where ORDER BY $orderBy", $params, 20, $page);
$quotes = $result['rows'];

// Summary across the current filter (not just this page)
$sum = dbFetch($db, "SELECT COUNT(*) c, COALESCE(SUM(q.total),0) t,
        COALESCE(SUM(CASE WHEN q.status='accepted' THEN q.total ELSE 0 END),0) a
    FROM quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE $where", $params);

// Status counts (live, non-trashed) for the filter chips
$counts = [];
foreach (dbFetchAll($db, "SELECT status, COUNT(*) c FROM quotes WHERE deleted_at IS NULL GROUP BY status") as $r) $counts[$r['status']] = (int)$r['c'];
$allCount = array_sum($counts);
$qsBase = http_build_query(array_filter(['search' => $search, 'sort' => $sort !== 'newest' ? $sort : null]));

require_once __DIR__ . '/header.php';

$chips = [''=>'All','draft'=>'Draft','sent'=>'Sent','accepted'=>'Accepted','rejected'=>'Rejected','expired'=>'Expired','archived'=>'Archived'];
?>
<div class="page-header">
    <div class="page-title">
        <h1><?= $trash ? 'Quotes — Trash' : 'Quotes History' ?></h1>
        <p class="page-subtitle"><?= (int)$sum['c'] ?> quote<?= $sum['c'] != 1 ? 's' : '' ?><?= $trash ? ' in trash' : '' ?> · <?= h($cur) . number_format($sum['t'], 0) ?> total value</p>
    </div>
    <div class="page-actions">
        <?php if ($trash): ?>
            <a href="quotes.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Quotes</a>
        <?php else: ?>
            <a href="quote_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Quote</a>
            <a href="quotes.php?trash=1" class="btn btn-secondary"><i data-lucide="trash-2"></i> Trash</a>
            <a href="export.php?type=quotes" class="btn btn-secondary"><i data-lucide="download"></i> Export</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$trash): ?>
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:18px;">
    <div class="stat-card"><div class="stat-icon"><i data-lucide="history"></i></div><div class="stat-number"><?= $allCount ?></div><div class="stat-label">All-time quotes</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#ecfdf5;color:#059669;"><i data-lucide="badge-check"></i></div><div class="stat-number"><?= $cur . number_format($sum['a'], 0) ?></div><div class="stat-label">Accepted value (filtered)</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fff7ed;color:#ea580c;"><i data-lucide="wallet"></i></div><div class="stat-number"><?= $cur . number_format($sum['t'], 0) ?></div><div class="stat-label">Total value (filtered)</div></div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form" style="width:100%;gap:10px;align-items:center;">
        <div class="filter-group" style="position:relative;flex:1;min-width:200px;">
            <i data-lucide="search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);width:16px;height:16px;"></i>
            <input type="text" name="search" placeholder="Search by number, customer or title…" value="<?= h($search) ?>" class="form-control" style="padding-left:36px;">
        </div>
        <div class="filter-group">
            <select name="sort" class="form-control" onchange="this.form.submit()">
                <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest first</option>
                <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest first</option>
                <option value="value" <?= $sort==='value'?'selected':'' ?>>Highest value</option>
                <option value="number" <?= $sort==='number'?'selected':'' ?>>Quote number</option>
            </select>
        </div>
        <input type="hidden" name="status" value="<?= h($status) ?>">
        <button type="submit" class="btn btn-primary"><i data-lucide="search"></i> Search</button>
        <?php if ($search || $status): ?><a href="quotes.php" class="btn btn-secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="quote-chips" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;">
    <?php foreach ($chips as $val => $label): $active = $status === $val;
        $url = 'quotes.php?' . http_build_query(array_filter(['status' => $val ?: null, 'search' => $search ?: null, 'sort' => $sort !== 'newest' ? $sort : null])); ?>
        <a href="<?= h($url) ?>" class="chip <?= $active ? 'chip-active' : '' ?>">
            <?= $label ?><span class="chip-count"><?= $val === '' ? $allCount : ($counts[$val] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form id="bulkForm" method="POST" class="bulk-bar">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_status">
    <input type="hidden" name="return" value="<?= h(http_build_query(array_filter(['search'=>$search,'status'=>$status,'sort'=>$sort!=='newest'?$sort:null]))) ?>">
    <input type="hidden" name="f_search" value="<?= h($search) ?>">
    <input type="hidden" name="f_status" value="<?= h($status) ?>">
    <input type="hidden" name="all_matching" id="allMatching" value="0">
    <span id="bulkCount" style="font-weight:700;">0 selected</span>
    <span style="color:#6b7280;">→ set status to</span>
    <select name="bulk_status" class="form-control" style="max-width:200px;">
        <option value="archived">Archived (pre-system)</option>
        <option value="accepted">Accepted (won)</option>
        <option value="rejected">Rejected (lost)</option>
        <option value="sent">Sent</option>
        <option value="draft">Draft</option>
        <option value="expired">Expired</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="qClearSel()">Clear</button>
    <label id="bulkAllWrap" style="font-size:12.5px;display:none;align-items:center;gap:5px;color:#374151;"><input type="checkbox" id="selAllMatching"> apply to <strong>all <?= (int)$sum['c'] ?></strong> matching this filter</label>
    <div id="bulkIds"></div>
</form>
<?php endif; ?>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr><?php if(!$trash): ?><th style="width:34px;text-align:center;"><input type="checkbox" id="selAll" title="Select all on this page"></th><?php endif; ?><th>Number</th><th>Customer</th><th>Title</th><th>Status</th><th style="text-align:right;">Total</th><th>Date</th><th style="width:120px;">Actions</th></tr>
        </thead>
        <tbody>
            <?php if (empty($quotes)): ?>
                <tr><td colspan="<?= $trash?7:8 ?>" style="padding:0">
                    <div style="padding:48px 20px;text-align:center">
                        <div class="empty-state-illustration">
                            <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:120px;height:90px;">
                                <rect x="30" y="15" width="100" height="80" rx="8" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                                <path d="M50 35h60M50 48h45M50 61h55M50 74h35" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="115" cy="85" r="18" fill="#fff7ed" stroke="#f25424" stroke-width="1.5"/>
                                <path d="M110 85l4 4 8-8" stroke="#f25424" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="empty-state-heading" style="font-weight:700;margin-top:8px;"><?= ($search||$status)?'No matching quotes':($trash?'Trash is empty':'No quotes yet') ?></div>
                        <div class="empty-state-desc" style="color:var(--gray-500);margin:4px 0 14px;"><?= ($search||$status)?'Try a different search or filter.':'Create your first quote using the Quote Builder.' ?></div>
                        <?php if (!$search && !$status && !$trash): ?><a href="quote_add.php" class="btn btn-primary"><i data-lucide="calculator"></i> Build First Quote</a><?php endif; ?>
                    </div>
                </td></tr>
            <?php else: foreach ($quotes as $q): ?>
                <tr>
                    <?php if(!$trash): ?><td style="text-align:center;"><input type="checkbox" class="qsel" value="<?= $q['id'] ?>"></td><?php endif; ?>
                    <td><a href="quote_view.php?id=<?= $q['id'] ?>" class="table-link" style="font-weight:600;">Q-<?= str_pad($q['quote_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                    <td><?= h($q['company_name'] ?: 'Walk-in') ?></td>
                    <td><?= h($q['title']) ?></td>
                    <td><span class="badge <?= getStatusBadgeClass($q['status']) ?>"><?= ucfirst(h($q['status'])) ?></span></td>
                    <td style="text-align:right;font-weight:600;"><?= $cur . number_format($q['total'], 2) ?></td>
                    <td><?= formatDate($q['created_at']) ?></td>
                    <td>
                        <div style="display:flex;gap:2px;">
                        <?php if ($trash): ?>
                            <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= $q['id'] ?>"><input type="hidden" name="return" value="trash=1"><button class="btn-icon" title="Restore"><i data-lucide="rotate-ccw"></i></button></form>
                        <?php else: ?>
                            <a href="quote_view.php?id=<?= $q['id'] ?>" class="btn-icon" title="View"><i data-lucide="eye"></i></a>
                            <a href="quote_pdf.php?id=<?= $q['id'] ?>" target="_blank" class="btn-icon" title="Print / PDF"><i data-lucide="printer"></i></a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Move quote Q-<?= str_pad($q['quote_number'],4,'0',STR_PAD_LEFT) ?> to trash?');"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $q['id'] ?>"><input type="hidden" name="return" value="<?= h($qsBase) ?>"><button class="btn-icon" title="Delete" style="color:var(--red);"><i data-lucide="trash-2"></i></button></form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= renderPagination($result, 'quotes.php', array_filter(['search'=>$search,'status'=>$status,'sort'=>$sort,'trash'=>$trash?1:null])) ?>

<style>
.chip{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;border-radius:20px;font-size:13px;font-weight:600;
  background:var(--white);border:1px solid var(--gray-200);color:var(--gray-600);transition:.12s;text-decoration:none}
.chip:hover{border-color:var(--primary);color:var(--primary)}
.chip-active{background:var(--primary);border-color:var(--primary);color:#fff}
.chip-count{font-size:11px;background:rgba(0,0,0,.08);padding:1px 7px;border-radius:10px;font-weight:700}
.chip-active .chip-count{background:rgba(255,255,255,.25)}
.bulk-bar{display:none;align-items:center;gap:10px;flex-wrap:wrap;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:10px 14px;margin-bottom:14px}
.bulk-bar.show{display:flex}
</style>
<script>
(function(){
    var bar=document.getElementById('bulkForm'); if(!bar) return;
    var selAll=document.getElementById('selAll');
    var allMatchCb=document.getElementById('selAllMatching');
    function boxes(){ return Array.prototype.slice.call(document.querySelectorAll('.qsel')); }
    function update(){
        var n=boxes().filter(function(b){return b.checked;}).length;
        document.getElementById('bulkCount').textContent=n+' selected';
        bar.classList.toggle('show', n>0);
        document.getElementById('bulkAllWrap').style.display=n>0?'inline-flex':'none';
        if(n===0 && allMatchCb) allMatchCb.checked=false;
        if(selAll){ selAll.checked = n>0 && n===boxes().length; }
    }
    if(selAll) selAll.addEventListener('change',function(){ boxes().forEach(function(b){b.checked=selAll.checked;}); update(); });
    boxes().forEach(function(b){ b.addEventListener('change',update); });
    window.qClearSel=function(){ boxes().forEach(function(b){b.checked=false;}); if(allMatchCb)allMatchCb.checked=false; update(); };
    bar.addEventListener('submit',function(e){
        var ids=document.getElementById('bulkIds'); ids.innerHTML='';
        var all=allMatchCb && allMatchCb.checked;
        document.getElementById('allMatching').value=all?'1':'0';
        var checked=boxes().filter(function(b){return b.checked;});
        if(!all && checked.length===0){ e.preventDefault(); alert('Select at least one quote first.'); return; }
        if(!all){ checked.forEach(function(b){ var i=document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=b.value; ids.appendChild(i); }); }
        var lbl=bar.querySelector('[name=bulk_status]').value;
        if(!confirm((all?'Apply to ALL matching quotes':'Apply to '+checked.length+' selected quote(s)')+' → set status to "'+lbl+'"?')){ e.preventDefault(); }
    });
    update();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
