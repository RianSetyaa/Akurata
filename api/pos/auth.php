<?php
declare(strict_types=1);

require_once __DIR__ . '/pos_helpers.php';
require_method('POST');

$input = read_json();
$email = trim($input['email'] ?? '');
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    json_response(['error' => 'Email dan password harus diisi.'], 400);
}

try {
    $pdo = db();
    
    // Perhatikan: query disesuaikan agar cocok untuk struktur prod dan dev jika perlu
    // Namun kita anggap struktur production yang benar: (email, password_hash)
    $stmt = $pdo->prepare("SELECT u.*, o.name as outlet_name FROM users u JOIN outlets o ON u.outlet_id = o.id WHERE u.email = ? AND u.is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Handling perbedaan schema dev local (password) vs production (password_hash)
    $dbPassword = $user['password_hash'] ?? $user['password'] ?? '';

    if (!$user || !password_verify($password, $dbPassword)) {
        json_response(['error' => 'Email atau password salah.'], 401);
    }

    // Generate token panjang yang unik
    $token = bin2hex(random_bytes(32)); // 64 karakter

    $update = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
    $update->execute([$token, $user['id']]);

    json_response([
        'status' => 'success',
        'message' => 'Login berhasil.',
        'data' => [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'] ?? $user['nama'] ?? '',
                'email' => $user['email'],
                'role' => normalize_role((string) $user['role']),
            ],
            'outlet' => [
                'id' => (int) ($user['outlet_id'] ?? $user['tenant_id'] ?? 0),
                'name' => $user['outlet_name'] ?? '',
            ]
        ]
    ]);
} catch (Exception $e) {
    error_log("POS API Auth Error: " . $e->getMessage());
    json_response(['error' => 'Terjadi kesalahan sistem saat login.'], 500);
}
