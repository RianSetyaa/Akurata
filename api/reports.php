<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('GET');

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$pdo = db();
$type = $_GET['type'] ?? 'sales';
$limit = max(1, min(1000, (int) ($_GET['limit'] ?? 500)));
$month = $_GET['month'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if ($type === 'sales') {
    $where = "WHERE t.outlet_id = :outlet_id AND t.payment_status <> 'void'";
    $params = [':outlet_id' => $outletId];

    if ($startDate !== '' && $endDate !== '') {
        $where .= ' AND DATE(t.sold_at) >= :start_date AND DATE(t.sold_at) <= :end_date';
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    } elseif ($month !== '') {
        $where .= ' AND DATE_FORMAT(t.sold_at, \'%Y-%m\') = :month';
        $params[':month'] = $month;
    }

    $paymentMethod = $_GET['payment_method'] ?? '';
    if ($paymentMethod !== '') {
        $where .= ' AND t.payment_method = :payment_method';
        $params[':payment_method'] = $paymentMethod;
    }

    $source = $_GET['source'] ?? '';
    if ($source !== '') {
        $where .= ' AND t.source = :source';
        $params[':source'] = $source;
    }

    $summaryWhere = str_replace('t.', '', $where);
    $summary = $pdo->prepare("
        SELECT COUNT(*) AS count_all,
               COALESCE(SUM(CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE total_amount - tax_amount END), 0) AS subtotal_amount,
               COALESCE(SUM(discount_amount), 0) AS discount_amount,
               COALESCE(SUM(tax_amount), 0) AS tax_amount,
               COALESCE(SUM(total_amount), 0) AS total_amount,
               COALESCE(SUM(cost_amount), 0) AS cost_amount,
               COALESCE(SUM((CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE total_amount - tax_amount END) - cost_amount), 0) AS gross_profit
        FROM transactions
        {$summaryWhere}
    ");
    $summary->execute($params);
    $summary = $summary->fetch();

    $stmt = $pdo->prepare("
        SELECT t.id, t.invoice_no, t.payment_method, t.payment_status,
               CASE WHEN t.subtotal_amount > 0 THEN t.subtotal_amount ELSE t.total_amount - t.tax_amount END AS subtotal_amount,
               t.discount_amount, t.tax_amount, t.total_amount,
               t.cost_amount, t.source, t.sold_at, u.name AS cashier, c.name AS customer
        FROM transactions t
        JOIN users u ON u.id = t.user_id
        LEFT JOIN customers c ON c.id = t.customer_id
        {$where}
        ORDER BY t.sold_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);

    json_response([
        'summary' => [
            'count' => (int) $summary['count_all'],
            'subtotal_amount' => money($summary['subtotal_amount']),
            'discount_amount' => money($summary['discount_amount']),
            'tax_amount' => money($summary['tax_amount']),
            'total_amount' => money($summary['total_amount']),
            'cost_amount' => money($summary['cost_amount']),
            'gross_profit' => money($summary['gross_profit']),
        ],
        'rows' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'invoice_no' => $row['invoice_no'],
            'cashier' => $row['cashier'],
            'customer' => $row['customer'] ?? 'Pelanggan umum',
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'subtotal_amount' => money($row['subtotal_amount']),
            'discount_amount' => money($row['discount_amount']),
            'tax_amount' => money($row['tax_amount']),
            'total_amount' => money($row['total_amount']),
            'cost_amount' => money($row['cost_amount']),
            'gross_profit' => money((float) $row['subtotal_amount'] - (float) $row['cost_amount']),
            'source' => $row['source'] ?? 'akurata',
            'date' => $row['sold_at'],
        ], $stmt->fetchAll()),
    ]);
}

if ($type === 'purchases') {
    $where = "WHERE p.outlet_id = :outlet_id";
    $params = [':outlet_id' => $outletId];

    if ($startDate !== '' && $endDate !== '') {
        $where .= ' AND DATE(p.purchased_at) >= :start_date AND DATE(p.purchased_at) <= :end_date';
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }

    $status = $_GET['status'] ?? '';
    if ($status !== '') {
        $where .= ' AND p.status = :status';
        $params[':status'] = $status;
    }

    $summaryWhere = str_replace('p.', '', $where);
    $summary = $pdo->prepare("
        SELECT COUNT(*) AS count_all,
               COALESCE(SUM(total_amount), 0) AS total_amount,
               COALESCE(SUM(additional_cost), 0) AS additional_cost,
               SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS received_count
        FROM purchases
        {$summaryWhere}
    ");
    $summary->execute($params);
    $summary = $summary->fetch();

    $stmt = $pdo->prepare("
        SELECT p.id, p.invoice_no, p.total_amount, p.status, p.additional_cost,
               p.purchased_at, p.received_at, s.name AS supplier
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        {$where}
        ORDER BY p.purchased_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);

    json_response([
        'summary' => [
            'count' => (int) $summary['count_all'],
            'received_count' => (int) ($summary['received_count'] ?? 0),
            'total_amount' => money($summary['total_amount']),
            'additional_cost' => money($summary['additional_cost']),
        ],
        'rows' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'invoice_no' => $row['invoice_no'],
            'supplier' => $row['supplier'] ?? 'Supplier belum diisi',
            'total_amount' => money($row['total_amount']),
            'additional_cost' => money($row['additional_cost']),
            'status' => $row['status'],
            'date' => $row['purchased_at'],
            'received_at' => $row['received_at'],
        ], $stmt->fetchAll()),
    ]);
}

json_response(['error' => 'Tipe laporan tidak dikenal.'], 422);
