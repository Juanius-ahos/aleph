<?php
/**
 * Aleph — Job Cost Sheet calculator (server authority; mirrored in assets/js/cost_sheet.js)
 * MUST stay numerically identical to the JS compute().
 *
 * A cost sheet is the digital version of the paper costing worksheet:
 *   components[]  — every paper part of the product (bag body, tongue, insert, cover…)
 *   operations[]  — everything that is not paper (plates, make-ready, press run,
 *                   die making, die cutting, lamination, gluing, labour, transport…)
 * Every derived number can be overridden; overrides always win.
 * Fixed costs (dies, plates, make-ready, setup waste) do not scale with quantity.
 */

function cs_num($v, $d = 0) { return is_numeric($v) ? (float)$v : $d; }
function cs_int($v, $d = 0) { return is_numeric($v) ? (int)$v : $d; }
function cs_isov($v) { return $v !== null && $v !== '' && is_numeric($v); }
function cs_fmt($v) { return number_format(round($v * 100) / 100, (fmod(round($v * 100) / 100, 1) == 0.0 ? 0 : 2)); }

/** How many pieces fit a parent sheet (tries both orientations). */
function cs_fit_ups($sheetW, $sheetH, $pieceW, $pieceH, $gutter) {
    if ($pieceW <= 0 || $pieceH <= 0 || $sheetW <= 0 || $sheetH <= 0) return 0;
    $fit = function ($w, $h) use ($sheetW, $sheetH, $gutter) {
        $c = (int)floor(($sheetW + $gutter) / ($w + $gutter));
        $r = (int)floor(($sheetH + $gutter) / ($h + $gutter));
        return max(0, $c) * max(0, $r);
    };
    return max($fit($pieceW, $pieceH), $fit($pieceH, $pieceW));
}

/** Cost of one parent sheet. per_ton: sheet weight from gsm and area. */
function cs_sheet_cost(array $c) {
    if (($c['price_mode'] ?? 'per_sheet') === 'per_ton') {
        return cs_num($c['sheet_w_cm'] ?? 0) * cs_num($c['sheet_h_cm'] ?? 0) * cs_num($c['gsm'] ?? 0) * cs_num($c['price_per_ton'] ?? 0) / 1e10;
    }
    return cs_num($c['cost_per_sheet'] ?? 0);
}
function cs_sheet_kg(array $c) {
    return cs_num($c['sheet_w_cm'] ?? 0) * cs_num($c['sheet_h_cm'] ?? 0) * cs_num($c['gsm'] ?? 0) / 1e7;
}

/**
 * Sanitize an untrusted cost-sheet structure from the browser.
 * Whitelists keys, coerces numerics, caps list sizes. Returns null when empty/invalid.
 */
function cs_sanitize($raw): ?array {
    if (!is_array($raw)) return null;
    $out = ['components' => [], 'operations' => [], 'markup_pct' => cs_num($raw['markup_pct'] ?? 0), 'notes' => mb_substr(trim((string)($raw['notes'] ?? '')), 0, 500)];
    $numOrNull = fn($v) => cs_isov($v) ? (float)$v : null;
    foreach (array_slice(is_array($raw['components'] ?? null) ? $raw['components'] : [], 0, 20) as $c) {
        if (!is_array($c)) continue;
        $ov = is_array($c['overrides'] ?? null) ? $c['overrides'] : [];
        $out['components'][] = [
            'label' => mb_substr(trim((string)($c['label'] ?? '')), 0, 120),
            'paper_id' => cs_isov($c['paper_id'] ?? null) ? (int)$c['paper_id'] : null,
            'paper_name' => mb_substr(trim((string)($c['paper_name'] ?? '')), 0, 200),
            'sheet_w_cm' => cs_num($c['sheet_w_cm'] ?? 0), 'sheet_h_cm' => cs_num($c['sheet_h_cm'] ?? 0),
            'price_mode' => ($c['price_mode'] ?? 'per_sheet') === 'per_ton' ? 'per_ton' : 'per_sheet',
            'price_per_ton' => cs_num($c['price_per_ton'] ?? 0), 'gsm' => cs_num($c['gsm'] ?? 0),
            'cost_per_sheet' => cs_num($c['cost_per_sheet'] ?? 0),
            'piece_w_cm' => cs_num($c['piece_w_cm'] ?? 0), 'piece_h_cm' => cs_num($c['piece_h_cm'] ?? 0),
            'ups' => cs_isov($c['ups'] ?? null) ? (int)$c['ups'] : null,
            'waste_pct' => cs_num($c['waste_pct'] ?? 0), 'setup_sheets' => cs_int($c['setup_sheets'] ?? 0),
            'pieces_per_product' => cs_num($c['pieces_per_product'] ?? 1, 1),
            'overrides' => ['sheets_total' => $numOrNull($ov['sheets_total'] ?? null), 'paper_cost' => $numOrNull($ov['paper_cost'] ?? null)],
        ];
    }
    $bases = ['fixed', 'per_sheet', 'per_1000_sheets', 'per_piece', 'per_1000_pieces', 'per_sqm', 'per_plate'];
    foreach (array_slice(is_array($raw['operations'] ?? null) ? $raw['operations'] : [], 0, 40) as $o) {
        if (!is_array($o)) continue;
        $out['operations'][] = [
            'label' => mb_substr(trim((string)($o['label'] ?? '')), 0, 160),
            'basis' => in_array($o['basis'] ?? '', $bases, true) ? $o['basis'] : 'fixed',
            'qty_auto' => cs_num($o['qty_auto'] ?? 0),
            'qty_override' => $numOrNull($o['qty_override'] ?? null),
            'rate' => cs_num($o['rate'] ?? 0),
            'amount_override' => $numOrNull($o['amount_override'] ?? null),
            'component_ref' => cs_isov($o['component_ref'] ?? null) ? (int)$o['component_ref'] : null,
            'source' => mb_substr(trim((string)($o['source'] ?? 'manual')), 0, 20),
        ];
    }
    return (empty($out['components']) && empty($out['operations'])) ? null : $out;
}

