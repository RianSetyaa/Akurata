<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$pdo = db();

// Update purchased_at and received_at year to 2026 for all purchases in this outlet
$stmt = $pdo->prepare("
    UPDATE purchases
    SET purchased_at = DATE_ADD(purchased_at, INTERVAL (2026 - YEAR(purchased_at)) YEAR),
        received_at = CASE 
            WHEN received_at IS NOT NULL THEN DATE_ADD(received_at, INTERVAL (2026 - YEAR(received_at)) YEAR)
            ELSE NULL 
        END
    WHERE outlet_id = :outlet_id AND (YEAR(purchased_at) <> 2026 OR YEAR(received_at) <> 2026)
");
$stmt->execute([':outlet_id' => $outletId]);
$affected = $stmt->rowCount();

json_response([
    'message' => "Berhasil mengubah {$affected} purchase order ke tahun 2026.",
    'affected_rows' => $affected,
]);
