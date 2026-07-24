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
    if (!is_logged_in()) {
        redirect(rel_base() . 'login.php');
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
    // Use BASE_URL if defined (from config.php), otherwise auto-detect
    if (defined('BASE_URL')) {
        // If BASE_URL is '/', we're at root level
        if (BASE_URL === '/') {
            $depth = 0;
        } else {
            // Calculate depth from BASE_URL
            $path = trim(BASE_URL, '/');
            $depth = $path === '' ? 0 : count(explode('/', $path));
        }
        return $depth === 0 ? './' : str_repeat('../', $depth);
    }
    
    // Fallback to original logic
    $depth = 0;
    $dir = dirname($_SERVER['SCRIPT_NAME']);
    $root = '/atsede_library';
    if (strpos($dir, $root) === 0) {
        $sub = trim(substr($dir, strlen($root)), '/');
        $depth = $sub === '' ? 0 : count(explode('/', $sub));
    }
    return $depth === 0 ? './' : str_repeat('../', $depth);
}

function redirect($url) {
    header("Location: $url");
    exit;
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
// Codes restart at 1 per category and append A/B/C for multiple copies.
// ---------------------------------------------------------------
function next_codes_for_category($conn, $categoryId, $quantity) {
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
            $codes[] = $nextPadded . chr(65 + $i); // A, B, C...
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
    $key = mysqli_real_escape_string($conn, $key);
    $res = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = '$key' LIMIT 1");
    $row = mysqli_fetch_assoc($res);
    return $row ? $row['setting_value'] : $default;
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
