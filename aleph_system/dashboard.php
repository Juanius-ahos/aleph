<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$db = getDB();
$cur = dbFetch($db, "SELECT setting_value v FROM settings WHERE setting_key IN ('pq_currency_symbol','currency_symbol') ORDER BY setting_key='pq_currency_symbol' DESC LIMIT 1")['v'] ?? '$';

$stat = function($sql, $p = []) use ($db) { return (int)(dbFetch($db, $sql, $p)['c'] ?? 0); };
$customers   = $stat("SELECT COUNT(*) c FROM customers WHERE deleted_at IS NULL");
$totalQuotes = $stat("SELECT COUNT(*) c FROM quotes WHERE deleted_at IS NULL");
$draft       = $stat("SELECT COUNT(*) c FROM quotes WHERE status='draft' AND deleted_at IS NULL");
$sent        = $stat("SELECT COUNT(*) c FROM quotes WHERE status='sent' AND deleted_at IS NULL");
$accepted    = $stat("SELECT COUNT(*) c FROM quotes WHERE status='accepted' AND deleted_at IS NULL");
$acceptedVal = (float)(dbFetch($db, "SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status='accepted' AND deleted_at IS NULL")['v'] ?? 0);
$monthQuotes = $stat("SELECT COUNT(*) c FROM quotes WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND deleted_at IS NULL");

$recent = dbFetchAll($db, "SELECT q.id,q.quote_number,q.title,q.status,q.total,q.created_at,c.company_name
    FROM quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE q.deleted_at IS NULL ORDER BY q.created_at DESC LIMIT 8");

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1>Welcome back<?= $currentUser && !empty($currentUser['full_name']) ? ', ' . h(explode(' ', $currentUser['full_name'])[0]) : '' ?></h1>
        <p class="page-subtitle">Aleph Printing &amp; Graphics</p>
    </div>
    <div class="page-actions">
        <a href="quote_add.php" class="btn btn-primary"><i data-lucide="plus"></i> New Quote</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon orange"><i data-lucide="file-text"></i></div>
        <div>
            <div class="stat-number" data-count="<?= $totalQuotes ?>"><?= $totalQuotes ?></div>
            <div class="stat-label">Total Quotes</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i data-lucide="users"></i></div>
        <div>
            <div class="stat-number" data-count="<?= $customers ?>"><?= $customers ?></div>
            <div class="stat-label">Customers</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i data-lucide="check-circle"></i></div>
        <div>
            <div class="stat-number" data-count="<?= $accepted ?>"><?= $accepted ?></div>
            <div class="stat-label">Accepted · <?= h($cur) . number_format($acceptedVal, 0) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i data-lucide="calendar"></i></div>
        <div>
            <div class="stat-number" data-count="<?= $monthQuotes ?>"><?= $monthQuotes ?></div>
            <div class="stat-label">This Month</div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Recent Quotes</h3>
            <a href="quotes.php" class="btn-link" style="font-size:12px">View all</a>
        </div>
        <div class="card-content" style="padding:0">
            <div class="table-responsive" style="border:none;border-radius:0">
                <table class="data-table">
                    <thead>
                        <tr><th>#</th><th>Customer</th><th>Title</th><th>Status</th><th>Total</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent)): ?>
                            <tr>
                                <td colspan="6" style="padding:0">
                                    <div class="empty-state">
                                        <div class="empty-state-heading">No quotes yet</div>
                                        <div class="empty-state-desc">Create your first quote to get started.</div>
                                        <a href="quote_add.php" class="btn btn-primary"><i data-lucide="plus"></i> Create Quote</a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach ($recent as $q): ?>
                            <tr onclick="location.href='quote_view.php?id=<?= $q['id'] ?>'" style="cursor:pointer">
                                <td class="table-link">Q-<?= str_pad($q['quote_number'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td><?= h($q['company_name'] ?? 'Walk-in') ?></td>
                                <td><?= h($q['title']) ?></td>
                                <td><span class="badge <?= getStatusBadgeClass($q['status']) ?>"><?= ucfirst(h($q['status'])) ?></span></td>
                                <td style="font-weight:600"><?= h($cur) . number_format($q['total'], 2) ?></td>
                                <td class="text-muted text-sm"><?= formatDate($q['created_at']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:14px">
        <div class="dashboard-card">
            <div class="card-header">
                <h3>Pipeline</h3>
            </div>
            <div class="card-content">
                <div class="detail-rows">
                    <div class="detail-row">
                        <span class="detail-label"><span class="badge badge-secondary">Draft</span></span>
                        <span class="detail-value"><?= $draft ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><span class="badge badge-info">Sent</span></span>
                        <span class="detail-value"><?= $sent ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><span class="badge badge-success">Accepted</span></span>
                        <span class="detail-value"><?= $accepted ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (currentUserRole() === 'admin'): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="card-content" style="display:flex;flex-direction:column;gap:8px">
                <a href="quote_add.php" class="btn btn-primary btn-block"><i data-lucide="calculator"></i> New Quote</a>
                <a href="pq_products.php" class="btn btn-secondary btn-block"><i data-lucide="layout-grid"></i> Product Presets</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
if(typeof Chart!=='undefined'){
    Chart.defaults.font.family="'Open Sans',-apple-system,BlinkMacSystemFont,sans-serif";
    Chart.defaults.font.size=12;
    Chart.defaults.color='#6b7280';
    Chart.defaults.plugins.legend.labels.usePointStyle=true;
    Chart.defaults.plugins.legend.labels.padding=16;
    Chart.defaults.elements.bar.borderRadius=6;
    Chart.defaults.elements.line.tension=0.4;
    Chart.defaults.scale.grid={color:'rgba(0,0,0,.04)',drawBorder:false};
    Chart.defaults.scale.ticks={padding:8};
}

document.querySelectorAll('.stat-number[data-count]').forEach(function(el){
    var target=parseInt(el.getAttribute('data-count'),10)||0;
    if(target===0)return;
    el.textContent='0';
    var start=0;
    var dur=600;
    var startTime=null;
    function step(ts){
        if(!startTime)startTime=ts;
        var p=Math.min((ts-startTime)/dur,1);
        var ease=1-Math.pow(1-p,3);
        el.textContent=Math.round(ease*target);
        if(p<1)requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
});
</script>
