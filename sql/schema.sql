-- Floorplan App Database Schema
-- Import this file into your MySQL database via phpMyAdmin (cPanel) before using the app.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every state of a floorplan (original upload, AI-extracted structure, each edit) is stored
-- as a version row, so users can see history and roll back.
CREATE TABLE IF NOT EXISTS floorplan_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  source_type ENUM('upload','ai_generated') NOT NULL,
  status ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
  image_path VARCHAR(255) DEFAULT NULL,       -- original uploaded image, if any
  floorplan_json LONGTEXT DEFAULT NULL,       -- structured vector floorplan data
  annotation_json LONGTEXT DEFAULT NULL,      -- drawn changes/notes that produced this version
  error_message TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_versions_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_versions_project ON floorplan_versions(project_id);
