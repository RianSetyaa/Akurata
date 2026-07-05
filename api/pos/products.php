<?php
declare(strict_types=1);

require_once __DIR__ . '/pos_helpers.php';
require_method('GET');

$user = require_pos_auth();
$outletId = (int) ($user['outlet_id'] ?? $user['tenant_id'] ?? 0);

try {
    $pdo = db();
    
    // Ambil produk aktif yang bukan komponen (is_composite bisa dijual, bahan baku murni biasanya tidak ditampilkan,
    // tapi kita filter berdasarkan is_active = 1 saja dan stok saat ini)
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            name, 
            sku, 
            barcode, 
            category, 
            is_composite,
            stock_qty, 
            sale_price, 
            cost_price
        FROM products 
        WHERE outlet_id = ? AND is_active = 1 
        ORDER BY name ASC
    ");
    $stmt->execute([$outletId]);
    $products = $stmt->fetchAll();

    // Format harga dan stok agar API friendly
    foreach ($products as &$p) {
        $p['id'] = (int) $p['id'];
        $p['stock'] = quantity($p['stock_qty']);
        $p['sell_price'] = money($p['sale_price']);
        $p['buy_price'] = money($p['cost_price']);
        $p['type'] = $p['is_composite'] ? 'composite' : 'simple';
        
        unset($p['stock_qty'], $p['sale_price'], $p['cost_price'], $p['is_composite']);
    }

    json_response([
        'status' => 'success',
        'data' => $products
    ]);
} catch (Exception $e) {
    error_log("POS API Products Error: " . $e->getMessage());
    json_response(['error' => 'Gagal mengambil data produk.'], 500);
}
