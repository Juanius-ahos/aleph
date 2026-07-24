<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

performLogout();

header('Location: ' . APP_URL . '/login.php');
exit();
