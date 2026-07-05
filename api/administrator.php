<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_administrator();
$adminId = (int) $user['id'];
$pdo = db();

function administrator_delete_outlet(PDO $pdo, int $outletId): void
{
    $pdo->beginTransaction();

    try {
        $userIds = $pdo->prepare("SELECT id FROM users WHERE outlet_id = :outlet_id");
        $userIds->execute([':outlet_id' => $outletId]);
        $userIds = array_map('intval', array_column($userIds->fetchAll(), 'id'));

        if ($userIds) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $pdo->prepare("DELETE FROM activity_logs WHERE user_id IN ({$placeholders})")->execute($userIds);
            if (table_column_exists($pdo, 'pending_password_resets', 'id')) {
                $pdo->prepare("DELETE FROM pending_password_resets WHERE user_id IN ({$placeholders})")->execute($userIds);
            }
        }

        $transactionIds = $pdo->prepare("SELECT id FROM transactions WHERE outlet_id = :outlet_id");
        $transactionIds->execute([':outlet_id' => $outletId]);
        $transactionIds = array_map('intval', array_column($transactionIds->fetchAll(), 'id'));

        if ($transactionIds) {
            $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
            $pdo->prepare("DELETE FROM receivable_payments WHERE receivable_id IN (SELECT id FROM receivables WHERE transaction_id IN ({$placeholders}))")->execute($transactionIds);
            $pdo->prepare("DELETE FROM receivables WHERE transaction_id IN ({$placeholders})")->execute($transactionIds);
            $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id IN ({$placeholders})")->execute($transactionIds);
        }

        $purchaseIds = $pdo->prepare("SELECT id FROM purchases WHERE outlet_id = :outlet_id");
        $purchaseIds->execute([':outlet_id' => $outletId]);
        $purchaseIds = array_map('intval', array_column($purchaseIds->fetchAll(), 'id'));

        if ($purchaseIds) {
            $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
            $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id IN ({$placeholders})")->execute($purchaseIds);
        }

        $quotationIds = $pdo->prepare("SELECT id FROM quotations WHERE outlet_id = :outlet_id");
        $quotationIds->execute([':outlet_id' => $outletId]);
        $quotationIds = array_map('intval', array_column($quotationIds->fetchAll(), 'id'));

        if ($quotationIds) {
            $placeholders = implode(',', array_fill(0, count($quotationIds), '?'));
            $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id IN ({$placeholders})")->execute($quotationIds);
        }

        $outletTables = [
            'activity_logs',
            'loyverse_integrations',
            'receivable_payments',
            'receivables',
            'transactions',
            'quotations',
            'purchases',
            'shifts',
            'product_components',
            'products',
            'suppliers',
            'customers',
            'users',
        ];

        if (table_column_exists($pdo, 'support_conversations', 'id')) {
            array_unshift($outletTables, 'support_conversations');
        }
        if (table_column_exists($pdo, 'support_messages', 'id')) {
            array_unshift($outletTables, 'support_messages');
        }

        foreach ($outletTables as $table) {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE outlet_id = :outlet_id");
            $stmt->execute([':outlet_id' => $outletId]);
        }

        $stmt = $pdo->prepare("DELETE FROM outlets WHERE id = :id");
        $stmt->execute([':id' => $outletId]);

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hasUserActiveColumn = table_column_exists($pdo, 'users', 'is_active');
    $activeUserJoinFilter = $hasUserActiveColumn ? 'AND u.is_active = 1' : '';
    $activeUserWhereFilter = $hasUserActiveColumn ? 'WHERE u.is_active = 1' : '';

    $outletStmt = $pdo->query("
        SELECT o.id, o.name, o.address, o.created_at,
               COUNT(DISTINCT u.id) AS user_count,
               COUNT(DISTINCT p.id) AS product_count,
               COUNT(DISTINCT t.id) AS transaction_count
        FROM outlets o
        LEFT JOIN users u ON u.outlet_id = o.id {$activeUserJoinFilter}
        LEFT JOIN products p ON p.outlet_id = o.id AND p.is_active = 1
        LEFT JOIN transactions t ON t.outlet_id = o.id
        GROUP BY o.id, o.name, o.address, o.created_at
        ORDER BY o.created_at DESC, o.id DESC
    ");

    $userStmt = $pdo->query("
        SELECT u.id, u.outlet_id, u.name, u.email, u.role, u.created_at, o.name AS outlet_name
        FROM users u
        JOIN outlets o ON o.id = u.outlet_id
        {$activeUserWhereFilter}
        ORDER BY o.name ASC, FIELD(u.role, 'administrator', 'owner', 'manager', 'cashier'), u.name ASC
    ");

    json_response([
        'admin' => [
            'id' => $adminId,
            'name' => $user['name'],
            'email' => $user['email'],
            'impersonating' => (bool) ($user['impersonating'] ?? false),
            'outlet_id' => (int) $user['outlet_id'],
            'outlet_name' => $user['outlet_name'] ?? null,
        ],
        'outlets' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'address' => $row['address'],
            'user_count' => (int) $row['user_count'],
            'product_count' => (int) $row['product_count'],
            'transaction_count' => (int) $row['transaction_count'],
            'created_at' => $row['created_at'],
        ], $outletStmt->fetchAll()),
        'users' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'outlet_id' => (int) $row['outlet_id'],
            'outlet_name' => $row['outlet_name'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => normalize_role((string) $row['role']),
            'created_at' => $row['created_at'],
        ], $userStmt->fetchAll()),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    $action = (string) ($data['action'] ?? '');

    if ($action === 'impersonate') {
        $outletId = (int) ($data['outlet_id'] ?? 0);
        if ($outletId <= 0) {
            json_response(['error' => 'Outlet wajib dipilih.'], 422);
        }

        $stmt = $pdo->prepare("SELECT id, name FROM outlets WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $outletId]);
        $outlet = $stmt->fetch();

        if (!$outlet) {
            json_response(['error' => 'Outlet tidak ditemukan.'], 404);
        }

        $_SESSION['impersonation'] = [
            'outlet_id' => (int) $outlet['id'],
            'outlet_name' => $outlet['name'],
        ];

        json_response([
            'message' => 'Impersonate outlet berhasil.',
            'redirect' => '../dashboard/index.html',
        ]);
    }

    if ($action === 'stop_impersonate') {
        unset($_SESSION['impersonation']);

        json_response([
            'message' => 'Impersonate dihentikan.',
            'redirect' => 'index.html',
        ]);
    }

    json_response(['error' => 'Action administrator tidak dikenal.'], 422);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = read_json();
    $type = (string) ($data['type'] ?? '');
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        json_response(['error' => 'ID wajib valid.'], 422);
    }

    if ($type === 'user') {
        if (!table_column_exists($pdo, 'users', 'is_active')) {
            json_response(['error' => 'Jalankan migration 2026_06_05_user_soft_delete.sql dulu sebelum menghapus user.'], 409);
        }

        if ($id === $adminId) {
            json_response(['error' => 'Administrator tidak bisa menghapus akunnya sendiri.'], 422);
        }

        $stmt = $pdo->prepare("SELECT id, outlet_id, name, email, role, is_active FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $target = $stmt->fetch();

        if (!$target || (int) $target['is_active'] !== 1) {
            json_response(['error' => 'User tidak ditemukan.'], 404);
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET is_active = 0,
                deleted_at = NOW(),
                email = CONCAT('deleted-', id, '-', email)
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        audit_log($pdo, (int) $target['outlet_id'], $adminId, 'admin_delete_user', 'user', $id, 'Administrator menghapus user.', [
            'name' => $target['name'],
            'email' => $target['email'],
            'role' => normalize_role((string) $target['role']),
        ]);

        json_response(['message' => 'User berhasil dihapus. Histori tetap disimpan dan login user dimatikan.']);
    }

    if ($type === 'outlet') {
        $stmt = $pdo->prepare("SELECT id, name FROM outlets WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $outlet = $stmt->fetch();

        if (!$outlet) {
            json_response(['error' => 'Toko tidak ditemukan.'], 404);
        }

        $adminOutletId = isset($_SESSION['user']['outlet_id']) ? (int) $_SESSION['user']['outlet_id'] : null;
        if ($id === $adminOutletId || (string) $outlet['name'] === 'Akurata Administrator') {
            json_response(['error' => 'Toko administrator tidak boleh dihapus.'], 422);
        }

        if (isset($_SESSION['impersonation']['outlet_id']) && (int) $_SESSION['impersonation']['outlet_id'] === $id) {
            unset($_SESSION['impersonation']);
        }

        administrator_delete_outlet($pdo, $id);

        audit_log($pdo, null, $adminId, 'admin_delete_outlet', 'outlet', $id, 'Administrator menghapus toko.', [
            'outlet_name' => $outlet['name'],
        ]);

        json_response(['message' => 'Toko dan seluruh datanya berhasil dihapus.']);
    }

    json_response(['error' => 'Tipe hapus tidak dikenal.'], 422);
}

json_response(['error' => 'Method tidak didukung.'], 405);
