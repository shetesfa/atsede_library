<?php
require_once __DIR__ . '/../config.php';
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'admin',
    'full_name' => 'አስተዳዳሪ',
    'role' => 'admin'
];
$_GET['limit'] = 'all';

ob_start();
require __DIR__ . '/../librarian/print_qr.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . "\n";
echo "Has stickers container: " . (strpos($html, 'stickers-container') !== false ? "YES" : "NO") . "\n";
echo "Has qr-sticker-card: " . (strpos($html, 'qr-sticker-card') !== false ? "YES" : "NO") . "\n";
$stickerCount = substr_count($html, 'qr-sticker-card');
echo "Rendered sticker count: " . $stickerCount . "\n";
echo "Has qrcode.min.js: " . (strpos($html, 'qrcode.min.js') !== false ? "YES" : "NO") . "\n";
echo "Has window.print: " . (strpos($html, 'window.print()') !== false ? "YES" : "NO") . "\n";
echo "Zero hardcoded 10.116: " . (strpos($html, '10.116') === false ? "YES" : "NO") . "\n";
