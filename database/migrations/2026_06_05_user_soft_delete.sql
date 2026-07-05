USE akurata_pos;

ALTER TABLE users
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
  ADD COLUMN deleted_at DATETIME NULL AFTER is_active;
