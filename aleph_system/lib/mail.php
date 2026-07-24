<?php
/**
 * Aleph ERP v6 — Email Service
 * PHPMailer wrapper for sending emails
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// =====================================================
// Email Configuration
// =====================================================

function getMailConfig() {
    $db = getDB();
    $settings = [];
    $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_name', 'smtp_from_email'];
    
    foreach ($keys as $key) {
        $row = dbFetch($db, "SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        $settings[$key] = $row['setting_value'] ?? '';
    }
    
    return $settings;
}

// =====================================================
// Send Email (simplified — uses PHP mail() as fallback)
// =====================================================

function sendEmail($to, $subject, $htmlBody, $options = []) {
    $config = getMailConfig();
    
    $fromName = $options['from_name'] ?? ($config['smtp_from_name'] ?? APP_NAME);
    $fromEmail = $options['from_email'] ?? ($config['smtp_from_email'] ?? 'noreply@aleph.com.lb');
    
    // Build headers
    $headers = [];
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-type: text/html; charset=UTF-8";
    $headers[] = "From: {$fromName} <{$fromEmail}>";
    $headers[] = "Reply-To: {$fromEmail}";
    $headers[] = "X-Mailer: Aleph ERP v" . APP_VERSION;
    
    // Try SMTP first if configured
    if (!empty($config['smtp_host'])) {
        return sendViaSmtp($to, $subject, $htmlBody, $config, $headers);
    }
    
    // Fallback to PHP mail()
    $result = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    
    // Log
    logEmail(null, null, $to, $subject, $htmlBody, $result ? 'sent' : 'failed');
    
    return $result;
}

// =====================================================
// SMTP Sending (requires PHPMailer or similar)
// =====================================================

function sendViaSmtp($to, $subject, $htmlBody, $config, $headers) {
    // Check if PHPMailer is available
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return sendWithPhpMailer($to, $subject, $htmlBody, $config);
    }
    
    // Fallback to native SMTP via fsockopen
    return sendNativeSmtp($to, $subject, $htmlBody, $config);
}

function sendNativeSmtp($to, $subject, $htmlBody, $config) {
    $host = $config['smtp_host'];
    $port = (int)($config['smtp_port'] ?? 587);
    $user = $config['smtp_user'];
    $pass = $config['smtp_pass'];
    $fromEmail = $config['smtp_from_email'];
    $fromName = $config['smtp_from_name'];
    
    $errno = 0;
    $errstr = '';
    
    // Connect
    $fp = fsockopen("tcp://{$host}", $port, $errno, $errstr, 30);
    if (!$fp) {
        error_log("SMTP connection failed: $errno $errstr");
        return false;
    }
    
    // Read greeting
    $response = fgets($fp, 512);
    
    // EHLO
    fwrite($fp, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
    $response = fread($fp, 512);
    
    // STARTTLS if port 587
    if ($port === 587) {
        fwrite($fp, "STARTTLS\r\n");
        $response = fread($fp, 512);
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        fwrite($fp, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
        $response = fread($fp, 512);
    }
    
    // AUTH
    if (!empty($user)) {
        fwrite($fp, "AUTH LOGIN\r\n");
        fread($fp, 512);
        fwrite($fp, base64_encode($user) . "\r\n");
        fread($fp, 512);
        fwrite($fp, base64_encode($pass) . "\r\n");
        fread($fp, 512);
    }
    
    // MAIL FROM
    fwrite($fp, "MAIL FROM:<{$fromEmail}>\r\n");
    fread($fp, 512);
    
    // RCPT TO
    fwrite($fp, "RCPT TO:<{$to}>\r\n");
    fread($fp, 512);
    
    // DATA
    fwrite($fp, "DATA\r\n");
    fread($fp, 512);
    
    // Headers
    $data = "From: {$fromName} <{$fromEmail}>\r\n";
    $data .= "To: {$to}\r\n";
    $data .= "Subject: {$subject}\r\n";
    $data .= "MIME-Version: 1.0\r\n";
    $data .= "Content-type: text/html; charset=UTF-8\r\n";
    $data .= "X-Mailer: Aleph ERP v" . APP_VERSION . "\r\n";
    $data .= "\r\n";
    $data .= $htmlBody . "\r\n";
    $data .= ".\r\n";
    
    fwrite($fp, $data);
    $response = fread($fp, 512);
    
    // QUIT
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    
    $success = strpos($response, '250') !== false;
    logEmail(null, null, $to, $subject, $htmlBody, $success ? 'sent' : 'failed');
    
    return $success;
}

// =====================================================
// Email Template Renderer
// =====================================================

function renderEmailTemplate($templateName, $variables = []) {
    $db = getDB();
    $template = dbFetch($db, "SELECT * FROM email_templates WHERE name = ? AND active = 1", [$templateName]);
    
    if (!$template) {
        error_log("Email template not found: $templateName");
        return null;
    }
    
    $subject = $template['subject'];
    $body = $template['body'];
    
    // Replace variables
    foreach ($variables as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        $subject = str_replace($placeholder, $value, $subject);
        $body = str_replace($placeholder, $value, $body);
    }
    
    // Wrap in email template
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;max-width:600px;margin:0 auto;padding:20px;">';
    $html .= $body;
    $html .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">';
    $html .= '<p style="font-size:12px;color:#9ca3af;text-align:center;">Aleph Printing & Graphics — Street 41, Mekalles, Beirut — +961 1 685 354/5</p>';
    $html .= '</body></html>';
    
    return ['subject' => $subject, 'body' => $html];
}

// =====================================================
// Send Template Email
// =====================================================

function sendTemplateEmail($to, $templateName, $variables, $options = []) {
    $rendered = renderEmailTemplate($templateName, $variables);
    if (!$rendered) return false;
    
    $result = sendEmail($to, $rendered['subject'], $rendered['body'], $options);
    
    // Log email
    $db = getDB();
    logEmail($db, $options['entity_type'] ?? null, $options['entity_id'] ?? null, $to, $rendered['subject'], $rendered['body'], $result ? 'sent' : 'failed');
    
    return $result;
}

// =====================================================
// Email Logging
// =====================================================

function logEmail($db, $entityType, $entityId, $to, $subject, $body, $status = 'sent', $errorMessage = null) {
    if (!$db) $db = getDB();
    try {
        dbInsert($db, 'email_logs', [
            'from_email' => 'noreply@aleph.com.lb',
            'to_email' => $to,
            'subject' => $subject,
            'body' => substr($body, 0, 10000),
            'status' => $status,
            'error_message' => $errorMessage,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'sent_by' => currentUserId() ?: null,
        ]);
    } catch (Exception $e) {
        error_log("Email log error: " . $e->getMessage());
    }
}

// =====================================================
// Convenience Functions
// =====================================================

function sendQuoteEmail($quoteId) {
    $db = getDB();
    $quote = dbFetch($db, "
        SELECT q.*, c.company_name, c.email as customer_email, u.full_name as salesperson
        FROM quotes q
        JOIN customers c ON c.id = q.customer_id
        JOIN users u ON u.id = q.created_by
        WHERE q.id = ?
    ", [$quoteId]);
    
    if (!$quote || empty($quote['customer_email'])) return false;
    
    return sendTemplateEmail($quote['customer_email'], 'quote_send', [
        'quote_number' => 'Q-' . str_pad($quote['quote_number'], 4, '0', STR_PAD_LEFT),
        'customer_name' => $quote['company_name'],
        'quote_title' => $quote['title'],
        'total' => number_format($quote['amount'], 2),
        'currency' => '$',
        'valid_until' => formatDate($quote['valid_until']),
    ], ['entity_type' => 'quote', 'entity_id' => $quoteId]);
}

function sendInvoiceEmail($invoiceId) {
    $db = getDB();
    $invoice = dbFetch($db, "
        SELECT i.*, c.company_name, c.email as customer_email, j.title as job_title
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        LEFT JOIN jobs j ON j.id = i.job_id
        WHERE i.id = ?
    ", [$invoiceId]);
    
    if (!$invoice || empty($invoice['customer_email'])) return false;
    
    return sendTemplateEmail($invoice['customer_email'], 'invoice_send', [
        'invoice_number' => 'INV-' . str_pad($invoice['invoice_number'], 4, '0', STR_PAD_LEFT),
        'customer_name' => $invoice['company_name'],
        'job_title' => $invoice['job_title'] ?? 'General',
        'balance_due' => number_format($invoice['balance_due'], 2),
        'currency' => '$',
        'due_date' => formatDate($invoice['due_date']),
        'payment_terms' => '30',
    ], ['entity_type' => 'invoice', 'entity_id' => $invoiceId]);
}

function sendPaymentReceiptEmail($paymentId) {
    $db = getDB();
    $payment = dbFetch($db, "
        SELECT p.*, i.invoice_number, c.company_name, c.email as customer_email
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        JOIN customers c ON c.id = i.customer_id
        WHERE p.id = ?
    ", [$paymentId]);
    
    if (!$payment || empty($payment['customer_email'])) return false;
    
    return sendTemplateEmail($payment['customer_email'], 'payment_receipt', [
        'invoice_number' => 'INV-' . str_pad($payment['invoice_number'], 4, '0', STR_PAD_LEFT),
        'customer_name' => $payment['company_name'],
        'payment_amount' => number_format($payment['amount'], 2),
        'balance_due' => number_format($payment['balance_due'] ?? 0, 2),
        'currency' => '$',
    ], ['entity_type' => 'payment', 'entity_id' => $paymentId]);
}

function sendPoEmail($poId) {
    $db = getDB();
    $po = dbFetch($db, "
        SELECT po.*, s.company_name as supplier_name, s.email as supplier_email
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ?
    ", [$poId]);
    
    if (!$po || empty($po['supplier_email'])) return false;
    
    return sendTemplateEmail($po['supplier_email'], 'po_send', [
        'po_number' => 'PO-' . str_pad($po['po_number'], 4, '0', STR_PAD_LEFT),
        'supplier_name' => $po['supplier_name'],
        'total' => number_format($po['total'], 2),
        'currency' => '$',
        'expected_date' => formatDate($po['expected_date']),
    ], ['entity_type' => 'purchase_order', 'entity_id' => $poId]);
}
