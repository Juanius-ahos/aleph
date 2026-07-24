/*
 * Aleph — Job Cost Sheet calculator (browser mirror of lib/cost_sheet.php)
 * MUST stay numerically identical to the PHP cs_compute().
 *
 * A cost sheet is the digital version of the paper costing worksheet:
 *   components[]  — every paper part of the product (bag body, tongue, insert, cover…)
 *   operations[]  — everything that is not paper (plates, make-ready, press run,
 *                   die making, die cutting, lamination, gluing, labour, transport…)
 * Every derived number can be overridden; overrides always win.
 * Fixed costs (dies, plates, make-ready, setup waste) do not scale with quantity,
 * which is exactly why 500 pcs can cost $500 while 1,000 pcs cost $600.
 */
(function (global) {
    'use strict';

    function num(v, d) { v = parseFloat(v); return isNaN(v) ? (d || 0) : v; }
    function intv(v, d) { v = parseInt(v, 10); return isNaN(v) ? (d || 0) : v; }
    function r2(v) { return Math.round(v * 100) / 100; }
    function r4(v) { return Math.round(v * 10000) / 10000; }
    function fmt(v) { return (Math.round(v * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 }); }
    function isOv(v) { return v !== null && v !== undefined && v !== '' && !isNaN(parseFloat(v)); }

    /* How many pieces fit a parent sheet (tries both orientations). */
    function fitUps(sheetW, sheetH, pieceW, pieceH, gutter) {
        if (pieceW <= 0 || pieceH <= 0 || sheetW <= 0 || sheetH <= 0) return 0;
        function fit(w, h) {
            var c = Math.floor((sheetW + gutter) / (w + gutter));
            var r = Math.floor((sheetH + gutter) / (h + gutter));
            return Math.max(0, c) * Math.max(0, r);
        }
        return Math.max(fit(pieceW, pieceH), fit(pieceH, pieceW));
    }

    /* Cost of one parent sheet. per_ton: sheet weight from gsm and area. */
    function sheetCost(comp) {
        if ((comp.price_mode || 'per_sheet') === 'per_ton') {
            // cm2 to m2 (/10000), times gsm = grams, /1e6 = tonnes; times price/ton
            return num(comp.sheet_w_cm) * num(comp.sheet_h_cm) * num(comp.gsm) * num(comp.price_per_ton) / 1e10;
        }
        return num(comp.cost_per_sheet);
    }
    function sheetKg(comp) {
        return num(comp.sheet_w_cm) * num(comp.sheet_h_cm) * num(comp.gsm) / 1e7;
    }

    /*
     * cs_compute(sheet, qty, config)
     * config: { gutter_cm, rounding, currency }
     * Returns full derived sheet for that quantity, incl. human-readable math lines.
     */
    function compute(sheet, qty, config) {
        config = config || {};
        var gutter = num(config.gutter_cm, 0.5);
        var rounding = num(config.rounding, 0);
        var cur = config.currency || '$';
        qty = Math.max(0, intv(qty));

        var warnings = [];
        var lines = [];
        var comps = [];
        var paperTotal = 0, paperFixed = 0;

        (sheet.components || []).forEach(function (c) {
            var ov = c.overrides || {};
            var perProd = Math.max(1, num(c.pieces_per_product, 1));
            var pieces = Math.ceil(qty * perProd);
            var upsAuto = fitUps(num(c.sheet_w_cm), num(c.sheet_h_cm), num(c.piece_w_cm), num(c.piece_h_cm), gutter);
            var ups = intv(c.ups) > 0 ? intv(c.ups) : Math.max(1, upsAuto);
            if (upsAuto === 0 && num(c.piece_w_cm) > 0) warnings.push((c.label || 'Component') + ': piece does not fit the sheet.');

            var wastePct = num(c.waste_pct, 0);
            var setupSheets = Math.max(0, intv(c.setup_sheets, 0));
            var sheetsNet = qty > 0 ? Math.ceil(pieces / ups) : 0;
            var sheetsTotal = isOv(ov.sheets_total) ? intv(ov.sheets_total)
                : (qty > 0 ? Math.ceil(sheetsNet * (1 + wastePct / 100)) + setupSheets : 0);
            var csheet = sheetCost(c);
            var cost = isOv(ov.paper_cost) ? num(ov.paper_cost) : sheetsTotal * csheet;

            paperTotal += cost;
            // the setup portion of paper is a fixed cost
            paperFixed += Math.min(sheetsTotal, setupSheets) * csheet;

            var mathStr = fmt(pieces) + ' pcs / ' + ups + '-up = ' + fmt(sheetsNet) + ' sh'
                + (wastePct > 0 ? ' +' + fmt(wastePct) + '%' : '')
                + (setupSheets > 0 ? ' +' + setupSheets + ' setup' : '')
                + ' = ' + fmt(sheetsTotal) + ' sh x ' + cur + r4(csheet).toFixed(3);
            if (isOv(ov.sheets_total)) mathStr = fmt(pieces) + ' pcs, sheets set manually: ' + fmt(sheetsTotal) + ' sh x ' + cur + r4(csheet).toFixed(3);
            if (isOv(ov.paper_cost)) mathStr += ' (cost set manually)';

            comps.push({
                label: c.label || 'Paper', paper_name: c.paper_name || '',
                ups: ups, ups_auto: upsAuto, pieces: pieces,
                sheets_net: sheetsNet, sheets_total: sheetsTotal,
                cost_per_sheet: r4(csheet), sheet_kg: r4(sheetKg(c)),
                cost: r2(cost),
                overridden: { ups: intv(c.ups) > 0, sheets: isOv(ov.sheets_total), cost: isOv(ov.paper_cost) }
            });
            lines.push({ kind: 'paper', label: (c.label || 'Paper') + (c.paper_name ? ' — ' + c.paper_name : ''), math: mathStr, amount: r2(cost) });
        });

        // Operations
        var opsTotal = 0, opsFixed = 0, ops = [];
        var sheetsAll = comps.reduce(function (s, c) { return s + c.sheets_total; }, 0);
        function compSheets(ref) {
            if (ref === null || ref === undefined || ref === '' ) return sheetsAll;
            var i = intv(ref, -1);
            return (comps[i] ? comps[i].sheets_total : sheetsAll);
        }
        function compSqm(ref) {
            var list = (ref === null || ref === undefined || ref === '') ? (sheet.components || [])
                : [(sheet.components || [])[intv(ref, 0)]].filter(Boolean);
            var sq = 0;
            list.forEach(function (c, i2) {
                var idx = (ref === null || ref === undefined || ref === '') ? i2 : intv(ref, 0);
                var d = comps[idx]; if (!d) return;
                sq += d.sheets_total * num(c.sheet_w_cm) * num(c.sheet_h_cm) / 10000;
            });
            return sq;
        }

        (sheet.operations || []).forEach(function (o) {
            var basis = o.basis || 'fixed';
            var rate = num(o.rate);
            var qAuto, unitLbl;
            switch (basis) {
                case 'per_sheet':        qAuto = compSheets(o.component_ref); unitLbl = 'sh'; break;
                case 'per_1000_sheets':  qAuto = compSheets(o.component_ref) / 1000; unitLbl = 'x1000 sh'; break;
                case 'per_piece':        qAuto = qty; unitLbl = 'pcs'; break;
                case 'per_1000_pieces':  qAuto = qty / 1000; unitLbl = 'x1000 pcs'; break;
                case 'per_sqm':          qAuto = compSqm(o.component_ref); unitLbl = 'm2'; break;
                case 'per_plate':        qAuto = Math.max(0, num(o.qty_auto, 0)); unitLbl = 'plates'; break;
                default:                 qAuto = 1; unitLbl = 'job'; break; // fixed
            }
            if (basis === 'per_plate' && qAuto === 0) qAuto = num(o.qty_override, 0) > 0 ? 0 : 1;
            var qEff = isOv(o.qty_override) ? num(o.qty_override) : qAuto;
            var amount = isOv(o.amount_override) ? num(o.amount_override) : qEff * rate;
            var isFixed = (basis === 'fixed' || basis === 'per_plate');
            opsTotal += amount;
            if (isFixed) opsFixed += amount;

            var mathStr = isOv(o.amount_override)
                ? 'amount set manually'
                : fmt(qEff) + ' ' + unitLbl + ' x ' + cur + fmt(rate);
            ops.push({
                label: o.label || 'Operation', basis: basis,
                qty: r4(qEff), qty_auto: r4(qAuto), rate: rate,
                amount: r2(amount), fixed: isFixed,
                overridden: { qty: isOv(o.qty_override), amount: isOv(o.amount_override) }
            });
            lines.push({ kind: 'op', label: o.label || 'Operation', math: mathStr, amount: r2(amount) });
        });

        var costTotal = paperTotal + opsTotal;
        var fixedTotal = paperFixed + opsFixed;
        var variableTotal = costTotal - fixedTotal;
        var unitCost = qty > 0 ? costTotal / qty : 0;

        var markup = num(sheet.markup_pct, 0);
        var sell = costTotal * (1 + markup / 100);
        if (rounding > 0) sell = Math.ceil(sell / rounding) * rounding;
        var unitPrice = qty > 0 ? r2(sell / qty) : 0;

        return {
            qty: qty,
            components: comps, operations: ops,
            paper_total: r2(paperTotal), ops_total: r2(opsTotal),
            fixed_total: r2(fixedTotal), variable_total: r2(variableTotal),
            cost_total: r2(costTotal), unit_cost: r4(unitCost),
            markup_pct: markup,
            sell: r2(sell), unit_price: unitPrice,
            margin_amount: r2(sell - costTotal),
            margin_pct: sell > 0 ? Math.round((sell - costTotal) / sell * 1000) / 10 : 0,
            lines: lines, warnings: warnings
        };
    }

    global.CostSheet = { compute: compute, fitUps: fitUps, sheetCost: sheetCost, sheetKg: sheetKg };
})(typeof window !== 'undefined' ? window : globalThis);
