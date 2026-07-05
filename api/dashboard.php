<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('GET');

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$pdo = db();

$summary = $pdo->prepare("
    SELECT
      COALESCE(SUM(total_amount), 0) AS revenue,
      COUNT(*) AS transactions,
      COALESCE(AVG(total_amount), 0) AS average_sale,
      COALESCE(
        SUM((CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE total_amount - tax_amount END) - cost_amount)
        / NULLIF(SUM(CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE total_amount - tax_amount END), 0)
        * 100,
        0
      ) AS gross_margin
    FROM transactions
    WHERE outlet_id = :outlet_id
      AND DATE(sold_at) = CURDATE()
      AND payment_status <> 'void'
");
$summary->execute([':outlet_id' => $outletId]);
$summary = $summary->fetch();

$yesterday = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS revenue
    FROM transactions
    WHERE outlet_id = :outlet_id
      AND DATE(sold_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
      AND payment_status <> 'void'
");
$yesterday->execute([':outlet_id' => $outletId]);
$yesterday = $yesterday->fetch();

$lowStock = $pdo->prepare("
    SELECT COUNT(*) AS count_all,
           SUM(CASE WHEN stock_qty <= min_stock THEN 1 ELSE 0 END) AS need_po
    FROM products
    WHERE outlet_id = :outlet_id
      AND is_active = 1
      AND is_composite = 0
");
$lowStock->execute([':outlet_id' => $outletId]);
$lowStock = $lowStock->fetch();

$shift = $pdo->prepare("
    SELECT
      SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END) AS active_count,
      MAX(closed_at) AS last_closed_at
    FROM shifts
    WHERE outlet_id = :outlet_id
      AND DATE(opened_at) = CURDATE()
");
$shift->execute([':outlet_id' => $outletId]);
$shift = $shift->fetch();

$hourlyStmt = $pdo->prepare("
    SELECT HOUR(sold_at) AS hour, SUM(total_amount) AS total
    FROM transactions
    WHERE outlet_id = :outlet_id
      AND DATE(sold_at) = CURDATE()
      AND payment_status <> 'void'
    GROUP BY HOUR(sold_at)
    ORDER BY hour
");
$hourlyStmt->execute([':outlet_id' => $outletId]);

$paymentStmt = $pdo->prepare("
    SELECT payment_method, SUM(total_amount) AS total
    FROM transactions
    WHERE outlet_id = :outlet_id
      AND DATE(sold_at) = CURDATE()
      AND payment_status <> 'void'
    GROUP BY payment_method
    ORDER BY total DESC
");
$paymentStmt->execute([':outlet_id' => $outletId]);

$recentStmt = $pdo->prepare("
    SELECT t.id, t.invoice_no, u.name AS cashier, t.payment_method, t.payment_status, t.total_amount,
           CASE WHEN t.subtotal_amount > 0 THEN t.subtotal_amount ELSE t.total_amount - t.tax_amount END AS subtotal_amount,
           t.cost_amount, t.sold_at
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    WHERE t.outlet_id = :outlet_id
    ORDER BY t.sold_at DESC
    LIMIT 8
");
$recentStmt->execute([':outlet_id' => $outletId]);

$stockStmt = $pdo->prepare("
    SELECT id, sku, name, stock_qty, min_stock
    FROM products
    WHERE outlet_id = :outlet_id
      AND is_active = 1
      AND is_composite = 0
      AND stock_qty <= min_stock
    ORDER BY stock_qty ASC, name ASC
    LIMIT 8
");
$stockStmt->execute([':outlet_id' => $outletId]);

$receivableStmt = $pdo->prepare("
    SELECT r.id, c.name AS customer, r.amount, r.paid_amount,
           (r.amount - r.paid_amount) AS remaining_amount, r.due_date, r.status, t.invoice_no,
           MAX(rp.id) AS last_payment_id
    FROM receivables r
    JOIN transactions t ON t.id = r.transaction_id
    LEFT JOIN customers c ON c.id = r.customer_id
    LEFT JOIN receivable_payments rp ON rp.receivable_id = r.id
    WHERE r.outlet_id = :outlet_id
      AND r.status <> 'paid'
    GROUP BY r.id, c.name, r.amount, r.paid_amount, r.due_date, r.status, t.invoice_no
    ORDER BY FIELD(r.status, 'open', 'partial'), r.due_date ASC
    LIMIT 8
");
$receivableStmt->execute([':outlet_id' => $outletId]);

$growth = 0;
$todayRevenue = (float) $summary['revenue'];
$yesterdayRevenue = (float) $yesterday['revenue'];
if ($yesterdayRevenue > 0) {
    $growth = (($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100;
}

json_response([
    'tenant' => [
        'outlet_id' => $outletId,
        'outlet_name' => $user['outlet_name'] ?? 'Outlet',
    ],
    'summary' => [
        'revenue' => money($summary['revenue']),
        'transactions' => (int) $summary['transactions'],
        'average_sale' => money($summary['average_sale']),
        'gross_margin' => round((float) $summary['gross_margin'], 1),
        'revenue_growth' => round($growth, 1),
        'low_stock' => (int) ($lowStock['need_po'] ?? 0),
        'active_shifts' => (int) ($shift['active_count'] ?? 0),
        'last_closed_at' => $shift['last_closed_at'],
    ],
    'hourly_sales' => array_map(fn ($row) => [
        'hour' => str_pad((string) $row['hour'], 2, '0', STR_PAD_LEFT),
        'total' => money($row['total']),
    ], $hourlyStmt->fetchAll()),
    'payments' => array_map(fn ($row) => [
        'method' => $row['payment_method'],
        'total' => money($row['total']),
    ], $paymentStmt->fetchAll()),
    'recent_transactions' => array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'invoice_no' => $row['invoice_no'],
        'cashier' => $row['cashier'],
        'payment_method' => $row['payment_method'],
        'payment_status' => $row['payment_status'],
        'total_amount' => money($row['total_amount']),
        'net_profit' => money((float) $row['subtotal_amount'] - (float) $row['cost_amount']),
        'sold_at' => $row['sold_at'],
    ], $recentStmt->fetchAll()),
    'low_stock_products' => array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'sku' => $row['sku'],
        'name' => $row['name'],
        'stock_qty' => quantity($row['stock_qty']),
        'min_stock' => quantity($row['min_stock']),
    ], $stockStmt->fetchAll()),
    'receivables' => array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'invoice_no' => $row['invoice_no'],
        'customer' => $row['customer'] ?? 'Pelanggan tempo',
        'amount' => money($row['amount']),
        'paid_amount' => money($row['paid_amount']),
        'remaining_amount' => money($row['remaining_amount']),
        'due_date' => $row['due_date'],
        'status' => $row['status'],
        'last_payment_id' => $row['last_payment_id'] ? (int) $row['last_payment_id'] : null,
    ], $receivableStmt->fetchAll()),
]);
