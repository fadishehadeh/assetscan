<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'ar'], true)) $lang = 'en';
$_SESSION['lang'] = $lang;
$back = $_GET['back'] ?? '/modules/dashboard/index.php';
// Safety: only redirect to local paths
if (!str_starts_with($back, '/')) $back = '/modules/dashboard/index.php';
header('Location: ' . $back);
exit;
