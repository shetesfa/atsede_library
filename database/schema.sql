-- =========================================================
-- ATSEDE LIBRARY MANAGEMENT SYSTEM
-- Database schema (MySQL 8+)
-- =========================================================

CREATE DATABASE IF NOT EXISTS atsede_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE atsede_library;

-- ---------------------------------------------------------
-- USERS (login identity, shared by admin / librarian / member)
-- ---------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','librarian','member') NOT NULL DEFAULT 'member',
    status ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending',
    avatar VARCHAR(255) DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- MEMBERS (extra profile data for role=member)
-- ---------------------------------------------------------
CREATE TABLE members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    class VARCHAR(60) DEFAULT NULL,
    student_id VARCHAR(60) DEFAULT NULL,
    max_borrow_limit INT NOT NULL DEFAULT 3,
    blocked_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- LIBRARIANS (extra profile data for role=librarian)
-- ---------------------------------------------------------
CREATE TABLE librarians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    assigned_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ROOMS & SHELVES (physical location)
-- ---------------------------------------------------------
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE shelves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    name VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- CATEGORIES
-- ---------------------------------------------------------
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    icon VARCHAR(40) DEFAULT 'bi-book',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BOOKS (a title, not a physical copy)
-- ---------------------------------------------------------
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    publication_year YEAR DEFAULT NULL,
    publisher VARCHAR(150) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    room_id INT DEFAULT NULL,
    shelf_id INT DEFAULT NULL,
    position VARCHAR(60) DEFAULT NULL,
    borrow_status ENUM('available','restricted','reference','archived') NOT NULL DEFAULT 'available',
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (shelf_id) REFERENCES shelves(id) ON DELETE SET NULL,
    FULLTEXT KEY ft_search (title, author)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BOOK COPIES (each physical copy, with church-style code)
-- ---------------------------------------------------------
CREATE TABLE book_copies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    copy_code VARCHAR(20) NOT NULL,
    status ENUM('available','borrowed','lost','damaged','archived') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_book_code (book_id, copy_code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BORROW REQUESTS (member asks, librarian approves/rejects)
-- ---------------------------------------------------------
CREATE TABLE borrow_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    book_id INT NOT NULL,
    type ENUM('borrow','reserve') NOT NULL DEFAULT 'borrow',
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME DEFAULT NULL,
    decided_by INT DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BORROW RECORDS (an approved request becomes an active loan)
-- ---------------------------------------------------------
CREATE TABLE borrow_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT DEFAULT NULL,
    member_id INT NOT NULL,
    book_copy_id INT NOT NULL,
    book_id INT NOT NULL,
    borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NOT NULL,
    returned_at DATETIME DEFAULT NULL,
    status ENUM('borrowed','returned','overdue','lost') NOT NULL DEFAULT 'borrowed',
    issued_by INT DEFAULT NULL,
    returned_to INT DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (book_copy_id) REFERENCES book_copies(id),
    FOREIGN KEY (book_id) REFERENCES books(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BOOK SUGGESTIONS
-- ---------------------------------------------------------
CREATE TABLE book_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT DEFAULT NULL,
    guest_name VARCHAR(120) DEFAULT NULL,
    guest_phone VARCHAR(30) DEFAULT NULL,
    book_name VARCHAR(200) NOT NULL,
    author VARCHAR(150) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    total_requests INT NOT NULL DEFAULT 1,
    status ENUM('pending','approved','rejected','purchased') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,          -- NULL = broadcast to all members
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    type VARCHAR(40) NOT NULL DEFAULT 'general',
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- PUSH SUBSCRIPTIONS (Web Push - PWA force notification)
-- ---------------------------------------------------------
CREATE TABLE push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_endpoint (endpoint(255)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- PUSH QUEUE (pending web-push deliveries, drained by cron/push_worker.php)
-- ---------------------------------------------------------
CREATE TABLE push_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    payload TEXT NOT NULL,
    sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- SETTINGS (key/value site settings)
-- ---------------------------------------------------------
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- AUDIT LOGS
-- ---------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(150) NOT NULL,
    details VARCHAR(500) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- SEED DATA
-- =========================================================

INSERT INTO categories (name, description, icon) VALUES
('የሃይማኖት መጽሐፍት ክፍል', 'የቤተ ክርስቲያን ትምህርትና ሃይማኖታዊ ጥናት', 'bi-mortarboard'),
('የታሪክ መጽሐፍት ክፍል', 'የቤተ ክርስቲያንና የዓለም ታሪክ', 'bi-hourglass-split'),
('የኮርስ መስጫ መጻሕፍት ክፍል', 'የትምህርት ቤት ኮርስ መጻሕፍት', 'bi-journal-bookmark'),
('የመንፈሳዊ ሕይወት ክፍል', 'ጸሎት፣ ጾምና አምልኮ', 'bi-droplet'),
('የመጽሐፍ ቅዱስ ጥናት ክፍል', 'ቅዱስ መጽሐፍና ትርጓሜ', 'bi-book'),
('የሕፃናት መጻሕፍት ክፍል', 'ለሕፃናትና ለወጣቶች', 'bi-emoji-smile'),
('የጠቅላላ ንባብ ክፍል', 'ጠቅላላ ንባብ', 'bi-stack'),
('ሌሎች', 'ሌላ ምድብ ያልገባ', 'bi-three-dots');

INSERT INTO rooms (name) VALUES ('ዋናው አዳራሽ'), ('የንባብ አዳራሽ');
INSERT INTO shelves (room_id, name) VALUES (1,'መደርደሪያ 1'), (1,'መደርደሪያ 2'), (2,'መደርደሪያ 1');

INSERT INTO settings (setting_key, setting_value) VALUES
('library_name', 'አጸደ ቤተ መጻሕፍት'),
('borrow_days', '14'),
('max_active_borrows', '3'),
('allow_self_registration', '1'),
('vapid_public_key', ''),
('vapid_private_key', '');

-- Default admin account: username "admin" password "Admin@123" (change immediately!)
INSERT INTO users (full_name, phone, username, password, role, status) VALUES
('የቤተ መጻሕፍት አስተዳዳሪ', '0900000000', 'admin', '$2y$10$8KqK2yqM8nE4kP1pXWqz7eOQ1l0u9C2g9b3yQ4mYV9j0F1H2I3J4K', 'admin', 'active');
-- ማስታወሻ፦ ከላይ ያለው የሚስጥር ቁልፍ hash setup.php በማስኬድ (የሚመረጥ) በትክክል ይዘመናል
-- "Admin@123" የሚለውን ቁልፍ በPHP's password_hash() በሰርቪሩ ላይ በትክክል በማመንጨት።
