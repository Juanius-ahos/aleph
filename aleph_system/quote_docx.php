<?php
/**
 * Aleph — generate an editable Word (.docx) quotation by FILLING the house template
 * (assets/quote_template.docx, produced from 1- Quotation Template.doc). The template shell
 * carries the real letterhead banner, asymmetric margins, the page-number footer and all
 * formatting untouched; here we only inject {{DATE}}, {{SIGNATORY}}, {{TERMS}} and the item
 * blocks (<!--ITEMS-->). No rebuild — the page geometry stays exactly the customer's.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: quotes.php'); exit; }

$db = getDB();
$quote = dbFetch($db, "SELECT q.*, c.company_name FROM quotes q
    LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ? AND q.deleted_at IS NULL", [$id]);
if (!$quote) { header('Location: quotes.php'); exit; }

$items = dbFetchAll($db, "SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order", [$id]);
$specsByItem = []; foreach (dbFetchAll($db, "SELECT * FROM pq_quote_specs WHERE quote_id=?", [$id]) as $s) $specsByItem[$s['quote_item_id']] = $s;
$tiersByItem = []; foreach (dbFetchAll($db, "SELECT * FROM pq_quote_tiers WHERE quote_id=? ORDER BY sort_order", [$id]) as $t) $tiersByItem[$t['quote_item_id']][] = $t;

$set = []; foreach (dbFetchAll($db, "SELECT setting_key, setting_value FROM settings") as $s) $set[$s['setting_key']] = $s['setting_value'];
$cur = $set['pq_currency_symbol'] ?? ($set['currency_symbol'] ?? '$');
$terms = $quote['terms'] ?: ($set['pq_payment_terms'] ?? '50% upon order & 50% upon delivery.');
$signatory = $set['pq_signatory'] ?? 'Samer Jawhar';
if (preg_match('/Signatory:\s*(.+)$/mi', $quote['internal_notes'] ?? '', $mm)) $signatory = trim($mm[1]);
$money = fn($v) => number_format((float)$v, 0);

/* ---------- WordprocessingML fragment builders (mirror the validated fill_template.py) ---------- */
const DX_CAL = '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>';
const DX_SPECTABS = '<w:tabs><w:tab w:val="left" w:pos="1440"/><w:tab w:val="left" w:pos="2160"/></w:tabs>';
// exact Quantity/Price table head lifted from the template (peach F7CAAC, black borders, 4158/4770)
const DX_TABLE_HEAD = '<w:tbl><w:tblPr><w:tblW w:w="8928" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:left w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:right w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/></w:tblBorders><w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr><w:tblGrid><w:gridCol w:w="4158"/><w:gridCol w:w="4770"/></w:tblGrid><w:tr w:rsidR="00EF16B8" w:rsidRPr="004E0E94" w:rsidTr="00EF16B8"><w:tc><w:tcPr><w:tcW w:w="4158" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F7CAAC"/></w:tcPr><w:p w:rsidR="00EF16B8" w:rsidRPr="004E0E94" w:rsidRDefault="00EF16B8"><w:pPr><w:spacing w:line="276" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:bCs/></w:rPr></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:bCs/></w:rPr><w:t>Quantity</w:t></w:r></w:p></w:tc><w:tc><w:tcPr><w:tcW w:w="4770" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F7CAAC"/></w:tcPr><w:p w:rsidR="00EF16B8" w:rsidRPr="004E0E94" w:rsidRDefault="00EF16B8"><w:pPr><w:spacing w:line="276" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr></w:pPr><w:r w:rsidRPr="004E0E94"><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:bCs/></w:rPr><w:t>Price</w:t></w:r></w:p></w:tc></w:tr>';

