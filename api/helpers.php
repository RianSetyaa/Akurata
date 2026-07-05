<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '2592000');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['error' => 'Payload JSON tidak valid.'], 400);
    }

    return $data;
}

function limited_text(string $value, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_response(['error' => 'Method tidak didukung.'], 405);
    }
}

function money($value): int
{
    return (int) round((float) ($value ?? 0));
}

function quantity($value)
{
    $number = round((float) ($value ?? 0), 3);
    return abs($number - round($number)) < 0.0005 ? (int) round($number) : $number;
}

function quantity_is_whole($value): bool
{
    $number = (float) ($value ?? 0);
    return abs($number - round($number)) < 0.0005;
}

function login_user(array $user, bool $remember = false): void
{
    session_regenerate_id(true);
    unset($_SESSION['impersonation']);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'outlet_id' => (int) $user['outlet_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => normalize_role((string) $user['role']),
        'outlet_name' => $user['outlet_name'] ?? null,
    ];

    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + 2592000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function normalize_role(string $role): string
{
    return $role === 'admin' ? 'administrator' : $role;
}

function user_role(array $user): string
{
    return normalize_role((string) ($user['role'] ?? 'cashier'));
}

function current_user(): ?array
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return null;
    }

    $user = $_SESSION['user'];
    $user['role'] = user_role($user);

    if ($user['role'] === 'administrator' && isset($_SESSION['impersonation']) && is_array($_SESSION['impersonation'])) {
        $impersonation = $_SESSION['impersonation'];
        $user['admin_id'] = (int) $user['id'];
        $user['admin_outlet_id'] = (int) $user['outlet_id'];
        $user['outlet_id'] = (int) $impersonation['outlet_id'];
        $user['outlet_name'] = $impersonation['outlet_name'] ?? $user['outlet_name'];
        $user['impersonating'] = true;
    } else {
        $user['impersonating'] = false;
    }

    return $user;
}

function require_auth(): array
{
    $user = current_user();

    if (!$user) {
        json_response(['error' => 'Belum login.'], 401);
    }

    return $user;
}

function current_outlet_id(): int
{
    $user = require_auth();
    return (int) $user['outlet_id'];
}

function require_role(array $allowedRoles): array
{
    $user = require_auth();
    $role = user_role($user);

    if (!in_array($role, $allowedRoles, true)) {
        json_response(['error' => 'Akses tidak diizinkan untuk role ini.'], 403);
    }

    return $user;
}

function require_administrator(): array
{
    return require_role(['administrator']);
}

function require_owner_or_administrator(): array
{
    return require_role(['owner', 'administrator']);
}

function deny_manager_access(string $feature): void
{
    $user = require_auth();
    if (user_role($user) === 'manager') {
        json_response(['error' => "Manager tidak memiliki akses ke {$feature}."], 403);
    }
}

