# 📚 Atsede Library Management System (PWA)

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-Progressive%20Web%20App-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)

A feature-packed Progressive Web Application (PWA) designed for modern library operations, book indexing, borrowing workflows, and automated push notifications.

## ✨ Core Features
- 📖 **Catalog & Book Management**: Search books by title, category, author, and physical shelf/room location (`admin/rooms.php`, `search.php`).
- 👥 **Multi-Role Portal**: Dedicated portals for **Admin**, **Librarian**, and **Library Members** (`admin/`, `librarian/`, `member/`).
- 🔔 **Push Notifications & Worker**: Web push notification service worker integration (`sw.js`, `ajax/push_subscribe.php`, `cron/push_worker.php`).
- 📑 **Docx Import Tool**: Bulk import books from Word documents directly into database (`tools/import_books_from_docx.php`).
- 📱 **Offline Mode**: Works offline as a native-feeling Web App with manifest support (`assets/manifest.json`, `offline.php`).

## 🛠️ Setup Instructions
1. Clone into `htdocs`:
   ```bash
   git clone https://github.com/shetesfa/atsede_library.git
   ```
2. Import `database.sql` into phpMyAdmin.
3. Configure settings in `config.php`.
4. Open in browser: `http://localhost/atsede_library/`

---
Developed by **[shetesfa](https://github.com/shetesfa)**
