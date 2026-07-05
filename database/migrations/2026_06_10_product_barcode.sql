USE akurata_pos;

ALTER TABLE products
  ADD COLUMN barcode VARCHAR(80) NULL AFTER sku,
  ADD UNIQUE KEY uq_products_outlet_barcode (outlet_id, barcode);
