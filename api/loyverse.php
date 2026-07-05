<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const LOYVERSE_SUCCESS_PATH = '/dashboard/integrations.html?loyverse=connected';
const LOYVERSE_ERROR_PATH = '/dashboard/integrations.html?loyverse=error';

function loyverse_json_request(string $url, array $payload): array
{
    $body = http_build_query($payload);

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException($error ?: 'Gagal menghubungi Loyverse.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = (int) ($matches[1] ?? 500);

        if ($response === false) {
            throw new RuntimeException('Gagal menghubungi Loyverse.');
        }
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Response Loyverse tidak valid.');
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException($decoded['error_description'] ?? $decoded['error'] ?? 'Loyverse menolak request token.');
    }

    return $decoded;
}

function loyverse_api_request(array $integration, string $method, string $path, array $query = [], ?array $payload = null): array
{
    $config = loyverse_config();
    $url = rtrim($config['api_base'], '/') . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = [
        'Authorization: ' . ($integration['token_type'] ?: 'Bearer') . ' ' . $integration['access_token'],
        'Content-Type: application/json',
    ];
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException($error ?: 'Gagal menghubungi API Loyverse.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = (int) ($matches[1] ?? 500);

        if ($response === false) {
            throw new RuntimeException('Gagal menghubungi API Loyverse.');
        }
    }

    $decoded = json_decode((string) $response, true);
    if ($decoded === null && trim((string) $response) !== '') {
        throw new RuntimeException('Response API Loyverse tidak valid.');
    }

    if ($status < 200 || $status >= 300) {
        $message = loyverse_error_message(is_array($decoded) ? $decoded : [], $status);
        throw new RuntimeException($message);
    }

    return is_array($decoded) ? $decoded : [];
}

function loyverse_error_message(array $decoded, int $status): string
{
    $parts = [];

    foreach (['error_description', 'error', 'message', 'detail', 'title'] as $key) {
        if (!empty($decoded[$key])) {
            $parts[] = is_scalar($decoded[$key])
                ? (string) $decoded[$key]
                : json_encode($decoded[$key], JSON_UNESCAPED_SLASHES);
        }
    }

    foreach (['errors', 'details'] as $listKey) {
        if (!isset($decoded[$listKey]) || !is_array($decoded[$listKey])) {
            continue;
        }

        foreach ($decoded[$listKey] as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $field = $item['field'] ?? $item['path'] ?? $item['property'] ?? null;
            $message = $item['message'] ?? $item['detail'] ?? $item['error'] ?? $item['description'] ?? null;

            if ($message) {
                $parts[] = ($field ? "{$field}: " : '') . $message;
            } else {
                $parts[] = json_encode($item, JSON_UNESCAPED_SLASHES);
            }
        }
    }

    if (!$parts && $decoded) {
        $parts[] = json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }

    return $parts
        ? 'HTTP ' . $status . ' - ' . implode(' | ', array_unique($parts))
        : 'HTTP ' . $status . ' - Request Loyverse gagal.';
}

function loyverse_get_integration(PDO $pdo, int $outletId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM loyverse_integrations
        WHERE outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $integration = $stmt->fetch();

    if (!$integration) {
        json_response(['error' => 'Outlet belum terhubung ke Loyverse.'], 409);
    }

    return $integration;
}

function loyverse_fresh_integration(PDO $pdo, array $integration): array
{
    $expiresAt = strtotime((string) ($integration['expires_at'] ?? ''));
    if (!$expiresAt || $expiresAt > time() + 60 || empty($integration['refresh_token'])) {
        return $integration;
    }

    $config = loyverse_config();
    $token = loyverse_json_request($config['token_url'], [
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $integration['refresh_token'],
        'grant_type' => 'refresh_token',
    ]);

    $expiresAt = isset($token['expires_in'])
        ? date('Y-m-d H:i:s', time() + (int) $token['expires_in'])
        : $integration['expires_at'];

    $stmt = $pdo->prepare("
        UPDATE loyverse_integrations
        SET access_token = :access_token,
            refresh_token = :refresh_token,
            token_type = :token_type,
            scope = :scope,
            expires_at = :expires_at
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $integration['id'],
        ':access_token' => $token['access_token'],
        ':refresh_token' => $token['refresh_token'] ?? $integration['refresh_token'],
        ':token_type' => $token['token_type'] ?? 'Bearer',
        ':scope' => $token['scope'] ?? $integration['scope'],
        ':expires_at' => $expiresAt,
    ]);

    return loyverse_get_integration($pdo, (int) $integration['outlet_id']);
}

function loyverse_require_scope(array $integration, string $scope): void
{
    $scopes = preg_split('/\s+/', trim((string) ($integration['scope'] ?? ''))) ?: [];

    if (!in_array($scope, $scopes, true)) {
        json_response([
            'error' => "Token Loyverse belum punya izin {$scope}. Putus integrasi lalu connect ulang Loyverse.",
            'required_scope' => $scope,
        ], 409);
    }
}

function loyverse_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int) ($stmt->fetch()['total'] ?? 0) > 0;
}

function loyverse_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => $table]);
    return (int) ($stmt->fetch()['total'] ?? 0) > 0;
}

function loyverse_require_sync_schema(PDO $pdo): void
{
    $required = [
        ['loyverse_integrations', 'last_product_sync_at'],
        ['loyverse_integrations', 'last_receipt_sync_at'],
        ['products', 'loyverse_item_id'],
        ['products', 'loyverse_variant_id'],
        ['products', 'loyverse_synced_at'],
        ['products', 'sold_by_weight'],
        ['products', 'is_composite'],
        ['products', 'barcode'],
        ['transactions', 'source'],
        ['transactions', 'loyverse_receipt_number'],
        ['transactions', 'stock_applied_at'],
        ['transactions', 'discount_amount'],
        ['transaction_items', 'discount_amount'],
        ['outlets', 'loyverse_tax_id'],
        ['outlets', 'loyverse_tax_synced_at'],
    ];

    foreach ($required as [$table, $column]) {
        if (!loyverse_column_exists($pdo, $table, $column)) {
            if ($column === 'discount_amount') {
                $migration = 'database/migrations/2026_06_11_transaction_discounts.sql';
            } elseif (str_starts_with($column, 'loyverse_tax_')) {
                $migration = 'database/migrations/2026_06_05_loyverse_tax_sync.sql';
            } elseif ($column === 'barcode') {
                $migration = 'database/migrations/2026_06_10_product_barcode.sql';
            } elseif ($column === 'is_composite') {
                $migration = 'database/migrations/2026_06_10_composite_products.sql';
            } elseif ($column === 'sold_by_weight') {
                $migration = 'database/migrations/2026_06_08_product_volume_mode.sql';
            } elseif ($column === 'stock_applied_at') {
                $migration = 'database/migrations/2026_06_05_loyverse_stock_sync.sql';
            } else {
                $migration = 'database/migrations/2026_06_05_loyverse_sync.sql';
            }

            json_response([
                'error' => 'Migration Loyverse sync belum dijalankan. Jalankan ' . $migration . ' di database aaPanel.',
                'missing' => "{$table}.{$column}",
            ], 409);
        }
    }

    if (!loyverse_table_exists($pdo, 'product_components')) {
        json_response([
            'error' => 'Migration composite product belum dijalankan. Jalankan database/migrations/2026_06_10_composite_products.sql di database aaPanel.',
            'missing' => 'product_components',
        ], 409);
    }
}

