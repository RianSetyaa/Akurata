USE akurata_pos;

ALTER TABLE outlets
  ADD COLUMN quotation_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER tax_rate;
