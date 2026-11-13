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