/**
 * cs_compute(sheet, qty, config) — config: {gutter_cm, rounding, currency}
 */
function cs_compute(array $sheet, $qty, array $config = []): array {
    $gutter = cs_num($config['gutter_cm'] ?? 0.5, 0.5);
    $rounding = cs_num($config['rounding'] ?? 0);
    $cur = $config['currency'] ?? '$';
    $qty = max(0, cs_int($qty));

    $warnings = [];
    $lines = [];
    $comps = [];
    $paperTotal = 0.0; $paperFixed = 0.0;
    $rawComps = is_array($sheet['components'] ?? null) ? $sheet['components'] : [];

    foreach ($rawComps as $c) {
        $ov = is_array($c['overrides'] ?? null) ? $c['overrides'] : [];
        $perProd = max(1, cs_num($c['pieces_per_product'] ?? 1, 1));
        $pieces = (int)ceil($qty * $perProd);
        $upsAuto = cs_fit_ups(cs_num($c['sheet_w_cm'] ?? 0), cs_num($c['sheet_h_cm'] ?? 0), cs_num($c['piece_w_cm'] ?? 0), cs_num($c['piece_h_cm'] ?? 0), $gutter);
        $ups = cs_int($c['ups'] ?? 0) > 0 ? cs_int($c['ups']) : max(1, $upsAuto);
        if ($upsAuto === 0 && cs_num($c['piece_w_cm'] ?? 0) > 0) $warnings[] = ($c['label'] ?? 'Component') . ': piece does not fit the sheet.';

        $wastePct = cs_num($c['waste_pct'] ?? 0);
        $setupSheets = max(0, cs_int($c['setup_sheets'] ?? 0));
        $sheetsNet = $qty > 0 ? (int)ceil($pieces / $ups) : 0;
        $sheetsTotal = cs_isov($ov['sheets_total'] ?? null) ? cs_int($ov['sheets_total'])
            : ($qty > 0 ? (int)ceil($sheetsNet * (1 + $wastePct / 100)) + $setupSheets : 0);
        $csheet = cs_sheet_cost($c);
        $cost = cs_isov($ov['paper_cost'] ?? null) ? cs_num($ov['paper_cost']) : $sheetsTotal * $csheet;

        $paperTotal += $cost;
        $paperFixed += min($sheetsTotal, $setupSheets) * $csheet;

        $mathStr = cs_fmt($pieces) . ' pcs / ' . $ups . '-up = ' . cs_fmt($sheetsNet) . ' sh'
            . ($wastePct > 0 ? ' +' . cs_fmt($wastePct) . '%' : '')
            . ($setupSheets > 0 ? ' +' . $setupSheets . ' setup' : '')
            . ' = ' . cs_fmt($sheetsTotal) . ' sh x ' . $cur . number_format(round($csheet * 10000) / 10000, 3);
        if (cs_isov($ov['sheets_total'] ?? null)) $mathStr = cs_fmt($pieces) . ' pcs, sheets set manually: ' . cs_fmt($sheetsTotal) . ' sh x ' . $cur . number_format(round($csheet * 10000) / 10000, 3);
        if (cs_isov($ov['paper_cost'] ?? null)) $mathStr .= ' (cost set manually)';

        $comps[] = [
            'label' => $c['label'] ?? 'Paper', 'paper_name' => $c['paper_name'] ?? '',
            'ups' => $ups, 'ups_auto' => $upsAuto, 'pieces' => $pieces,
            'sheets_net' => $sheetsNet, 'sheets_total' => $sheetsTotal,
            'cost_per_sheet' => round($csheet, 4), 'sheet_kg' => round(cs_sheet_kg($c), 4),
            'cost' => round($cost, 2),
            'overridden' => ['ups' => cs_int($c['ups'] ?? 0) > 0, 'sheets' => cs_isov($ov['sheets_total'] ?? null), 'cost' => cs_isov($ov['paper_cost'] ?? null)],
        ];
        $lines[] = ['kind' => 'paper', 'label' => ($c['label'] ?? 'Paper') . (!empty($c['paper_name']) ? ' — ' . $c['paper_name'] : ''), 'math' => $mathStr, 'amount' => round($cost, 2)];
    }

    // Operations
    $opsTotal = 0.0; $opsFixed = 0.0; $ops = [];
    $sheetsAll = array_sum(array_map(fn($d) => $d['sheets_total'], $comps));
    $compSheets = function ($ref) use ($comps, $sheetsAll) {
        if ($ref === null || $ref === '') return $sheetsAll;
        $i = cs_int($ref, -1);
        return isset($comps[$i]) ? $comps[$i]['sheets_total'] : $sheetsAll;
    };
    $compSqm = function ($ref) use ($comps, $rawComps) {
        $sq = 0.0;
        if ($ref === null || $ref === '') {
            foreach ($rawComps as $i => $c) {
                if (!isset($comps[$i])) continue;
                $sq += $comps[$i]['sheets_total'] * cs_num($c['sheet_w_cm'] ?? 0) * cs_num($c['sheet_h_cm'] ?? 0) / 10000;
            }
        } else {
            $i = cs_int($ref, 0);
            if (isset($rawComps[$i], $comps[$i])) {
                $sq = $comps[$i]['sheets_total'] * cs_num($rawComps[$i]['sheet_w_cm'] ?? 0) * cs_num($rawComps[$i]['sheet_h_cm'] ?? 0) / 10000;
            }
        }
        return $sq;
    };

    foreach ((is_array($sheet['operations'] ?? null) ? $sheet['operations'] : []) as $o) {
        $basis = $o['basis'] ?? 'fixed';
        $rate = cs_num($o['rate'] ?? 0);
        $ref = $o['component_ref'] ?? null;
        switch ($basis) {
            case 'per_sheet':       $qAuto = $compSheets($ref); $unitLbl = 'sh'; break;
            case 'per_1000_sheets': $qAuto = $compSheets($ref) / 1000; $unitLbl = 'x1000 sh'; break;
            case 'per_piece':       $qAuto = $qty; $unitLbl = 'pcs'; break;
            case 'per_1000_pieces': $qAuto = $qty / 1000; $unitLbl = 'x1000 pcs'; break;
            case 'per_sqm':         $qAuto = $compSqm($ref); $unitLbl = 'm2'; break;
            case 'per_plate':       $qAuto = max(0, cs_num($o['qty_auto'] ?? 0)); $unitLbl = 'plates'; break;
            default:                $qAuto = 1; $unitLbl = 'job'; break; // fixed
        }
        if ($basis === 'per_plate' && $qAuto == 0) $qAuto = cs_num($o['qty_override'] ?? 0) > 0 ? 0 : 1;
        $qEff = cs_isov($o['qty_override'] ?? null) ? cs_num($o['qty_override']) : $qAuto;
        $amount = cs_isov($o['amount_override'] ?? null) ? cs_num($o['amount_override']) : $qEff * $rate;
        $isFixed = ($basis === 'fixed' || $basis === 'per_plate');
        $opsTotal += $amount;
        if ($isFixed) $opsFixed += $amount;

        $mathStr = cs_isov($o['amount_override'] ?? null) ? 'amount set manually' : cs_fmt($qEff) . ' ' . $unitLbl . ' x ' . $cur . cs_fmt($rate);
        $ops[] = [
            'label' => $o['label'] ?? 'Operation', 'basis' => $basis,
            'qty' => round($qEff, 4), 'qty_auto' => round($qAuto, 4), 'rate' => $rate,
            'amount' => round($amount, 2), 'fixed' => $isFixed,
            'overridden' => ['qty' => cs_isov($o['qty_override'] ?? null), 'amount' => cs_isov($o['amount_override'] ?? null)],
        ];
        $lines[] = ['kind' => 'op', 'label' => $o['label'] ?? 'Operation', 'math' => $mathStr, 'amount' => round($amount, 2)];
    }

    $costTotal = $paperTotal + $opsTotal;
    $fixedTotal = $paperFixed + $opsFixed;
    $variableTotal = $costTotal - $fixedTotal;
    $unitCost = $qty > 0 ? $costTotal / $qty : 0;

    $markup = cs_num($sheet['markup_pct'] ?? 0);
    $sell = $costTotal * (1 + $markup / 100);
    if ($rounding > 0) $sell = ceil($sell / $rounding) * $rounding;
    $unitPrice = $qty > 0 ? round($sell / $qty, 2) : 0;

    return [
        'qty' => $qty,
        'components' => $comps, 'operations' => $ops,
        'paper_total' => round($paperTotal, 2), 'ops_total' => round($opsTotal, 2),
        'fixed_total' => round($fixedTotal, 2), 'variable_total' => round($variableTotal, 2),
        'cost_total' => round($costTotal, 2), 'unit_cost' => round($unitCost, 4),
        'markup_pct' => $markup,
        'sell' => round($sell, 2), 'unit_price' => $unitPrice,
        'margin_amount' => round($sell - $costTotal, 2),
        'margin_pct' => $sell > 0 ? round(($sell - $costTotal) / $sell * 100, 1) : 0,
        'lines' => $lines, 'warnings' => $warnings,
    ];
}
