# Enabling Real Push Notifications (PUSH_SETUP.md)

Atsede Library has **two notification layers**, and only the second needs setup:

1. **In-app notifications (works out of the box)** — every important event
   (borrow approved/rejected, new book, registration approved, suggestion
   approved, admin broadcast) writes a row to the `notifications` table.
   The bell icon polls `ajax/unread_count.php` every 30s and the
   `member/notifications.php`, `librarian/notifications.php`, and
   `admin/notifications.php` pages show the full feed. **This always works,
   with zero configuration.**

2. **Browser push notifications (works even when the tab/app is closed)** —
   this is real Web Push and requires a one-time setup:

## Steps

1. Install Composer dependencies in the project root:
   ```bash
   composer require minishlink/web-push
   ```

2. Generate a VAPID key pair. Easiest way, from the project root:
   ```bash
   php -r "require 'vendor/autoload.php'; 
   \$keys = Minishlink\WebPush\VAPID::createVapidKeys();
   echo 'Public: ' . \$keys['publicKey'] . PHP_EOL;
   echo 'Private: ' . \$keys['privateKey'] . PHP_EOL;"
   ```

3. Paste the **Public key** and **Private key** into
   **Admin → Settings → Push Notifications**, then save.

4. Schedule the worker to run every minute (crontab -e):
   ```
   * * * * * php /full/path/to/atsede_library/cron/push_worker.php >> /var/log/atsede_push.log 2>&1
   ```

5. Visit the site over **HTTPS** (Web Push requires a secure context — only
   `localhost` is exempt during local testing). When a logged-in member taps
   "Enable notifications" (shown automatically after login, or from
   Profile → Preferences), the browser will ask for permission and register
   a push subscription.

That's it — from then on, `notify()` and `notify_broadcast()` in
`includes/functions.php` automatically queue a push delivery in
`push_queue`, and the cron worker delivers it via the browser's push
service, showing a native notification even if Atsede Library isn't open.

## Troubleshooting
- No prompt appears → check the browser console; push requires HTTPS.
- Notifications stop arriving → check `push_queue` for unsent rows and the
  cron log for errors; expired subscriptions are pruned automatically.
- iOS Safari requires the PWA to be **installed to the home screen** before
  push permission can be granted (a Safari/WebKit limitation).
