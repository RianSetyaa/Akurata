USE akurata_pos;

CREATE TABLE IF NOT EXISTS loyverse_integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  merchant_name VARCHAR(160) NULL,
  merchant_email VARCHAR(180) NULL,
  access_token TEXT NOT NULL,
  refresh_token TEXT NULL,
  token_type VARCHAR(40) NOT NULL DEFAULT 'Bearer',
  scope VARCHAR(500) NULL,
  expires_at DATETIME NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loyverse_integrations_outlet (outlet_id),
  CONSTRAINT fk_loyverse_integrations_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_loyverse_integrations_user FOREIGN KEY (user_id) REFERENCES users(id)
);
