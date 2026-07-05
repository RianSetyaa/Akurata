USE akurata_pos;

CREATE TABLE IF NOT EXISTS support_conversations (
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

CREATE TABLE IF NOT EXISTS support_messages (
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
