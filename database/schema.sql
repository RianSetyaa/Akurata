CREATE DATABASE IF NOT EXISTS akurata_pos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE akurata_pos;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS receivable_payments;
DROP TABLE IF EXISTS receivables;
DROP TABLE IF EXISTS support_messages;
DROP TABLE IF EXISTS support_conversations;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS loyverse_integrations;
DROP TABLE IF EXISTS pending_password_resets;
DROP TABLE IF EXISTS pending_registrations;
DROP TABLE IF EXISTS quotation_items;
DROP TABLE IF EXISTS quotations;
DROP TABLE IF EXISTS purchase_items;
DROP TABLE IF EXISTS purchases;
DROP TABLE IF EXISTS transaction_items;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS shifts;
DROP TABLE IF EXISTS product_components;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS outlets;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE outlets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL,
  billing_bank_name VARCHAR(120) NULL,
  billing_account_name VARCHAR(160) NULL,
  billing_account_number VARCHAR(80) NULL,
  tax_enabled TINYINT(1) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  quotation_enabled TINYINT(1) NOT NULL DEFAULT 1,
  loyverse_tax_id VARCHAR(80) NULL,
  loyverse_tax_synced_at DATETIME NULL,
  loyverse_store_id VARCHAR(80) NULL,
  loyverse_store_name VARCHAR(160) NULL,
  whatsapp_number VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  api_token VARCHAR(64) UNIQUE NULL,
  role ENUM('administrator', 'owner', 'manager', 'cashier') NOT NULL DEFAULT 'cashier',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id)
);

CREATE TABLE support_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  admin_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  outlet_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at DATETIME(3) NULL,
  closed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_support_conversation_outlet_status (outlet_id, status, id),
  INDEX idx_support_conversation_activity (status, last_message_at),
  CONSTRAINT fk_support_conversation_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id)
);

CREATE TABLE support_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  outlet_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NULL,
  sender_type ENUM('outlet', 'administrator') NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_support_messages_conversation (conversation_id, id),
  INDEX idx_support_messages_outlet (outlet_id, id),
  CONSTRAINT fk_support_message_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id),
  CONSTRAINT fk_support_message_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_support_message_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE pending_registrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  business VARCHAR(120) NOT NULL,
  outlets VARCHAR(20) NULL,
  plan VARCHAR(40) NULL,
  otp_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pending_registrations_email (email),
  INDEX idx_pending_registrations_expires (expires_at)
);

CREATE TABLE pending_password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  email VARCHAR(160) NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_resets_email (email),
  INDEX idx_password_resets_expires (expires_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE loyverse_integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  merchant_name VARCHAR(160) NULL,
  merchant_email VARCHAR(180) NULL,
  access_token TEXT NOT NULL,
  refresh_token TEXT NULL,
  token_type VARCHAR(40) NOT NULL DEFAULT 'Bearer',
  scope VARCHAR(500) NULL,
  expires_at DATETIME NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_product_sync_at DATETIME NULL,
  last_receipt_sync_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loyverse_integrations_outlet (outlet_id),
  CONSTRAINT fk_loyverse_integrations_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_loyverse_integrations_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id VARCHAR(80) NULL,
  description VARCHAR(255) NOT NULL,
  metadata JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_logs_outlet_created (outlet_id, created_at),
  INDEX idx_activity_logs_user_created (user_id, created_at),
  CONSTRAINT fk_activity_logs_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  phone VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_customers_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id)
);

CREATE TABLE suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  phone VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_suppliers_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id)
);

CREATE TABLE products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(60) NOT NULL,
  barcode VARCHAR(80) NULL,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(80) NULL,
  stock_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  min_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  sold_by_weight TINYINT(1) NOT NULL DEFAULT 0,
  is_composite TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  loyverse_item_id VARCHAR(80) NULL,
  loyverse_variant_id VARCHAR(80) NULL,
  loyverse_synced_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_outlet_sku (outlet_id, sku),
  UNIQUE KEY uq_products_outlet_barcode (outlet_id, barcode),
  UNIQUE KEY uq_products_loyverse_variant (outlet_id, loyverse_variant_id),
  CONSTRAINT fk_products_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id)
);