function dx_esc($s) { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string)$s); }
function dx_r($text, $bold = false) {
    $rpr = '<w:rPr>' . DX_CAL . ($bold ? '<w:b/><w:bCs/>' : '') . '</w:rPr>';
    return '<w:r>' . $rpr . '<w:t xml:space="preserve">' . dx_esc($text) . '</w:t></w:r>';
}
function dx_rtab() { return '<w:r><w:rPr>' . DX_CAL . '</w:rPr><w:tab/></w:r>'; }
function dx_popen($extra = '') { return '<w:p><w:pPr>' . $extra . '<w:spacing w:line="276" w:lineRule="auto"/><w:rPr>' . DX_CAL . '</w:rPr></w:pPr>'; }
function dx_empty() { return dx_popen() . '</w:p>'; }
function dx_title($n, $title) {
    $ppr = '<w:pPr><w:spacing w:line="276" w:lineRule="auto"/><w:rPr>' . DX_CAL . '<w:b/></w:rPr></w:pPr>';
    $run = '<w:r><w:rPr>' . DX_CAL . '<w:b/></w:rPr><w:t xml:space="preserve">' . dx_esc($n . ' – ' . $title) . '</w:t></w:r>';
    return '<w:p>' . $ppr . $run . '</w:p>';
}
function dx_spec($label, $value) {
    return dx_popen(DX_SPECTABS) . dx_r($label) . dx_rtab() . dx_r(':') . dx_rtab() . dx_r($value) . '</w:p>';
}
function dx_datarow($qtyRuns, $priceRuns) {
    return '<w:tr><w:tc><w:tcPr><w:tcW w:w="4158" w:type="dxa"/></w:tcPr>' . dx_popen() . $qtyRuns . '</w:p></w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="4770" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="auto"/></w:tcPr>' . dx_popen() . $priceRuns . '</w:p></w:tc></w:tr>';
}
function dx_qptable($tiers, $options, $cur, $money) {
    $rows = '';
    foreach ($tiers as $t) $rows .= dx_datarow(dx_r($t['label']), dx_r($money($t['total_price']) . ' ' . $cur, true) . dx_r(' + VAT'));
    foreach ($options as $o) $rows .= dx_datarow(dx_r($o['label']), dx_r('+ ' . $money($o['price']) . ' ' . $cur, true) . dx_r(' + VAT'));
    return DX_TABLE_HEAD . $rows . '</w:tbl>';
}
function dx_item($n, $title, $lines, $tiers, $options, $cur, $money) {
    $x = dx_title($n, $title) . dx_empty();
    foreach ($lines as $l) if (trim($l['value'] ?? '') !== '') $x .= dx_spec($l['label'] ?? '', $l['value']);
    $x .= dx_empty() . dx_qptable($tiers, $options, $cur, $money) . dx_empty();
    return $x;
}

// ---- build the items XML ----
$middle = '';
foreach ($items as $n => $item) {
    $s = $specsByItem[$item['id']] ?? null;
    $tiers = $tiersByItem[$item['id']] ?? [];
    $lines = $s && $s['spec_lines'] ? (json_decode($s['spec_lines'], true) ?: []) : [];
    $opts  = $s && $s['options'] ? (json_decode($s['options'], true) ?: []) : [];
    $title = $s['title'] ?? $item['description'];
    $middle .= dx_item($n + 1, $title, $lines, $tiers, $opts, $cur, $money);
}

// ---- fill the template shell ----
$tpl = __DIR__ . '/assets/quote_template.docx';
if (!is_file($tpl)) { http_response_code(500); exit('Quote template missing.'); }
$src = new ZipArchive();
if ($src->open($tpl) !== true) { http_response_code(500); exit('Could not open template.'); }
$documentXml = $src->getFromName('word/document.xml');
$documentXml = str_replace(
    ['{{DATE}}', '{{SIGNATORY}}', '{{TERMS}}', '<!--ITEMS-->'],
    [dx_esc(date('l, F j, Y', strtotime($quote['created_at']))), dx_esc($signatory), dx_esc($terms), $middle],
    $documentXml
);

// ---- repackage: copy every part from the shell, swap in the filled document.xml ----
$tmp = tempnam(sys_get_temp_dir(), 'qdocx');
$out = new ZipArchive();
if ($out->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) { $src->close(); http_response_code(500); exit('Could not build document.'); }
for ($i = 0; $i < $src->numFiles; $i++) {
    $name = $src->getNameIndex($i);
    if ($name === 'word/document.xml') $out->addFromString($name, $documentXml);
    else $out->addFromString($name, $src->getFromIndex($i));
}
$src->close();
$out->close();

$fname = 'Quotation-' . str_pad($quote['quote_number'], 4, '0', STR_PAD_LEFT) . '.docx';
logActivity('quotes', 'export', 'quote', $id, "Downloaded Word for quote #{$quote['quote_number']}");
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
