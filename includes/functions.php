<?php
/**
 * includes/functions.php
 * Shared helpers used across the whole system.
 * Requires config.php ($conn) to already be loaded.
 */

require_once __DIR__ . '/lang.php';

// ---------------------------------------------------------------
// AUTH / ROLE GUARDS
// ---------------------------------------------------------------
function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_logged_in() {
    return isset($_SESSION['user']);
}

function require_login() {
    global $conn;
    if (!is_logged_in()) {
        redirect(rel_base() . 'login.php');
        return;
    }
    
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        $_SESSION = [];
        redirect(rel_base() . 'login.php');
        return;
    }

    if ($conn) {
        $stmt = mysqli_prepare($conn, "SELECT id, status, role FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$userRow || $userRow['status'] !== 'active' || is_member_blocked($conn, $userId)) {
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
                }
                @session_destroy();
                redirect(rel_base() . 'login.php?error=blocked');
                return;
            }

            $_SESSION['user']['role'] = $userRow['role'];
            $_SESSION['user']['status'] = $userRow['status'];
        }
    }
}

function require_role($roles) {
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center">
              <h2>403 — መግባት አይፈቀድም</h2><p>ይህን ገጽ ለመመልከት ስልጣን የለዎትም።</p>
              <a href="' . rel_base() . 'index.php">ወደ መግቢያ ይመለሱ</a></div>');
    }
}

// Works out how many "../" are needed to reach the project root from the current script
function rel_base() {
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $rootDir = realpath(__DIR__ . '/..');
    if ($scriptFile && $rootDir && strpos($scriptFile, $rootDir) === 0) {
        $relPath = trim(substr(dirname($scriptFile), strlen($rootDir)), DIRECTORY_SEPARATOR);
        $depth = ($relPath === '') ? 0 : count(explode(DIRECTORY_SEPARATOR, $relPath));
        return $depth === 0 ? './' : str_repeat('../', $depth);
    }
    return './';
}

function redirect($url) {
    header("Location: $url");
    if (!defined('PHPUNIT_RUNNING')) exit;
}

