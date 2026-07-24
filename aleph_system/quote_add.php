<?php
$pageTitle = 'Quote Builder';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/quote_engine.php';
require_once __DIR__ . '/lib/cost_sheet.php';
requireLogin();

$db = getDB();
$config = pq_load_config($db);
$cur = $config['currency_symbol'];
$preselectedCustomer = (int)($_GET['customer_id'] ?? 0);

// Document settings
$docSettings = [];
foreach (dbFetchAll($db, "SELECT setting_key, setting_value FROM settings WHERE category IN ('quote_doc','company')") as $r) $docSettings[$r['setting_key']] = $r['setting_value'];
$signatories = array_filter(array_map('trim', explode(',', $docSettings['pq_signatories'] ?? 'Samer Jawhar')));
$defaultTerms = $docSettings['pq_payment_terms'] ?? '50% upon order & 50% upon delivery.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $title = clean($_POST['title'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
    $validUntil = $_POST['valid_until'] ?? null;
    $notes = clean($_POST['notes'] ?? '');
    $terms = clean($_POST['terms'] ?? '');
    $internalNotes = clean($_POST['internal_notes'] ?? '');
    $signatory = clean($_POST['signatory'] ?? ($signatories[0] ?? ''));
    $vatRate = (float)($_POST['vat_rate'] ?? $config['vat_pct']);
    $payloads = $_POST['item_payload'] ?? [];

    if ($title === '' || empty($payloads)) {
        setFlash('error', 'Title and at least one item are required.');
        header('Location: quote_add.php'); exit;
    }

    // Build items (server-authoritative for engine-mode tiers)
    $items = [];
    $subtotal = 0;
    foreach ($payloads as $json) {
        $it = json_decode($json, true);
        if (!is_array($it)) continue;

        $structIn = [
            'size_w_cm' => (float)($it['size_w'] ?? 0), 'size_h_cm' => (float)($it['size_h'] ?? 0),
            'depth_cm' => (float)($it['depth'] ?? 0) ?: null,
            'paper_id' => $it['paper_id'] ?? null, 'method' => $it['method'] ?? 'offset',
            'sides' => $it['sides'] ?? 1,
            'colors_front' => $it['cf'] ?? 0, 'colors_back' => $it['cb'] ?? 0,
            'finishing_ids' => $it['finishing_ids'] ?? [],
            'pages' => (int)($it['pages'] ?? 1), 'category' => $it['category'] ?? '',
            'cover_paper_id' => $it['cover_paper_id'] ?? null,
        ];
        list($baseSpec, $meta) = pq_resolve_spec($db, $structIn);

        // Job cost sheet (pricing v2): server-authoritative recompute per tier
        $costSheet = cs_sanitize($it['cost_sheet'] ?? null);
        $csConfig = [
            'gutter_cm' => (float)($config['gutter_mm'] ?? 5) / 10,
            'rounding' => (float)($config['rounding'] ?? 0),
            'currency' => $cur,
        ];

        // Compute each tier
        $tiers = [];
        $primaryIdx = 0;
        $tin = is_array($it['tiers'] ?? null) ? $it['tiers'] : [];
        foreach ($tin as $ti => $t) {
            $qty = (int)($t['quantity'] ?? 0);
            $mode = ($t['price_mode'] ?? 'engine') === 'manual' ? 'manual' : 'engine';
            $breakdown = null; $unitCost = 0;
            if ($mode === 'engine' && $qty > 0 && $costSheet) {
                $r = cs_compute($costSheet, $qty, $csConfig);
                $unit = $r['unit_price']; $total = $r['sell']; $unitCost = $r['unit_cost']; $breakdown = $r;
            } elseif ($mode === 'engine' && $qty > 0) {
                // legacy estimate path (no cost sheet present)
                $spec = $baseSpec; $spec['quantity'] = $qty; $spec['price_mode'] = 'engine';
                $r = pq_calculate($spec, $config);
                $unit = $r['unit_price']; $total = $r['selling_total']; $unitCost = $r['unit_cost']; $breakdown = $r;
            } else {
                $unit = (float)($t['unit_price'] ?? 0); $total = round($unit * $qty, 2);
            }
            if (!empty($t['is_primary'])) $primaryIdx = count($tiers);
            $tiers[] = [
                'label' => mb_substr(clean($t['label'] ?? (string)$qty), 0, 100),
                'quantity' => $qty, 'unit_price' => $unit, 'total_price' => $total,
                'unit_cost' => $unitCost, 'price_mode' => $mode, 'breakdown' => $breakdown,
            ];
        }
        if (empty($tiers)) continue;

        // spec lines & options (sanitised)
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

        $items[] = [
            'title' => mb_substr(clean($it['title'] ?? 'Print item'), 0, 200),
            'struct' => $structIn, 'meta' => $meta, 'spec_lines' => $specLines, 'options' => $options,
            'tiers' => $tiers, 'primary' => $primaryIdx, 'cost_sheet' => $costSheet,
        ];
        $subtotal += $tiers[$primaryIdx]['total_price'];
    }

    if (empty($items)) { setFlash('error', 'No valid items (each item needs at least one quantity/price).'); header('Location: quote_add.php'); exit; }

    $taxAmount = 0; // per-line "+VAT" style; VAT stored for reference/invoicing
    $total = $subtotal;

    $db->beginTransaction();
    try {
        $quoteNumber = generateQuoteNumber($db);
        // store signatory + intro inside internal/terms context via terms; keep notes for customer
        $quoteId = dbInsert($db, 'quotes', [
            'quote_number' => $quoteNumber, 'customer_id' => $customerId > 0 ? $customerId : null, 'title' => $title,
            'status' => 'draft', 'priority' => $priority,
            'subtotal' => round($subtotal, 2), 'discount_amount' => 0,
            'tax_rate' => $vatRate, 'tax_amount' => 0, 'total' => round($total, 2),
            'valid_until' => $validUntil ?: null, 'notes' => $notes,
            'terms' => $terms, 'internal_notes' => trim($internalNotes . "\nSignatory: " . $signatory),
            'created_by' => currentUserId(),
        ]);
        if (!$quoteId) throw new Exception('Failed to create quote');

        $sort = 0;
        foreach ($items as $it) {
            $primary = $it['tiers'][$it['primary']];
            $s = $it['struct']; $m = $it['meta'];
            // readable description from spec lines
            $descBits = [];
            foreach ($it['spec_lines'] as $l) if ($l['value'] !== '') $descBits[] = ($l['label'] ? $l['label'] . ': ' : '') . $l['value'];
            $desc = mb_substr($it['title'] . ' — ' . implode(', ', $descBits), 0, 500);

            $itemId = dbInsert($db, 'quote_items', [
                'quote_id' => $quoteId, 'product_id' => null, 'description' => $desc,
                'quantity' => $primary['quantity'], 'unit_price' => $primary['unit_price'],
                'discount' => 0, 'total' => $primary['total_price'], 'sort_order' => $sort,
            ]);
            dbInsert($db, 'pq_quote_specs', [
                'quote_id' => $quoteId, 'quote_item_id' => $itemId, 'title' => $it['title'],
                'product_id' => $m['product_id'], 'product_name' => $it['title'],
                'size_w_cm' => $s['size_w_cm'], 'size_h_cm' => $s['size_h_cm'],
                'depth_cm' => $s['depth_cm'],
                'pages' => max(1, (int)($it['pages'] ?? 1)),
                'paper_id' => $m['paper_id'], 'paper_name' => $m['paper_name'],
                'cover_paper_id' => $m['cover_paper_id'] ?? null, 'cover_paper_name' => $m['cover_paper_name'] ?? null,
                'method' => ($s['method'] === 'digital' ? 'digital' : 'offset'),
                'colors_front' => (int)$s['colors_front'], 'colors_back' => (int)$s['colors_back'],
                'sides' => (int)$s['sides'] >= 2 ? 2 : 1,
                'finishing_ids' => json_encode($m['finishing_ids']), 'finishing_names' => $m['finishing_names'] ?: null,
                'spec_lines' => json_encode($it['spec_lines']), 'options' => json_encode($it['options']),
                'breakdown' => json_encode($primary['breakdown']), 'sort_order' => $sort,
                'cost_sheet' => $it['cost_sheet'] ? json_encode($it['cost_sheet']) : null,
            ]);
            $tsort = 0;
            foreach ($it['tiers'] as $ti => $t) {
                dbInsert($db, 'pq_quote_tiers', [
                    'quote_id' => $quoteId, 'quote_item_id' => $itemId, 'label' => $t['label'],
                    'quantity' => $t['quantity'], 'unit_price' => $t['unit_price'], 'total_price' => $t['total_price'],
                    'unit_cost' => $t['unit_cost'], 'price_mode' => $t['price_mode'],
                    'is_primary' => ($ti === $it['primary']) ? 1 : 0, 'breakdown' => $t['breakdown'] ? json_encode($t['breakdown']) : null,
                    'sort_order' => $tsort++,
                ]);
            }
            $sort++;
        }

        // Artwork uploads (quote-level)
        $savedFiles = 0;
        if (!empty($_FILES['artwork']) && is_array($_FILES['artwork']['name'])) {
            $allowed = ['pdf','png','jpg','jpeg','gif','svg','ai','eps','tif','tiff','psd','zip'];
            $dir = UPLOADS_PATH . '/quotes/' . $quoteId;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            for ($f = 0; $f < count($_FILES['artwork']['name']); $f++) {
                if (($_FILES['artwork']['error'][$f] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $orig = $_FILES['artwork']['name'][$f]; $size = (int)$_FILES['artwork']['size'][$f];
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed) || $size > 25 * 1024 * 1024) continue;
                $safe = 'art_' . time() . '_' . $f . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                if (@move_uploaded_file($_FILES['artwork']['tmp_name'][$f], $dir . '/' . $safe)) {
                    dbInsert($db, 'documents', [
                        'name' => $orig, 'file_name' => $safe, 'file_path' => 'uploads/quotes/' . $quoteId . '/' . $safe,
                        'file_size' => $size, 'mime_type' => $_FILES['artwork']['type'][$f] ?? null,
                        'category' => 'artwork', 'entity_type' => 'quote', 'entity_id' => $quoteId, 'uploaded_by' => currentUserId(),
                    ]);
                    $savedFiles++;
                }
            }
        }

        $db->commit();
        logActivity('quotes', 'create', 'quote', $quoteId, "Created quote #$quoteNumber (" . count($items) . " items)");
        setFlash('success', "Quote #$quoteNumber created" . ($savedFiles ? " with $savedFiles file(s)" : '') . '.');
        header('Location: quote_view.php?id=' . $quoteId); exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed to create quote: ' . $e->getMessage());
        header('Location: quote_add.php'); exit;
    }
}

