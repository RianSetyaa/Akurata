USE akurata_pos;

ALTER TABLE outlets
  ADD COLUMN billing_bank_name VARCHAR(120) NULL AFTER logo_path,
  ADD COLUMN billing_account_name VARCHAR(160) NULL AFTER billing_bank_name,
  ADD COLUMN billing_account_number VARCHAR(80) NULL AFTER billing_account_name;
