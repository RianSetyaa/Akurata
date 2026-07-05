USE akurata_pos;

ALTER TABLE transactions
  ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER subtotal_amount;

ALTER TABLE transaction_items
  ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER unit_cost,
  ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER discount_rate;