// ---- GET: catalog ----
$customers = dbFetchAll($db, "SELECT id, company_name FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
$sizes = dbFetchAll($db, "SELECT id,name,width_cm,height_cm FROM pq_sizes WHERE active=1 ORDER BY category,name");
$papers = dbFetchAll($db, "SELECT id,name,gsm,sheet_w_cm,sheet_h_cm,cost_per_sheet,price_per_ton,price_mode FROM pq_papers WHERE active=1 ORDER BY type,gsm,name");
if (empty($papers)) $papers = dbFetchAll($db, "SELECT id,name,gsm,sheet_w_cm,sheet_h_cm,cost_per_sheet FROM pq_papers WHERE active=1 ORDER BY type,gsm,name"); // pre-upgrade fallback
$finishing = dbFetchAll($db, "SELECT id,name,pricing_model,unit_cost,setup_cost,min_charge FROM pq_finishing WHERE active=1 ORDER BY name");
$products = dbFetchAll($db, "SELECT * FROM pq_products WHERE active=1 ORDER BY category,name");
$catalogJson = json_encode(['sizes'=>$sizes,'papers'=>$papers,'finishing'=>$finishing,'products'=>$products,'config'=>$config]);

require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Quote Builder</h1><p class="page-subtitle">Create a printing quotation — pick a preset or build from scratch, then set quantities and prices.</p></div>
    <div class="page-actions"><a href="quotes.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>

<?php if (empty($papers) || empty($finishing)): ?>
<div class="alert alert-warning"><i data-lucide="alert-triangle"></i> The catalog is empty. <a href="pq_setup.php">Run the installer</a> to seed papers, finishing and presets.</div>
<?php endif; ?>

<form method="POST" id="quoteForm" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div class="detail-grid" style="grid-template-columns: 2fr 1fr; align-items:start;">
        <div>
            <div class="card">
                <h3>Quote Details</h3>
                <div class="form-grid">
                    <div class="form-group"><label>Customer</label>
                        <select name="customer_id" class="form-control"><option value="">— No customer (walk-in) —</option>
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id']==$preselectedCustomer?'selected':'' ?>><?= h($c['company_name']) ?></option><?php endforeach; ?>
                        </select>
                        <p class="pq-help">Optional — leave blank for walk-in customers.</p>
                    </div>
                    <div class="form-group"><label>Quote Title <span class="required">*</span></label><input type="text" name="title" class="form-control" required placeholder="e.g. Aloe Lab – Packaging"></div>
                    <div class="form-group"><label>Valid Until</label><input type="date" name="valid_until" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
                    <div class="form-group"><label>Signatory</label>
                        <select name="signatory" class="form-control"><?php foreach ($signatories as $sig): ?><option value="<?= h($sig) ?>"><?= h($sig) ?></option><?php endforeach; ?></select>
                        <p class="pq-help">Who signs the quote ("Sincerely, …").</p>
                    </div>
                    <div class="form-group"><label>Priority</label><select name="priority" class="form-control"><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></div>
                    <div class="form-group"><label>VAT rate (%)</label><input type="number" step="0.01" name="vat_rate" class="form-control" value="<?= h($config['vat_pct']) ?>"></div>
                </div>
            </div>

            <div class="pq-item-head" style="margin:18px 4px 6px;"><h3 style="margin:0;">Items</h3><button type="button" class="btn btn-secondary btn-sm" onclick="pqAddItem()"><i data-lucide="plus"></i> Add Item</button></div>
            <div id="pqItems">
                <div class="pq-empty-state" id="pqEmpty" style="text-align:center;padding:40px 20px;border:2px dashed var(--line);border-radius:var(--radius-lg);background:var(--gray-50);">
                    <div style="font-size:36px;margin-bottom:8px;color:var(--gray-300);"><i data-lucide="file-plus"></i></div>
                    <p style="font-weight:600;color:var(--gray-500);margin-bottom:4px;">No items yet</p>
                    <p style="font-size:13px;color:var(--gray-400);margin:0 0 12px;">Click "Add Item" above to start building your quote. Each item can be a preset or fully custom.</p>
                    <button type="button" class="btn btn-primary" onclick="pqAddItem()"><i data-lucide="plus"></i> Add your first item</button>
                </div>
            </div>

            <div class="card" style="margin-top:8px;">
                <h3>Artwork / Design Files</h3>
                <p style="color:var(--gray-500);font-size:13px;margin-bottom:8px;">Attach customer artwork files to keep everything in one place. Accepted: PDF, AI, EPS, PSD, PNG, JPG, TIFF, SVG, ZIP — up to 25MB each.</p>
                <input type="file" name="artwork[]" class="form-control" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.svg,.ai,.eps,.tif,.tiff,.psd,.zip">
            </div>

            <div class="card" style="margin-top:16px;">
                <h3>Notes &amp; Terms</h3>
                <div class="form-grid">
                    <div class="form-group full-width"><label>Notes (printed on quote)</label><textarea name="notes" class="form-control" rows="2" placeholder="e.g. Prices valid for 30 days. Delivery within 5 working days."></textarea></div>
                    <div class="form-group full-width"><label>Payment terms (printed on quote)</label><textarea name="terms" class="form-control" rows="2"><?= h($defaultTerms) ?></textarea></div>
                    <div class="form-group full-width"><label>Internal notes (not printed)</label><textarea name="internal_notes" class="form-control" rows="2" placeholder="Only visible to you and your team."></textarea></div>
                </div>
            </div>
        </div>

        <div class="pq-side">
            <div class="pq-price-panel" id="pqSummary">
                <h4>Quote Total</h4>
                <div class="pq-price-big" id="pqGrand"><?= h($cur) ?>0.00</div>
                <div class="pq-price-sub">Sum of each item's primary tier <span id="pqItemsCount">· 0 items</span></div>
                <div class="pq-break" id="pqSummaryBreak"></div>
                <div class="pq-margin" id="pqMargin"></div>
                <p style="font-size:11px;color:var(--gray-400);margin:6px 0 0;">The total includes all items at their primary quantity. VAT is added on the printed quote separately.</p>
                <button type="button" class="pqmath-toggle" id="pqMathToggle" onclick="pqToggleMath()">Show the maths</button>
                <div id="pqSummaryMath" style="display:none;"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px;"><i data-lucide="save"></i> Create Quote</button>
            <p style="color:var(--gray-400);font-size:12px;margin-top:8px;text-align:center;">Prices appear as "+ VAT" on the printed quote. You can edit everything after saving.</p>
        </div>
    </div>
</form>

<!-- ITEM TEMPLATE -->
<style>
.pq-tiers-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--card)}
.pq-tiers-table{width:100%;min-width:520px;border-collapse:collapse}
.pq-tiers-table thead th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500);font-weight:700;text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);background:var(--gray-50)}
.pq-tiers-table td{padding:8px 10px;vertical-align:middle;border-bottom:1px solid var(--gray-100)}
.pq-tiers-table tr:last-child td{border-bottom:none}
.pq-tiers-table input.form-control,.pq-tiers-table select.form-control{width:100%;font-size:13px;padding:8px 10px;min-height:38px;background:var(--card)}
.pq-tiers-table input[data-tunit],.pq-tiers-table input[data-ttotal]{font-weight:700;color:var(--ink)}
/* Job cost sheet */
.cs-block{border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--gray-50);padding:14px;margin-bottom:14px}
.cs-block>summary{cursor:pointer;font-weight:700;font-size:13px;color:var(--primary);list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px}
.cs-block>summary::-webkit-details-marker{display:none}
.cs-head-total{font-weight:700;color:var(--ink);font-size:13px}
.cs-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--gray-200);border-radius:var(--radius);background:var(--card);margin-top:10px}
.cs-table{width:100%;border-collapse:collapse}
.cs-table thead th{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-500);font-weight:700;text-align:left;padding:8px 8px;border-bottom:1px solid var(--gray-200);background:var(--gray-50);white-space:nowrap}
.cs-table td{padding:6px 6px;vertical-align:middle;border-bottom:1px solid var(--gray-100)}
.cs-table tr:last-child td{border-bottom:none}
.cs-table input.form-control,.cs-table select.form-control{font-size:13px;padding:8px;min-height:38px;background:var(--card);width:100%}
.cs-comp-table{min-width:900px}
.cs-ops-table{min-width:720px}
.cs-derived{font-size:12px;color:var(--gray-500);background:var(--gray-50)!important;padding:5px 10px!important}
.cs-derived strong{color:var(--ink)}
.cs-totline{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:10px;font-size:13px}
.cs-totline .t{color:var(--gray-500)}
.cs-totline .v{font-weight:700;color:var(--ink)}
.cs-math{margin-top:10px;background:var(--card);border:1px solid var(--gray-200);border-radius:var(--radius);padding:10px 12px;font-size:12.5px;display:none}
.cs-math.open{display:block}
.cs-math .ln{display:flex;justify-content:space-between;gap:14px;padding:3px 0;border-bottom:1px dashed var(--gray-200)}
.cs-math .ln:last-child{border-bottom:none}
.cs-math .m{color:var(--gray-500)}
.cs-math .a{font-weight:700;white-space:nowrap}
.cs-pin{background:var(--accent-subtle)!important;border-color:var(--primary)!important}
/* Searchable combobox (customer + preset) */
.combo{position:relative}
.combo-panel{position:absolute;z-index:60;top:calc(100% + 4px);left:0;right:0;background:var(--card);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-float);max-height:300px;overflow:auto;padding:5px;display:none}
.combo-item{padding:9px 12px;border-radius:var(--radius);font-size:13px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.combo-item:hover,.combo-item.active{background:var(--gray-100)}
.combo-item.sel{color:var(--primary);font-weight:600}
.combo-empty{padding:10px 12px;color:var(--gray-400);font-size:13px}
/* Sticky quote summary */
.pq-side{position:sticky;top:74px;align-self:start;max-height:calc(100vh - 90px);overflow:auto}
.pq-side::-webkit-scrollbar{width:6px}.pq-side::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:6px}
.pqmath-toggle{background:none;border:none;color:var(--accent-glow);font-size:12px;font-weight:600;cursor:pointer;padding:8px 0 0;display:block}
.pqmath-toggle:hover{color:#fff}
#pqSummaryMath{margin-top:8px;border-top:1px solid rgba(255,255,255,.06);padding-top:6px}
.pqm-item{padding:8px 0;border-bottom:1px dashed rgba(255,255,255,.06)}
.pqm-item:last-child{border-bottom:none}
.pqm-h{font-weight:700;font-size:12px;color:var(--gray-100);margin-bottom:3px}
.pqm-ln{display:flex;justify-content:space-between;gap:10px;font-size:11.5px;color:var(--gray-400);padding:2px 0}
.pqm-ln .mm{color:var(--gray-500)}
.pqm-ln.pqm-tot{color:#fff;font-weight:700;border-top:1px solid rgba(255,255,255,.06);margin-top:3px;padding-top:4px}
/* Finishing tag input */
.fin-tagwrap{display:flex;flex-wrap:wrap;gap:5px;padding:6px 8px;border:1px solid var(--line);border-radius:var(--radius);background:var(--card);min-height:38px;cursor:text;transition:border-color .15s}
.fin-tagwrap:focus-within{border-color:var(--primary)}
.fin-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:var(--radius);background:var(--accent-subtle);color:var(--primary);font-size:12px;font-weight:600;white-space:nowrap;line-height:1.4}
.fin-tag .fin-tag-x{cursor:pointer;opacity:.6;font-size:14px;line-height:1;margin-left:2px}
.fin-tag .fin-tag-x:hover{opacity:1}
.fin-taginput{border:none;outline:none;background:transparent;font-size:13px;min-width:120px;flex:1;padding:2px 0;color:var(--ink)}
.fin-taginput::placeholder{color:var(--gray-400)}
.fin-tag-panel{position:absolute;z-index:60;background:var(--card);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-float);max-height:240px;overflow:auto;padding:4px;display:none;width:100%}
.fin-tag-item{padding:7px 10px;border-radius:var(--radius);font-size:12px;cursor:pointer;display:flex;align-items:center;gap:8px}
.fin-tag-item:hover{background:var(--gray-100)}
.fin-tag-item .fin-cat{font-size:10px;color:var(--gray-400);font-weight:600;text-transform:uppercase;margin-left:auto;white-space:nowrap}
.fin-tag-item.used{opacity:.35;pointer-events:none}
.fin-tag-empty{padding:8px 10px;color:var(--gray-400);font-size:12px}
/* Spec lines */
.spec-line{display:flex;gap:6px;margin-bottom:5px;align-items:center}
.spec-line input{font-size:13px}
.spec-line input:first-child{max-width:110px;font-weight:600;color:var(--gray-600)}
/* Qty preset buttons */
.pq-qty-btn{font-size:11px!important;padding:3px 10px!important;min-height:auto!important;font-weight:600;border:1px solid var(--line)!important;background:var(--card)!important;color:var(--gray-600)!important;cursor:pointer;transition:all .15s}
.pq-qty-btn:hover{border-color:var(--primary)!important;color:var(--primary)!important;background:var(--accent-subtle)!important}
/* Tier mode badge */
.pq-tier-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:var(--radius);text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.pq-tier-auto{background:var(--gray-100);color:var(--gray-500)}
.pq-tier-manual{background:var(--accent-subtle);color:var(--primary)}
/* Item section labels */
.pq-section{margin-bottom:14px;padding:12px 14px;border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--card)}
.pq-section-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:default}
.pq-section-head h4{margin:0;font-size:13px;font-weight:700;color:var(--ink)}
.pq-section-head .pq-section-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent-subtle);color:var(--primary);font-size:11px;font-weight:700;flex-shrink:0}
.pq-help{font-size:11.5px;color:var(--gray-400);margin-top:3px;line-height:1.4}
.pq-help strong{color:var(--gray-500)}
@media(max-width:900px){
  #quoteForm > .detail-grid{grid-template-columns:1fr!important}
  .pq-side{order:-1;top:60px;max-height:48vh;box-shadow:var(--shadow-lg)}
}
</style>
<template id="pqItemTemplate">
    <div class="pq-item-card card" data-row style="margin-bottom:16px;">

        <!-- HEADER: Item name + controls -->
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;">
            <input type="text" class="form-control pq-row-title" data-title placeholder="e.g. Aloe Lab – Soft Box" style="font-weight:600;font-size:15px;flex:1;" title="Give this line item a name — it appears on the printed quote">
            <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--gray-500);font-weight:600;white-space:nowrap;cursor:pointer;" title="When ON: all tiers start as manual pricing, no auto-estimation. You type your own prices.">
                <input type="checkbox" data-manualmode style="accent-color:var(--primary);"> Manual pricing
            </label>
            <button type="button" class="btn btn-danger btn-sm" data-remove title="Remove this item"><i data-lucide="trash-2"></i></button>
        </div>

        <!-- STEP 1: Product preset -->
        <div class="pq-section">
            <div class="pq-section-head">
                <span class="pq-section-num">1</span>
                <h4>Product</h4>
            </div>
            <div class="form-group" style="margin:0;">
                <select class="form-control" data-preset><option value="">— Choose a preset (or skip to build custom) —</option></select>
                <p class="pq-help">Pre-fills size, paper, printing, and finishing for common products. <strong>You can always override everything below.</strong></p>
            </div>
        </div>

        <!-- STEP 2: Print specs -->
        <div class="pq-section">
            <div class="pq-section-head">
                <span class="pq-section-num">2</span>
                <h4>Print Specifications</h4>
            </div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group"><label>Size preset</label>
                    <select class="form-control" data-sizepreset><option value="">Custom…</option></select>
                </div>
                <div class="form-group"><label>Trim W × H (cm)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="number" step="0.01" class="form-control" data-w oninput="pqOnStruct(this)" placeholder="Width" title="Width in centimetres (trim size, not sheet)">
                        <input type="number" step="0.01" class="form-control" data-h oninput="pqOnStruct(this)" placeholder="Height" title="Height in centimetres (trim size, not sheet)">
                    </div>
                    <p class="pq-help">The finished size of one piece, in cm.</p>
                </div>
                <div class="form-group"><label>Depth / thickness (cm)</label>
                    <input type="number" step="0.01" class="form-control" data-depth placeholder="Leave empty for flat items" oninput="pqOnStruct(this)" title="For boxes, bags, hardcovers — the 3rd dimension">
                    <p class="pq-help">Only for 3D objects (boxes, bags, cases). Leave empty for flat prints.</p>
                </div>
                <div class="form-group"><label>Number of pages</label>
                    <input type="number" step="1" min="1" class="form-control" data-pages placeholder="1 = single sheet" oninput="pqOnStruct(this)" title="1 for single sheets; 2+ for booklets, catalogs, manuals">
                    <p class="pq-help">1 = single sheet. 2+ = booklet (auto-suggests binding below).</p>
                </div>
                <div class="form-group" data-coverwrap style="display:none;"><label>Cover paper</label>
                    <select class="form-control" data-coverpaper onchange="pqOnStruct(this)"><option value="">— Same as inner pages —</option></select>
                    <p class="pq-help">A different, heavier paper for the cover of booklets.</p>
                </div>
                <div class="form-group"><label>Paper stock</label>
                    <select class="form-control" data-paper onchange="pqOnStruct(this)"></select>
                    <p class="pq-help">The paper everything is printed on (affects cost estimate).</p>
                </div>
                <div class="form-group"><label>Print method</label>
                    <select class="form-control" data-method onchange="pqOnStruct(this)"><option value="offset">Offset (cheaper at high qty)</option><option value="digital">Digital (cheaper at low qty)</option></select>
                    <p class="pq-help">Offset = plate-based, cheaper at 500+. Digital = no plates, good for short runs.</p>
                </div>
                <div class="form-group"><label>Printed sides / Colors (Front / Back)</label>
                    <div style="display:flex;gap:6px;">
                        <select class="form-control" data-sides onchange="pqOnStruct(this)" style="max-width:70px;" title="1 = front only, 2 = front + back"><option value="1">1 side</option><option value="2">2 sides</option></select>
                        <input type="number" class="form-control" data-cf value="4" min="0" max="8" oninput="pqOnStruct(this)" title="Ink colors on the front (0–8)" placeholder="F">
                        <input type="number" class="form-control" data-cb value="0" min="0" max="8" oninput="pqOnStruct(this)" title="Ink colors on the back (0–8)" placeholder="B">
                    </div>
                    <p class="pq-help">4/0 = full color front only. 4/4 = full color both sides. B/W = 1.</p>
                </div>
                <div class="form-group full-width"><label>Finishing operations</label>
                    <div data-finwrap style="position:relative;">
                        <div class="fin-tagwrap" data-fintags></div>
                        <div class="fin-tag-panel" data-finpanel></div>
                        <input type="hidden" data-finids value="">
                    </div>
                    <p class="pq-help">Die-cutting, lamination, binding, foil, embossing… type to search, click to add. Each one adds to the cost estimate.</p>
                </div>
            </div>
        </div>

        <!-- STEP 3: Spec lines (what prints on the quote document) -->
        <div class="pq-section">
            <div class="pq-section-head">
                <span class="pq-section-num">3</span>
                <h4>Printed Specification Lines</h4>
            </div>
            <p class="pq-help" style="margin-top:0;margin-bottom:8px;">These lines appear on the customer-facing quote document. Auto-filled from specs above — edit freely.</p>
            <div data-speclines></div>
            <button type="button" class="btn btn-secondary btn-sm" data-addline style="margin-top:6px;">+ Add line</button>
        </div>

        <!-- STEP 4: Quantity & pricing -->
        <div class="pq-section">
            <div class="pq-section-head">
                <span class="pq-section-num">4</span>
                <h4>Quantity &amp; Pricing</h4>
            </div>
            <p class="pq-help" style="margin-top:0;margin-bottom:8px;">Set quantity tiers with prices. <strong>Type in "Unit price" or "Total" to set your own price</strong> — it switches to Manual mode. Click <strong>Est</strong> to re-calculate from the cost sheet.</p>
            <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center;">
                <span style="font-size:11px;color:var(--gray-400);font-weight:600;">Quick-add qty:</span>
                <button type="button" class="btn btn-secondary btn-sm pq-qty-btn" data-qty="100" title="Add a tier for 100 pieces">100</button>
                <button type="button" class="btn btn-secondary btn-sm pq-qty-btn" data-qty="250" title="Add a tier for 250 pieces">250</button>
                <button type="button" class="btn btn-secondary btn-sm pq-qty-btn" data-qty="500" title="Add a tier for 500 pieces">500</button>
                <button type="button" class="btn btn-secondary btn-sm pq-qty-btn" data-qty="1000" title="Add a tier for 1,000 pieces">1,000</button>
                <button type="button" class="btn btn-secondary btn-sm pq-qty-btn" data-qty="2000" title="Add a tier for 2,000 pieces">2,000</button>
                <button type="button" class="btn btn-secondary btn-sm pq-qty-btn" data-qty="5000" title="Add a tier for 5,000 pieces">5,000</button>
                <span style="color:var(--gray-300);margin:0 2px;">|</span>
                <button type="button" class="btn btn-secondary btn-sm" data-autotiers title="Auto-generate tiers at 1×, 2×, 5×, 10× the first qty"><i data-lucide="zap"></i> Auto tiers</button>
                <button type="button" class="btn btn-secondary btn-sm" data-addtier title="Add a blank tier row">+ tier</button>
            </div>
            <div class="pq-tiers-scroll"><table class="pq-tiers-table"><thead><tr><th>Quantity</th><th style="width:120px;">Unit price</th><th style="width:130px;">Total</th><th style="width:72px;">Mode</th><th style="width:50px;" title="Re-estimate this tier from the cost sheet">Est</th><th style="width:50px;"></th></tr></thead><tbody data-tiers></tbody></table></div>
        </div>

        <!-- Options (collapsed) -->
        <details style="margin-bottom:14px;">
            <summary style="cursor:pointer;font-size:12px;color:var(--gray-500);font-weight:600;padding:4px 0;">
                Add options (printed as "Option … + $" on the quote)
            </summary>
            <div style="padding-top:8px;">
                <p class="pq-help" style="margin-top:0;">Optional extras the customer can add — e.g. "Rounded corners +$50".</p>
                <div data-options></div>
                <button type="button" class="btn btn-secondary btn-sm" data-addoption style="margin-top:6px;">+ Add option</button>
            </div>
        </details>

        <!-- Cost sheet (collapsed) -->
        <details class="cs-block" data-csblock style="margin-top:12px;">
            <summary>
                <span>Cost worksheet — see how the price is built</span>
                <span style="display:flex;gap:8px;align-items:center;">
                    <span class="cs-head-total" data-cstotal></span>
                    <button type="button" class="btn btn-secondary btn-sm" data-csreseed title="Rebuild the cost sheet from the print specs above">Rebuild from specs</button>
                </span>
            </summary>
            <p class="pq-help" style="margin-top:6px;margin-bottom:8px;">Internal only — not shown to the customer. Shows paper cost, press cost, finishing cost, and markup.</p>
            <div class="cs-scroll"><table class="cs-table cs-comp-table">
                <thead><tr><th style="min-width:110px;">Component</th><th style="min-width:150px;">Paper</th><th style="width:70px;">Sheet W</th><th style="width:70px;">Sheet H</th><th style="width:90px;">Cost/sheet</th><th style="width:70px;">Piece W</th><th style="width:70px;">Piece H</th><th style="width:64px;">Ups</th><th style="width:64px;">Waste%</th><th style="width:64px;">Setup sh</th><th style="width:64px;">Per prod</th><th style="width:40px;"></th></tr></thead>
                <tbody data-cscomps></tbody>
            </table></div>
            <button type="button" class="btn btn-secondary btn-sm" data-csaddcomp style="margin-top:8px;">+ Paper component</button>

            <div class="cs-scroll"><table class="cs-table cs-ops-table">
                <thead><tr><th style="min-width:170px;">Operation</th><th style="width:140px;">Basis</th><th style="width:90px;">Qty</th><th style="width:90px;">Rate</th><th style="width:100px;">Amount</th><th style="width:40px;"></th></tr></thead>
                <tbody data-csops></tbody>
            </table></div>
            <button type="button" class="btn btn-secondary btn-sm" data-csaddop style="margin-top:8px;">+ Operation (die, labour, transport)</button>

            <div class="cs-totline">
                <span><span class="t">Markup&nbsp;%</span> <input type="number" step="0.1" class="form-control" data-csmarkup style="width:84px;display:inline-block;padding:7px;min-height:36px;"></span>
                <span><span class="t">Cost</span> <span class="v" data-cscost>-</span></span>
                <span><span class="t">Fixed</span> <span class="v" data-csfixed>-</span></span>
                <span><span class="t">Sell</span> <span class="v" data-cssell>-</span></span>
                <button type="button" class="btn btn-secondary btn-sm" data-csmath>View calculation</button>
            </div>
            <div class="cs-math" data-csmathpanel></div>
        </details>

        <div class="pq-warn" data-warn></div>
    </div>
