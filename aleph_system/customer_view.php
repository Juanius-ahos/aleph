<?php
$pageTitle = 'Customer Details';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid customer ID.'); header('Location: customers.php'); exit; }

$customer = dbFetch($db, "SELECT * FROM customers WHERE id=? AND deleted_at IS NULL", [$id]);
if (!$customer) { setFlash('error', 'Customer not found.'); header('Location: customers.php'); exit; }

$contacts = dbFetchAll($db, "SELECT * FROM customer_contacts WHERE customer_id=? AND deleted_at IS NULL ORDER BY is_primary DESC, first_name", [$id]);
$notes = dbFetchAll($db, "SELECT n.*, u.full_name as author FROM customer_notes n LEFT JOIN users u ON n.created_by=u.id WHERE n.customer_id=? AND n.deleted_at IS NULL ORDER BY n.created_at DESC", [$id]);
$quotes = dbFetchAll($db, "SELECT * FROM quotes WHERE customer_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10", [$id]);
$jobs = dbFetchAll($db, "SELECT j.*, c.company_name FROM jobs j LEFT JOIN customers c ON j.customer_id=c.id WHERE j.customer_id=? AND j.deleted_at IS NULL ORDER BY j.created_at DESC LIMIT 10", [$id]);
$invoices = dbFetchAll($db, "SELECT i.*, c.company_name FROM invoices i LEFT JOIN customers c ON i.customer_id=c.id WHERE i.customer_id=? AND i.deleted_at IS NULL ORDER BY i.created_at DESC LIMIT 10", [$id]);
$followups = dbFetchAll($db, "SELECT f.*, u.full_name as assigned_name FROM followups f LEFT JOIN users u ON f.assigned_to=u.id WHERE f.customer_id=? ORDER BY f.due_date ASC LIMIT 10", [$id]);

