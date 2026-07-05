USE akurata_pos;

ALTER TABLE loyverse_integrations
  ADD COLUMN last_product_sync_at DATETIME NULL AFTER connected_at,
  ADD COLUMN last_receipt_sync_at DATETIME NULL AFTER last_product_sync_at;

ALTER TABLE products
  ADD COLUMN loyverse_item_id VARCHAR(80) NULL AFTER is_active,
  ADD COLUMN loyverse_variant_id VARCHAR(80) NULL AFTER loyverse_item_id,
  ADD COLUMN loyverse_synced_at DATETIME NULL AFTER loyverse_variant_id,
  ADD UNIQUE KEY uq_products_loyverse_variant (outlet_id, loyverse_variant_id);

ALTER TABLE transactions
  ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'akurata' AFTER cost_amount,
  ADD COLUMN loyverse_receipt_number VARCHAR(80) NULL AFTER source,
  ADD UNIQUE KEY uq_transactions_loyverse_receipt (outlet_id, loyverse_receipt_number);
