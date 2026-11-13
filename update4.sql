-- Ek baar phpMyAdmin > viralclips > SQL mein chalaiye
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS captions    TINYINT(1) DEFAULT 1;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS credit_line TINYINT(1) DEFAULT 1;
