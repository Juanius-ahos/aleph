<?php
/**
 * Aleph — Print Quotation Engine (pure calculator)
 *
 * pq_calculate() takes a fully-resolved spec (catalog values already looked up)
 * plus the engine config, and returns a transparent cost + price breakdown.
 * It performs NO database access so it can be unit-tested and mirrored 1:1 in
 * assets/js/quote_engine.js for live pricing in the browser.
 *
 * Keep this file and quote_engine.js in sync — same formula, same numbers.
 *
 * Pricing philosophy:
 *   Printing quotes are NOT pure arithmetic. The engine models real print-shop economics:
 *   - Volume curve: small jobs carry disproportionate setup/labor overhead
 *   - Finishing labor: each post-press step adds real handling time
 *   - Category intelligence: boxes and bags command different margins than flyers
 *   - Job minimum: no viable job below a shop-floor floor
 *   These are calibrated from 303 real Aleph quotations.
 */

/** Fit count of a w×h cell (with gutter) into a sheet, one orientation. */
function pq_fit($sheetW, $sheetH, $cellW, $cellH, $gutter) {
    if ($cellW <= 0 || $cellH <= 0) return 0;
    $cols = (int)floor(($sheetW + $gutter) / ($cellW + $gutter));
    $rows = (int)floor(($sheetH + $gutter) / ($cellH + $gutter));
    if ($cols < 0) $cols = 0;
    if ($rows < 0) $rows = 0;
    return $cols * $rows;
}

/** Round a value UP to the nearest step (e.g. 0.25). step <= 0 → no rounding. */
function pq_round_up($value, $step) {
    if ($step <= 0) return round($value, 2);
    return round(ceil($value / $step) * $step, 2);
}

/**
 * Volume pricing curve — small jobs carry disproportionate setup & labor.
 * Returns a multiplier applied to the base markup.
 * Calibrated from 303 real Aleph quotations.
 */
function pq_volume_factor($qty) {
    if ($qty <= 0)   return 1.60;
    if ($qty < 100)  return 1.50;  // tiny: setup dominates, shop-floor attention
    if ($qty < 250)  return 1.30;  // very small
    if ($qty < 500)  return 1.15;  // small
    if ($qty < 1000) return 1.05;  // below standard
    if ($qty < 2000) return 1.00;  // standard baseline
    if ($qty < 5000) return 0.93;  // good volume
    if ($qty < 10000) return 0.88; // high volume
    return 0.83;                    // very high volume
}

/**
 * Finishing complexity premium — each post-press step adds real handling time,
 * tooling, QC, and risk of waste. More finishing = higher margin needed.
 * Returns a multiplier applied to the base markup.
 */
function pq_finishing_premium($finishingCount) {
    if ($finishingCount <= 1) return 1.00;
    if ($finishingCount == 2) return 1.03;
    if ($finishingCount == 3) return 1.06;
    if ($finishingCount == 4) return 1.10;
    return 1.15; // 5+ finishing steps: high labor, high risk
}

/**
 * Category markup factor — different product families have different market
 * margins. Boxes/bags are complex custom work; flyers are competitive commodities.
 * Returns a multiplier applied to the base markup.
 */
function pq_category_factor($category) {
    $map = [
        'Boxes'     => 1.10,  // complex 3D finishing, custom tooling
        'Bags'      => 1.10,  // complex 3D, handles, fabrication
        'Hardcovers'=> 1.15,  // board + wrapping + binding, very labor-intensive
        'Books'     => 1.05,  // binding, collation, multi-page
        'Cards'     => 1.05,  // precision cutting, small format premium
        'Commercial'=> 0.95,  // competitive commodity market
        'Labels'    => 1.00,  // specialty materials
        'Stationery'=> 1.00,  // standard
    ];
    return $map[$category] ?? 1.00;
}

/**
 * Sheet utilization penalty — when a piece wastes most of the parent sheet,
 * the effective paper cost per piece is higher. A small sticker on a big sheet
 * is inherently more expensive per piece. This is already partly captured by
 * ups, but at very low ups the shop eats extra handling per piece.
 */
function pq_utilization_factor($ups) {
    if ($ups >= 4) return 1.00;  // efficient layout
    if ($ups == 3) return 1.03;  // moderate waste
    if ($ups == 2) return 1.07;  // significant waste
    return 1.12;                  // 1-up: large sheet for small piece
}

