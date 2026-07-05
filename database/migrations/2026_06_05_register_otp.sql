USE akurata_pos;

CREATE TABLE IF NOT EXISTS pending_registrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  business VARCHAR(120) NOT NULL,
  outlets VARCHAR(20) NULL,
  plan VARCHAR(40) NULL,
  otp_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pending_registrations_email (email),
  INDEX idx_pending_registrations_expires (expires_at)
);