CREATE TABLE product_components (
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

CREATE TABLE shifts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  closing_cash DECIMAL(14,2) NULL,
  CONSTRAINT fk_shifts_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_shifts_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  invoice_no VARCHAR(40) NOT NULL UNIQUE,
  payment_method ENUM('cash', 'qris', 'transfer', 'tempo') NOT NULL DEFAULT 'cash',
  payment_status ENUM('paid', 'receivable', 'void') NOT NULL DEFAULT 'paid',
  subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  source VARCHAR(40) NOT NULL DEFAULT 'akurata',
  loyverse_receipt_number VARCHAR(80) NULL,
  sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  stock_applied_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transactions_loyverse_receipt (outlet_id, loyverse_receipt_number),
  CONSTRAINT fk_transactions_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE quotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  quote_no VARCHAR(40) NOT NULL UNIQUE,
  status ENUM('draft', 'sent', 'accepted', 'expired') NOT NULL DEFAULT 'draft',
  subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  valid_until DATE NULL,
  notes TEXT NULL,
  quoted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quotations_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_quotations_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_quotations_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE quotation_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_quotation_items_quotation FOREIGN KEY (quotation_id) REFERENCES quotations(id),
  CONSTRAINT fk_quotation_items_product FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE transaction_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL,
  discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_transaction_items_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_transaction_items_product FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  invoice_no VARCHAR(60) NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('ordered', 'received') NOT NULL DEFAULT 'ordered',
  additional_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_purchases_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE purchase_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE receivables (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  status ENUM('open', 'partial', 'paid') NOT NULL DEFAULT 'open',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receivables_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_receivables_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_receivables_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE receivable_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outlet_id BIGINT UNSIGNED NOT NULL,
  receivable_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  payment_no VARCHAR(60) NOT NULL UNIQUE,
  payment_method ENUM('cash', 'qris', 'transfer') NOT NULL DEFAULT 'cash',
  amount DECIMAL(14,2) NOT NULL,
  notes TEXT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receivable_payments_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_receivable_payments_receivable FOREIGN KEY (receivable_id) REFERENCES receivables(id),
  CONSTRAINT fk_receivable_payments_user FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO outlets (id, name, address) VALUES
  (1, 'Outlet Depok', 'Jl. Margonda Raya, Depok');

INSERT INTO users (id, outlet_id, name, email, password_hash, role) VALUES
  (1, 1, 'Dewi', 'dewi@akurata.test', '$2y$10$demo', 'cashier'),
  (2, 1, 'Fajar', 'fajar@akurata.test', '$2y$10$demo', 'cashier'),
  (3, 1, 'Raka', 'raka@akurata.test', '$2y$10$demo', 'administrator');

INSERT INTO customers (id, outlet_id, name, phone) VALUES
  (1, 1, 'Budi Santoso', '081234567890'),
  (2, 1, 'Warung Sinar Jaya', '081298765432');

INSERT INTO suppliers (id, outlet_id, name, phone) VALUES
  (1, 1, 'Supplier Utama', '0215550188');

INSERT INTO products (id, outlet_id, sku, name, category, stock_qty, min_stock, cost_price, sale_price) VALUES
  (1, 1, 'SKU-KOPI-1L', 'Kopi susu 1L', 'Minuman', 8, 20, 42000, 58000),
  (2, 1, 'SKU-ROTI-GND', 'Roti gandum', 'Roti', 12, 25, 12000, 18500),
  (3, 1, 'SKU-GULA-500', 'Gula aren 500ml', 'Bahan baku', 5, 15, 28000, 39000),
  (4, 1, 'SKU-PAKET-HEMAT', 'Paket hemat', 'Bundling', 42, 12, 32000, 52000),
  (5, 1, 'SKU-AIR-600', 'Air mineral 600ml', 'Minuman', 90, 30, 2500, 5000);

INSERT INTO shifts (outlet_id, user_id, opened_at, closed_at, opening_cash, closing_cash) VALUES
  (1, 1, CONCAT(CURDATE(), ' 08:00:00'), NULL, 500000, NULL),
  (1, 2, CONCAT(CURDATE(), ' 09:00:00'), NULL, 500000, NULL),
  (1, 3, CONCAT(CURDATE(), ' 07:30:00'), CONCAT(CURDATE(), ' 15:42:00'), 500000, 18420000);

INSERT INTO transactions (
  id, outlet_id, user_id, customer_id, invoice_no, payment_method, payment_status,
  subtotal_amount, tax_rate, tax_amount, total_amount, cost_amount, sold_at
) VALUES
  (1, 1, 1, NULL, 'INV-2048', 'qris', 'paid', 284000, 0, 0, 284000, 203000, CONCAT(CURDATE(), ' 14:15:00')),
  (2, 1, 2, NULL, 'INV-2047', 'cash', 'paid', 126500, 0, 0, 126500, 84000, CONCAT(CURDATE(), ' 13:40:00')),
  (3, 1, 1, 2, 'INV-2046', 'tempo', 'receivable', 760000, 0, 0, 760000, 520000, CONCAT(CURDATE(), ' 12:28:00')),
  (4, 1, 3, NULL, 'INV-2045', 'transfer', 'paid', 412000, 0, 0, 412000, 298000, CONCAT(CURDATE(), ' 11:20:00')),
  (5, 1, 1, NULL, 'INV-2044', 'cash', 'paid', 184000, 0, 0, 184000, 128000, CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 15:12:00'));

INSERT INTO transaction_items (transaction_id, product_id, qty, unit_price, unit_cost, subtotal) VALUES
  (1, 1, 4, 58000, 42000, 232000),
  (1, 5, 4, 5000, 2500, 20000),
  (2, 2, 5, 18500, 12000, 92500),
  (2, 5, 6, 5000, 2500, 30000),
  (3, 4, 10, 52000, 32000, 520000),
  (3, 1, 4, 58000, 42000, 232000),
  (4, 1, 3, 58000, 42000, 174000),
  (4, 4, 4, 52000, 32000, 208000),
  (5, 1, 3, 58000, 42000, 174000);

INSERT INTO receivables (outlet_id, transaction_id, customer_id, amount, paid_amount, due_date, status) VALUES
  (1, 3, 2, 760000, 0, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'open');
