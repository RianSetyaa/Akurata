USE akurata_pos;

ALTER TABLE outlets
  ADD COLUMN logo_path VARCHAR(255) NULL AFTER address;
