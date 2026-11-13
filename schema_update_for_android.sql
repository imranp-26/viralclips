-- Agar aapne pehle se schema.sql import kar liya hai, to sirf ye ek line chalaiye (phpMyAdmin > viralclips > SQL)
ALTER TABLE users ADD COLUMN api_token VARCHAR(64) DEFAULT NULL;
