-- Migration 007: Notification Deliveries, Reminder Log, and Broadcast Read Tracking
-- Idempotent schema creation for reliable multi-channel notifications

-- 1. Table for tracking delivery attempts across channels
CREATE TABLE IF NOT EXISTS `notification_deliveries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `notification_id` INT NOT NULL,
    `channel` ENUM('inapp', 'push', 'telegram') NOT NULL,
    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notif_id` (`notification_id`),
    INDEX `idx_channel_status` (`channel`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table for deduplicating daily reminders so cron never double-sends
CREATE TABLE IF NOT EXISTS `reminder_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `reminder_type` VARCHAR(50) NOT NULL,
    `reminder_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_member_reminder_day` (`member_id`, `reminder_type`, `reminder_date`),
    INDEX `idx_rem_date` (`reminder_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table for per-user read tracking of broadcast notifications (user_id IS NULL)
CREATE TABLE IF NOT EXISTS `notification_reads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `notification_id` INT NOT NULL,
    `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user_notification_read` (`user_id`, `notification_id`),
    INDEX `idx_user_read` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Default cron security token in settings
INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'cron_token', 'atsede_cron_sec_89d3fa6b'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'cron_token');
