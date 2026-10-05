<?php
/**
 * cron/telegram_polling.php
 *
 * LOCAL DEVELOPMENT ONLY — Long-polling bot with:
 *  - Inline query handling (@Atsedeteguhan_library_bot [query])
 *  - Group chat handling (/መጽሐፍ, /መጽሃፍ, /ፈልግ with replies)
 *  - Pure Amharic commands and status verification
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only. Run: php cron/telegram_polling.php\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$token = get_setting($conn, 'telegram_bot_token', '');
if (!$token) {
    die("❌ Bot token not set in settings table.\n");
}

$botUser = get_setting($conn, 'telegram_bot_username', 'Atsedeteguhan_library_bot');
$libName = library_name($conn);
echo "🤖 Telegram Polling Daemon Started for @$botUser (Inline + Groups Enabled)\n";
echo "Press Ctrl+C to stop.\n\n";

$mainKeyboard = get_bot_main_keyboard();
$offset = 0;

function ensureDbConnection(&$conn) {
    if (!$conn || !@mysqli_ping($conn)) {
        @mysqli_close($conn);
        $conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn) {
            mysqli_set_charset($conn, 'utf8mb4');
        }
    }
}

function downloadTelegramPhoto($token, $fileId, $targetDir, $userId) {
    require_once __DIR__ . '/../includes/functions.php';

    $url = "https://api.telegram.org/bot{$token}/getFile?file_id={$fileId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return false;
    $data = json_decode($res, true);
    if (!isset($data['ok']) || !$data['ok']) return false;
    $filePath = $data['result']['file_path'] ?? '';
    if (!$filePath) return false;

    // Cap download size (max 5MB)
    $fileSize = (int)($data['result']['file_size'] ?? 0);
    if ($fileSize > 5 * 1024 * 1024) return false;

    $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
    $tmpTarget = tempnam(sys_get_temp_dir(), 'tg_poll_photo_');
    if (!$tmpTarget) return false;

    $ch2 = curl_init($fileUrl);
    $fp = fopen($tmpTarget, 'wb');
    if (!$fp) {
        @unlink($tmpTarget);
        return false;
    }
    curl_setopt_array($ch2, [
        CURLOPT_FILE           => $fp,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $success = curl_exec($ch2);
    curl_close($ch2);
    fclose($fp);

    if (!$success || filesize($tmpTarget) > 5 * 1024 * 1024) {
        @unlink($tmpTarget);
        return false;
    }

    $uploadResult = secure_process_image($tmpTarget, $targetDir, 'avatar_' . (int)$userId, 5 * 1024 * 1024);
    @unlink($tmpTarget);

    if ($uploadResult['success']) {
        return 'uploads/avatars/' . $uploadResult['file_name'];
    }
    return false;
}

function sendPollingMsg($token, $chatId, $text, $replyMarkup = null, $replyToMsgId = null) {
    $payload = [
        'chat_id'    => (int)$chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup !== null)   $payload['reply_markup'] = $replyMarkup;
    if ($replyToMsgId !== null) $payload['reply_to_message_id'] = (int)$replyToMsgId;

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function searchLibraryBooksLocal($conn, $query) {
    $qEsc = mysqli_real_escape_string($conn, $query);
    $res  = mysqli_query($conn, "
        SELECT b.title, b.author, r.name AS room_name, s.name AS shelf_name,
               SUM(CASE WHEN bc.status='available' THEN 1 ELSE 0 END) AS avail,
               COUNT(bc.id) AS total
        FROM books b
        LEFT JOIN book_copies bc ON bc.book_id = b.id
        LEFT JOIN rooms r ON r.id = b.room_id
        LEFT JOIN shelves s ON s.id = b.shelf_id
        WHERE b.borrow_status != 'archived'
          AND (b.title LIKE '%$qEsc%' OR b.author LIKE '%$qEsc%')
        GROUP BY b.id
        ORDER BY avail DESC
        LIMIT 6
    ");

    if (mysqli_num_rows($res) === 0) {
        return "🔍 «<b>" . htmlspecialchars($query) . "</b>» በሚል ፍለጋ በቤተ-መጻሕፍቱ ውስጥ ምንም መጽሐፍ አልተገኘም።\nእባክዎ የስሙን አጻጻፍ አስተካክለው እንደገና ይሞክሩ።";
    }

    $reply = "🔍 <b>የፍለጋ ውጤቶች ለ «" . htmlspecialchars($query) . "»፦</b>\n\n";
    $n = 1;
    while ($b = mysqli_fetch_assoc($res)) {
        $availCount = (int)$b['avail'];
        $icon = $availCount > 0 ? "✅" : "❌";
        $reply .= "$n. $icon <b>" . htmlspecialchars($b['title']) . "</b>\n";
        $reply .= "   ✍️ ደራሲ፦ " . htmlspecialchars($b['author'] ?: 'ያልተገለጸ') . "\n";
        if ($b['room_name'] || $b['shelf_name']) {
            $loc = trim(($b['room_name'] ?? '') . ' ' . ($b['shelf_name'] ? 'መደርደሪያ ' . $b['shelf_name'] : ''));
            $reply .= "   📍 ቦታ፦ " . htmlspecialchars($loc) . "\n";
        }
        $reply .= "   📦 የሚገኙ ቅጂዎች፦ <b>" . $availCount . " / " . (int)$b['total'] . "</b> " . ($availCount > 0 ? '(ይገኛል)' : '(ተይዟል)') . "\n\n";
        $n++;
    }
    return $reply;
}

while (true) {
    ensureDbConnection($conn);
    $url = "https://api.telegram.org/bot{$token}/getUpdates?offset={$offset}&timeout=20&allowed_updates=[\"message\",\"inline_query\"]";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) { sleep(2); continue; }
    $data = json_decode($resp, true);
    if (!isset($data['ok']) || !$data['ok']) { sleep(3); continue; }

    foreach ($data['result'] as $update) {
        $offset = $update['update_id'] + 1;

        // 1. INLINE QUERY
        if (isset($update['inline_query'])) {
            $iq        = $update['inline_query'];
            $iqId      = $iq['id'];
            $rawQuery  = trim($iq['query'] ?? '');
            echo "[" . date('H:i:s') . "] Inline Query: $rawQuery\n";

            $results = [];
            if ($rawQuery !== '') {
                $qEsc = mysqli_real_escape_string($conn, $rawQuery);
                $res = mysqli_query($conn, "
                    SELECT b.id, b.title, b.author, r.name AS room_name, s.name AS shelf_name,
                           SUM(CASE WHEN bc.status='available' THEN 1 ELSE 0 END) AS avail,
                           COUNT(bc.id) AS total
                    FROM books b
                    LEFT JOIN book_copies bc ON bc.book_id = b.id
                    LEFT JOIN rooms r ON r.id = b.room_id
                    LEFT JOIN shelves s ON s.id = b.shelf_id
                    WHERE b.borrow_status != 'archived'
                      AND (b.title LIKE '%$qEsc%' OR b.author LIKE '%$qEsc%')
                    GROUP BY b.id
                    ORDER BY avail DESC
                    LIMIT 8
                ");

                while ($b = mysqli_fetch_assoc($res)) {
                    $avail = (int)$b['avail'];
                    $total = (int)$b['total'];
                    $statusText = $avail > 0 ? "✅ ይገኛል ($avail/$total ቅጂ)" : "❌ በአሁኑ ጊዜ የለም ($total ቅጂ ተይዟል)";
                    $loc = trim(($b['room_name'] ?? '') . ' ' . ($b['shelf_name'] ? 'መደርደሪያ ' . $b['shelf_name'] : ''));

                    $msgText  = "📖 <b>" . htmlspecialchars($b['title']) . "</b>\n";
                    $msgText .= "✍️ ደራሲ፦ " . htmlspecialchars($b['author'] ?: 'ያልተገለጸ') . "\n";
                    if ($loc) $msgText .= "📍 ቦታ፦ " . htmlspecialchars($loc) . "\n";
                    $msgText .= "📦 ሁኔታ፦ <b>$statusText</b>\n\n";
                    $msgText .= "🏛️ <i>" . htmlspecialchars($libName) . "</i>";

                    $results[] = [
                        'type'        => 'article',
                        'id'          => 'book_' . $b['id'],
                        'title'       => $b['title'],
                        'description' => ($b['author'] ? $b['author'] . ' · ' : '') . $statusText,
                        'input_message_content' => [
                            'message_text' => $msgText,
                            'parse_mode'   => 'HTML',
                        ]
                    ];
                }
            }

            $ch2 = curl_init("https://api.telegram.org/bot{$token}/answerInlineQuery");
            curl_setopt_array($ch2, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'inline_query_id' => $iqId,
                    'results'         => $results,
                    'cache_time'      => 5,
                    'is_personal'     => false,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($ch2);
            curl_close($ch2);
            continue;
        }

        // 2. STANDARD MESSAGES
        if (!isset($update['message'])) continue;

        $msg       = $update['message'];
        $msgId     = $msg['message_id']         ?? 0;
        $chatId    = $msg['chat']['id']         ?? 0;
        $chatType  = $msg['chat']['type']       ?? 'private';
        $isGroup   = ($chatType === 'group' || $chatType === 'supergroup');
        $text      = trim($msg['text']          ?? ($msg['caption'] ?? ''));
        $hasPhoto  = isset($msg['photo']) && is_array($msg['photo']) && count($msg['photo']) > 0;
        $username  = ltrim(trim($msg['from']['username'] ?? ''), '@');
        $firstName = $msg['from']['first_name'] ?? 'አባል';

        if (!$chatId || (!$text && !$hasPhoto)) continue;

        echo "[" . date('H:i:s') . "] Msg from @$username ($firstName) in $chatType" . ($hasPhoto ? " [PHOTO]" : "") . ": $text\n";

        // GROUP LOGIC
        if ($isGroup) {
            if (preg_match('/^(\/መጽ[ሐሃ]ፍ|\/ፈልግ)(?:@\w+)?(?:\s+(.*))?$/u', $text, $matches)) {
                $searchTarget = trim($matches[2] ?? '');
                if ($searchTarget === '') {
                    sendPollingMsg($token, $chatId,
                        "📖 <b>እባክዎ የሚፈልጉትን የመጽሐፍ ስም ወይም ደራሲ ጨምረው ይጻፉ።</b>\n\n💡 ምሳሌ፦ <code>/መጽሐፍ ተአምረ ማርያም</code>",
                        null, $msgId
                    );
                } else {
                    $searchResult = searchLibraryBooksLocal($conn, $searchTarget);
                    sendPollingMsg($token, $chatId, $searchResult, null, $msgId);
                }
            } elseif (in_array($text, ['/ክፍያ','/ቅጣት','/መታወቂያ','/መጻሕፍት','/መጻህፍት','/ፎቶ','📚 ያዋስኳቸው መጻሕፍት','💰 ወርሃዊ ክፍያ','⚠️ የቅጣት መረጃ','🪪 የእኔ ዲጂታል ካርድ'])) {
                sendPollingMsg($token, $chatId, "🔒 ይህ የግል መረጃ ስለሆነ እባክዎ ቦቱን በግል አናግረው፦ @$botUser", null, $msgId);
            }
            continue;
        }

        // PRIVATE LOGIC
        $libUser = null;
        if ($username) {
            $uEsc    = mysqli_real_escape_string($conn, $username);
            $libUser = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT u.*, m.id AS member_id, m.class, m.student_id FROM users u
                 LEFT JOIN members m ON m.user_id = u.id
                 WHERE u.telegram_username = '$uEsc' LIMIT 1"));
        }
        if (!$libUser && $chatId) {
            $libUser = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT u.*, m.id AS member_id, m.class, m.student_id FROM users u
                 LEFT JOIN members m ON m.user_id = u.id
                 WHERE u.telegram_chat_id = $chatId LIMIT 1"));
        }

        if ($libUser && (int)$libUser['telegram_chat_id'] !== (int)$chatId) {
            $uid = (int)$libUser['id'];
            mysqli_query($conn, "UPDATE users SET telegram_chat_id=$chatId WHERE id=$uid");
            $libUser['telegram_chat_id'] = $chatId;
        }

        // --- PHOTO UPLOAD VIA TELEGRAM ---
        if ($hasPhoto) {
            if (!$libUser) {
                sendPollingMsg($token, $chatId,
                    "⚠️ <b>ይቅርታ፣ የቴሌግራም መለያዎ በቤተ-መጻሕፍቱ ውስጥ አልተገኘም!</b>\n\n" .
                    "ፎቶ ለመጫን እባክዎ መጀመሪያ በድረ-ገጹ ላይ ይመዝገቡ፤ ከዚያም የራስዎን ፎቶ እዚህ በመላክ የመታወቂያ ፎቶዎን ማዘጋጀት ይችላሉ።",
                    ['remove_keyboard' => true]
                );
                continue;
            }

            // Download largest photo
            $largestPhoto = end($msg['photo']);
            $fileId = $largestPhoto['file_id'] ?? '';
            if ($fileId) {
                $avatarDir = __DIR__ . '/../uploads/avatars';
                $savedPath = downloadTelegramPhoto($token, $fileId, $avatarDir, $libUser['id']);
                if ($savedPath) {
                    $stmtUp = mysqli_prepare($conn, "UPDATE users SET profile_photo=? WHERE id=?");
                    mysqli_stmt_bind_param($stmtUp, 'si', $savedPath, $libUser['id']);
                    mysqli_stmt_execute($stmtUp);
                    mysqli_stmt_close($stmtUp);
                    $libUser['profile_photo'] = $savedPath;

                    $name = $libUser['full_name'] ?: $firstName;
                    if ($libUser['status'] === 'pending') {
                        sendPollingMsg($token, $chatId,
                            "📸 <b>የመታወቂያ ፎቶዎ በተሳካ ሁኔታ ተቀብለናል!</b>\n\n" .
                            "👤 አባል፦ <b>" . htmlspecialchars($name) . "</b>\n\n" .
                            "⏳ የአባልነት ጥያቄዎ በአስተዳዳሪ ሲጸድቅ ፎቶው በቀጥታ በዲጂታል መታወቂያ ካርድዎ ላይ ተካቶ ዝግጁ ይሆናል።\n" .
                            "እባክዎ ማረጋገጫውን በትዕግስት ይጠብቁ።",
                            ['remove_keyboard' => true]
                        );
                    } else {
                        sendPollingMsg($token, $chatId,
                            "📸 <b>የመታወቂያ ፎቶዎ በተሳካ ሁኔታ ተጭኗል!</b>\n\n" .
                            "👤 አባል፦ <b>" . htmlspecialchars($name) . "</b>\n\n" .
                            "✅ ፎቶው በዲጂታል መታወቂያ ካርድዎ ላይ ተካቷል።\n" .
                            "በድረ-ገጹ ላይ ወይም እዚህ <b>🪪 የእኔ ዲጂታል ካርድ</b> የሚለውን በመጫን ማየት ይችላሉ።",
                            $mainKeyboard
                        );
                    }
                    continue;
                } else {
                    sendPollingMsg($token, $chatId, "❌ ፎቶውን ማውረድ አልተቻለም። እባክዎ እንደገና ይሞክሩ።", $mainKeyboard);
                    continue;
                }
            }
            continue;
        }

        // /start
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text, 2);
            $param = $parts[1] ?? '';
            if (strpos($param, 'verify_') === 0) {
                $verifyUserId = (int)substr($param, 7);
                if ($verifyUserId > 0) {
                    $uEsc = mysqli_real_escape_string($conn, $username);
                    mysqli_query($conn,
                        "UPDATE users SET telegram_chat_id=$chatId 
                         WHERE id=$verifyUserId AND (telegram_username='$uEsc' OR telegram_chat_id IS NULL OR telegram_username IS NULL)");
                    $libUser = mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT u.*, m.id AS member_id, m.class, m.student_id FROM users u
                         LEFT JOIN members m ON m.user_id = u.id WHERE u.id=$verifyUserId LIMIT 1"));
                }
            }

            if (!$libUser) {
                sendPollingMsg($token, $chatId,
                    "👋 <b>እንኳን ወደ " . htmlspecialchars($libName) . " ቦት በደህና መጡ!</b>\n\n" .
                    "⚠️ የቴሌግራም መለያዎ (<code>@" . htmlspecialchars($username ?: 'የሌለዎት') . "</code>) በቤተ-መጻሕፍቱ ዳታቤዝ ውስጥ አልተገኘም።\n\n" .
                    "📝 በድረ-ገጹ ላይ ሲመዘገቡ የቴሌግራም የተጠቃሚ ስምዎን (Username) በትክክል ማስገባትዎን ያረጋግጡ።\n\n" .
                    "ከተመዘገቡ በኋላ አስተዳዳሪው ሲያረጋግጥልዎት አገልግሎቱን ማግኘት ይችላሉ።",
                    ['remove_keyboard' => true]
                );
                continue;
            }

            $name   = $libUser['full_name'] ?: $firstName;
            $status = $libUser['status'];

            if ($status === 'pending') {
                sendPollingMsg($token, $chatId,
                    "👋 <b>ሰላም፣ " . htmlspecialchars($name) . "!</b>\n\n" .
                    "⏳ <b>የአባልነት ጥያቄዎ በአስተዳዳሪ ማረጋገጫ በመጠባበቅ ላይ ነው!</b>\n\n" .
                    "🔒 መለያዎ ገና አልጸደቀም። አስተዳዳሪው ወይም ላይብረሪያኑ ሲያጸድቁት ወዲያውኑ በዚህ ቴሌግራም መልእክት ይደርስዎታል፤ ከዚያም አገልግሎቱን ሙሉ በሙሉ መጠቀም ይችላሉ።\n\n" .
                    "ℹ️ እባክዎ በትዕግስት ይጠብቁ።",
                    ['remove_keyboard' => true]
                );
                continue;
            } elseif ($status === 'rejected') {
                sendPollingMsg($token, $chatId, "❌ <b>ይቅርታ፣ " . htmlspecialchars($name) . "</b>\n\nየአባልነት ምዝገባ ጥያቄዎ ተቀባይነት አላገኘም። ለተጨማሪ መረጃ ቤተ-መጻሕፍቱን በአካል ያነጋግሩ።", ['remove_keyboard' => true]);
                continue;
            } elseif ($status === 'suspended') {
                sendPollingMsg($token, $chatId, "🚫 <b>ሰላም፣ " . htmlspecialchars($name) . "</b>\n\nየቤተ-መጻሕፍት መለያዎ ለጊዜው ታግዷል። ለተጨማሪ መረጃ ቤተ-መጻሕፍቱን ያነጋግሩ።", ['remove_keyboard' => true]);
                continue;
            }

            mysqli_query($conn, "UPDATE users SET telegram_joined=1 WHERE id=" . (int)$libUser['id']);
            sendPollingMsg($token, $chatId,
                "👋 <b>እንኳን ደህና መጡ፣ " . htmlspecialchars($name) . "!</b>\n\n" .
                "✅ <b>የ" . htmlspecialchars($libName) . " አገልግሎት ተከፍቶልዎታል!</b>\n\n" .
                "ከታች ያሉትን የአማርኛ አዝራሮች በመጠቀም መገልገል ይችላሉ፦",
                $mainKeyboard
            );
            continue;
        }

        if (!$libUser) {
            sendPollingMsg($token, $chatId, "⚠️ የቴሌግራም መለያዎ ከቤተ-መጻሕፍቱ ጋር አልተገናኘም። እባክዎ በድረ-ገጹ ላይ ይመዝገቡ ወይም /start ይጫኑ።", ['remove_keyboard' => true]);
            continue;
        }

        $status = $libUser['status'];
        $name   = $libUser['full_name'] ?: $firstName;

        if ($status === 'pending') {
            sendPollingMsg($token, $chatId, "⏳ <b>ሰላም፣ " . htmlspecialchars($name) . "!</b>\n\nመለያዎ ገና በአስተዳዳሪ አልጸደቀም። ማረጋገጫ እንዳገኘ መልእክት ይደርስዎታል፤ እባክዎ ይጠብቁ።", ['remove_keyboard' => true]);
            continue;
        } elseif ($status !== 'active') {
            sendPollingMsg($token, $chatId, "🚫 መለያዎ ንቁ አይደለም። እባክዎ ቤተ-መጻሕፍቱን ያነጋግሩ።", ['remove_keyboard' => true]);
            continue;
        }

        $memberId = (int)($libUser['member_id'] ?? 0);

        // --- 📚 ያዋስኳቸው መጻሕፍት ---
        if ($text === '📚 ያዋስኳቸው መጻሕፍት' || $text === '/መጻሕፍት' || $text === '/መጻህፍት' || $text === '/books') {
            $res = mysqli_query($conn, "
                SELECT b.title, b.author, bc.copy_code, br.due_date, br.overdue_fine, br.fine_paid, br.fine_waived
                FROM borrow_records br
                JOIN books b ON b.id = br.book_id
                JOIN book_copies bc ON bc.id = br.book_copy_id
                WHERE br.member_id = $memberId AND br.status = 'borrowed'
                ORDER BY br.due_date ASC
            ");

            if (mysqli_num_rows($res) === 0) {
                sendPollingMsg($token, $chatId, "📭 <b>በአሁኑ ጊዜ በእጅዎ ያለ የተዋሱት መጽሐፍ የለም።</b>", $mainKeyboard);
            } else {
                $reply = "📚 <b>ያዋሷቸው መጻሕፍት ዝርዝር፦</b>\n\n";
                $today = new DateTime();
                $i = 1;
                while ($br = mysqli_fetch_assoc($res)) {
                    $due     = new DateTime($br['due_date']);
                    $isLate  = $today > $due;
                    $netFine = max(0, (float)$br['overdue_fine'] - (float)$br['fine_paid'] - (float)$br['fine_waived']);
                    $icon    = $isLate ? "⚠️" : "📖";

                    $reply .= "$i. $icon <b>" . htmlspecialchars($br['title']) . "</b>\n";
                    $reply .= "   ✍️ ደራሲ፦ " . htmlspecialchars($br['author'] ?: 'ያልተገለጸ') . "\n";
                    $reply .= "   🏷️ የቅጂ ኮድ፦ <code>" . htmlspecialchars($br['copy_code']) . "</code>\n";
                    $reply .= "   📅 የመመለሻ ቀን፦ <b>" . formatDate($br['due_date']) . "</b>\n";
                    if ($isLate) {
                        $days = $today->diff($due)->days;
                        $reply .= "   ⏰ <b>ዘግይቷል፦ $days ቀናት</b>\n";
                        if ($netFine > 0) $reply .= "   💸 ቅጣት፦ <b>" . number_format($netFine, 2) . " ብር</b>\n";
                    }
                    $reply .= "\n";
                    $i++;
                }
                sendPollingMsg($token, $chatId, $reply, $mainKeyboard);
            }
            continue;
        }

        // --- 💰 ወርሃዊ ክፍያ ---
        if ($text === '💰 ወርሃዊ ክፍያ' || $text === '/ክፍያ' || $text === '/payment') {
            $ps = get_member_payment_status($conn, $memberId);
            $icon = $ps['is_paid'] ? '✅' : '❌';
            $reply = "💰 <b>የወርሃዊ አባልነት ክፍያ መረጃ፦</b>\n\n" .
                     "$icon <b>ሁኔታ፦ " . htmlspecialchars($ps['status_text']) . "</b>\n" .
                     "📅 ወር፦ <b>" . htmlspecialchars($ps['month_label']) . "</b>\n" .
                     "💵 የተከፈለ መጠን፦ <b>" . number_format($ps['amount_paid'], 2) . " ብር</b>\n" .
                     "📋 የሚፈለገው ዝቅተኛ፦ <b>" . number_format($ps['minimum_required'], 2) . " ብር</b>\n";
            if (!$ps['is_paid']) {
                $rem = max(0, $ps['minimum_required'] - $ps['amount_paid']);
                $reply .= "\n⚠️ <b>የሚቀረው መጠን፦ " . number_format($rem, 2) . " ብር</b>\n" .
                          "መጻሕፍትን ለመዋስ እባክዎ ወደ ቤተ-መጻሕፍቱ በመሄድ ክፍያዎን ያስፈጽሙ።";
            } else {
                $reply .= "\n🎉 ክፍያዎ የተሟላ ነው፤ መጻሕፍትን መዋስ ይችላሉ!";
            }
            sendPollingMsg($token, $chatId, $reply, $mainKeyboard);
            continue;
        }

        // --- ⚠️ የቅጣት መረጃ ---
        if ($text === '⚠️ የቅጣት መረጃ' || $text === '/ቅጣት' || $text === '/fine') {
            $totalFine = get_member_outstanding_fine($conn, $memberId);
            if ($totalFine <= 0) {
                sendPollingMsg($token, $chatId, "✅ <b>ምንም አይነት ያልተከፈለ ቅጣት የለዎትም! እናመሰግናለን።</b>", $mainKeyboard);
            } else {
                $finePerDay = get_setting($conn, 'overdue_fine_per_day', 5);
                sendPollingMsg($token, $chatId,
                    "⚠️ <b>የውሰት ማዘግየት ቅጣት መረጃ፦</b>\n\n" .
                    "💸 ጠቅላላ የሚፈለግ ቅጣት፦ <b>" . number_format($totalFine, 2) . " ብር</b>\n" .
                    "📌 ዕለታዊ የቅጣት ተመን፦ <b>$finePerDay ብር / ቀን</b>\n\n" .
                    "እባክዎ የተዋሷቸውን መጻሕፍት በፍጥነት በመመለስ ቅጣቱን በቤተ-መጻሕፍቱ ይክፈሉ።",
                    $mainKeyboard
                );
            }
            continue;
        }

        // --- 🪪 የእኔ ዲጂታል ካርድ ---
        if ($text === '🪪 የእኔ ዲጂታል ካርድ' || $text === '/መታወቂያ' || $text === '/ካርድ' || $text === '/id') {
            $ps        = get_member_payment_status($conn, $memberId);
            $totalFine = get_member_outstanding_fine($conn, $memberId);
            $borrowRow = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS c FROM borrow_records WHERE member_id=$memberId AND status='borrowed'"));
            $activeBorrows = (int)($borrowRow['c'] ?? 0);
            $idNum = str_pad($libUser['id'], 6, '0', STR_PAD_LEFT);
            $hasPhoto = (!empty($libUser['profile_photo']) && file_exists(__DIR__ . '/../' . $libUser['profile_photo']));

            $card = "🪪 <b>የ" . htmlspecialchars($libName) . " ዲጂታል መታወቂያ</b>\n" .
                    "──────────────────\n" .
                    "👤 ስም፦ <b>" . htmlspecialchars($libUser['full_name']) . "</b>\n" .
                    "🆔 መለያ ቁጥር፦ <code>#$idNum</code>\n" .
                    "🏫 ክፍል፦ " . htmlspecialchars($libUser['class'] ?: '—') . "\n" .
                    "📞 ስልክ፦ " . htmlspecialchars($libUser['phone'] ?: '—') . "\n" .
                    "📸 ፎቶ፦ " . ($hasPhoto ? "✅ ተጭኗል" : "⚠️ አልተጫነም (ፎቶ እዚህ በመላክ ይጫኑ)") . "\n" .
                    "💳 ወርሃዊ ክፍያ፦ " . ($ps['is_paid'] ? '✅ ተከፍሏል' : '❌ አልተከፈለም') . "\n" .
                    "📚 የተዋሷቸው ቅጂዎች፦ <b>$activeBorrows ቅጂ</b>\n";
            if ($totalFine > 0) {
                $card .= "⚠️ ቅጣት፦ <b>" . number_format($totalFine, 2) . " ብር</b>\n";
            }
            $card .= "──────────────────\n" .
                     "ℹ️ ይህን መረጃ በቤተ-መጻሕፍቱ ውስጥ ማንነትዎን ለማረጋገጥ መጠቀም ይችላሉ።";
            sendPollingMsg($token, $chatId, $card, $mainKeyboard);
            continue;
        }

        // --- 📸 ፎቶ ስቀል / ቀይር ---
        if ($text === '/ፎቶ' || $text === '📸 ፎቶ' || $text === '/photo') {
            sendPollingMsg($token, $chatId,
                "📸 <b>የመታወቂያ ፎቶ ለመጫን ወይም ለመቀየር፦</b>\n\n" .
                "እባክዎ ግልጽ የሆነ የራስዎን ፎቶ በቀጥታ ወደዚህ ውይይት ይላኩ (ልክ ለጓደኛዎ ፎቶ እንደሚልኩት ያያይዙ)።\n\n" .
                "ቦቱ ፎቶውን ተቀብሎ በዲጂታል መታወቂያ ካርድዎ ላይ በቅጽበት ይጭነዋል።",
                $mainKeyboard
            );
            continue;
        }

        // --- 🔍 መጽሐፍ ፈልግ Prompt ---
        if ($text === '🔍 መጽሐፍ ፈልግ') {
            sendPollingMsg($token, $chatId,
                "🔍 <b>መጽሐፍ መፈለጊያ፦</b>\n\n" .
                "እባክዎ የሚፈልጉትን የመጽሐፍ ስም ወይም ደራሲ ጽፈው ይላኩ።\n\n" .
                "💡 <b>ምሳሌ፦</b> <code>/መጽሐፍ ተአምረ ማርያም</code> ወይም በቀጥታ <code>ማርያም</code> ብለው ይጻፉ።",
                $mainKeyboard
            );
            continue;
        }

        // --- ❓ እርዳታ ---
        if ($text === '❓ እርዳታ' || $text === '/እርዳታ' || $text === '/help' || $text === '/መመሪያ') {
            $libName = library_name($conn);
            sendPollingMsg($token, $chatId,
                "📖 <b>$libName — የቦት አጠቃቀም መመሪያ፦</b>\n\n" .
                "ከስር ያሉትን አዝራሮች በመጫን የሚከተሉትን አገልግሎቶች ማግኘት ይችላሉ፦\n\n" .
                "📚 <b>ያዋስኳቸው መጻሕፍት</b> — በእጅዎ ያሉ መጻሕፍትን እና የመመለሻ ቀንን ያሳያል\n" .
                "💰 <b>ወርሃዊ ክፍያ</b> — የዚህን ወር የአባልነት ክፍያ ሁኔታ ያሳያል\n" .
                "⚠️ <b>የቅጣት መረጃ</b> — የዘገየ መጽሐፍ ካለብዎት ቅጣቱን ያሳያል\n" .
                "🔍 <b>መጽሐፍ ፈልግ</b> — በቤተ-መጻሕፍቱ ውስጥ ያሉ መጻሕፍትን ይፈልጋል\n" .
                "🪪 <b>የእኔ ዲጂታል ካርድ</b> — የእርስዎን የአባልነት መረጃ ያሳያል\n" .
                "📸 <b>የመታወቂያ ፎቶ</b> — ግልጽ የራስዎን ፎቶ በቀጥታ ወደዚህ ውይይት በመላክ በካርድዎ ላይ መጫን ይችላሉ\n\n" .
                "💡 <b>ትዕዛዞች፦</b> <code>/መጽሐፍ [ስም]</code>፣ <code>/መጻሕፍት</code>፣ <code>/ክፍያ</code>፣ <code>/ቅጣት</code>፣ <code>/መታወቂያ</code>፣ <code>/ፎቶ</code>\n" .
                "👥 <b>በግሩፕ ውስጥ፦</b> ቦቱን ግሩፕ ውስጥ አስገብተው <code>/መጽሐፍ [ስም]</code> ብለው መፈለግ ይችላሉ!\n" .
                "✨ <b>በየትኛውም ቻት፦</b> <code>@$botUser [ስም]</code> ብለው Inline መፈለግ ይችላሉ!",
                $mainKeyboard
            );
            continue;
        }

        // Search execution
        $query = '';
        if (preg_match('/^(\/መጽ[ሐሃ]ፍ|\/ፈልግ)(?:@\w+)?(?:\s+(.*))?$/u', $text, $matches)) {
            $query = trim($matches[2] ?? '');
            if ($query === '') {
                sendPollingMsg($token, $chatId,
                    "📖 <b>እባክዎ የሚፈልጉትን የመጽሐፍ ስም ወይም ደራሲ ጨምረው ይጻፉ።</b>\n\n💡 ምሳሌ፦ <code>/መጽሐፍ ተአምረ ማርያም</code>",
                    $mainKeyboard
                );
                continue;
            }
        } else {
            if (strpos($text, '/') !== 0) {
                $query = $text;
            }
        }

        if ($query !== '') {
            $searchResult = searchLibraryBooksLocal($conn, $query);
            sendPollingMsg($token, $chatId, $searchResult, $mainKeyboard);
            continue;
        }

        sendPollingMsg($token, $chatId, "❓ ይቅርታ፣ ያልታወቀ ትዕዛዝ ነው። እባክዎ ከታች ያሉትን አዝራሮች ይጠቀሙ ወይም <b>/እርዳታ</b> ብለው ይጻፉ።", $mainKeyboard);
    }
}
