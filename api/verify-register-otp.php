<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('POST');

$data = read_json();
$pendingId = (int) ($data['pending_id'] ?? 0);
$otp = preg_replace('/\D+/', '', (string) ($data['otp'] ?? ''));

if ($pendingId <= 0 || $otp === '') {
    json_response(['error' => 'Kode OTP wajib diisi.'], 422);
}

if (strlen($otp) !== 6) {
    json_response(['error' => 'Kode OTP harus 6 digit.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare("
    SELECT *,
           expires_at < NOW() AS is_expired
    FROM pending_registrations
    WHERE id = :id
      AND verified_at IS NULL
    LIMIT 1
");
$stmt->execute([':id' => $pendingId]);
$pending = $stmt->fetch();

if (!$pending) {
    json_response(['error' => 'Sesi OTP tidak ditemukan. Silakan daftar ulang.'], 404);
}

if ((int) ($pending['is_expired'] ?? 0) === 1) {
    json_response(['error' => 'Kode OTP sudah kedaluwarsa. Silakan daftar ulang untuk menerima kode baru.'], 410);
}

if ((int) $pending['attempts'] >= 5) {
    json_response(['error' => 'Percobaan OTP terlalu banyak. Silakan daftar ulang.'], 429);
}

if (!password_verify($otp, (string) $pending['otp_hash'])) {
    $stmt = $pdo->prepare("UPDATE pending_registrations SET attempts = attempts + 1 WHERE id = :id");
    $stmt->execute([':id' => $pendingId]);
    json_response(['error' => 'Kode OTP salah.'], 422);
}

$exists = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$exists->execute([':email' => $pending['email']]);
if ($exists->fetch()) {
    json_response(['error' => 'Email sudah terdaftar.'], 409);
}

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("INSERT INTO outlets (name, address) VALUES (:name, :address)");
    $stmt->execute([
        ':name' => $pending['business'],
        ':address' => 'Setup awal - ' . (string) ($pending['outlets'] ?? '1') . ' outlet',
    ]);
    $outletId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO users (outlet_id, name, email, password_hash, role)
        VALUES (:outlet_id, :name, :email, :password_hash, 'owner')
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':name' => $pending['name'],
        ':email' => $pending['email'],
        ':password_hash' => $pending['password_hash'],
    ]);
    $userId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("UPDATE pending_registrations SET verified_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $pendingId]);

    $pdo->commit();

    $sessionUser = [
        'id' => $userId,
        'outlet_id' => $outletId,
        'name' => $pending['name'],
        'email' => $pending['email'],
        'role' => 'owner',
        'outlet_name' => $pending['business'],
    ];

    login_user($sessionUser);
    audit_log($pdo, $outletId, $userId, 'register', 'auth', $userId, 'Akun dan outlet baru dibuat setelah OTP valid.', [
        'business' => $pending['business'],
    ]);

    json_response([
        'message' => 'OTP valid. Akun berhasil dibuat.',
        'user' => $sessionUser,
    ], 201);
} catch (Throwable $error) {
    $pdo->rollBack();
    json_response(['error' => $error->getMessage()], 500);
}
