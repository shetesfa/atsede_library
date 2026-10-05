-- Migration 005: Add card_token to members, create login_attempts and register_attempts tables
-- Idempotent script

-- 1. Add card_token to members if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns 
               WHERE table_schema = DATABASE() 
               AND table_name = 'members' 
               AND column_name = 'card_token');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE members ADD COLUMN card_token VARCHAR(64) UNIQUE NULL AFTER student_id', 
    'SELECT 1');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Backfill card_token for existing members
UPDATE members 
SET card_token = SUBSTRING(SHA2(CONCAT(id, '-', user_id, '-', UNIX_TIMESTAMP(), '-', RAND()), 256), 1, 32)
WHERE card_token IS NULL OR card_token = '';

-- 3. Create login_attempts table for throttling (10 failed attempts -> 20 minutes block)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time INT NOT NULL,
    INDEX idx_ident_time (identifier, attempt_time),
    INDEX idx_ip_time (ip_address, attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create register_attempts table for registration rate limiting
CREATE TABLE IF NOT EXISTS register_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time INT NOT NULL,
    INDEX idx_reg_ip_time (ip_address, attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
