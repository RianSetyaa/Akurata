USE akurata_pos;

ALTER TABLE users
  MODIFY role ENUM('administrator', 'owner', 'manager', 'cashier', 'admin') NOT NULL DEFAULT 'cashier';

UPDATE users
SET role = 'administrator'
WHERE role = 'admin';

ALTER TABLE users
  MODIFY role ENUM('administrator', 'owner', 'manager', 'cashier') NOT NULL DEFAULT 'cashier';
