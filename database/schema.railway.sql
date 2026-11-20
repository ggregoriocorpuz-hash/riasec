-- ═══════════════════════════════════════════════════════════
-- RIASEC Career Interest Assessment — Railway import schema
--
-- Same as schema.sql, minus the CREATE DATABASE / USE lines:
-- the tables MUST land in the database shown in your Railway
-- MySQL service's MYSQLDATABASE variable, which is exactly the
-- database config/db.php connects to.
--
-- How to import:
--   Railway dashboard → MySQL service → Data / Query tab →
--   paste this file's contents → Execute.
--   (Or from a mysql shell:  USE `value-of-MYSQLDATABASE`;  first,
--    then paste. CLI: railway connect mysql)
--
-- Default admin account:  username `admin`  password `Admin@1234`
-- (change it immediately after first login)
-- ═══════════════════════════════════════════════════════════

-- ─── Admins ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  username      VARCHAR(80)      NOT NULL,
  password_hash VARCHAR(255)     NOT NULL,
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Respondents ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS respondents (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  full_name      VARCHAR(200)  NOT NULL,
  student_id     VARCHAR(50)   NOT NULL,
  section        VARCHAR(100)  NOT NULL,
  score_r        TINYINT       NOT NULL DEFAULT 0,
  score_i        TINYINT       NOT NULL DEFAULT 0,
  score_a        TINYINT       NOT NULL DEFAULT 0,
  score_s        TINYINT       NOT NULL DEFAULT 0,
  score_e        TINYINT       NOT NULL DEFAULT 0,
  score_c        TINYINT       NOT NULL DEFAULT 0,
  holland_code   CHAR(3)       NOT NULL,
  submitted_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_respondents_student_id (student_id),
  KEY idx_respondents_submitted_at (submitted_at),
  KEY idx_respondents_holland_code (holland_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Answers ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS answers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  respondent_id   INT UNSIGNED NOT NULL,
  question_id     CHAR(3)      NOT NULL,   -- e.g. R1, I5, A8
  category        CHAR(1)      NOT NULL,   -- R I A S E C
  score           TINYINT      NOT NULL,   -- 1-5
  PRIMARY KEY (id),
  KEY idx_answers_respondent (respondent_id),
  CONSTRAINT fk_answers_respondent
    FOREIGN KEY (respondent_id) REFERENCES respondents(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Student inquiries and interview requests ────────────
CREATE TABLE IF NOT EXISTS inquiries (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  respondent_id   INT UNSIGNED NOT NULL,
  concern         TEXT NOT NULL,
  preferred_date  DATE NOT NULL,
  status          ENUM('Pending','Approved','Accepted','Rejected','Rescheduled') NOT NULL DEFAULT 'Pending',
  scheduled_at    DATETIME NULL,
  teacher_message TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inquiries_respondent (respondent_id),
  KEY idx_inquiries_status (status),
  KEY idx_inquiries_created_at (created_at),
  CONSTRAINT fk_inquiries_respondent
    FOREIGN KEY (respondent_id) REFERENCES respondents(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inquiry_updates (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inquiry_id      INT UNSIGNED NOT NULL,
  actor           ENUM('student','admin') NOT NULL,
  status          ENUM('Pending','Approved','Accepted','Rejected','Rescheduled') NOT NULL,
  preferred_date  DATE NOT NULL,
  scheduled_at    DATETIME NULL,
  message         TEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inquiry_updates_inquiry (inquiry_id),
  CONSTRAINT fk_inquiry_updates_inquiry
    FOREIGN KEY (inquiry_id) REFERENCES inquiries(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Default admin account ───────────────────────────────
-- Password: Admin@1234  (change immediately after setup!)
-- Generated with: password_hash('Admin@1234', PASSWORD_BCRYPT)
INSERT INTO admins (username, password_hash) VALUES
  ('admin', '$2y$10$Qf73NbX.HiNPl9eoLc1N/unBN35c.OgEfF90qivVqRnDKc.WIB8LO')
ON DUPLICATE KEY UPDATE id=id;

-- ═══════════════════════════════════════════════════════════
-- RIASEC AI Assistant — Additional Tables
-- ═══════════════════════════════════════════════════════════

-- ─── App Settings (AI API key, model, etc.) ──────────────
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT         NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Schools database ────────────────────────────────────
CREATE TABLE IF NOT EXISTS schools (
  school_id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  school_name          VARCHAR(200)   NOT NULL,
  school_address       VARCHAR(500)   NOT NULL,
  city                 VARCHAR(100)   NOT NULL,
  province             VARCHAR(100)   NOT NULL,
  latitude             DECIMAL(10,7)  NULL,
  longitude            DECIMAL(10,7)  NULL,
  contact_number       VARCHAR(50)    NULL,
  email                VARCHAR(150)   NULL,
  official_website     VARCHAR(255)   NULL,
  courses_offered      TEXT           NOT NULL,
  admission_information TEXT          NULL,
  created_at           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (school_id),
  KEY idx_schools_city (city),
  KEY idx_schools_province (province),
  KEY idx_schools_name (school_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No fixed school list is seeded here: colleges and universities are
-- looked up dynamically from OpenStreetMap using the student's
-- location, and their actual programs are read from each school's
-- own course tags or website. This table only stores dynamically
-- added directory rows — it is intentionally left empty.

-- ─── Default AI settings ─────────────────────────────────
INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('openai_model', 'gpt-4o-mini')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
