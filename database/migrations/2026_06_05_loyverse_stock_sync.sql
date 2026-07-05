USE akurata_pos;

ALTER TABLE transactions
  ADD COLUMN stock_applied_at DATETIME NULL AFTER sold_at;

UPDATE transactions
SET stock_applied_at = created_at
WHERE source <> 'loyverse'
  AND stock_applied_at IS NULL;

UPDATE products p
JOIN (
  SELECT t.outlet_id, ti.product_id, SUM(ti.qty) AS qty_sold
  FROM transactions t
  JOIN transaction_items ti ON ti.transaction_id = t.id
  WHERE t.source = 'loyverse'
    AND t.stock_applied_at IS NULL
  GROUP BY t.outlet_id, ti.product_id
) sold ON sold.product_id = p.id AND sold.outlet_id = p.outlet_id
SET p.stock_qty = p.stock_qty - sold.qty_sold;

UPDATE transactions
SET stock_applied_at = NOW()
WHERE source = 'loyverse'
  AND stock_applied_at IS NULL;
