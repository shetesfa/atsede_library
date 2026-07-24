<?php
/**
 * includes/lang.php
 * Amharic is the default and only active language right now.
 * Built as a dictionary so English can be re-enabled later without
 * touching every page — just add an 'en' => [...] array and a switcher.
 */

$GLOBALS['__LANG'] = [
    // Brand / nav
    'app_name' => 'አጸደ ቤተ መጻሕፍት',
    'home' => 'መግቢያ',
    'search' => 'ፍለጋ',
    'categories' => 'ምድቦች',
    'my_books' => 'የእኔ መጻሕፍት',
    'notifications' => 'ማሳወቂያዎች',
    'profile' => 'መገለጫ',
    'dashboard' => 'ዳሽቦርድ',
    'members' => 'አባላት',
    'librarians' => 'ቤተ-መጻሕፍት ኃላፊዎች',
    'books' => 'መጻሕፍት',
    'requests' => 'ጥያቄዎች',
    'returns' => 'ተመላሾች',
    'suggestions' => 'የመጽሐፍ ጥቆማዎች',
    'reports' => 'ሪፖርቶች',
    'settings' => 'ቅንብሮች',
    'send_notice' => 'ማሳወቂያ ይላኩ',
    'rooms_shelves' => 'አዳራሾችና መደርደሪያዎች',
    'more' => 'ተጨማሪ',
    'sign_out' => 'ይውጡ',
    'login' => 'ይግቡ',
    'join' => 'አባል ይሁኑ',

    // Common actions
    'save' => 'አስቀምጥ',
    'save_changes' => 'ለውጦችን አስቀምጥ',
    'cancel' => 'ይቅር',
    'add' => 'ጨምር',
    'edit' => 'አርም',
    'delete' => 'አጥፋ',
    'close' => 'ዝጋ',
    'approve' => 'አጽድቅ',
    'reject' => 'ውድቅ አድርግ',
    'submit' => 'አስገባ',
    'send' => 'ይላኩ',
    'back' => 'ተመለስ',
    'see_all' => 'ሁሉንም ይዩ',
    'search_btn' => 'ይፈልጉ',
    'confirm' => 'አረጋግጥ',

    // Auth
    'username' => 'የተጠቃሚ ስም',
    'password' => 'የሚስጥር ቁልፍ',
    'current_password' => 'የአሁኑ የሚስጥር ቁልፍ',
    'new_password' => 'አዲስ የሚስጥር ቁልፍ',
    'full_name' => 'ሙሉ ስም',
    'phone' => 'ስልክ ቁጥር',
    'class' => 'ክፍል (ደረጃ)',
    'student_id' => 'የተማሪ መታወቂያ ቁጥር',
    'welcome_back' => 'እንኳን ደህና መጡ',
    'sign_in_sub' => 'ወደ አጸደ ቤተ መጻሕፍት ይግቡ',
    'create_account' => 'የአባልነት መለያ ይክፈቱ',
    'already_member' => 'አባል ነዎት?',
    'new_here' => 'አዲስ ነዎት?',
    'continue_as_guest' => 'እንደ እንግዳ ይቀጥሉ',

    // Book fields
    'book_name' => 'የመጽሐፍ ስም',
    'author' => 'ደራሲ',
    'category' => 'ምድብ',
    'quantity' => 'ብዛት',
    'publication_year' => 'የታተመበት ዓመት',
    'publisher' => 'አሳታሚ',
    'price' => 'ዋጋ',
    'description' => 'መግለጫ',
    'cover_image' => 'የመጽሐፍ ሽፋን ምስል',
    'room' => 'አዳራሽ',
    'shelf' => 'መደርደሪያ',
    'position' => 'አቀማመጥ',
    'shelf_code' => 'የመደርደሪያ ኮድ',
    'borrow_status' => 'የውሰት ሁኔታ',

    // Statuses
    'available' => 'ይገኛል',
    'restricted' => 'ለውሰት ያልተፈቀደ',
    'reference' => 'ለንባብ ብቻ',
    'archived' => 'ማህደር',
    'borrowed' => 'ተወስዷል',
    'lost' => 'ጠፍቷል',
    'damaged' => 'ተጎድቷል',
    'pending' => 'በመጠባበቅ ላይ',
    'approved' => 'ጸድቷል',
    'rejected' => 'ተቀባይነት አላገኘም',
    'purchased' => 'ተገዝቷል',
    'active' => 'ንቁ',
    'suspended' => 'ታግዷል',

    // Messages
    'restricted_msg' => 'ይህ መጽሐፍ ለውሰት አይፈቀድም።',
    'reference_msg' => 'በቤተ መጻሕፍት ውስጥ ብቻ ይነበብ።',
    'no_books_found' => 'መጽሐፍ አልተገኘም',
    'request_this_book' => 'ይህን መጽሐፍ ይጠይቁ',
    'pending_approval' => 'ለማረጋገጫ በመጠባበቅ ላይ',
];

/**
 * Translate a key. Falls back to the key itself if missing,
 * so a typo shows up instead of crashing the page.
 */
function __($key) {
    return $GLOBALS['__LANG'][$key] ?? $key;
}
