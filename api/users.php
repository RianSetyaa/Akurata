<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_owner_or_administrator();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $activeFilter = table_column_exists($pdo, 'users', 'is_active') ? 'AND is_active = 1' : '';
    $stmt = $pdo->prepare("
        SELECT id, name, email, role, created_at
        FROM users
        WHERE outlet_id = :outlet_id
          {$activeFilter}
        ORDER BY FIELD(role, 'owner', 'manager', 'cashier'), name ASC
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    json_response([
        'users' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => normalize_role((string) $row['role']),
            'created_at' => $row['created_at'],
        ], $stmt->fetchAll()),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $role = normalize_role((string) ($data['role'] ?? 'manager'));

    if ($name === '' || $email === '' || $password === '') {
        json_response(['error' => 'Nama, email, dan password wajib diisi.'], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'Email user tidak valid.'], 422);
    }

    if (strlen($password) < 6) {
        json_response(['error' => 'Password minimal 6 karakter.'], 422);
    }

    if (!in_array($role, ['manager', 'cashier'], true)) {
        json_response(['error' => 'Owner hanya bisa membuat Manager atau Cashier.'], 422);
    }

    $exists = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $exists->execute([':email' => $email]);
    if ($exists->fetch()) {
        json_response(['error' => 'Email user sudah digunakan.'], 409);
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (outlet_id, name, email, password_hash, role)
        VALUES (:outlet_id, :name, :email, :password_hash, :role)
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => $role,
    ]);

    $createdUserId = (int) $pdo->lastInsertId();
    audit_log($pdo, $outletId, $userId, 'user_create', 'user', $createdUserId, 'User bawahan dibuat.', [
        'name' => $name,
        'email' => $email,
        'role' => $role,
    ]);

    json_response([
        'message' => ucfirst($role) . ' berhasil dibuat.',
        'id' => $createdUserId,
    ], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!table_column_exists($pdo, 'users', 'is_active')) {
        json_response(['error' => 'Jalankan migration 2026_06_05_user_soft_delete.sql dulu sebelum menghapus user.'], 409);
    }

    $data = read_json();
    $targetId = (int) ($data['id'] ?? 0);

    if ($targetId <= 0) {
        json_response(['error' => 'User wajib dipilih.'], 422);
    }

    if ($targetId === $userId) {
        json_response(['error' => 'Tidak bisa menghapus akun sendiri dari halaman akses.'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT id, name, email, role, is_active
        FROM users
        WHERE id = :id
          AND outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $targetId,
        ':outlet_id' => $outletId,
    ]);
    $target = $stmt->fetch();

    if (!$target || (int) $target['is_active'] !== 1) {
        json_response(['error' => 'User tidak ditemukan.'], 404);
    }

    $targetRole = normalize_role((string) $target['role']);
    if (!in_array($targetRole, ['manager', 'cashier'], true)) {
        json_response(['error' => 'Hanya akun Manager dan Cashier yang bisa dihapus dari halaman akses.'], 422);
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET is_active = 0,
            deleted_at = NOW(),
            email = CONCAT('deleted-', id, '-', email)
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");
    $stmt->execute([
        ':id' => $targetId,
        ':outlet_id' => $outletId,
    ]);

    audit_log($pdo, $outletId, $userId, 'user_delete', 'user', $targetId, 'User bawahan dihapus.', [
        'name' => $target['name'],
        'email' => $target['email'],
        'role' => $targetRole,
    ]);

    json_response(['message' => 'Akun user berhasil dihapus.']);
}

json_response(['error' => 'Method tidak didukung.'], 405);
