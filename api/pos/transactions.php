<?php
declare(strict_types=1);

require_once __DIR__ . '/pos_helpers.php';
require_method('POST');

$user = require_pos_auth();
$outletId = (int) ($user['outlet_id'] ?? $user['tenant_id'] ?? 0);
$userId = (int) $user['id'];

$input = read_json();

// Validasi input minimum
if (empty($input['items']) || !is_array($input['items'])) {
    json_response(['error' => 'Daftar item belanja tidak boleh kosong.'], 400);
}

$paymentMethod = $input['payment_method'] ?? 'cash';
$notes = $input['notes'] ?? '';
$discount = money($input['discount'] ?? 0);
$tax = money($input['tax'] ?? 0);

try {
    $pdo = db();
    $pdo->beginTransaction();

    // 1. Generate Invoice No (POS-YYMMDD-XXXX)
    $today = date('ymd');
    $stmt = $pdo->prepare("SELECT invoice_no FROM transactions WHERE outlet_id = ? AND invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$outletId, "POS-$today-%"]);
    $lastInvoice = $stmt->fetchColumn();

    $sequence = 1;
    if ($lastInvoice) {
        $parts = explode('-', $lastInvoice);
        if (count($parts) === 3) {
            $sequence = (int) $parts[2] + 1;
        }
    }
    $invoiceNo = sprintf("POS-%s-%04d", $today, $sequence);

    // 2. Hitung total berdasarkan harga dari database (keamanan)
    $subtotal = 0;
    $items = [];
    
    // Ambil data asli produk dari DB berdasarkan ID yang dikirim
    $productIds = array_column($input['items'], 'product_id');
    if (empty($productIds)) {
        throw new Exception("Format item tidak valid.");
    }
    
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $prodStmt = $pdo->prepare("SELECT id, name, sale_price as price, cost_price as cost, stock_qty as stock, is_composite FROM products WHERE outlet_id = ? AND id IN ($placeholders)");
    $prodParams = array_merge([$outletId], $productIds);
    $prodStmt->execute($prodParams);
    
    $dbProducts = [];
    foreach ($prodStmt->fetchAll() as $p) {
        $dbProducts[(int)$p['id']] = $p;
    }

    foreach ($input['items'] as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        $qty = quantity($item['qty'] ?? 1);
        
        if ($qty <= 0) continue;
        if (!isset($dbProducts[$pid])) {
            throw new Exception("Produk ID $pid tidak ditemukan di sistem.");
        }
        
        $prod = $dbProducts[$pid];
        $price = money($prod['price']);
        $cost = money($prod['cost']);
        
        // Item total
        $itemTotal = $price * $qty;
        $subtotal += $itemTotal;
        
        $items[] = [
            'product_id' => $pid,
            'name' => $prod['name'],
            'qty' => $qty,
            'price' => $price,
            'cost' => $cost,
            'total' => $itemTotal,
            'is_composite' => (bool)$prod['is_composite']
        ];
    }
    
    if (empty($items)) {
        throw new Exception("Tidak ada item yang valid untuk diproses.");
    }

    $grandTotal = $subtotal - $discount + $tax;

    // 3. Simpan Transaksi Induk
    $insertTx = $pdo->prepare("
        INSERT INTO transactions (
            outlet_id, user_id, invoice_no, 
            subtotal_amount, discount_amount, tax_amount, total_amount, cost_amount,
            payment_method
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // Asumsikan notes tidak ada di tabel transactions, kita keluarkan dari insert
    // Kita perlu menghitung total cost dari semua items
    $totalCost = 0;
    foreach ($items as $item) {
        $totalCost += $item['cost'] * $item['qty'];
    }

    $insertTx->execute([
        $outletId, $userId, $invoiceNo,
        $subtotal, $discount, $tax, $grandTotal, $totalCost,
        $paymentMethod
    ]);
    
    $transactionId = (int) $pdo->lastInsertId();

    // 4. Simpan Item Transaksi & Potong Stok
    $insertItem = $pdo->prepare("
        INSERT INTO transaction_items (transaction_id, product_id, qty, unit_price, unit_cost, subtotal)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    // Siapkan update stok
    $updateStock = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND outlet_id = ?");
    
    foreach ($items as $item) {
        $insertItem->execute([
            $transactionId,
            $item['product_id'],
            $item['qty'],
            $item['price'],
            $item['cost'],
            $item['total']
        ]);
        
        // Potong stok (hanya produk fisik/simple yang punya stok langsung)
        if (!$item['is_composite']) {
            $updateStock->execute([$item['qty'], $item['product_id'], $outletId]);
            notify_low_stock_if_needed($pdo, $outletId, $item['product_id'], $item['qty']);
        }
    }

    $pdo->commit();

    json_response([
        'status' => 'success',
        'message' => 'Transaksi berhasil disimpan.',
        'data' => [
            'transaction_id' => $transactionId,
            'invoice_no' => $invoiceNo,
            'total' => $grandTotal
        ]
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("POS API Transaction Error: " . $e->getMessage());
    json_response(['error' => $e->getMessage()], 400);
}
