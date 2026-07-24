<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_database') {
    requireLogin();
    if (!hasRole('admin')) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'messages' => [['type' => 'error', 'text' => 'Admin access required']]]);
        exit;
    }
    ob_start();
    header('Content-Type: application/json');

    $db = getDB();
    $messages = [];

    $tables = [
        "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `username` VARCHAR(50) NOT NULL,
            `email` VARCHAR(100) NOT NULL, `password_hash` VARCHAR(255) NOT NULL,
            `first_name` VARCHAR(100) DEFAULT NULL, `last_name` VARCHAR(100) DEFAULT NULL,
            `phone` VARCHAR(20) DEFAULT NULL, `role` VARCHAR(20) NOT NULL DEFAULT 'sales',
            `department` VARCHAR(50) DEFAULT NULL, `job_title` VARCHAR(100) DEFAULT NULL,
            `avatar` VARCHAR(255) DEFAULT NULL, `locale` VARCHAR(10) DEFAULT 'en',
            `timezone` VARCHAR(50) DEFAULT 'Asia/Beirut', `is_2fa_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `totp_secret` VARCHAR(255) DEFAULT NULL, `force_password_change` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT UNSIGNED NOT NULL DEFAULT 1, `last_login` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_username` (`username`), UNIQUE KEY `uq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `modules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `slug` VARCHAR(50) NOT NULL,
            `label` VARCHAR(100) NOT NULL, `category` VARCHAR(50) NOT NULL DEFAULT 'general',
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `user_modules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL,
            `module_id` INT UNSIGNED NOT NULL, `can_view` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `can_create` TINYINT UNSIGNED NOT NULL DEFAULT 0, `can_edit` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `can_delete` TINYINT UNSIGNED NOT NULL DEFAULT 0, `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_user_module` (`user_id`,`module_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `module_logs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `module_id` INT UNSIGNED NOT NULL,
            `entity_id` INT UNSIGNED NOT NULL, `user_id` INT UNSIGNED NOT NULL,
            `action` VARCHAR(20) NOT NULL, `old_values` JSON DEFAULT NULL,
            `new_values` JSON DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL, `message` TEXT DEFAULT NULL,
            `entity_type` VARCHAR(50) DEFAULT NULL, `entity_id` INT UNSIGNED DEFAULT NULL,
            `read_at` DATETIME DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), INDEX `idx_notif_user` (`user_id`,`read_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `company_name` VARCHAR(200) NOT NULL,
            `contact_name` VARCHAR(100) DEFAULT NULL, `email` VARCHAR(100) DEFAULT NULL,
            `phone` VARCHAR(30) DEFAULT NULL, `mobile` VARCHAR(30) DEFAULT NULL,
            `website` VARCHAR(200) DEFAULT NULL, `address` TEXT DEFAULT NULL,
            `city` VARCHAR(100) DEFAULT NULL, `country` VARCHAR(100) DEFAULT 'Lebanon',
            `customer_type` VARCHAR(20) NOT NULL DEFAULT 'new',
            `industry` VARCHAR(100) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
            `credit_limit` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `tax_id` VARCHAR(50) DEFAULT NULL,
            `currency` VARCHAR(3) DEFAULT 'USD', `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_cust_type` (`customer_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `products` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `sku` VARCHAR(100) DEFAULT NULL, `description` TEXT DEFAULT NULL,
            `unit` VARCHAR(20) DEFAULT 'each', `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `category` VARCHAR(100) DEFAULT NULL,
            `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_sku` (`sku`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `quotes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `quote_number` INT UNSIGNED NOT NULL,
            `customer_id` INT UNSIGNED DEFAULT NULL, `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
            `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount_type` VARCHAR(20) DEFAULT NULL, `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00, `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `valid_until` DATE DEFAULT NULL,
            `notes` TEXT DEFAULT NULL, `terms` TEXT DEFAULT NULL, `internal_notes` TEXT DEFAULT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL, `assigned_to` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_quote_number` (`quote_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `quote_items` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `quote_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED DEFAULT NULL, `description` VARCHAR(500) NOT NULL,
            `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1, `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `idx_qi_quote` (`quote_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `materials` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `sku` VARCHAR(100) DEFAULT NULL, `category` VARCHAR(100) DEFAULT NULL,
            `description` TEXT DEFAULT NULL, `unit` VARCHAR(20) DEFAULT 'kg',
            `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `stock_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `min_stock` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `suppliers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `company_name` VARCHAR(200) NOT NULL,
            `contact_name` VARCHAR(100) DEFAULT NULL, `email` VARCHAR(100) DEFAULT NULL,
            `phone` VARCHAR(30) DEFAULT NULL, `mobile` VARCHAR(30) DEFAULT NULL,
            `website` VARCHAR(200) DEFAULT NULL, `address` TEXT DEFAULT NULL,
            `city` VARCHAR(100) DEFAULT NULL, `country` VARCHAR(100) DEFAULT 'Lebanon',
            `tax_id` VARCHAR(50) DEFAULT NULL, `payment_terms` VARCHAR(100) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL, `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `purchase_orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `po_number` INT UNSIGNED NOT NULL,
            `supplier_id` INT UNSIGNED NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `order_date` DATE NOT NULL, `expected_date` DATE DEFAULT NULL,
            `received_date` DATE DEFAULT NULL, `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `notes` TEXT DEFAULT NULL, `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_po_number` (`po_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `purchase_order_items` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `po_id` INT UNSIGNED NOT NULL,
            `material_id` INT UNSIGNED DEFAULT NULL, `description` VARCHAR(500) NOT NULL,
            `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1, `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `qty_received` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`id`), KEY `idx_poi_po` (`po_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `jobs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `job_number` INT UNSIGNED NOT NULL,
            `quote_id` INT UNSIGNED DEFAULT NULL, `customer_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL, `description` TEXT DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `stage` VARCHAR(20) NOT NULL DEFAULT 'design',
            `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1, `due_date` DATE DEFAULT NULL,
            `estimated_hours` DECIMAL(6,2) DEFAULT NULL, `actual_hours` DECIMAL(6,2) DEFAULT NULL,
            `assigned_to` INT UNSIGNED DEFAULT NULL, `completed_at` DATETIME DEFAULT NULL,
            `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `selling_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `notes` TEXT DEFAULT NULL, `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_job_number` (`job_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `job_materials` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `job_id` INT UNSIGNED NOT NULL,
            `material_id` INT UNSIGNED NOT NULL, `quantity_used` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_jm_job` (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `job_stage_progress` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `job_id` INT UNSIGNED NOT NULL,
            `stage` VARCHAR(20) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `completion_percentage` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `assigned_to` INT UNSIGNED DEFAULT NULL, `started_at` DATETIME DEFAULT NULL,
            `completed_at` DATETIME DEFAULT NULL, `estimated_hours` DECIMAL(6,2) DEFAULT NULL,
            `actual_hours` DECIMAL(6,2) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_jsp` (`job_id`,`stage`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `invoices` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `invoice_number` INT UNSIGNED NOT NULL,
            `job_id` INT UNSIGNED DEFAULT NULL, `customer_id` INT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft', `invoice_date` DATE NOT NULL,
            `due_date` DATE DEFAULT NULL, `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount_type` VARCHAR(20) DEFAULT NULL, `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00, `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `balance_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `notes` TEXT DEFAULT NULL,
            `terms` TEXT DEFAULT NULL, `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_inv_number` (`invoice_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `invoice_items` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `invoice_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED DEFAULT NULL, `description` VARCHAR(500) NOT NULL,
            `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1, `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `idx_ii_inv` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `invoice_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(20) NOT NULL DEFAULT 'cash', `payment_date` DATE NOT NULL,
            `reference_number` VARCHAR(100) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
            `voided` TINYINT UNSIGNED NOT NULL DEFAULT 0, `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_pay_inv` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `credit_notes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `credit_note_number` INT UNSIGNED NOT NULL,
            `invoice_id` INT UNSIGNED DEFAULT NULL, `customer_id` INT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft', `credit_date` DATE NOT NULL,
            `reason` TEXT DEFAULT NULL, `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `applied_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_cn_number` (`credit_note_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `followups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(20) NOT NULL DEFAULT 'task', `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL, `due_date` DATETIME DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
            `assigned_to` INT UNSIGNED DEFAULT NULL, `created_by` INT UNSIGNED DEFAULT NULL,
            `completed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_fu_cust` (`customer_id`), KEY `idx_fu_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_profiles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL,
            `department` VARCHAR(100) DEFAULT NULL, `position` VARCHAR(100) DEFAULT NULL,
            `employment_type` VARCHAR(20) DEFAULT 'full_time', `hire_date` DATE DEFAULT NULL,
            `base_salary` DECIMAL(12,2) DEFAULT NULL, `currency` VARCHAR(3) DEFAULT 'USD',
            `payment_method` VARCHAR(10) DEFAULT 'bank', `bank_name` VARCHAR(100) DEFAULT NULL,
            `bank_account` VARCHAR(50) DEFAULT NULL, `tax_id` VARCHAR(50) DEFAULT NULL,
            `social_security` VARCHAR(50) DEFAULT NULL,
            `annual_leave_days` INT UNSIGNED NOT NULL DEFAULT 15,
            `sick_leave_days` INT UNSIGNED NOT NULL DEFAULT 10,
            `emergency_contact_name` VARCHAR(100) DEFAULT NULL,
            `emergency_contact_phone` VARCHAR(30) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_ep_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `activity_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED DEFAULT NULL,
            `module` VARCHAR(50) NOT NULL, `action` VARCHAR(50) NOT NULL,
            `entity_type` VARCHAR(50) DEFAULT NULL, `entity_id` INT UNSIGNED DEFAULT NULL,
            `description` TEXT DEFAULT NULL, `old_values` JSON DEFAULT NULL,
            `new_values` JSON DEFAULT NULL, `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_al_module` (`module`,`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT DEFAULT NULL, `setting_type` VARCHAR(20) NOT NULL DEFAULT 'string',
            `category` VARCHAR(50) NOT NULL DEFAULT 'general', `description` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `password_history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_ph_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `login_history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED DEFAULT NULL,
            `email` VARCHAR(100) NOT NULL, `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT DEFAULT NULL, `success` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `failure_reason` VARCHAR(100) DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_lh_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL, `key_hash` VARCHAR(255) NOT NULL,
            `key_prefix` VARCHAR(10) NOT NULL, `scopes` JSON DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL, `last_used_at` DATETIME DEFAULT NULL,
            `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_key_hash` (`key_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `customer_contacts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` INT UNSIGNED NOT NULL,
            `first_name` VARCHAR(100) NOT NULL, `last_name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(100) DEFAULT NULL, `phone` VARCHAR(30) DEFAULT NULL,
            `mobile` VARCHAR(30) DEFAULT NULL, `job_title` VARCHAR(100) DEFAULT NULL,
            `is_primary` TINYINT UNSIGNED NOT NULL DEFAULT 0, `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_ccust` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `customer_notes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` INT UNSIGNED NOT NULL,
            `note` TEXT NOT NULL, `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_cn_cust` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `email_templates` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL,
            `slug` VARCHAR(100) NOT NULL, `subject` VARCHAR(200) NOT NULL,
            `body` LONGTEXT NOT NULL, `variables` JSON DEFAULT NULL,
            `category` VARCHAR(50) NOT NULL DEFAULT 'general',
            `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_et_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `email_logs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `from_email` VARCHAR(100) NOT NULL,
            `to_email` VARCHAR(100) NOT NULL, `cc_email` VARCHAR(200) DEFAULT NULL,
            `subject` VARCHAR(200) NOT NULL, `body` LONGTEXT DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'sent', `error_message` TEXT DEFAULT NULL,
            `entity_type` VARCHAR(50) DEFAULT NULL, `entity_id` INT UNSIGNED DEFAULT NULL,
            `sent_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `email_attachments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `email_log_id` INT UNSIGNED NOT NULL,
            `file_name` VARCHAR(255) NOT NULL, `file_path` VARCHAR(500) NOT NULL,
            `file_size` INT UNSIGNED NOT NULL DEFAULT 0, `mime_type` VARCHAR(100) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_ea_log` (`email_log_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL, `file_name` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(500) NOT NULL, `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
            `mime_type` VARCHAR(100) DEFAULT NULL, `category` VARCHAR(50) NOT NULL DEFAULT 'general',
            `entity_type` VARCHAR(50) DEFAULT NULL, `entity_id` INT UNSIGNED DEFAULT NULL,
            `uploaded_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    $created = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($tables as $i => $sql) {
        try {
            $db->exec($sql);
            $created++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'already exists')) {
                $skipped++;
            } else {
                $errors++;
                $messages[] = ['type' => 'error', 'text' => "Table #$i error: $msg"];
            }
        }
    }

    $messages[] = ['type' => 'success', 'text' => "Schema: $created created, $skipped skipped, $errors errors"];

    // Migration: allow NULL customer_id on quotes (for walk-in / no-customer quotes)
    try {
        $db->exec("ALTER TABLE `quotes` MODIFY COLUMN `customer_id` INT UNSIGNED DEFAULT NULL");
    } catch (PDOException $e) { /* column already nullable or table doesn't exist yet */ }

    $admin = dbFetch($db, "SELECT id FROM users WHERE username = 'admin'");
    if (!$admin) {
        $newAdminPassword = bin2hex(random_bytes(12));
        $hash = password_hash($newAdminPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $adminId = dbInsert($db, 'users', [
                'username' => 'admin', 'email' => 'admin@aleph.com.lb',
                'password_hash' => $hash, 'first_name' => 'System', 'last_name' => 'Administrator',
                'role' => 'admin', 'active' => 1, 'force_password_change' => 1, 'is_2fa_enabled' => 0,
            ]);
            $messages[] = ['type' => 'success', 'text' => "Admin created (ID: $adminId) — admin / $newAdminPassword"];
        } catch (Exception $e) {
            $messages[] = ['type' => 'error', 'text' => "Admin creation failed: " . $e->getMessage()];
            $adminId = null;
        }
    } else {
        $adminId = $admin['id'];
        $messages[] = ['type' => 'info', 'text' => 'Admin user exists'];
    }

    $modules = [
        ['slug'=>'customers','label'=>'Customers','category'=>'crm','sort_order'=>1],
        ['slug'=>'quotes','label'=>'Quotes','category'=>'sales','sort_order'=>2],
        ['slug'=>'jobs','label'=>'Jobs','category'=>'production','sort_order'=>3],
        ['slug'=>'invoices','label'=>'Invoices','category'=>'finance','sort_order'=>4],
        ['slug'=>'credit_notes','label'=>'Credit Notes','category'=>'finance','sort_order'=>5],
        ['slug'=>'suppliers','label'=>'Suppliers','category'=>'procurement','sort_order'=>6],
        ['slug'=>'purchase_orders','label'=>'Purchase Orders','category'=>'procurement','sort_order'=>7],
        ['slug'=>'materials','label'=>'Materials','category'=>'inventory','sort_order'=>8],
        ['slug'=>'products','label'=>'Products','category'=>'inventory','sort_order'=>9],
        ['slug'=>'reports','label'=>'Reports','category'=>'analytics','sort_order'=>10],
        ['slug'=>'users','label'=>'Users','category'=>'admin','sort_order'=>11],
        ['slug'=>'settings','label'=>'Settings','category'=>'admin','sort_order'=>12],
        ['slug'=>'email','label'=>'Email','category'=>'communication','sort_order'=>13],
        ['slug'=>'documents','label'=>'Documents','category'=>'general','sort_order'=>14],
        ['slug'=>'followups','label'=>'Follow-ups','category'=>'crm','sort_order'=>15],
        ['slug'=>'employees','label'=>'Employees','category'=>'hr','sort_order'=>16],
    ];
    $modCount = 0;
    foreach ($modules as $m) {
        try {
            $existing = dbFetch($db, "SELECT id FROM modules WHERE slug=?", [$m['slug']]);
            if (!$existing) { dbInsert($db, 'modules', $m); $modCount++; }
        } catch (Exception $e) {}
    }
    $messages[] = ['type' => 'success', 'text' => "Modules: $modCount new seeded"];

    $templates = [
        ['name'=>'Quote Follow-up','slug'=>'quote_followup','subject'=>'Following up on Quote #{{quote_number}}','body'=>'Dear {{customer_name}},\n\nI wanted to follow up on the quote we sent you.\n\nBest regards,\n{{company_name}}','category'=>'sales'],
        ['name'=>'Invoice Reminder','slug'=>'invoice_reminder','subject'=>'Payment Reminder - Invoice #{{invoice_number}}','body'=>'Dear {{customer_name}},\n\nThis is a reminder that Invoice #{{invoice_number}} for {{amount}} is due on {{due_date}}.\n\nBest regards,\n{{company_name}}','category'=>'finance'],
        ['name'=>'Welcome','slug'=>'welcome_customer','subject'=>'Welcome to {{company_name}}!','body'=>'Dear {{customer_name}},\n\nThank you for choosing {{company_name}}.\n\nBest regards,\n{{company_name}}','category'=>'general'],
        ['name'=>'Low Stock','slug'=>'low_stock_alert','subject'=>'Low Stock: {{material_name}}','body'=>'{{material_name}} is low.\n\nStock: {{current_stock}}\nMin: {{min_stock}}','category'=>'inventory'],
    ];
    foreach ($templates as $t) {
        try {
            $existing = dbFetch($db, "SELECT id FROM email_templates WHERE slug=?", [$t['slug']]);
            if (!$existing) dbInsert($db, 'email_templates', $t);
        } catch (Exception $e) {}
    }
    $messages[] = ['type' => 'success', 'text' => 'Email templates seeded'];

    if ($adminId) {
        try {
            $allModules = dbFetchAll($db, "SELECT id FROM modules");
            $permCount = 0;
            foreach ($allModules as $mod) {
                $existing = dbFetch($db, "SELECT id FROM user_modules WHERE user_id=? AND module_id=?", [$adminId, $mod['id']]);
                if (!$existing) {
                    dbInsert($db, 'user_modules', [
                        'user_id' => $adminId, 'module_id' => $mod['id'],
                        'can_view' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 1,
                    ]);
                    $permCount++;
                }
            }
            $messages[] = ['type' => 'success', 'text' => "Admin permissions: $permCount modules configured"];
        } catch (Exception $e) {
            $messages[] = ['type' => 'error', 'text' => "Permissions error: " . $e->getMessage()];
        }
    }

    $messages[] = ['type' => 'success', 'text' => 'Setup complete!'];

    ob_end_clean();
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

$db = getDB();
$requiredTables = ['users','modules','user_modules','module_logs','notifications','customers','quotes','quote_items','products','materials','suppliers','purchase_orders','purchase_order_items','jobs','job_materials','job_stage_progress','invoices','invoice_items','payments','credit_notes','followups','employee_profiles','activity_log','settings','password_history','login_history','api_keys','customer_contacts','customer_notes','email_templates','email_logs','email_attachments','documents'];

$found = 0;
$total = count($requiredTables);
$missing = [];
foreach ($requiredTables as $table) {
    if (tableExists($db, $table)) { $found++; } else { $missing[] = $table; }
}

$adminUser = dbFetch($db, "SELECT id FROM users WHERE username='admin'");
$moduleCount = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM modules")['c'] ?? 0);
$templateCount = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM email_templates")['c'] ?? 0);
$permissionCount = 0;
if ($adminUser) {
    $permissionCount = (int)(dbFetch($db, "SELECT COUNT(*) as c FROM user_modules WHERE user_id=?", [$adminUser['id']])['c'] ?? 0);
}
$setupComplete = ($found === $total) && $adminUser && ($moduleCount > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Aleph ERP Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=10">
    <style>
        body.login-page{display:flex;align-items:center;justify-content:center;min-height:100vh;}
        .setup-box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;max-width:650px;width:100%;}
        .setup-box h1{font-size:24px;margin-bottom:8px;color:#e2e8f0;}.setup-box h1 span{color:#f25424;}
        .subtitle{color:#94a3b8;margin-bottom:24px;font-size:14px;}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px;}
        .info-item{padding:12px;background:#0f172a;border:1px solid #334155;border-radius:6px;}
        .info-item .label{font-size:11px;color:#94a3b8;text-transform:uppercase;}.info-item .value{font-size:16px;font-weight:600;margin-top:4px;}
        .info-item .value.green{color:#4ade80;}.info-item .value.orange{color:#f25424;}.info-item .value.red{color:#f87171;}
        .progress-container{display:none;margin-top:24px;}
        .progress-bar{width:100%;height:8px;background:#334155;border-radius:4px;overflow:hidden;margin-bottom:16px;}
        .progress-fill{height:100%;background:#f25424;border-radius:4px;transition:width 0.3s;width:0%;}
        .log{font-family:'Courier New',monospace;font-size:11px;line-height:1.6;max-height:300px;overflow-y:auto;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;}
        .log-entry{padding:1px 0;}.log-success{color:#4ade80;}.log-error{color:#f87171;}.log-info{color:#60a5fa;}
        .btn-primary{background:#f25424;color:white;border:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;width:100%;}.btn-primary:hover{background:#e04a1e;}.btn-primary:disabled{opacity:0.6;cursor:not-allowed;}
        .btn-secondary{background:#475569;color:white;border:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;display:inline-block;}.btn-secondary:hover{background:#64748b;}
        .next-steps{margin-top:20px;padding:16px;background:rgba(242,84,36,0.1);border:1px solid #f25424;border-radius:8px;display:none;}
        .next-steps h3{color:#f25424;margin-bottom:12px;font-size:14px;}.next-steps ol{margin-left:20px;}.next-steps li{margin-bottom:8px;font-size:13px;color:#e2e8f0;}.next-steps code{background:#334155;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px;}
    </style>
</head>
<body class="login-page">
    <div class="setup-box">
        <h1>Aleph <span>ERP</span> Setup</h1>
        <p class="subtitle">Version <?= APP_VERSION ?> — Installation & Verification</p>

        <div class="info-grid">
            <div class="info-item"><div class="label">Tables Found</div><div class="value <?= $found === $total ? 'green' : 'orange' ?>"><?= $found ?> / <?= $total ?></div></div>
            <div class="info-item"><div class="label">Missing Tables</div><div class="value <?= empty($missing) ? 'green' : 'red' ?>"><?= count($missing) ?></div></div>
            <div class="info-item"><div class="label">Admin User</div><div class="value <?= $adminUser ? 'green' : 'red' ?>"><?= $adminUser ? 'Created' : 'Not Found' ?></div></div>
            <div class="info-item"><div class="label">Modules</div><div class="value <?= $moduleCount > 0 ? 'green' : 'orange' ?>"><?= $moduleCount ?></div></div>
            <div class="info-item"><div class="label">Permissions</div><div class="value <?= $permissionCount > 0 ? 'green' : 'red' ?>"><?= $permissionCount ?></div></div>
            <div class="info-item"><div class="label">Templates</div><div class="value <?= $templateCount > 0 ? 'green' : 'orange' ?>"><?= $templateCount ?></div></div>
        </div>

        <?php if ($setupComplete): ?>
            <div style="background:rgba(74,222,128,0.1);border:1px solid #4ade80;color:#4ade80;padding:16px;border-radius:8px;margin-top:20px;">Setup Complete! Database is ready.</div>
            <div class="next-steps" style="display:block;">
                <h3>Next Steps</h3>
                <ol>
                    <li>Delete <code>setup.php</code> and <code>reset_admin.php</code> from your server</li>
                    <li>Go to <code>login.php</code></li>
                    <li>Login with the admin credentials shown above</li>
                    <li>Change the admin password immediately</li>
                </ol>
            </div>
        <?php endif; ?>

        <?php if (!empty($missing) && !$setupComplete): ?>
            <div style="background:rgba(248,113,113,0.1);border:1px solid #f87171;color:#f87171;padding:12px;border-radius:8px;margin-top:20px;font-size:13px;">
                Missing: <?= h(implode(', ', $missing)) ?>
            </div>
        <?php endif; ?>

        <?php if (!$setupComplete): ?>
        <button id="setupBtn" class="btn-primary" onclick="runSetup()" style="margin-top:24px;">Run Database Setup</button>
        <?php endif; ?>

        <div class="progress-container" id="progressContainer">
            <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
            <div class="log" id="setupLog"></div>
        </div>

        <div style="margin-top:20px;">
            <a href="login.php" class="btn-secondary">Login</a>
        </div>
    </div>
    <script>
    async function runSetup() {
        var btn = document.getElementById('setupBtn');
        var pc = document.getElementById('progressContainer');
        var pf = document.getElementById('progressFill');
        var log = document.getElementById('setupLog');
        btn.disabled = true; btn.textContent = 'Setting up...';
        pc.style.display = 'block'; pf.style.width = '10%'; log.innerHTML = '';
        try {
            var fd = new FormData(); fd.append('action', 'create_database');
            var resp = await fetch('setup.php', { method: 'POST', body: fd });
            pf.style.width = '60%';
            var data = await resp.json();
            pf.style.width = '80%';
            if (data.messages) data.messages.forEach(function(m) {
                var d = document.createElement('div'); d.className = 'log-entry log-' + m.type; d.textContent = m.text; log.appendChild(d);
            });
            log.scrollTop = log.scrollHeight;
            pf.style.width = '100%';
            if (data.success) {
                var r = document.createElement('div'); r.style.cssText = 'background:rgba(74,222,128,0.1);border:1px solid #4ade80;color:#4ade80;padding:16px;border-radius:8px;margin-top:20px;';
                r.textContent = 'Setup Complete!'; document.querySelector('.setup-box').appendChild(r);
                var ns = document.createElement('div'); ns.className = 'next-steps'; ns.style.display = 'block';
                ns.innerHTML = '<h3>Next Steps</h3><ol><li>Delete <code>setup.php</code> and <code>reset_admin.php</code></li><li>Go to <code>login.php</code></li><li>Login with the admin credentials shown above</li></ol>';
                document.querySelector('.setup-box').appendChild(ns);
                btn.textContent = 'Done!'; btn.disabled = false;
                btn.onclick = function() { window.location = 'login.php'; };
            } else { btn.textContent = 'Failed - Retry'; btn.disabled = false; }
        } catch (err) {
            var d = document.createElement('div'); d.className = 'log-entry log-error'; d.textContent = 'Error: ' + err.message; log.appendChild(d);
            btn.textContent = 'Failed - Retry'; btn.disabled = false; pf.style.width = '0%';
        }
    }
    </script>
</body>
</html>
