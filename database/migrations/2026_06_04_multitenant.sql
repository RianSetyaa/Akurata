USE akurata_pos;

ALTER TABLE customers ADD COLUMN outlet_id BIGINT UNSIGNED NULL AFTER id;
UPDATE customers SET outlet_id = 1 WHERE outlet_id IS NULL;
ALTER TABLE customers MODIFY outlet_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE customers ADD INDEX idx_customers_outlet (outlet_id);

ALTER TABLE suppliers ADD COLUMN outlet_id BIGINT UNSIGNED NULL AFTER id;
UPDATE suppliers SET outlet_id = 1 WHERE outlet_id IS NULL;
ALTER TABLE suppliers MODIFY outlet_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE suppliers ADD INDEX idx_suppliers_outlet (outlet_id);

ALTER TABLE products ADD COLUMN outlet_id BIGINT UNSIGNED NULL AFTER id;
UPDATE products SET outlet_id = 1 WHERE outlet_id IS NULL;
ALTER TABLE products MODIFY outlet_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE products DROP INDEX sku;
ALTER TABLE products ADD UNIQUE KEY uq_products_outlet_sku (outlet_id, sku);

ALTER TABLE purchases ADD COLUMN outlet_id BIGINT UNSIGNED NULL AFTER id;
UPDATE purchases p
LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
LEFT JOIN products pr ON pr.id = pi.product_id
SET p.outlet_id = COALESCE(pr.outlet_id, 1)
WHERE p.outlet_id IS NULL;
ALTER TABLE purchases MODIFY outlet_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE purchases ADD INDEX idx_purchases_outlet (outlet_id);

ALTER TABLE receivables ADD COLUMN outlet_id BIGINT UNSIGNED NULL AFTER id;
UPDATE receivables r
JOIN transactions t ON t.id = r.transaction_id
SET r.outlet_id = t.outlet_id
WHERE r.outlet_id IS NULL;
ALTER TABLE receivables MODIFY outlet_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE receivables ADD INDEX idx_receivables_outlet (outlet_id);

ALTER TABLE customers ADD CONSTRAINT fk_customers_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id);
ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id);
ALTER TABLE products ADD CONSTRAINT fk_products_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id);
ALTER TABLE purchases ADD CONSTRAINT fk_purchases_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id);
ALTER TABLE receivables ADD CONSTRAINT fk_receivables_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id);