</template>

<script src="assets/js/quote_engine.js"></script>
<script src="assets/js/cost_sheet.js"></script>
<script>
var PQ = <?= $catalogJson ?>;
var CUR = <?= json_encode($cur) ?>;
var CSCFG = { gutter_cm: (parseFloat(PQ.config.gutter_mm)||5)/10, rounding: parseFloat(PQ.config.rounding)||0, currency: CUR };
// MUST match pq_method_defaults() in lib/quote_engine.php
var METHOD_DEFAULTS={
    offset:{max_sheet_w_cm:52,max_sheet_h_cm:74,plate_cost:35,makeready_cost_per_color:15,run_cost_per_1000:8,click_cost_per_sheet:0,min_charge:50},
    digital:{max_sheet_w_cm:33,max_sheet_h_cm:48,plate_cost:0,makeready_cost_per_color:0,run_cost_per_1000:0,click_cost_per_sheet:0.05,min_charge:25}
};
var uid = 0;
var idx = function(a){var m={};(a||[]).forEach(function(x){m[x.id]=x;});return m;};
var PAPER=idx(PQ.papers), FIN=idx(PQ.finishing), PROD=idx(PQ.products), SIZE=idx(PQ.sizes);
function money(v){return CUR+(Math.round(v*100)/100).toFixed(2);}
function fillSelect(sel,rows,val,lab,ph){sel.innerHTML=ph?'<option value="">'+ph+'</option>':'';rows.forEach(function(r){var o=document.createElement('option');o.value=r[val];o.textContent=lab(r);sel.appendChild(o);});}
function el(html){var t=document.createElement('template');t.innerHTML=html.trim();return t.content.firstElementChild;}

