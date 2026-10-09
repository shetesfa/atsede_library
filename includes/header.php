<?php
/**
 * includes/header.php
 * Expects (optional): $pageTitle, $activeKey, $showSearch (bool), $hideChrome (bool)
 */
$user = current_user();
$role = $user['role'] ?? 'guest';
$base = rel_base();
$rawBase = defined('BASE_URL') ? BASE_URL : '/';
$appRoot = '/' . trim($rawBase, '/') . '/';
if ($appRoot === '//') $appRoot = '/';
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
<link rel="manifest" href="<?= $appRoot ?>assets/manifest.php">
<link rel="icon" href="<?= $logoUrl ?: $appRoot . 'assets/icons/icon-512.png' ?>">
<link rel="apple-touch-icon" href="<?= $appRoot ?>assets/icons/icon-maskable-512.png">
<link rel="stylesheet" href="<?= $appRoot ?>assets/lib/fonts/fonts.css">
<link rel="stylesheet" href="<?= $appRoot ?>assets/lib/bootstrap-icons/bootstrap-icons.css">
<link rel="stylesheet" href="<?= $appRoot ?>assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
<script>
  window.APP_BASE = "<?= $base ?>";
  window.APP_ROOT = "<?= $appRoot ?>";
  <?php if ($user): ?>window.VAPID_PUBLIC_KEY = <?= json_encode(get_setting($conn, 'vapid_public_key', '')) ?>;<?php endif; ?>
</script>
</head>
<body>
<div class="logo-bg-wrapper" style="position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;overflow:hidden;pointer-events:none;z-index:-1;contain:strict;clip-path:inset(0);" aria-hidden="true">
  <div class="logo-background logo-1"></div>
  <div class="logo-background logo-2"></div>
  <div class="logo-background logo-3"></div>
</div>
<div class="app-shell">
  <?php if (empty($hideChrome)) include __DIR__ . '/sidebar.php'; ?>
  <div class="main-area">
    <?php if (empty($hideChrome)) include __DIR__ . '/topbar.php'; ?>
    <div class="page">
