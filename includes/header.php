<?php
/**
 * includes/header.php
 * Expects (optional): $pageTitle, $activeKey, $showSearch (bool), $hideChrome (bool)
 */
$user = current_user();
$role = $user['role'] ?? 'guest';
$base = rel_base();
$baseUrl = defined('BASE_URL') ? BASE_URL : '/'; // Use absolute BASE_URL for assets
// Ensure baseUrl starts with / and doesn't end with /
$baseUrl = '/' . trim($baseUrl, '/');
$siteName = library_name($conn);
$pageTitle = $pageTitle ?? __('home');
$activeKey = $activeKey ?? '';
$navItems = nav_items_for_role($role);
$logoUrl = library_logo_url();
?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?> — <?= e($siteName) ?></title>
<meta name="theme-color" content="#0F172A">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(mb_substr($siteName, 0, 12)) ?>">
<link rel="manifest" href="<?= $baseUrl ?>assets/manifest.php">
<link rel="icon" href="<?= $logoUrl ?: $baseUrl . 'assets/icons/icon-512.png' ?>">
<link rel="apple-touch-icon" href="<?= $logoUrl ?: $baseUrl . 'assets/icons/icon-512.png' ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@500;600;700&family=Noto+Sans+Ethiopic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= $baseUrl ?>assets/css/style.css?v=<?= time() ?>">
<script>
  window.APP_BASE = "<?= $base ?>";
  window.APP_ROOT = "<?= BASE_URL ?>/";
  <?php if ($user): ?>window.VAPID_PUBLIC_KEY = <?= json_encode(get_setting($conn, 'vapid_public_key', '')) ?>;<?php endif; ?>
</script>
</head>
<body>
<div class="logo-background logo-1"></div>
<div class="logo-background logo-2"></div>
<div class="logo-background logo-3"></div>
<div class="app-shell">
  <?php if (empty($hideChrome)) include __DIR__ . '/sidebar.php'; ?>
  <div class="main-area">
    <?php if (empty($hideChrome)) include __DIR__ . '/topbar.php'; ?>
    <div class="page">
