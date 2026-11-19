USE riasec_db;

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

ALTER TABLE inquiries
  MODIFY status ENUM('Pending','Approved','Accepted','Rejected','Rescheduled') NOT NULL DEFAULT 'Pending';

ALTER TABLE inquiry_updates
  MODIFY status ENUM('Pending','Approved','Accepted','Rejected','Rescheduled') NOT NULL;