$totalRevenue = (float)(dbFetch($db, "SELECT COALESCE(SUM(p.amount),0) as total FROM payments p JOIN invoices i ON p.invoice_id=i.id WHERE i.customer_id=? AND p.voided=0", [$id])['total'] ?? 0);
$totalOutstanding = (float)(dbFetch($db, "SELECT COALESCE(SUM(balance_due),0) as total FROM invoices WHERE customer_id=? AND status IN ('sent','partial','overdue') AND deleted_at IS NULL", [$id])['total'] ?? 0);

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><?= h($customer['company_name']) ?></h1>
        <p class="page-subtitle"><?= h($customer['contact_name'] ?? '') ?></p>
    </div>
    <div class="page-actions">
        <a href="customer_edit.php?id=<?= $id ?>" class="btn btn-primary"><i data-lucide="edit"></i> Edit</a>
        <a href="customers.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3>Contact Information</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value"><?= h($customer['email'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value"><?= h($customer['phone'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Mobile</span><span class="detail-value"><?= h($customer['mobile'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Website</span><span class="detail-value"><?= h($customer['website'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value"><?= h($customer['address'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">City</span><span class="detail-value"><?= h($customer['city'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Country</span><span class="detail-value"><?= h($customer['country'] ?? '—') ?></span></div>
        </div>
    </div>
    <div class="detail-card">
        <h3>Business Details</h3>
        <div class="detail-rows">
            <div class="detail-row"><span class="detail-label">Type</span><span class="detail-value"><span class="badge <?= getStatusBadgeClass($customer['customer_type']) ?>"><?= ucfirst(h($customer['customer_type'])) ?></span></span></div>
            <div class="detail-row"><span class="detail-label">Industry</span><span class="detail-value"><?= h($customer['industry'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Tax ID</span><span class="detail-value"><?= h($customer['tax_id'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-label">Credit Limit</span><span class="detail-value"><?= formatMoney($customer['credit_limit']) ?></span></div>
            <div class="detail-row"><span class="detail-label">Total Revenue</span><span class="detail-value" style="color:#059669;font-weight:600;"><?= formatMoney($totalRevenue) ?></span></div>
            <div class="detail-row"><span class="detail-label">Outstanding</span><span class="detail-value" style="color:#dc2626;font-weight:600;"><?= formatMoney($totalOutstanding) ?></span></div>
        </div>
    </div>
</div>

<div class="tabs">
    <div class="tabs-nav">
        <button class="tab-btn active" data-tab="contacts">Contacts (<?= count($contacts) ?>)</button>
        <button class="tab-btn" data-tab="quotes">Quotes (<?= count($quotes) ?>)</button>
        <button class="tab-btn" data-tab="jobs">Jobs (<?= count($jobs) ?>)</button>
        <button class="tab-btn" data-tab="invoices">Invoices (<?= count($invoices) ?>)</button>
        <button class="tab-btn" data-tab="followups">Follow-ups (<?= count($followups) ?>)</button>
        <button class="tab-btn" data-tab="notes">Notes (<?= count($notes) ?>)</button>
    </div>

    <div class="tab-content active" id="tab-contacts">
        <div class="tab-header">
            <a href="customer_contact_add.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Contact</a>
        </div>
        <?php if (empty($contacts)): ?>
            <div class="empty-state">No contacts</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Job Title</th><th>Primary</th></tr></thead>
                    <tbody>
                        <?php foreach ($contacts as $c): ?>
                        <tr>
                            <td><?= h($c['first_name'].' '.$c['last_name']) ?></td>
                            <td><?= h($c['email'] ?? '—') ?></td>
                            <td><?= h($c['phone'] ?? '—') ?></td>
                            <td><?= h($c['job_title'] ?? '—') ?></td>
                            <td><?= $c['is_primary'] ? '<span class="badge badge-success">Yes</span>' : 'No' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-quotes">
        <div class="tab-header">
            <a href="quote_add.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Quote</a>
        </div>
        <?php if (empty($quotes)): ?>
            <div class="empty-state">No quotes</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Number</th><th>Title</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($quotes as $q): ?>
                        <tr>
                            <td><a href="quote_view.php?id=<?= $q['id'] ?>" class="table-link">#<?= str_pad($q['quote_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                            <td><?= h($q['title']) ?></td>
                            <td><?= formatMoney($q['total']) ?></td>
                            <td><span class="badge <?= getStatusBadgeClass($q['status']) ?>"><?= ucfirst(h($q['status'])) ?></span></td>
                            <td><?= formatDate($q['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-jobs">
        <div class="tab-header">
            <a href="job_add.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Job</a>
        </div>
        <?php if (empty($jobs)): ?>
            <div class="empty-state">No jobs</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Number</th><th>Title</th><th>Stage</th><th>Status</th><th>Due Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($jobs as $j): ?>
                        <tr>
                            <td><a href="job_view.php?id=<?= $j['id'] ?>" class="table-link">#<?= str_pad($j['job_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                            <td><?= h($j['title']) ?></td>
                            <td><span class="badge badge-info"><?= getStageLabel($j['stage']) ?></span></td>
                            <td><span class="badge <?= getStatusBadgeClass($j['status']) ?>"><?= ucfirst(h($j['status'])) ?></span></td>
                            <td><?= $j['due_date'] ? formatDate($j['due_date']) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-invoices">
        <div class="tab-header">
            <a href="invoice_add.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Invoice</a>
        </div>
        <?php if (empty($invoices)): ?>
            <div class="empty-state">No invoices</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Number</th><th>Total</th><th>Balance</th><th>Status</th><th>Due Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($invoices as $i): ?>
                        <tr>
                            <td><a href="invoice_view.php?id=<?= $i['id'] ?>" class="table-link">#<?= str_pad($i['invoice_number'], 4, '0', STR_PAD_LEFT) ?></a></td>
                            <td><?= formatMoney($i['total']) ?></td>
                            <td><?= formatMoney($i['balance_due']) ?></td>
                            <td><span class="badge <?= getStatusBadgeClass($i['status']) ?>"><?= ucfirst(h($i['status'])) ?></span></td>
                            <td><?= $i['due_date'] ? formatDate($i['due_date']) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-followups">
        <div class="tab-header">
            <a href="followup_add.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Follow-up</a>
        </div>
        <?php if (empty($followups)): ?>
            <div class="empty-state">No follow-ups</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Title</th><th>Type</th><th>Due Date</th><th>Status</th><th>Assigned</th></tr></thead>
                    <tbody>
                        <?php foreach ($followups as $f): ?>
                        <tr>
                            <td><?= h($f['task_description']) ?></td>
                            <td><?= ucfirst(h($f['followup_type'])) ?></td>
                            <td><?= $f['due_date'] ? formatDate($f['due_date']) : '—' ?></td>
                            <td><span class="badge <?= getStatusBadgeClass($f['status']) ?>"><?= ucfirst(h($f['status'])) ?></span></td>
                            <td><?= h($f['assigned_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-notes">
        <div class="tab-header">
            <form method="POST" action="customer_note_add.php" style="display:flex;gap:8px;flex:1;">
                <input type="hidden" name="_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="customer_id" value="<?= $id ?>">
                <textarea name="note" class="form-control" placeholder="Add a note..." rows="2" required style="flex:1;"></textarea>
                <button type="submit" class="btn btn-primary"><i data-lucide="plus"></i> Add</button>
            </form>
        </div>
        <?php if (empty($notes)): ?>
            <div class="empty-state">No notes</div>
        <?php else: ?>
            <?php foreach ($notes as $n): ?>
            <div class="note-item">
                <div class="note-meta">
                    <span class="note-author"><?= h($n['author'] ?? 'Unknown') ?></span>
                    <span class="note-date"><?= formatDateTime($n['created_at']) ?></span>
                </div>
                <div class="note-content"><?= nl2br(h($n['note'])) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
        btn.classList.add('active');
        var tab = document.getElementById('tab-' + btn.dataset.tab);
        if (tab) tab.classList.add('active');
    });
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
