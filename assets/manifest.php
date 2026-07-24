<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/manifest+json');

$name = library_name($conn);
$root = rtrim(BASE_URL, '/');
$hasLogo = file_exists(library_logo_path());
$icon512 = $hasLogo ? $root . '/uploads/logo.png' : $root . '/assets/icons/icon-512.png';
$icon192 = $hasLogo ? $root . '/uploads/logo.png' : $root . '/assets/icons/icon-512.png';

echo json_encode([
    'id' => $root . '/',
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => 'የቤተ ክርስቲያንና የትምህርት ቤት ቤተ መጻሕፍት መተግበሪያ',
    'start_url' => $root . '/index.php',
    'scope' => $root . '/',
    'display' => 'standalone',
    'background_color' => '#0F172A',
    'theme_color' => '#0F172A',
    'orientation' => 'portrait-primary',
    'icons' => [
        ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
