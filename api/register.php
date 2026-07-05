<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('POST');

$data = read_json();

$name = trim((string) ($data['name'] ?? ''));
$identity = trim((string) ($data['identity'] ?? ''));
$password = (string) ($data['password'] ?? '');
$business = trim((string) ($data['business'] ?? ''));
$outlets = trim((string) ($data['outlets'] ?? '1'));
$plan = trim((string) ($data['plan'] ?? 'basic'));

if ($name === '' || $identity === '' || $password === '' || $business === '') {
    json_response(['error' => 'Nama, kontak, password, dan nama usaha wajib diisi.'], 422);
}

if (!filter_var($identity, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Saat OTP aktif, pendaftaran wajib memakai email valid.'], 422);
}

if (strlen($password) < 6) {
    json_response(['error' => 'Password minimal 6 karakter.'], 422);
}

$pdo = db();
$email = strtolower($identity);
$otpTtlMinutes = 30;

$exists = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$exists->execute([':email' => $email]);
if ($exists->fetch()) {
    json_response(['error' => 'Akun dengan email/nomor tersebut sudah terdaftar.'], 409);
}

$pdo->prepare("DELETE FROM pending_registrations WHERE expires_at < NOW() OR verified_at IS NOT NULL")->execute();

try {
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO pending_registrations (
          name, email, password_hash, business, outlets, plan, otp_hash, attempts, expires_at
        )
        VALUES (
          :name, :email, :password_hash, :business, :outlets, :plan, :otp_hash, 0, DATE_ADD(NOW(), INTERVAL {$otpTtlMinutes} MINUTE)
        )
    ");
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':business' => $business,
        ':outlets' => $outlets,
        ':plan' => $plan,
        ':otp_hash' => $otpHash,
    ]);
    $pendingId = (int) $pdo->lastInsertId();

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    brevo_send_email(
        $email,
        $name,
        'Kode OTP Akurata POS',
        "
            <div style=\"font-family:Arial,sans-serif;line-height:1.6;color:#111827\">
              <p>Halo {$safeName},</p>
              <p>Kode OTP pendaftaran Akurata POS Anda:</p>
              <p style=\"font-size:28px;font-weight:700;letter-spacing:6px;margin:18px 0\">{$safeOtp}</p>
              <p>Kode berlaku {$otpTtlMinutes} menit. Abaikan email ini jika Anda tidak membuat akun.</p>
            </div>
        ",
        "Kode OTP Akurata POS Anda: {$otp}. Kode berlaku {$otpTtlMinutes} menit."
    );

    json_response([
        'message' => "Kode OTP sudah dikirim ke email dan berlaku {$otpTtlMinutes} menit.",
        'requires_otp' => true,
        'pending_id' => $pendingId,
        'email' => $email,
        'verify_api' => 'api/verify-register-otp.php',
    ], 202);
} catch (Throwable $error) {
    if (isset($pendingId)) {
        $cleanup = $pdo->prepare("DELETE FROM pending_registrations WHERE id = :id");
        $cleanup->execute([':id' => $pendingId]);
    }
    json_response(['error' => $error->getMessage()], 500);
}
