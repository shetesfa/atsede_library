-- Migration 002: Add profile photo and set authentic library name
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL AFTER telegram_joined;

UPDATE settings SET setting_value='አጸደ ትጉሃን ሰንበት ትምህርት ቤት ቤተ ይትባረክ ቤተ-መጽሃፍት' WHERE setting_key='library_name';
