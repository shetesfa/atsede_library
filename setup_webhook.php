<?php
/**
 * setup_webhook.php  —  Registers Telegram webhook.
 * Requires authenticated administrator session.
 * After running, this file must be deleted or removed from the web root.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authenticated admin session
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;padding:30px;color:#c00;"><h2>403 — ያልተፈቀደ መዳረሻ</h2><p>የቴሌግራም ዌብሁክን ለማዋቀር በአስተዳዳሪ (Admin) መለያ መግባት አለብዎት።</p><a href="login.php">ወደ መግቢያ ገጽ</a></div>';
    if (!defined('PHPUNIT_RUNNING')) {
        exit;
    }
    return;
}

$token = get_setting($conn, 'telegram_bot_token', '');
if (!$token) {
    echo '<div style="font-family:sans-serif;padding:30px;color:#c00;"><h2>❌ የቦት ቶከን አልተገኘም</h2><p>እባክዎ በመጀመሪያ ወደ አስተዳዳሪ ቅንብሮች (Admin → Settings → Telegram) በመሄድ የቦት ቶከን ያስገቡ።</p></div>';
    if (!defined('PHPUNIT_RUNNING')) {
        exit;
    }
    return;
}

// Auto-detect this server's URL
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$webhookUrl = rtrim($protocol . '://' . $host . $scriptDir, '/') . '/telegram_bot.php';

// Generate or retrieve webhook secret token
$secretToken = get_setting($conn, 'telegram_webhook_secret');
if (!$secretToken) {
    $secretToken = bin2hex(random_bytes(24));
    set_setting($conn, 'telegram_webhook_secret', $secretToken);
}

echo "<h2>🤖 የቴሌግራም ዌብሁክ ማቀናበሪያ (Telegram Webhook Setup)</h2>";
echo "<p><strong>የዌብሁክ አድራሻ (Webhook URL):</strong> <code>" . htmlspecialchars($webhookUrl) . "</code></p>";

// Allowed updates including messages, callback queries, and inline queries
$allowedUpdates = ['message', 'edited_message', 'callback_query', 'inline_query'];

$payload = [
    'url'             => $webhookUrl,
    'allowed_updates' => $allowedUpdates,
    'secret_token'    => $secretToken,
];

// Register webhook with Telegram API
$apiUrl = "https://api.telegram.org/bot{$token}/setWebhook";

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($curlError) {
    echo "<p style='color:red'>❌ የ cURL ስህተት: " . htmlspecialchars($curlError) . "</p>";
} elseif (isset($result['ok']) && $result['ok']) {
    echo "<p style='color:green'>✅ <strong>ዌብሁክ በተሳካ ሁኔታ ተመዝግቧል!</strong></p>";
    echo "<p>መግለጫ: " . htmlspecialchars($result['description'] ?? '') . "</p>";
    echo "<hr>";
    echo "<p style='color:orange;font-weight:bold;'>⚠️ አስፈላጊ ማሳሰቢያ፡ ይህን ፋይል (setup_webhook.php) ለደህንነት ሲባል ከሰርቨሩ ላይ ያጥፉት!</p>";
} else {
    echo "<p style='color:red'>❌ አልተሳካም: " . htmlspecialchars($result['description'] ?? 'ያልታወቀ ስህተት') . " (HTTP " . (int)$httpCode . ")</p>";
    echo "<pre>" . htmlspecialchars(print_r($result, true)) . "</pre>";
}

echo "<hr><h3>የአሁኑ የዌብሁክ ሁኔታ (Webhook Info):</h3>";
$infoCh = curl_init("https://api.telegram.org/bot{$token}/getWebhookInfo");
curl_setopt_array($infoCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$infoResp = curl_exec($infoCh);
curl_close($infoCh);

echo "<pre>" . htmlspecialchars(json_encode(json_decode($infoResp), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
?>
