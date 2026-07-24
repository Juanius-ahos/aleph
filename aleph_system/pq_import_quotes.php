<?php
/**
 * Aleph Historical Quote Importer
 * Reads quotation_data.json and imports 481 quotes as historical accepted quotes.
 * One-time use — DELETE after running.
 *
 * Usage: Visit pq_import_quotes.php in browser or run via CLI:
 *   php pq_import_quotes.php
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Require admin login
if (!isLoggedIn() || currentUserRole() !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admin login required.');
}

$isCli = (php_sapi_name() === 'cli');
$log = function($msg) use ($isCli) {
    if ($isCli) { echo strip_tags($msg) . "\n"; } else { echo $msg . "<br>"; flush(); }
};

$db = getDB();

$jsonFile = __DIR__ . '/quotation_data.json';
if (!file_exists($jsonFile)) {
    $log("<span style='color:red'>ERROR: quotation_data.json not found. Run parse_quotes.py first.</span>");
    exit(1);
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data || empty($data['items'])) {
    $log("<span style='color:red'>ERROR: No items found in quotation_data.json</span>");
    exit(1);
}

$log("<h2>Aleph Historical Quote Importer</h2>");
$log("<p>Found <strong>" . count($data['items']) . "</strong> items to import from " . count($data['items']) . " parsed quotations.</p>");

// ── Get or create the import user (first admin) ──
$admin = dbFetch($db, "SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1");
$userId = $admin ? $admin['id'] : null;

// ── Normalize customer name ──
function normalizeCustomer($name) {
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    // Remove trailing numbering like "1" that's just a template artifact
    if (preg_match('/^\d+$/', $name)) return null;
    return $name;
}

// ── Find or create customer ──
function findOrCreateCustomer($db, $name, $userId) {
    $name = normalizeCustomer($name);
    if (!$name) return null;

    // Case-insensitive lookup
    $existing = dbFetch($db, "SELECT id FROM customers WHERE LOWER(company_name) = LOWER(?) AND deleted_at IS NULL", [$name]);
    if ($existing) return $existing['id'];

    // Create new customer
    $id = dbInsert($db, 'customers', [
        'company_name' => $name,
        'customer_type' => 'existing',
        'currency' => 'USD',
        'country' => 'Lebanon',
        'created_by' => $userId,
    ]);
    return $id;
}

// ── Parse size from spec string ──
function parseSize($sizeStr) {
    $w = 0; $h = 0; $d = null;
    if (empty($sizeStr)) return ['w'=>$w, 'h'=>$h, 'd'=>$d];

    // "21 x 29.7 cm" or "8.5 x 13.9 x 5.1 cm"
    if (preg_match('/([\d.]+)\s*x\s*([\d.]+)\s*x\s*([\d.]+)/', $sizeStr, $m)) {
        $w = floatval($m[1]); $h = floatval($m[2]); $d = floatval($m[3]);
    } elseif (preg_match('/([\d.]+)\s*x\s*([\d.]+)/', $sizeStr, $m)) {
        $w = floatval($m[1]); $h = floatval($m[2]);
    }
    return ['w'=>$w, 'h'=>$h, 'd'=>$d];
}

// ── Parse printing spec ──
function parsePrinting($printStr) {
    $front = 0; $back = 0; $sides = 1;
    if (empty($printStr)) return ['front'=>$front, 'back'=>$back, 'sides'=>$sides];

    $p = strtolower($printStr);
    // "4/4" or "2/0" or "1/1"
    if (preg_match('/(\d+)\s*\/\s*(\d+)/', $p, $m)) {
        $front = intval($m[1]); $back = intval($m[2]);
        $sides = ($back > 0) ? 2 : 1;
    } elseif (preg_match('/(\d+)\s*\/\s*(\d+)/', $printStr, $m)) {
        $front = intval($m[1]); $back = intval($m[2]);
        $sides = ($back > 0) ? 2 : 1;
    } elseif (stripos($p, 'recto') !== false && stripos($p, 'verso') !== false) {
        $front = 4; $back = 4; $sides = 2;
    } elseif (stripos($p, 'recto') !== false || stripos($p, '1/0') !== false) {
        $front = 4; $back = 0; $sides = 1;
    } elseif (stripos($p, 'digital') !== false) {
        $front = 4; $back = (stripos($p, 'r/v') !== false || stripos($p, 'verso') !== false) ? 4 : 0;
        $sides = ($back > 0) ? 2 : 1;
    }
    return ['front'=>$front, 'back'=>$back, 'sides'=>$sides];
}

// ── Parse pages ──
function parsePages($pagesStr) {
    if (empty($pagesStr)) return 1;
    if (preg_match('/(\d+)/', $pagesStr, $m)) return intval($m[1]);
    return 1;
}

// ── Get next quote number ──
$maxNum = (int)(dbFetch($db, "SELECT COALESCE(MAX(quote_number),0) m FROM quotes")['m'] ?? 0);
$nextNum = $maxNum + 1;

// ── Begin Import ──
$imported = 0;
$skipped = 0;
$customersCreated = 0;
$customersReused = 0;
$errors = [];

$log("<p style='margin-top:16px'><strong>Starting import...</strong></p>");

foreach ($data['items'] as $idx => $item) {
    $customerName = $item['customer'] ?? '';
    $title = $item['title'] ?? $customerName . ' – Quotation';
    $date = $item['date'] ?? date('Y-m-d');
    $specs = $item['specs'] ?? [];
    $qtyPrices = $item['quantity_prices'] ?? [];

    // Skip items with no prices at all
    if (empty($qtyPrices)) {
        $skipped++;
        continue;
    }

    try {
        // Find or create customer
        $custCountBefore = (int)(dbFetch($db, "SELECT COUNT(*) c FROM customers WHERE deleted_at IS NULL")['c'] ?? 0);
        $customerId = findOrCreateCustomer($db, $customerName, $userId);
        $custCountAfter = (int)(dbFetch($db, "SELECT COUNT(*) c FROM customers WHERE deleted_at IS NULL")['c'] ?? 0);
        if ($custCountAfter > $custCountBefore) {
            $customersCreated++;
        } else {
            $customersReused++;
        }

        // Use the first quantity/price tier as the primary
        $primaryTier = $qtyPrices[0];
        $total = $primaryTier['price'] ?? 0;

        // Calculate total if multiple tiers (use the primary/first)
        // For historical quotes, we keep the actual price from the quotation

        // Create quote
        $quoteId = dbInsert($db, 'quotes', [
            'quote_number' => $nextNum,
            'customer_id' => $customerId,
            'title' => mb_substr($title, 0, 200),
            'description' => "Imported from historical quotation: " . ($item['filename'] ?? ''),
            'status' => 'accepted',
            'subtotal' => $total,
            'total' => $total,
            'created_by' => $userId,
            'created_at' => $date . ' 10:00:00',
        ]);

        // Create quote item (main line)
        $specLine = [];
        if (!empty($specs['size'])) $specLine[] = "Size: " . $specs['size'];
        if (!empty($specs['pages'])) $specLine[] = "Pages: " . $specs['pages'];
        if (!empty($specs['paper'])) $specLine[] = "Paper: " . $specs['paper'];
        if (!empty($specs['printing'])) $specLine[] = "Printing: " . $specs['printing'];
        if (!empty($specs['finishing'])) $specLine[] = "Finishing: " . $specs['finishing'];
        $description = implode(' | ', $specLine);
        if (empty($description)) $description = $title;

        $itemId = dbInsert($db, 'quote_items', [
            'quote_id' => $quoteId,
            'description' => mb_substr($description, 0, 500),
            'quantity' => $primaryTier['quantity'] ?? 1,
            'unit_price' => $total / max(1, $primaryTier['quantity'] ?? 1),
            'total' => $total,
            'sort_order' => 0,
        ]);

        // Create pq_quote_specs
        $size = parseSize($specs['size'] ?? '');
        $printing = parsePrinting($specs['printing'] ?? '');
        $pages = parsePages($specs['pages'] ?? '');

        $specLines = [];
        if (!empty($specs['size'])) $specLines[] = ['label' => 'Size', 'value' => $specs['size']];
        if (!empty($specs['pages'])) $specLines[] = ['label' => 'Pages', 'value' => $specs['pages']];
        if (!empty($specs['paper'])) $specLines[] = ['label' => 'Paper', 'value' => $specs['paper']];
        if (!empty($specs['printing'])) $specLines[] = ['label' => 'Printing', 'value' => $specs['printing']];
        if (!empty($specs['finishing'])) $specLines[] = ['label' => 'Finishing', 'value' => $specs['finishing']];

        dbInsert($db, 'pq_quote_specs', [
            'quote_id' => $quoteId,
            'quote_item_id' => $itemId,
            'title' => mb_substr($title, 0, 200),
            'size_w_cm' => $size['w'],
            'size_h_cm' => $size['h'],
            'depth_cm' => $size['d'],
            'pages' => $pages,
            'paper_name' => $specs['paper'] ?? null,
            'method' => (stripos($specs['printing'] ?? '', 'digital') !== false) ? 'digital' : 'offset',
            'colors_front' => $printing['front'],
            'colors_back' => $printing['back'],
            'sides' => $printing['sides'],
            'finishing_names' => $specs['finishing'] ?? null,
            'spec_lines' => json_encode($specLines),
        ]);

        // Create pq_quote_tiers for all quantity/price pairs
        $tierSort = 0;
        foreach ($qtyPrices as $tier) {
            $tierQty = $tier['quantity'] ?? 0;
            $tierPrice = $tier['price'] ?? 0;
            $tierLabel = $tier['quantity_raw'] ?? number_format($tierQty);
            if ($tier['is_option'] ?? false) {
                $tierLabel = ($tier['label'] ?? 'Option') . ' – ' . $tierLabel;
            }

            dbInsert($db, 'pq_quote_tiers', [
                'quote_id' => $quoteId,
                'quote_item_id' => $itemId,
                'label' => mb_substr($tierLabel, 0, 100),
                'quantity' => $tierQty,
                'unit_price' => $tierQty > 0 ? round($tierPrice / $tierQty, 4) : 0,
                'total_price' => $tierPrice,
                'price_mode' => 'historical',
                'is_primary' => ($tierSort === 0) ? 1 : 0,
                'sort_order' => $tierSort,
            ]);
            $tierSort++;
        }

        $nextNum++;
        $imported++;

        if (($imported % 50) === 0) {
            $log("<p>Imported $imported quotes so far...</p>");
        }

    } catch (Exception $e) {
        $errors[] = ['item' => $title, 'error' => $e->getMessage()];
        $log("<span style='color:orange'>Warning: {$e->getMessage()} on item " . htmlspecialchars($title) . "</span><br>");
    }
}

// ── Summary ──
$log("<hr>");
$log("<h3>Import Complete</h3>");
$log("<table border='1' cellpadding='8' cellspacing='0' style='border-collapse:collapse;font-size:13px'>");
$log("<tr><td><strong>Quotes imported</strong></td><td>$imported</td></tr>");
$log("<tr><td><strong>Skipped (no price)</strong></td><td>$skipped</td></tr>");
$log("<tr><td><strong>Customers created</strong></td><td>$customersCreated</td></tr>");
$log("<tr><td><strong>Customers reused</strong></td><td>$customersReused</td></tr>");
$log("<tr><td><strong>Errors</strong></td><td>" . count($errors) . "</td></tr>");
$log("<tr><td><strong>Quote numbers</strong></td><td>Q-0001 to Q-" . str_pad($nextNum - 1, 4, '0', STR_PAD_LEFT) . "</td></tr>");
$log("</table>");

if (!empty($errors)) {
    $log("<h4>Errors:</h4><ul>");
    foreach ($errors as $e) {
        $log("<li><strong>" . htmlspecialchars($e['item']) . "</strong>: " . htmlspecialchars($e['error']) . "</li>");
    }
    $log("</ul>");
}

$log("<p style='margin-top:20px;color:green'><strong>Done.</strong> You can now DELETE this file (pq_import_quotes.php) for security.</p>");
if (!$isCli) $log("<p><a href='quotes.php'>View Quotes</a> | <a href='customers.php'>View Customers</a></p>");
