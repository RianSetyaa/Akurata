<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "--- Debugging transactions.php ---\n\n";

try {
    require_once __DIR__ . '/helpers.php';
    echo "Step 1: helpers.php loaded OK\n";

    $pdo = db();
    echo "Step 2: db() connected OK\n";

    $user = current_user();
    echo "Step 3: current_user = " . ($user ? $user['name'] : 'null') . "\n";

    if (!$user) {
        echo "Not logged in, skipping DB queries\n";
        exit;
    }

    $outletId = (int) $user['outlet_id'];
    echo "Step 4: outlet_id = $outletId\n\n";

    // Test query yang mirip dengan transactions.php
    echo "Step 5: Testing transactions query...\n";
    $stmt = $pdo->prepare("
        SELECT t.id, t.invoice_number, t.sold_at, t.grand_total, t.payment_method, t.payment_status
        FROM transactions t
        WHERE t.outlet_id = :outlet_id
        ORDER BY t.sold_at DESC
        LIMIT 5
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $rows = $stmt->fetchAll();
    echo "Found " . count($rows) . " transactions\n";
    if (count($rows) > 0) {
        echo "First row: " . print_r($rows[0], true) . "\n";
    }

    echo "\n--- ALL OK ---\n";
} catch (Throwable $e) {
    echo "\nERROR:\n";
    echo get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
