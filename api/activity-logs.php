<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('GET');

$user = require_owner_or_administrator();
$outletId = (int) $user['outlet_id'];
$pdo = db();

if (!activity_logs_table_exists($pdo)) {
    json_response([
        'error' => 'Migration log aktivitas belum dijalankan. Jalankan database/migrations/2026_06_05_activity_logs.sql di database aaPanel.',
        'logs' => [],
    ], 409);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM activity_logs
    WHERE outlet_id = :outlet_id
");
$countStmt->execute([':outlet_id' => $outletId]);
$total = (int) ($countStmt->fetch()['total'] ?? 0);

$stmt = $pdo->prepare("
    SELECT al.id, al.action, al.entity_type, al.entity_id, al.description,
           al.metadata, al.ip_address, al.created_at, u.name AS user_name
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.outlet_id = :outlet_id
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute([':outlet_id' => $outletId]);

json_response([
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => max(1, (int) ceil($total / $perPage)),
    ],
    'logs' => array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'action' => $row['action'],
        'entity_type' => $row['entity_type'],
        'entity_id' => $row['entity_id'],
        'description' => $row['description'],
        'metadata' => $row['metadata'] ? json_decode((string) $row['metadata'], true) : null,
        'ip_address' => $row['ip_address'],
        'user_name' => $row['user_name'] ?? 'Sistem',
        'created_at' => $row['created_at'],
    ], $stmt->fetchAll()),
]);
