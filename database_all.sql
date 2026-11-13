-- ViralClips AI: poora database ek hi file mein.
-- Naya install ho ya purana, dono mein chalaiye (dobara chalane se koi nuksan nahi).
CREATE DATABASE IF NOT EXISTS viralclips CHARACTER SET utf8mb4;
USE viralclips;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  api_token VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  youtube_url VARCHAR(500) NOT NULL,
  video_id VARCHAR(20) DEFAULT NULL,
  title VARCHAR(300) DEFAULT NULL,
  status ENUM('queued','downloading','transcribing','analyzing','clipping','done','failed') DEFAULT 'queued',
  progress INT DEFAULT 0,
  message VARCHAR(300) DEFAULT 'Queue mein hai',
  vertical TINYINT(1) DEFAULT 1,
  max_clips INT DEFAULT 5,
  error TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS clips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  start_sec INT NOT NULL,
  end_sec INT NOT NULL,
  title VARCHAR(200),
  viral_score INT DEFAULT 0,
  reason TEXT,
  caption_instagram TEXT,
  caption_youtube TEXT,
  hashtags VARCHAR(500),
  file_path VARCHAR(300),
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS youtube_tokens (
  user_id INT PRIMARY KEY,
  refresh_token TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Purane database ko naye features ke liye upgrade (naye install par ye kuch nahi karte)
ALTER TABLE users ADD COLUMN IF NOT EXISTS api_token  VARCHAR(64)  DEFAULT NULL;
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS language   VARCHAR(20)  DEFAULT 'hindi';
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS audio_lang VARCHAR(10)  DEFAULT 'hi';
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS aspect     VARCHAR(8)   DEFAULT '9:16';
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS fit_mode   VARCHAR(8)   DEFAULT 'bars';
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS captions    TINYINT(1)  DEFAULT 1;
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS credit_line TINYINT(1)  DEFAULT 1;
ALTER TABLE clips ADD COLUMN IF NOT EXISTS thumb_path VARCHAR(300) DEFAULT NULL;
ALTER TABLE clips ADD COLUMN IF NOT EXISTS youtube_video_id VARCHAR(20) DEFAULT NULL;
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS smart_frame TINYINT(1)  DEFAULT 1;
ALTER TABLE jobs  ADD COLUMN IF NOT EXISTS title_hook  TINYINT(1)  DEFAULT 1;

CREATE TABLE IF NOT EXISTS instagram_accounts (
  user_id INT PRIMARY KEY,
  ig_user_id VARCHAR(40) NOT NULL,
  username VARCHAR(100) DEFAULT NULL,
  access_token TEXT NOT NULL,
  saved_at DATETIME DEFAULT NULL,
  refreshed_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
ALTER TABLE clips ADD COLUMN IF NOT EXISTS instagram_media_id VARCHAR(40)  DEFAULT NULL;
ALTER TABLE clips ADD COLUMN IF NOT EXISTS instagram_url      VARCHAR(255) DEFAULT NULL;
