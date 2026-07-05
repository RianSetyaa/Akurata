<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('GET');

$start = microtime(true);

try {
    $pdo = db();
    $stmt = $pdo->query('SELECT DATABASE() AS db_name, NOW() AS server_time');
    $row = $stmt->fetch();

    json_response([
        'ok' => true,
        'database' => $row['db_name'],
        'server_time' => $row['server_time'],
        'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
    ]);
} catch (Throwable $error) {
    json_response([
        'ok' => false,
        'error' => $error->getMessage(),
        'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
    ], 500);
}