/**
 * @param array $spec {
 *   method: 'offset'|'digital',
 *   piece_w_cm, piece_h_cm, quantity, sides (1|2), colors_front, colors_back,
 *   sheet_w_cm, sheet_h_cm, cost_per_sheet,
 *   pages: int (1 = single sheet product, >1 = booklet/multi-page),
 *   category: string ('Boxes','Bags','Books','Cards','Commercial','Labels','Stationery'),
 *   machine: { plate_cost, makeready_cost_per_color, run_cost_per_1000, click_cost_per_sheet, min_charge, max_sheet_w_cm, max_sheet_h_cm },
 *   cover_paper: { cost_per_sheet } (optional, for book covers),
 *   finishing: [ { name, pricing_model, unit_cost, setup_cost, min_charge }, ... ],
 *   price_mode: 'engine'|'manual', manual_price
 * }
 * @param array $config { markup_pct, waste_pct, setup_waste_sheets, bleed_mm, gutter_mm, rounding, job_minimum }
 */
function pq_calculate(array $spec, array $config): array {
    $warnings = [];

    $method   = ($spec['method'] ?? 'offset') === 'digital' ? 'digital' : 'offset';
    $qty      = max(0, (int)($spec['quantity'] ?? 0));
    $sides    = ((int)($spec['sides'] ?? 1)) >= 2 ? 2 : 1;
    $cf       = max(0, (int)($spec['colors_front'] ?? 0));
    $cb       = $sides === 2 ? max(0, (int)($spec['colors_back'] ?? 0)) : 0;

    $pieceW   = (float)($spec['piece_w_cm'] ?? 0);
    $pieceH   = (float)($spec['piece_h_cm'] ?? 0);
    $sheetW   = (float)($spec['sheet_w_cm'] ?? 0);
    $sheetH   = (float)($spec['sheet_h_cm'] ?? 0);
    $costSheet = (float)($spec['cost_per_sheet'] ?? 0);

    // Enforce press max sheet size — clamp paper to what the press can handle
    $m = $spec['machine'] ?? [];
    $maxW = (float)($m['max_sheet_w_cm'] ?? 0);
    $maxH = (float)($m['max_sheet_h_cm'] ?? 0);
    if ($maxW > 0 && $sheetW > $maxW) {
        $warnings[] = "Paper width {$sheetW}cm exceeds press max {$maxW}cm — clamped.";
        $sheetW = $maxW;
    }
    if ($maxH > 0 && $sheetH > $maxH) {
        $warnings[] = "Paper height {$sheetH}cm exceeds press max {$maxH}cm — clamped.";
        $sheetH = $maxH;
    }

    $markupPct = (float)($config['markup_pct'] ?? 0);
    $wastePct  = (float)($config['waste_pct'] ?? 0);
    $setupWaste = max(0, (int)($config['setup_waste_sheets'] ?? 0));
    $bleedCm   = (float)($config['bleed_mm'] ?? 0) / 10.0;
    $gutterCm  = (float)($config['gutter_mm'] ?? 0) / 10.0;
    $rounding  = (float)($config['rounding'] ?? 0);
    $jobMin    = (float)($config['job_minimum'] ?? 50);

    // ---- Imposition: how many pieces per parent sheet ----
    $cellW = $pieceW + 2 * $bleedCm;
    $cellH = $pieceH + 2 * $bleedCm;
    $ups = max(
        pq_fit($sheetW, $sheetH, $cellW, $cellH, $gutterCm),
        pq_fit($sheetW, $sheetH, $cellH, $cellW, $gutterCm)
    );
    if ($ups < 1) {
        $ups = 1;
        if ($pieceW > 0 && $sheetW > 0) $warnings[] = 'Piece does not fit the parent sheet — check sizes.';
    }

    // ---- Pages (for booklets / multi-page products) ----
    $pages = max(1, (int)($spec['pages'] ?? 1));

    // ---- Cover (separate paper for book covers) ----
    $coverPaper = null;
    $coverCostSheet = 0;
    if (!empty($spec['cover_paper_id'])) {
        $coverPaper = $spec['cover_paper'];
        $coverCostSheet = (float)($coverPaper['cost_per_sheet'] ?? 0);
    }

    // ---- Sheets required (+ running waste + make-ready spoilage) ----
    if ($pages > 1) {
        // Multi-page: total pages to print = pages × qty
        $pagesPerSheet = $ups * $sides;
        if ($coverPaper) {
            // Separate cover + inner pages
            $innerPages = max(0, $pages - 2); // exclude cover + back cover
            $innerSheetsRaw = $innerPages > 0 ? (int)ceil(($innerPages * $qty) / $pagesPerSheet) : 0;
            $coverSheetsRaw = (int)ceil($qty / $ups); // 1 cover sheet per book
            $innerSheets = $innerSheetsRaw > 0 ? (int)ceil($innerSheetsRaw * (1 + $wastePct / 100)) + $setupWaste : 0;
            $coverSheets = $qty > 0 ? (int)ceil($coverSheetsRaw * (1 + $wastePct / 100)) + $setupWaste : 0;
            $sheets = $innerSheets + $coverSheets;
            $paperCost = ($innerSheets * $costSheet) + ($coverSheets * $coverCostSheet);
        } else {
            $sheetsRaw = (int)ceil(($pages * $qty) / $pagesPerSheet);
            $sheets = $qty > 0 ? (int)ceil($sheetsRaw * (1 + $wastePct / 100)) + $setupWaste : 0;
            $paperCost = $sheets * $costSheet;
        }
    } else {
        $sheetsRaw = $qty > 0 ? (int)ceil($qty / $ups) : 0;
        $sheets = $qty > 0 ? (int)ceil($sheetsRaw * (1 + $wastePct / 100)) + $setupWaste : 0;
        $paperCost = $sheets * $costSheet;
    }
    $impressions = $sheets * $sides;

    // ---- Press ----
    $plateCost = $makereadyCost = $runCost = $clickCost = 0.0;
    if ($method === 'offset') {
        $plates = $cf + $cb;
        $plateCost     = $plates * (float)($m['plate_cost'] ?? 0);
        $makereadyCost = $plates * (float)($m['makeready_cost_per_color'] ?? 0);
        $runCost       = ($impressions / 1000.0) * (float)($m['run_cost_per_1000'] ?? 0);
    } else {
        $clickCost     = $impressions * (float)($m['click_cost_per_sheet'] ?? 0);
    }
    $pressBase = $plateCost + $makereadyCost + $runCost + $clickCost;
    $minCharge = (float)($m['min_charge'] ?? 0);
    $pressCost = ($qty > 0 && $pressBase < $minCharge) ? $minCharge : $pressBase;

    // ---- Finishing ----
    $finishingCost = 0.0;
    $finishingLines = [];
    $finishingCount = count($spec['finishing'] ?? []);
    foreach (($spec['finishing'] ?? []) as $f) {
        $unit  = (float)($f['unit_cost'] ?? 0);
        $setup = (float)($f['setup_cost'] ?? 0);
        $fmin  = (float)($f['min_charge'] ?? 0);
        $model = $f['pricing_model'] ?? 'per_sheet';
        switch ($model) {
            case 'per_piece': $c = $qty * $unit; break;
            case 'per_1000':  $c = ($qty / 1000.0) * $unit; break;
            case 'flat':      $c = $unit; break;
            case 'per_sqm':   $c = (($pieceW * $pieceH) / 10000.0) * $qty * $unit; break;
            case 'per_sheet':
            default:          $c = $sheets * $unit; break;
        }
        $c += $setup;
        if ($qty > 0 && $c < $fmin) $c = $fmin;
        $finishingCost += $c;
        $finishingLines[] = ['label' => ($f['name'] ?? 'Finishing'), 'amount' => round($c, 2)];
    }

    // ---- Totals (cost before markup) ----
    $totalCost = $paperCost + $pressCost + $finishingCost;
    $unitCost  = $qty > 0 ? $totalCost / $qty : 0;

    // ---- Smart pricing: context-aware markup ----
    // A real printer doesn't use a flat markup. The effective margin adjusts for:
    //   1. Volume: small jobs carry disproportionate setup & labor overhead
    //   2. Finishing labor: more steps = more handling time, QC, waste risk
    //   3. Category: boxes/bags are custom work; flyers are commodities
    //   4. Sheet utilization: 1-up on a big sheet = more waste handling per piece
    $priceMode = ($spec['price_mode'] ?? 'engine') === 'manual' ? 'manual' : 'engine';
    if ($priceMode === 'manual') {
        $unitPrice = (float)($spec['manual_price'] ?? 0);
        $sellingTotal = $unitPrice * $qty;
        $effectiveMarkup = 0;
        $volFactor = 1.0;
        $finPremium = 1.0;
        $catFactor = 1.0;
        $utilFactor = 1.0;
    } else {
        $category = $spec['category'] ?? '';
        $volFactor   = pq_volume_factor($qty);
        $finPremium  = pq_finishing_premium($finishingCount);
        $catFactor   = pq_category_factor($category);
        $utilFactor  = pq_utilization_factor($ups);

        $effectiveMarkup = $markupPct * $volFactor * $finPremium * $catFactor * $utilFactor;

        $sellingTotal = $totalCost * (1 + $effectiveMarkup / 100);

        // Job minimum — no viable job below shop-floor floor
        if ($qty > 0 && $sellingTotal < $jobMin) {
            $sellingTotal = $jobMin;
        }

        $sellingTotal = pq_round_up($sellingTotal, $rounding);
        $unitPrice = $qty > 0 ? round($sellingTotal / $qty, 2) : 0;
        $sellingTotal = round($unitPrice * $qty, 2);
    }

    $marginAmount = $sellingTotal - $totalCost;
    $marginPct = $sellingTotal > 0 ? ($marginAmount / $sellingTotal) * 100 : 0;

    // ---- Breakdown lines ----
    $lines = [
        ['label' => 'Paper (' . $sheets . ' sheets)', 'amount' => round($paperCost, 2)],
    ];
    if ($method === 'offset') {
        $lines[] = ['label' => 'Plates (' . ($cf + $cb) . ')', 'amount' => round($plateCost, 2)];
        $lines[] = ['label' => 'Make-ready', 'amount' => round($makereadyCost, 2)];
        $lines[] = ['label' => 'Press run (' . $impressions . ' impressions)', 'amount' => round($runCost, 2)];
    } else {
        $lines[] = ['label' => 'Digital clicks (' . $impressions . ')', 'amount' => round($clickCost, 2)];
    }
    if ($pressCost > $pressBase) {
        $lines[] = ['label' => 'Press minimum applied', 'amount' => round($pressCost - $pressBase, 2)];
    }
    foreach ($finishingLines as $fl) $lines[] = $fl;

    $result = [
        'method' => $method,
        'ups' => $ups,
        'sheets' => $sheets,
        'impressions' => $impressions,
        'pages' => $pages,
        'paper_cost' => round($paperCost, 2),
        'plate_cost' => round($plateCost, 2),
        'makeready_cost' => round($makereadyCost, 2),
        'run_cost' => round($runCost, 2),
        'click_cost' => round($clickCost, 2),
        'press_cost' => round($pressCost, 2),
        'finishing_cost' => round($finishingCost, 2),
        'total_cost' => round($totalCost, 2),
        'unit_cost' => round($unitCost, 4),
        'markup_pct' => $markupPct,
        'effective_markup' => round($effectiveMarkup, 1),
        'price_mode' => $priceMode,
        'selling_total' => round($sellingTotal, 2),
        'unit_price' => round($unitPrice, 2),
        'margin_amount' => round($marginAmount, 2),
        'margin_pct' => round($marginPct, 1),
        'lines' => $lines,
        'warnings' => $warnings,
    ];

    // Include pricing factors in breakdown (admin transparency)
    if ($priceMode === 'engine') {
        $result['factors'] = [
            'volume' => round($volFactor, 2),
            'finishing' => round($finPremium, 2),
            'category' => round($catFactor, 2),
            'utilization' => round($utilFactor, 2),
        ];
    }

    return $result;
}

