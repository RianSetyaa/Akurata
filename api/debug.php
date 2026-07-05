<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "--- Debugging Database Connection ---\n\n";

echo "PHP OS: " . PHP_OS . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'not set') . "\n";
echo "SERVER_ADDR: " . ($_SERVER['SERVER_ADDR'] ?? 'not set') . "\n\n";

try {
    echo "Including config.php...\n";
    require_once __DIR__ . '/config.php';
    echo "config.php included successfully.\n\n";

    echo "Attempting to connect to database using db()...\n";
    $pdo = db();
    echo "SUCCESS: Connected to database successfully!\n";
    
    $stmt = $pdo->query("SELECT DATABASE() AS db, NOW() AS time");
    $row = $stmt->fetch();
    echo "Database Name: " . $row['db'] . "\n";
    echo "Server Time: " . $row['time'] . "\n";
} catch (Throwable $e) {
    echo "ERROR OCCURRED:\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
