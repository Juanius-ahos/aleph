/*
 * Aleph — Print Quotation Engine (browser mirror of lib/quote_engine.php)
 * MUST stay in sync with the PHP pq_calculate() — same formula, same numbers.
 * Used for live pricing in the quote builder. The server recomputes on save.
 */
(function (global) {
    'use strict';

    function fit(sheetW, sheetH, cellW, cellH, gutter) {
        if (cellW <= 0 || cellH <= 0) return 0;
        var cols = Math.floor((sheetW + gutter) / (cellW + gutter));
        var rows = Math.floor((sheetH + gutter) / (cellH + gutter));
        if (cols < 0) cols = 0;
        if (rows < 0) rows = 0;
        return cols * rows;
    }

    function roundUp(value, step) {
        if (step <= 0) return round2(value);
        return round2(Math.ceil(value / step) * step);
    }
    function round2(v) { return Math.round(v * 100) / 100; }
    function round4(v) { return Math.round(v * 10000) / 10000; }
    function num(v, d) { v = parseFloat(v); return isNaN(v) ? (d || 0) : v; }
    function intval(v, d) { v = parseInt(v, 10); return isNaN(v) ? (d || 0) : v; }

    /* Volume pricing curve — small jobs carry disproportionate setup & labor */
    function volumeFactor(qty) {
        if (qty <= 0)   return 1.60;
        if (qty < 100)  return 1.50;
        if (qty < 250)  return 1.30;
        if (qty < 500)  return 1.15;
        if (qty < 1000) return 1.05;
        if (qty < 2000) return 1.00;
        if (qty < 5000) return 0.93;
        if (qty < 10000) return 0.88;
        return 0.83;
    }

    /* Finishing complexity premium — more steps = more handling, QC, risk */
    function finishingPremium(count) {
        if (count <= 1) return 1.00;
        if (count === 2) return 1.03;
        if (count === 3) return 1.06;
        if (count === 4) return 1.10;
        return 1.15;
    }

    /* Category markup factor — boxes/bags are custom work; flyers are commodities */
    function categoryFactor(cat) {
        var map = {
            'Boxes': 1.10, 'Bags': 1.10, 'Hardcovers': 1.15,
            'Books': 1.05, 'Cards': 1.05, 'Commercial': 0.95,
            'Labels': 1.00, 'Stationery': 1.00
        };
        return map[cat] || 1.00;
    }

    /* Sheet utilization penalty — low ups = more waste handling per piece */
    function utilizationFactor(ups) {
        if (ups >= 4) return 1.00;
        if (ups === 3) return 1.03;
        if (ups === 2) return 1.07;
        return 1.12;
    }

    function calculate(spec, config) {
        var warnings = [];
        var method = (spec.method === 'digital') ? 'digital' : 'offset';
        var qty = Math.max(0, intval(spec.quantity));
        var sides = intval(spec.sides) >= 2 ? 2 : 1;
        var cf = Math.max(0, intval(spec.colors_front));
        var cb = sides === 2 ? Math.max(0, intval(spec.colors_back)) : 0;

        var pieceW = num(spec.piece_w_cm), pieceH = num(spec.piece_h_cm);
        var sheetW = num(spec.sheet_w_cm), sheetH = num(spec.sheet_h_cm);
        var costSheet = num(spec.cost_per_sheet);

        var METHOD_DEFAULTS={
            offset:{max_sheet_w_cm:52,max_sheet_h_cm:74,plate_cost:35,makeready_cost_per_color:15,run_cost_per_1000:8,click_cost_per_sheet:0,min_charge:50},
            digital:{max_sheet_w_cm:33,max_sheet_h_cm:48,plate_cost:0,makeready_cost_per_color:0,run_cost_per_1000:0,click_cost_per_sheet:0.05,min_charge:25}
        };
        var m = METHOD_DEFAULTS[method] || METHOD_DEFAULTS.offset;
        var maxW = num(m.max_sheet_w_cm), maxH = num(m.max_sheet_h_cm);
        if (maxW > 0 && sheetW > maxW) { warnings.push('Paper width ' + sheetW + 'cm exceeds press max ' + maxW + 'cm — clamped.'); sheetW = maxW; }
        if (maxH > 0 && sheetH > maxH) { warnings.push('Paper height ' + sheetH + 'cm exceeds press max ' + maxH + 'cm — clamped.'); sheetH = maxH; }

        var markupPct = num(config.markup_pct);
        var wastePct = num(config.waste_pct);
        var setupWaste = Math.max(0, intval(config.setup_waste_sheets));
        var bleedCm = num(config.bleed_mm) / 10;
        var gutterCm = num(config.gutter_mm) / 10;
        var rounding = num(config.rounding);
        var jobMin = num(config.job_minimum, 50);

        var cellW = pieceW + 2 * bleedCm, cellH = pieceH + 2 * bleedCm;
        var ups = Math.max(fit(sheetW, sheetH, cellW, cellH, gutterCm), fit(sheetW, sheetH, cellH, cellW, gutterCm));
        if (ups < 1) { ups = 1; if (pieceW > 0 && sheetW > 0) warnings.push('Piece does not fit the parent sheet — check sizes.'); }

        var pages = Math.max(1, intval(spec.pages, 1));

        var sheetsRaw, sheets, impressions, paperCost;

        if (pages > 1) {
            var pagesPerSheet = ups * sides;
            var coverPaper = spec.cover_paper;
            if (coverPaper && num(coverPaper.cost_per_sheet) > 0) {
                var innerPages = Math.max(0, pages - 2);
                var innerSheetsRaw = innerPages > 0 ? Math.ceil((innerPages * qty) / pagesPerSheet) : 0;
                var coverSheetsRaw = Math.ceil(qty / ups);
                var innerSheets = innerSheetsRaw > 0 ? Math.ceil(innerSheetsRaw * (1 + wastePct / 100)) + setupWaste : 0;
                var coverSheets = qty > 0 ? Math.ceil(coverSheetsRaw * (1 + wastePct / 100)) + setupWaste : 0;
                sheets = innerSheets + coverSheets;
                paperCost = (innerSheets * costSheet) + (coverSheets * num(coverPaper.cost_per_sheet));
            } else {
                sheetsRaw = Math.ceil((pages * qty) / pagesPerSheet);
                sheets = qty > 0 ? Math.ceil(sheetsRaw * (1 + wastePct / 100)) + setupWaste : 0;
                paperCost = sheets * costSheet;
            }
        } else {
            sheetsRaw = qty > 0 ? Math.ceil(qty / ups) : 0;
            sheets = qty > 0 ? Math.ceil(sheetsRaw * (1 + wastePct / 100)) + setupWaste : 0;
            paperCost = sheets * costSheet;
        }
        impressions = sheets * sides;

        var plateCost = 0, makereadyCost = 0, runCost = 0, clickCost = 0;
        if (method === 'offset') {
            var plates = cf + cb;
            plateCost = plates * num(m.plate_cost);
            makereadyCost = plates * num(m.makeready_cost_per_color);
            runCost = (impressions / 1000) * num(m.run_cost_per_1000);
        } else {
            clickCost = impressions * num(m.click_cost_per_sheet);
        }
        var pressBase = plateCost + makereadyCost + runCost + clickCost;
        var minCharge = num(m.min_charge);
        var pressCost = (qty > 0 && pressBase < minCharge) ? minCharge : pressBase;

        var finishingCost = 0, finishingLines = [];
        var finArr = spec.finishing || [];
        var finCount = finArr.length;
        finArr.forEach(function (f) {
            var unit = num(f.unit_cost), setup = num(f.setup_cost), fmin = num(f.min_charge);
            var model = f.pricing_model || 'per_sheet', c;
            switch (model) {
                case 'per_piece': c = qty * unit; break;
                case 'per_1000': c = (qty / 1000) * unit; break;
                case 'flat': c = unit; break;
                case 'per_sqm': c = ((pieceW * pieceH) / 10000) * qty * unit; break;
                default: c = sheets * unit; break;
            }
            c += setup;
            if (qty > 0 && c < fmin) c = fmin;
            finishingCost += c;
            finishingLines.push({ label: f.name || 'Finishing', amount: round2(c) });
        });

        var totalCost = paperCost + pressCost + finishingCost;
        var unitCost = qty > 0 ? totalCost / qty : 0;

        var priceMode = (spec.price_mode === 'manual') ? 'manual' : 'engine';
        var sellingTotal, unitPrice, effectiveMarkup = 0;
        var volFac = 1, finPrem = 1, catFac = 1, utilFac = 1;

        if (priceMode === 'manual') {
            unitPrice = num(spec.manual_price);
            sellingTotal = unitPrice * qty;
        } else {
            var cat = spec.category || '';
            volFac = volumeFactor(qty);
            finPrem = finishingPremium(finCount);
            catFac = categoryFactor(cat);
            utilFac = utilizationFactor(ups);

            effectiveMarkup = markupPct * volFac * finPrem * catFac * utilFac;

            sellingTotal = totalCost * (1 + effectiveMarkup / 100);

            if (qty > 0 && sellingTotal < jobMin) sellingTotal = jobMin;

            sellingTotal = roundUp(sellingTotal, rounding);
            unitPrice = qty > 0 ? round2(sellingTotal / qty) : 0;
            sellingTotal = round2(unitPrice * qty);
        }

        var marginAmount = sellingTotal - totalCost;
        var marginPct = sellingTotal > 0 ? (marginAmount / sellingTotal) * 100 : 0;

        var lines = [{ label: 'Paper (' + sheets + ' sheets)', amount: round2(paperCost) }];
        if (method === 'offset') {
            lines.push({ label: 'Plates (' + (cf + cb) + ')', amount: round2(plateCost) });
            lines.push({ label: 'Make-ready', amount: round2(makereadyCost) });
            lines.push({ label: 'Press run (' + impressions + ' impressions)', amount: round2(runCost) });
        } else {
            lines.push({ label: 'Digital clicks (' + impressions + ')', amount: round2(clickCost) });
        }
        if (pressCost > pressBase) lines.push({ label: 'Press minimum applied', amount: round2(pressCost - pressBase) });
        finishingLines.forEach(function (fl) { lines.push(fl); });

        var result = {
            method: method, ups: ups, sheets: sheets, impressions: impressions, pages: pages,
            paper_cost: round2(paperCost), plate_cost: round2(plateCost), makeready_cost: round2(makereadyCost),
            run_cost: round2(runCost), click_cost: round2(clickCost), press_cost: round2(pressCost),
            finishing_cost: round2(finishingCost), total_cost: round2(totalCost), unit_cost: round4(unitCost),
            markup_pct: markupPct, effective_markup: Math.round(effectiveMarkup * 10) / 10,
            price_mode: priceMode, selling_total: round2(sellingTotal),
            unit_price: round2(unitPrice), margin_amount: round2(marginAmount), margin_pct: Math.round(marginPct * 10) / 10,
            lines: lines, warnings: warnings
        };

        if (priceMode === 'engine') {
            result.factors = {
                volume: Math.round(volFac * 100) / 100,
                finishing: Math.round(finPrem * 100) / 100,
                category: Math.round(catFac * 100) / 100,
                utilization: Math.round(utilFac * 100) / 100
            };
        }

        return result;
    }

    global.PQEngine = { calculate: calculate };
})(window);
