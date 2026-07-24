<?php
/**
 * Aleph ERP v7 — Helper Functions (supplement)
 * Core helpers are in config.php. This file adds extras.
 */

function getPriorityBadgeClass($priority) {
    $map = ['low' => 'badge-secondary', 'normal' => 'badge-info', 'high' => 'badge-warning', 'urgent' => 'badge-danger'];
    return $map[$priority] ?? 'badge-secondary';
}

function getStockStatus($qty, $minStock) {
    if ($qty <= 0) return 'out';
    if ($qty <= $minStock) return 'low';
    return 'ok';
}

function getStockStatusClass($status) {
    $map = ['out' => 'badge-danger', 'low' => 'badge-warning', 'ok' => 'badge-success'];
    return $map[$status] ?? 'badge-success';
}

function getStageLabels() {
    return [
        'design' => 'Design', 'prepress' => 'Prepress', 'printing' => 'Printing',
        'finishing' => 'Finishing', 'qc' => 'Quality Control', 'packaging' => 'Packaging',
        'delivered' => 'Delivered', 'completed' => 'Completed',
    ];
}

function getStageProgress($stage) {
    $index = getStageIndex($stage);
    $total = count(getStageLabels());
    return round(($index / ($total - 1)) * 100);
}

function validatePhone($phone) {
    return preg_match('/^[\+]?[\d\s\-\(\)]{7,30}$/', $phone);
}

function validateUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function arrayGroup($array, $key) {
    $grouped = [];
    foreach ($array as $item) $grouped[$item[$key]][] = $item;
    return $grouped;
}

function arrayPluck($array, $key) {
    return array_map(fn($item) => $item[$key], $array);
}

function currentUrl() {
    return $_SERVER['REQUEST_URI'] ?? '/';
}

function urlWithParams($baseUrl, $params) {
    return $baseUrl . '?' . http_build_query($params);
}

function isActivePage($page) {
    return basename($_SERVER['PHP_SELF'], '.php') === $page;
}

function renderPagination($pagination, $baseUrl, $params = []) {
    if ($pagination['total_pages'] <= 1) return '';

    $html = '<nav class="pagination">';
    $html .= '<span class="pagination-info">Showing ' . (($pagination['page']-1) * $pagination['per_page'] + 1) . '-' . min($pagination['page'] * $pagination['per_page'], $pagination['total']) . ' of ' . $pagination['total'] . '</span>';
    $html .= '<div class="pagination-links">';

    if ($pagination['has_prev']) {
        $p = $params; $p['page'] = $pagination['page'] - 1;
        $html .= '<a href="' . h(urlWithParams($baseUrl, $p)) . '" class="btn btn-sm btn-outline">&larr; Prev</a>';
    }

    $start = max(1, $pagination['page'] - 2);
    $end = min($pagination['total_pages'], $pagination['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $p = $params; $p['page'] = $i;
        $active = $i === $pagination['page'] ? ' btn-primary' : ' btn-outline';
        $html .= '<a href="' . h(urlWithParams($baseUrl, $p)) . '" class="btn btn-sm' . $active . '">' . $i . '</a>';
    }

    if ($pagination['has_next']) {
        $p = $params; $p['page'] = $pagination['page'] + 1;
        $html .= '<a href="' . h(urlWithParams($baseUrl, $p)) . '" class="btn btn-sm btn-outline">Next &rarr;</a>';
    }

    $html .= '</div></nav>';
    return $html;
}

function exportCsv($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, $headers);
    foreach ($rows as $row) fputcsv($output, $row);
    fclose($output);
    exit();
}

function getActivityDescription($activity) {
    $entity = entityName($activity['entity_type'] ?? '');
    $action = $activity['action'] ?? '';
    $verbs = [
        'create' => "created a new $entity", 'update' => "updated $entity",
        'delete' => "deleted $entity", 'status_change' => "changed $entity status",
        'login' => "logged in", 'logout' => "logged out",
        'password_change' => "changed their password",
        'enable_2fa' => "enabled two-factor authentication",
        'disable_2fa' => "disabled two-factor authentication",
    ];
    return $verbs[$action] ?? "$action on $entity";
}

function entityName($type) {
    $names = [
        'customer' => 'Customer', 'quote' => 'Quote', 'job' => 'Job',
        'invoice' => 'Invoice', 'credit_note' => 'Credit Note',
        'supplier' => 'Supplier', 'purchase_order' => 'Purchase Order',
        'material' => 'Material', 'payment' => 'Payment',
        'followup' => 'Follow-up', 'user' => 'User',
    ];
    return $names[$type] ?? ucfirst(str_replace('_', ' ', $type));
}
