<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$pdo = db();

// Update sold_at year to 2026 for all transactions in this outlet
$stmt = $pdo->prepare("
    UPDATE transactions
    SET sold_at = DATE_ADD(sold_at, INTERVAL (2026 - YEAR(sold_at)) YEAR)
    WHERE outlet_id = :outlet_id AND YEAR(sold_at) <> 2026
");
$stmt->execute([':outlet_id' => $outletId]);
$affected = $stmt->rowCount();

json_response([
    'message' => "Berhasil mengubah {$affected} transaksi ke tahun 2026.",
    'affected_rows' => $affected,
]);
