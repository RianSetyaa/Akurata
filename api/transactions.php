<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $detailId = (int) ($_GET['id'] ?? 0);
    if ($detailId > 0) {
        $stmt = $pdo->prepare("
            SELECT t.id, t.invoice_no, u.name AS cashier, c.name AS customer, c.phone AS customer_phone,
                   t.payment_method, t.payment_status,
                   CASE WHEN t.subtotal_amount > 0 THEN t.subtotal_amount ELSE t.total_amount - t.tax_amount END AS subtotal_amount,
                   t.discount_amount, t.tax_rate, t.tax_amount,
                   t.total_amount, t.cost_amount, t.sold_at
            FROM transactions t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN customers c ON c.id = t.customer_id
            WHERE t.id = :id
              AND t.outlet_id = :outlet_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $detailId,
            ':outlet_id' => $outletId,
        ]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            json_response(['error' => 'Transaksi tidak ditemukan.'], 404);
        }

        $itemStmt = $pdo->prepare("
            SELECT p.sku, p.name, ti.qty, ti.unit_price, ti.unit_cost,
                   ti.discount_rate, ti.discount_amount, ti.subtotal
            FROM transaction_items ti
            JOIN products p ON p.id = ti.product_id
            WHERE ti.transaction_id = :id
            ORDER BY ti.id ASC
        ");
        $itemStmt->execute([':id' => $detailId]);

        json_response([
            'transaction' => [
                'id' => (int) $transaction['id'],
                'invoice_no' => $transaction['invoice_no'],
                'cashier' => $transaction['cashier'],
                'customer' => $transaction['customer'] ?? 'Pelanggan umum',
                'customer_phone' => $transaction['customer_phone'],
                'payment_method' => $transaction['payment_method'],
                'payment_status' => $transaction['payment_status'],
                'subtotal_amount' => money($transaction['subtotal_amount']),
                'gross_amount' => money((float) $transaction['subtotal_amount'] + (float) $transaction['discount_amount']),
                'discount_amount' => money($transaction['discount_amount']),
                'tax_rate' => (float) $transaction['tax_rate'],
                'tax_amount' => money($transaction['tax_amount']),
                'total_amount' => money($transaction['total_amount']),
                'cost_amount' => money($transaction['cost_amount']),
                'gross_profit' => money((float) $transaction['subtotal_amount'] - (float) $transaction['cost_amount']),
                'sold_at' => $transaction['sold_at'],
            ],
            'items' => array_map(fn ($row) => [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'qty' => quantity($row['qty']),
                'unit_price' => money($row['unit_price']),
                'unit_cost' => money($row['unit_cost']),
                'discount_rate' => (float) $row['discount_rate'],
                'discount_amount' => money($row['discount_amount']),
                'subtotal' => money($row['subtotal']),
            ], $itemStmt->fetchAll()),
        ]);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM transactions
        WHERE outlet_id = :outlet_id
    ");
    $countStmt->execute([':outlet_id' => $outletId]);
    $totalCount = (int) $countStmt->fetch()['total'];
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT t.id, t.invoice_no, u.name AS cashier, c.name AS customer,
               t.payment_method, t.payment_status, t.total_amount,
               CASE WHEN t.subtotal_amount > 0 THEN t.subtotal_amount ELSE t.total_amount - t.tax_amount END AS subtotal_amount,
               t.cost_amount, t.sold_at
        FROM transactions t
        JOIN users u ON u.id = t.user_id
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.outlet_id = :outlet_id
        ORDER BY t.sold_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':outlet_id', $outletId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    json_response([
        'transactions' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'invoice_no' => $row['invoice_no'],
            'cashier' => $row['cashier'],
            'customer' => $row['customer'],
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'total_amount' => money($row['total_amount']),
            'net_profit' => money((float) $row['subtotal_amount'] - (float) $row['cost_amount']),
            'sold_at' => $row['sold_at'],
        ], $stmt->fetchAll()),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalCount,
            'total_pages' => $totalPages,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    $items = $data['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
        json_response(['error' => 'Minimal satu item transaksi wajib diisi.'], 422);
    }

    $paymentMethod = $data['payment_method'] ?? 'cash';
    if (!in_array($paymentMethod, ['cash', 'qris', 'transfer', 'tempo'], true)) {
        json_response(['error' => 'Metode pembayaran tidak valid.'], 422);
    }

    $downPayment = max(0, (float) ($data['down_payment'] ?? 0));
    $downPaymentMethod = $data['down_payment_method'] ?? 'cash';
    if (!in_array($downPaymentMethod, ['cash', 'qris', 'transfer'], true)) {
        json_response(['error' => 'Metode DP tidak valid.'], 422);
    }

    $customerName = trim((string) ($data['customer_name'] ?? ''));
    if ($paymentMethod === 'tempo' && $customerName === '') {
        json_response(['error' => 'Nama pelanggan wajib diisi untuk penjualan piutang.'], 422);
    }

    if ($paymentMethod !== 'tempo' && $downPayment > 0) {
        json_response(['error' => 'DP hanya digunakan untuk penjualan tempo.'], 422);
    }

    $pdo->beginTransaction();

    try {
        $customerId = null;
        $stockSyncProductIds = [];

        if ($customerName !== '') {
            $stmt = $pdo->prepare("INSERT INTO customers (outlet_id, name, phone) VALUES (:outlet_id, :name, :phone)");
            $stmt->execute([
                ':outlet_id' => $outletId,
                ':name' => $customerName,
                ':phone' => trim((string) ($data['customer_phone'] ?? '')),
            ]);
            $customerId = (int) $pdo->lastInsertId();
        }

        $invoice = invoice_number($pdo, $outletId);
        $grossTotal = 0.0;
        $totalDiscount = 0.0;
        $total = 0.0;
        $cost = 0.0;
        $preparedItems = [];
        $stockRequirements = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $discountRate = (float) ($item['discount_rate'] ?? 0);

            if ($productId <= 0 || $qty <= 0 || $discountRate < 0 || $discountRate > 100) {
                throw new RuntimeException('Produk, qty, dan diskon transaksi wajib valid.');
            }

            $stmt = $pdo->prepare("
                SELECT id, name, stock_qty, cost_price, sale_price, sold_by_weight, is_composite
                FROM products
                WHERE id = :id
                  AND outlet_id = :outlet_id
                  AND is_active = 1
                FOR UPDATE
            ");
            $stmt->execute([
                ':id' => $productId,
                ':outlet_id' => $outletId,
            ]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            if ((int) $product['is_composite'] !== 1
                && (int) $product['sold_by_weight'] !== 1
                && !quantity_is_whole($qty)) {
                throw new RuntimeException('Qty ' . $product['name'] . ' harus bilangan bulat karena produk dijual satuan.');
            }

            $unitPrice = (float) $product['sale_price'];
            $unitCost = (float) $product['cost_price'];

            if ((int) $product['is_composite'] === 1) {
                $componentStmt = $pdo->prepare("
                    SELECT pc.component_product_id, pc.quantity, p.name, p.cost_price
                    FROM product_components pc
                    JOIN products p ON p.id = pc.component_product_id
                    WHERE pc.product_id = :product_id
                      AND pc.outlet_id = :outlet_id
                      AND p.is_active = 1
                    FOR UPDATE
                ");
                $componentStmt->execute([
                    ':product_id' => $productId,
                    ':outlet_id' => $outletId,
                ]);
                $components = $componentStmt->fetchAll();
                if (!$components) {
                    throw new RuntimeException('Komponen ' . $product['name'] . ' belum diatur.');
                }

                $unitCost = 0.0;
                foreach ($components as $component) {
                    $componentId = (int) $component['component_product_id'];
                    $requiredQty = (float) $component['quantity'] * $qty;
                    $unitCost += (float) $component['cost_price'] * (float) $component['quantity'];
                    $stockRequirements[$componentId]['qty'] = ($stockRequirements[$componentId]['qty'] ?? 0) + $requiredQty;
                    $stockRequirements[$componentId]['name'] = $component['name'];
                }
            } else {
                $stockRequirements[$productId]['qty'] = ($stockRequirements[$productId]['qty'] ?? 0) + $qty;
                $stockRequirements[$productId]['name'] = $product['name'];
            }

            $grossSubtotal = $unitPrice * $qty;
            $discountAmount = round($grossSubtotal * $discountRate / 100, 2);
            $subtotal = max(0, $grossSubtotal - $discountAmount);
            $grossTotal += $grossSubtotal;
            $totalDiscount += $discountAmount;
            $total += $subtotal;
            $cost += $unitCost * $qty;

            $preparedItems[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'discount_rate' => $discountRate,
                'discount_amount' => $discountAmount,
                'subtotal' => $subtotal,
            ];
        }

        foreach ($stockRequirements as $stockProductId => $requirement) {
            $stockStmt = $pdo->prepare("
                SELECT stock_qty
                FROM products
                WHERE id = :id
                  AND outlet_id = :outlet_id
                FOR UPDATE
            ");
            $stockStmt->execute([
                ':id' => $stockProductId,
                ':outlet_id' => $outletId,
            ]);
            $stock = $stockStmt->fetch();
            if (!$stock || (float) $stock['stock_qty'] < (float) $requirement['qty']) {
                throw new RuntimeException('Stok ' . $requirement['name'] . ' tidak cukup.');
            }
        }

        $tax = tax_breakdown($pdo, $outletId, $total);
        $subtotalAmount = $tax['subtotal_amount'];
        $taxRate = $tax['tax_rate'];
        $taxAmount = $tax['tax_amount'];
        $grandTotal = $tax['total_amount'];

        if ($downPayment > $grandTotal) {
            throw new RuntimeException('DP tidak boleh lebih besar dari total transaksi.');
        }

        $isTempo = $paymentMethod === 'tempo';
        $status = $isTempo && $downPayment < $grandTotal ? 'receivable' : 'paid';
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                outlet_id, user_id, customer_id, invoice_no, payment_method, payment_status,
                subtotal_amount, discount_amount, tax_rate, tax_amount, total_amount, cost_amount
            )
            VALUES (
                :outlet_id, :user_id, :customer_id, :invoice_no, :payment_method, :payment_status,
                :subtotal_amount, :discount_amount, :tax_rate, :tax_amount, :total_amount, :cost_amount
            )
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':user_id' => $userId,
            ':customer_id' => $customerId,
            ':invoice_no' => $invoice,
            ':payment_method' => $paymentMethod,
            ':payment_status' => $status,
            ':subtotal_amount' => $subtotalAmount,
            ':discount_amount' => $totalDiscount,
            ':tax_rate' => $taxRate,
            ':tax_amount' => $taxAmount,
            ':total_amount' => $grandTotal,
            ':cost_amount' => $cost,
        ]);

        $transactionId = (int) $pdo->lastInsertId();

        foreach ($preparedItems as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO transaction_items (
                    transaction_id, product_id, qty, unit_price, unit_cost,
                    discount_rate, discount_amount, subtotal
                )
                VALUES (
                    :transaction_id, :product_id, :qty, :unit_price, :unit_cost,
                    :discount_rate, :discount_amount, :subtotal
                )
            ");
            $stmt->execute([
                ':transaction_id' => $transactionId,
                ':product_id' => $item['product_id'],
                ':qty' => $item['qty'],
                ':unit_price' => $item['unit_price'],
                ':unit_cost' => $item['unit_cost'],
                ':discount_rate' => $item['discount_rate'],
                ':discount_amount' => $item['discount_amount'],
                ':subtotal' => $item['subtotal'],
            ]);

        }

        foreach ($stockRequirements as $stockProductId => $requirement) {
            $stmt = $pdo->prepare("
                UPDATE products
                SET stock_qty = stock_qty - :qty
                WHERE id = :product_id
                  AND outlet_id = :outlet_id
            ");
            $stmt->execute([
                ':qty' => $requirement['qty'],
                ':product_id' => $stockProductId,
                ':outlet_id' => $outletId,
            ]);
            $stockSyncProductIds[] = (int) $stockProductId;
        }

        if ($isTempo) {
            $dueDate = trim((string) ($data['due_date'] ?? ''));
            if ($dueDate === '') {
                $dueDate = date('Y-m-d', strtotime('+7 days'));
            }

            $receivableStatus = match (true) {
                $downPayment >= $grandTotal => 'paid',
                $downPayment > 0 => 'partial',
                default => 'open',
            };
            $stmt = $pdo->prepare("
                INSERT INTO receivables (outlet_id, transaction_id, customer_id, amount, paid_amount, due_date, status, paid_at)
                VALUES (:outlet_id, :transaction_id, :customer_id, :amount, :paid_amount, :due_date, :status, :paid_at)
            ");
            $stmt->execute([
                ':outlet_id' => $outletId,
                ':transaction_id' => $transactionId,
                ':customer_id' => $customerId,
                ':amount' => $grandTotal,
                ':paid_amount' => $downPayment,
                ':due_date' => $dueDate,
                ':status' => $receivableStatus,
                ':paid_at' => $receivableStatus === 'paid' ? date('Y-m-d H:i:s') : null,
            ]);

            $receivableId = (int) $pdo->lastInsertId();

            if ($downPayment > 0) {
                $paymentNo = receivable_payment_number($pdo, $outletId);
                $stmt = $pdo->prepare("
                    INSERT INTO receivable_payments (outlet_id, receivable_id, user_id, payment_no, payment_method, amount, notes)
                    VALUES (:outlet_id, :receivable_id, :user_id, :payment_no, :payment_method, :amount, :notes)
                ");
                $stmt->execute([
                    ':outlet_id' => $outletId,
                    ':receivable_id' => $receivableId,
                    ':user_id' => $userId,
                    ':payment_no' => $paymentNo,
                    ':payment_method' => $downPaymentMethod,
                    ':amount' => $downPayment,
                    ':notes' => 'DP transaksi ' . $invoice,
                ]);
            }
        }

        $pdo->commit();
        audit_log($pdo, $outletId, $userId, 'transaction_create', 'transaction', $transactionId, 'Transaksi penjualan dibuat.', [
            'invoice_no' => $invoice,
            'payment_method' => $paymentMethod,
            'payment_status' => $status,
            'total_amount' => money($grandTotal),
            'item_count' => count($preparedItems),
        ]);

        $loyverseReceiptNumber = null;
        $loyverseReceiptWarning = null;
        if (!$isTempo) {
            try {
                $loyverseReceiptNumber = loyverse_sync_transaction_receipt($pdo, $outletId, $transactionId);
            } catch (Throwable $syncError) {
                $loyverseReceiptWarning = $syncError->getMessage();
            }
        }

        $loyverseStockWarning = null;
        foreach (array_unique($stockSyncProductIds) as $syncProductId) {
            try {
                loyverse_sync_product_stock($pdo, $outletId, $syncProductId);
            } catch (Throwable $syncError) {
                $loyverseStockWarning = $syncError->getMessage();
                break;
            }
        }

        json_response([
            'message' => 'Transaksi berhasil disimpan.',
            'transaction_id' => $transactionId,
            'invoice_no' => $invoice,
            'gross_amount' => money($grossTotal),
            'discount_amount' => money($totalDiscount),
            'subtotal_amount' => money($subtotalAmount),
            'tax_amount' => money($taxAmount),
            'total_amount' => money($grandTotal),
            'item_count' => count($preparedItems),
            'loyverse_receipt_number' => $loyverseReceiptNumber,
            'loyverse_receipt_warning' => $loyverseReceiptWarning,
            'loyverse_stock_warning' => $loyverseStockWarning,
        ], 201);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = read_json();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        if (isset($data['id'])) {
            $ids = [$data['id']];
        } else {
            json_response(['error' => 'ID transaksi tidak valid.'], 422);
        }
    }

    $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
    if (count($ids) === 0) {
        json_response(['error' => 'Tidak ada ID transaksi yang valid.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $deletedCount = 0;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare("SELECT id, invoice_no, total_amount FROM transactions WHERE id = :id AND outlet_id = :outlet_id LIMIT 1 FOR UPDATE");
            $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);
            $transaction = $stmt->fetch();

            if (!$transaction) {
                continue; // Lewati jika tidak ditemukan
            }

        // Ambil item transaksi
        $itemStmt = $pdo->prepare("
            SELECT ti.product_id, ti.qty, p.is_composite, p.name 
            FROM transaction_items ti
            JOIN products p ON p.id = ti.product_id
            WHERE ti.transaction_id = :transaction_id
        ");
        $itemStmt->execute([':transaction_id' => $id]);
        $items = $itemStmt->fetchAll();

        $stockToRestore = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $qty = (float) $item['qty'];

            if ((int) $item['is_composite'] === 1) {
                // Ambil komponen saat ini untuk mengembalikan stok
                $compStmt = $pdo->prepare("SELECT component_product_id, quantity FROM product_components WHERE product_id = :product_id AND outlet_id = :outlet_id");
                $compStmt->execute([':product_id' => $productId, ':outlet_id' => $outletId]);
                $components = $compStmt->fetchAll();

                foreach ($components as $comp) {
                    $compId = (int) $comp['component_product_id'];
                    $compQty = (float) $comp['quantity'] * $qty;
                    $stockToRestore[$compId] = ($stockToRestore[$compId] ?? 0) + $compQty;
                }
            } else {
                $stockToRestore[$productId] = ($stockToRestore[$productId] ?? 0) + $qty;
            }
        }

        // Kembalikan stok
        foreach ($stockToRestore as $restoreProductId => $restoreQty) {
            $stmt = $pdo->prepare("
                UPDATE products 
                SET stock_qty = stock_qty + :qty 
                WHERE id = :id AND outlet_id = :outlet_id
            ");
            $stmt->execute([
                ':qty' => $restoreQty,
                ':id' => $restoreProductId,
                ':outlet_id' => $outletId,
            ]);
        }

        // Hapus pembayaran piutang dan piutang jika ada
        $stmt = $pdo->prepare("DELETE FROM receivable_payments WHERE receivable_id IN (SELECT id FROM receivables WHERE transaction_id = :id AND outlet_id = :outlet_id)");
        $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);

        $stmt = $pdo->prepare("DELETE FROM receivables WHERE transaction_id = :id AND outlet_id = :outlet_id");
        $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);

        // Hapus item dan transaksi
        $stmt = $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = :id");
        $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = :id AND outlet_id = :outlet_id");
            $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);

            audit_log($pdo, $outletId, $userId, 'transaction_delete', 'transaction', $id, 'Transaksi penjualan dihapus.', [
                'invoice_no' => $transaction['invoice_no'],
                'total_amount' => money($transaction['total_amount']),
            ]);
            
            $deletedCount++;
        }

        if ($deletedCount === 0) {
            throw new RuntimeException('Tidak ada transaksi yang berhasil dihapus.');
        }

        $pdo->commit();
        json_response(['message' => "$deletedCount transaksi berhasil dihapus."]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Gagal menghapus transaksi: ' . $e->getMessage()], 500);
    }
}

json_response(['error' => 'Method tidak didukung.'], 405);
