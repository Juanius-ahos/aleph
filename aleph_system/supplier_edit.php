<?php
$pageTitle = 'Edit Supplier';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('error', 'Invalid ID.'); header('Location: suppliers.php'); exit; }

$supplier = dbFetch($db, "SELECT * FROM suppliers WHERE id=?", [$id]);
if (!$supplier) { setFlash('error', 'Not found.'); header('Location: suppliers.php'); exit; }

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
        'country' => clean($_POST['country'] ?? ''),
        'tax_id' => clean($_POST['tax_id'] ?? ''),
        'payment_terms' => clean($_POST['payment_terms'] ?? ''),
        'notes' => clean($_POST['notes'] ?? ''),
    ];
    dbUpdate($db, 'suppliers', $data, 'id = ?', [$id]);
    setFlash('success', 'Supplier updated.');
    header("Location: suppliers.php"); exit;
}

require_once __DIR__ . '/header.php';
?>
<div class="page-header"><div class="page-title"><h1>Edit Supplier</h1></div><div class="page-actions"><a href="suppliers.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a></div></div>
<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="form-group"><label>Company Name *</label><input type="text" name="company_name" class="form-control" required value="<?= h($supplier['company_name']) ?>"></div>
        <div class="form-group"><label>Contact Name</label><input type="text" name="contact_name" class="form-control" value="<?= h($supplier['contact_name']) ?>"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?= h($supplier['email']) ?>"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= h($supplier['phone']) ?>"></div>
        <div class="form-group"><label>Mobile</label><input type="text" name="mobile" class="form-control" value="<?= h($supplier['mobile']) ?>"></div>
        <div class="form-group"><label>Website</label><input type="url" name="website" class="form-control" value="<?= h($supplier['website']) ?>"></div>
        <div class="form-group full-width"><label>Address</label><textarea name="address" class="form-control" rows="2"><?= h($supplier['address']) ?></textarea></div>
        <div class="form-group"><label>City</label><input type="text" name="city" class="form-control" value="<?= h($supplier['city']) ?>"></div>
        <div class="form-group"><label>Country</label><input type="text" name="country" class="form-control" value="<?= h($supplier['country']) ?>"></div>
        <div class="form-group"><label>Tax ID</label><input type="text" name="tax_id" class="form-control" value="<?= h($supplier['tax_id']) ?>"></div>
        <div class="form-group"><label>Payment Terms</label><input type="text" name="payment_terms" class="form-control" value="<?= h($supplier['payment_terms']) ?>"></div>
        <div class="form-group full-width"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($supplier['notes']) ?></textarea></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
