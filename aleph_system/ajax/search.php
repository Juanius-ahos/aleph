<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || empty($_GET['q'])) { http_response_code(400); echo json_encode(['error'=>'GET parameter "q" required']); exit; }

$db = getDB();
$q = '%' . $_GET['q'] . '%';
$results = ['customers'=>[],'quotes'=>[],'jobs'=>[],'invoices'=>[],'suppliers'=>[],'materials'=>[]];

$results['customers'] = dbFetchAll($db, "SELECT id, company_name as title, email as subtitle, 'customer' as type FROM customers WHERE (company_name LIKE ? OR email LIKE ? OR city LIKE ?) AND deleted_at IS NULL ORDER BY company_name LIMIT 5", [$q,$q,$q]);
$results['quotes'] = dbFetchAll($db, "SELECT q.id, CONCAT('Q-', LPAD(q.quote_number,4,'0')) as title, q.status as subtitle, 'quote' as type FROM quotes q WHERE (q.quote_number LIKE ? OR q.title LIKE ? OR q.status LIKE ?) AND q.deleted_at IS NULL ORDER BY q.created_at DESC LIMIT 5", [$q,$q,$q]);
$results['jobs'] = dbFetchAll($db, "SELECT j.id, CONCAT('J-', LPAD(j.job_number,4,'0')) as title, j.status as subtitle, 'job' as type FROM jobs j WHERE (j.job_number LIKE ? OR j.title LIKE ? OR j.status LIKE ?) AND j.deleted_at IS NULL ORDER BY j.created_at DESC LIMIT 5", [$q,$q,$q]);
$results['invoices'] = dbFetchAll($db, "SELECT i.id, CONCAT('INV-', LPAD(i.invoice_number,4,'0')) as title, i.status as subtitle, 'invoice' as type FROM invoices i WHERE (i.invoice_number LIKE ? OR i.status LIKE ?) AND i.deleted_at IS NULL ORDER BY i.created_at DESC LIMIT 5", [$q,$q]);
$results['suppliers'] = dbFetchAll($db, "SELECT id, company_name as title, email as subtitle, 'supplier' as type FROM suppliers WHERE (company_name LIKE ? OR email LIKE ? OR contact_name LIKE ?) ORDER BY company_name LIMIT 5", [$q,$q,$q]);
$results['materials'] = dbFetchAll($db, "SELECT id, name as title, CONCAT(stock_qty, ' in stock') as subtitle, 'material' as type FROM materials WHERE (name LIKE ? OR category LIKE ?) AND active=1 ORDER BY name LIMIT 5", [$q,$q]);

echo json_encode(['results' => $results]);
