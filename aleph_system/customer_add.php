<?php
$pageTitle = 'Add Customer';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
requireLogin();

$db = getDB();
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
        'created_by' => currentUserId(),
    ];

    if (empty($data['company_name'])) {
        setFlash('error', 'Company name is required.');
        header('Location: customer_add.php');
        exit;
    }

    $customerId = dbInsert($db, 'customers', $data);
    if ($customerId) {
        logActivity('customers', 'create', 'customer', $customerId, "Created customer: {$data['company_name']}");
        setFlash('success', 'Customer created successfully.');
        header('Location: customer_view.php?id=' . $customerId);
    } else {
        setFlash('error', 'Failed to create customer.');
        header('Location: customer_add.php');
    }
    exit;
}

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1>Add Customer</h1>
        <p class="page-subtitle">Create a new customer record</p>
    </div>
    <div class="page-actions">
        <a href="customers.php" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <form method="POST" class="form-grid">
        <?= csrfField() ?>

        <div class="form-group">
            <label>Company Name <span class="required">*</span></label>
            <input type="text" name="company_name" class="form-control" required value="<?= h($_POST['company_name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Contact Name</label>
            <input type="text" name="contact_name" class="form-control" value="<?= h($_POST['contact_name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Mobile</label>
            <input type="text" name="mobile" class="form-control" value="<?= h($_POST['mobile'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Website</label>
            <input type="url" name="website" class="form-control" value="<?= h($_POST['website'] ?? '') ?>">
        </div>

        <div class="form-group full-width">
            <label>Address</label>
            <textarea name="address" class="form-control" rows="2"><?= h($_POST['address'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label>City</label>
            <input type="text" name="city" class="form-control" value="<?= h($_POST['city'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Country</label>
            <input type="text" name="country" class="form-control" value="<?= h($_POST['country'] ?? 'Lebanon') ?>">
        </div>

        <div class="form-group">
            <label>Customer Type</label>
            <select name="customer_type" class="form-control">
                <option value="prospect">Prospect</option>
                <option value="new" selected>New</option>
                <option value="regular">Regular</option>
                <option value="vip">VIP</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="form-group">
            <label>Industry</label>
            <input type="text" name="industry" class="form-control" value="<?= h($_POST['industry'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Credit Limit</label>
            <input type="number" name="credit_limit" class="form-control" step="0.01" min="0" value="<?= h($_POST['credit_limit'] ?? '0') ?>">
        </div>

        <div class="form-group">
            <label>Tax ID</label>
            <input type="text" name="tax_id" class="form-control" value="<?= h($_POST['tax_id'] ?? '') ?>">
        </div>

        <div class="form-group full-width">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3"><?= h($_POST['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Create Customer</button>
            <a href="customers.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
