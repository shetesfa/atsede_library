<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/manifest+json; charset=utf-8');

$name = library_name($conn);
$rawBase = defined('BASE_URL') ? BASE_URL : '/';
$appRoot = '/' . trim($rawBase, '/') . '/';
if ($appRoot === '//') $appRoot = '/';

// Always use calibrated, true-dimension PNG icons for 100% Chrome PWA install compliance
$icon512 = $appRoot . 'assets/icons/icon-512.png';
$icon192 = $appRoot . 'assets/icons/icon-192.png';
$iconMaskable512 = $appRoot . 'assets/icons/icon-maskable-512.png';
$iconMaskable192 = $appRoot . 'assets/icons/icon-maskable-192.png';

$shortName = get_setting($conn, 'library_short_name', 'ቤተ ይትባረክ');

echo json_encode([
    'id' => $appRoot,
    'name' => $name,
    'short_name' => $shortName,
    'description' => 'የቤተ ክርስቲያንና የትምህርት ቤት ቤተ መጻሕፍት መተግበሪያ',
    'start_url' => $appRoot . 'index.php',
    'scope' => $appRoot,
    'display' => 'standalone',
    'background_color' => '#0F172A',
    'theme_color' => '#0F172A',
    'orientation' => 'portrait-primary',
    'launch_handler' => [
        'client_mode' => 'navigate-existing'
    ],
    'handle_links' => 'preferred',
    'capture_links' => 'existing_client_navigate',
    'icons' => [
        ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconMaskable512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $iconMaskable192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