function invoice_number(PDO $pdo, int $outletId): string
{
    $date = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 AS next_no
        FROM transactions
        WHERE outlet_id = :outlet_id
          AND DATE(sold_at) = CURDATE()
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $next = (int) $stmt->fetch()['next_no'];

    return 'INV-' . $outletId . '-' . $date . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function quotation_number(PDO $pdo, int $outletId): string
{
    $date = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 AS next_no
        FROM quotations
        WHERE outlet_id = :outlet_id
          AND DATE(quoted_at) = CURDATE()
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $next = (int) $stmt->fetch()['next_no'];

    return 'QTN-' . $outletId . '-' . $date . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function purchase_order_number(PDO $pdo, int $outletId): string
{
    $date = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 AS next_no
        FROM purchases
        WHERE outlet_id = :outlet_id
          AND DATE(purchased_at) = CURDATE()
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $next = (int) $stmt->fetch()['next_no'];

    return 'PO-' . $outletId . '-' . $date . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function outlet_tax_config(PDO $pdo, int $outletId): array
{
    $stmt = $pdo->prepare("
        SELECT tax_enabled, tax_rate
        FROM outlets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $outletId]);
    $row = $stmt->fetch() ?: [];
    $enabled = (int) ($row['tax_enabled'] ?? 0) === 1;
    $rate = max(0, (float) ($row['tax_rate'] ?? 0));

    return [
        'enabled' => $enabled && $rate > 0,
        'rate' => $enabled ? $rate : 0.0,
    ];
}

function tax_breakdown(PDO $pdo, int $outletId, float $subtotal): array
{
    $config = outlet_tax_config($pdo, $outletId);
    $taxRate = $config['enabled'] ? $config['rate'] : 0.0;
    $taxAmount = round($subtotal * $taxRate / 100, 2);

    return [
        'subtotal_amount' => $subtotal,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total_amount' => $subtotal + $taxAmount,
    ];
}

function receivable_payment_number(PDO $pdo, int $outletId): string
{
    $date = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 AS next_no
        FROM receivable_payments
        WHERE outlet_id = :outlet_id
          AND DATE(paid_at) = CURDATE()
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $next = (int) $stmt->fetch()['next_no'];

    return 'PAY-' . $outletId . '-' . $date . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function table_column_exists(PDO $pdo, string $table, string $column): bool
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

function activity_logs_table_exists(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $exists = table_column_exists($pdo, 'activity_logs', 'created_at');
    } catch (Throwable) {
        $exists = false;
    }

    return $exists;
}

function audit_log(
    PDO $pdo,
    ?int $outletId,
    ?int $userId,
    string $action,
    string $entityType,
    $entityId,
    string $description,
    array $metadata = []
): void {
    if (!activity_logs_table_exists($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (
              outlet_id, user_id, action, entity_type, entity_id, description,
              metadata, ip_address, user_agent
            )
            VALUES (
              :outlet_id, :user_id, :action, :entity_type, :entity_id, :description,
              :metadata, :ip_address, :user_agent
            )
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':user_id' => $userId,
            ':action' => limited_text($action, 60),
            ':entity_type' => limited_text($entityType, 60),
            ':entity_id' => $entityId !== null ? limited_text((string) $entityId, 80) : null,
            ':description' => limited_text($description, 255),
            ':metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ':ip_address' => limited_text((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 45) ?: null,
            ':user_agent' => limited_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255) ?: null,
        ]);
    } catch (Throwable) {
        return;
    }
}

function brevo_send_email(string $toEmail, string $toName, string $subject, string $htmlContent, string $textContent): void
{
    $config = brevo_config();

    if (($config['api_key'] ?? '') === '') {
        throw new RuntimeException('BREVO_API_KEY belum diset di server.');
    }

    $payload = [
        'sender' => [
            'name' => $config['sender_name'],
            'email' => $config['sender_email'],
        ],
        'to' => [[
            'email' => $toEmail,
            'name' => $toName,
        ]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => $textContent,
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Payload email tidak valid.');
    }

    if (function_exists('curl_init')) {
        $curl = curl_init((string) $config['api_url']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'api-key: ' . $config['api_key'],
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($response === false) {
            throw new RuntimeException($error ?: 'Gagal menghubungi Brevo.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'api-key: ' . $config['api_key'],
                ]) . "\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents((string) $config['api_url'], false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = (int) ($matches[1] ?? 500);

        if ($response === false) {
            throw new RuntimeException('Gagal menghubungi Brevo.');
        }
    }

    if ($status < 200 || $status >= 300) {
        $data = json_decode((string) $response, true);
        $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;
        throw new RuntimeException('Brevo gagal mengirim email' . ($message ? ': ' . $message : '.'));
    }
}

function loyverse_shared_json_request(string $url, array $payload): array
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

function loyverse_shared_api_request(array $integration, string $method, string $path, array $query = [], ?array $payload = null): array
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
        throw new RuntimeException('HTTP ' . $status . ' - Request Loyverse gagal.');
    }

    return is_array($decoded) ? $decoded : [];
}

function loyverse_shared_scopes(array $integration): array
{
    return preg_split('/\s+/', trim((string) ($integration['scope'] ?? ''))) ?: [];
}

function loyverse_shared_require_scopes(array $integration, array $required): void
{
    $scopes = loyverse_shared_scopes($integration);
    foreach ($required as $scope) {
        if (!in_array($scope, $scopes, true)) {
            throw new RuntimeException("Token Loyverse belum punya izin {$scope}. Putus integrasi lalu connect ulang Loyverse.");
        }
    }
}

