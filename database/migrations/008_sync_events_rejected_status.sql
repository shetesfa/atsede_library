-- 008: sync_events.status must allow 'rejected'.
-- ajax/offline_sync.php writes 'rejected' for events the server refuses
-- (e.g. a borrow of an unavailable copy); the original enum did not include it.
ALTER TABLE `sync_events`
  MODIFY `status` ENUM('pending','synced','failed','rejected') NOT NULL DEFAULT 'pending';