function loyverse_store_mapping_schema_ready(PDO $pdo): bool
{
    return loyverse_column_exists($pdo, 'outlets', 'loyverse_store_id')
        && loyverse_column_exists($pdo, 'outlets', 'loyverse_store_name');
}

function loyverse_require_store_mapping_schema(PDO $pdo): void
{
    if (!loyverse_store_mapping_schema_ready($pdo)) {
        json_response([
            'error' => 'Migration pemilihan POS Loyverse belum dijalankan. Jalankan database/migrations/2026_06_12_loyverse_store_mapping.sql di database aaPanel.',
        ], 409);
    }
}

function loyverse_public_stores(array $integration): array
{
    loyverse_require_scope($integration, 'STORES_READ');
    $response = loyverse_api_request($integration, 'GET', '/stores', ['limit' => 250]);
    $stores = $response['stores'] ?? [];

    if (!is_array($stores)) {
        throw new RuntimeException('Response daftar POS Loyverse tidak valid.');
    }

    $result = [];
    foreach ($stores as $store) {
        if (!is_array($store) || empty($store['id'])) {
            continue;
        }

        $address = $store['address'] ?? '';
        if (!is_scalar($address)) {
            $address = '';
        }

        $result[] = [
            'id' => (string) $store['id'],
            'name' => trim((string) ($store['name'] ?? 'POS tanpa nama')),
            'address' => trim((string) $address),
            'active' => !array_key_exists('active', $store) || (bool) $store['active'],
        ];
    }

    usort($result, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
    return $result;
}

function decode_jwt_payload(?string $jwt): array
{
    if (!$jwt || substr_count($jwt, '.') < 2) {
        return [];
    }

    [, $payload] = explode('.', $jwt, 3);
    $payload = strtr($payload, '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $decoded = json_decode((string) base64_decode($payload), true);

    return is_array($decoded) ? $decoded : [];
}

function redirect_to_dashboard(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function loyverse_item_payload(array $product, ?string $storeId = null, ?string $taxId = null): array
{
    $sku = trim((string) $product['sku']);
    $barcode = trim((string) ($product['barcode'] ?? ''));
    $name = trim((string) $product['name']);
    $description = trim((string) ($product['category'] ?? ''));

    if ($sku === '' || strlen($sku) > 40) {
        throw new RuntimeException('SKU ' . ($product['sku'] ?? '-') . ' wajib 1-40 karakter untuk Loyverse.');
    }

    if ($name === '' || strlen($name) > 64) {
        throw new RuntimeException('Nama produk ' . ($product['name'] ?? '-') . ' wajib 1-64 karakter untuk Loyverse.');
    }

    if (strlen($description) > 512) {
        throw new RuntimeException('Kategori/deskripsi produk ' . $name . ' maksimal 512 karakter untuk Loyverse.');
    }

    $isComposite = (int) ($product['is_composite'] ?? 0) === 1;
    $variant = [
        'sku' => $sku,
        'reference_variant_id' => 'akurata-product-' . $product['id'],
        'default_pricing_type' => 'FIXED',
        'default_price' => (float) $product['sale_price'],
    ];

    if ($barcode !== '') {
        $variant['barcode'] = $barcode;
    }

    if (!$isComposite) {
        $variant['cost'] = (float) $product['cost_price'];
        $variant['purchase_cost'] = (float) $product['cost_price'];
    }

    if (!empty($product['loyverse_variant_id'])) {
        $variant['variant_id'] = $product['loyverse_variant_id'];
    }

    if ($storeId !== null && $storeId !== '') {
        $store = [
            'store_id' => $storeId,
            'pricing_type' => 'FIXED',
            'price' => (float) $product['sale_price'],
            'available_for_sale' => true,
        ];
        if (!$isComposite) {
            $store['optimal_stock'] = max((float) ($product['stock_qty'] ?? 0), (float) ($product['min_stock'] ?? 0));
            $store['low_stock'] = (float) ($product['min_stock'] ?? 0);
        }
        $variant['stores'] = [$store];
    }

    $payload = [
        'item_name' => $name,
        'description' => $description,
        'reference_id' => 'akurata-product-' . $product['id'],
        'track_stock' => !$isComposite,
        'sold_by_weight' => !$isComposite && (int) ($product['sold_by_weight'] ?? 0) === 1,
        'is_composite' => $isComposite,
        'variants' => [$variant],
    ];

    if ($isComposite) {
        $payload['components'] = array_map(fn ($component) => [
            'variant_id' => (string) $component['loyverse_variant_id'],
            'quantity' => (float) $component['quantity'],
        ], $product['components'] ?? []);
    }

    if ($taxId !== null && $taxId !== '') {
        $payload['tax_ids'] = [$taxId];
    }

    if (!empty($product['loyverse_item_id'])) {
        $payload['id'] = $product['loyverse_item_id'];
    }

    return $payload;
}

function first_loyverse_variant(array $item): array
{
    $variants = $item['variants'] ?? [];
    return is_array($variants) && isset($variants[0]) && is_array($variants[0]) ? $variants[0] : [];
}

function loyverse_find_variant_by_sku(array $integration, string $sku): ?array
{
    $cursor = null;

    do {
        $query = ['limit' => 250];
        if ($cursor) {
            $query['cursor'] = $cursor;
        }

        $response = loyverse_api_request($integration, 'GET', '/variants', $query);
        $variants = $response['variants'] ?? [];

        if (is_array($variants)) {
            foreach ($variants as $variant) {
                if (!is_array($variant) || !empty($variant['deleted_at'])) {
                    continue;
                }

                if ((string) ($variant['sku'] ?? '') === $sku) {
                    return $variant;
                }
            }
        }

        $cursor = $response['cursor'] ?? null;
    } while ($cursor);

    return null;
}

function save_loyverse_product_mapping(PDO $pdo, int $outletId, array $product, ?string $itemId, ?string $variantId): void
{
    $update = $pdo->prepare("
        UPDATE products
        SET loyverse_item_id = :item_id,
            loyverse_variant_id = :variant_id,
            loyverse_synced_at = NOW()
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");
    $update->execute([
        ':id' => $product['id'],
        ':outlet_id' => $outletId,
        ':item_id' => $itemId,
        ':variant_id' => $variantId,
    ]);
}

function loyverse_composite_components(PDO $pdo, int $outletId, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT pc.quantity, p.name, p.loyverse_variant_id
        FROM product_components pc
        JOIN products p ON p.id = pc.component_product_id
        WHERE pc.product_id = :product_id
          AND pc.outlet_id = :outlet_id
        ORDER BY pc.id ASC
    ");
    $stmt->execute([
        ':product_id' => $productId,
        ':outlet_id' => $outletId,
    ]);
    $components = $stmt->fetchAll();

    if (!$components) {
        throw new RuntimeException('Produk composite belum memiliki komponen.');
    }
    foreach ($components as $component) {
        if (empty($component['loyverse_variant_id'])) {
            throw new RuntimeException('Komponen ' . $component['name'] . ' belum dipush ke Loyverse.');
        }
    }
    return $components;
}

function loyverse_push_product(
    PDO $pdo,
    array $integration,
    int $outletId,
    array $product,
    array $context = []
): array
{
    loyverse_shared_require_scopes($integration, ['STORES_READ', 'INVENTORY_WRITE']);
    $storeId = (string) ($context['store_id'] ?? loyverse_shared_default_store_id($pdo, $integration));
    $taxId = array_key_exists('tax_id', $context)
        ? $context['tax_id']
        : loyverse_outlet_tax_id($pdo, $outletId, $integration);

    if ((int) ($product['is_composite'] ?? 0) === 1) {
        $product['components'] = loyverse_composite_components($pdo, $outletId, (int) $product['id']);
    }

    try {
        $response = loyverse_api_request($integration, 'POST', '/items', [], loyverse_item_payload($product, $storeId, $taxId));
        $variant = first_loyverse_variant($response);
        $itemId = $response['id'] ?? $product['loyverse_item_id'] ?? null;
        $variantId = $variant['variant_id'] ?? $product['loyverse_variant_id'] ?? null;

        save_loyverse_product_mapping($pdo, $outletId, $product, $itemId, $variantId);
        if ((int) ($product['is_composite'] ?? 0) !== 1) {
            loyverse_sync_product_stock($pdo, $outletId, (int) $product['id'], $integration, $storeId);
        }

        return [
            'synced' => true,
            'item_id' => $itemId,
            'variant_id' => $variantId,
            'matched_existing' => false,
        ];
    } catch (Throwable $error) {
        if ((int) ($product['is_composite'] ?? 0) === 1) {
            throw $error;
        }

        $variant = loyverse_find_variant_by_sku($integration, (string) $product['sku']);

        if ($variant) {
            $itemId = $variant['item_id'] ?? null;
            $variantId = $variant['variant_id'] ?? null;
            save_loyverse_product_mapping($pdo, $outletId, $product, $itemId, $variantId);
            if ((int) ($product['is_composite'] ?? 0) !== 1) {
                loyverse_sync_product_stock($pdo, $outletId, (int) $product['id'], $integration, $storeId);
            }

            return [
                'synced' => true,
                'item_id' => $itemId,
                'variant_id' => $variantId,
                'matched_existing' => true,
            ];
        }

        throw $error;
    }
}

function loyverse_outlet_tax_is_enabled(PDO $pdo, int $outletId): bool
{
    $stmt = $pdo->prepare("
        SELECT tax_enabled, tax_rate
        FROM outlets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $outletId]);
    $outlet = $stmt->fetch() ?: [];

    return (int) ($outlet['tax_enabled'] ?? 0) === 1 && (float) ($outlet['tax_rate'] ?? 0) > 0;
}

function loyverse_outlet_tax_id(PDO $pdo, int $outletId, array $integration): ?string
{
    $stmt = $pdo->prepare("
        SELECT tax_enabled, tax_rate, loyverse_tax_id
        FROM outlets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $outletId]);
    $outlet = $stmt->fetch() ?: [];

    if ((int) ($outlet['tax_enabled'] ?? 0) !== 1 || (float) ($outlet['tax_rate'] ?? 0) <= 0) {
        return null;
    }

    $taxId = trim((string) ($outlet['loyverse_tax_id'] ?? ''));
    return $taxId !== '' ? $taxId : loyverse_sync_outlet_tax($pdo, $outletId, $integration);
}

function mysql_datetime_from_iso(?string $value): string
{
    $timestamp = $value ? strtotime($value) : false;
    return date('Y-m-d H:i:s', $timestamp ?: time());
}

function iso_from_mysql(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? gmdate('Y-m-d\TH:i:s.000\Z', $timestamp - 300) : null;
}

function loyverse_invoice_no(PDO $pdo, int $outletId, string $receiptNumber): string
{
    $base = 'LOY-' . preg_replace('/[^A-Za-z0-9-]/', '-', $receiptNumber);
    $base = limited_text($base, 34);
    $invoice = $base;
    $counter = 1;

    while (true) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM transactions
            WHERE outlet_id = :outlet_id
              AND invoice_no = :invoice_no
            LIMIT 1
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':invoice_no' => $invoice,
        ]);

        if (!$stmt->fetch()) {
            return $invoice;
        }

        $invoice = limited_text($base, 34) . '-' . $counter;
        $counter++;
    }
}

