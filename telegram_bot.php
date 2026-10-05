<?php
/**
 * telegram_bot.php  —  Atsede Library Telegram Bot Webhook
 *
 * Secure Webhook Endpoint:
 *  - Verifies X-Telegram-Bot-Api-Secret-Token with hash_equals
 *  - Uses prepared statements across all queries
 *  - Delegates update processing to shared bot_handler.php
 */

define('TELEGRAM_WEBHOOK', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bot_handler.php';

global $conn;
if (!$conn && isset($GLOBALS['conn'])) {
    $conn = $GLOBALS['conn'];
}

$expectedToken = get_setting($conn, 'telegram_bot_token', '');
if (!$expectedToken) {
    http_response_code(403);
    echo 'Bot not configured';
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

// Verify Telegram Webhook Secret Token header
$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$configuredSecret = get_setting($conn, 'telegram_webhook_secret', '');

if ($configuredSecret !== '') {
    if (!hash_equals($configuredSecret, $secretHeader)) {
        http_response_code(403);
        echo 'Access denied: invalid webhook secret token';
        if (!defined('PHPUNIT_RUNNING')) exit;
        return;
    }
}

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update || !is_array($update)) {
    echo 'ok';
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

handle_telegram_update($update, $conn, $expectedToken);
echo 'ok';
