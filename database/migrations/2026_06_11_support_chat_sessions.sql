USE akurata_pos;

SET @has_closed_at = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'support_conversations'
    AND COLUMN_NAME = 'closed_at'
);
SET @sql = IF(
  @has_closed_at = 0,
  'ALTER TABLE support_conversations ADD COLUMN closed_at DATETIME(3) NULL AFTER last_message_at',
  'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_session_index = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'support_conversations'
    AND INDEX_NAME = 'idx_support_conversation_outlet_status'
);
SET @sql = IF(
  @has_session_index = 0,
  'ALTER TABLE support_conversations ADD INDEX idx_support_conversation_outlet_status (outlet_id, status, id)',
  'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_unique_outlet = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'support_conversations'
    AND INDEX_NAME = 'uq_support_conversation_outlet'
);
SET @sql = IF(
  @has_unique_outlet > 0,
  'ALTER TABLE support_conversations DROP INDEX uq_support_conversation_outlet',
  'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