function loyverse_payment_method(array $receipt): string
{
    $payments = $receipt['payments'] ?? [];
    $payment = is_array($payments) && isset($payments[0]) && is_array($payments[0]) ? $payments[0] : [];
    $text = strtolower((string) ($payment['name'] ?? $payment['payment_name'] ?? $payment['type'] ?? $payment['payment_type'] ?? ''));

    if (str_contains($text, 'qris')) {
        return 'qris';
    }

    if (str_contains($text, 'transfer') || str_contains($text, 'card') || str_contains($text, 'debit') || str_contains($text, 'credit')) {
        return 'transfer';
    }

    return 'cash';
}

function loyverse_line_number(array $line, array $keys, float $default = 0): float
{
    foreach ($keys as $key) {
        if (isset($line[$key]) && is_numeric($line[$key])) {
            return (float) $line[$key];
        }
    }

    return $default;
}

function loyverse_tax_lines_amount(array $taxes): float
{
    $amount = 0.0;
    foreach ($taxes as $tax) {
        if (!is_array($tax)) {
            continue;
        }

        $amount += loyverse_line_number($tax, ['money_amount', 'amount'], 0);
    }

    return $amount;
}

function loyverse_line_tax_amount(array $line): float
{
    $taxes = $line['line_taxes'] ?? [];
    return is_array($taxes) ? loyverse_tax_lines_amount($taxes) : 0.0;
}

function loyverse_discount_lines_amount(array $discounts): float
{
    $amount = 0.0;
    foreach ($discounts as $discount) {
        if (!is_array($discount)) {
            continue;
        }
        $amount += loyverse_line_number($discount, ['money_amount', 'amount', 'discount_amount'], 0);
    }

    return $amount;
}

function loyverse_line_discount_amount(array $line): float
{
    $amount = loyverse_line_number($line, ['total_discount', 'discount_amount'], 0);
    foreach (['line_discounts', 'discounts'] as $key) {
        $discounts = $line[$key] ?? [];
        if (is_array($discounts)) {
            $amount = max($amount, loyverse_discount_lines_amount($discounts));
        }
    }

    return max(0, $amount);
}

