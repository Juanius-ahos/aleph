<?php
/**
 * Aleph — server-authoritative price calc for one print item.
 * POST (form or JSON). Returns the pq_calculate() breakdown as JSON.
 * The browser mirrors this live; this endpoint is the source of truth on save.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/quote_engine.php';
require_once __DIR__ . '/../lib/cost_sheet.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// Accept JSON body or form-encoded
$raw = file_get_contents('php://input');
$body = [];
if ($raw && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $body = json_decode($raw, true) ?: [];
} else {
    $body = $_POST;
}

// CSRF check (token from body or header)
$token = $body['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($token) || !hash_equals(csrfToken(), $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$db = getDB();
$config = pq_load_config($db);

// Cost-sheet mode: {cost_sheet: {...}, quantity: N} -> full worksheet computation
$costSheet = cs_sanitize($body['cost_sheet'] ?? null);
if ($costSheet) {
    $result = cs_compute($costSheet, (int)($body['quantity'] ?? 0), [
        'gutter_cm' => (float)($config['gutter_mm'] ?? 5) / 10,
        'rounding' => (float)($config['rounding'] ?? 0),
        'currency' => $config['currency_symbol'],
    ]);
    $result['mode'] = 'cost_sheet';
    $result['currency'] = $config['currency_symbol'];
    echo json_encode($result);
    exit;
}

// Legacy spec-estimate mode
list($spec, $meta) = pq_resolve_spec($db, $body);
$result = pq_calculate($spec, $config);
$result['meta'] = $meta;
$result['currency'] = $config['currency_symbol'];

echo json_encode($result);
