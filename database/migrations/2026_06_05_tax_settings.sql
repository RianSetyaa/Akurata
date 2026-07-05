USE akurata_pos;

ALTER TABLE outlets
  ADD COLUMN tax_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_account_number,
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER tax_enabled;

ALTER TABLE transactions
  ADD COLUMN subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER payment_status,
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER subtotal_amount,
  ADD COLUMN tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER tax_rate;

UPDATE transactions
SET subtotal_amount = total_amount
WHERE subtotal_amount = 0;

ALTER TABLE quotations
  ADD COLUMN subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER subtotal_amount,
  ADD COLUMN tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER tax_rate;

UPDATE quotations
SET subtotal_amount = total_amount
WHERE subtotal_amount = 0;
