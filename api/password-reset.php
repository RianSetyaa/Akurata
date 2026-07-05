<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('POST');

$data = read_json();
$action = (string) ($data['action'] ?? 'request');
$pdo = db();

if (!table_column_exists($pdo, 'pending_password_resets', 'id')) {
    json_response(['error' => 'Migration reset password belum dijalankan.'], 409);
}

if ($action === 'request') {
    $email = strtolower(trim((string) ($data['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'Email tidak valid.'], 422);
    }

    $pdo->prepare("DELETE FROM pending_password_resets WHERE expires_at < NOW() OR used_at IS NOT NULL")->execute();

    $recentStmt = $pdo->prepare("
        SELECT id
        FROM pending_password_resets
        WHERE email = :email
          AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        LIMIT 1
    ");
    $recentStmt->execute([':email' => $email]);
    if ($recentStmt->fetch()) {
        json_response(['error' => 'Tunggu 1 menit sebelum meminta OTP baru.'], 429);
    }

    $activeFilter = table_column_exists($pdo, 'users', 'is_active') ? 'AND is_active = 1' : '';
    $stmt = $pdo->prepare("
        SELECT id, name, email
        FROM users
        WHERE email = :email
          {$activeFilter}
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO pending_password_resets (
          user_id, email, otp_hash, attempts, expires_at
        )
        VALUES (
          :user_id, :email, :otp_hash, 0, DATE_ADD(NOW(), INTERVAL 30 MINUTE)
        )
    ");
    $stmt->execute([
        ':user_id' => $user ? (int) $user['id'] : null,
        ':email' => $email,
        ':otp_hash' => $otpHash,
    ]);
    $pendingId = (int) $pdo->lastInsertId();

    if ($user) {
        try {
            $safeName = htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8');
            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            brevo_send_email(
                $email,
                (string) $user['name'],
                'Reset password Akurata POS',
                "
                    <div style=\"font-family:Arial,sans-serif;line-height:1.6;color:#111827\">
                      <p>Halo {$safeName},</p>
                      <p>Kode OTP untuk mengatur ulang password Akurata POS:</p>
                      <p style=\"font-size:28px;font-weight:700;letter-spacing:6px;margin:18px 0\">{$safeOtp}</p>
                      <p>Kode berlaku 30 menit. Abaikan email ini jika Anda tidak meminta reset password.</p>
                    </div>
                ",
                "Kode OTP reset password Akurata POS: {$otp}. Kode berlaku 30 menit."
            );
        } catch (Throwable $error) {
            $cleanup = $pdo->prepare("DELETE FROM pending_password_resets WHERE id = :id");
            $cleanup->execute([':id' => $pendingId]);
            json_response(['error' => $error->getMessage()], 500);
        }
    }

    json_response([
        'message' => 'Jika email terdaftar, kode OTP sudah dikirim dan berlaku 30 menit.',
        'pending_id' => $pendingId,
        'requires_otp' => true,
    ], 202);
}

if ($action === 'verify') {
    $pendingId = (int) ($data['pending_id'] ?? 0);
    $otp = preg_replace('/\D+/', '', (string) ($data['otp'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');

    if ($pendingId <= 0 || strlen($otp) !== 6) {
        json_response(['error' => 'Kode OTP harus 6 digit.'], 422);
    }

    if (strlen($password) < 6) {
        json_response(['error' => 'Password baru minimal 6 karakter.'], 422);
    }

    if ($password !== $passwordConfirmation) {
        json_response(['error' => 'Konfirmasi password baru tidak sama.'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT *,
               expires_at < NOW() AS is_expired
        FROM pending_password_resets
        WHERE id = :id
          AND used_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $pendingId]);
    $pending = $stmt->fetch();

    if (!$pending || !$pending['user_id']) {
        json_response(['error' => 'Kode OTP tidak valid atau akun tidak ditemukan.'], 422);
    }

    if ((int) ($pending['is_expired'] ?? 0) === 1) {
        json_response(['error' => 'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.'], 410);
    }

    if ((int) $pending['attempts'] >= 5) {
        json_response(['error' => 'Percobaan OTP terlalu banyak. Silakan minta kode baru.'], 429);
    }

    if (!password_verify($otp, (string) $pending['otp_hash'])) {
        $stmt = $pdo->prepare("UPDATE pending_password_resets SET attempts = attempts + 1 WHERE id = :id");
        $stmt->execute([':id' => $pendingId]);
        json_response(['error' => 'Kode OTP salah.'], 422);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :id");
        $stmt->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => (int) $pending['user_id'],
        ]);

        $stmt = $pdo->prepare("UPDATE pending_password_resets SET used_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $pendingId]);
        $pdo->commit();

        $userStmt = $pdo->prepare("SELECT outlet_id FROM users WHERE id = :id LIMIT 1");
        $userStmt->execute([':id' => (int) $pending['user_id']]);
        $user = $userStmt->fetch();
        audit_log(
            $pdo,
            $user ? (int) $user['outlet_id'] : null,
            (int) $pending['user_id'],
            'password_reset',
            'auth',
            (int) $pending['user_id'],
            'Password direset melalui OTP email.'
        );

        json_response(['message' => 'Password berhasil diubah. Silakan masuk dengan password baru.']);
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['error' => 'Password tidak bisa diperbarui.'], 500);
    }
}

json_response(['error' => 'Action reset password tidak dikenal.'], 422);
