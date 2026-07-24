<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page Not Found | <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/style.css?v=10">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="error-page">
    <div class="error-box">
        <div class="error-logo">Aleph <span>ERP</span></div>
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-desc">The page you're looking for doesn't exist or has been moved.</p>
        <div style="display:flex;gap:10px;justify-content:center">
            <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-primary">
                <i data-lucide="layout-dashboard" style="width:14px;height:14px"></i> Dashboard
            </a>
            <button onclick="history.back()" class="btn btn-secondary">
                <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Go Back
            </button>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
