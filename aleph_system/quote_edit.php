<?php
$pageTitle = 'Edit Quote';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/quote_engine.php';
require_once __DIR__ . '/lib/cost_sheet.php';
requireLogin();

$db = getDB();
$cfg = pq_load_config($db);
$cur = $cfg['currency_symbol'];
$id = (int)($_GET['id'] ?? $_POST['quote_id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid quote.'); header('Location: quotes.php'); exit; }

$quote = dbFetch($db, "SELECT * FROM quotes WHERE id=? AND deleted_at IS NULL", [$id]);
if (!$quote) { setFlash('error', 'Quote not found.'); header('Location: quotes.php'); exit; }

$docSettings = [];
foreach (dbFetchAll($db, "SELECT setting_key, setting_value FROM settings WHERE category IN ('quote_doc','company')") as $r) $docSettings[$r['setting_key']] = $r['setting_value'];
$signatories = array_filter(array_map('trim', explode(',', $docSettings['pq_signatories'] ?? 'Samer Jawhar')));
// current signatory parsed from internal_notes "Signatory: X"
$curSig = '';
if (preg_match('/Signatory:\s*(.+)$/m', $quote['internal_notes'] ?? '', $mm)) $curSig = trim($mm[1]);
$internalClean = trim(preg_replace('/\n?Signatory:.*$/m', '', $quote['internal_notes'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $title = clean($_POST['title'] ?? '');
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $validUntil = $_POST['valid_until'] ?? null;
    $notes = clean($_POST['notes'] ?? '');
    $terms = clean($_POST['terms'] ?? '');
    $internalNotes = clean($_POST['internal_notes'] ?? '');
    $signatory = clean($_POST['signatory'] ?? '');
    $payloads = $_POST['item_payload'] ?? [];

    if ($title === '') { setFlash('error', 'Title is required.'); header('Location: quote_edit.php?id=' . $id); exit; }

    $subtotal = 0;
    $db->beginTransaction();
    try {
        foreach ($payloads as $json) {
            $it = json_decode($json, true);
            if (!is_array($it)) continue;
            $itemId = (int)($it['item_id'] ?? 0);
            // sanitize spec lines
            $specLines = [];
            foreach (($it['spec_lines'] ?? []) as $l) {
                $lab = mb_substr(clean($l['label'] ?? ''), 0, 60); $val = mb_substr(clean($l['value'] ?? ''), 0, 300);
                if ($lab !== '' || $val !== '') $specLines[] = ['label' => $lab, 'value' => $val];
            }
            $options = [];
            foreach (($it['options'] ?? []) as $o) {
                $lab = mb_substr(clean($o['label'] ?? ''), 0, 200); $pr = (float)($o['price'] ?? 0);
                if ($lab !== '') $options[] = ['label' => $lab, 'price' => $pr];
            }
            // cost sheet (worksheet travels with the item; breakdowns recomputed per tier)
            $costSheet = cs_sanitize($it['cost_sheet'] ?? null);
            $csConfig = ['gutter_cm' => (float)($cfg['gutter_mm'] ?? 5) / 10, 'rounding' => (float)($cfg['rounding'] ?? 0), 'currency' => $cur];

            // tiers
            $tiers = []; $primaryIdx = 0;
            foreach (($it['tiers'] ?? []) as $ti => $t) {
                $qty = (int)($t['quantity'] ?? 0);
                $unit = (float)($t['unit_price'] ?? 0);
                $total = isset($t['total_price']) && $t['total_price'] !== '' ? (float)$t['total_price'] : round($unit * $qty, 2);
                if (!empty($t['is_primary'])) $primaryIdx = count($tiers);
                $breakdown = null; $unitCost = 0; $mode = 'manual';
                if ($costSheet && $qty > 0) {
                    $r = cs_compute($costSheet, $qty, $csConfig);
                    $breakdown = $r; $unitCost = $r['unit_cost'];
                    // if the typed price matches the worksheet output, it is worksheet-priced
                    if (abs($total - $r['sell']) < 0.015) $mode = 'engine';
                }
                $tiers[] = ['label' => mb_substr(clean($t['label'] ?? (string)$qty), 0, 100), 'quantity' => $qty, 'unit_price' => $unit, 'total_price' => $total, 'unit_cost' => $unitCost, 'price_mode' => $mode, 'breakdown' => $breakdown];
            }
            if (empty($tiers)) continue;
            $itemTitle = mb_substr(clean($it['title'] ?? 'Print item'), 0, 200);
            $primary = $tiers[$primaryIdx];

            // description text from title + spec lines
            $bits = [];
            foreach ($specLines as $l) if ($l['value'] !== '') $bits[] = ($l['label'] ? $l['label'] . ': ' : '') . $l['value'];
            $desc = mb_substr($itemTitle . ' — ' . implode(', ', $bits), 0, 500);

            if ($itemId > 0 && dbFetch($db, "SELECT id FROM quote_items WHERE id=? AND quote_id=?", [$itemId, $id])) {
                dbUpdate($db, 'quote_items', [
                    'description' => $desc, 'quantity' => $primary['quantity'],
                    'unit_price' => $primary['unit_price'], 'total' => $primary['total_price'],
                ], 'id = ?', [$itemId]);
                dbUpdate($db, 'pq_quote_specs', [
                    'title' => $itemTitle, 'product_name' => $itemTitle,
                    'spec_lines' => json_encode($specLines), 'options' => json_encode($options),
                    'cost_sheet' => $costSheet ? json_encode($costSheet) : null,
                    'breakdown' => ($tiers[$primaryIdx]['breakdown'] ?? null) ? json_encode($tiers[$primaryIdx]['breakdown']) : null,
                ], 'quote_item_id = ?', [$itemId]);
                dbDelete($db, 'pq_quote_tiers', 'quote_item_id = ?', [$itemId]);
            } else {
                // new item added during edit
                $itemId = dbInsert($db, 'quote_items', [
                    'quote_id' => $id, 'product_id' => null, 'description' => $desc,
                    'quantity' => $primary['quantity'], 'unit_price' => $primary['unit_price'],
                    'discount' => 0, 'total' => $primary['total_price'], 'sort_order' => 99,
                ]);
                dbInsert($db, 'pq_quote_specs', [
                    'quote_id' => $id, 'quote_item_id' => $itemId, 'title' => $itemTitle, 'product_name' => $itemTitle,
                    'method' => 'offset', 'spec_lines' => json_encode($specLines), 'options' => json_encode($options),
                    'cost_sheet' => $costSheet ? json_encode($costSheet) : null,
                    'breakdown' => ($tiers[$primaryIdx]['breakdown'] ?? null) ? json_encode($tiers[$primaryIdx]['breakdown']) : null,
                ]);
            }
            $tsort = 0;
            foreach ($tiers as $ti => $t) {
                dbInsert($db, 'pq_quote_tiers', [
                    'quote_id' => $id, 'quote_item_id' => $itemId, 'label' => $t['label'],
                    'quantity' => $t['quantity'], 'unit_price' => $t['unit_price'], 'total_price' => $t['total_price'],
                    'unit_cost' => $t['unit_cost'], 'price_mode' => $t['price_mode'],
                    'breakdown' => $t['breakdown'] ? json_encode($t['breakdown']) : null,
                    'is_primary' => ($ti === $primaryIdx) ? 1 : 0, 'sort_order' => $tsort++,
                ]);
            }
            $subtotal += $primary['total_price'];
        }

        // removed items (present before, not in payload): delete tiers/specs/items not resubmitted
        $keepIds = array_filter(array_map(fn($j) => (int)(json_decode($j, true)['item_id'] ?? 0), $payloads));
        foreach (dbFetchAll($db, "SELECT id FROM quote_items WHERE quote_id=?", [$id]) as $qi) {
            if (!in_array((int)$qi['id'], $keepIds, true)) {
                dbDelete($db, 'pq_quote_tiers', 'quote_item_id = ?', [$qi['id']]);
                dbDelete($db, 'pq_quote_specs', 'quote_item_id = ?', [$qi['id']]);
                dbDelete($db, 'quote_items', 'id = ?', [$qi['id']]);
            }
        }

        dbUpdate($db, 'quotes', [
            'title' => $title, 'customer_id' => $customerId > 0 ? $customerId : null,
            'valid_until' => $validUntil ?: null, 'notes' => $notes, 'terms' => $terms,
            'internal_notes' => trim($internalNotes . "\nSignatory: " . $signatory),
            'subtotal' => round($subtotal, 2), 'total' => round($subtotal, 2),
        ], 'id = ?', [$id]);

        $db->commit();
        logActivity('quotes', 'update', 'quote', $id, "Edited quote #{$quote['quote_number']}");
        setFlash('success', 'Quote updated.');
        header('Location: quote_view.php?id=' . $id); exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed to save: ' . $e->getMessage());
        header('Location: quote_edit.php?id=' . $id); exit;
    }
}

// ---- load for form ----
$customers = dbFetchAll($db, "SELECT id, company_name FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
$papersCat = dbFetchAll($db, "SELECT id,name,gsm,sheet_w_cm,sheet_h_cm,cost_per_sheet,price_per_ton,price_mode FROM pq_papers WHERE active=1 ORDER BY type,gsm,name");
if (empty($papersCat)) $papersCat = dbFetchAll($db, "SELECT id,name,gsm,sheet_w_cm,sheet_h_cm,cost_per_sheet FROM pq_papers WHERE active=1 ORDER BY gsm,name");
$items = dbFetchAll($db, "SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order", [$id]);
$itemData = [];
foreach ($items as $item) {
    $spec = dbFetch($db, "SELECT * FROM pq_quote_specs WHERE quote_item_id=?", [$item['id']]);
    $tiers = dbFetchAll($db, "SELECT * FROM pq_quote_tiers WHERE quote_item_id=? ORDER BY sort_order", [$item['id']]);
    $itemData[] = [
        'item_id' => (int)$item['id'],
        'title' => $spec['title'] ?? $item['description'],
        'spec_lines' => $spec && $spec['spec_lines'] ? (json_decode($spec['spec_lines'], true) ?: []) : [],
        'options' => $spec && $spec['options'] ? (json_decode($spec['options'], true) ?: []) : [],
        'cost_sheet' => $spec && !empty($spec['cost_sheet']) ? (json_decode($spec['cost_sheet'], true) ?: null) : null,
        'tiers' => array_map(fn($t) => ['label'=>$t['label'],'quantity'=>(int)$t['quantity'],'unit_price'=>(float)$t['unit_price'],'total_price'=>(float)$t['total_price'],'is_primary'=>(int)$t['is_primary']], $tiers),
    ];
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Edit Quote #<?= str_pad($quote['quote_number'],4,'0',STR_PAD_LEFT) ?></h1><p class="page-subtitle">Everything is editable — specs, quantities and prices</p></div>
    <div class="page-actions"><a href="quote_view.php?id=<?= $id ?>" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Cancel</a></div>
</div>

<form method="POST" id="editForm">
    <?= csrfField() ?>
    <input type="hidden" name="quote_id" value="<?= $id ?>">
    <div class="card">
        <h3>Quote Details</h3>
        <div class="form-grid">
            <div class="form-group"><label>Customer</label>
                <select name="customer_id" class="form-control"><option value="">— No customer (walk-in) —</option>
                    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id']==$quote['customer_id']?'selected':'' ?>><?= h($c['company_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Title <span class="required">*</span></label><input type="text" name="title" class="form-control" required value="<?= h($quote['title']) ?>"></div>
            <div class="form-group"><label>Valid Until</label><input type="date" name="valid_until" class="form-control" value="<?= h($quote['valid_until']) ?>"></div>
            <div class="form-group"><label>Signatory</label>
                <select name="signatory" class="form-control"><?php foreach ($signatories as $sig): ?><option value="<?= h($sig) ?>" <?= $sig===$curSig?'selected':'' ?>><?= h($sig) ?></option><?php endforeach; ?></select>
            </div>
        </div>
    </div>

    <div class="pq-item-head" style="margin:18px 4px 6px;"><h3 style="margin:0;">Items</h3><button type="button" class="btn btn-secondary btn-sm" onclick="addItem()"><i data-lucide="plus"></i> Add Item</button></div>
    <div id="items"></div>

    <div class="card" style="margin-top:16px;">
        <h3>Notes &amp; Terms</h3>
        <div class="form-grid">
            <div class="form-group full-width"><label>Notes (printed)</label><textarea name="notes" class="form-control" rows="2"><?= h($quote['notes']) ?></textarea></div>
            <div class="form-group full-width"><label>Payment terms (printed)</label><textarea name="terms" class="form-control" rows="2"><?= h($quote['terms']) ?></textarea></div>
            <div class="form-group full-width"><label>Internal notes (not printed)</label><textarea name="internal_notes" class="form-control" rows="2"><?= h($internalClean) ?></textarea></div>
        </div>
    </div>

    <div style="margin-top:18px;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Changes</button>
        <a href="quote_view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<style>
.pq-tiers-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--card)}
.pq-tiers-table{width:100%;min-width:600px;border-collapse:collapse}
.pq-tiers-table thead th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500);font-weight:700;text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);background:var(--gray-50)}
.pq-tiers-table td{padding:8px 10px;vertical-align:middle;border-bottom:1px solid var(--gray-100)}
.pq-tiers-table tr:last-child td{border-bottom:none}
.pq-tiers-table input.form-control{width:100%;font-size:13px;padding:8px 10px;min-height:38px;background:var(--card)}
.pq-tiers-table input[data-tu],.pq-tiers-table input[data-tt]{font-weight:700;color:var(--ink)}
.cs-block{border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--gray-50);padding:14px;margin-bottom:14px}
.cs-block>summary{cursor:pointer;font-weight:700;font-size:13px;color:var(--primary);list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px}
.cs-block>summary::-webkit-details-marker{display:none}
.cs-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--gray-200);border-radius:var(--radius);background:var(--card);margin-top:10px}
.cs-table{width:100%;border-collapse:collapse}
.cs-table thead th{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-500);font-weight:700;text-align:left;padding:8px 8px;border-bottom:1px solid var(--gray-200);background:var(--gray-50);white-space:nowrap}
.cs-table td{padding:6px 6px;vertical-align:middle;border-bottom:1px solid var(--gray-100)}
.cs-table input.form-control,.cs-table select.form-control{font-size:13px;padding:8px;min-height:38px;background:var(--card);width:100%}
.cs-comp-table{min-width:900px}
.cs-ops-table{min-width:720px}
.cs-derived{font-size:12px;color:var(--gray-500);background:var(--gray-50)!important;padding:5px 10px!important}
.cs-derived strong{color:var(--ink)}
.cs-totline{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:10px;font-size:13px}
.cs-totline .t{color:var(--gray-500)}
.cs-totline .v{font-weight:700;color:var(--ink)}
.cs-pin{background:var(--accent-subtle)!important;border-color:var(--primary)!important}
</style>
<template id="itemTpl">
    <div class="card" data-item style="margin-bottom:14px;">
        <div class="pq-item-head">
            <input type="text" class="form-control" data-title placeholder="Item name" style="font-weight:600;max-width:70%;">
            <button type="button" class="btn btn-danger btn-sm" data-delitem><i data-lucide="trash-2"></i></button>
        </div>
        <input type="hidden" data-itemid value="0">
        <div class="form-group">
            <label style="display:flex;justify-content:space-between;">Specification lines <button type="button" class="btn btn-secondary btn-sm" data-addline>+ line</button></label>
            <div data-lines></div>
        </div>
        <details class="cs-block" data-csblock style="display:none;">
            <summary>
                <span>Job cost sheet — the worksheet behind this price</span>
                <button type="button" class="btn btn-secondary btn-sm" data-csapply title="Recompute every tier price from this worksheet">Apply worksheet to tier prices</button>
            </summary>
            <div class="cs-scroll"><table class="cs-table cs-comp-table">
                <thead><tr><th style="min-width:110px;">Component</th><th style="min-width:130px;">Paper</th><th style="width:70px;">Sheet W</th><th style="width:70px;">Sheet H</th><th style="width:90px;">Cost/sheet</th><th style="width:70px;">Piece W</th><th style="width:70px;">Piece H</th><th style="width:64px;">Ups</th><th style="width:64px;">Waste%</th><th style="width:64px;">Setup sh</th><th style="width:64px;">Per prod</th><th style="width:40px;"></th></tr></thead>
                <tbody data-cscomps></tbody>
            </table></div>
            <button type="button" class="btn btn-secondary btn-sm" data-csaddcomp style="margin-top:8px;">+ paper component</button>
            <div class="cs-scroll"><table class="cs-table cs-ops-table">
                <thead><tr><th style="min-width:170px;">Operation</th><th style="width:140px;">Basis</th><th style="width:90px;">Qty</th><th style="width:90px;">Rate</th><th style="width:100px;">Amount</th><th style="width:40px;"></th></tr></thead>
                <tbody data-csops></tbody>
            </table></div>
            <button type="button" class="btn btn-secondary btn-sm" data-csaddop style="margin-top:8px;">+ operation</button>
            <div class="cs-totline">
                <span><span class="t">Markup&nbsp;%</span> <input type="number" step="0.1" class="form-control" data-csmarkup style="width:84px;display:inline-block;padding:7px;min-height:36px;"></span>
                <span><span class="t">Cost</span> <span class="v" data-cscost>-</span></span>
                <span><span class="t">Sell</span> <span class="v" data-cssell>-</span></span>
            </div>
        </details>

        <div class="form-group">
            <label style="display:flex;justify-content:space-between;">Quantity &amp; price tiers <button type="button" class="btn btn-secondary btn-sm" data-addtier>+ tier</button></label>
            <div class="pq-tiers-scroll"><table class="pq-tiers-table"><thead><tr><th>Qty</th><th style="width:130px;">Unit price</th><th style="width:140px;">Total</th><th style="width:54px;">Primary</th><th style="width:60px;"></th></tr></thead><tbody data-tiers></tbody></table></div>
        </div>
        <div class="form-group">
            <label style="display:flex;justify-content:space-between;">Options (add-ons) <button type="button" class="btn btn-secondary btn-sm" data-addopt>+ option</button></label>
            <div data-opts></div>
        </div>
    </div>