// ---------------------------------------------------------------
// LOGIN & REGISTRATION THROTTLING (10 MISTAKES -> 20 MIN BLOCK)
// ---------------------------------------------------------------
function get_client_ip() {
    return clean($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

/**
 * Check if identifier or IP is throttled (10 failed attempts within 20 minutes = 1200 seconds)
 */
function check_login_throttle($conn, $identifier, $ip = null) {
    if (!$conn) return ['blocked' => false];
    $ip = $ip ?: get_client_ip();
    $cutoff = time() - 1200; // 20 minutes window

    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS cnt, MIN(attempt_time) AS oldest_time 
        FROM login_attempts 
        WHERE (identifier = ? OR ip_address = ?) AND attempt_time > ?
    ");
    if (!$stmt) return ['blocked' => false];

    mysqli_stmt_bind_param($stmt, 'ssi', $identifier, $ip, $cutoff);
    mysqli_stmt_execute($stmt);
    $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $cnt = (int)($res['cnt'] ?? 0);
    if ($cnt >= 10) {
        $remaining = max(1, 1200 - (time() - (int)$res['oldest_time']));
        $remMin = ceil($remaining / 60);
        return [
            'blocked' => true,
            'retry_after' => $remaining,
            'message' => "በጣም ብዙ የተሳሳቱ የይለፍ ቃል ሙከራዎች ተደርገዋል። መለያው ለ 20 ደቂቃ ተቆልፏል (ከ {$remMin} ደቂቃ በኋላ እንደገና ይሞክሩ)።"
        ];
    }

    return ['blocked' => false, 'attempts' => $cnt];
}

function record_login_attempt($conn, $identifier, $ip = null) {
    if (!$conn) return;
    $ip = $ip ?: get_client_ip();
    $now = time();
    $stmt = mysqli_prepare($conn, "INSERT INTO login_attempts (identifier, ip_address, attempt_time) VALUES (?, ?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssi', $identifier, $ip, $now);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function clear_login_attempts($conn, $identifier, $ip = null) {
    if (!$conn) return;
    $ip = $ip ?: get_client_ip();
    $stmt = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE identifier = ? OR ip_address = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $identifier, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function check_register_throttle($conn, $ip = null) {
    if (!$conn) return ['blocked' => false];
    $ip = $ip ?: get_client_ip();
    $cutoff = time() - 3600; // 1 hour window

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM register_attempts WHERE ip_address = ? AND attempt_time > ?");
    if (!$stmt) return ['blocked' => false];

    mysqli_stmt_bind_param($stmt, 'si', $ip, $cutoff);
    mysqli_stmt_execute($stmt);
    $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $cnt = (int)($res['cnt'] ?? 0);
    if ($cnt >= 5) {
        return [
            'blocked' => true,
            'message' => 'በዚህ ሰዓት ውስጥ ከተፈቀደው በላይ የምዝገባ ሙከራ አድርገዋል። እባክዎ ከጥቂት ጊዜ በኋላ ይሞክሩ።'
        ];
    }
    return ['blocked' => false];
}

function record_register_attempt($conn, $ip = null) {
    if (!$conn) return;
    $ip = $ip ?: get_client_ip();
    $now = time();
    $stmt = mysqli_prepare($conn, "INSERT INTO register_attempts (ip_address, attempt_time) VALUES (?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'si', $ip, $now);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

// ---------------------------------------------------------------
// CSRF PROTECTION
// ---------------------------------------------------------------
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify() {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('የእርስዎ ክፍለ ጊዜ አልቋል። እባክዎ ተመልሰው እንደገና ይሞክሩ።');
    }
}

// ---------------------------------------------------------------
// INPUT HELPERS
// ---------------------------------------------------------------
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function clean($str) {
    return trim(strip_tags($str ?? ''));
}

function flash($key, $msg = null, $type = 'info') {
    if ($msg === null) {
        if (isset($_SESSION['flash'][$key])) {
            $f = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $f;
        }
        return null;
    }
    $_SESSION['flash'][$key] = ['msg' => $msg, 'type' => $type];
}

// ---------------------------------------------------------------
// NOTIFICATIONS
// ---------------------------------------------------------------
function notify($conn, $userId, $title, $message, $type = 'general', $link = null) {
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, title, message, type, link) VALUES (?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'issss', $userId, $title, $message, $type, $link);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    send_push_to_user($conn, $userId, $title, $message, $link);
}

function notify_broadcast($conn, $title, $message, $type = 'general', $link = null) {
    notify($conn, null, $title, $message, $type, $link);
}

function unread_count($conn, $userId) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return (int)($row['c'] ?? 0);
}

// ---------------------------------------------------------------
// WEB PUSH (force notification even when app is closed)
// Requires VAPID keys set in `settings` table (see PUSH_SETUP.md)
// ---------------------------------------------------------------
function send_push_to_user($conn, $userId, $title, $body, $link = null) {
    $sql = $userId
        ? "SELECT id FROM push_subscriptions WHERE user_id = $userId"
        : "SELECT id FROM push_subscriptions";
    $res = mysqli_query($conn, $sql);
    if (!$res || mysqli_num_rows($res) === 0) return;

    $payload = json_encode(['title' => $title, 'body' => $body, 'link' => $link ?: rel_base() . 'index.php']);
    $payloadEsc = mysqli_real_escape_string($conn, $payload);

    while ($row = mysqli_fetch_assoc($res)) {
        $subId = (int)$row['id'];
        mysqli_query($conn, "INSERT INTO push_queue (subscription_id, payload) VALUES ($subId, '$payloadEsc')");
    }
}

// ---------------------------------------------------------------
// CHURCH-STYLE BOOK CODE GENERATOR
// Codes restart at 1 per category and append A/B/C...AA/AB for multiple copies.
// ---------------------------------------------------------------
function get_copy_code_suffix($index) {
    $suffix = '';
    while ($index >= 0) {
        $suffix = chr(65 + ($index % 26)) . $suffix;
        $index = intdiv($index, 26) - 1;
    }
    return $suffix;
}

function next_codes_for_category($conn, $categoryId, $quantity) {
    $categoryId = (int)$categoryId;
    // Lock category for update to prevent concurrent duplicate codes
    @mysqli_query($conn, "SELECT id FROM categories WHERE id = $categoryId FOR UPDATE");

    $stmt = mysqli_prepare($conn, "SELECT bc.copy_code FROM book_copies bc
        JOIN books b ON b.id = bc.book_id WHERE b.category_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $categoryId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $maxNumber = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        if (preg_match('/^(\d+)/', $row['copy_code'], $m)) {
            $maxNumber = max($maxNumber, (int)$m[1]);
        }
    }
    $next = $maxNumber + 1;
    $nextPadded = sprintf('%02d', $next);

    $codes = [];
    if ($quantity <= 1) {
        $codes[] = $nextPadded;
    } else {
        for ($i = 0; $i < $quantity; $i++) {
            $codes[] = $nextPadded . get_copy_code_suffix($i);
        }
    }
    return $codes;
}

// ---------------------------------------------------------------
// AUDIT LOG
// ---------------------------------------------------------------
function audit($conn, $userId, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = mysqli_prepare($conn, "INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $action, $details, $ip);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// ---------------------------------------------------------------
// SETTINGS
// ---------------------------------------------------------------
function get_setting($conn, $key, $default = null) {
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row ? $row['setting_value'] : $default;
}

function set_setting($conn, $key, $value) {
    $stmt = mysqli_prepare($conn, "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'sss', $key, $value, $value);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Secure image upload processor: validates MIME, checks getimagesize,
 * enforces size limit, re-encodes via GD to strip payloads, forces safe extension (.jpg).
 */
function secure_process_image($tmpFilePath, $targetDir, $prefix = 'img', $maxBytes = 5242880) {
    if (!file_exists($tmpFilePath)) {
        return ['success' => false, 'error' => 'የፋይል መገኛ አልተገኘም።'];
    }

    if (filesize($tmpFilePath) > $maxBytes) {
        return ['success' => false, 'error' => 'የምስሉ መጠን ከ 5MB መብለጥ የለበትም።'];
    }

    $imageInfo = @getimagesize($tmpFilePath);
    if ($imageInfo === false) {
        return ['success' => false, 'error' => 'ትክክለኛ የምስል ፋይል አይደለም።'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpFilePath);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'jpg', // re-encoded to jpg
        'image/webp' => 'jpg', // re-encoded to jpg
    ];

    if (!isset($allowedMimes[$mime])) {
        return ['success' => false, 'error' => 'የተፈቀዱ የምስል አይነቶች JPG, PNG ወይም WEBP ብቻ ናቸው።'];
    }

    $imgContent = file_get_contents($tmpFilePath);
    $gdImg = @imagecreatefromstring($imgContent);
    if (!$gdImg) {
        return ['success' => false, 'error' => 'ምስሉን ማቀናበር አልተቻለም (Corrupted image)።'];
    }

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    $randomName = $prefix . '_' . bin2hex(random_bytes(10)) . '.jpg';
    $targetPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $randomName;

    // Convert PNG transparency / palette if needed and save as JPEG to sanitize
    $width = imagesx($gdImg);
    $height = imagesy($gdImg);
    $trueColorImg = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($trueColorImg, 255, 255, 255);
    imagefilledrectangle($trueColorImg, 0, 0, $width, $height, $white);
    imagecopy($trueColorImg, $gdImg, 0, 0, 0, 0, $width, $height);

    $saved = imagejpeg($trueColorImg, $targetPath, 85);
    imagedestroy($gdImg);
    imagedestroy($trueColorImg);

    if (!$saved || !file_exists($targetPath)) {
        return ['success' => false, 'error' => 'ምስሉን ወደ ሰርቨር ማስቀመጥ አልተቻለም።'];
    }

    return [
        'success'   => true,
        'file_name' => $randomName,
        'full_path' => $targetPath,
    ];
}

// ---------------------------------------------------------------
// BORROW STATUS BADGE HELPERS
// ---------------------------------------------------------------
function borrow_status_label($status) {
    return [
        'available' => __('available'),
        'restricted' => __('restricted'),
        'reference' => __('reference'),
        'archived' => __('archived'),
    ][$status] ?? $status;
}

function borrow_status_message($status) {
    return [
        'restricted' => __('restricted_msg'),
        'reference' => __('reference_msg'),
        'archived' => 'ይህ መጽሐፍ በማህደር ውስጥ ነው፣ ለውሰት አይቀርብም።',
    ][$status] ?? null;
}

function borrow_status_class($status) {
    return [
        'available' => 'badge-success',
        'restricted' => 'badge-warning',
        'reference' => 'badge-gold',
        'archived' => 'badge-danger',
    ][$status] ?? 'badge-muted';
}

// ---------------------------------------------------------------
// BRANDING (church logo, used in header, sidebar, login, favicon,
// printable reports, and the PWA icon). Admin uploads it once from
// Settings; until then a neutral crest placeholder is shown.
// ---------------------------------------------------------------
function library_logo_path() {
    return __DIR__ . '/../uploads/logo.png';
}

function library_logo_url() {
    if (file_exists(library_logo_path())) {
        // Use absolute path from BASE_URL for consistent hosting behavior
        return (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/logo.png';
    }
    return null;
}

/**
 * Renders a book cover <img> if a cover exists, otherwise shows
 * the library logo as a branded placeholder (or a styled gradient
 * if no logo has been uploaded yet).
 *
 * Usage: echo book_cover_html($book['cover_image']);
 */
function book_cover_html($coverImage, $extraStyle = '') {
    if ($coverImage) {
        // Ensure BASE_URL is used for consistent paths
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
        $url = $baseUrl . 'uploads/covers/' . htmlspecialchars($coverImage, ENT_QUOTES, 'UTF-8');
        return '<img src="' . $url . '" alt="" style="width:100%;height:100%;object-fit:cover;' . $extraStyle . '" onerror="this.src=\'/uploads/logo.png\';">';
    }
    $logoUrl = library_logo_url();
    if ($logoUrl) {
        // outer div is position:relative + fills the slot;
        // inner .book-cover-logo-placeholder is position:absolute;inset:0
        return '<div style="width:100%;height:100%;position:relative;">'
             . '<div class="book-cover-logo-placeholder">'
             . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" class="book-logo-img">'
             . '</div></div>';
    }
    // Fallback: branded gradient with book icon
    return '<div style="width:100%;height:100%;position:relative;">'
         . '<div class="book-cover-logo-placeholder book-cover-gradient">'
         . '<i class="bi bi-book-half" style="font-size:2rem;color:rgba(255,255,255,0.7);position:relative;z-index:1;"></i>'
         . '</div></div>';
}

function library_name($conn) {
    return get_setting($conn, 'library_name', __('app_name'));
}

// ---------------------------------------------------------------
// MEMBER BLOCK (login + borrow ban for N days)
// ---------------------------------------------------------------
function member_block_row($conn, $userId) {
    $uid = (int)$userId;
    return mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT u.status, m.blocked_until FROM users u
        JOIN members m ON m.user_id = u.id WHERE u.id = $uid LIMIT 1"));
}

function is_member_blocked($conn, $userId) {
    $row = member_block_row($conn, $userId);
    if (!$row) return false;
    if ($row['status'] === 'suspended') return true;
    if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) return true;
    return false;
}

function member_block_message($conn, $userId) {
    $row = member_block_row($conn, $userId);
    if (!$row) return 'መለያዎ ታግዷል። ቤተ መጻሕፍቱን ያግኙ።';
    if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
        return 'መለያዎ እስከ ' . formatDate($row['blocked_until']) . ' ድረስ ታግዷል። መዋስ አይፈቀድም።';
    }
    return 'መለያዎ ታግዷል። ቤተ መጻሕፍቱን ያግኙ።';
}

function clear_expired_member_blocks($conn) {
    mysqli_query($conn, "
        UPDATE users u JOIN members m ON m.user_id = u.id
        SET u.status = 'active', m.blocked_until = NULL
        WHERE u.role = 'member' AND u.status = 'suspended'
          AND m.blocked_until IS NOT NULL AND m.blocked_until <= NOW()");
}

function next_shelf_name($conn, $roomId) {
    $roomId = (int)$roomId;
    $count = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM shelves WHERE room_id=$roomId"))['c'];
    return 'መደርደሪያ ' . ($count + 1);
}

function shelf_display_name($index) {
    return 'መደርደሪያ ' . ((int)$index + 1);
}

// ---------------------------------------------------------------
// MEMBERSHIP PAYMENT HELPERS
// Minimum payment = 50 ETB by default, configurable by Admin.
// Rules:
// - Paid amount >= minimum: PAID (ተከፍሏል)
// - Paid amount < minimum: NOT PAID (አልተከፈለም)
// - No debt, no negative balances.
// ---------------------------------------------------------------
function get_minimum_monthly_payment($conn) {
    return (float)get_setting($conn, 'minimum_monthly_payment', 50.0);
}

function current_billing_month() {
    $eth = gregorianToEthParts(date('Y-m-d'));
    if (!$eth) return date('Y-m');
    return sprintf('%04d-%02d', $eth['year'], $eth['month']);
}

function format_billing_month_amharic($ym) {
    if (empty($ym)) return '—';
    $parts = explode('-', $ym);
    if (count($parts) < 2) return $ym;
    $y = (int)$parts[0];
    $m = (int)$parts[1];

    // If year is Gregorian (> 2020), convert to Ethiopian
    if ($y > 2025) {
        $eth = gregorianToEthParts("$ym-01");
        if ($eth) {
            return get_ethiopian_month_name($eth['month']) . ' ' . $eth['year'] . ' ዓ.ም.';
        }
    }

    return get_ethiopian_month_name($m) . ' ' . $y . ' ዓ.ም.';
}

function get_member_payment_status($conn, $memberId, $billingMonth = null) {
    $memberId = (int)$memberId;
    if ($billingMonth === null) {
        $billingMonth = current_billing_month();
    }
    $minRequired = get_minimum_monthly_payment($conn);

    $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount), 0) AS total_paid FROM membership_payments WHERE member_id = ? AND payment_month = ?");
    mysqli_stmt_bind_param($stmt, 'is', $memberId, $billingMonth);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    $paidAmount = (float)($row['total_paid'] ?? 0);

    $isPaid = ($paidAmount >= $minRequired);
    return [
        'month' => $billingMonth,
        'month_label' => format_billing_month_amharic($billingMonth),
        'amount_paid' => $paidAmount,
        'minimum_required' => $minRequired,
        'is_paid' => $isPaid,
        'status' => $isPaid ? 'PAID' : 'NOT_PAID',
        'status_text' => $isPaid ? 'ተከፍሏል' : 'አልተከፈለም',
        'badge_class' => $isPaid ? 'badge-success' : 'badge-danger'
    ];
}

function get_member_payment_history($conn, $memberId, $monthsCount = 12) {
    $memberId = (int)$memberId;
    $minRequired = get_minimum_monthly_payment($conn);

    // Only show months since member registration, up to current month
    $memRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT created_at FROM members WHERE id=$memberId LIMIT 1"));
    $regDate = $memRow['created_at'] ?? date('Y-m-d');
    $regEth  = gregorianToEthParts($regDate) ?? ['year' => 2019, 'month' => 1];
    $curEth  = gregorianToEthParts(date('Y-m-d')) ?? ['year' => 2019, 'month' => 1];

    $history = [];

    // Build Ethiopian months from registration month up to current month
    $startYear  = $regEth['year'];
    $startMonth = $regEth['month'];
    $endYear    = $curEth['year'];
    $endMonth   = $curEth['month'];

    for ($y = $endYear; $y >= $startYear; $y--) {
        $mLimit = ($y === $endYear) ? $endMonth : 12;
        $mFloor = ($y === $startYear) ? $startMonth : 1;
        for ($m = $mLimit; $m >= $mFloor; $m--) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $history[$ym] = [
                'month' => $ym,
                'month_label' => format_billing_month_amharic($ym),
                'amount_paid' => 0.0,
                'minimum_required' => $minRequired,
                'is_paid' => false,
                'status_text' => 'አልተከፈለም',
                'records' => []
            ];
        }
    }

    // Always ensure current month exists
    $curYm = current_billing_month();
    if (!isset($history[$curYm])) {
        $history[$curYm] = [
            'month' => $curYm,
            'month_label' => format_billing_month_amharic($curYm),
            'amount_paid' => 0.0,
            'minimum_required' => $minRequired,
            'is_paid' => false,
            'status_text' => 'አልተከፈለም',
            'records' => []
        ];
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM membership_payments WHERE member_id = ? ORDER BY paid_at DESC");
    mysqli_stmt_bind_param($stmt, 'i', $memberId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $ym = $row['payment_month'];
        if (!isset($history[$ym])) {
            $history[$ym] = [
                'month' => $ym,
                'month_label' => format_billing_month_amharic($ym),
                'amount_paid' => 0.0,
                'minimum_required' => $minRequired,
                'is_paid' => false,
                'status_text' => 'አልተከፈለም',
                'records' => []
            ];
        }
        $history[$ym]['amount_paid'] += (float)$row['amount'];
        $history[$ym]['records'][] = $row;
    }

    foreach ($history as $ym => &$item) {
        $item['is_paid'] = ($item['amount_paid'] >= $item['minimum_required']);
        $item['status_text'] = $item['is_paid'] ? 'ተከፍሏል' : 'አልተከፈለም';
    }
    unset($item);

    krsort($history);
    return array_values($history);
}

function record_membership_payment($conn, $memberId, $month, $amount, $method = 'Cash', $recordedBy = null, $ref = null, $offlineUuid = null, $notes = null) {
    $memberId = (int)$memberId;
    $amount = (float)$amount;

    // Idempotency check with offline_uuid
    if ($offlineUuid) {
        $check = mysqli_query($conn, "SELECT id FROM membership_payments WHERE offline_uuid = '" . mysqli_real_escape_string($conn, $offlineUuid) . "' LIMIT 1");
        if ($check && mysqli_num_rows($check) > 0) {
            $existing = mysqli_fetch_assoc($check);
            return (int)$existing['id'];
        }
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO membership_payments 
        (member_id, payment_month, amount, payment_method, recorded_by, reference_number, offline_uuid, sync_status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', ?)");
    mysqli_stmt_bind_param($stmt, 'isdsisss', $memberId, $month, $amount, $method, $recordedBy, $ref, $offlineUuid, $notes);
    mysqli_stmt_execute($stmt);
    $paymentId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Fetch member user_id to notify
    $mRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id FROM members WHERE id = $memberId LIMIT 1"));
    if ($mRow) {
        $userId = (int)$mRow['user_id'];
        $monthLbl = format_billing_month_amharic($month);
        notify($conn, $userId, 'የአባልነት ክፍያ ተመዝግቧል', "ለ $monthLbl ወር " . number_format($amount, 2) . " ብር ክፍያዎ ተመዝግቧል። እናመሰግናለን።", 'payment_received', 'member/payments.php');
    }

    audit($conn, $recordedBy, 'membership_payment_recorded', "payment_id:$paymentId member_id:$memberId month:$month amount:$amount");
    return $paymentId;
}

// ---------------------------------------------------------------
// BOOK BORROWABILITY & STRICT ELIGIBILITY CHECKS
// ---------------------------------------------------------------
function is_book_borrowable($bookRow) {
    if (!isset($bookRow['is_borrowable'])) return true;
    return ((int)$bookRow['is_borrowable'] === 1);
}

function verify_borrow_eligibility($conn, $memberId, $bookId, $copyId = null) {
    $memberId = (int)$memberId;
    $bookId = (int)$bookId;

    // 1. Member check
    $mQuery = mysqli_query($conn, "SELECT m.*, u.status, u.full_name FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = $memberId LIMIT 1");
    $member = mysqli_fetch_assoc($mQuery);
    $memberValid = ($member && $member['status'] === 'active' && !is_member_blocked($conn, $member['user_id']));

    // 2. Monthly payment check
    $paymentStatus = get_member_payment_status($conn, $memberId);
    $paymentValid = $paymentStatus['is_paid'];

    // 3. Book check & borrowability
    $bQuery = mysqli_query($conn, "SELECT * FROM books WHERE id = $bookId LIMIT 1");
    $book = mysqli_fetch_assoc($bQuery);
    $bookExists = ($book !== null);
    $isReference = ($book && ($book['borrow_status'] ?? '') === 'reference');
    $bookBorrowable = ($bookExists && is_book_borrowable($book) 
                       && ($book['borrow_status'] ?? '') !== 'restricted' 
                       && ($book['borrow_status'] ?? '') !== 'archived'
                       && !$isReference);

    // 4. Physical copy check
    $copyAvailable = false;
    $foundCopyId = null;
    if ($copyId) {
        $cQuery = mysqli_query($conn, "SELECT * FROM book_copies WHERE id = " . (int)$copyId . " AND book_id = $bookId AND status = 'available' LIMIT 1");
        if ($cRow = mysqli_fetch_assoc($cQuery)) {
            $copyAvailable = true;
            $foundCopyId = (int)$cRow['id'];
        }
    } else {
        $cQuery = mysqli_query($conn, "SELECT id FROM book_copies WHERE book_id = $bookId AND status = 'available' LIMIT 1");
        if ($cRow = mysqli_fetch_assoc($cQuery)) {
            $copyAvailable = true;
            $foundCopyId = (int)$cRow['id'];
        }
    }

    // 5. Max active borrows check
    $maxBorrows = (int)($member['max_borrow_limit'] ?? get_setting($conn, 'max_active_borrows', 3));
    $activeBorrows = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id = $memberId AND status = 'borrowed'"))['c'];
    $borrowLimitOk = ($activeBorrows < $maxBorrows);

    // 6. Outstanding fines check
    $maxFineAllowed = (float)get_setting($conn, 'max_unpaid_fine', get_setting($conn, 'max_allowed_unpaid_fine', 0.00));
    $outstandingFine = (float)get_member_outstanding_fine($conn, $memberId);
    $fineOk = ($outstandingFine <= $maxFineAllowed);

    $canBorrow = ($memberValid && $paymentValid && $bookBorrowable && $copyAvailable && $borrowLimitOk && $fineOk);

    return [
        'can_borrow' => $canBorrow,
        'copy_id' => $foundCopyId,
        'member' => $member,
        'book' => $book,
        'checks' => [
            'member' => [
                'passed' => $memberValid,
                'title' => 'የአባል ማረጋገጫ',
                'detail' => $memberValid ? 'ንቁ አባል ተረጋግጧል' : ($member ? 'አባሉ ታግዷል ወይም አልጸደቀም' : 'አባሉ አልተገኘም')
            ],
            'payment' => [
                'passed' => $paymentValid,
                'title' => 'የዚህ ወር ክፍያ (' . $paymentStatus['month_label'] . ')',
                'detail' => $paymentValid 
                    ? 'ተከፍሏል (' . number_format($paymentStatus['amount_paid'], 2) . ' ብር)'
                    : 'አልተከፈለም (' . number_format($paymentStatus['amount_paid'], 2) . ' / ' . number_format($paymentStatus['minimum_required'], 2) . ' ብር)'
            ],
            'borrowable' => [
                'passed' => $bookBorrowable,
                'title' => 'መጽሐፉ ለመዋስ ተፈቅዷል',
                'detail' => $bookBorrowable 
                    ? 'ለመዋስ የተፈቀደ' 
                    : ($isReference ? 'የማጣቀሻ (Reference) መጽሐፍ በመሆኑ ከቤተ-መጻሕፍት ውጭ መዋስ አይፈቀድም' : ($book['non_borrowable_reason'] ?: 'ይህ መጽሐፍ ለመዋስ አይፈቀድም'))
            ],
            'availability' => [
                'passed' => $copyAvailable,
                'title' => 'አካላዊ ቅጂ ይገኛል',
                'detail' => $copyAvailable ? 'የሚገኝ ቅጂ አለ' : 'አሁን ለማበደር የሚገኝ ቅጂ የለም'
            ],
            'limit' => [
                'passed' => $borrowLimitOk,
                'title' => 'የውሰት ብዛት ገደብ',
                'detail' => $borrowLimitOk ? "በውሰት ላይ ያለ፦ $activeBorrows / $maxBorrows" : "ከፍተኛ የውሰት ገደብ ($maxBorrows) ላይ ደርሷል"
            ],
            'fines' => [
                'passed' => $fineOk,
                'title' => 'ያልተከፈለ ቅጣት',
                'detail' => $fineOk ? ($outstandingFine > 0 ? "ቅጣት አለ ($outstandingFine ብር) ግን ከተፈቀደው ($maxFineAllowed ብር) አይበልጥም" : 'ምንም ያልተከፈለ ቅጣት የለም') : "ያልተከፈለ ቅጣት አለ ($outstandingFine ብር)፤ መጀመሪያ መከፈል አለበት"
            ]
        ]
    ];
}

// ---------------------------------------------------------------
// QR CODE HELPERS
// ---------------------------------------------------------------
function resolve_copy_by_qr($conn, $qrIdentifier) {
    $qr = trim($qrIdentifier);
    // If a full URL is scanned (e.g. https://domain.com/qr.php?code=ATS-COPY-0001)
    if (strpos($qr, 'code=') !== false) {
        $parts = parse_url($qr);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            if (!empty($params['code'])) {
                $qr = trim($params['code']);
            }
        }
    }

    if (empty($qr)) {
        return null;
    }

    $baseSelect = "SELECT bc.*, b.title, b.author, b.description, b.cover_image, b.cover_original, b.cover_optimized, 
                          b.borrow_status, b.is_borrowable, b.non_borrowable_reason, b.price, b.publication_year, 
                          b.publisher, b.position, c.name AS category_name, r.name AS room_name, s.name AS shelf_name
                   FROM book_copies bc
                   JOIN books b ON b.id = bc.book_id
                   LEFT JOIN categories c ON c.id = b.category_id
                   LEFT JOIN rooms r ON r.id = b.room_id
                   LEFT JOIN shelves s ON s.id = b.shelf_id";

    // 1. Prioritize strict qr_identifier resolution
    $stmt1 = mysqli_prepare($conn, "$baseSelect WHERE bc.qr_identifier = ? LIMIT 1");
    if ($stmt1) {
        mysqli_stmt_bind_param($stmt1, 's', $qr);
        mysqli_stmt_execute($stmt1);
        $res1 = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt1));
        mysqli_stmt_close($stmt1);
        if ($res1) {
            return $res1;
        }
    }

    // 2. Fallback to copy_code resolution
    $stmt2 = mysqli_prepare($conn, "$baseSelect WHERE bc.copy_code = ? LIMIT 1");
    if ($stmt2) {
        mysqli_stmt_bind_param($stmt2, 's', $qr);
        mysqli_stmt_execute($stmt2);
        $res2 = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
        mysqli_stmt_close($stmt2);
        if ($res2) {
            return $res2;
        }
    }

    return null;
}

function get_qr_url($qrIdentifier) {
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $base = '/' . trim($base, '/') . '/';
    if ($base === '//') $base = '/';
    return $base . 'qr.php?code=' . urlencode($qrIdentifier);
}

// ---------------------------------------------------------------
// TELEGRAM BOT HELPERS
// ---------------------------------------------------------------

/**
 * Send a plain-text or HTML message to a Telegram chat, optionally with a custom keyboard.
 * Returns true on success, false on failure.
 */
/**
 * Unified Telegram API caller.
 * Handles rate limits (429), blocked bot status (403), SSL verification, and testing mocks.
 */
function telegram_api($method, array $payload = [], $botToken = null) {
    global $conn;

    // Check if testing mock transport is active
    if (class_exists('Tests\FakeTelegram', false)) {
        return \Tests\FakeTelegram::record($method, $payload);
    }

    $token = $botToken;
    if (!$token && isset($conn) && $conn instanceof mysqli) {
        $token = get_setting($conn, 'telegram_bot_token', '');
    }
    if (!$token) {
        return ['ok' => false, 'description' => 'Bot token not configured'];
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("Telegram API Error ($method): " . $err);
        return ['ok' => false, 'description' => $err];
    }

    $decoded = json_decode($res, true) ?: [];

    // Handle HTTP 429 (rate limiting)
    if ($httpCode === 429) {
        $retryAfter = (int)($decoded['parameters']['retry_after'] ?? 5);
        error_log("Telegram API rate limited (429). Retry after: {$retryAfter}s");
    }

    // Handle HTTP 403 (bot blocked by user)
    if ($httpCode === 403) {
        $chatId = (int)($payload['chat_id'] ?? 0);
        if ($chatId && isset($conn) && $conn instanceof mysqli) {
            $stmt = mysqli_prepare($conn, "UPDATE users SET telegram_joined = 0 WHERE telegram_chat_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $chatId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    return $decoded;
}

function telegram_send($conn, $chatId, $text, $replyMarkup = null) {
    if (!$chatId) return false;
    $payload = [
        'chat_id'    => (int)$chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }
    $res = telegram_api('sendMessage', $payload);
    return isset($res['ok']) && $res['ok'] === true;
}

/**
 * Generate secure one-time token for Telegram account binding (valid for 48 hours)
 */
function generate_telegram_verify_token($conn, $userId) {
    $rawToken = bin2hex(random_bytes(16));
    $hashedToken = hash('sha256', $rawToken);
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));

    $stmt = mysqli_prepare($conn, "UPDATE users SET telegram_verify_token = ?, telegram_verify_expires = ? WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssi', $hashedToken, $expires, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return $rawToken;
}

/**
 * Main Amharic Keyboard Menu for Telegram Bot
 */
function get_bot_main_keyboard() {
    return [
        'keyboard' => [
            [['text' => '📚 ያዋስኳቸው መጻሕፍት'], ['text' => '💰 ወርሃዊ ክፍያ']],
            [['text' => '⚠️ የቅጣት መረጃ'], ['text' => '🔍 መጽሐፍ ፈልግ']],
            [['text' => '🪪 የእኔ ዲጂታል ካርድ'], ['text' => '❓ እርዳታ']]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}

/**
 * Send Telegram message to a user by user_id (looks up their chat_id using prepared statement).
 */
function telegram_notify_user($conn, $userId, $text, $replyMarkup = null) {
    $uid = (int)$userId;
    $stmt = mysqli_prepare($conn, "SELECT telegram_chat_id, telegram_joined FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row || !$row['telegram_chat_id'] || empty($row['telegram_joined'])) return false;
    return telegram_send($conn, $row['telegram_chat_id'], $text, $replyMarkup);
}

/**
 * Send Telegram to ALL users who have verified their Telegram account.
 * Use for broadcasts from admin.
 */
function telegram_broadcast($conn, $text) {
    $res = mysqli_query($conn,
        "SELECT telegram_chat_id FROM users WHERE telegram_joined=1 AND telegram_chat_id IS NOT NULL");
    $sent = 0;
    while ($r = mysqli_fetch_assoc($res)) {
        if (telegram_send($conn, $r['telegram_chat_id'], $text)) $sent++;
        usleep(50000); // 50ms delay between messages (Telegram rate limit)
    }
    return $sent;
}

// ---------------------------------------------------------------
// OVERDUE FINE HELPERS  (5 ብር/ቀን)
// ---------------------------------------------------------------

/**
 * Calculate the current fine for a borrow record.
 * Fine = overdue_days * fine_per_day — already_paid — waived
 */
function calculate_overdue_fine($conn, $recordRow) {
    if (empty($recordRow['due_date']) || $recordRow['status'] === 'returned') return 0.0;
    $dueDate    = new DateTime($recordRow['due_date']);
    $today      = new DateTime(date('Y-m-d'));
    if ($today <= $dueDate) return 0.0; // not overdue yet

    $graceDays    = (int)get_setting($conn, 'fine_grace_days', 0);
    $diffDays     = (int)$today->diff($dueDate)->days;
    $overdueDays  = max(0, $diffDays - $graceDays);
    $finePerDay   = (float)get_setting($conn, 'overdue_fine_per_day', 5);
    $grossFine    = $overdueDays * $finePerDay;
    $alreadyPaid  = (float)($recordRow['fine_paid']   ?? 0);
    $waived       = (float)($recordRow['fine_waived'] ?? 0);
    $netFine      = max(0.0, $grossFine - $alreadyPaid - $waived);
    return round($netFine, 2);
}

/**
 * Update all active borrow_records with current overdue fines.
 * Called from cron or on-demand.
 */
function recalculate_all_fines($conn) {
    $finePerDay  = (float)get_setting($conn, 'overdue_fine_per_day', 5);
    $graceDays   = (int)get_setting($conn, 'fine_grace_days', 0);
    $today       = date('Y-m-d');

    mysqli_query($conn, "
        UPDATE borrow_records
        SET overdue_fine = GREATEST(0,
              (DATEDIFF('$today', due_date) - $graceDays) * $finePerDay
            ),
            last_fine_calc = '$today'
        WHERE status = 'borrowed'
          AND due_date < '$today'
    ");
}

/**
 * Get total outstanding fine for a member (unpaid + unwaived),
 * including returned/lost/damaged records where fine remains unpaid.
 */
function get_member_outstanding_fine($conn, $memberId) {
    $memberId = (int)$memberId;
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(GREATEST(0, overdue_fine - fine_paid - fine_waived)), 0) AS total
         FROM borrow_records
         WHERE member_id = $memberId
           AND status IN ('borrowed', 'returned', 'lost', 'damaged')
           AND overdue_fine > 0"));
    return max(0.0, (float)($row['total'] ?? 0));
}

/**
 * Record a fine payment (partial or full).
 */
function record_fine_payment($conn, $recordId, $memberId, $amount, $waived = 0, $recordedBy = null, $notes = '') {
    $recordId   = (int)$recordId;
    $memberId   = (int)$memberId;
    $amount     = (float)$amount;
    $waived     = (float)$waived;
    $recordedBy = $recordedBy ? (int)$recordedBy : null;
    $notesEsc   = mysqli_real_escape_string($conn, $notes);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO fine_payments (record_id, member_id, amount, waived, recorded_by, notes)
         VALUES (?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iiddis', $recordId, $memberId, $amount, $waived, $recordedBy, $notesEsc);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Update borrow_records cumulative paid/waived
    mysqli_query($conn,
        "UPDATE borrow_records
         SET fine_paid   = fine_paid   + $amount,
             fine_waived = fine_waived + $waived
         WHERE id = $recordId");

    audit($conn, $recordedBy, 'fine_payment_recorded', "record_id:$recordId amount:$amount waived:$waived");
}

/**
 * Waive a fine with mandatory reason.
 */
function waive_fine($conn, $recordId, $amount, $waivedBy = null, $reason = '') {
    $recordId = (int)$recordId;
    $amount   = (float)$amount;
    $reason   = trim($reason);

    if ($reason === '') {
        return ['success' => false, 'message' => 'የይቅርታ ምክንያት መጻፍ ግዴታ ነው።'];
    }
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'የይቅርታ መጠኑ ከ 0 ብር መብለጥ አለበት።'];
    }
    $rec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM borrow_records WHERE id = $recordId"));
    if (!$rec) {
        return ['success' => false, 'message' => 'የውሰት መዝገቡ አልተገኘም።'];
    }
    $memberId = (int)$rec['member_id'];
    record_fine_payment($conn, $recordId, $memberId, 0.00, $amount, $waivedBy, "ይቅርታ የተደረገበት ምክንያት፦ " . $reason);
    audit($conn, $waivedBy, 'fine_waived', "record_id:$recordId amount:$amount reason:$reason");
    return ['success' => true, 'message' => 'የቅጣት ይቅርታው ተመዝግቧል።'];
}

