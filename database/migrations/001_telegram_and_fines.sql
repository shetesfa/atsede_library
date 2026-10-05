-- ============================================================
-- Migration 001: Telegram Integration + Overdue Fines
-- Run once in phpMyAdmin or MySQL CLI
-- ============================================================

-- 1. Add Telegram fields to users table
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `telegram_username`  VARCHAR(100) DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `telegram_chat_id`   BIGINT      DEFAULT NULL AFTER `telegram_username`,
  ADD COLUMN IF NOT EXISTS `telegram_joined`    TINYINT(1)  NOT NULL DEFAULT 0 AFTER `telegram_chat_id`;

-- 2. Add fine columns to borrow_records
ALTER TABLE `borrow_records`
  ADD COLUMN IF NOT EXISTS `overdue_fine`    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `due_date`,
  ADD COLUMN IF NOT EXISTS `fine_paid`       DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `overdue_fine`,
  ADD COLUMN IF NOT EXISTS `fine_waived`     DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `fine_paid`,
  ADD COLUMN IF NOT EXISTS `last_fine_calc`  DATE DEFAULT NULL AFTER `fine_waived`;

-- 3. Fine payments table
CREATE TABLE IF NOT EXISTS `fine_payments` (
  `id`          INT(11)        NOT NULL AUTO_INCREMENT,
  `record_id`   INT(11)        NOT NULL,
  `member_id`   INT(11)        NOT NULL,
  `amount`      DECIMAL(10,2)  NOT NULL,
  `waived`      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `recorded_by` INT(11)        DEFAULT NULL,
  `notes`       VARCHAR(300)   DEFAULT NULL,
  `paid_at`     DATETIME       NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `record_id` (`record_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Telegram verification tokens (for join check)
CREATE TABLE IF NOT EXISTS `telegram_verifications` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)     NOT NULL,
  `token`      VARCHAR(64) NOT NULL,
  `created_at` DATETIME    NOT NULL DEFAULT current_timestamp(),
  `expires_at` DATETIME    NOT NULL,
  `verified`   TINYINT(1)  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Settings for Telegram bot token + fine per day
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('telegram_bot_token', ''),
  ('telegram_bot_username', ''),
  ('overdue_fine_per_day', '5'),
  ('fine_grace_days', '0')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- Done!
SELECT 'Migration 001 applied successfully' AS status;
