<?php
/**
 * includes/bot_handler.php
 * Unified Telegram update processor for both Webhook and Long Polling.
 * Enforces prepared statements, immutable Telegram user ID binding,
 * group chat protections, and strict access controls.
 */

require_once __DIR__ . '/functions.php';

function handle_telegram_update(array $update, mysqli $conn, string $botToken): void {
    // 1. Check for Inline Query
    if (isset($update['inline_query'])) {
        handle_bot_inline_query($update['inline_query'], $conn, $botToken);
        return;
    }

    // 2. Check for Callback Query
    if (isset($update['callback_query'])) {
        handle_bot_callback_query($update['callback_query'], $conn, $botToken);
        return;
    }

    // 3. Message handler
    $msg = $update['message'] ?? $update['edited_message'] ?? null;
    if (!$msg || !isset($msg['chat']['id'])) {
        return;
    }

    $chatId   = (int)$msg['chat']['id'];
    $chatType = $msg['chat']['type'] ?? 'private';
    $fromId   = (int)($msg['from']['id'] ?? 0);
    $text     = trim($msg['text'] ?? '');
    $hasPhoto = !empty($msg['photo']);

    // Lookup user by immutable telegram_user_id or telegram_chat_id
    $libUser = null;
    if ($fromId > 0 || $chatId > 0) {
        $stmt = mysqli_prepare($conn, "SELECT u.*, m.id AS member_id, m.class, m.student_id 
                                       FROM users u 
                                       LEFT JOIN members m ON m.user_id = u.id 
                                       WHERE (u.telegram_user_id = ? AND u.telegram_user_id IS NOT NULL)
                                          OR (u.telegram_chat_id = ? AND u.telegram_chat_id IS NOT NULL) 
                                       LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $fromId, $chatId);
            mysqli_stmt_execute($stmt);
            $libUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        }
    }

    // Handle /start verify_<token> (Account Binding)
    if (str_starts_with($text, '/start verify_')) {
        $rawToken = trim(substr($text, 14));
        if ($rawToken === '') {
            telegram_send($conn, $chatId, "❌ የተሳሳተ የማረጋገጫ ምልክት።");
            return;
        }

        $hashedToken = hash('sha256', $rawToken);
        $stmtV = mysqli_prepare($conn, "SELECT id, full_name, status FROM users 
                                        WHERE telegram_verify_token = ? 
                                          AND (telegram_verify_expires IS NULL OR telegram_verify_expires > NOW()) 
                                        LIMIT 1");
        if ($stmtV) {
            mysqli_stmt_bind_param($stmtV, 's', $hashedToken);
            mysqli_stmt_execute($stmtV);
            $matchedUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtV));
            mysqli_stmt_close($stmtV);

            if ($matchedUser) {
                $bindStmt = mysqli_prepare($conn, "UPDATE users 
                                                    SET telegram_chat_id = ?, 
                                                        telegram_user_id = ?, 
                                                        telegram_joined = 1, 
                                                        telegram_verify_token = NULL, 
                                                        telegram_verify_expires = NULL 
                                                    WHERE id = ?");
                if ($bindStmt) {
                    mysqli_stmt_bind_param($bindStmt, 'iii', $chatId, $fromId, $matchedUser['id']);
                    mysqli_stmt_execute($bindStmt);
                    mysqli_stmt_close($bindStmt);
                }

                $cleanName = htmlspecialchars($matchedUser['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $matchedRole = $matchedUser['role'] ?? 'member';
                $welcome = "✅ <b>እንኳን ደህና መጡ፣ {$cleanName}!</b>\n\nየቴሌግራም መለያዎ ከአጸደ ቤተ-መጻሕፍት ጋር በተሳካ ሁኔታ ተገናኝቷል።";
                telegram_send($conn, $chatId, $welcome, get_bot_main_keyboard($matchedRole));
                return;
            }
        }

        telegram_send($conn, $chatId, "❌ የማረጋገጫ ምልክቱ ልክ አይደለም ወይም ጊዜው አልፏል። እባክዎ ከመገለጫ ገጽዎ አዲስ ምልክት ይውሰዱ።");
        return;
    }

    // Handle normal /start or help
    if ($text === '/start' || $text === '❓ እርዳታ' || $text === '/help') {
        $userRole = $libUser['role'] ?? 'member';
        $resp = "📖 <b>አጸደ ቤተ-መጻሕፍት ቦት</b>\n\n" .
                "በዚህ ቦት አማካኝነት የተዋሷቸውን መጻሕፍት፣ ወርሃዊ ክፍያ እና የቅጣት መረጃዎችን ማየት ይችላሉ።\n\n" .
                "ከታች ያሉትን አማራጮች ይጠቀሙ፦";
        telegram_send($conn, $chatId, $resp, get_bot_main_keyboard($userRole));
        return;
    }

    // Require Private chat for personal/sensitive operations
    $isPrivateChat = ($chatType === 'private');

    // Handle Book Search (Allowed in both private and groups)
    if (str_starts_with($text, '🔍 መጽሐፍ ፈልግ') || str_starts_with($text, '/search')) {
        $term = trim(str_replace(['🔍 መጽሐፍ ፈልግ', '/search'], '', $text));
        if ($term === '') {
            telegram_send($conn, $chatId, "እባክዎ የሚፈልጉትን የመጽሐፍ ርዕስ ወይም የደራሲ ስም ያስገቡ፦\nምሳሌ፦ <code>/search ታሪክ</code>");
            return;
        }

        $escapedTerm = '%' . addcslashes($term, '%_') . '%';
        $stmtSearch = mysqli_prepare($conn, "SELECT b.id, b.title, b.author, b.borrow_status,
                                                    (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.id AND bc.status = 'available') AS available_copies 
                                             FROM books b 
                                             WHERE b.title LIKE ? OR b.author LIKE ? 
                                             LIMIT 5");
        if ($stmtSearch) {
            mysqli_stmt_bind_param($stmtSearch, 'ss', $escapedTerm, $escapedTerm);
            mysqli_stmt_execute($stmtSearch);
            $resSearch = mysqli_stmt_get_result($stmtSearch);
            $count = mysqli_num_rows($resSearch);

            if ($count === 0) {
                telegram_send($conn, $chatId, "❌ '<b>" . htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>' በሚል ምንም መጽሐፍ አልተገኘም።");
            } else {
                $reply = "📚 <b>የፍለጋ ውጤቶች ({$count})፦</b>\n\n";
                while ($b = mysqli_fetch_assoc($resSearch)) {
                    $bTitle = htmlspecialchars($b['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $bAuthor = htmlspecialchars($b['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $avail = (int)$b['available_copies'];
                    $reply .= "📖 <b>{$bTitle}</b>\n✍️ ደራሲ፦ {$bAuthor}\n📦 ዝግጁ ኮፒዎች፦ {$avail}\n\n";
                }
                telegram_send($conn, $chatId, $reply);
            }
            mysqli_stmt_close($stmtSearch);
        }
        return;
    }

    // Block group chats from personal commands
    if (!$isPrivateChat) {
        if (in_array($text, ['📚 የተዋስኳቸው መጻሕፍት', '📚 ያዋስኳቸው መጻሕፍት', '💰 ወርሃዊ ክፍያ', '⚠️ የቅጣት መረጃ', '🪪 የእኔ ዲጂታል ካርድ', '👤 አባል ፈልግ (በID)'])) {
            telegram_send($conn, $chatId, "🔒 የግል መረጃዎችን በግሩፕ ውስጥ ማየት አይፈቀድም። እባክዎ ለቦቱ በግል መልዕክት (Private Chat) ይላኩ።");
        }
        return;
    }

    // Personal operations require authenticated active user
    if (!$libUser) {
        telegram_send($conn, $chatId, "⚠️ የቴሌግራም መለያዎ ከቤተ-መጻሕፍት ሲስተም ጋር አልተገናኘም።\nእባክዎ ወደ ሲስተሙ በመግባት ከመገለጫ (Profile) ገጽዎ ላይ 'ከቴሌግራም ጋር አገናኝ' የሚለውን ይጫኑ።");
        return;
    }

    if ($libUser['status'] !== 'active') {
        telegram_send($conn, $chatId, "⚠️ መለያዎ ገና አልተረጋገጠም ወይም ታግዷል (ሁኔታ፦ " . htmlspecialchars($libUser['status']) . ")። እባክዎ አስተዳዳሪውን ያነጋግሩ።");
        return;
    }

    $memberId = (int)($libUser['member_id'] ?? 0);

    // 1. Borrowed Books
    if ($text === '📚 የተዋስኳቸው መጻሕፍት' || $text === '📚 ያዋስኳቸው መጻሕፍት' || $text === '/mybooks') {
        if (!$memberId) {
            telegram_send($conn, $chatId, "የአባልነት መረጃ አልተገኘም።");
            return;
        }

        $stmtB = mysqli_prepare($conn, "SELECT br.*, b.title 
                                        FROM borrow_records br 
                                        JOIN books b ON b.id = br.book_id 
                                        WHERE br.member_id = ? AND br.status = 'borrowed' 
                                        ORDER BY br.due_date ASC");
        if ($stmtB) {
            mysqli_stmt_bind_param($stmtB, 'i', $memberId);
            mysqli_stmt_execute($stmtB);
            $resB = mysqli_stmt_get_result($stmtB);

            if (mysqli_num_rows($resB) === 0) {
                telegram_send($conn, $chatId, "📚 በአሁኑ ሰዓት በእጅዎ ላይ ያለ መጽሐፍ የለም።");
            } else {
                $out = "📚 <b>በእጅዎ ያሉ መጻሕፍት፦</b>\n\n";
                while ($br = mysqli_fetch_assoc($resB)) {
                    $bTitle = htmlspecialchars($br['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $due = htmlspecialchars(formatDate($br['due_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $out .= "📖 <b>{$bTitle}</b>\n📅 የመመለሻ ቀን፦ {$due}\n\n";
                }
                telegram_send($conn, $chatId, $out);
            }
            mysqli_stmt_close($stmtB);
        }
        return;
    }

    // 2. Monthly Payment Status
    if ($text === '💰 ወርሃዊ ክፍያ' || $text === '/payment') {
        if (!$memberId) {
            telegram_send($conn, $chatId, "የአባልነት መረጃ አልተገኘም።");
            return;
        }
        $currMonth = date('Y-m');
        $stmtP = mysqli_prepare($conn, "SELECT * FROM membership_payments WHERE member_id = ? AND payment_month = ? LIMIT 1");
        if ($stmtP) {
            mysqli_stmt_bind_param($stmtP, 'is', $memberId, $currMonth);
            mysqli_stmt_execute($stmtP);
            $pay = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtP));
            mysqli_stmt_close($stmtP);

            $mLabel = htmlspecialchars(format_billing_month_amharic($currMonth), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($pay) {
                $amt = number_format((float)$pay['amount'], 2);
                telegram_send($conn, $chatId, "✅ <b>የ{$mLabel} ወርሃዊ ክፍያዎ ተከፍሏል!</b>\nየተከፈለው መጠን፦ <b>{$amt} ብር</b>");
            } else {
                telegram_send($conn, $chatId, "⚠️ <b>የ{$mLabel} ወርሃዊ ክፍያ እስካሁን አልተከፈለም።</b>\nእባክዎ ወደ ቤተ-መጻሕፍቱ በመምጣት ክፍያዎን ያጠናቁ።");
            }
        }
        return;
    }

    // 3. Fines
    if ($text === '⚠️ የቅጣት መረጃ' || $text === '/fines') {
        if (!$memberId) {
            telegram_send($conn, $chatId, "የአባልነት መረጃ አልተገኘም።");
            return;
        }
        $stmtF = mysqli_prepare($conn, "SELECT COALESCE(SUM(overdue_fine - fine_paid - fine_waived), 0) AS total_fine 
                                        FROM borrow_records 
                                        WHERE member_id = ? AND status = 'borrowed'");
        if ($stmtF) {
            mysqli_stmt_bind_param($stmtF, 'i', $memberId);
            mysqli_stmt_execute($stmtF);
            $rowF = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtF));
            mysqli_stmt_close($stmtF);

            $fine = (float)($rowF['total_fine'] ?? 0);
            if ($fine > 0) {
                $fStr = number_format($fine, 2);
                telegram_send($conn, $chatId, "⚠️ <b>ያለብዎት ያልተከፈለ ቅጣት፦ {$fStr} ብር</b>\nእባክዎ መጽሐፉን በፍጥነት በመመለስ ቅጣትዎን ይክፈሉ።");
            } else {
                telegram_send($conn, $chatId, "✅ <b>ምንም አይነት ያልተከፈለ ቅጣት የለብዎትም። እናመሰግናለን!</b>");
            }
        }
        return;
    }

    // 4. Digital Member Card
    if ($text === '🪪 የእኔ ዲጂታል ካርድ' || $text === '/card') {
        $cName = htmlspecialchars($libUser['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cPhone = htmlspecialchars($libUser['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cClass = htmlspecialchars($libUser['class'] ?? 'የተማሪ ክፍል አልተገለጸም', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cStudId = htmlspecialchars($libUser['student_id'] ?? 'አልተሰጠም', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $cardInfo = "🪪 <b>የአጸደ ቤተ-መጻሕፍት ዲጂታል ካርድ</b>\n\n" .
                    "🆔 <b>መለያ ቁጥር፦</b> <code>{$cStudId}</code>\n" .
                    "👤 <b>ስም፦</b> {$cName}\n" .
                    "📱 <b>ስልክ፦</b> {$cPhone}\n" .
                    "🏫 <b>ክፍል፦</b> {$cClass}\n";
        telegram_send($conn, $chatId, $cardInfo);
        return;
    }

    // 4.1 Admin Member Search by ID / Name / Phone (Admin/Librarian Only)
    if ((str_starts_with($text, '/member') || str_starts_with($text, '👤 አባል ፈልግ')) && in_array($libUser['role'] ?? '', ['admin', 'librarian'])) {
        $term = trim(str_replace(['/member', '👤 አባል ፈልግ (በID)', '👤 አባል ፈልግ'], '', $text));
        if ($term === '') {
            telegram_send($conn, $chatId, "👤 <b>አባል ለመፈለግ፦</b>\nእባክዎ የመታወቂያ ቁጥር (ID)፣ ስም ወይም ስልክ ያስገቡ፦\nምሳሌ፦ <code>/member አጸደቤይ01</code> ወይም <code>/member 01</code>");
            return;
        }

        $cleanTerm = format_member_student_id($term);
        $escaped = mysqli_real_escape_string($conn, $term);
        $escapedClean = mysqli_real_escape_string($conn, $cleanTerm);

        $mQuery = mysqli_query($conn, "
            SELECT u.id, u.full_name, u.phone, u.status, m.class, m.student_id, m.id AS member_id,
              (SELECT COUNT(*) FROM borrow_records WHERE member_id=m.id AND status='borrowed') AS active_loans
            FROM users u 
            JOIN members m ON m.user_id = u.id 
            WHERE m.student_id = '$escaped' 
               OR m.student_id = '$escapedClean' 
               OR m.student_id LIKE '%$escaped%' 
               OR u.full_name LIKE '%$escaped%' 
               OR u.phone LIKE '%$escaped%' 
            LIMIT 5
        ");

        if (!$mQuery || mysqli_num_rows($mQuery) === 0) {
            telegram_send($conn, $chatId, "❌ '<b>" . htmlspecialchars($term) . "</b>' በሚል ምንም አባል አልተገኘም።");
        } else {
            $rep = "👥 <b>የአባላት ፍለጋ ውጤት፦</b>\n\n";
            while ($mb = mysqli_fetch_assoc($mQuery)) {
                $mbName = htmlspecialchars($mb['full_name']);
                $mbId = htmlspecialchars($mb['student_id'] ?: 'አልተሰጠም');
                $mbPhone = htmlspecialchars($mb['phone']);
                $mbClass = htmlspecialchars($mb['class'] ?: '—');
                $mbLoans = (int)$mb['active_loans'];
                $mbStatus = $mb['status'] === 'active' ? '✅ ንቁ' : '⚠️ ' . $mb['status'];
                $rep .= "🆔 <code>{$mbId}</code>\n" .
                        "👤 <b>ስም፦</b> {$mbName}\n" .
                        "📱 <b>ስልክ፦</b> {$mbPhone}\n" .
                        "🏫 <b>ክፍል፦</b> {$mbClass}\n" .
                        "📖 <b>የተዋሳቸው፦</b> {$mbLoans}\n" .
                        "📌 <b>ሁኔታ፦</b> {$mbStatus}\n\n";
            }
            telegram_send($conn, $chatId, $rep);
        }
        return;
    }

    // 5. Admin Statistics (Admin/Librarian Only)
    if (($text === '📊 የአድሚን ስታትስቲክስ' || $text === '/admin_stats') && in_array($libUser['role'] ?? '', ['admin', 'librarian'])) {
        $totalB = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books"))['c'];
        $borrowedC = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies WHERE status='borrowed'"))['c'];
        $availC = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies WHERE status='available'"))['c'];
        $pendingReq = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE status='pending'"))['c'];
        $membersC = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member' AND status='active'"))['c'];

        $statsMsg = "📊 <b>የአጸደ ቤተ-መጻሕፍት ወቅታዊ ስታትስቲክስ</b>\n\n" .
                    "📚 ጠቅላላ መጻሕፍት፦ <b>{$totalB}</b>\n" .
                    "📦 ዝግጁ ቅጂዎች፦ <b>{$availC}</b>\n" .
                    "📖 በውሰት ላይ ያሉ፦ <b>{$borrowedC}</b>\n" .
                    "👥 ንቁ አባላት፦ <b>{$membersC}</b>\n" .
                    "⏳ ያልተስተናገዱ የመዋስ ጥያቄዎች፦ <b>{$pendingReq}</b>\n";
        telegram_send($conn, $chatId, $statsMsg);
        return;
    }

    // 6. Admin Pending Requests (Admin/Librarian Only)
    if (($text === '📥 የመዋስ ጥያቄዎች' || $text === '/admin_requests') && in_array($libUser['role'] ?? '', ['admin', 'librarian'])) {
        $reqs = mysqli_query($conn, "SELECT br.*, b.title, u.full_name, u.phone 
                                     FROM borrow_requests br 
                                     JOIN books b ON b.id=br.book_id 
                                     JOIN members m ON m.id=br.member_id 
                                     JOIN users u ON u.id=m.user_id 
                                     WHERE br.status='pending' 
                                     ORDER BY br.created_at DESC LIMIT 5");
        $cnt = mysqli_num_rows($reqs);
        if ($cnt === 0) {
            telegram_send($conn, $chatId, "✅ በአሁኑ ሰዓት በመጠባበቅ ላይ ያለ ምንም የመዋስ ጥያቄ የለም።");
        } else {
            $reqMsg = "📥 <b>ያልተስተናገዱ የመዋስ ጥያቄዎች ({$cnt})፦</b>\n\n";
            while ($r = mysqli_fetch_assoc($reqs)) {
                $mName = htmlspecialchars($r['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $bTitle = htmlspecialchars($r['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $reqMsg .= "👤 <b>{$mName}</b> ({$r['phone']})\n📖 መጽሐፍ፦ {$bTitle}\n📅 የቀረበበት፦ " . formatDate($r['created_at']) . "\n\n";
            }
            telegram_send($conn, $chatId, $reqMsg);
        }
        return;
    }

    // 7. Check and Read Notifications (Syncs with website/app)
    if ($text === '🔔 ማሳወቂያዎች' || $text === '/notifications') {
        $uId = (int)$libUser['id'];
        $nRes = mysqli_query($conn, "SELECT * FROM notifications 
                                     WHERE (user_id = $uId OR user_id IS NULL) 
                                     ORDER BY created_at DESC LIMIT 5");
        if (mysqli_num_rows($nRes) === 0) {
            telegram_send($conn, $chatId, "🔔 ምንም ማሳወቂያ የለዎትም።");
        } else {
            $nMsg = "🔔 <b>የቅርብ ጊዜ ማሳወቂያዎች፦</b>\n\n";
            while ($nRow = mysqli_fetch_assoc($nRes)) {
                $nMsg .= "📌 <b>" . htmlspecialchars($nRow['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n" .
                         htmlspecialchars($nRow['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" .
                         "🕒 " . formatDate($nRow['created_at']) . "\n\n";
            }
            telegram_send($conn, $chatId, $nMsg);

            // Mark personal notifications as read in database
            mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = $uId");
            mysqli_query($conn, "INSERT IGNORE INTO notification_reads (user_id, notification_id) SELECT $uId, id FROM notifications WHERE user_id IS NULL");
        }
        return;
    }

    // Photo upload handler
    if ($hasPhoto && $isPrivateChat) {
        $largestPhoto = end($msg['photo']);
        $fileId = $largestPhoto['file_id'] ?? '';
        if ($fileId) {
            $avatarDir = __DIR__ . '/../uploads/avatars';
            $savedPath = downloadTelegramPhotoWebhook($botToken, $fileId, $avatarDir, $libUser['id']);
            if ($savedPath) {
                $stmtPhoto = mysqli_prepare($conn, "UPDATE users SET profile_photo = ? WHERE id = ?");
                if ($stmtPhoto) {
                    mysqli_stmt_bind_param($stmtPhoto, 'si', $savedPath, $libUser['id']);
                    mysqli_stmt_execute($stmtPhoto);
                    mysqli_stmt_close($stmtPhoto);
                }
                telegram_send($conn, $chatId, "✅ የመታወቂያ ፎቶዎ በተሳካ ሁኔታ ተቀይሯል!");
                return;
            }
        }
        telegram_send($conn, $chatId, "❌ ፎቶውን ማቀናበር አልተቻለም። እባክዎ እንደገና ይሞክሩ።");
        return;
    }
}

function handle_bot_inline_query(array $inlineQuery, mysqli $conn, string $botToken): void {
    $queryId = $inlineQuery['id'] ?? '';
    $queryText = trim($inlineQuery['query'] ?? '');

    $results = [];
    if ($queryText !== '') {
        $escaped = '%' . addcslashes($queryText, '%_') . '%';
        $stmt = mysqli_prepare($conn, "SELECT b.id, b.title, b.author, b.description,
                                              (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.id AND bc.status = 'available') AS available_copies 
                                       FROM books b 
                                       WHERE b.title LIKE ? OR b.author LIKE ? 
                                       LIMIT 10");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ss', $escaped, $escaped);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($b = mysqli_fetch_assoc($res)) {
                $title = htmlspecialchars($b['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $author = htmlspecialchars($b['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $avail = (int)$b['available_copies'];

                $results[] = [
                    'type' => 'article',
                    'id' => (string)$b['id'],
                    'title' => $b['title'],
                    'description' => "ደራሲ፦ {$b['author']} | ዝግጁ ኮፒዎች፦ {$avail}",
                    'input_message_content' => [
                        'message_text' => "📖 <b>{$title}</b>\n✍️ ደራሲ፦ {$author}\n📦 ዝግጁ ኮፒዎች፦ {$avail}",
                        'parse_mode' => 'HTML',
                    ]
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    telegram_api('answerInlineQuery', [
        'inline_query_id' => $queryId,
        'results' => $results,
        'cache_time' => 30,
    ], $botToken);
}

function handle_bot_callback_query(array $callbackQuery, mysqli $conn, string $botToken): void {
    $cbId = $callbackQuery['id'] ?? '';
    telegram_api('answerCallbackQuery', ['callback_query_id' => $cbId], $botToken);
}