</template>

<script src="assets/js/cost_sheet.js"></script>
<script>
var ITEMS = <?= json_encode($itemData) ?>;
var CUR = <?= json_encode($cur) ?>;
var PAPERS = <?= json_encode($papersCat) ?>;
var CSCFG = { gutter_cm: (<?= (float)($cfg['gutter_mm'] ?? 5) ?>)/10, rounding: <?= (float)($cfg['rounding'] ?? 0) ?>, currency: CUR };
var PAPERBYID = {}; PAPERS.forEach(function(p){ PAPERBYID[p.id]=p; });
var uid = 0;
function money(v){return CUR+(Math.round(v*100)/100).toFixed(2);}
function el(h){var t=document.createElement('template');t.innerHTML=h.trim();return t.content.firstElementChild;}

function addLine(node,label,value){
    var row=el('<div style="display:flex;gap:6px;margin-bottom:6px;"><input type="text" class="form-control" data-l style="max-width:150px;" placeholder="Label"><input type="text" class="form-control" data-v placeholder="Value"><button type="button" class="btn btn-danger btn-sm">&times;</button></div>');
    row.querySelector('[data-l]').value=label||''; row.querySelector('[data-v]').value=value||'';
    row.querySelector('button').addEventListener('click',function(){row.remove();});
    node.querySelector('[data-lines]').appendChild(row);
}
function addTier(node,t){
    var u=node._uid;
    var row=el('<tr>'+
        '<td><div style="display:flex;gap:4px;align-items:center;"><input type="text" class="form-control" data-tl placeholder="e.g. 500" style="flex:1;"><input type="number" class="form-control" data-tq min="0" style="width:60px;"></div></td>'+
        '<td><input type="number" step="0.01" class="form-control" data-tu></td>'+
        '<td><input type="number" step="0.01" class="form-control" data-tt></td>'+
        '<td style="text-align:center;"><input type="radio" name="pri_'+u+'" data-tp></td>'+
        '<td><button type="button" class="btn btn-danger btn-sm">&times;</button></td></tr>');
    t=t||{}; row.querySelector('[data-tl]').value=t.label||''; row.querySelector('[data-tq]').value=t.quantity||0;
    row.querySelector('[data-tu]').value=(t.unit_price!=null?t.unit_price:0); row.querySelector('[data-tt]').value=(t.total_price!=null?t.total_price:0);
    if(t.is_primary) row.querySelector('[data-tp]').checked=true;
    // Sync label ↔ qty
    row.querySelector('[data-tl]').addEventListener('input',function(){var v=parseInt(this.value.replace(/[^0-9]/g,''),10);if(!isNaN(v)&&v>0)row.querySelector('[data-tq]').value=v;});
    row.querySelector('[data-tq]').addEventListener('input',function(){var v=parseInt(this.value,10);if(v>0)row.querySelector('[data-tl]').value=v.toLocaleString();});
    // unit -> total
    row.querySelector('[data-tu]').addEventListener('input',function(){var q=parseInt(row.querySelector('[data-tq]').value,10)||0;row.querySelector('[data-tt]').value=(this.value*q).toFixed(2);});
    // total -> unit
    row.querySelector('[data-tt]').addEventListener('input',function(){var q=parseInt(row.querySelector('[data-tq]').value,10)||0,tt=parseFloat(this.value)||0;row.querySelector('[data-tu]').value=q>0?(tt/q).toFixed(2):'0';});
    // qty -> keep unit, recompute total
    row.querySelector('[data-tq]').addEventListener('input',function(){var q=parseInt(this.value,10)||0,uu=parseFloat(row.querySelector('[data-tu]').value)||0;row.querySelector('[data-tt]').value=(uu*q).toFixed(2);});
    row.querySelector('button').addEventListener('click',function(){row.remove();});
    node.querySelector('[data-tiers]').appendChild(row);
    if(!node.querySelector('[data-tiers] [data-tp]:checked')){var f=node.querySelector('[data-tiers] [data-tp]');if(f)f.checked=true;}
}
function addOpt(node,o){
    o=o||{};
    var row=el('<div style="display:flex;gap:6px;margin-bottom:6px;"><input type="text" class="form-control" data-ol placeholder="Option label"><input type="number" step="0.01" class="form-control" data-op style="max-width:120px;"><button type="button" class="btn btn-danger btn-sm">&times;</button></div>');
    row.querySelector('[data-ol]').value=o.label||''; row.querySelector('[data-op]').value=(o.price!=null?o.price:0);
    row.querySelector('button').addEventListener('click',function(){row.remove();});
    node.querySelector('[data-opts]').appendChild(row);
}
/* ---- Job cost sheet grids (worksheet editing on existing quotes) ---- */
var CS_BASIS_LABELS={fixed:'Fixed / job',per_sheet:'Per sheet',per_1000_sheets:'Per 1000 sheets',per_piece:'Per piece',per_1000_pieces:'Per 1000 pieces',per_sqm:'Per m2',per_plate:'Per plate'};
function csHasSheet(node){ return node._cs && (node._cs.components.length>0 || node._cs.operations.length>0); }
function csPrimaryQty(node){
    var pri=node.querySelector('[data-tiers] tr [data-tp]:checked');
    var row=pri?pri.closest('tr'):node.querySelector('[data-tiers] tr');
    var q=row?parseInt(row.querySelector('[data-tq]').value,10)||0:0;
    return q>0?q:1000;
}
function csNI(val,step){ return '<input type="number" step="'+(step||'0.01')+'" class="form-control" value="'+(val===null||val===undefined?'':val)+'">'; }
function csRender(node){
    var block=node.querySelector('[data-csblock]');
    block.style.display='';
    if(csHasSheet(node)) block.open=true;
    var cbody=node.querySelector('[data-cscomps]'); cbody.innerHTML='';
    node._cs.components.forEach(function(c,i){
        var tr=document.createElement('tr');
        var paperOpts='<option value="">custom</option>';
        PAPERS.forEach(function(p){ paperOpts+='<option value="'+p.id+'"'+(c.paper_id==p.id?' selected':'')+'>'+p.name+'</option>'; });
        tr.innerHTML='<td><input type="text" class="form-control" value="'+(c.label||'').replace(/"/g,'&quot;')+'"></td>'+
            '<td><select class="form-control">'+paperOpts+'</select></td>'+
            '<td>'+csNI(c.sheet_w_cm)+'</td><td>'+csNI(c.sheet_h_cm)+'</td>'+
            '<td>'+csNI(Math.round(CostSheet.sheetCost(c)*10000)/10000,'0.0001')+'</td>'+
            '<td>'+csNI(Math.round(c.piece_w_cm*100)/100)+'</td><td>'+csNI(Math.round(c.piece_h_cm*100)/100)+'</td>'+
            '<td><input type="number" step="1" class="form-control" value="'+(c.ups===null||c.ups===undefined?'':c.ups)+'" placeholder="auto"></td>'+
            '<td>'+csNI(c.waste_pct)+'</td>'+
            '<td><input type="number" step="1" class="form-control" value="'+(c.setup_sheets||0)+'"></td>'+
            '<td>'+csNI(c.pieces_per_product||1,'0.5')+'</td>'+
            '<td><button type="button" class="btn btn-danger btn-sm">x</button></td>';
        var inp=tr.querySelectorAll('input,select,button');
        inp[0].addEventListener('input',function(){c.label=this.value;});
        inp[1].addEventListener('change',function(){var p=PAPERBYID[this.value];if(p){c.paper_id=p.id;c.paper_name=p.name;c.sheet_w_cm=parseFloat(p.sheet_w_cm)||70;c.sheet_h_cm=parseFloat(p.sheet_h_cm)||100;c.gsm=parseFloat(p.gsm)||0;c.price_mode=(p.price_mode==='per_ton')?'per_ton':'per_sheet';c.price_per_ton=parseFloat(p.price_per_ton)||0;c.cost_per_sheet=parseFloat(p.cost_per_sheet)||0;}else{c.paper_id=null;}csRender(node);});
        inp[2].addEventListener('input',function(){c.sheet_w_cm=parseFloat(this.value)||0;csTotals(node);});
        inp[3].addEventListener('input',function(){c.sheet_h_cm=parseFloat(this.value)||0;csTotals(node);});
        inp[4].addEventListener('input',function(){c.price_mode='per_sheet';c.cost_per_sheet=parseFloat(this.value)||0;this.classList.add('cs-pin');csTotals(node);});
        inp[5].addEventListener('input',function(){c.piece_w_cm=parseFloat(this.value)||0;csTotals(node);});
        inp[6].addEventListener('input',function(){c.piece_h_cm=parseFloat(this.value)||0;csTotals(node);});
        inp[7].addEventListener('input',function(){c.ups=this.value===''?null:parseInt(this.value,10)||null;this.classList.toggle('cs-pin',c.ups!==null);csTotals(node);});
        inp[8].addEventListener('input',function(){c.waste_pct=parseFloat(this.value)||0;csTotals(node);});
        inp[9].addEventListener('input',function(){c.setup_sheets=parseInt(this.value,10)||0;csTotals(node);});
        inp[10].addEventListener('input',function(){c.pieces_per_product=parseFloat(this.value)||1;csTotals(node);});
        inp[11].addEventListener('click',function(){node._cs.components.splice(i,1);csRender(node);});
        cbody.appendChild(tr);
        var dr=document.createElement('tr'); dr.innerHTML='<td class="cs-derived" colspan="12" data-derived></td>'; cbody.appendChild(dr);
    });
    var obody=node.querySelector('[data-csops]'); obody.innerHTML='';
    node._cs.operations.forEach(function(o,i){
        var tr=document.createElement('tr');
        var basisOpts=''; Object.keys(CS_BASIS_LABELS).forEach(function(b){ basisOpts+='<option value="'+b+'"'+(o.basis===b?' selected':'')+'>'+CS_BASIS_LABELS[b]+'</option>'; });
        tr.innerHTML='<td><input type="text" class="form-control" value="'+(o.label||'').replace(/"/g,'&quot;')+'"></td>'+
            '<td><select class="form-control">'+basisOpts+'</select></td>'+
            '<td><input type="number" step="0.01" class="form-control'+(o.qty_override!==null&&o.qty_override!==undefined?' cs-pin':'')+'" value="'+(o.qty_override===null||o.qty_override===undefined?'':o.qty_override)+'" placeholder="auto"></td>'+
            '<td>'+csNI(o.rate,'0.0001')+'</td>'+
            '<td><input type="number" step="0.01" class="form-control'+(o.amount_override!==null&&o.amount_override!==undefined?' cs-pin':'')+'" value="'+(o.amount_override===null||o.amount_override===undefined?'':o.amount_override)+'" placeholder="auto"></td>'+
            '<td><button type="button" class="btn btn-danger btn-sm">x</button></td>';
        var inp=tr.querySelectorAll('input,select,button');
        inp[0].addEventListener('input',function(){o.label=this.value;});
        inp[1].addEventListener('change',function(){o.basis=this.value;csTotals(node);});
        inp[2].addEventListener('input',function(){o.qty_override=this.value===''?null:parseFloat(this.value);this.classList.toggle('cs-pin',o.qty_override!==null);csTotals(node);});
        inp[3].addEventListener('input',function(){o.rate=parseFloat(this.value)||0;csTotals(node);});
        inp[4].addEventListener('input',function(){o.amount_override=this.value===''?null:parseFloat(this.value);this.classList.toggle('cs-pin',o.amount_override!==null);csTotals(node);});
        inp[5].addEventListener('click',function(){node._cs.operations.splice(i,1);csRender(node);});
        obody.appendChild(tr);
    });
    node.querySelector('[data-csmarkup]').value=node._cs.markup_pct||0;
    csTotals(node);
}
function csTotals(node){
    if(!csHasSheet(node)) return;
    var q=csPrimaryQty(node);
    var r=CostSheet.compute(node._cs,q,CSCFG);
    var dcells=node.querySelectorAll('[data-cscomps] [data-derived]');
    r.components.forEach(function(d,i){
        if(!dcells[i]) return;
        dcells[i].innerHTML='at qty '+q.toLocaleString()+': '+(d.overridden.ups?'<strong>'+d.ups+'-up (set)</strong>':d.ups+'-up (auto '+d.ups_auto+')')+' &middot; <strong>'+d.sheets_total.toLocaleString()+'</strong> sheets &middot; '+CUR+d.cost_per_sheet.toFixed(3)+'/sheet &rarr; <strong>'+money(d.cost)+'</strong>';
    });
    var orows=node.querySelectorAll('[data-csops] tr');
    r.operations.forEach(function(d,i){
        var row=orows[i]; if(!row) return;
        var qin=row.querySelectorAll('input')[1], ain=row.querySelectorAll('input')[3];
        if(qin&&!d.overridden.qty) qin.placeholder=d.qty_auto.toLocaleString();
        if(ain&&!d.overridden.amount) ain.placeholder=d.amount.toFixed(2);
    });
    node.querySelector('[data-cscost]').textContent=money(r.cost_total)+' ('+money(r.fixed_total)+' fixed)';
    node.querySelector('[data-cssell]').textContent=money(r.sell)+' ('+r.margin_pct+'% margin)';
}
function csApplyToTiers(node){
    if(!csHasSheet(node)) return;
    node.querySelectorAll('[data-tiers] tr').forEach(function(row){
        var q=parseInt(row.querySelector('[data-tq]').value,10)||0;
        if(q<=0) return;
        var r=CostSheet.compute(node._cs,q,CSCFG);
        row.querySelector('[data-tu]').value=r.unit_price.toFixed(2);
        row.querySelector('[data-tt]').value=r.sell.toFixed(2);
    });
}

function addItem(data){
    data=data||{item_id:0,title:'',spec_lines:[],tiers:[{quantity:0,unit_price:0,total_price:0,is_primary:1}],options:[],cost_sheet:null};
    var node=document.getElementById('itemTpl').content.firstElementChild.cloneNode(true);
    node._uid=++uid;
    document.getElementById('items').appendChild(node);
    node.querySelector('[data-itemid]').value=data.item_id||0;
    node.querySelector('[data-title]').value=data.title||'';
    node.querySelector('[data-delitem]').addEventListener('click',function(){node.remove();});
    node.querySelector('[data-addline]').addEventListener('click',function(){addLine(node,'','');});
    node.querySelector('[data-addtier]').addEventListener('click',function(){addTier(node,{});});
    node.querySelector('[data-addopt]').addEventListener('click',function(){addOpt(node,{});});
    (data.spec_lines||[]).forEach(function(l){addLine(node,l.label,l.value);});
    if(!(data.spec_lines||[]).length) addLine(node,'','');
    (data.tiers||[]).forEach(function(t){addTier(node,t);});
    if(!(data.tiers||[]).length) addTier(node,{});
    (data.options||[]).forEach(function(o){addOpt(node,o);});
    // cost sheet
    node._cs=data.cost_sheet&&(data.cost_sheet.components||data.cost_sheet.operations)?data.cost_sheet:{components:[],operations:[],markup_pct:0,notes:''};
    node.querySelector('[data-csapply]').addEventListener('click',function(e){e.preventDefault();e.stopPropagation();csApplyToTiers(node);});
    node.querySelector('[data-csaddcomp]').addEventListener('click',function(){node._cs.components.push({label:'Component',paper_id:null,paper_name:'',sheet_w_cm:70,sheet_h_cm:100,price_mode:'per_sheet',price_per_ton:0,gsm:0,cost_per_sheet:0,piece_w_cm:0,piece_h_cm:0,ups:null,waste_pct:10,setup_sheets:80,pieces_per_product:1,overrides:{}});csRender(node);});
    node.querySelector('[data-csaddop]').addEventListener('click',function(){node._cs.operations.push({label:'',basis:'fixed',qty_auto:1,qty_override:null,rate:0,amount_override:null,component_ref:null,source:'manual'});csRender(node);});
    node.querySelector('[data-csmarkup]').addEventListener('input',function(){node._cs.markup_pct=parseFloat(this.value)||0;csTotals(node);});
    csRender(node);
    if(window.lucide) lucide.createIcons();
}
function serialize(node){
    var lines=[];node.querySelectorAll('[data-lines] > div').forEach(function(r){lines.push({label:r.querySelector('[data-l]').value,value:r.querySelector('[data-v]').value});});
    var opts=[];node.querySelectorAll('[data-opts] > div').forEach(function(r){opts.push({label:r.querySelector('[data-ol]').value,price:parseFloat(r.querySelector('[data-op]').value)||0});});
    var tiers=[];node.querySelectorAll('[data-tiers] tr').forEach(function(r){tiers.push({label:r.querySelector('[data-tl]').value,quantity:parseInt(r.querySelector('[data-tq]').value,10)||0,unit_price:parseFloat(r.querySelector('[data-tu]').value)||0,total_price:parseFloat(r.querySelector('[data-tt]').value)||0,is_primary:r.querySelector('[data-tp]').checked?1:0});});
    return {item_id:parseInt(node.querySelector('[data-itemid]').value,10)||0,title:node.querySelector('[data-title]').value,spec_lines:lines,tiers:tiers,options:opts,
        cost_sheet:csHasSheet(node)?node._cs:null};
}
// Enter advances to the next field instead of submitting.
document.getElementById('editForm').addEventListener('keydown',function(e){
    if(e.key!=='Enter') return;
    var t=e.target;
    if(t.tagName==='TEXTAREA' || t.tagName==='BUTTON') return;
    if(t.tagName==='INPUT' && t.type==='submit') return;
    e.preventDefault();
    var f=Array.prototype.slice.call(this.querySelectorAll('input,select,textarea')).filter(function(el){ return !el.disabled && el.type!=='hidden' && el.offsetParent!==null; });
    var i=f.indexOf(t);
    if(i>-1 && i<f.length-1){ var nx=f[i+1]; nx.focus(); if(nx.select){ try{ nx.select(); }catch(err){} } }
});
document.getElementById('editForm').addEventListener('submit',function(e){
    document.querySelectorAll('.pq-gen').forEach(function(n){n.remove();});
    document.querySelectorAll('#items [data-item]').forEach(function(node){
        var inp=document.createElement('input');inp.type='hidden';inp.name='item_payload[]';inp.className='pq-gen';inp.value=JSON.stringify(serialize(node));this.appendChild(inp);
    },this);
});
ITEMS.forEach(function(d){addItem(d);});
if(!ITEMS.length) addItem();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
