USE akurata_pos;

ALTER TABLE outlets
  ADD COLUMN loyverse_store_id VARCHAR(80) NULL AFTER loyverse_tax_synced_at,
  ADD COLUMN loyverse_store_name VARCHAR(160) NULL AFTER loyverse_store_id;
