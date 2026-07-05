<?php
/**
 * Test wrapper: Simulasi seolah-olah PDO tidak ada,
 * lalu jalankan operasi database menggunakan wrapper.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "--- Testing PDO Wrapper via MySQLi ---\n\n";

// Force load wrapper
require_once __DIR__ . '/pdo_wrapper.php';

echo "PDO class exists: " . (class_exists('PDO') ? 'YES' : 'NO') . "\n";
echo "PDOStatement class exists: " . (class_exists('PDOStatement') ? 'YES' : 'NO') . "\n\n";

try {
    // Deteksi environment
    $isLocal = (PHP_OS === 'Darwin');
    
    if ($isLocal) {
        $dsn = "mysql:host=127.0.0.1;port=3306;dbname=akurata_erp;charset=utf8mb4";
        $user = 'root';
        $pass = '';
    } else {
        $dsn = "mysql:host=localhost;port=3306;dbname=zzckthsk_akurata;charset=utf8mb4";
        $user = 'zzckthsk_user';
        $pass = '@Jajay2134567';
    }

    echo "Step 1: new PDO()...\n";
    $pdo = new PDO($dsn, $user, $pass);
    echo "OK\n\n";

    echo "Step 2: query()...\n";
    $stmt = $pdo->query("SELECT DATABASE() AS db, NOW() AS time");
    $row = $stmt->fetch();
    echo "Database: " . $row['db'] . "\n";
    echo "Time: " . $row['time'] . "\n\n";

    echo "Step 3: prepare() with named params...\n";
    $stmt = $pdo->prepare("SELECT 1 AS test WHERE :val = :val");
    $stmt->execute([':val' => 1]);
    $row = $stmt->fetch();
    echo "Result: " . print_r($row, true) . "\n";

    echo "Step 4: fetchAll()...\n";
    $stmt = $pdo->query("SELECT 'hello' AS greeting UNION SELECT 'world'");
    $rows = $stmt->fetchAll();
    echo "Rows: " . count($rows) . "\n";
    echo print_r($rows, true) . "\n";

    echo "Step 5: fetchColumn()...\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables LIMIT 1");
    $count = $stmt->fetchColumn();
    echo "Count: " . $count . "\n\n";

    echo "Step 6: lastInsertId()...\n";
    $id = $pdo->lastInsertId();
    echo "Last ID: " . $id . "\n\n";

    echo "Step 7: beginTransaction/commit...\n";
    $pdo->beginTransaction();
    $pdo->commit();
    echo "OK\n\n";

    echo "Step 8: rowCount()...\n";
    $stmt = $pdo->prepare("SELECT 1 WHERE 1=0");
    $stmt->execute();
    echo "rowCount: " . $stmt->rowCount() . "\n\n";

    echo "--- ALL TESTS PASSED ---\n";
} catch (Throwable $e) {
    echo "ERROR:\n";
    echo get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
