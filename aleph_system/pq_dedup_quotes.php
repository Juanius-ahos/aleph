<?php
/**
 * Deduplicate Historical Quotes
 * Removes duplicate quotes from double-import, keeping the first occurrence.
 * DELETE after running.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isLoggedIn() || currentUserRole() !== 'admin') { http_response_code(403); exit('Access denied.'); }

$db = getDB();
$isCli = (php_sapi_name() === 'cli');
$log = function($msg) use ($isCli) { if ($isCli) { echo strip_tags($msg) . "\n"; } else { echo $msg . "<br>"; flush(); } };

$log("<h2>Deduplication Tool</h2>");

// Find duplicates: same title + customer_id + total + created_at
$dups = dbFetchAll($db, "
    SELECT q1.id as keep_id, q2.id as remove_id, q1.title, q1.total, q1.quote_number
    FROM quotes q1
    INNER JOIN quotes q2 
        ON q1.customer_id <=> q2.customer_id 
        AND q1.title = q2.title 
        AND q1.total = q2.total 
        AND DATE(q1.created_at) = DATE(q2.created_at)
        AND q2.id > q1.id
    WHERE q1.deleted_at IS NULL AND q2.deleted_at IS NULL
    ORDER BY q2.id
");

$removed = 0;
$quoteIds = [];

foreach ($dups as $dup) {
    $removeId = $dup['remove_id'];
    if (in_array($removeId, $quoteIds)) continue;
    $quoteIds[] = $removeId;
}

$log("<p>Found <strong>" . count($quoteIds) . "</strong> duplicate quotes to remove.</p>");

if (empty($quoteIds)) {
    $log("<p style='color:green'>No duplicates found. Database is clean.</p>");
    exit(0);
}

// Show some examples
$log("<p>Sample duplicates being removed:</p><ul>");
foreach (array_slice($dups, 0, 5) as $dup) {
    $log("<li>Q-" . str_pad($dup['quote_number'], 4, '0', STR_PAD_LEFT) . ": " . htmlspecialchars($dup['title']) . " (\$" . number_format($dup['total'], 2) . ") — removing ID " . $dup['remove_id'] . "</li>");
}
$log("</ul>");

// Soft-delete duplicates
$placeholders = implode(',', array_fill(0, count($quoteIds), '?'));

// Delete pq_quote_tiers for these quotes
dbFetch($db, "DELETE FROM pq_quote_tiers WHERE quote_id IN ($placeholders)", $quoteIds);
$log("<p>Deleted pq_quote_tiers for duplicate quotes.</p>");

// Delete pq_quote_specs for these quotes
dbFetch($db, "DELETE FROM pq_quote_specs WHERE quote_id IN ($placeholders)", $quoteIds);
$log("<p>Deleted pq_quote_specs for duplicate quotes.</p>");

// Delete quote_items for these quotes
dbFetch($db, "DELETE FROM quote_items WHERE quote_id IN ($placeholders)", $quoteIds);
$log("<p>Deleted quote_items for duplicate quotes.</p>");

// Delete quotes
dbFetch($db, "DELETE FROM quotes WHERE id IN ($placeholders)", $quoteIds);
$log("<p>Deleted <strong>" . count($quoteIds) . "</strong> duplicate quotes.</p>");

// Count remaining
$remaining = (int)(dbFetch($db, "SELECT COUNT(*) c FROM quotes WHERE deleted_at IS NULL")['c'] ?? 0);
$log("<p style='color:green'><strong>Done.</strong> $remaining quotes remaining in database.</p>");
if (!$isCli) $log("<p><a href='quotes.php'>View Quotes</a></p>");
