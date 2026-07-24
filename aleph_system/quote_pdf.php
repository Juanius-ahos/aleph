<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: quotes.php'); exit; }

$db = getDB();
$quote = dbFetch($db, "SELECT q.*, c.company_name, c.contact_name, c.email, c.phone, c.address
    FROM quotes q LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ? AND q.deleted_at IS NULL", [$id]);
if (!$quote) { header('Location: quotes.php'); exit; }

$items = dbFetchAll($db, "SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order", [$id]);
$specsByItem = []; foreach (dbFetchAll($db, "SELECT * FROM pq_quote_specs WHERE quote_id=?", [$id]) as $s) $specsByItem[$s['quote_item_id']] = $s;
$tiersByItem = []; foreach (dbFetchAll($db, "SELECT * FROM pq_quote_tiers WHERE quote_id=? ORDER BY sort_order", [$id]) as $t) $tiersByItem[$t['quote_item_id']][] = $t;

$set = []; foreach (dbFetchAll($db, "SELECT setting_key, setting_value FROM settings") as $s) $set[$s['setting_key']] = $s['setting_value'];
$cur = $set['pq_currency_symbol'] ?? ($set['currency_symbol'] ?? '$');
$company = $set['company_name'] ?? 'Aleph';
$intro = $set['pq_intro'] ?? 'We have the pleasure to submit to you our quotation with the following specifications:';
$terms = $quote['terms'] ?: ($set['pq_payment_terms'] ?? '50% upon order & 50% upon delivery.');
$signatory = $set['pq_signatory'] ?? 'Samer Jawhar';
if (preg_match('/Signatory:\s*(.+)$/mi', $quote['internal_notes'] ?? '', $mm)) $signatory = trim($mm[1]);
$m = fn($v) => number_format((float)$v, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quotation <?= str_pad($quote['quote_number'], 4, '0', STR_PAD_LEFT) ?> — <?= h($company) ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#1a1a1a;background:#fff;padding:38px 46px;font-size:13.5px;line-height:1.45;}
    .lh{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #d8d8d8;padding-bottom:10px;}
    .logo{width:118px;}
    .logo img{width:118px;height:auto;display:block;}
    .contact{text-align:right;font-size:11px;color:#555;line-height:1.5;}
    .date{text-align:right;margin:26px 0 14px;font-size:13.5px;}
    .intro{margin-bottom:22px;}
    .item{margin-bottom:24px;}
    .item-title{font-weight:700;margin-bottom:8px;}
    .spec{display:grid;grid-template-columns:130px 12px 1fr;row-gap:2px;margin-bottom:10px;}
    .spec .k{color:#111;} .spec .c{color:#111;}
    table.qp{width:100%;border-collapse:collapse;margin-top:6px;}
    table.qp th{text-align:left;padding:6px 12px;font-weight:700;border:1px solid #ddd;font-size:13px;background:#f5f5f5;}
    table.qp td{padding:6px 12px;border:1px solid #ddd;font-size:13px;}
    table.qp td.price{font-weight:700;}
    .opt td{color:#333;font-style:italic;}
    .terms{margin-top:26px;}
    .sign{margin-top:34px;text-align:right;}
    .sign .i{font-style:italic;}
    .foot{margin-top:30px;text-align:center;color:#999;font-size:10px;border-top:1px solid #eee;padding-top:10px;}
    @media print{body{padding:24px 30px;} .noprint{display:none;}}
</style>
</head>
<body>
    <div class="lh">
        <div class="logo"><img src="assets/logo.png" alt="Aleph"></div>
        <div class="contact">
            <?= h($set['company_website'] ?? 'www.aleph.com.lb') ?><br>
            e-mail: <?= h($set['company_email'] ?? 'fabrication@aleph.com.lb') ?><br>
            tel/Fax: <?= h($set['company_phone'] ?? '00961 1 685 354 / 355') ?><br>
            <?= h($set['company_address'] ?? 'Mekalles - Eliane Building, P.O.Box 147 Mansourieh El Metn, 1253 2020 Lebanon') ?><br>
            R.C.B. <?= h($set['company_rcb'] ?? '18872') ?> &nbsp; TVA: <?= h($set['company_tva'] ?? '248620-601') ?>
        </div>
    </div>

    <div class="date"><?= date('l, F j, Y', strtotime($quote['created_at'])) ?></div>
    <div class="intro">Dear Sirs,<br><br><?= h($intro) ?></div>

    <?php foreach ($items as $n => $item): $s = $specsByItem[$item['id']] ?? null; $tiers = $tiersByItem[$item['id']] ?? [];
        $lines = $s && $s['spec_lines'] ? (json_decode($s['spec_lines'], true) ?: []) : [];
        $opts = $s && $s['options'] ? (json_decode($s['options'], true) ?: []) : [];
        $pg = $s ? (int)($s['pages'] ?? 1) : 1;
        $cpn = $s ? ($s['cover_paper_name'] ?? null) : null; ?>
    <div class="item">
        <div class="item-title"><?= ($n+1) ?> — <?= h($s['title'] ?? $item['description']) ?></div>
        <?php if ($pg > 1): ?><div class="spec"><div class="k">Pages</div><div class="c">:</div><div class="v"><?= $pg ?> pages</div></div><?php endif; ?>
        <?php if ($cpn): ?><div class="spec"><div class="k">Cover</div><div class="c">:</div><div class="v"><?= h($cpn) ?></div></div><?php endif; ?>
        <div class="spec">
            <?php foreach ($lines as $l): if (trim($l['value'])==='') continue; ?>
                <div class="k"><?= h($l['label']) ?></div><div class="c">:</div><div class="v"><?= h($l['value']) ?></div>
            <?php endforeach; ?>
        </div>
        <table class="qp">
            <thead><tr><th style="width:55%;">Quantity</th><th>Price</th></tr></thead>
            <tbody>
                <?php foreach ($tiers as $t): ?>
                <tr><td><?= h($t['label']) ?></td><td class="price"><?= $m($t['total_price']) ?> <?= h($cur) ?> + VAT</td></tr>
                <?php endforeach; ?>
                <?php foreach ($opts as $o): ?>
                <tr class="opt"><td><strong>Option</strong> <?= h($o['label']) ?>, add:</td><td class="price">+ <?= $m($o['price']) ?> <?= h($cur) ?> + VAT</td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($quote['notes'])): ?><div class="terms"><strong>Note:</strong> <?= nl2br(h($quote['notes'])) ?></div><?php endif; ?>
    <div class="terms"><strong>Payment terms:</strong> <?= h($terms) ?><br>Should you need further details, please do not hesitate to contact us.</div>

    <div class="sign">Sincerely,<br><span class="i"><?= h($signatory) ?></span></div>
    <div class="foot"><?= h($set['company_website'] ?? 'www.aleph.com.lb') ?></div>

    <script>window.onload=function(){window.print();};</script>
</body>
</html>
