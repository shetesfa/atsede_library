<?php
// Test PWA Manifest and Service Worker from localhost HTTP server
$ch = curl_init('http://localhost/atsede_library/assets/manifest.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$manifestBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "Manifest HTTP Code: $httpCode\n";
echo "Manifest Content-Type: $contentType\n";

$json = json_decode($manifestBody, true);
if ($json) {
    echo "Manifest JSON Valid: YES\n";
    echo "  name: " . ($json['name'] ?? 'missing') . "\n";
    echo "  short_name: " . ($json['short_name'] ?? 'missing') . "\n";
    echo "  start_url: " . ($json['start_url'] ?? 'missing') . "\n";
    echo "  scope: " . ($json['scope'] ?? 'missing') . "\n";
    echo "  display: " . ($json['display'] ?? 'missing') . "\n";
    echo "  icons count: " . count($json['icons'] ?? []) . "\n";
    foreach ($json['icons'] as $idx => $icon) {
        $ch2 = curl_init('http://localhost' . $icon['src']);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch2);
        $iconCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        echo "    Icon $idx: " . $icon['src'] . " (" . $icon['sizes'] . ") HTTP=$iconCode purpose=" . ($icon['purpose'] ?? 'none') . "\n";
    }
} else {
    echo "Manifest JSON Valid: NO\nBody:\n$manifestBody\n";
}

// Test Service worker
$ch = curl_init('http://localhost/atsede_library/sw.js');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$swBody = curl_exec($ch);
$swCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "sw.js HTTP Code: $swCode\n";
echo "sw.js contains fetch handler: " . (strpos($swBody, "addEventListener('fetch'") !== false ? "YES" : "NO") . "\n";
echo "sw.js contains icon-192: " . (strpos($swBody, 'icon-192.png') !== false ? "YES" : "NO") . "\n";

// Test Index.php manifest link
$ch = curl_init('http://localhost/atsede_library/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$indexBody = curl_exec($ch);
curl_close($ch);
preg_match('/<link rel="manifest" href="([^"]+)"/i', $indexBody, $m);
echo "Index.php manifest href: " . ($m[1] ?? 'NOT FOUND') . "\n";
preg_match('/window\.APP_ROOT = "([^"]+)"/i', $indexBody, $m2);
echo "Index.php APP_ROOT: " . ($m2[1] ?? 'NOT FOUND') . "\n";
