<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('POST');

$data = read_json();
$identity = trim((string) ($data['identity'] ?? ''));
$password = (string) ($data['password'] ?? '');
$remember = !empty($data['remember']) && (string) $data['remember'] !== '0';

if ($identity === '' || $password === '') {
    json_response(['error' => 'Email/nomor dan password wajib diisi.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare("
    SELECT u.id, u.outlet_id, u.name, u.email, u.password_hash, u.role,
           " . (table_column_exists($pdo, 'users', 'is_active') ? 'u.is_active' : '1 AS is_active') . ",
           o.name AS outlet_name
    FROM users u
    JOIN outlets o ON o.id = u.outlet_id
    WHERE u.email = :identity
    LIMIT 1
");
$stmt->execute([':identity' => $identity]);
$user = $stmt->fetch();

if (!$user || (int) ($user['is_active'] ?? 1) !== 1 || !password_verify($password, $user['password_hash'])) {
    if ($user) {
        audit_log($pdo, (int) $user['outlet_id'], (int) $user['id'], 'login_failed', 'auth', (int) $user['id'], 'Percobaan login gagal.', [
            'identity' => $identity,
        ]);
    }
    json_response(['error' => 'Email/nomor atau password salah.'], 401);
}

$sessionUser = [
    'id' => (int) $user['id'],
    'outlet_id' => (int) $user['outlet_id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => $user['role'],
    'outlet_name' => $user['outlet_name'],
];

login_user($sessionUser, $remember);
audit_log($pdo, (int) $user['outlet_id'], (int) $user['id'], 'login_success', 'auth', (int) $user['id'], 'User login ke sistem.');

json_response([
    'message' => 'Login berhasil.',
    'user' => $sessionUser,
    'remembered' => $remember,
]);
