<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Load helpers first before any output to prevent headers already sent warnings
try {
    require_once __DIR__ . '/helpers.php';
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FAILED TO LOAD helpers.php:\n";
    echo $e->getMessage() . "\n";
    exit;
}

// Now we can output text safely if we override the JSON header set by helpers.php
header('Content-Type: text/plain; charset=utf-8');

echo "--- Debugging me.php ---\n\n";
echo "PHP OS: " . PHP_OS . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'not set') . "\n";
echo "SERVER_ADDR: " . ($_SERVER['SERVER_ADDR'] ?? 'not set') . "\n\n";

echo "Current Session User: " . (isset($_SESSION['user']) ? print_r($_SESSION['user'], true) : 'Not set') . "\n\n";

try {
    echo "Running require_auth()...\n";
    $user = require_auth();
    echo "require_auth() success!\n";
    echo "User data:\n";
    print_r($user);
} catch (Throwable $e) {
    echo "ERROR OCCURRED:\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
