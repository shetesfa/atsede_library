<?php
/**
 * cron/telegram_polling.php
 *
 * Local long-polling daemon for development and testing.
 * Shares update handling logic with telegram_bot.php via includes/bot_handler.php.
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only. Run: php cron/telegram_polling.php\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bot_handler.php';

$token = get_setting($conn, 'telegram_bot_token', '');
if (!$token) {
    die("❌ Bot token not set in settings table.\n");
}

$botUser = get_setting($conn, 'telegram_bot_username', 'AtsedeLibraryBot');
echo "🤖 Telegram Polling Daemon Started for @$botUser\n";
echo "Press Ctrl+C to stop.\n\n";

$offset = 0;

function ensureDbConnection(&$conn, $h, $u, $p, $d, $port) {
    if (!$conn || !@mysqli_ping($conn)) {
        @mysqli_close($conn);
        $conn = @mysqli_connect($h, $u, $p, $d, $port);
        if ($conn) {
            mysqli_set_charset($conn, 'utf8mb4');
        }
    }
}

while (true) {
    ensureDbConnection($conn, $host, $user, $pass, $db, $port);

    $payload = [
        'offset'          => $offset,
        'timeout'         => 20,
        'allowed_updates' => ['message', 'edited_message', 'callback_query', 'inline_query'],
    ];

    $response = telegram_api('getUpdates', $payload, $token);

    if (isset($response['ok']) && $response['ok'] && !empty($response['result'])) {
        foreach ($response['result'] as $update) {
            $offset = (int)$update['update_id'] + 1;
            try {
                handle_telegram_update($update, $conn, $token);
            } catch (\Throwable $e) {
                error_log("Error processing telegram update: " . $e->getMessage());
            }
        }
    }

    usleep(500000); // 500ms
}
