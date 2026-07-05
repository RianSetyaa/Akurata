USE akurata_pos;

INSERT INTO receivables (
  outlet_id,
  transaction_id,
  customer_id,
  amount,
  paid_amount,
  due_date,
  status,
  paid_at
)
SELECT
  t.outlet_id,
  t.id,
  t.customer_id,
  t.total_amount,
  CASE WHEN t.payment_status = 'paid' THEN t.total_amount ELSE 0 END AS paid_amount,
  DATE_ADD(DATE(t.sold_at), INTERVAL 7 DAY) AS due_date,
  CASE WHEN t.payment_status = 'paid' THEN 'paid' ELSE 'open' END AS status,
  CASE WHEN t.payment_status = 'paid' THEN t.sold_at ELSE NULL END AS paid_at
FROM transactions t
LEFT JOIN receivables r ON r.transaction_id = t.id
WHERE t.payment_method = 'tempo'
  AND r.id IS NULL;
