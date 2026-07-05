USE akurata_pos;

INSERT INTO outlets (name, address)
SELECT 'Akurata Administrator', 'Administrator system outlet'
WHERE NOT EXISTS (
  SELECT 1
  FROM outlets
  WHERE name = 'Akurata Administrator'
);

INSERT INTO users (outlet_id, name, email, password_hash, role)
SELECT
  o.id,
  'Administrator',
  'admin@akurata.my.id',
  '$2y$12$P4be5gRD9S7onOR1vRzSj.Q7xp2pQA.MzCWNr268grm2DQfSBofXe',
  'administrator'
FROM outlets o
WHERE o.name = 'Akurata Administrator'
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  password_hash = VALUES(password_hash),
  role = VALUES(role);
