<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

function receive_purchase(PDO $pdo, int $outletId, array $data): void
{
    $purchaseId = (int) ($data['id'] ?? 0);
    $additionalCost = (float) ($data['additional_cost'] ?? 0);

    if ($purchaseId <= 0 || $additionalCost < 0) {
        json_response(['error' => 'Purchase order dan biaya lain wajib valid.'], 422);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT id, invoice_no, status
            FROM purchases
            WHERE id = :id
              AND outlet_id = :outlet_id
            FOR UPDATE
        ");
        $stmt->execute([
            ':id' => $purchaseId,
            ':outlet_id' => $outletId,
        ]);
        $purchase = $stmt->fetch();

        if (!$purchase) {
            throw new RuntimeException('Purchase order tidak ditemukan.');
        }

        if ($purchase['status'] === 'received') {
            throw new RuntimeException('Purchase order ini sudah diterima.');
        }

        $stmt = $pdo->prepare("
            SELECT pi.product_id, pi.qty, pi.unit_cost, pr.cost_price AS previous_cost
            FROM purchase_items pi
            JOIN products pr ON pr.id = pi.product_id
            WHERE pi.purchase_id = :purchase_id
              AND pr.outlet_id = :outlet_id
            FOR UPDATE
        ");
        $stmt->execute([
            ':purchase_id' => $purchaseId,
            ':outlet_id' => $outletId,
        ]);
        $items = $stmt->fetchAll();

        if (count($items) === 0) {
            throw new RuntimeException('Item purchase order tidak ditemukan.');
        }

        $totalQty = array_sum(array_map(fn ($item) => (float) $item['qty'], $items));
        if ($totalQty <= 0) {
            throw new RuntimeException('Qty purchase order tidak valid.');
        }

        $extraCostPerPcs = $additionalCost / $totalQty;

        foreach ($items as $item) {
            $qty = (float) $item['qty'];
            $hppPo = (float) $item['unit_cost'] + $extraCostPerPcs;
            $previousCost = (float) $item['previous_cost'];
            $newCost = $previousCost > 0 ? (($hppPo + $previousCost) / 2) : $hppPo;

            $stmt = $pdo->prepare("
                UPDATE products
                SET stock_qty = stock_qty + :qty,
                    cost_price = :cost_price
                WHERE id = :product_id
                  AND outlet_id = :outlet_id
            ");
            $stmt->execute([
                ':qty' => $qty,
                ':cost_price' => $newCost,
                ':product_id' => (int) $item['product_id'],
                ':outlet_id' => $outletId,
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE purchases
            SET status = 'received',
                additional_cost = :additional_cost,
                received_at = NOW()
            WHERE id = :id
              AND outlet_id = :outlet_id
        ");
        $stmt->execute([
            ':additional_cost' => $additionalCost,
            ':id' => $purchaseId,
            ':outlet_id' => $outletId,
        ]);

        $pdo->commit();
        $actor = current_user();
        audit_log($pdo, $outletId, $actor ? (int) $actor['id'] : null, 'purchase_receive', 'purchase', $purchaseId, 'Barang PO diterima.', [
            'invoice_no' => $purchase['invoice_no'],
            'additional_cost' => $additionalCost,
        ]);

        json_response([
            'message' => 'Barang berhasil diterima dan HPP diperbarui.',
            'purchase_id' => $purchaseId,
            'invoice_no' => $purchase['invoice_no'],
        ]);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $detailId = (int) ($_GET['id'] ?? 0);
    if ($detailId > 0) {
        $stmt = $pdo->prepare("
            SELECT p.id, s.name AS supplier, p.invoice_no, p.total_amount, p.status,
                   p.additional_cost, p.purchased_at, p.received_at
            FROM purchases p
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = :id
              AND p.outlet_id = :outlet_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $detailId,
            ':outlet_id' => $outletId,
        ]);
        $purchase = $stmt->fetch();

        if (!$purchase) {
            json_response(['error' => 'Purchase order tidak ditemukan.'], 404);
        }

        $itemStmt = $pdo->prepare("
            SELECT pr.sku, pr.name, pi.qty, pi.unit_cost, pi.subtotal
            FROM purchase_items pi
            JOIN products pr ON pr.id = pi.product_id
            WHERE pi.purchase_id = :id
            ORDER BY pi.id ASC
        ");
        $itemStmt->execute([':id' => $detailId]);

        json_response([
            'purchase' => [
                'id' => (int) $purchase['id'],
                'supplier' => $purchase['supplier'] ?? 'Supplier belum diisi',
                'invoice_no' => $purchase['invoice_no'],
                'total_amount' => money($purchase['total_amount']),
                'status' => $purchase['status'],
                'additional_cost' => money($purchase['additional_cost']),
                'purchased_at' => $purchase['purchased_at'],
                'received_at' => $purchase['received_at'],
            ],
            'items' => array_map(fn ($row) => [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'qty' => quantity($row['qty']),
                'unit_cost' => money($row['unit_cost']),
                'subtotal' => money($row['subtotal']),
            ], $itemStmt->fetchAll()),
        ]);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE outlet_id = :outlet_id");
    $countStmt->execute([':outlet_id' => $outletId]);
    $totalRecords = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));

    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT p.id, s.name AS supplier, p.invoice_no, p.total_amount, p.status,
               p.additional_cost, p.purchased_at, p.received_at
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.outlet_id = :outlet_id
        ORDER BY p.purchased_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    json_response([
        'purchases' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'supplier' => $row['supplier'],
            'invoice_no' => $row['invoice_no'],
            'total_amount' => money($row['total_amount']),
            'status' => $row['status'],
            'additional_cost' => money($row['additional_cost']),
            'purchased_at' => $row['purchased_at'],
            'received_at' => $row['received_at'],
        ], $stmt->fetchAll()),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
        ]
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    if (($data['action'] ?? '') === 'receive') {
        receive_purchase($pdo, $outletId, $data);
    }

    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        json_response(['error' => 'Minimal satu item purchase order wajib diisi.'], 422);
    }

    $pdo->beginTransaction();

    try {
        $supplierId = null;
        $supplierName = trim((string) ($data['supplier_name'] ?? ''));

        if ($supplierName !== '') {
            $stmt = $pdo->prepare("INSERT INTO suppliers (outlet_id, name) VALUES (:outlet_id, :name)");
            $stmt->execute([
                ':outlet_id' => $outletId,
                ':name' => $supplierName,
            ]);
            $supplierId = (int) $pdo->lastInsertId();
        }

        $preparedItems = [];
        $totalAmount = 0.0;
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $unitCost = (float) ($item['unit_cost'] ?? 0);

            if ($productId <= 0 || $qty <= 0 || $unitCost < 0) {
                throw new RuntimeException('Produk, qty, dan harga beli setiap item wajib valid.');
            }

            $stmt = $pdo->prepare("
                SELECT id, name, sold_by_weight, is_composite
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
                throw new RuntimeException('Produk tidak ditemukan untuk outlet ini.');
            }
            if ((int) $product['is_composite'] === 1) {
                throw new RuntimeException('Purchase order dibuat untuk komponen, bukan produk composite.');
            }
            if ((int) $product['sold_by_weight'] !== 1 && !quantity_is_whole($qty)) {
                throw new RuntimeException('Qty ' . $product['name'] . ' harus bilangan bulat karena produk dijual satuan.');
            }

            $subtotal = $qty * $unitCost;
            $totalAmount += $subtotal;
            $preparedItems[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'subtotal' => $subtotal,
            ];
        }

        $poNumber = trim((string) ($data['invoice_no'] ?? ''));
        if ($poNumber === '') {
            $poNumber = purchase_order_number($pdo, $outletId);
        }

        $stmt = $pdo->prepare("
            INSERT INTO purchases (outlet_id, supplier_id, invoice_no, total_amount)
            VALUES (:outlet_id, :supplier_id, :invoice_no, :total_amount)
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':supplier_id' => $supplierId,
            ':invoice_no' => $poNumber,
            ':total_amount' => $totalAmount,
        ]);

        $purchaseId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO purchase_items (purchase_id, product_id, qty, unit_cost, subtotal)
            VALUES (:purchase_id, :product_id, :qty, :unit_cost, :subtotal)
        ");
        foreach ($preparedItems as $item) {
            $stmt->execute([
                ':purchase_id' => $purchaseId,
                ':product_id' => $item['product_id'],
                ':qty' => $item['qty'],
                ':unit_cost' => $item['unit_cost'],
                ':subtotal' => $item['subtotal'],
            ]);
        }

        $pdo->commit();
        audit_log($pdo, $outletId, $userId, 'purchase_create', 'purchase', $purchaseId, 'Purchase order dibuat.', [
            'invoice_no' => $poNumber,
            'supplier' => $supplierName,
            'total_amount' => money($totalAmount),
            'item_count' => count($preparedItems),
        ]);

        json_response([
            'message' => 'Purchase order berhasil disimpan.',
            'purchase_id' => $purchaseId,
            'invoice_no' => $poNumber,
            'total_amount' => money($totalAmount),
            'item_count' => count($preparedItems),
        ], 201);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = read_json();
    receive_purchase($pdo, $outletId, $data);
}


if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = read_json();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        if (isset($data['id'])) {
            $ids = [$data['id']];
        } else {
            json_response(['error' => 'ID purchase tidak valid.'], 422);
        }
    }

    $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
    if (count($ids) === 0) {
        json_response(['error' => 'Tidak ada ID purchase yang valid.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $deletedCount = 0;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare("SELECT id, invoice_no, total_amount, status FROM purchases WHERE id = :id AND outlet_id = :outlet_id LIMIT 1 FOR UPDATE");
            $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);
            $purchase = $stmt->fetch();

            if (!$purchase) {
                continue;
            }

        if ($purchase['status'] === 'received') {
            // Ambil item purchase
            $itemStmt = $pdo->prepare("SELECT product_id, qty FROM purchase_items WHERE purchase_id = :id");
            $itemStmt->execute([':id' => $id]);
            $items = $itemStmt->fetchAll();

            // Kembalikan stok (kurangi)
            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET stock_qty = GREATEST(0, stock_qty - :qty) 
                    WHERE id = :product_id AND outlet_id = :outlet_id
                ");
                $stmt->execute([
                    ':qty' => (float) $item['qty'],
                    ':product_id' => (int) $item['product_id'],
                    ':outlet_id' => $outletId,
                ]);
            }
        }

        // Hapus item dan purchase
        $stmt = $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = :id");
        $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare("DELETE FROM purchases WHERE id = :id AND outlet_id = :outlet_id");
            $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);

            audit_log($pdo, $outletId, $userId, 'purchase_delete', 'purchase', $id, 'Purchase order dihapus.', [
                'invoice_no' => $purchase['invoice_no'],
                'total_amount' => money($purchase['total_amount']),
            ]);

            $deletedCount++;
        }

        if ($deletedCount === 0) {
            throw new RuntimeException('Tidak ada purchase yang berhasil dihapus.');
        }

        $pdo->commit();
        json_response(['message' => "$deletedCount purchase berhasil dihapus."]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Gagal menghapus purchase: ' . $e->getMessage()], 500);
    }
}

json_response(['error' => 'Method tidak didukung.'], 405);
