<?php
require_once __DIR__ . '/../config.php';
$_GET['code'] = '01A';

ob_start();
require __DIR__ . '/../qr.php';
$html = ob_get_clean();

echo "Length: " . strlen($html) . "\n";
echo "Has deep shelf section: " . (strpos($html, 'ጥልቅ የመደርደሪያና ክፍል መገኛ') !== false ? "YES" : "NO") . "\n";
echo "Has stock status section: " . (strpos($html, 'የመጽሐፉ ቅጂዎችና የላይብረሪ አጠቃላይ ሁኔታ') !== false ? "YES" : "NO") . "\n";
echo "Has room/shelf/position: " . (strpos($html, 'የመደርደሪያ ቦታ / ረድፍ') !== false ? "YES" : "NO") . "\n";
echo "Has borrowable status: " . (strpos($html, 'ለመዋስ የተፈቀደ') !== false || strpos($html, 'ይህ መጽሐፍ ከላይብረሪ ውጭ') !== false ? "YES" : "NO") . "\n";
