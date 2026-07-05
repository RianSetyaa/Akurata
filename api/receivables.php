<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

function receivable_status(float $amount, float $paidAmount): string
{
    if ($paidAmount >= $amount) {
        return 'paid';
    }

    return $paidAmount > 0 ? 'partial' : 'open';
}

function record_receivable_payment(PDO $pdo, int $outletId, int $userId, int $id, float $amount, string $method, ?string $notes): array
{
    if ($id <= 0) {
        throw new RuntimeException('ID piutang wajib valid.');
    }

    if ($amount <= 0) {
        throw new RuntimeException('Nominal pembayaran wajib lebih dari 0.');
    }

    if (!in_array($method, ['cash', 'qris', 'transfer'], true)) {
        throw new RuntimeException('Metode pembayaran tidak valid.');
    }

    $stmt = $pdo->prepare("
        SELECT r.id, r.transaction_id, r.amount, r.paid_amount, r.status
        FROM receivables r
        WHERE r.id = :id
          AND r.outlet_id = :outlet_id
        FOR UPDATE
    ");
    $stmt->execute([
        ':id' => $id,
        ':outlet_id' => $outletId,
    ]);
    $receivable = $stmt->fetch();

    if (!$receivable) {
        throw new RuntimeException('Piutang tidak ditemukan.');
    }

    $total = (float) $receivable['amount'];
    $paid = (float) $receivable['paid_amount'];
    $remaining = max(0, $total - $paid);

    if ($remaining <= 0) {
        throw new RuntimeException('Piutang sudah lunas.');
    }

    if ($amount > $remaining) {
        throw new RuntimeException('Nominal pembayaran melebihi sisa piutang.');
    }

    $paymentNo = receivable_payment_number($pdo, $outletId);
    $stmt = $pdo->prepare("
        INSERT INTO receivable_payments (outlet_id, receivable_id, user_id, payment_no, payment_method, amount, notes)
        VALUES (:outlet_id, :receivable_id, :user_id, :payment_no, :payment_method, :amount, :notes)
    ");
    $stmt->execute([
        ':outlet_id' => $outletId,
        ':receivable_id' => $id,
        ':user_id' => $userId,
        ':payment_no' => $paymentNo,
        ':payment_method' => $method,
        ':amount' => $amount,
        ':notes' => $notes,
    ]);

    $paymentId = (int) $pdo->lastInsertId();
    $newPaid = $paid + $amount;
    $newStatus = receivable_status($total, $newPaid);

    $stmt = $pdo->prepare("
        UPDATE receivables
        SET paid_amount = :paid_amount,
            status = :status,
            paid_at = CASE WHEN :status_paid = 'paid' THEN NOW() ELSE paid_at END
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");
    $stmt->execute([
        ':paid_amount' => $newPaid,
        ':status' => $newStatus,
        ':status_paid' => $newStatus,
        ':id' => $id,
        ':outlet_id' => $outletId,
    ]);

    if ($newStatus === 'paid') {
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET payment_status = 'paid'
            WHERE id = :transaction_id
              AND outlet_id = :outlet_id
        ");
        $stmt->execute([
            ':transaction_id' => (int) $receivable['transaction_id'],
            ':outlet_id' => $outletId,
        ]);
    }

    audit_log($pdo, $outletId, $userId, 'receivable_payment', 'receivable', $id, 'Pembayaran piutang dicatat.', [
        'payment_no' => $paymentNo,
        'payment_method' => $method,
        'amount' => money($amount),
        'status' => $newStatus,
    ]);

    return [
        'payment_id' => $paymentId,
        'payment_no' => $paymentNo,
        'status' => $newStatus,
        'paid_amount' => money($newPaid),
        'remaining_amount' => money(max(0, $total - $newPaid)),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $detailId = (int) ($_GET['id'] ?? 0);

    if ($detailId > 0) {
        $stmt = $pdo->prepare("
            SELECT r.id, r.transaction_id, t.invoice_no, c.name AS customer, c.phone AS customer_phone,
                   r.amount, r.paid_amount, (r.amount - r.paid_amount) AS remaining_amount,
                   r.due_date, r.status, r.paid_at, r.created_at
            FROM receivables r
            JOIN transactions t ON t.id = r.transaction_id
            LEFT JOIN customers c ON c.id = r.customer_id
            WHERE r.id = :id
              AND r.outlet_id = :outlet_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $detailId,
            ':outlet_id' => $outletId,
        ]);
        $receivable = $stmt->fetch();

        if (!$receivable) {
            json_response(['error' => 'Piutang tidak ditemukan.'], 404);
        }

        $paymentStmt = $pdo->prepare("
            SELECT rp.id, rp.payment_no, rp.payment_method, rp.amount, rp.notes, rp.paid_at, u.name AS cashier
            FROM receivable_payments rp
            JOIN users u ON u.id = rp.user_id
            WHERE rp.receivable_id = :id
              AND rp.outlet_id = :outlet_id
            ORDER BY rp.paid_at ASC, rp.id ASC
        ");
        $paymentStmt->execute([
            ':id' => $detailId,
            ':outlet_id' => $outletId,
        ]);

        json_response([
            'receivable' => [
                'id' => (int) $receivable['id'],
                'transaction_id' => (int) $receivable['transaction_id'],
                'invoice_no' => $receivable['invoice_no'],
                'customer' => $receivable['customer'] ?? 'Pelanggan tempo',
                'customer_phone' => $receivable['customer_phone'],
                'amount' => money($receivable['amount']),
                'paid_amount' => money($receivable['paid_amount']),
                'remaining_amount' => money($receivable['remaining_amount']),
                'due_date' => $receivable['due_date'],
                'status' => $receivable['status'],
                'paid_at' => $receivable['paid_at'],
                'created_at' => $receivable['created_at'],
            ],
            'payments' => array_map(fn ($row) => [
                'id' => (int) $row['id'],
                'payment_no' => $row['payment_no'],
                'payment_method' => $row['payment_method'],
                'amount' => money($row['amount']),
                'notes' => $row['notes'],
                'paid_at' => $row['paid_at'],
                'cashier' => $row['cashier'],
            ], $paymentStmt->fetchAll()),
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT r.id, t.invoice_no, c.name AS customer, r.amount, r.paid_amount,
               (r.amount - r.paid_amount) AS remaining_amount, r.due_date, r.status, r.paid_at,
               MAX(rp.id) AS last_payment_id
        FROM receivables r
        JOIN transactions t ON t.id = r.transaction_id
        LEFT JOIN customers c ON c.id = r.customer_id
        LEFT JOIN receivable_payments rp ON rp.receivable_id = r.id
        WHERE r.outlet_id = :outlet_id
        GROUP BY r.id, t.invoice_no, c.name, r.amount, r.paid_amount, r.due_date, r.status, r.paid_at
        ORDER BY FIELD(r.status, 'open', 'partial', 'paid'), r.due_date ASC, r.id DESC
    ");
    $stmt->execute([':outlet_id' => $outletId]);

    json_response(['receivables' => array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'invoice_no' => $row['invoice_no'],
        'customer' => $row['customer'] ?? 'Pelanggan tempo',
        'amount' => money($row['amount']),
        'paid_amount' => money($row['paid_amount']),
        'remaining_amount' => money($row['remaining_amount']),
        'due_date' => $row['due_date'],
        'status' => $row['status'],
        'paid_at' => $row['paid_at'],
        'last_payment_id' => $row['last_payment_id'] ? (int) $row['last_payment_id'] : null,
    ], $stmt->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();

    if (($data['action'] ?? '') === 'terms') {
        $id = (int) ($data['id'] ?? 0);
        $dueDate = trim((string) ($data['due_date'] ?? ''));

        if ($id <= 0) {
            json_response(['error' => 'ID piutang wajib valid.'], 422);
        }

        if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            json_response(['error' => 'Tanggal jatuh tempo tidak valid.'], 422);
        }

        $stmt = $pdo->prepare("
            UPDATE receivables
            SET due_date = :due_date
            WHERE id = :id
              AND outlet_id = :outlet_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':outlet_id' => $outletId,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
        ]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'Piutang tidak ditemukan.'], 404);
        }

        audit_log($pdo, $outletId, $userId, 'receivable_terms_update', 'receivable', $id, 'Jatuh tempo piutang diperbarui.', [
            'due_date' => $dueDate !== '' ? $dueDate : null,
        ]);

        json_response([
            'message' => 'Jatuh tempo piutang berhasil diperbarui.',
            'due_date' => $dueDate !== '' ? $dueDate : null,
        ]);
    }

    $pdo->beginTransaction();
    try {
        $result = record_receivable_payment(
            $pdo,
            $outletId,
            $userId,
            (int) ($data['id'] ?? 0),
            (float) ($data['amount'] ?? 0),
            (string) ($data['payment_method'] ?? 'cash'),
            trim((string) ($data['notes'] ?? '')) ?: null
        );
        $pdo->commit();

        json_response([
            'message' => 'Pembayaran piutang berhasil disimpan.',
            ...$result,
        ], 201);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = read_json();
    $id = (int) ($data['id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT amount, paid_amount
        FROM receivables
        WHERE id = :id
          AND outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $id,
        ':outlet_id' => $outletId,
    ]);
    $receivable = $stmt->fetch();

    if (!$receivable) {
        json_response(['error' => 'Piutang tidak ditemukan.'], 404);
    }

    $remaining = max(0, (float) $receivable['amount'] - (float) $receivable['paid_amount']);

    $pdo->beginTransaction();
    try {
        $result = record_receivable_payment(
            $pdo,
            $outletId,
            $userId,
            $id,
            $remaining,
            (string) ($data['payment_method'] ?? 'cash'),
            trim((string) ($data['notes'] ?? 'Pelunasan piutang')) ?: null
        );
        $pdo->commit();

        json_response([
            'message' => 'Piutang ditandai lunas.',
            ...$result,
        ]);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

json_response(['error' => 'Method tidak didukung.'], 405);
