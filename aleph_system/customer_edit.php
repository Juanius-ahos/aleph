<?php
$pageTitle = 'Edit Customer';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid ID.'); header('Location: customers.php'); exit; }

$customer = dbFetch($db, "SELECT * FROM customers WHERE id=? AND deleted_at IS NULL", [$id]);
if (!$customer) { setFlash('error', 'Not found.'); header('Location: customers.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'company_name' => clean($_POST['company_name'] ?? ''),
        'contact_name' => clean($_POST['contact_name'] ?? ''),
        'email' => clean($_POST['email'] ?? ''),
        'phone' => clean($_POST['phone'] ?? ''),
        'mobile' => clean($_POST['mobile'] ?? ''),
        'website' => clean($_POST['website'] ?? ''),
        'address' => clean($_POST['address'] ?? ''),
        'city' => clean($_POST['city'] ?? ''),
        'country' => clean($_POST['country'] ?? 'Lebanon'),
        'customer_type' => in_array($_POST['customer_type'] ?? '', ['prospect','new','regular','vip','inactive']) ? $_POST['customer_type'] : 'new',
        'industry' => clean($_POST['industry'] ?? ''),
        'notes' => clean($_POST['notes'] ?? ''),
        'credit_limit' => (float)($_POST['credit_limit'] ?? 0),
        'tax_id' => clean($_POST['tax_id'] ?? ''),
    ];
    if (empty($data['company_name'])) {
        setFlash('error', 'Company name is required.');
        header("Location: customer_edit.php?id=$id");
        exit;
    }
    dbUpdate($db, 'customers', $data, 'id = ?', [$id]);
    logActivity('customers', 'update', 'customer', $id);
    setFlash('success', 'Customer updated.');
    header("Location: customer_view.php?id=$id");
    exit;
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <div class="page-title"><h1>Edit Customer</h1></div>
    <div class="page-actions"><a href="customer_view.php?id=<?= $id ?>" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div>
</div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Company Name *</label><input type="text" name="company_name" class="form-control" required value="<?= h($customer['company_name']) ?>"></div>
        <div class="form-group"><label>Contact Name</label><input type="text" name="contact_name" class="form-control" value="<?= h($customer['contact_name']) ?>"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?= h($customer['email']) ?>"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= h($customer['phone']) ?>"></div>
        <div class="form-group"><label>Mobile</label><input type="text" name="mobile" class="form-control" value="<?= h($customer['mobile']) ?>"></div>
        <div class="form-group"><label>Website</label><input type="url" name="website" class="form-control" value="<?= h($customer['website']) ?>"></div>
        <div class="form-group full-width"><label>Address</label><textarea name="address" class="form-control" rows="2"><?= h($customer['address']) ?></textarea></div>
        <div class="form-group"><label>City</label><input type="text" name="city" class="form-control" value="<?= h($customer['city']) ?>"></div>
        <div class="form-group"><label>Country</label><input type="text" name="country" class="form-control" value="<?= h($customer['country']) ?>"></div>
        <div class="form-group"><label>Type</label>
            <select name="customer_type" class="form-control">
                <?php foreach (['prospect','new','regular','vip','inactive'] as $t): ?>
                    <option value="<?= $t ?>" <?= $customer['customer_type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Industry</label><input type="text" name="industry" class="form-control" value="<?= h($customer['industry']) ?>"></div>
        <div class="form-group"><label>Credit Limit</label><input type="number" name="credit_limit" class="form-control" step="0.01" value="<?= h($customer['credit_limit']) ?>"></div>
        <div class="form-group"><label>Tax ID</label><input type="text" name="tax_id" class="form-control" value="<?= h($customer['tax_id']) ?>"></div>
        <div class="form-group full-width"><label>Notes</label><textarea name="notes" class="form-control" rows="3"><?= h($customer['notes']) ?></textarea></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Changes</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
