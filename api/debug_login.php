<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "--- Debugging login.php ---\n\n";
echo "PHP Version: " . PHP_VERSION . "\n\n";

try {
    echo "Step 1: Loading helpers.php...\n";
    require_once __DIR__ . '/helpers.php';
    echo "OK\n\n";

    echo "Step 2: Calling db()...\n";
    $pdo = db();
    echo "OK - Connected to: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

    echo "Step 3: Checking table 'users' exists...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $hasUsers = $stmt->fetchColumn();
    echo "users table: " . ($hasUsers ? "EXISTS" : "NOT FOUND") . "\n\n";

    echo "Step 4: Checking table 'outlets' exists...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'outlets'");
    $hasOutlets = $stmt->fetchColumn();
    echo "outlets table: " . ($hasOutlets ? "EXISTS" : "NOT FOUND") . "\n\n";

    echo "Step 5: Checking table_column_exists('users','is_active')...\n";
    $hasIsActive = table_column_exists($pdo, 'users', 'is_active');
    echo "users.is_active column: " . ($hasIsActive ? "EXISTS" : "NOT FOUND") . "\n\n";

    echo "Step 6: Checking activity_logs table...\n";
    $hasActivityLogs = activity_logs_table_exists($pdo);
    echo "activity_logs table: " . ($hasActivityLogs ? "EXISTS" : "NOT FOUND") . "\n\n";

    echo "Step 7: Count users...\n";
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "Total users: " . $count . "\n\n";

    echo "--- ALL CHECKS PASSED ---\n";
} catch (Throwable $e) {
    echo "ERROR:\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
