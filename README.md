# Atsede Library — Church & School Library Management System

A modern, mobile-first PWA built with PHP 8+, MySQL, vanilla JS and
Bootstrap 5 grid/utilities. Navy (#0F172A) + Gold (#D4AF37) theme, with
church-style shelf codes instead of generic Dewey-style codes.

## Quick start

1. Create a MySQL database (or let `setup.php` do it for you).
2. Edit `config.php` with your DB host/user/password/database name.
3. Visit `https://yourdomain.com/atsede_library/setup.php` in a browser,
   choose an admin password, and run setup. **Delete `setup.php` afterward.**
4. Log in at `login.php` with username `admin` and the password you chose.
5. (Optional but recommended) Follow `PUSH_SETUP.md` to enable real browser
   push notifications. In-app notifications work immediately without this.

## Roles

| Role      | Can do |
|-----------|--------|
| Guest     | Search, view categories/new books/availability, register, suggest a book |
| Member    | Everything a guest can, plus borrow/reserve requests, view history, get notified, suggest books, install as PWA |
| Librarian | Approve/reject requests, add/edit books & copies, process returns, view members & suggestions |
| Admin     | Manage librarians, approve registrations, manage categories, settings, broadcast notifications, view reports |

## Church-style shelf codes

Codes restart at `1` per category. A single copy gets `7`; three copies of
the next title in that category get `8A`, `8B`, `8C`. The system detects the
highest existing number per category automatically (`includes/functions.php
→ next_codes_for_category()`), and librarians can hand-edit any code from
**Books → Copies**.

## Folder structure

```
atsede_library/
├── config.php              DB connection + Ethiopian date helpers
├── setup.php                One-time installer (delete after use)
├── index.php, search.php, book.php, register.php, login.php, ...
├── includes/                 Shared header/footer/sidebar/bottomnav/functions
├── member/ librarian/ admin/  Role-specific pages
├── ajax/                     Small JSON endpoints (push, unread count, etc.)
├── assets/css/style.css      Design system (colors, cards, badges, forms…)
├── assets/js/app.js          Toasts, sheets, theme, PWA install, push
├── assets/manifest.php, sw.js     PWA installability + offline + push display (manifest is dynamic: reflects library name & uploaded logo)
├── database/schema.sql       Full schema + seed categories/settings
├── cron/push_worker.php      Drains push_queue via minishlink/web-push
└── PUSH_SETUP.md             How to turn on real browser push
```

## Security

- All queries use prepared statements or escaped input.
- Passwords hashed with `password_hash()` (bcrypt).
- CSRF token required on every state-changing form (`csrf_field()` / `csrf_verify()`).
- Role checks via `require_role()` on every protected page.
- Every meaningful action is written to `audit_logs`.

## Notes on scope

This is a complete, working foundation covering every flow in the brief —
search, borrow/reserve, approvals, returns, suggestions, registrations,
categories, notifications (in-app + push-ready), PWA install/offline, and
reports. Treat it as a strong v1 to deploy and iterate on: things you may
want to add next are email/SMS reminders for due dates, a librarian
activity log viewer, and CSV export on the reports page.

## Amharic interface

The entire UI (menus, buttons, forms, messages, dashboards, reports) is in
Amharic by default, driven by `includes/lang.php`. To add an English toggle
later, add an `'en' => [...]` array there and a small language switcher —
every page already calls `__('key')` instead of hardcoding English, except
for page-specific copy which was written directly in Amharic.

## Church logo

Upload your logo once from **Admin → Settings**. It is stored at
`uploads/logo.png` and automatically used in the sidebar, top bar, login
page, browser favicon, printable reports, and the PWA home-screen icon
(`assets/manifest.php` serves it dynamically). Until uploaded, a neutral
gold crest placeholder is shown everywhere instead.

## Church-style codes, duplicates, and rooms

- Codes are zero-padded per the registration documents (`01`, `02`, `04A`,
  `04B`, …) and restart at `01` for every category — see
  `next_codes_for_category()` in `includes/functions.php`.
- Adding a book with a title+author that already exists triggers a
  duplicate-warning sheet in **Librarian → Books**, letting you add copies
  to the existing title or create a separate record anyway.
- **Admin → Rooms & Shelves** manages halls and their shelves; the book
  form's Shelf dropdown automatically filters to the selected Room via
  `ajax/shelves_by_room.php`.

