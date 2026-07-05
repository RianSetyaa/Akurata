USE akurata_pos;

ALTER TABLE outlets
  ADD COLUMN loyverse_tax_id VARCHAR(80) NULL AFTER tax_rate,
  ADD COLUMN loyverse_tax_synced_at DATETIME NULL AFTER loyverse_tax_id;
