USE akurata_pos;

ALTER TABLE receivables
  MODIFY status ENUM('open', 'partial', 'paid') NOT NULL DEFAULT 'open',
  ADD COLUMN paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount;

UPDATE receivables
SET paid_amount = CASE WHEN status = 'paid' THEN amount ELSE 0 END;

CREATE TABLE IF NOT EXISTS receivable_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  receivable_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  payment_no VARCHAR(60) NOT NULL UNIQUE,
  payment_method ENUM('cash', 'qris', 'transfer') NOT NULL DEFAULT 'cash',
  amount DECIMAL(14,2) NOT NULL,
  notes TEXT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receivable_payments_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_receivable_payments_receivable FOREIGN KEY (receivable_id) REFERENCES receivables(id),
  CONSTRAINT fk_receivable_payments_user FOREIGN KEY (user_id) REFERENCES users(id)
);