function loyverse_shared_integration(PDO $pdo, int $outletId): ?array
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
        return null;
    }

    $expiresAt = strtotime((string) ($integration['expires_at'] ?? ''));
    if (!$expiresAt || $expiresAt > time() + 60 || empty($integration['refresh_token'])) {
        return $integration;
    }

    $config = loyverse_config();
    $token = loyverse_shared_json_request($config['token_url'], [
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $integration['refresh_token'],
        'grant_type' => 'refresh_token',
    ]);

    $nextExpiresAt = isset($token['expires_in'])
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
        ':expires_at' => $nextExpiresAt,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM loyverse_integrations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $integration['id']]);
    return $stmt->fetch() ?: null;
}

function loyverse_shared_default_store_id(PDO $pdo, array $integration): string
{
    loyverse_shared_require_scopes($integration, ['STORES_READ']);

    $outletId = (int) ($integration['outlet_id'] ?? 0);
    if ($outletId > 0) {
        $columnStmt = $pdo->query("
            SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'outlets'
              AND COLUMN_NAME = 'loyverse_store_id'
        ");
        $hasStoreColumn = (int) ($columnStmt->fetch()['total'] ?? 0) > 0;

        if ($hasStoreColumn) {
            $stmt = $pdo->prepare("
                SELECT loyverse_store_id
                FROM outlets
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $outletId]);
            $selectedStoreId = trim((string) ($stmt->fetch()['loyverse_store_id'] ?? ''));
            if ($selectedStoreId !== '') {
                return $selectedStoreId;
            }
        }
    }

    $response = loyverse_shared_api_request($integration, 'GET', '/stores', ['limit' => 1]);
    $stores = $response['stores'] ?? [];

    if (!is_array($stores) || !isset($stores[0]) || !is_array($stores[0]) || empty($stores[0]['id'])) {
        throw new RuntimeException('Store Loyverse tidak ditemukan.');
    }

    return (string) $stores[0]['id'];
}

function loyverse_sync_product_stock(
    PDO $pdo,
    int $outletId,
    int $productId,
    ?array $integration = null,
    ?string $storeId = null
): void
{
    $integration ??= loyverse_shared_integration($pdo, $outletId);
    if (!$integration) {
        return;
    }

    loyverse_shared_require_scopes($integration, ['INVENTORY_WRITE', 'STORES_READ']);

    $stmt = $pdo->prepare("
        SELECT id, stock_qty, is_composite, loyverse_variant_id
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

    if (!$product || (int) ($product['is_composite'] ?? 0) === 1 || empty($product['loyverse_variant_id'])) {
        return;
    }

    $storeId ??= loyverse_shared_default_store_id($pdo, $integration);
    loyverse_shared_api_request($integration, 'POST', '/inventory', [], [
        'inventory_levels' => [[
            'variant_id' => (string) $product['loyverse_variant_id'],
            'store_id' => $storeId,
            'stock_after' => (float) $product['stock_qty'],
        ]],
    ]);
}

function loyverse_sync_outlet_tax(PDO $pdo, int $outletId, ?array $integration = null): ?string
{
    $integration ??= loyverse_shared_integration($pdo, $outletId);
    if (!$integration) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT tax_enabled, tax_rate, loyverse_tax_id
        FROM outlets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $outletId]);
    $outlet = $stmt->fetch();

    if (!$outlet || (int) ($outlet['tax_enabled'] ?? 0) !== 1 || (float) ($outlet['tax_rate'] ?? 0) <= 0) {
        return null;
    }

    loyverse_shared_require_scopes($integration, ['TAXES_WRITE', 'STORES_READ']);
    $storeId = loyverse_shared_default_store_id($pdo, $integration);
    $rate = min(100, max(0, (float) $outlet['tax_rate']));
    $payload = [
        'type' => 'ADDED',
        'name' => 'Akurata Pajak ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%',
        'rate' => $rate,
        'stores' => [$storeId],
    ];

    if (!empty($outlet['loyverse_tax_id'])) {
        $payload['id'] = $outlet['loyverse_tax_id'];
    }

    $tax = loyverse_shared_api_request($integration, 'POST', '/taxes', [], $payload);
    $taxId = (string) ($tax['id'] ?? $outlet['loyverse_tax_id'] ?? '');
    if ($taxId === '') {
        throw new RuntimeException('Loyverse tidak mengirim ID pajak.');
    }

    $stmt = $pdo->prepare("
        UPDATE outlets
        SET loyverse_tax_id = :tax_id,
            loyverse_tax_synced_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':tax_id' => $taxId,
        ':id' => $outletId,
    ]);

    return $taxId;
}

