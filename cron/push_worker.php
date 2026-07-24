<?php
/**
 * cron/push_worker.php
 *
 * Drains push_queue and delivers actual browser push notifications using
 * the minishlink/web-push library. Run this on a schedule, e.g. every minute:
 *   * * * * * php /path/to/atsede_library/cron/push_worker.php >> /var/log/atsede_push.log 2>&1
 *
 * SETUP (one-time):
 *   1. composer require minishlink/web-push   (run inside the project root)
 *   2. Generate VAPID keys and paste them into Admin → Settings → Push Notifications
 *   3. Make sure this file can run via CLI cron (not through the browser)
 *
 * Until composer is installed, this script safely does nothing (it checks
 * for the library and exits early) — in-app DB notifications still work
 * regardless, so the system is fully usable without this worker.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "minishlink/web-push not installed yet — run `composer require minishlink/web-push`. Skipping push delivery.\n");
    exit(0);
}
require_once $autoload;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$publicKey = get_setting($conn, 'vapid_public_key', '');
$privateKey = get_setting($conn, 'vapid_private_key', '');
if (!$publicKey || !$privateKey) {
    fwrite(STDERR, "VAPID keys not configured in Settings. Skipping push delivery.\n");
    exit(0);
}

$webPush = new WebPush([
    'VAPID' => [
        'subject' => 'mailto:library@example.org',
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
    ],
]);

$queue = mysqli_query($conn, "
    SELECT pq.id, pq.payload, ps.endpoint, ps.p256dh, ps.auth
    FROM push_queue pq JOIN push_subscriptions ps ON ps.id = pq.subscription_id
    WHERE pq.sent = 0 LIMIT 200");

while ($row = mysqli_fetch_assoc($queue)) {
    $subscription = Subscription::create([
        'endpoint' => $row['endpoint'],
        'publicKey' => $row['p256dh'],
        'authToken' => $row['auth'],
    ]);
    $webPush->queueNotification($subscription, $row['payload']);
    mysqli_query($conn, "UPDATE push_queue SET sent=1 WHERE id=" . (int)$row['id']);
}

foreach ($webPush->flush() as $report) {
    if (!$report->isSuccess()) {
        fwrite(STDERR, "Push failed for " . $report->getEndpoint() . ": " . $report->getReason() . "\n");
        if ($report->isSubscriptionExpired()) {
            $endpoint = mysqli_real_escape_string($conn, $report->getEndpoint());
            mysqli_query($conn, "DELETE FROM push_subscriptions WHERE endpoint='$endpoint'");
        }
    }
}

echo "Push worker run complete.\n";