function loyverse_receipt_discount_amount(array $receipt): float
{
    $amount = loyverse_line_number($receipt, ['total_discount', 'discount_amount'], 0);
    foreach (['total_discounts', 'discounts'] as $key) {
        $discounts = $receipt[$key] ?? [];
        if (is_array($discounts)) {
            $amount = max($amount, loyverse_discount_lines_amount($discounts));
        }
    }

    return max(0, $amount);
}

function loyverse_receipt_tax_amount(array $receipt): float
{
    $taxAmount = loyverse_line_number($receipt, ['total_tax', 'tax_amount'], 0);
    if ($taxAmount > 0) {
        return $taxAmount;
    }

    $totalTaxes = $receipt['total_taxes'] ?? [];
    if (is_array($totalTaxes)) {
        $taxAmount = loyverse_tax_lines_amount($totalTaxes);
        if ($taxAmount > 0) {
            return $taxAmount;
        }
    }

    $lineItems = $receipt['line_items'] ?? [];
    if (!is_array($lineItems)) {
        return 0.0;
    }

    foreach ($lineItems as $line) {
        if (is_array($line)) {
            $taxAmount += loyverse_line_tax_amount($line);
        }
    }

    return $taxAmount;
}

function loyverse_product_for_line(PDO $pdo, int $outletId, array $line): array
{
    $variantId = (string) ($line['variant_id'] ?? '');
    if ($variantId !== '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM products
            WHERE outlet_id = :outlet_id
              AND loyverse_variant_id = :variant_id
            LIMIT 1
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':variant_id' => $variantId,
        ]);
        $product = $stmt->fetch();
        if ($product) {
            return $product;
        }
    }

    $sku = trim((string) ($line['sku'] ?? ''));
    if ($sku === '') {
        $sku = 'LOY-' . substr(sha1($variantId . ($line['item_name'] ?? 'produk-loyverse')), 0, 12);
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM products
        WHERE outlet_id = :outlet_id
          AND sku = :sku
        LIMIT 1
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':sku' => limited_text($sku, 60),
    ]);
    $product = $stmt->fetch();
    if ($product) {
        return $product;
    }

    $qty = max(1.0, loyverse_line_number($line, ['quantity'], 1));
    $soldByWeight = quantity_is_whole($qty) ? 0 : 1;
    $price = loyverse_line_number($line, ['price', 'unit_price'], 0);
    if ($price <= 0) {
        $price = loyverse_line_number($line, ['total_money', 'gross_total_money', 'net_total_money'], 0) / $qty;
    }

    $stmt = $pdo->prepare("
        INSERT INTO products (
          outlet_id, sku, name, category, stock_qty, min_stock, cost_price, sale_price, sold_by_weight,
          loyverse_item_id, loyverse_variant_id, loyverse_synced_at
        )
        VALUES (
          :outlet_id, :sku, :name, 'Loyverse', 0, 0, :cost_price, :sale_price, :sold_by_weight,
          :loyverse_item_id, :loyverse_variant_id, NOW()
        )
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':sku' => limited_text($sku, 60),
        ':name' => limited_text((string) ($line['item_name'] ?? $line['name'] ?? 'Produk Loyverse'), 160),
        ':cost_price' => loyverse_line_number($line, ['cost'], 0),
        ':sale_price' => $price,
        ':sold_by_weight' => $soldByWeight,
        ':loyverse_item_id' => $line['item_id'] ?? null,
        ':loyverse_variant_id' => $variantId !== '' ? $variantId : null,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute([':id' => (int) $pdo->lastInsertId()]);
    return $stmt->fetch();
}