function loyverse_disable_outlet_tax(PDO $pdo, int $outletId, ?array $integration = null): void
{
    $integration ??= loyverse_shared_integration($pdo, $outletId);
    if (!$integration) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT loyverse_tax_id
        FROM outlets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $outletId]);
    $outlet = $stmt->fetch();
    $taxId = (string) ($outlet['loyverse_tax_id'] ?? '');

    if ($taxId === '') {
        return;
    }

    loyverse_shared_require_scopes($integration, ['TAXES_WRITE']);
    loyverse_shared_api_request($integration, 'DELETE', '/taxes/' . rawurlencode($taxId));

    $stmt = $pdo->prepare("
        UPDATE outlets
        SET loyverse_tax_id = NULL,
            loyverse_tax_synced_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $outletId]);
}

function loyverse_shared_iso_datetime(?string $value = null): string
{
    $timestamp = strtotime((string) ($value ?: 'now'));
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp ?: time());
}

function loyverse_shared_payment_type_id(array $integration, string $method): string
{
    loyverse_shared_require_scopes($integration, ['PAYMENT_TYPES_READ']);

    $response = loyverse_shared_api_request($integration, 'GET', '/payment_types', ['limit' => 250]);
    $paymentTypes = $response['payment_types'] ?? [];
    if (!is_array($paymentTypes) || count($paymentTypes) === 0) {
        throw new RuntimeException('Payment type Loyverse tidak ditemukan.');
    }

    $keywords = match ($method) {
        'qris' => ['qris', 'qr', 'qr code'],
        'transfer' => ['transfer', 'bank', 'card', 'kartu', 'debit', 'credit'],
        default => ['cash', 'tunai'],
    };

    foreach ($paymentTypes as $paymentType) {
        if (!is_array($paymentType) || !empty($paymentType['deleted_at'])) {
            continue;
        }

        $id = (string) ($paymentType['id'] ?? '');
        $name = strtolower((string) ($paymentType['name'] ?? ''));
        $type = strtolower((string) ($paymentType['type'] ?? ''));
        if ($id === '') {
            continue;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword) || str_contains($type, $keyword)) {
                return $id;
            }
        }
    }

    foreach ($paymentTypes as $paymentType) {
        if (!is_array($paymentType) || !empty($paymentType['deleted_at'])) {
            continue;
        }

        $id = (string) ($paymentType['id'] ?? '');
        $type = strtolower((string) ($paymentType['type'] ?? ''));
        if ($id === '') {
            continue;
        }

        if (($method === 'cash' && $type === 'cash') || ($method === 'transfer' && $type === 'card')) {
            return $id;
        }
    }

    if ($method !== 'cash') {
        throw new RuntimeException('Payment type ' . strtoupper($method) . ' belum ada/cocok di Loyverse.');
    }

    throw new RuntimeException('Payment type tunai Loyverse aktif tidak ditemukan.');
}

