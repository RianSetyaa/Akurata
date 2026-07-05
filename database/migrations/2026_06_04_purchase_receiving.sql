USE akurata_pos;

ALTER TABLE purchases ADD COLUMN status ENUM('ordered', 'received') NOT NULL DEFAULT 'ordered' AFTER total_amount;
ALTER TABLE purchases ADD COLUMN additional_cost DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE purchases ADD COLUMN received_at DATETIME NULL AFTER purchased_at;

UPDATE purchases
SET status = 'received',
    received_at = purchased_at
WHERE status = 'ordered';
