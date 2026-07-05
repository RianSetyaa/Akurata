USE akurata_pos;

ALTER TABLE products
  ADD COLUMN is_composite TINYINT(1) NOT NULL DEFAULT 0 AFTER sold_by_weight;

CREATE TABLE IF NOT EXISTS product_components (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  component_product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_component (product_id, component_product_id),
  INDEX idx_product_components_outlet (outlet_id),
  CONSTRAINT fk_product_components_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_product_components_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_product_components_component FOREIGN KEY (component_product_id) REFERENCES products(id)
);