function loyverse_sync_transaction_receipt(PDO $pdo, int $outletId, int $transactionId): ?string
{
    $integration = loyverse_shared_integration($pdo, $outletId);
    if (!$integration) {
        return null;
    }

    loyverse_shared_require_scopes($integration, ['RECEIPTS_WRITE', 'PAYMENT_TYPES_READ', 'STORES_READ']);

    $stmt = $pdo->prepare("
        SELECT id, invoice_no, payment_method, payment_status, tax_amount,
               loyverse_receipt_number, sold_at
        FROM transactions
        WHERE id = :id
          AND outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $transactionId,
        ':outlet_id' => $outletId,
    ]);
    $transaction = $stmt->fetch();

    if (!$transaction || (string) $transaction['payment_status'] !== 'paid') {
        return null;
    }

    if ((string) $transaction['payment_method'] === 'tempo' || !empty($transaction['loyverse_receipt_number'])) {
        return $transaction['loyverse_receipt_number'] ?: null;
    }

    $itemStmt = $pdo->prepare("
        SELECT ti.qty, ti.unit_price, ti.unit_cost, p.name, p.loyverse_variant_id
        FROM transaction_items ti
        JOIN products p ON p.id = ti.product_id
        WHERE ti.transaction_id = :transaction_id
        ORDER BY ti.id ASC
    ");
    $itemStmt->execute([':transaction_id' => $transactionId]);
    $items = $itemStmt->fetchAll();
    if (!$items) {
        throw new RuntimeException('Item transaksi tidak ditemukan untuk sync Loyverse.');
    }

    $taxId = (float) ($transaction['tax_amount'] ?? 0) > 0
        ? loyverse_sync_outlet_tax($pdo, $outletId, $integration)
        : null;

    $lineItems = [];
    foreach ($items as $item) {
        $variantId = (string) ($item['loyverse_variant_id'] ?? '');
        if ($variantId === '') {
            throw new RuntimeException('Produk ' . ($item['name'] ?? '-') . ' belum terintegrasi ke Loyverse.');
        }

        $line = [
            'variant_id' => $variantId,
            'quantity' => (float) $item['qty'],
            'price' => (float) $item['unit_price'],
            'cost' => (float) $item['unit_cost'],
        ];

        if ($taxId !== null && $taxId !== '') {
            $line['line_taxes'] = [['id' => $taxId]];
        }

        $lineItems[] = $line;
    }

    $storeId = loyverse_shared_default_store_id($pdo, $integration);
    $paymentTypeId = loyverse_shared_payment_type_id($integration, (string) $transaction['payment_method']);
    $payload = [
        'store_id' => $storeId,
        'order' => (string) $transaction['invoice_no'],
        'source' => 'Akurata POS',
        'receipt_date' => loyverse_shared_iso_datetime($transaction['sold_at'] ?? null),
        'note' => 'Invoice Akurata ' . $transaction['invoice_no'],
        'line_items' => $lineItems,
        'payments' => [[
            'payment_type_id' => $paymentTypeId,
            'paid_at' => loyverse_shared_iso_datetime($transaction['sold_at'] ?? null),
        ]],
    ];

    $receipt = loyverse_shared_api_request($integration, 'POST', '/receipts', [], $payload);
    $receiptNumber = (string) ($receipt['receipt_number'] ?? '');
    if ($receiptNumber === '') {
        throw new RuntimeException('Loyverse tidak mengirim nomor receipt.');
    }

    $stmt = $pdo->prepare("
        UPDATE transactions
        SET loyverse_receipt_number = :receipt_number
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");
    $stmt->execute([
        ':id' => $transactionId,
        ':outlet_id' => $outletId,
        ':receipt_number' => $receiptNumber,
    ]);

    return $receiptNumber;
}

function send_fonnte_message(string $target, string $message): void
{
    $target = trim($target);
    if ($target === '') {
        return; // No target
    }

    $config = fonnte_config();
    if (empty($config['token'])) {
        return; // Fonnte token not configured
    }

    $url = $config['api_url'];
    $data = [
        'target' => $target,
        'message' => $message,
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $config['token']
            ],
            CURLOPT_TIMEOUT => 3, // Very short timeout so we don't hang the API
        ]);

        curl_exec($curl);
        curl_close($curl);
    }
}

function notify_low_stock_if_needed(PDO $pdo, int $outletId, int $productId, float $qtyDeducted): void
{
    if ($qtyDeducted <= 0) return;

    // Check product's current stock and min_stock
    $stmt = $pdo->prepare("SELECT name, stock_qty, min_stock, is_composite FROM products WHERE id = :id AND outlet_id = :outlet_id");
    $stmt->execute([':id' => $productId, ':outlet_id' => $outletId]);
    $product = $stmt->fetch();

    if (!$product || $product['is_composite']) return;

    $currentStock = (float) $product['stock_qty'];
    $minStock = (float) $product['min_stock'];
    $previousStock = $currentStock + $qtyDeducted;

    // Only notify if it JUST dropped to/below minimum
    if ($previousStock > $minStock && $currentStock <= $minStock) {
        $stmt = $pdo->prepare("SELECT name, whatsapp_number FROM outlets WHERE id = :outlet_id");
        $stmt->execute([':outlet_id' => $outletId]);
        $outlet = $stmt->fetch();

        if ($outlet && !empty($outlet['whatsapp_number'])) {
            $msg = "*Stok Menipis!*\n\n";
            $msg .= "Outlet: {$outlet['name']}\n";
            $msg .= "Barang: {$product['name']}\n";
            $msg .= "Sisa Stok: {$currentStock}\n";
            $msg .= "Batas Minimum: {$minStock}\n\n";
            $msg .= "_Pesan otomatis dari sistem Akurata POS_";

            send_fonnte_message((string) $outlet['whatsapp_number'], $msg);
        }
    }
}