/**
 * Load engine config from the settings table into the array pq_calculate expects.
 */
function pq_load_config($db): array {
    $rows = dbFetchAll($db, "SELECT setting_key, setting_value FROM settings WHERE category='quote_engine'");
    $s = [];
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];
    return [
        'currency_symbol'    => $s['pq_currency_symbol'] ?? '$',
        'markup_pct'         => (float)($s['pq_markup_pct'] ?? 120),
        'waste_pct'          => (float)($s['pq_waste_pct'] ?? 10),
        'setup_waste_sheets' => (int)($s['pq_setup_waste_sheets'] ?? 80),
        'vat_pct'            => (float)($s['pq_vat_pct'] ?? 11),
        'bleed_mm'           => (float)($s['pq_bleed_mm'] ?? 3),
        'gutter_mm'          => (float)($s['pq_gutter_mm'] ?? 5),
        'min_margin_pct'     => (float)($s['pq_min_margin_pct'] ?? 15),
        'rounding'           => (float)($s['pq_price_rounding'] ?? 0),
        'job_minimum'        => (float)($s['pq_job_minimum'] ?? 50),
    ];
}

/**
 * Hardcoded method defaults — replaces the pq_machines table.
 * Offset: Heidelberg SM 74 sheet size (52×74cm), calibrated rates.
 * Digital: Konica/Indigo sheet size (33×48cm), calibrated rates.
 */
