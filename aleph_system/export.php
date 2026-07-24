<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }

$db = getDB();
$type = clean($_GET['type'] ?? '');

if (!in_array($type, ['customers','quotes','jobs','invoices','payments','materials'])) {
    http_response_code(400);
    echo 'Invalid export type';
    exit;
}

switch ($type) {
    case 'customers':
        $columns = ['ID','Company Name','Type','Industry','Email','Phone','City','Country'];
        $rows = dbFetchAll($db, "SELECT id, company_name, customer_type, industry, email, phone, city, country FROM customers WHERE deleted_at IS NULL ORDER BY company_name");
        break;
    case 'quotes':
        $columns = ['ID','Number','Customer','Title','Total','Status','Created','Valid Until'];
        $rows = dbFetchAll($db, "SELECT q.id, q.quote_number, c.company_name, q.title, q.total, q.status, q.created_at, q.valid_until FROM quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE q.deleted_at IS NULL ORDER BY q.created_at DESC");
        break;
    case 'jobs':
        $columns = ['ID','Number','Customer','Title','Status','Stage','Due Date','Created'];
        $rows = dbFetchAll($db, "SELECT j.id, j.job_number, c.company_name, j.title, j.status, j.stage, j.estimated_delivery as due_date, j.created_at FROM jobs j LEFT JOIN customers c ON j.customer_id=c.id WHERE j.deleted_at IS NULL ORDER BY j.created_at DESC");
        break;
    case 'invoices':
        $columns = ['ID','Number','Customer','Total','Balance Due','Status','Due Date','Created'];
        $rows = dbFetchAll($db, "SELECT i.id, i.invoice_number, c.company_name, i.total, i.balance_due, i.status, i.due_date, i.created_at FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id WHERE i.deleted_at IS NULL ORDER BY i.created_at DESC");
        break;
    case 'payments':
        $columns = ['ID','Invoice','Amount','Method','Date'];
        $rows = dbFetchAll($db, "SELECT id, invoice_id, amount, payment_method, payment_date FROM payments WHERE voided = 0 ORDER BY payment_date DESC");
        break;
    case 'materials':
        $columns = ['ID','Name','Category','Stock','Min Stock','Unit Cost'];
        $rows = dbFetchAll($db, "SELECT id, name, category, stock_qty, min_stock, unit_cost FROM materials WHERE active=1 ORDER BY name");
        break;
}

exportCsv($type . '_' . date('Y-m-d') . '.csv', $columns, $rows ?? []);