function import_loyverse_receipt(PDO $pdo, int $outletId, int $userId, array $receipt): bool
{
    $receiptNumber = (string) ($receipt['receipt_number'] ?? '');
    if ($receiptNumber === '' || ($receipt['receipt_type'] ?? 'SALE') !== 'SALE' || !empty($receipt['cancelled_at'])) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM transactions
        WHERE outlet_id = :outlet_id
          AND loyverse_receipt_number = :receipt_number
        LIMIT 1
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':receipt_number' => $receiptNumber,
    ]);
    if ($stmt->fetch()) {
        return false;
    }

    $lines = $receipt['line_items'] ?? [];
    if (!is_array($lines) || count($lines) === 0) {
        return false;
    }

    $prepared = [];
    $total = 0.0;
    $cost = 0.0;
    $stockDeductions = [];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $product = loyverse_product_for_line($pdo, $outletId, $line);
        $qtyRaw = max(0.001, loyverse_line_number($line, ['quantity'], 1));
        $qty = $qtyRaw;
        if (!quantity_is_whole($qtyRaw) && (int) ($product['sold_by_weight'] ?? 0) !== 1) {
            $markVolume = $pdo->prepare("
                UPDATE products
                SET sold_by_weight = 1
                WHERE id = :id
                  AND outlet_id = :outlet_id
            ");
            $markVolume->execute([
                ':id' => (int) $product['id'],
                ':outlet_id' => $outletId,
            ]);
            $product['sold_by_weight'] = 1;
        }
        $unitPrice = loyverse_line_number($line, ['price', 'unit_price'], 0);
        $lineTotal = loyverse_line_number($line, ['total_money', 'net_total_money', 'gross_total_money'], 0);
        $lineTax = loyverse_line_tax_amount($line);
        $discountAmount = loyverse_line_discount_amount($line);

        if ($unitPrice <= 0 && $lineTotal > 0) {
            $unitPrice = max(0, $lineTotal - $lineTax + $discountAmount) / $qtyRaw;
        }
        $grossSubtotal = $unitPrice * $qtyRaw;
        $subtotal = $lineTotal > 0 ? max(0, $lineTotal - $lineTax) : max(0, $grossSubtotal - $discountAmount);
        if ($discountAmount <= 0 && $grossSubtotal > $subtotal) {
            $discountAmount = $grossSubtotal - $subtotal;
        }
        if ($subtotal <= 0 && $grossSubtotal > 0) {
            $subtotal = max(0, $grossSubtotal - $discountAmount);
        }
        $discountRate = $grossSubtotal > 0 ? min(100, round($discountAmount / $grossSubtotal * 100, 2)) : 0;

        $unitCost = loyverse_line_number($line, ['cost'], (float) $product['cost_price']);
        if ((int) ($product['is_composite'] ?? 0) === 1) {
            $componentStmt = $pdo->prepare("
                SELECT pc.component_product_id, pc.quantity, p.cost_price
                FROM product_components pc
                JOIN products p ON p.id = pc.component_product_id
                WHERE pc.product_id = :product_id
                  AND pc.outlet_id = :outlet_id
            ");
            $componentStmt->execute([
                ':product_id' => (int) $product['id'],
                ':outlet_id' => $outletId,
            ]);
            $components = $componentStmt->fetchAll();
            if (!$components) {
                throw new RuntimeException('Komponen produk composite ' . $product['name'] . ' belum tersedia.');
            }
            $unitCost = 0.0;
            foreach ($components as $component) {
                $componentId = (int) $component['component_product_id'];
                $componentQty = (float) $component['quantity'];
                $unitCost += (float) $component['cost_price'] * $componentQty;
                $stockDeductions[$componentId] = ($stockDeductions[$componentId] ?? 0) + ($componentQty * $qtyRaw);
            }
        } else {
            $stockDeductions[(int) $product['id']] = ($stockDeductions[(int) $product['id']] ?? 0) + $qtyRaw;
        }
        $total += $subtotal;
        $cost += $unitCost * $qtyRaw;

        $prepared[] = [
            'product_id' => (int) $product['id'],
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'gross_subtotal' => $grossSubtotal,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'subtotal' => $subtotal,
        ];
    }

    if (!$prepared) {
        return false;
    }

    $receiptDiscount = loyverse_receipt_discount_amount($receipt);
    $lineDiscount = array_sum(array_column($prepared, 'discount_amount'));
    $extraDiscount = max(0, $receiptDiscount - $lineDiscount);
    $grossPreparedTotal = array_sum(array_column($prepared, 'gross_subtotal'));
    if ($extraDiscount > 0 && $grossPreparedTotal > 0) {
        foreach ($prepared as &$item) {
            $allocated = round($extraDiscount * ($item['gross_subtotal'] / $grossPreparedTotal), 2);
            $item['discount_amount'] = min($item['gross_subtotal'], $item['discount_amount'] + $allocated);
            $item['discount_rate'] = round($item['discount_amount'] / $item['gross_subtotal'] * 100, 2);
            $item['subtotal'] = max(0, $item['gross_subtotal'] - $item['discount_amount']);
        }
        unset($item);
    }
    $total = array_sum(array_column($prepared, 'subtotal'));
    $totalDiscount = array_sum(array_column($prepared, 'discount_amount'));

    $taxAmount = loyverse_receipt_tax_amount($receipt);
    $receiptTotal = loyverse_line_number($receipt, ['total_money', 'net_total_money'], 0);
    if ($receiptTotal <= 0) {
        $receiptTotal = $total + $taxAmount;
    }

    $subtotalAmount = max(0, $receiptTotal - $taxAmount);
    if ($subtotalAmount <= 0 && $total > 0) {
        $subtotalAmount = $total;
        $receiptTotal = $subtotalAmount + $taxAmount;
    }

    $taxRate = $subtotalAmount > 0 ? round($taxAmount / $subtotalAmount * 100, 2) : 0;
    $invoice = loyverse_invoice_no($pdo, $outletId, $receiptNumber);
    $paymentMethod = loyverse_payment_method($receipt);
    $soldAt = mysql_datetime_from_iso($receipt['receipt_date'] ?? $receipt['created_at'] ?? null);

    $stmt = $pdo->prepare("
        INSERT INTO transactions (
          outlet_id, user_id, customer_id, invoice_no, payment_method, payment_status,
          subtotal_amount, discount_amount, tax_rate, tax_amount, total_amount, cost_amount, source, loyverse_receipt_number, sold_at, stock_applied_at
        )
        VALUES (
          :outlet_id, :user_id, NULL, :invoice_no, :payment_method, 'paid',
          :subtotal_amount, :discount_amount, :tax_rate, :tax_amount, :total_amount, :cost_amount, 'loyverse', :receipt_number, :sold_at, NOW()
        )
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':user_id' => $userId,
        ':invoice_no' => $invoice,
        ':payment_method' => $paymentMethod,
        ':subtotal_amount' => $subtotalAmount,
        ':discount_amount' => $totalDiscount,
        ':tax_rate' => $taxRate,
        ':tax_amount' => $taxAmount,
        ':total_amount' => $receiptTotal,
        ':cost_amount' => $cost,
        ':receipt_number' => $receiptNumber,
        ':sold_at' => $soldAt,
    ]);

    $transactionId = (int) $pdo->lastInsertId();

    foreach ($prepared as $item) {
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

    foreach ($stockDeductions as $stockProductId => $stockQty) {
        $stmt = $pdo->prepare("
            UPDATE products
            SET stock_qty = GREATEST(0, stock_qty - :qty)
            WHERE id = :product_id
              AND outlet_id = :outlet_id
        ");
        $stmt->execute([
            ':qty' => $stockQty,
            ':product_id' => $stockProductId,
            ':outlet_id' => $outletId,
        ]);
        notify_low_stock_if_needed($pdo, $outletId, $stockProductId, $stockQty);
    }

    return true;
}

function refresh_loyverse_receipt_tax(PDO $pdo, int $outletId, array $transaction, array $receipt): bool
{
    $taxAmount = loyverse_receipt_tax_amount($receipt);
    if ($taxAmount <= 0) {
        return false;
    }

    $receiptTotal = loyverse_line_number($receipt, ['total_money', 'net_total_money'], (float) $transaction['total_amount']);
    $subtotalAmount = max(0, $receiptTotal - $taxAmount);
    $taxRate = $subtotalAmount > 0 ? round($taxAmount / $subtotalAmount * 100, 2) : 0;
    $transactionId = (int) $transaction['id'];

    $lineItems = $receipt['line_items'] ?? [];
    $stmt = $pdo->prepare("
        SELECT id
        FROM transaction_items
        WHERE transaction_id = :transaction_id
        ORDER BY id ASC
    ");
    $stmt->execute([':transaction_id' => $transactionId]);
    $existingItems = $stmt->fetchAll();

    if (is_array($lineItems) && count($lineItems) === count($existingItems)) {
        foreach ($lineItems as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $qtyRaw = max(1.0, loyverse_line_number($line, ['quantity'], 1));
            $unitPrice = loyverse_line_number($line, ['price', 'unit_price'], 0);
            $lineSubtotal = loyverse_line_number($line, ['total_money', 'gross_total_money', 'net_total_money'], 0);
            $lineTax = loyverse_line_tax_amount($line);

            if ($unitPrice <= 0 && $lineSubtotal > 0) {
                $unitPrice = $lineSubtotal / $qtyRaw;
            }
            if ($lineSubtotal <= 0) {
                $lineSubtotal = $unitPrice * $qtyRaw;
            }
            if ($lineTax > 0) {
                $lineSubtotal = max(0, $lineSubtotal - $lineTax);
                $unitPrice = $lineSubtotal > 0 ? $lineSubtotal / $qtyRaw : $unitPrice;
            }

            $updateItem = $pdo->prepare("
                UPDATE transaction_items
                SET unit_price = :unit_price,
                    subtotal = :subtotal
                WHERE id = :id
            ");
            $updateItem->execute([
                ':id' => (int) $existingItems[$index]['id'],
                ':unit_price' => $unitPrice,
                ':subtotal' => $lineSubtotal,
            ]);
        }
    }

    $stmt = $pdo->prepare("
        UPDATE transactions
        SET subtotal_amount = :subtotal_amount,
            tax_rate = :tax_rate,
            tax_amount = :tax_amount,
            total_amount = :total_amount
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");
    $stmt->execute([
        ':id' => $transactionId,
        ':outlet_id' => $outletId,
        ':subtotal_amount' => $subtotalAmount,
        ':tax_rate' => $taxRate,
        ':tax_amount' => $taxAmount,
        ':total_amount' => $receiptTotal,
    ]);

    return true;
}

$action = $_GET['action'] ?? 'status';
$config = loyverse_config();

if ($action === 'callback') {
    $user = require_auth();
    if (user_role($user) === 'manager') {
        redirect_to_dashboard(LOYVERSE_ERROR_PATH . '&reason=role');
    }
    $outletId = (int) $user['outlet_id'];
    $userId = (int) $user['id'];
    $state = (string) ($_GET['state'] ?? '');
    $code = (string) ($_GET['code'] ?? '');
    $expected = $_SESSION['loyverse_oauth_state'] ?? null;

    if ($state === '' || $code === '' || !$expected || !hash_equals((string) $expected, $state)) {
        redirect_to_dashboard(LOYVERSE_ERROR_PATH . '&reason=state');
    }

    unset($_SESSION['loyverse_oauth_state']);

    try {
        $token = loyverse_json_request($config['token_url'], [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $profile = decode_jwt_payload($token['id_token'] ?? null);
        $expiresAt = isset($token['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int) $token['expires_in'])
            : null;

        $stmt = db()->prepare("
            INSERT INTO loyverse_integrations (
              outlet_id, user_id, merchant_name, merchant_email, access_token,
              refresh_token, token_type, scope, expires_at, connected_at
            )
            VALUES (
              :outlet_id, :user_id, :merchant_name, :merchant_email, :access_token,
              :refresh_token, :token_type, :scope, :expires_at, NOW()
            )
            ON DUPLICATE KEY UPDATE
              user_id = VALUES(user_id),
              merchant_name = VALUES(merchant_name),
              merchant_email = VALUES(merchant_email),
              access_token = VALUES(access_token),
              refresh_token = VALUES(refresh_token),
              token_type = VALUES(token_type),
              scope = VALUES(scope),
              expires_at = VALUES(expires_at),
              connected_at = NOW()
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':user_id' => $userId,
            ':merchant_name' => $profile['name'] ?? null,
            ':merchant_email' => $profile['email'] ?? null,
            ':access_token' => $token['access_token'],
            ':refresh_token' => $token['refresh_token'] ?? null,
            ':token_type' => $token['token_type'] ?? 'Bearer',
            ':scope' => $token['scope'] ?? $config['scope'],
            ':expires_at' => $expiresAt,
        ]);
        audit_log(db(), $outletId, $userId, 'loyverse_connect', 'integration', 'loyverse', 'Integrasi Loyverse dihubungkan.', [
            'merchant_email' => $profile['email'] ?? null,
            'scope' => $token['scope'] ?? $config['scope'],
        ]);

        redirect_to_dashboard(LOYVERSE_SUCCESS_PATH);
    } catch (Throwable $error) {
        redirect_to_dashboard(LOYVERSE_ERROR_PATH . '&reason=token');
    }
}

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

deny_manager_access('Integrasi Loyverse');

if ($action === 'connect') {
    if ($config['client_id'] === '' || $config['client_secret'] === '') {
        json_response(['error' => 'LOYVERSE_CLIENT_ID dan LOYVERSE_CLIENT_SECRET belum diset.'], 500);
    }

    $state = bin2hex(random_bytes(24));
    $_SESSION['loyverse_oauth_state'] = $state;

    $params = http_build_query([
        'client_id' => $config['client_id'],
        'scope' => $config['scope'],
        'response_type' => 'code',
        'redirect_uri' => $config['redirect_uri'],
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);

    header('Location: ' . $config['authorize_url'] . '?' . $params);
    exit;
}

if ($action === 'disconnect') {
    require_method('POST');
    $stmt = $pdo->prepare("DELETE FROM loyverse_integrations WHERE outlet_id = :outlet_id");
    $stmt->execute([':outlet_id' => $outletId]);
    if (loyverse_store_mapping_schema_ready($pdo)) {
        $stmt = $pdo->prepare("
            UPDATE outlets
            SET loyverse_store_id = NULL,
                loyverse_store_name = NULL
            WHERE id = :id
        ");
        $stmt->execute([':id' => $outletId]);
    }
    audit_log($pdo, $outletId, $userId, 'loyverse_disconnect', 'integration', 'loyverse', 'Integrasi Loyverse diputus.');
    json_response(['message' => 'Integrasi Loyverse diputus.']);
}

if ($action === 'select_store') {
    require_method('POST');
    loyverse_require_store_mapping_schema($pdo);
    $integration = loyverse_fresh_integration($pdo, loyverse_get_integration($pdo, $outletId));
    $data = read_json();
    $storeId = trim((string) ($data['store_id'] ?? ''));

    if ($storeId === '') {
        json_response(['error' => 'POS Loyverse wajib dipilih.'], 422);
    }

    $stores = loyverse_public_stores($integration);
    $selectedStore = null;
    foreach ($stores as $store) {
        if (hash_equals($store['id'], $storeId)) {
            $selectedStore = $store;
            break;
        }
    }

    if (!$selectedStore) {
        json_response(['error' => 'POS Loyverse tidak ditemukan pada akun yang terhubung.'], 404);
    }

    $stmt = $pdo->prepare("
        UPDATE outlets
        SET loyverse_store_id = :store_id,
            loyverse_store_name = :store_name
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $outletId,
        ':store_id' => $selectedStore['id'],
        ':store_name' => $selectedStore['name'],
    ]);

    audit_log($pdo, $outletId, $userId, 'loyverse_select_store', 'integration', $selectedStore['id'], 'POS Loyverse aktif dipilih.', [
        'store_name' => $selectedStore['name'],
    ]);

    json_response([
        'message' => 'POS ' . $selectedStore['name'] . ' dipakai untuk sinkronisasi.',
        'selected_store' => $selectedStore,
    ]);
}

if ($action === 'push_products') {
    require_method('POST');
    loyverse_require_sync_schema($pdo);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $integration = loyverse_fresh_integration($pdo, loyverse_get_integration($pdo, $outletId));
    loyverse_require_scope($integration, 'ITEMS_WRITE');
    loyverse_require_scope($integration, 'STORES_READ');
    loyverse_require_scope($integration, 'INVENTORY_WRITE');
    if (loyverse_outlet_tax_is_enabled($pdo, $outletId)) {
        loyverse_require_scope($integration, 'TAXES_WRITE');
    }

    $data = read_json();
    $requestedIds = array_values(array_unique(array_filter(
        array_map('intval', is_array($data['ids'] ?? null) ? $data['ids'] : []),
        static fn (int $id): bool => $id > 0
    )));
    if (!$requestedIds) {
        json_response(['error' => 'Daftar produk batch wajib diisi.'], 422);
    }
    if (count($requestedIds) > 200) {
        json_response(['error' => 'Maksimal 200 produk per batch Loyverse.'], 422);
    }

    $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
               sold_by_weight, is_composite, loyverse_item_id, loyverse_variant_id
        FROM products
        WHERE outlet_id = ?
          AND is_active = 1
          AND id IN ({$placeholders})
        ORDER BY is_composite ASC, name ASC
    ");
    $stmt->execute([$outletId, ...$requestedIds]);
    $products = $stmt->fetchAll();

    if (!$products) {
        json_response([
            'message' => 'Belum ada produk aktif untuk dipush ke Loyverse.',
            'synced' => 0,
            'failed' => 0,
            'errors' => [],
        ]);
    }

    $synced = 0;
    $errors = [];
    $storeId = loyverse_shared_default_store_id($pdo, $integration);
    $taxId = loyverse_outlet_tax_id($pdo, $outletId, $integration);
    $context = [
        'store_id' => $storeId,
        'tax_id' => $taxId,
    ];

    foreach ($products as $product) {
        try {
            loyverse_push_product($pdo, $integration, $outletId, $product, $context);
            $synced++;
        } catch (Throwable $error) {
            $errors[] = [
                'product_id' => (int) $product['id'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'error' => $error->getMessage(),
            ];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE loyverse_integrations
        SET last_product_sync_at = NOW()
        WHERE outlet_id = :outlet_id
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    $firstError = $errors[0]['error'] ?? null;
    audit_log($pdo, $outletId, $userId, 'loyverse_push_products', 'integration', 'loyverse', 'Push produk ke Loyverse dijalankan.', [
        'synced' => $synced,
        'failed' => count($errors),
        'requested' => count($requestedIds),
    ]);

    json_response([
        'message' => $firstError
            ? "{$synced} produk berhasil, " . count($errors) . " gagal. Error pertama: {$firstError}"
            : "{$synced} produk berhasil dipush ke Loyverse.",
        'synced' => $synced,
        'failed' => count($errors),
        'requested' => count($requestedIds),
        'errors' => $errors,
    ], $errors ? 207 : 200);
}

if ($action === 'push_changed') {
    require_method('POST');
    loyverse_require_sync_schema($pdo);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $integration = loyverse_fresh_integration($pdo, loyverse_get_integration($pdo, $outletId));
    loyverse_require_scope($integration, 'ITEMS_WRITE');
    loyverse_require_scope($integration, 'STORES_READ');
    loyverse_require_scope($integration, 'INVENTORY_WRITE');
    if (loyverse_outlet_tax_is_enabled($pdo, $outletId)) {
        loyverse_require_scope($integration, 'TAXES_WRITE');
    }

    $stmt = $pdo->prepare("
        SELECT id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
               sold_by_weight, is_composite, loyverse_item_id, loyverse_variant_id
        FROM products
        WHERE outlet_id = :outlet_id
          AND is_active = 1
          AND loyverse_synced_at IS NULL
        ORDER BY is_composite ASC, name ASC
        LIMIT 200
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $products = $stmt->fetchAll();

    if (!$products) {
        json_response([
            'message' => 'Tidak ada produk yang berubah. Semua produk sudah tersinkron.',
            'synced' => 0,
            'failed' => 0,
            'requested' => 0,
            'errors' => [],
        ]);
    }

    $synced = 0;
    $errors = [];
    $storeId = loyverse_shared_default_store_id($pdo, $integration);
    $taxId = loyverse_outlet_tax_id($pdo, $outletId, $integration);
    $context = [
        'store_id' => $storeId,
        'tax_id' => $taxId,
    ];

    foreach ($products as $product) {
        try {
            loyverse_push_product($pdo, $integration, $outletId, $product, $context);
            $synced++;
        } catch (Throwable $error) {
            $errors[] = [
                'product_id' => (int) $product['id'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'error' => $error->getMessage(),
            ];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE loyverse_integrations
        SET last_product_sync_at = NOW()
        WHERE outlet_id = :outlet_id
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    $firstError = $errors[0]['error'] ?? null;
    audit_log($pdo, $outletId, $userId, 'loyverse_push_changed', 'integration', 'loyverse', 'Push produk berubah ke Loyverse.', [
        'synced' => $synced,
        'failed' => count($errors),
        'requested' => count($products),
    ]);

    json_response([
        'message' => $firstError
            ? "{$synced} produk berhasil, " . count($errors) . " gagal. Error pertama: {$firstError}"
            : "{$synced} produk berubah berhasil dipush ke Loyverse.",
        'synced' => $synced,
        'failed' => count($errors),
        'requested' => count($products),
        'errors' => $errors,
    ], $errors ? 207 : 200);
}

if ($action === 'push_product') {
    require_method('POST');
    loyverse_require_sync_schema($pdo);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $integration = loyverse_fresh_integration($pdo, loyverse_get_integration($pdo, $outletId));
    loyverse_require_scope($integration, 'ITEMS_WRITE');
    loyverse_require_scope($integration, 'STORES_READ');
    loyverse_require_scope($integration, 'INVENTORY_WRITE');
    if (loyverse_outlet_tax_is_enabled($pdo, $outletId)) {
        loyverse_require_scope($integration, 'TAXES_WRITE');
    }

    $data = read_json();
    $productId = (int) ($data['id'] ?? 0);
    if ($productId <= 0) {
        json_response(['error' => 'ID produk wajib valid.'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
               sold_by_weight, is_composite, loyverse_item_id, loyverse_variant_id
        FROM products
        WHERE id = :id
          AND outlet_id = :outlet_id
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $productId,
        ':outlet_id' => $outletId,
    ]);
    $product = $stmt->fetch();

    if (!$product) {
        json_response(['error' => 'Produk tidak ditemukan.'], 404);
    }

    try {
        $result = loyverse_push_product($pdo, $integration, $outletId, $product);

        $stmt = $pdo->prepare("
            UPDATE loyverse_integrations
            SET last_product_sync_at = NOW()
            WHERE outlet_id = :outlet_id
        ");
        $stmt->execute([':outlet_id' => $outletId]);
        audit_log($pdo, $outletId, $userId, 'loyverse_push_product', 'product', (int) $product['id'], 'Produk dipush ke Loyverse.', [
            'sku' => $product['sku'],
            'name' => $product['name'],
            'item_id' => $result['item_id'],
            'variant_id' => $result['variant_id'],
            'matched_existing' => $result['matched_existing'],
        ]);

        json_response([
            'message' => $result['matched_existing']
                ? 'Produk sudah ada di Loyverse dan mapping berhasil disimpan.'
                : 'Produk berhasil dipush ke Loyverse.',
            'product_id' => (int) $product['id'],
            'item_id' => $result['item_id'],
            'variant_id' => $result['variant_id'],
        ]);
    } catch (Throwable $error) {
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($action === 'import_receipts') {
    require_method('POST');
    loyverse_require_sync_schema($pdo);
    $integration = loyverse_fresh_integration($pdo, loyverse_get_integration($pdo, $outletId));
    loyverse_require_scope($integration, 'RECEIPTS_READ');
    $selectedStoreId = loyverse_shared_default_store_id($pdo, $integration);
    $query = ['limit' => 50];
    $updatedAtMin = iso_from_mysql($integration['last_receipt_sync_at'] ?? null);
    if ($updatedAtMin) {
        $query['updated_at_min'] = $updatedAtMin;
    }

    $response = loyverse_api_request($integration, 'GET', '/receipts', $query);
    $receipts = $response['receipts'] ?? [];
    if (!is_array($receipts)) {
        json_response(['error' => 'Response receipt Loyverse tidak valid.'], 502);
    }

    $imported = 0;
    $skipped = 0;
    $errors = [];

    foreach ($receipts as $receipt) {
        if (!is_array($receipt)) {
            $skipped++;
            continue;
        }

        $receiptStoreId = trim((string) ($receipt['store_id'] ?? ''));
        if ($receiptStoreId !== '' && !hash_equals($selectedStoreId, $receiptStoreId)) {
            $skipped++;
            continue;
        }

        try {
            $pdo->beginTransaction();
            $didImport = import_loyverse_receipt($pdo, $outletId, $userId, $receipt);
            $pdo->commit();

            $didImport ? $imported++ : $skipped++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = [
                'receipt_number' => $receipt['receipt_number'] ?? '-',
                'error' => $error->getMessage(),
            ];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE loyverse_integrations
        SET last_receipt_sync_at = NOW()
        WHERE outlet_id = :outlet_id
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    audit_log($pdo, $outletId, $userId, 'loyverse_import_receipts', 'integration', 'loyverse', 'Penjualan Loyverse ditarik.', [
        'imported' => $imported,
        'skipped' => $skipped,
        'failed' => count($errors),
    ]);

    json_response([
        'message' => "{$imported} penjualan Loyverse berhasil diimpor.",
        'imported' => $imported,
        'skipped' => $skipped,
        'failed' => count($errors),
        'errors' => $errors,
    ], $errors ? 207 : 200);
}

if ($action === 'refresh_receipt_taxes') {
    require_method('POST');
    loyverse_require_sync_schema($pdo);
    $integration = loyverse_fresh_integration($pdo, loyverse_get_integration($pdo, $outletId));
    loyverse_require_scope($integration, 'RECEIPTS_READ');

    $stmt = $pdo->prepare("
        SELECT id, invoice_no, loyverse_receipt_number, subtotal_amount, tax_amount, total_amount
        FROM transactions
        WHERE outlet_id = :outlet_id
          AND source = 'loyverse'
          AND loyverse_receipt_number IS NOT NULL
          AND loyverse_receipt_number <> ''
          AND (tax_amount = 0 OR subtotal_amount = total_amount)
        ORDER BY sold_at DESC
        LIMIT 100
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $transactions = $stmt->fetchAll();

    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($transactions as $transaction) {
        $receiptNumber = (string) $transaction['loyverse_receipt_number'];

        try {
            $receipt = loyverse_api_request($integration, 'GET', '/receipts/' . rawurlencode($receiptNumber));
            if (!$receipt) {
                $skipped++;
                continue;
            }

            $pdo->beginTransaction();
            $didUpdate = refresh_loyverse_receipt_tax($pdo, $outletId, $transaction, $receipt);
            $pdo->commit();

            $didUpdate ? $updated++ : $skipped++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = [
                'receipt_number' => $receiptNumber,
                'invoice_no' => $transaction['invoice_no'] ?? '-',
                'error' => $error->getMessage(),
            ];
        }
    }

    $firstError = $errors[0]['error'] ?? null;
    audit_log($pdo, $outletId, $userId, 'loyverse_refresh_receipt_taxes', 'integration', 'loyverse', 'Repair pajak transaksi Loyverse lama dijalankan.', [
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => count($errors),
    ]);

    json_response([
        'message' => $firstError
            ? "{$updated} transaksi diperbaiki, {$skipped} dilewati, " . count($errors) . " gagal. Error pertama: {$firstError}"
            : "{$updated} transaksi Loyverse lama berhasil diperbaiki pajaknya.",
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => count($errors),
        'errors' => $errors,
    ], $errors ? 207 : 200);
}

if ($action === 'status') {
    require_method('GET');
    $stmt = $pdo->prepare("
        SELECT *
        FROM loyverse_integrations
        WHERE outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $integration = $stmt->fetch();
    $publicIntegration = null;
    $stores = [];
    $storesError = null;
    $selectedStore = null;
    $storeMappingReady = loyverse_store_mapping_schema_ready($pdo);

    if ($integration) {
        $publicIntegration = [
            'merchant_name' => $integration['merchant_name'] ?? null,
            'merchant_email' => $integration['merchant_email'] ?? null,
            'scope' => $integration['scope'] ?? null,
            'expires_at' => $integration['expires_at'] ?? null,
            'connected_at' => $integration['connected_at'] ?? null,
            'last_product_sync_at' => $integration['last_product_sync_at'] ?? null,
            'last_receipt_sync_at' => $integration['last_receipt_sync_at'] ?? null,
            'updated_at' => $integration['updated_at'] ?? null,
        ];

        if ($storeMappingReady) {
            $stmt = $pdo->prepare("
                SELECT loyverse_store_id, loyverse_store_name
                FROM outlets
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $outletId]);
            $outletStore = $stmt->fetch() ?: [];
            if (!empty($outletStore['loyverse_store_id'])) {
                $selectedStore = [
                    'id' => (string) $outletStore['loyverse_store_id'],
                    'name' => (string) ($outletStore['loyverse_store_name'] ?? ''),
                ];
            }
        }

        if (($_GET['include_stores'] ?? '') === '1') {
            try {
                $integration = loyverse_fresh_integration($pdo, $integration);
                $stores = loyverse_public_stores($integration);
                if (!$selectedStore && isset($stores[0])) {
                    $selectedStore = $stores[0];
                }
            } catch (Throwable $error) {
                $storesError = $error->getMessage();
            }
        }
    }

    json_response([
        'configured' => $config['client_id'] !== '' && $config['client_secret'] !== '',
        'connected' => (bool) $integration,
        'integration' => $publicIntegration,
        'stores' => $stores,
        'stores_error' => $storesError,
        'selected_store' => $selectedStore,
        'store_mapping_ready' => $storeMappingReady,
        'connect_url' => '../api/loyverse.php?action=connect',
    ]);
}

json_response(['error' => 'Action Loyverse tidak dikenal.'], 404);
