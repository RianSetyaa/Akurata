USE akurata_pos;

CREATE TABLE IF NOT EXISTS quotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  quote_no VARCHAR(40) NOT NULL UNIQUE,
  status ENUM('draft', 'sent', 'accepted', 'expired') NOT NULL DEFAULT 'draft',
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  valid_until DATE NULL,
  notes TEXT NULL,
  quoted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quotations_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_quotations_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_quotations_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE IF NOT EXISTS quotation_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_quotation_items_quotation FOREIGN KEY (quotation_id) REFERENCES quotations(id),
  CONSTRAINT fk_quotation_items_product FOREIGN KEY (product_id) REFERENCES products(id)
);
