<?php
/**
 * includes/nav_config.php
 * Returns the navigation items for the current role, in Amharic.
 * Each item: key, label, icon (bootstrap-icons class), href (relative to BASE)
 */
function nav_items_for_role($role) {
    $base = rel_base();
    switch ($role) {
        case 'admin':
            return [
                ['key'=>'dashboard','label'=>__('dashboard'),'icon'=>'bi-speedometer2','href'=>$base.'admin/dashboard.php','primary'=>true],
                ['key'=>'members','label'=>__('members'),'icon'=>'bi-people','href'=>$base.'admin/members.php','primary'=>true],
                ['key'=>'librarians','label'=>__('librarians'),'icon'=>'bi-person-badge','href'=>$base.'admin/librarians.php','primary'=>true],
                ['key'=>'categories','label'=>__('categories'),'icon'=>'bi-tags','href'=>$base.'admin/categories.php'],
                ['key'=>'rooms','label'=>__('rooms_shelves'),'icon'=>'bi-door-open','href'=>$base.'admin/rooms.php'],
                ['key'=>'books','label'=>__('books'),'icon'=>'bi-book','href'=>$base.'librarian/books.php'],
                ['key'=>'suggestions','label'=>__('suggestions'),'icon'=>'bi-lightbulb','href'=>$base.'librarian/suggestions.php'],
                ['key'=>'reports','label'=>__('reports'),'icon'=>'bi-bar-chart','href'=>$base.'admin/reports.php'],
                ['key'=>'notifications_send','label'=>__('send_notice'),'icon'=>'bi-megaphone','href'=>$base.'admin/notifications.php','primary'=>true],
                ['key'=>'settings','label'=>__('settings'),'icon'=>'bi-gear','href'=>$base.'admin/settings.php'],
            ];
        case 'librarian':
            return [
                ['key'=>'dashboard','label'=>__('dashboard'),'icon'=>'bi-speedometer2','href'=>$base.'librarian/dashboard.php','primary'=>true],
                ['key'=>'requests','label'=>__('requests'),'icon'=>'bi-inbox','href'=>$base.'librarian/requests.php','primary'=>true],
                ['key'=>'books','label'=>__('books'),'icon'=>'bi-book','href'=>$base.'librarian/books.php','primary'=>true],
                ['key'=>'returns','label'=>__('returns'),'icon'=>'bi-arrow-return-left','href'=>$base.'librarian/returns.php'],
                ['key'=>'members','label'=>__('members'),'icon'=>'bi-people','href'=>$base.'librarian/members.php','primary'=>true],
                ['key'=>'suggestions','label'=>__('suggestions'),'icon'=>'bi-lightbulb','href'=>$base.'librarian/suggestions.php'],
            ];
        case 'member':
            return [
                ['key'=>'dashboard','label'=>__('home'),'icon'=>'bi-house','href'=>$base.'member/dashboard.php','primary'=>true],
                ['key'=>'browse','label'=>__('search'),'icon'=>'bi-search','href'=>$base.'search.php','primary'=>true],
                ['key'=>'mybooks','label'=>__('my_books'),'icon'=>'bi-journal-bookmark','href'=>$base.'member/my_books.php','primary'=>true],
                ['key'=>'notifications','label'=>__('notifications'),'icon'=>'bi-bell','href'=>$base.'member/notifications.php','primary'=>true],
                ['key'=>'profile','label'=>__('profile'),'icon'=>'bi-person-circle','href'=>$base.'member/profile.php','primary'=>true],
                ['key'=>'suggest','label'=>__('suggestions'),'icon'=>'bi-lightbulb','href'=>$base.'member/suggestions.php'],
            ];
        default: // guest
            return [
                ['key'=>'home','label'=>__('home'),'icon'=>'bi-house','href'=>$base.'index.php','primary'=>true],
                ['key'=>'browse','label'=>__('search'),'icon'=>'bi-search','href'=>$base.'search.php','primary'=>true],
                ['key'=>'categories','label'=>__('categories'),'icon'=>'bi-grid','href'=>$base.'index.php#categories','primary'=>true],
                ['key'=>'register','label'=>__('join'),'icon'=>'bi-person-plus','href'=>$base.'register.php','primary'=>true],
                ['key'=>'login','label'=>__('login'),'icon'=>'bi-box-arrow-in-right','href'=>$base.'login.php','primary'=>true],
            ];
    }
}
