<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * Validasi token Bearer dari Header Authorization.
 * @return array Data user dan outlet
 */
function require_pos_auth(): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        json_response(['error' => 'Token otentikasi tidak ditemukan atau tidak valid.'], 401);
    }

    $token = trim($matches[1]);

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.*, o.name as outlet_name, o.tax_enabled, o.tax_rate 
        FROM users u 
        JOIN outlets o ON u.outlet_id = o.id 
        WHERE u.api_token = ? AND u.is_active = 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response(['error' => 'Token tidak valid atau sesi telah berakhir.'], 401);
    }

    $user['role'] = normalize_role((string) $user['role']);
    
    // Jangan kirim password_hash ke client
    unset($user['password_hash']);
    
    return $user;
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}