/* Turn a <select> into a type-to-filter combobox. Keeps the <select> as the
   value holder (form submission + change events keep working). */
function enhanceSelect(sel, placeholder){
    if(!sel || sel._combo) return; sel._combo = true;
    sel.style.display='none';
    var wrap=document.createElement('div'); wrap.className='combo';
    var input=document.createElement('input'); input.type='text'; input.className='form-control combo-input'; input.setAttribute('autocomplete','off'); input.placeholder=placeholder||'Search…';
    var panel=document.createElement('div'); panel.className='combo-panel';
    wrap.appendChild(input); wrap.appendChild(panel);
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    var active=-1;
    function opts(){ return Array.prototype.slice.call(sel.options); }
    function labelFor(v){ var f=null; opts().forEach(function(o){ if(o.value===v) f=o; }); return f?f.textContent:''; }
    function setDisplay(){ input.value=labelFor(sel.value); }
    function render(filter){
        var f=(filter||'').toLowerCase(); panel.innerHTML=''; active=-1;
        var vis=opts().filter(function(o){ return o.textContent.toLowerCase().indexOf(f)>=0; });
        vis.forEach(function(o){
            var it=document.createElement('div'); it.className='combo-item'+(o.value===sel.value?' sel':''); it.textContent=o.textContent; it.setAttribute('data-v',o.value);
            it.addEventListener('mousedown',function(e){ e.preventDefault(); choose(o.value); });
            panel.appendChild(it);
        });
        if(!vis.length){ var n=document.createElement('div'); n.className='combo-empty'; n.textContent='No matches'; panel.appendChild(n); }
    }
    function choose(v){ sel.value=v; setDisplay(); panel.style.display='none'; sel.dispatchEvent(new Event('change',{bubbles:true})); }
    function hi(items){ items.forEach(function(it,i){ it.classList.toggle('active',i===active); if(i===active) it.scrollIntoView({block:'nearest'}); }); }
    input.addEventListener('focus',function(){ render(''); panel.style.display='block'; input.select(); });
    input.addEventListener('input',function(){ render(input.value); panel.style.display='block'; });
    input.addEventListener('keydown',function(e){
        var items=panel.querySelectorAll('.combo-item');
        if(e.key==='ArrowDown'){ e.preventDefault(); if(panel.style.display==='none'){render('');panel.style.display='block';} active=Math.min(active+1,items.length-1); hi(items); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); active=Math.max(active-1,0); hi(items); }
        else if(e.key==='Enter'){ e.preventDefault(); if(active>=0&&items[active]) choose(items[active].getAttribute('data-v')); panel.style.display='none'; if(typeof pqFocusNext==='function') pqFocusNext(input); }
        else if(e.key==='Escape'){ panel.style.display='none'; setDisplay(); }
    });
    document.addEventListener('mousedown',function(e){ if(!wrap.contains(e.target)){ panel.style.display='none'; setDisplay(); } });
    setDisplay();
}

/* ============================================================
   FINISHING TAG-INPUT — searchable multi-select with chips
   ============================================================ */
function initFinTagInput(node){
    var wrap=node.querySelector('[data-finwrap]');
    var tagWrap=wrap.querySelector('[data-fintags]');
    var panel=wrap.querySelector('[data-finpanel]');
    var hidden=wrap.querySelector('[data-finids]');
    node._finIds=[];
    // Click anywhere in the tag-wrap to focus the input
    tagWrap.addEventListener('click',function(){var inp=tagWrap.querySelector('.fin-taginput');if(inp){inp.focus();inp.select();}});
    // Build panel items (all finishing, grouped)
    function renderPanel(filter){
        var f=(filter||'').toLowerCase(); panel.innerHTML=''; var any=false;
        var cats={}; PQ.finishing.forEach(function(fi){var c=fi.category||'Other';if(!cats[c])cats[c]=[];cats[c].push(fi);});
        Object.keys(cats).sort().forEach(function(cat){
            cats[cat].forEach(function(fi){
                if(f && fi.name.toLowerCase().indexOf(f)<0 && cat.toLowerCase().indexOf(f)<0) return;
                var used=node._finIds.indexOf(fi.id)>=0;
                var it=el('<div class="fin-tag-item'+(used?' used':'')+'" data-finid="'+fi.id+'"><span>'+fi.name+'</span><span class="fin-cat">'+cat+'</span></div>');
                if(!used){
                    it.addEventListener('mousedown',function(e){e.preventDefault();finAddTag(node,fi.id);var inp=tagWrap.querySelector('.fin-taginput');if(inp){inp.value='';inp.focus();}renderPanel('');});
                }
                panel.appendChild(it); any=true;
            });
        });
        if(!any) panel.innerHTML='<div class="fin-tag-empty">No matching finishing</div>';
    }
    // Create the text input
    var inp=el('<input type="text" class="fin-taginput" placeholder="Search finishing…">');
    inp.addEventListener('focus',function(){renderPanel('');panel.style.display='block';});
    inp.addEventListener('input',function(){renderPanel(this.value);panel.style.display='block';});
    inp.addEventListener('keydown',function(e){
        if(e.key==='Escape'){panel.style.display='none';inp.value='';}
        else if(e.key==='Enter'){
            e.preventDefault();
            // Add the first visible unused item
            var first=panel.querySelector('.fin-tag-item:not(.used)');
            if(first){finAddTag(node,parseInt(first.getAttribute('data-finid'),10));inp.value='';renderPanel('');}
        }
        else if(e.key==='Backspace' && inp.value==='' && node._finIds.length>0){
            finRemoveTag(node,node._finIds[node._finIds.length-1]);
        }
    });
    document.addEventListener('mousedown',function(e){if(!wrap.contains(e.target))panel.style.display='none';});
    tagWrap.appendChild(inp);
    finRenderTags(node);
}
function finAddTag(node,id){
    if(node._finIds.indexOf(id)>=0) return;
    node._finIds.push(id);
    finRenderTags(node);
    // re-render panel to grey-out used items
    var panel=node.querySelector('[data-finpanel]');
    if(panel.style.display!=='none'){
        var inp=node.querySelector('.fin-taginput');
        renderFinPanel(node,inp?inp.value:'');
    }
    pqOnStruct(node);
}
function finRemoveTag(node,id){
    node._finIds=node._finIds.filter(function(x){return x!==id;});
    finRenderTags(node);
    pqOnStruct(node);
}
function finGetSelected(node){
    return node._finIds.slice();
}
function finRenderTags(node){
    var tagWrap=node.querySelector('[data-fintags]');
    var hidden=node.querySelector('[data-finids]');
    // Remove existing chips (keep the input)
    tagWrap.querySelectorAll('.fin-tag').forEach(function(t){t.remove();});
    // Add chips before the input
    var inp=tagWrap.querySelector('.fin-taginput');
    node._finIds.forEach(function(id){
        var f=FIN[id]; if(!f)return;
        var chip=el('<span class="fin-tag"><span class="fin-tag-name">'+f.name+'</span><span class="fin-tag-x">&times;</span></span>');
        chip.querySelector('.fin-tag-x').addEventListener('click',function(e){e.stopPropagation();finRemoveTag(node,id);});
        tagWrap.insertBefore(chip,inp);
    });
    hidden.value=node._finIds.join(',');
}
function renderFinPanel(node,filter){
    var panel=node.querySelector('[data-finpanel]');
    var f=(filter||'').toLowerCase(); panel.innerHTML=''; var any=false;
    var cats={}; PQ.finishing.forEach(function(fi){var c=fi.category||'Other';if(!cats[c])cats[c]=[];cats[c].push(fi);});
    Object.keys(cats).sort().forEach(function(cat){
        cats[cat].forEach(function(fi){
            if(f && fi.name.toLowerCase().indexOf(f)<0 && cat.toLowerCase().indexOf(f)<0) return;
            var used=node._finIds.indexOf(fi.id)>=0;
            var it=el('<div class="fin-tag-item'+(used?' used':'')+'" data-finid="'+fi.id+'"><span>'+fi.name+'</span><span class="fin-cat">'+cat+'</span></div>');
            if(!used){
                it.addEventListener('mousedown',function(e){e.preventDefault();finAddTag(node,fi.id);var inp=node.querySelector('.fin-taginput');if(inp){inp.value='';inp.focus();}renderFinPanel(node,'');});
            }
            panel.appendChild(it); any=true;
        });
    });
    if(!any) panel.innerHTML='<div class="fin-tag-empty">No matching finishing</div>';
}

