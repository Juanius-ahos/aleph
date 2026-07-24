<?php
$pageTitle = 'Aging Report';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/header.php';

$db = getDB();
$today = date('Y-m-d');

$aging = dbFetchAll($db, "SELECT i.id, i.invoice_number, i.total, i.balance_due, i.due_date, c.company_name, DATEDIFF(?, i.due_date) as days_overdue FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id WHERE i.status NOT IN ('paid','cancelled') AND i.due_date < ? AND i.deleted_at IS NULL ORDER BY days_overdue DESC", [$today, $today]);

$brackets = ['0-30' => ['min'=>1,'max'=>30,'total'=>0], '31-60' => ['min'=>31,'max'=>60,'total'=>0], '61-90' => ['min'=>61,'max'=>90,'total'=>0], '90+' => ['min'=>91,'max'=>9999,'total'=>0]];
foreach ($aging as $inv) {
    $days = $inv['days_overdue'];
    if ($days <= 30) $brackets['0-30']['total'] += $inv['balance_due'];
    elseif ($days <= 60) $brackets['31-60']['total'] += $inv['balance_due'];
    elseif ($days <= 90) $brackets['61-90']['total'] += $inv['balance_due'];
    else $brackets['90+']['total'] += $inv['balance_due'];
}
?>

<div class="page-header">
    <div class="page-title"><h1>Aging Report</h1></div>
    <div class="page-actions"><a href="reports.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>

<div class="stats-grid">
    <?php foreach ($brackets as $label => $b): ?>
    <div class="stat-card"><div class="stat-icon <?= $b['total']>0?'red':'green' ?>"><i data-lucide="clock"></i></div><div class="stat-info"><div class="stat-label"><?= h($label) ?> days</div><div class="stat-value"><?= formatMoney($b['total']) ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card" style="margin-top:20px;">
    <h3>Overdue Invoices</h3>
    <?php if (empty($aging)): ?>
        <div class="empty-state">No overdue invoices</div>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Invoice</th><th>Customer</th><th>Balance</th><th>Due Date</th><th>Days Overdue</th></tr></thead>
            <tbody>
                <?php foreach ($aging as $inv): ?>
                <tr>
                    <td><a href="invoice_view.php?id=<?= $inv['id'] ?>" class="table-link">#<?= str_pad($inv['invoice_number'],4,'0',STR_PAD_LEFT) ?></a></td>
                    <td><?= h($inv['company_name'] ?? 'N/A') ?></td>
                    <td style="font-weight:600;color:#dc2626;"><?= formatMoney($inv['balance_due']) ?></td>
                    <td><?= formatDate($inv['due_date']) ?></td>
                    <td><span class="badge badge-danger"><?= $inv['days_overdue'] ?> days</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
