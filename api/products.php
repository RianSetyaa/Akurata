<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
               sold_by_weight, is_composite,
               loyverse_item_id, loyverse_variant_id, loyverse_synced_at
        FROM products
        WHERE outlet_id = :outlet_id
          AND is_active = 1
        ORDER BY name ASC
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    $products = $stmt->fetchAll();
    $componentStmt = $pdo->prepare("
        SELECT pc.product_id, pc.component_product_id, pc.quantity,
               p.name, p.sku, p.stock_qty, p.cost_price, p.loyverse_variant_id
        FROM product_components pc
        JOIN products p ON p.id = pc.component_product_id
        WHERE pc.outlet_id = :outlet_id
        ORDER BY pc.id ASC
    ");
    $componentStmt->execute([':outlet_id' => $outletId]);
    $componentsByProduct = [];
    foreach ($componentStmt->fetchAll() as $component) {
        $componentsByProduct[(int) $component['product_id']][] = $component;
    }

    json_response(['products' => array_map(function ($row) use ($componentsByProduct) {
        $productId = (int) $row['id'];
        $isComposite = (int) $row['is_composite'] === 1;
        $components = $componentsByProduct[$productId] ?? [];
        $availableStock = (float) $row['stock_qty'];
        $displayCost = (float) $row['cost_price'];

        if ($isComposite && $components) {
            $availableStock = min(array_map(
                fn ($component) => floor((float) $component['stock_qty'] / (float) $component['quantity']),
                $components
            ));
            $displayCost = array_sum(array_map(
                fn ($component) => (float) $component['cost_price'] * (float) $component['quantity'],
                $components
            ));
        }

        return [
            'id' => $productId,
            'sku' => $row['sku'],
            'barcode' => $row['barcode'],
            'name' => $row['name'],
            'category' => $row['category'],
            'stock_qty' => quantity($availableStock),
            'min_stock' => quantity($row['min_stock']),
            'cost_price' => money($displayCost),
            'sale_price' => money($row['sale_price']),
            'sold_by_weight' => (int) $row['sold_by_weight'] === 1,
            'is_composite' => $isComposite,
            'components' => array_map(fn ($component) => [
                'product_id' => (int) $component['component_product_id'],
                'name' => $component['name'],
                'sku' => $component['sku'],
                'quantity' => quantity($component['quantity']),
                'loyverse_synced' => !empty($component['loyverse_variant_id']),
            ], $components),
            'loyverse_item_id' => $row['loyverse_item_id'],
            'loyverse_variant_id' => $row['loyverse_variant_id'],
            'loyverse_synced_at' => $row['loyverse_synced_at'],
            'loyverse_synced' => !empty($row['loyverse_item_id']) || !empty($row['loyverse_variant_id']),
        ];
    }, $products)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    $isComposite = !empty($data['is_composite']) && (string) $data['is_composite'] !== '0';
    $required = $isComposite
        ? ['sku', 'name', 'sale_price']
        : ['sku', 'name', 'stock_qty', 'min_stock', 'cost_price', 'sale_price'];

    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            json_response(['error' => "Field {$field} wajib diisi."], 422);
        }
    }

    $sku = trim((string) $data['sku']);
    $barcode = trim((string) ($data['barcode'] ?? ''));
    $name = trim((string) $data['name']);
    $category = trim((string) ($data['category'] ?? ''));
    $stockQty = $isComposite ? 0.0 : (float) $data['stock_qty'];
    $minStock = $isComposite ? 0.0 : (float) $data['min_stock'];
    $costPrice = $isComposite ? 0.0 : (float) $data['cost_price'];
    $salePrice = (float) $data['sale_price'];
    $soldByWeight = !$isComposite && !empty($data['sold_by_weight']) && (string) $data['sold_by_weight'] !== '0' ? 1 : 0;

    if ($sku === '' || strlen($sku) > 40) {
        json_response(['error' => 'SKU wajib 1-40 karakter agar valid saat push ke Loyverse.'], 422);
    }

    if (strlen($barcode) > 80) {
        json_response(['error' => 'Barcode maksimal 80 karakter.'], 422);
    }

    if ($barcode !== '') {
        $barcodeCheck = $pdo->prepare("
            SELECT id
            FROM products
            WHERE outlet_id = :outlet_id
              AND barcode = :barcode
            LIMIT 1
        ");
        $barcodeCheck->execute([
            ':outlet_id' => $outletId,
            ':barcode' => $barcode,
        ]);
        if ($barcodeCheck->fetch()) {
            json_response(['error' => 'Barcode sudah digunakan oleh produk lain di outlet ini.'], 409);
        }
    }

    if ($name === '' || strlen($name) > 64) {
        json_response(['error' => 'Nama produk wajib 1-64 karakter agar valid saat push ke Loyverse.'], 422);
    }

    if (strlen($category) > 512) {
        json_response(['error' => 'Kategori/deskripsi produk maksimal 512 karakter.'], 422);
    }

    if ($stockQty < 0 || $minStock < 0 || $costPrice < 0 || $salePrice < 0) {
        json_response(['error' => 'Stok dan harga tidak boleh bernilai negatif.'], 422);
    }

    if (!$soldByWeight && (!quantity_is_whole($stockQty) || !quantity_is_whole($minStock))) {
        json_response(['error' => 'Produk satuan harus memakai stok dan min stok bilangan bulat. Pilih Volume/Berat untuk qty desimal.'], 422);
    }

    $components = [];
    if ($isComposite) {
        foreach (($data['components'] ?? []) as $component) {
            $componentId = (int) ($component['product_id'] ?? 0);
            $componentQty = (float) ($component['quantity'] ?? 0);
            if ($componentId <= 0 || $componentQty <= 0) {
                continue;
            }
            $components[$componentId] = ($components[$componentId] ?? 0) + $componentQty;
        }

        if (!$components) {
            json_response(['error' => 'Produk composite wajib memiliki minimal satu komponen.'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($components), '?'));
        $componentCheck = $pdo->prepare("
            SELECT id, name, cost_price, is_composite
            FROM products
            WHERE outlet_id = ?
              AND is_active = 1
              AND id IN ({$placeholders})
        ");
        $componentCheck->execute([$outletId, ...array_keys($components)]);
        $componentProducts = $componentCheck->fetchAll();

        if (count($componentProducts) !== count($components)) {
            json_response(['error' => 'Ada komponen yang tidak ditemukan pada outlet ini.'], 422);
        }

        foreach ($componentProducts as $componentProduct) {
            if ((int) $componentProduct['is_composite'] === 1) {
                json_response(['error' => 'Composite bertingkat belum didukung. Pilih produk biasa sebagai komponen.'], 422);
            }
            $costPrice += (float) $componentProduct['cost_price'] * $components[(int) $componentProduct['id']];
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO products (
              outlet_id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
              sold_by_weight, is_composite
            )
            VALUES (
              :outlet_id, :sku, :barcode, :name, :category, :stock_qty, :min_stock, :cost_price, :sale_price,
              :sold_by_weight, :is_composite
            )
        ");

        $stmt->execute([
            ':outlet_id' => $outletId,
            ':sku' => $sku,
            ':barcode' => $barcode !== '' ? $barcode : null,
            ':name' => $name,
            ':category' => $category,
            ':stock_qty' => $stockQty,
            ':min_stock' => $minStock,
            ':cost_price' => $costPrice,
            ':sale_price' => $salePrice,
            ':sold_by_weight' => $soldByWeight,
            ':is_composite' => $isComposite ? 1 : 0,
        ]);

        $productId = (int) $pdo->lastInsertId();
        if ($isComposite) {
            $componentInsert = $pdo->prepare("
                INSERT INTO product_components (outlet_id, product_id, component_product_id, quantity)
                VALUES (:outlet_id, :product_id, :component_product_id, :quantity)
            ");
            foreach ($components as $componentId => $componentQty) {
                $componentInsert->execute([
                    ':outlet_id' => $outletId,
                    ':product_id' => $productId,
                    ':component_product_id' => $componentId,
                    ':quantity' => $componentQty,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }

    audit_log($pdo, $outletId, $userId, 'product_create', 'product', $productId, 'Produk baru ditambahkan.', [
        'sku' => $sku,
        'barcode' => $barcode !== '' ? $barcode : null,
        'name' => $name,
        'stock_qty' => $stockQty,
        'sale_price' => $salePrice,
        'sold_by_weight' => $soldByWeight,
        'is_composite' => $isComposite,
        'component_count' => count($components),
    ]);

    json_response(['message' => 'Produk berhasil ditambahkan.', 'id' => $productId], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = read_json();
    $productId = (int) ($data['id'] ?? 0);

    if ($productId <= 0) {
        json_response(['error' => 'ID produk tidak valid.'], 422);
    }

    $productCheck = $pdo->prepare("
        SELECT id, name, is_composite
        FROM products
        WHERE id = :id
          AND outlet_id = :outlet_id
          AND is_active = 1
        LIMIT 1
    ");
    $productCheck->execute([
        ':id' => $productId,
        ':outlet_id' => $outletId,
    ]);
    $product = $productCheck->fetch();
    if (!$product) {
        json_response(['error' => 'Produk tidak ditemukan.'], 404);
    }

    if (($data['action'] ?? '') === 'update') {
        $isComposite = !empty($data['is_composite']) && (string) $data['is_composite'] !== '0';
        $required = $isComposite
            ? ['sku', 'name', 'sale_price']
            : ['sku', 'name', 'stock_qty', 'min_stock', 'cost_price', 'sale_price'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                json_response(['error' => "Field {$field} wajib diisi."], 422);
            }
        }

        $sku = trim((string) $data['sku']);
        $barcode = trim((string) ($data['barcode'] ?? ''));
        $name = trim((string) $data['name']);
        $category = trim((string) ($data['category'] ?? ''));
        $stockQty = $isComposite ? 0.0 : (float) $data['stock_qty'];
        $minStock = $isComposite ? 0.0 : (float) $data['min_stock'];
        $costPrice = $isComposite ? 0.0 : (float) $data['cost_price'];
        $salePrice = (float) $data['sale_price'];
        $soldByWeight = !$isComposite && !empty($data['sold_by_weight']) && (string) $data['sold_by_weight'] !== '0' ? 1 : 0;

        if ($sku === '' || strlen($sku) > 40) {
            json_response(['error' => 'SKU wajib 1-40 karakter agar valid saat push ke Loyverse.'], 422);
        }

        if (strlen($barcode) > 80) {
            json_response(['error' => 'Barcode maksimal 80 karakter.'], 422);
        }

        if ($name === '' || strlen($name) > 64) {
            json_response(['error' => 'Nama produk wajib 1-64 karakter agar valid saat push ke Loyverse.'], 422);
        }

        if (strlen($category) > 512) {
            json_response(['error' => 'Kategori/deskripsi produk maksimal 512 karakter.'], 422);
        }

        if ($stockQty < 0 || $minStock < 0 || $costPrice < 0 || $salePrice < 0) {
            json_response(['error' => 'Stok dan harga tidak boleh bernilai negatif.'], 422);
        }

        if (!$soldByWeight && (!quantity_is_whole($stockQty) || !quantity_is_whole($minStock))) {
            json_response(['error' => 'Produk satuan harus memakai stok dan min stok bilangan bulat. Pilih Volume/Berat untuk qty desimal.'], 422);
        }

        $skuCheck = $pdo->prepare("
            SELECT id
            FROM products
            WHERE outlet_id = :outlet_id
              AND sku = :sku
              AND id <> :id
            LIMIT 1
        ");
        $skuCheck->execute([
            ':outlet_id' => $outletId,
            ':sku' => $sku,
            ':id' => $productId,
        ]);
        if ($skuCheck->fetch()) {
            json_response(['error' => 'SKU sudah digunakan oleh produk lain di outlet ini.'], 409);
        }

        if ($barcode !== '') {
            $barcodeCheck = $pdo->prepare("
                SELECT id
                FROM products
                WHERE outlet_id = :outlet_id
                  AND barcode = :barcode
                  AND id <> :id
                LIMIT 1
            ");
            $barcodeCheck->execute([
                ':outlet_id' => $outletId,
                ':barcode' => $barcode,
                ':id' => $productId,
            ]);
            if ($barcodeCheck->fetch()) {
                json_response(['error' => 'Barcode sudah digunakan oleh produk lain di outlet ini.'], 409);
            }
        }

        $components = [];
        if ($isComposite) {
            foreach (($data['components'] ?? []) as $component) {
                $componentId = (int) ($component['product_id'] ?? 0);
                $componentQty = (float) ($component['quantity'] ?? 0);
                if ($componentId <= 0 || $componentQty <= 0) {
                    continue;
                }
                if ($componentId === $productId) {
                    json_response(['error' => 'Produk tidak dapat menjadi komponen untuk dirinya sendiri.'], 422);
                }
                $components[$componentId] = ($components[$componentId] ?? 0) + $componentQty;
            }

            if (!$components) {
                json_response(['error' => 'Produk composite wajib memiliki minimal satu komponen.'], 422);
            }

            $usedAsComponent = $pdo->prepare("
                SELECT pc.product_id, p.name
                FROM product_components pc
                JOIN products p ON p.id = pc.product_id
                WHERE pc.outlet_id = :outlet_id
                  AND pc.component_product_id = :product_id
                  AND pc.product_id <> :product_id
                LIMIT 1
            ");
            $usedAsComponent->execute([
                ':outlet_id' => $outletId,
                ':product_id' => $productId,
            ]);
            $parentProduct = $usedAsComponent->fetch();
            if ($parentProduct) {
                json_response([
                    'error' => 'Produk ini masih dipakai sebagai komponen ' . $parentProduct['name'] . ', sehingga belum dapat diubah menjadi composite.',
                ], 422);
            }

            $placeholders = implode(',', array_fill(0, count($components), '?'));
            $componentCheck = $pdo->prepare("
                SELECT id, name, cost_price, is_composite
                FROM products
                WHERE outlet_id = ?
                  AND is_active = 1
                  AND id IN ({$placeholders})
            ");
            $componentCheck->execute([$outletId, ...array_keys($components)]);
            $componentProducts = $componentCheck->fetchAll();

            if (count($componentProducts) !== count($components)) {
                json_response(['error' => 'Ada komponen yang tidak ditemukan pada outlet ini.'], 422);
            }

            foreach ($componentProducts as $componentProduct) {
                if ((int) $componentProduct['is_composite'] === 1) {
                    json_response(['error' => 'Composite bertingkat belum didukung. Pilih produk biasa sebagai komponen.'], 422);
                }
                $costPrice += (float) $componentProduct['cost_price'] * $components[(int) $componentProduct['id']];
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE products
                SET sku = :sku,
                    barcode = :barcode,
                    name = :name,
                    category = :category,
                    stock_qty = :stock_qty,
                    min_stock = :min_stock,
                    cost_price = :cost_price,
                    sale_price = :sale_price,
                    sold_by_weight = :sold_by_weight,
                    is_composite = :is_composite,
                    loyverse_synced_at = NULL
                WHERE id = :id
                  AND outlet_id = :outlet_id
            ");
            $stmt->execute([
                ':sku' => $sku,
                ':barcode' => $barcode !== '' ? $barcode : null,
                ':name' => $name,
                ':category' => $category,
                ':stock_qty' => $stockQty,
                ':min_stock' => $minStock,
                ':cost_price' => $costPrice,
                ':sale_price' => $salePrice,
                ':sold_by_weight' => $soldByWeight,
                ':is_composite' => $isComposite ? 1 : 0,
                ':id' => $productId,
                ':outlet_id' => $outletId,
            ]);

            $componentDelete = $pdo->prepare("
                DELETE FROM product_components
                WHERE product_id = :product_id
                  AND outlet_id = :outlet_id
            ");
            $componentDelete->execute([
                ':product_id' => $productId,
                ':outlet_id' => $outletId,
            ]);

            if ($isComposite) {
                $componentInsert = $pdo->prepare("
                    INSERT INTO product_components (outlet_id, product_id, component_product_id, quantity)
                    VALUES (:outlet_id, :product_id, :component_product_id, :quantity)
                ");
                foreach ($components as $componentId => $componentQty) {
                    $componentInsert->execute([
                        ':outlet_id' => $outletId,
                        ':product_id' => $productId,
                        ':component_product_id' => $componentId,
                        ':quantity' => $componentQty,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            json_response(['error' => $error->getMessage()], 422);
        }

        audit_log($pdo, $outletId, $userId, 'product_update', 'product', $productId, 'Produk diperbarui.', [
            'sku' => $sku,
            'barcode' => $barcode !== '' ? $barcode : null,
            'name' => $name,
            'stock_qty' => $stockQty,
            'sale_price' => $salePrice,
            'sold_by_weight' => $soldByWeight,
            'is_composite' => $isComposite,
            'component_count' => count($components),
        ]);

        json_response([
            'message' => 'Produk ' . $name . ' berhasil diperbarui.',
            'id' => $productId,
        ]);
    }

    $barcode = trim((string) ($data['barcode'] ?? ''));

    if (strlen($barcode) > 80) {
        json_response(['error' => 'Barcode maksimal 80 karakter.'], 422);
    }

    if ($barcode !== '') {
        $barcodeCheck = $pdo->prepare("
            SELECT id
            FROM products
            WHERE outlet_id = :outlet_id
              AND barcode = :barcode
              AND id <> :id
            LIMIT 1
        ");
        $barcodeCheck->execute([
            ':outlet_id' => $outletId,
            ':barcode' => $barcode,
            ':id' => $productId,
        ]);
        if ($barcodeCheck->fetch()) {
            json_response(['error' => 'Barcode sudah digunakan oleh produk lain di outlet ini.'], 409);
        }
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET barcode = :barcode,
            loyverse_synced_at = NULL
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");
    $stmt->execute([
        ':barcode' => $barcode !== '' ? $barcode : null,
        ':id' => $productId,
        ':outlet_id' => $outletId,
    ]);

    audit_log($pdo, $outletId, $userId, 'product_barcode_update', 'product', $productId, 'Barcode produk diperbarui.', [
        'barcode' => $barcode !== '' ? $barcode : null,
    ]);

    json_response([
        'message' => 'Barcode ' . $product['name'] . ' berhasil disimpan.',
        'barcode' => $barcode !== '' ? $barcode : null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = [];

    if (!empty($input['id'])) {
        $ids = [(int) $input['id']];
    } elseif (!empty($input['ids']) && is_array($input['ids'])) {
        $ids = array_map('intval', $input['ids']);
    }

    if (empty($ids)) {
        json_response(['error' => 'ID produk harus diisi.'], 400);
    }

    try {
        $pdo->beginTransaction();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Cek apakah produk ada yang merupakan bagian dari composite product lain
        $checkComponents = $pdo->prepare("
            SELECT p.name as parent_name, c.name as component_name
            FROM product_components pc
            JOIN products p ON p.id = pc.product_id
            JOIN products c ON c.id = pc.component_product_id
            WHERE pc.outlet_id = ? AND pc.component_product_id IN ($placeholders)
            LIMIT 1
        ");
        $checkParams = array_merge([$outletId], $ids);
        $checkComponents->execute($checkParams);
        
        if ($row = $checkComponents->fetch()) {
            $pdo->rollBack();
            json_response(['error' => "Produk {$row['component_name']} tidak bisa dihapus karena digunakan sebagai bahan baku pada produk {$row['parent_name']}."], 409);
        }

        // Hapus komponen yang dimiliki produk ini (jika produk ini adalah produk composite)
        $deleteComponents = $pdo->prepare("DELETE FROM product_components WHERE outlet_id = ? AND product_id IN ($placeholders)");
        $deleteComponents->execute(array_merge([$outletId], $ids));

        // Soft delete produk
        $deleteProducts = $pdo->prepare("UPDATE products SET is_active = 0, barcode = NULL WHERE outlet_id = ? AND id IN ($placeholders)");
        $deleteProducts->execute(array_merge([$outletId], $ids));
        
        $deletedCount = $deleteProducts->rowCount();

        foreach ($ids as $deletedId) {
            audit_log($pdo, $outletId, $userId, 'product_delete', 'product', $deletedId, 'Produk dihapus (soft delete).');
        }

        $pdo->commit();

        json_response([
            'message' => $deletedCount > 1 
                ? "$deletedCount produk berhasil dihapus." 
                : "Produk berhasil dihapus.",
            'deleted' => $deletedCount
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting products: " . $e->getMessage());
        json_response(['error' => 'Terjadi kesalahan sistem saat menghapus produk.'], 500);
    }
}

json_response(['error' => 'Method tidak didukung.'], 405);