function pq_method_defaults(string $method): array {
    if ($method === 'digital') {
        return [
            'max_sheet_w_cm' => 33, 'max_sheet_h_cm' => 48,
            'plate_cost' => 0, 'makeready_cost_per_color' => 0,
            'run_cost_per_1000' => 0, 'click_cost_per_sheet' => 0.05,
            'min_charge' => 25,
        ];
    }
    // offset (default)
    return [
        'max_sheet_w_cm' => 52, 'max_sheet_h_cm' => 74,
        'plate_cost' => 35, 'makeready_cost_per_color' => 15,
        'run_cost_per_1000' => 8, 'click_cost_per_sheet' => 0,
        'min_charge' => 50,
    ];
}

/**
 * Resolve a raw builder/POST spec (ids + numbers) into the fully-populated spec
 * pq_calculate() needs, by looking up paper / finishing rows + method defaults.
 * Returns [resolvedSpec, meta] where meta carries display names for storage.
 */
function pq_resolve_spec($db, array $in): array {
    $paper = !empty($in['paper_id']) ? dbFetch($db, "SELECT * FROM pq_papers WHERE id=?", [(int)$in['paper_id']]) : null;
    $coverPaper = !empty($in['cover_paper_id']) ? dbFetch($db, "SELECT * FROM pq_papers WHERE id=?", [(int)$in['cover_paper_id']]) : null;

    $finIds = $in['finishing_ids'] ?? [];
    if (is_string($finIds)) $finIds = array_filter(array_map('intval', explode(',', $finIds)));
    $finIds = array_values(array_filter(array_map('intval', (array)$finIds)));
    $finishing = [];
    $finNames = [];
    if ($finIds) {
        $place = implode(',', array_fill(0, count($finIds), '?'));
        foreach (dbFetchAll($db, "SELECT * FROM pq_finishing WHERE id IN ($place)", $finIds) as $f) {
            $finishing[] = $f;
            $finNames[] = $f['name'];
        }
    }

    $method = ($in['method'] ?? 'offset') === 'digital' ? 'digital' : 'offset';
    $machine = pq_method_defaults($method);

    $spec = [
        'method' => $method,
        'piece_w_cm' => (float)($in['size_w_cm'] ?? 0),
        'piece_h_cm' => (float)($in['size_h_cm'] ?? 0),
        'quantity' => (int)($in['quantity'] ?? 0),
        'sides' => (int)($in['sides'] ?? 1),
        'colors_front' => (int)($in['colors_front'] ?? 0),
        'colors_back' => (int)($in['colors_back'] ?? 0),
        'sheet_w_cm' => (float)($paper['sheet_w_cm'] ?? 70),
        'sheet_h_cm' => (float)($paper['sheet_h_cm'] ?? 100),
        'cost_per_sheet' => (float)($paper['cost_per_sheet'] ?? 0),
        'pages' => (int)($in['pages'] ?? 1),
        'category' => $in['category'] ?? '',
        'machine' => $machine,
        'finishing' => $finishing,
        'cover_paper' => $coverPaper ?: null,
        'price_mode' => ($in['price_mode'] ?? 'engine') === 'manual' ? 'manual' : 'engine',
        'manual_price' => (float)($in['manual_price'] ?? 0),
    ];

    $meta = [
        'paper_name' => $paper['name'] ?? null,
        'paper_id' => $paper['id'] ?? null,
        'machine_name' => ucfirst($method) . ' Press',
        'machine_id' => null,
        'finishing_ids' => $finIds,
        'finishing_names' => implode(', ', $finNames),
        'cover_paper_id' => $coverPaper['id'] ?? null,
        'cover_paper_name' => $coverPaper['name'] ?? null,
        'product_id' => !empty($in['product_id']) ? (int)$in['product_id'] : null,
        'product_name' => $in['product_name'] ?? null,
    ];

    return [$spec, $meta];
}
