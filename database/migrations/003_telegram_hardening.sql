-- ============================================================
-- Migration 003: Telegram Hardening & Token Binding Security
-- Idempotent: safe to run multiple times
-- ============================================================

-- 1. Add immutable Telegram user ID and secure verification token columns to users
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `telegram_user_id` BIGINT DEFAULT NULL AFTER `telegram_chat_id`,
  ADD COLUMN IF NOT EXISTS `telegram_verify_token` VARCHAR(64) DEFAULT NULL AFTER `telegram_user_id`,
  ADD COLUMN IF NOT EXISTS `telegram_verify_expires` DATETIME DEFAULT NULL AFTER `telegram_verify_token`;

-- Ensure indexes exist safely
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_tg_user_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE users ADD INDEX idx_tg_user_id (telegram_user_id)', 'SELECT "index idx_tg_user_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_tg_verify_token');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE users ADD INDEX idx_tg_verify_token (telegram_verify_token)', 'SELECT "index idx_tg_verify_token already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add telegram_webhook_secret setting key if missing
INSERT INTO `settings` (`setting_key`, `setting_value`)
VALUES ('telegram_webhook_secret', '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