function pqAddItem(){
    var node=document.getElementById('pqItemTemplate').content.firstElementChild.cloneNode(true);
    node._uid=++uid;
    document.getElementById('pqItems').appendChild(node);
    // Hide empty state
    var emp=document.getElementById('pqEmpty');if(emp)emp.style.display='none';
    fillSelect(node.querySelector('[data-preset]'),PQ.products,'id',function(p){return p.name;},'— Custom / blank —');
    enhanceSelect(node.querySelector('[data-preset]'),'Search presets…');
    fillSelect(node.querySelector('[data-sizepreset]'),PQ.sizes,'id',function(s){return s.name+' ('+(+s.width_cm)+'×'+(+s.height_cm)+')';},'Custom…');
    fillSelect(node.querySelector('[data-paper]'),PQ.papers,'id',function(p){return p.name;},'— Select paper —');
    fillSelect(node.querySelector('[data-coverpaper]'),PQ.papers,'id',function(p){return p.name;},'— Same as inner —');
    var fw=node.querySelector('[data-finwrap]');
    initFinTagInput(node);

    node.querySelector('[data-remove]').addEventListener('click',function(){node.remove();pqRecalc();var emp=document.getElementById('pqEmpty');if(emp&&document.querySelectorAll('#pqItems [data-row]').length===0)emp.style.display='';});
    node.querySelector('[data-preset]').addEventListener('change',function(){pqApplyPreset(node,this.value);});
    node.querySelector('[data-sizepreset]').addEventListener('change',function(){var s=SIZE[this.value];if(s){node.querySelector('[data-w]').value=+s.width_cm;node.querySelector('[data-h]').value=+s.height_cm;pqOnStruct(node);}});
    node.querySelector('[data-addline]').addEventListener('click',function(){pqAddLine(node,'','',null);});
    node.querySelector('[data-addtier]').addEventListener('click',function(){pqAddTier(node,'',0);});
    node.querySelector('[data-autotiers]').addEventListener('click',function(){pqAutoTiers(node);});
    node.querySelector('[data-addoption]').addEventListener('click',function(){pqAddOption(node,'',0);});
    node.querySelector('[data-title]').addEventListener('input',pqRecalc);
    // Manual mode toggle
    node._manualMode=false;
    node.querySelector('[data-manualmode]').addEventListener('change',function(){
        node._manualMode=this.checked;
        node.querySelectorAll('[data-tiers] tr').forEach(function(row){
            if(node._manualMode){row._mode='manual';}
        });
        // Update all mode badges
        node.querySelectorAll('[data-tiers] tr').forEach(function(row){
            var badge=row.querySelector('[data-tmodebadge]');
            if(badge){badge.textContent=row._mode==='manual'?'Manual':'Auto';badge.className='pq-tier-badge '+(row._mode==='manual'?'pq-tier-manual':'pq-tier-auto');}
        });
        // In manual mode, clear cost sheet; don't auto-estimate
        if(node._manualMode){
            node._cs={components:[],operations:[],markup_pct:parseFloat(PQ.config.markup_pct)||120,notes:''};
            node._csTouched=false;
        }
        pqRecalc();
    });
    // Qty preset quick buttons
    node.querySelectorAll('.pq-qty-btn').forEach(function(btn){
        btn.addEventListener('click',function(){
            var q=parseInt(this.getAttribute('data-qty'),10); if(!q)return;
            // Add a new tier with this qty, estimate its price
            pqAddTier(node,q.toLocaleString(),q);
        });
    });

    // Cost sheet wiring
    node._cs = { components: [], operations: [], markup_pct: parseFloat(PQ.config.markup_pct)||50, notes: '' };
    node._csTouched = false;
    node.querySelector('[data-csreseed]').addEventListener('click',function(e){e.preventDefault();csSeed(node,true);});
    node.querySelector('[data-csaddcomp]').addEventListener('click',function(){node._csTouched=true;node._cs.components.push(csBlankComp(node));csRender(node);});
    node.querySelector('[data-csaddop]').addEventListener('click',function(){node._csTouched=true;node._cs.operations.push({label:'',basis:'fixed',qty_auto:1,qty_override:null,rate:0,amount_override:null,component_ref:null,source:'manual'});csRender(node);});
    node.querySelector('[data-csmarkup]').addEventListener('input',function(){node._csTouched=true;node._cs.markup_pct=parseFloat(this.value)||0;csRefresh(node);});
    node.querySelector('[data-csmath]').addEventListener('click',function(){node.querySelector('[data-csmathpanel]').classList.toggle('open');csRefresh(node);});

    // default standard spec lines + one tier
    pqAddLine(node,'Size','','size'); pqAddLine(node,'Paper','','paper'); pqAddLine(node,'Printing','','printing'); pqAddLine(node,'Finishing','','finishing');
    pqAddTier(node,'500',500);
    pqSyncAutoLines(node);
    csSeed(node,false);
    if(window.lucide) lucide.createIcons();
    pqRecalc();
    return node;
}

/* ============================================================
   JOB COST SHEET — seeding, rendering, live math
   ============================================================ */
function csFindPaperByName(name){
    if(!name) return null;
    var lower=String(name).toLowerCase(), best=null;
    PQ.papers.forEach(function(p){ if(p.name.toLowerCase().indexOf(lower)>=0 || lower.indexOf(p.name.toLowerCase())>=0){ if(!best) best=p; } });
    return best;
}
function csCompFromPaper(p, pieceW, pieceH, ppp){
    p=p||{};
    return { label:'Main', paper_id:p.id||null, paper_name:p.name||'',
        sheet_w_cm:parseFloat(p.sheet_w_cm)||70, sheet_h_cm:parseFloat(p.sheet_h_cm)||100,
        price_mode:(p.price_mode==='per_ton')?'per_ton':'per_sheet',
        price_per_ton:parseFloat(p.price_per_ton)||0, gsm:parseFloat(p.gsm)||0,
        cost_per_sheet:parseFloat(p.cost_per_sheet)||0,
        piece_w_cm:pieceW, piece_h_cm:pieceH, ups:null,
        waste_pct:parseFloat(PQ.config.waste_pct)||10, setup_sheets:parseInt(PQ.config.setup_waste_sheets,10)||80,
        pieces_per_product:ppp||1, overrides:{sheets_total:null,paper_cost:null} };
}
function csBlankComp(node){
    var p=PAPER[node.querySelector('[data-paper]').value]||{};
    var c=csCompFromPaper(p, parseFloat(node.querySelector('[data-w]').value)||0, parseFloat(node.querySelector('[data-h]').value)||0, 1);
    c.label='Component'; return c;
}
var CS_CAT_FACTOR={Boxes:1.10,Bags:1.10,Hardcovers:1.15,Books:1.05,Cards:1.05,Commercial:0.95};
var CS_BASIS_LABELS={fixed:'Fixed / job',per_sheet:'Per sheet',per_1000_sheets:'Per 1000 sheets',per_piece:'Per piece',per_1000_pieces:'Per 1000 pieces',per_sqm:'Per m2',per_plate:'Per plate'};
function csFinBasis(model){ return {per_sheet:'per_sheet',per_piece:'per_piece',per_1000:'per_1000_pieces',flat:'fixed',per_sqm:'per_sqm'}[model]||'per_sheet'; }

/* Build the whole sheet from the current print specs (+ preset component templates). */
function csSeed(node, force){
    if(node._csTouched && !force) return;
    var w=parseFloat(node.querySelector('[data-w]').value)||0, h=parseFloat(node.querySelector('[data-h]').value)||0;
    var bleed=((parseFloat(PQ.config.bleed_mm)||3)/10);
    var pw=w>0?w+2*bleed:0, ph=h>0?h+2*bleed:0;
    var pages=parseInt(node.querySelector('[data-pages]').value,10)||1;
    var sides=parseInt(node.querySelector('[data-sides]').value,10)||1;
    var cf=parseInt(node.querySelector('[data-cf]').value,10)||0, cb=parseInt(node.querySelector('[data-cb]').value,10)||0;
    var paper=PAPER[node.querySelector('[data-paper]').value]||null;
    var method=node.querySelector('[data-method]').value;
    var machine=METHOD_DEFAULTS[method]||METHOD_DEFAULTS.offset;
    var presetId=node.querySelector('[data-preset]').value;
    var preset=presetId?PROD[presetId]:null;

    var comps=[];
    var tpl=null;
    if(preset&&preset.component_templates){ try{ tpl=JSON.parse(preset.component_templates); }catch(e){} }
    if(tpl&&tpl.length){
        tpl.forEach(function(t){
            var p=csFindPaperByName(t.paper)||paper||{};
            var cw=(t.piece==='flat')?pw:(parseFloat(t.piece_w_cm)||0)+2*bleed;
            var ch=(t.piece==='flat')?ph:(parseFloat(t.piece_h_cm)||0)+2*bleed;
            var c=csCompFromPaper(p,cw,ch,parseFloat(t.pieces_per_product)||1);
            c.label=t.label||'Component';
            comps.push(c);
        });
    } else if(pages>1){
        var inner=csCompFromPaper(paper||{},pw,ph,Math.max(1,pages/Math.max(1,sides)));
        inner.label='Inner pages'; comps.push(inner);
        var coverP=PAPER[node.querySelector('[data-coverpaper]').value]||null;
        if(coverP){ var cov=csCompFromPaper(coverP,pw,ph,1); cov.label='Cover'; comps.push(cov); }
    } else {
        var main=csCompFromPaper(paper||{},pw,ph,1);
        main.label=(preset?preset.name:'Main'); comps.push(main);
    }

    var ops=[];
    var plates=cf+(sides===2?cb:0);
    if(method==='offset'){
            if(plates>0) ops.push({label:'Plates ('+plates+')',basis:'per_plate',qty_auto:plates,qty_override:null,rate:parseFloat(machine.plate_cost)||0,amount_override:null,component_ref:null,source:'machine'});
            ops.push({label:'Make-ready',basis:'fixed',qty_auto:1,qty_override:null,rate:(parseFloat(machine.makeready_cost_per_color)||0)*Math.max(1,plates),amount_override:null,component_ref:null,source:'machine'});
            ops.push({label:'Press run',basis:'per_1000_sheets',qty_auto:0,qty_override:null,rate:(parseFloat(machine.run_cost_per_1000)||0)*sides,amount_override:null,component_ref:null,source:'machine'});
        } else {
            ops.push({label:'Digital clicks',basis:'per_sheet',qty_auto:0,qty_override:null,rate:(parseFloat(machine.click_cost_per_sheet)||0)*sides,amount_override:null,component_ref:null,source:'machine'});
        }
    node._finIds.forEach(function(fid){
        var f=FIN[fid]; if(!f) return;
        ops.push({label:f.name,basis:csFinBasis(f.pricing_model),qty_auto:0,qty_override:null,rate:parseFloat(f.unit_cost)||0,amount_override:null,component_ref:null,source:'finishing'});
        if(parseFloat(f.setup_cost)>0) ops.push({label:f.name+' setup',basis:'fixed',qty_auto:1,qty_override:null,rate:parseFloat(f.setup_cost)||0,amount_override:null,component_ref:null,source:'finishing'});
    });

    var cat=preset?preset.category:'';
    var markup=(parseFloat(PQ.config.markup_pct)||50)*(CS_CAT_FACTOR[cat]||1);
    node._cs={components:comps,operations:ops,markup_pct:Math.round(markup*10)/10,notes:''};
    node._csTouched=false;
    csRender(node);
}

