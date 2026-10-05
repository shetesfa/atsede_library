-- Migration 004: Ensure UNIQUE index on book_copies(qr_identifier)
-- Idempotent check before creating unique index

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'book_copies' 
               AND index_name = 'qr_identifier');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE book_copies ADD UNIQUE KEY qr_identifier (qr_identifier)', 
    'SELECT 1');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
