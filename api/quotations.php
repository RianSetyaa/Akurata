<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

$featureStmt = $pdo->prepare("SELECT quotation_enabled FROM outlets WHERE id = :id LIMIT 1");
$featureStmt->execute([':id' => $outletId]);
$featureSettings = $featureStmt->fetch() ?: [];
$quotationEnabled = (int) ($featureSettings['quotation_enabled'] ?? 1) === 1;

if (!$quotationEnabled && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'quotation_enabled' => false,
        'quotations' => [],
    ]);
}

if (!$quotationEnabled) {
    json_response(['error' => 'Fitur quotation sedang dinonaktifkan untuk outlet ini.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT q.id, q.quote_no, q.status, q.total_amount, q.valid_until, q.quoted_at,
               c.name AS customer
        FROM quotations q
        LEFT JOIN customers c ON c.id = q.customer_id
        WHERE q.outlet_id = :outlet_id
        ORDER BY q.quoted_at DESC
        LIMIT 50
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    json_response(['quotations' => array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'quote_no' => $row['quote_no'],
        'customer' => $row['customer'] ?? 'Pelanggan belum diisi',
        'status' => $row['status'],
        'total_amount' => money($row['total_amount']),
        'valid_until' => $row['valid_until'],
        'quoted_at' => $row['quoted_at'],
    ], $stmt->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    $productId = (int) ($data['product_id'] ?? 0);
    $qty = (float) ($data['qty'] ?? 0);
    $customerName = trim((string) ($data['customer_name'] ?? ''));

    if ($productId <= 0 || $qty <= 0) {
        json_response(['error' => 'Produk dan qty quotation wajib valid.'], 422);
    }

    if ($customerName === '') {
        json_response(['error' => 'Nama pelanggan wajib diisi untuk quotation.'], 422);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, sale_price, sold_by_weight
            FROM products
            WHERE id = :id
              AND outlet_id = :outlet_id
              AND is_active = 1
        ");
        $stmt->execute([
            ':id' => $productId,
            ':outlet_id' => $outletId,
        ]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }

        if ((int) $product['sold_by_weight'] !== 1 && !quantity_is_whole($qty)) {
            throw new RuntimeException('Qty ' . $product['name'] . ' harus bilangan bulat karena produk dijual satuan.');
        }

        $stmt = $pdo->prepare("INSERT INTO customers (outlet_id, name, phone) VALUES (:outlet_id, :name, :phone)");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':name' => $customerName,
            ':phone' => trim((string) ($data['customer_phone'] ?? '')),
        ]);
        $customerId = (int) $pdo->lastInsertId();

        $quoteNo = quotation_number($pdo, $outletId);
        $unitPrice = (float) $product['sale_price'];
        $subtotal = $unitPrice * $qty;
        $tax = tax_breakdown($pdo, $outletId, $subtotal);
        $total = $tax['total_amount'];
        $validUntil = trim((string) ($data['valid_until'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        $stmt = $pdo->prepare("
            INSERT INTO quotations (
                outlet_id, user_id, customer_id, quote_no, status,
                subtotal_amount, tax_rate, tax_amount, total_amount, valid_until, notes
            )
            VALUES (
                :outlet_id, :user_id, :customer_id, :quote_no, 'sent',
                :subtotal_amount, :tax_rate, :tax_amount, :total_amount, :valid_until, :notes
            )
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':user_id' => $userId,
            ':customer_id' => $customerId,
            ':quote_no' => $quoteNo,
            ':subtotal_amount' => $tax['subtotal_amount'],
            ':tax_rate' => $tax['tax_rate'],
            ':tax_amount' => $tax['tax_amount'],
            ':total_amount' => $total,
            ':valid_until' => $validUntil !== '' ? $validUntil : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);
        $quotationId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO quotation_items (quotation_id, product_id, qty, unit_price, subtotal)
            VALUES (:quotation_id, :product_id, :qty, :unit_price, :subtotal)
        ");
        $stmt->execute([
            ':quotation_id' => $quotationId,
            ':product_id' => $productId,
            ':qty' => $qty,
            ':unit_price' => $unitPrice,
            ':subtotal' => $subtotal,
        ]);

        $pdo->commit();
        audit_log($pdo, $outletId, $userId, 'quotation_create', 'quotation', $quotationId, 'Quotation dibuat.', [
            'quote_no' => $quoteNo,
            'customer' => $customerName,
            'total_amount' => money($total),
        ]);

        json_response([
            'message' => 'Quotation berhasil dibuat.',
            'quotation_id' => $quotationId,
            'quote_no' => $quoteNo,
            'total_amount' => money($total),
        ], 201);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

json_response(['error' => 'Method tidak didukung.'], 405);
