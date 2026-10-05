-- Migration 006: Library Service Settings and Schema Fixes
-- Idempotent schema changes for fine limits, borrow records status, and copy uniqueness

-- 1. Ensure max_unpaid_fine setting exists in settings table
INSERT INTO `settings` (`setting_key`, `setting_value`) 
SELECT 'max_unpaid_fine', '0'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'max_unpaid_fine');

-- 2. Update borrow_records status ENUM to include 'damaged'
ALTER TABLE `borrow_records` 
MODIFY COLUMN `status` ENUM('borrowed','returned','overdue','lost','damaged') NOT NULL DEFAULT 'borrowed';