/* Primary quantity used for the sheet's derived numbers. */
function csPrimaryQty(node){
    var row=node.querySelector('[data-tiers] tr');
    var q=row?parseInt(row.querySelector('[data-tqty]').value,10)||0:0;
    return q>0?q:1000;
}
function csHasSheet(node){ return node._cs && (node._cs.components.length>0 || node._cs.operations.length>0); }
// An item is "ready" to price only once real data exists (a paper chosen, or a
// component carrying a paper/cost). Blank new items stay at 0 until then.
function csItemReady(node){
    var mp=node.querySelector('[data-paper]');
    if(mp && mp.value) return true;
    return (node._cs && node._cs.components ? node._cs.components : []).some(function(c){
        return c.paper_id || parseFloat(c.cost_per_sheet)>0 || parseFloat(c.price_per_ton)>0;
    });
}

/* Render both grids from node._cs (structure changes only). */
function csRender(node){
    var cbody=node.querySelector('[data-cscomps]'); cbody.innerHTML='';
    node._cs.components.forEach(function(c,i){ cbody.appendChild(csCompRow(node,c,i)); cbody.appendChild(csDerivedRow(12)); });
    var obody=node.querySelector('[data-csops]'); obody.innerHTML='';
    node._cs.operations.forEach(function(o,i){ obody.appendChild(csOpRow(node,o,i)); });
    node.querySelector('[data-csmarkup]').value=node._cs.markup_pct;
    csRefresh(node);
}
function csDerivedRow(span){
    var tr=document.createElement('tr');
    tr.innerHTML='<td class="cs-derived" colspan="'+span+'" data-derived></td>';
    return tr;
}
function csNumInput(val,cls,step){
    return '<input type="number" step="'+(step||'0.01')+'" class="form-control'+(cls?' '+cls:'')+'" value="'+(val===null||val===undefined?'':val)+'">';
}
function csCompRow(node,c,i){
    var tr=document.createElement('tr');
    var paperOpts='<option value="">custom</option>';
    PQ.papers.forEach(function(p){ paperOpts+='<option value="'+p.id+'"'+(c.paper_id==p.id?' selected':'')+'>'+p.name+'</option>'; });
    tr.innerHTML='<td><input type="text" class="form-control" value="'+(c.label||'').replace(/"/g,'&quot;')+'"></td>'+
        '<td><select class="form-control">'+paperOpts+'</select></td>'+
        '<td>'+csNumInput(c.sheet_w_cm)+'</td><td>'+csNumInput(c.sheet_h_cm)+'</td>'+
        '<td>'+csNumInput(Math.round(CostSheet.sheetCost(c)*10000)/10000,'','0.0001')+'</td>'+
        '<td>'+csNumInput(Math.round(c.piece_w_cm*100)/100)+'</td><td>'+csNumInput(Math.round(c.piece_h_cm*100)/100)+'</td>'+
        '<td><input type="number" step="1" class="form-control" value="'+(c.ups===null?'':c.ups)+'" placeholder="auto"></td>'+
        '<td>'+csNumInput(c.waste_pct)+'</td>'+
        '<td><input type="number" step="1" class="form-control" value="'+c.setup_sheets+'"></td>'+
        '<td>'+csNumInput(c.pieces_per_product,'','0.5')+'</td>'+
        '<td><button type="button" class="btn btn-danger btn-sm">x</button></td>';
    var inp=tr.querySelectorAll('input,select,button');
    var touch=function(){node._csTouched=true;};
    inp[0].addEventListener('input',function(){touch();c.label=this.value;});
    inp[1].addEventListener('change',function(){touch();var p=PAPER[this.value];if(p){c.paper_id=p.id;c.paper_name=p.name;c.sheet_w_cm=parseFloat(p.sheet_w_cm)||70;c.sheet_h_cm=parseFloat(p.sheet_h_cm)||100;c.gsm=parseFloat(p.gsm)||0;c.price_mode=(p.price_mode==='per_ton')?'per_ton':'per_sheet';c.price_per_ton=parseFloat(p.price_per_ton)||0;c.cost_per_sheet=parseFloat(p.cost_per_sheet)||0;}else{c.paper_id=null;}csRender(node);});
    inp[2].addEventListener('input',function(){touch();c.sheet_w_cm=parseFloat(this.value)||0;csRefresh(node);});
    inp[3].addEventListener('input',function(){touch();c.sheet_h_cm=parseFloat(this.value)||0;csRefresh(node);});
    inp[4].addEventListener('input',function(){touch();c.price_mode='per_sheet';c.cost_per_sheet=parseFloat(this.value)||0;this.classList.add('cs-pin');csRefresh(node);});
    inp[5].addEventListener('input',function(){touch();c.piece_w_cm=parseFloat(this.value)||0;csRefresh(node);});
    inp[6].addEventListener('input',function(){touch();c.piece_h_cm=parseFloat(this.value)||0;csRefresh(node);});
    inp[7].addEventListener('input',function(){touch();c.ups=this.value===''?null:parseInt(this.value,10)||null;this.classList.toggle('cs-pin',c.ups!==null);csRefresh(node);});
    inp[8].addEventListener('input',function(){touch();c.waste_pct=parseFloat(this.value)||0;csRefresh(node);});
    inp[9].addEventListener('input',function(){touch();c.setup_sheets=parseInt(this.value,10)||0;csRefresh(node);});
    inp[10].addEventListener('input',function(){touch();c.pieces_per_product=parseFloat(this.value)||1;csRefresh(node);});
    inp[11].addEventListener('click',function(){touch();node._cs.components.splice(i,1);csRender(node);});
    return tr;
}
function csOpRow(node,o,i){
    var tr=document.createElement('tr');
    var basisOpts='';
    Object.keys(CS_BASIS_LABELS).forEach(function(b){ basisOpts+='<option value="'+b+'"'+(o.basis===b?' selected':'')+'>'+CS_BASIS_LABELS[b]+'</option>'; });
    tr.innerHTML='<td><input type="text" class="form-control" value="'+(o.label||'').replace(/"/g,'&quot;')+'" placeholder="e.g. Cutting die (one-time)"></td>'+
        '<td><select class="form-control">'+basisOpts+'</select></td>'+
        '<td><input type="number" step="0.01" class="form-control'+(o.qty_override!==null?' cs-pin':'')+'" value="'+(o.qty_override===null?'':o.qty_override)+'" placeholder="auto"></td>'+
        '<td>'+csNumInput(o.rate,'','0.0001')+'</td>'+
        '<td><input type="number" step="0.01" class="form-control'+(o.amount_override!==null?' cs-pin':'')+'" value="'+(o.amount_override===null?'':o.amount_override)+'" placeholder="auto"></td>'+
        '<td><button type="button" class="btn btn-danger btn-sm">x</button></td>';
    var inp=tr.querySelectorAll('input,select,button');
    var touch=function(){node._csTouched=true;};
    inp[0].addEventListener('input',function(){touch();o.label=this.value;});
    inp[1].addEventListener('change',function(){touch();o.basis=this.value;if(o.basis==='per_plate'&&!o.qty_auto)o.qty_auto=1;csRefresh(node);});
    inp[2].addEventListener('input',function(){touch();o.qty_override=this.value===''?null:parseFloat(this.value);this.classList.toggle('cs-pin',o.qty_override!==null);csRefresh(node);});
    inp[3].addEventListener('input',function(){touch();o.rate=parseFloat(this.value)||0;csRefresh(node);});
    inp[4].addEventListener('input',function(){touch();o.amount_override=this.value===''?null:parseFloat(this.value);this.classList.toggle('cs-pin',o.amount_override!==null);csRefresh(node);});
    inp[5].addEventListener('click',function(){touch();node._cs.operations.splice(i,1);csRender(node);});
    return tr;
}

/* Recompute derived cells + totals + tier prices (no structural re-render). */
function csRefresh(node){
    if(!csHasSheet(node)){ node.querySelector('[data-cstotal]').textContent=''; return; }
    if(!csItemReady(node)){
        // No real data yet — keep everything at zero instead of a phantom price.
        node.querySelector('[data-cscost]').textContent='—';
        node.querySelector('[data-csfixed]').textContent='';
        node.querySelector('[data-cssell]').textContent='—';
        node.querySelector('[data-cstotal]').textContent='Pick a paper to price';
        node.querySelectorAll('[data-cscomps] [data-derived]').forEach(function(c){ c.innerHTML='<span style="color:#b8ab9c;">choose a paper first</span>'; });
        node.querySelectorAll('[data-tiers] tr').forEach(function(row){ if(row._mode!=='manual'){ row.querySelector('[data-tunit]').value='0.00'; row.querySelector('[data-ttotal]').value='0.00'; row._cost=0; row._effectiveMarkup=undefined; } });
        pqRecalc();
        return;
    }
    var q=csPrimaryQty(node);
    var r=CostSheet.compute(node._cs,q,CSCFG);
    // derived rows under components
    var dcells=node.querySelectorAll('[data-cscomps] [data-derived]');
    r.components.forEach(function(d,i){
        if(!dcells[i]) return;
        dcells[i].innerHTML='at qty '+q.toLocaleString()+': '+(d.overridden.ups?'<strong>'+d.ups+'-up (set)</strong>':d.ups+'-up (auto '+d.ups_auto+')')+
            ' &middot; <strong>'+d.sheets_total.toLocaleString()+'</strong> sheets &middot; '+CUR+d.cost_per_sheet.toFixed(3)+'/sheet'+
            (d.sheet_kg>0?' ('+d.sheet_kg.toFixed(3)+' kg)':'')+
            ' &rarr; <strong>'+money(d.cost)+'</strong>';
    });
    // qty placeholders on ops
    var orows=node.querySelectorAll('[data-csops] tr');
    r.operations.forEach(function(d,i){
        var row=orows[i]; if(!row) return;
        var qin=row.querySelectorAll('input')[1], ain=row.querySelectorAll('input')[3];
        if(qin&&!d.overridden.qty) qin.placeholder=d.qty_auto.toLocaleString();
        if(ain&&!d.overridden.amount) ain.placeholder=d.amount.toFixed(2);
    });
    node.querySelector('[data-cscost]').textContent=money(r.cost_total)+' ('+CUR+r.unit_cost.toFixed(3)+'/pc)';
    node.querySelector('[data-csfixed]').textContent=money(r.fixed_total)+' fixed + '+money(r.variable_total)+' variable';
    node.querySelector('[data-cssell]').textContent=money(r.sell)+' ('+r.margin_pct+'% margin)';
    node.querySelector('[data-cstotal]').textContent='Cost '+money(r.cost_total)+' | Sell '+money(r.sell)+' @ '+q.toLocaleString();
    var mp=node.querySelector('[data-csmathpanel]');
    if(mp.classList.contains('open')){
        var html='<div style="font-weight:700;margin-bottom:4px;">Worksheet at qty '+q.toLocaleString()+'</div>';
        r.lines.forEach(function(l){ html+='<div class="ln"><span>'+l.label+' <span class="m">'+l.math+'</span></span><span class="a">'+money(l.amount)+'</span></div>'; });
        html+='<div class="ln"><span><strong>Total cost</strong></span><span class="a">'+money(r.cost_total)+'</span></div>';
        html+='<div class="ln"><span><strong>Sell</strong> <span class="m">cost x '+(1+r.markup_pct/100).toFixed(2)+' (markup '+r.markup_pct+'%)</span></span><span class="a">'+money(r.sell)+'</span></div>';
        mp.innerHTML=html;
    }
    if(r.warnings.length) node.querySelector('[data-warn]').textContent=r.warnings.join(' ');
    // re-estimate non-manual tiers from the sheet
    node.querySelectorAll('[data-tiers] tr').forEach(function(row){ if(row._mode!=='manual') pqEstimateTier(node,row); });
    pqRecalc();
}

function pqAddLine(node,label,value,key){
    var row=el('<div style="display:flex;gap:6px;margin-bottom:6px;">'+
        '<input type="text" class="form-control" placeholder="Label" style="max-width:130px;" value="'+label.replace(/"/g,'&quot;')+'">'+
        '<input type="text" class="form-control" placeholder="Value" value="'+value.replace(/"/g,'&quot;')+'">'+
        '<button type="button" class="btn btn-danger btn-sm">×</button></div>');
    var inputs=row.querySelectorAll('input');
    if(key){inputs[1].setAttribute('data-key',key);inputs[1].setAttribute('data-auto','1');inputs[1].addEventListener('input',function(){this.setAttribute('data-auto','0');});}
    row.querySelector('button').addEventListener('click',function(){row.remove();});
    node.querySelector('[data-speclines]').appendChild(row);
}

function pqAddTier(node,label,qty){
    var u=node._uid;
    var isManual=node._manualMode;
    var row=el('<tr>'+
        '<td><div style="display:flex;gap:4px;align-items:center;"><input type="text" class="form-control" data-tlabel value="'+label+'" placeholder="e.g. 500" style="flex:1;"><input type="number" class="form-control" data-tqty value="'+qty+'" min="0" style="width:60px;"></div></td>'+
        '<td><input type="number" step="0.01" class="form-control" data-tunit value="0"></td>'+
        '<td><input type="number" step="0.01" class="form-control" data-ttotal value="0" title="Type a total to price this tier manually"></td>'+
        '<td><span data-tmodebadge class="pq-tier-badge '+(isManual?'pq-tier-manual':'pq-tier-auto')+'">'+(isManual?'Manual':'Auto')+'</span></td>'+
        '<td style="white-space:nowrap;"><button type="button" class="btn btn-secondary btn-sm" data-testimate title="Re-estimate from cost sheet">Est</button></td>'+
        '<td style="white-space:nowrap;"><button type="button" class="btn btn-danger btn-sm" data-tdel>×</button></td></tr>');
    row._mode=isManual?'manual':'engine';
    if(node.querySelectorAll('[data-tiers] tr').length===0) {/* first tier is primary by default, no radio needed */}
    // Sync label → qty
    row.querySelector('[data-tlabel]').addEventListener('input',function(){
        var v=parseInt(this.value.replace(/[^0-9]/g,''),10);
        if(!isNaN(v)&&v>0) row.querySelector('[data-tqty]').value=v;
    });
    row.querySelector('[data-tqty]').addEventListener('input',function(){
        var v=parseInt(this.value,10);
        if(v>0) row.querySelector('[data-tlabel]').value=v.toLocaleString();
    });
    function pqTierUpdateModeBadge(){
        var badge=row.querySelector('[data-tmodebadge]');
        if(row._mode==='manual'){badge.textContent='Manual';badge.className='pq-tier-badge pq-tier-manual';}
        else{badge.textContent='Auto';badge.className='pq-tier-badge pq-tier-auto';}
    }
    row.querySelector('[data-tqty]').addEventListener('input',function(){if(row._mode==='manual'){pqTierManual(row);pqRecalc();}else if(csHasSheet(node)){csRefresh(node);}else{pqEstimateTier(node,row);pqRecalc();}});
    row.querySelector('[data-tunit]').addEventListener('input',function(){row._mode='manual';pqTierManual(row);pqTierUpdateModeBadge();pqRecalc();});
    row.querySelector('[data-ttotal]').addEventListener('input',function(){row._mode='manual';var q=parseInt(row.querySelector('[data-tqty]').value,10)||0,t=parseFloat(this.value)||0;row.querySelector('[data-tunit]').value=q>0?(t/q).toFixed(2):'0';pqTierUpdateModeBadge();pqRecalc();});
    row.querySelector('[data-testimate]').addEventListener('click',function(){row._mode='engine';pqEstimateTier(node,row);pqTierUpdateModeBadge();pqRecalc();});
    row.querySelector('[data-tdel]').addEventListener('click',function(){row.remove();pqRecalc();});
    node.querySelector('[data-tiers]').appendChild(row);
    if(qty>0 && !isManual) pqEstimateTier(node,row);
}
function pqTierManual(row){
    var q=parseInt(row.querySelector('[data-tqty]').value,10)||0, u=parseFloat(row.querySelector('[data-tunit]').value)||0;
    row.querySelector('[data-ttotal]').value=(u*q).toFixed(2);
}

// Smart pricing: generate the standard price-break ladder from a base quantity
function pqAutoTiers(node){
    var body=node.querySelector('[data-tiers]');
    var first=body.querySelector('tr');
    var base=first?parseInt(first.querySelector('[data-tqty]').value,10)||0:0;
    if(base<=0){var pr=PROD[node.querySelector('[data-preset]').value];base=pr?+pr.default_qty:1000;}
    if(base<=0)base=1000;
    if(!node.querySelector('[data-paper]').value){node.querySelector('[data-warn]').textContent='Pick a paper first so tiers can be priced.';return;}
    body.innerHTML='';
    [1,2,5,10].forEach(function(m){var q=base*m;pqAddTier(node,q.toLocaleString(),q);});
    pqRecalc();
}

function pqAddOption(node,label,price){
    var row=el('<div style="display:flex;gap:6px;margin-bottom:6px;">'+
        '<input type="text" class="form-control" data-olabel placeholder="e.g. Cialux hard-cased stand" value="'+label.replace(/"/g,'&quot;')+'">'+
        '<input type="number" step="0.01" class="form-control" data-oprice style="max-width:110px;" value="'+price+'">'+
        '<button type="button" class="btn btn-danger btn-sm">×</button></div>');
    row.querySelector('button').addEventListener('click',function(){row.remove();});
    node.querySelector('[data-options]').appendChild(row);
}

function pqBuildSpec(node,qty){
    var paper=PAPER[node.querySelector('[data-paper]').value]||{};
    var finIds=finGetSelected(node);
    var finishing=finIds.map(function(i){return FIN[i];}).filter(Boolean);
    var presetId=node.querySelector('[data-preset]').value;
    var cat=presetId&&PROD[presetId]?PROD[presetId].category:'';
    var coverPaperId=node.querySelector('[data-coverpaper]').value||null;
    var coverPaper=PAPER[coverPaperId]||null;
    var method=node.querySelector('[data-method]').value;
    return {method:method,piece_w_cm:parseFloat(node.querySelector('[data-w]').value)||0,piece_h_cm:parseFloat(node.querySelector('[data-h]').value)||0,
        quantity:qty,sides:parseInt(node.querySelector('[data-sides]').value,10)||1,colors_front:parseInt(node.querySelector('[data-cf]').value,10)||0,colors_back:parseInt(node.querySelector('[data-cb]').value,10)||0,
        sheet_w_cm:paper.sheet_w_cm||70,sheet_h_cm:paper.sheet_h_cm||100,cost_per_sheet:paper.cost_per_sheet||0,finishing:finishing,
        machine:METHOD_DEFAULTS[method]||METHOD_DEFAULTS.offset,
        pages:parseInt(node.querySelector('[data-pages]').value,10)||1,category:cat,price_mode:'engine',
        cover_paper:coverPaper?{cost_per_sheet:coverPaper.cost_per_sheet}:null};
}
function pqEstimateTier(node,row){
    var q=parseInt(row.querySelector('[data-tqty]').value,10)||0;
    if(row._mode==='manual'||node._manualMode){pqTierManual(row);row._effectiveMarkup=undefined;return;}
    if(csHasSheet(node)){
        if(!csItemReady(node)){ row.querySelector('[data-tunit]').value='0.00'; row.querySelector('[data-ttotal]').value='0.00'; row._cost=0; row._effectiveMarkup=undefined; return; }
        // price from the job cost sheet (the real worksheet)
        var r=CostSheet.compute(node._cs,q,CSCFG);
        row.querySelector('[data-tunit]').value=r.unit_price.toFixed(2);
        row.querySelector('[data-ttotal]').value=r.sell.toFixed(2);
        row._cost=r.cost_total; row._warn=(r.warnings||[]).join(' ');
        row._effectiveMarkup=r.markup_pct;
        return;
    }
    // legacy estimate (no cost sheet)
    var r2=PQEngine.calculate(pqBuildSpec(node,q),PQ.config);
    row.querySelector('[data-tunit]').value=r2.unit_price.toFixed(2);
    row.querySelector('[data-ttotal]').value=r2.selling_total.toFixed(2);
    row._cost=r2.total_cost; row._warn=(r2.warnings||[]).join(' ');
    row._effectiveMarkup=r2.effective_markup;
    var warn=node.querySelector('[data-warn]'); warn.textContent=r2.warnings&&r2.warnings.length?r2.warnings.join(' '):'';
}

function pqSyncAutoLines(node){
    var w=parseFloat(node.querySelector('[data-w]').value)||0,h=parseFloat(node.querySelector('[data-h]').value)||0;
    var d=parseFloat(node.querySelector('[data-depth]').value)||0;
    var pages=parseInt(node.querySelector('[data-pages]').value,10)||1;
    var paper=PAPER[node.querySelector('[data-paper]').value];
    var cf=node.querySelector('[data-cf]').value,cb=node.querySelector('[data-cb]').value;
    var finNames=[];node._finIds.forEach(function(fid){var f=FIN[fid];if(f)finNames.push(f.name);});
    var sizeStr=(w&&h)?(d?(w+' x '+h+' x '+d+' cm'):(w+' x '+h+' cm')):'';
    if(pages>1&&sizeStr) sizeStr+=' · '+pages+' pages';
    var vals={size:sizeStr,paper:paper?paper.name:'',printing:cf+'/'+cb,finishing:finNames.join(' + ')};
    node.querySelectorAll('[data-speclines] input[data-key]').forEach(function(inp){
        if(inp.getAttribute('data-auto')==='1'){var k=inp.getAttribute('data-key');if(vals[k]!==undefined)inp.value=vals[k];}
    });
}
function pqOnStruct(elm){
    var node=elm.closest('[data-row]');
    var pages=parseInt(node.querySelector('[data-pages]').value,10)||1;
    var cw=node.querySelector('[data-coverwrap]');
    cw.style.display=pages>1?'':'none';
    if(pages<=1) node.querySelector('[data-coverpaper]').value='';
    if(!node._manualMode){
        pqSyncAutoLines(node);
        csSeed(node,false); // rebuilds sheet only while untouched; otherwise keeps user's worksheet
        node.querySelectorAll('[data-tiers] tr').forEach(function(row){if(row._mode!=='manual')pqEstimateTier(node,row);});
        csRefresh(node);
    }
    pqRecalc();
}

function pqApplyPreset(node,id){
    var p=PROD[id];if(!p)return;
    node.querySelector('[data-title]').value=p.name;
    node.querySelector('[data-method]').value=p.default_method;
    if(p.default_paper_id)node.querySelector('[data-paper]').value=p.default_paper_id;
    // Use flat dimensions if set (bags), otherwise use size preset
    if(p.default_flat_w_cm&&p.default_flat_h_cm){
        node.querySelector('[data-sizepreset]').value='';
        node.querySelector('[data-w]').value=+p.default_flat_w_cm;
        node.querySelector('[data-h]').value=+p.default_flat_h_cm;
    } else if(p.default_size_id&&SIZE[p.default_size_id]){
        node.querySelector('[data-sizepreset]').value=p.default_size_id;
        node.querySelector('[data-w]').value=+SIZE[p.default_size_id].width_cm;
        node.querySelector('[data-h]').value=+SIZE[p.default_size_id].height_cm;
    }
    node.querySelector('[data-depth]').value=p.default_depth_cm?+p.default_depth_cm:'';
    node.querySelector('[data-pages]').value=p.default_pages?+p.default_pages:1;
    node.querySelector('[data-sides]').value=p.default_sides;node.querySelector('[data-cf]').value=p.default_colors_front;node.querySelector('[data-cb]').value=p.default_colors_back;
    var fin=[];try{fin=JSON.parse(p.finishing_ids||'[]');}catch(e){}
    node._finIds=fin.map(Number).filter(function(x){return x>0;});
    finRenderTags(node);
    // Auto-suggest binding for books if no binding finishing already selected
    var pg=parseInt(node.querySelector('[data-pages]').value,10)||1;
    if(pg>1){
        var hasBinding=false;
        node._finIds.forEach(function(fid){var f=FIN[fid];if(f&&/binding|stitch/i.test(f.name))hasBinding=true;});
        if(!hasBinding){
            var bindName=pg<=64?'Saddle Stitching':'Perfect Binding';
            PQ.finishing.forEach(function(f){if(f.name===bindName)finAddTag(node,f.id);});
        }
    }
    // reset auto lines
    node.querySelectorAll('[data-speclines] input[data-key]').forEach(function(i){i.setAttribute('data-auto','1');});
    pqSyncAutoLines(node);
    // trigger cover paper visibility
    var cwWrap=node.querySelector('[data-coverwrap]');
    cwWrap.style.display=(parseInt(node.querySelector('[data-pages]').value,10)||1)>1?'':'none';
    if(parseInt(node.querySelector('[data-pages]').value,10)<=1) node.querySelector('[data-coverpaper]').value='';
    // set first tier to preset default qty
    var firstTier=node.querySelector('[data-tiers] tr');
    if(firstTier){firstTier.querySelector('[data-tlabel]').value=(+p.default_qty).toLocaleString();firstTier.querySelector('[data-tqty]').value=p.default_qty;}
    // rebuild the cost sheet for the chosen preset (components + operations)
    csSeed(node,true);
    pqRecalc();
}

function pqToggleMath(){
    var w=document.getElementById('pqSummaryMath'), b=document.getElementById('pqMathToggle');
    var show=w.style.display==='none'; w.style.display=show?'block':'none'; b.textContent=show?'Hide the maths':'Show the maths';
}
function pqRecalc(){
    var rows=document.querySelectorAll('#pqItems [data-row]');var subtotal=0,cost=0,count=0;var sb='';var mathHtml='';
    rows.forEach(function(node){
        var title=node.querySelector('[data-title]').value||'Item';
        var priRow=node.querySelector('[data-tiers] tr');
        if(priRow){var t=parseFloat(priRow.querySelector('[data-ttotal]').value)||0;subtotal+=t;cost+=(priRow._cost||0);count++;
            var markup=priRow._effectiveMarkup;
            var markupStr=markup!==undefined?'<small style="color:#94a3b8;"> · '+markup+'% eff.</small>':'';
            sb+='<div class="row"><span>'+title+markupStr+'</span><span>'+money(t)+'</span></div>';
            // per-item worksheet math for the primary tier
            var manual=priRow._mode==='manual';
            if(!manual && csHasSheet(node) && csItemReady(node)){
                var q=parseInt(priRow.querySelector('[data-tqty]').value,10)||0;
                if(q>0){
                    var r=CostSheet.compute(node._cs,q,CSCFG);
                    mathHtml+='<div class="pqm-item"><div class="pqm-h">'+title+' — '+q.toLocaleString()+' pcs</div>';
                    r.lines.forEach(function(l){ mathHtml+='<div class="pqm-ln"><span>'+l.label+' <span class="mm">'+l.math+'</span></span><span>'+money(l.amount)+'</span></div>'; });
                    mathHtml+='<div class="pqm-ln pqm-tot"><span>Cost</span><span>'+money(r.cost_total)+'</span></div>';
                    mathHtml+='<div class="pqm-ln pqm-tot"><span>Sell (markup '+r.markup_pct+'%)</span><span>'+money(r.sell)+'</span></div></div>';
                }
            } else if(manual){
                mathHtml+='<div class="pqm-item"><div class="pqm-h">'+title+'</div><div class="pqm-ln"><span class="mm">priced manually</span><span>'+money(t)+'</span></div></div>';
            }
        }
    });
    document.getElementById('pqGrand').textContent=money(subtotal);
    document.getElementById('pqItemsCount').textContent='· '+count+(count===1?' item':' items');
    document.getElementById('pqSummaryBreak').innerHTML=sb;
    document.getElementById('pqSummaryMath').innerHTML=mathHtml||'<div style="color:#94a3b8;font-size:12px;">Enter specs to see the calculation.</div>';
    var margin=subtotal-cost,mpct=subtotal>0?(margin/subtotal*100):0;
    var mEl=document.getElementById('pqMargin');
    if(cost>0){
        var floor=parseFloat(PQ.config.min_margin_pct)||15;
        mEl.innerHTML='Est. cost '+money(cost)+' · margin '+money(margin)+' <strong>('+mpct.toFixed(1)+'%)</strong>'+(mpct<floor?' (below target)':'');
        mEl.style.color=mpct<floor?'#fca5a5':(mpct<floor+10?'#fcd34d':'#86efac');
    } else { mEl.textContent=''; mEl.style.color=''; }
}

function pqSerializeItem(node){
    var lines=[];node.querySelectorAll('[data-speclines] > div').forEach(function(r){var i=r.querySelectorAll('input');lines.push({label:i[0].value,value:i[1].value});});
    var opts=[];node.querySelectorAll('[data-options] > div').forEach(function(r){opts.push({label:r.querySelector('[data-olabel]').value,price:parseFloat(r.querySelector('[data-oprice]').value)||0});});
    var tiers=[];node.querySelectorAll('[data-tiers] tr').forEach(function(r,i){tiers.push({label:r.querySelector('[data-tlabel]').value,quantity:parseInt(r.querySelector('[data-tqty]').value,10)||0,unit_price:parseFloat(r.querySelector('[data-tunit]').value)||0,price_mode:r._mode||'engine',is_primary:i===0?1:0});});
    var finIds=finGetSelected(node);
    var presetId=node.querySelector('[data-preset]').value;
    var cat=presetId&&PROD[presetId]?PROD[presetId].category:'';
    return {title:node.querySelector('[data-title]').value,size_w:parseFloat(node.querySelector('[data-w]').value)||0,size_h:parseFloat(node.querySelector('[data-h]').value)||0,
        depth:parseFloat(node.querySelector('[data-depth]').value)||0,
        pages:parseInt(node.querySelector('[data-pages]').value,10)||1,category:cat,
        paper_id:node.querySelector('[data-paper]').value||null,cover_paper_id:node.querySelector('[data-coverpaper]').value||null,
        method:node.querySelector('[data-method]').value,
        sides:parseInt(node.querySelector('[data-sides]').value,10)||1,cf:parseInt(node.querySelector('[data-cf]').value,10)||0,cb:parseInt(node.querySelector('[data-cb]').value,10)||0,
        finishing_ids:finIds,spec_lines:lines,options:opts,tiers:tiers,
        cost_sheet:csHasSheet(node)?node._cs:null};
}

// Enter should advance to the next field, never submit the quote.
function pqFocusNext(cur){
    var form=document.getElementById('quoteForm');
    var f=Array.prototype.slice.call(form.querySelectorAll('input,select,textarea')).filter(function(el){
        if(el.disabled || el.type==='hidden') return false;
        if(el.offsetParent===null) return false; // hidden (e.g. native <select> behind a combobox)
        return true;
    });
    var i=f.indexOf(cur);
    if(i>-1 && i<f.length-1){ var nx=f[i+1]; nx.focus(); if(nx.select){ try{ nx.select(); }catch(e){} } }
}
document.getElementById('quoteForm').addEventListener('keydown',function(e){
    if(e.key!=='Enter') return;
    var t=e.target;
    if(t.tagName==='TEXTAREA' || t.tagName==='BUTTON') return;      // newline in textareas; let buttons act
    if(t.tagName==='INPUT' && t.type==='submit') return;            // explicit submit button
    if(t.classList && t.classList.contains('combo-input')) return; // combobox handles its own Enter
    e.preventDefault();
    pqFocusNext(t);
});

document.getElementById('quoteForm').addEventListener('submit',function(e){
    document.querySelectorAll('.pq-gen').forEach(function(n){n.remove();});
    var items=document.querySelectorAll('#pqItems [data-row]');
    if(items.length===0){e.preventDefault();alert('Add at least one item.');return;}
    items.forEach(function(node){var inp=document.createElement('input');inp.type='hidden';inp.name='item_payload[]';inp.className='pq-gen';inp.value=JSON.stringify(pqSerializeItem(node));quoteForm.appendChild(inp);});
});

enhanceSelect(document.querySelector('#quoteForm [name=customer_id]'),'Search customers…');
pqAddItem();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
