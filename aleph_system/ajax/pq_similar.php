<?php
/**
 * Aleph — "Similar past quotes" price lookup.
 * Given a print item's specs (title / paper / printing / finishing / size), returns the
 * closest real historical quotes with their actual quantity->price tiers, ranked by
 * similarity. This is the real price book: what Aleph actually charged for like jobs.
 * Read-only. POST (JSON or form) with a _token; token is NOT rotated (mirrors pq_calc.php).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/quote_engine.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$raw = file_get_contents('php://input');
$body = ($raw && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) ? (json_decode($raw, true) ?: []) : $_POST;

$token = $body['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($token) || !hash_equals(csrfToken(), $token)) { http_response_code(403); echo json_encode(['error' => 'Invalid security token']); exit; }

$qTitle     = (string)($body['title'] ?? '');
$qPaper     = (string)($body['paper'] ?? '');
$qPrinting  = (string)($body['printing'] ?? '');
$qFinishing = (string)($body['finishing'] ?? '');
$qW = (float)($body['size_w'] ?? 0);
$qH = (float)($body['size_h'] ?? 0);
$excludeId = (int)($body['exclude_id'] ?? 0);

// nothing to match on
if (trim($qTitle . $qPaper . $qFinishing) === '') { echo json_encode(['matches' => []]); exit; }

$db = getDB();
$config = pq_load_config($db);
$cur = $config['currency_symbol'];

// tokeniser + a few noise words that would over-match
$STOP = ['gsm' => 1, 'cm' => 1, 'mm' => 1, 'the' => 1, 'and' => 1, 'for' => 1, 'with' => 1, 'add' => 1, 'option' => 1];
$tok = function ($s) use ($STOP) {
    preg_match_all('/[a-z0-9]+/', mb_strtolower((string)$s), $m);
    $out = [];
    foreach ($m[0] as $t) if (!isset($STOP[$t]) && strlen($t) > 1) $out[$t] = true;
    return $out;
};
$qPaperT = $tok($qPaper);
$qFinT   = $tok($qFinishing);
$qTitleT = $tok($qTitle);
$qPrintT = $tok($qPrinting);

// pull candidate specs (all non-deleted quotes; historical imports + real accepted ones)
$specs = dbFetchAll($db, "
    SELECT s.quote_id, s.quote_item_id, s.title, s.paper_name, s.finishing_names,
           s.size_w_cm, s.size_h_cm, s.depth_cm, s.colors_front, s.colors_back, s.spec_lines,
           q.created_at, q.status, c.company_name
    FROM pq_quote_specs s
    JOIN quotes q ON q.id = s.quote_id AND q.deleted_at IS NULL
    LEFT JOIN customers c ON c.id = q.customer_id
    WHERE (? = 0 OR s.quote_id <> ?)
", [$excludeId, $excludeId]);

// tiers grouped by item
$tiersByItem = [];
foreach (dbFetchAll($db, "
    SELECT t.quote_item_id, t.label, t.quantity, t.total_price, t.sort_order
    FROM pq_quote_tiers t
    JOIN quotes q ON q.id = t.quote_id AND q.deleted_at IS NULL
    ORDER BY t.quote_item_id, t.sort_order
") as $t) {
    $tiersByItem[$t['quote_item_id']][] = [
        'label' => $t['label'],
        'quantity' => (int)$t['quantity'],
        'price' => (float)$t['total_price'],
    ];
}

$matches = [];
foreach ($specs as $s) {
    // prefer the printed spec-line text where present (closest to what was quoted)
    $paperTxt = $s['paper_name']; $finTxt = $s['finishing_names'];
    if (!empty($s['spec_lines'])) {
        foreach ((json_decode($s['spec_lines'], true) ?: []) as $l) {
            $lab = mb_strtolower($l['label'] ?? '');
            if ($lab === 'paper' && trim($l['value']) !== '') $paperTxt = $l['value'];
            if ($lab === 'finishing' && trim($l['value']) !== '') $finTxt = $l['value'];
        }
    }
    $sp = $tok($paperTxt); $sf = $tok($finTxt); $st = $tok($s['title']);
    $printing = ((int)$s['colors_front']) . '/' . ((int)$s['colors_back']);

    $score = 0;
    foreach ($qPaperT as $t => $_) { if (isset($sp[$t])) $score += 3; if (isset($st[$t])) $score += 0.5; }
    foreach ($qFinT   as $t => $_) { if (isset($sf[$t])) $score += 2; }
    foreach ($qTitleT as $t => $_) { if (isset($st[$t])) $score += 2; if (isset($sp[$t]) || isset($sf[$t])) $score += 0.5; }
    if ($qPrinting !== '' && $printing === $qPrinting) $score += 1;
    // size proximity bonus (both dims within ~25%)
    if ($qW > 0 && $qH > 0 && (float)$s['size_w_cm'] > 0 && (float)$s['size_h_cm'] > 0) {
        $dw = abs((float)$s['size_w_cm'] - $qW) / $qW;
        $dh = abs((float)$s['size_h_cm'] - $qH) / $qH;
        if ($dw <= 0.25 && $dh <= 0.25) $score += 1.5;
    }
    if ($score <= 0) continue;

    $tiers = $tiersByItem[$s['quote_item_id']] ?? [];
    // keep only rows that actually carry a price
    $tiers = array_values(array_filter($tiers, fn($t) => $t['price'] > 0));
    if (empty($tiers)) continue;

    $matches[] = [
        'quote_id' => (int)$s['quote_id'],
        'customer' => $s['company_name'] ?: '—',
        'date' => $s['created_at'] ? date('M Y', strtotime($s['created_at'])) : '',
        'title' => $s['title'],
        'paper' => $paperTxt,
        'printing' => $printing,
        'finishing' => $finTxt,
        'size' => trim(($s['size_w_cm'] > 0 ? rtrim(rtrim((string)$s['size_w_cm'], '0'), '.') . ' x ' . rtrim(rtrim((string)$s['size_h_cm'], '0'), '.') . ($s['depth_cm'] > 0 ? ' x ' . rtrim(rtrim((string)$s['depth_cm'], '0'), '.') : '') . ' cm' : '')),
        'score' => round($score, 1),
        'tiers' => $tiers,
    ];
}

usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
$matches = array_slice($matches, 0, 8);

echo json_encode(['currency' => $cur, 'matches' => $matches]);
