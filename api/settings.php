<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$pdo = db();

function settings_payload(PDO $pdo, int $userId, int $outletId): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, email, role
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $profile = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT id, name, address, logo_path, billing_bank_name, billing_account_name, billing_account_number,
               tax_enabled, tax_rate, quotation_enabled, whatsapp_number
        FROM outlets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $outletId]);
    $business = $stmt->fetch();

    return [
        'profile' => $profile ? [
            'id' => (int) $profile['id'],
            'name' => $profile['name'],
            'email' => $profile['email'],
            'role' => normalize_role((string) $profile['role']),
        ] : null,
        'business' => $business ? [
            'id' => (int) $business['id'],
            'name' => $business['name'],
            'address' => $business['address'],
            'logo_path' => $business['logo_path'],
            'billing_bank_name' => $business['billing_bank_name'] ?? null,
            'billing_account_name' => $business['billing_account_name'] ?? null,
            'billing_account_number' => $business['billing_account_number'] ?? null,
            'tax_enabled' => (int) ($business['tax_enabled'] ?? 0),
            'tax_rate' => (float) ($business['tax_rate'] ?? 0),
            'quotation_enabled' => (int) ($business['quotation_enabled'] ?? 1),
            'whatsapp_number' => $business['whatsapp_number'] ?? null,
        ] : null,
    ];
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Logo melebihi batas upload server.',
        UPLOAD_ERR_PARTIAL => 'Upload logo tidak lengkap.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary upload server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server tidak bisa menulis file upload.',
        UPLOAD_ERR_EXTENSION => 'Upload logo diblokir ekstensi PHP.',
        default => 'Upload logo gagal.',
    };
}

function uploaded_image_mime(string $path): ?string
{
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    $imageInfo = @getimagesize($path);
    return is_array($imageInfo) && isset($imageInfo['mime']) ? (string) $imageInfo['mime'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(settings_payload($pdo, $userId, $outletId));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            json_response(['error' => 'Nama profil wajib diisi.'], 422);
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET name = :name
            WHERE id = :id
              AND outlet_id = :outlet_id
        ");
        $stmt->execute([
            ':name' => $name,
            ':id' => $userId,
            ':outlet_id' => $outletId,
        ]);

        $_SESSION['user']['name'] = $name;
        audit_log($pdo, $outletId, $userId, 'profile_update', 'user', $userId, 'Profil user diperbarui.', [
            'name' => $name,
        ]);
        json_response(['message' => 'Profil berhasil diperbarui.', ...settings_payload($pdo, $userId, $outletId)]);
    }

    if ($action === 'password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $passwordConfirmation === '') {
            json_response(['error' => 'Password saat ini dan password baru wajib diisi.'], 422);
        }

        if (strlen($newPassword) < 6) {
            json_response(['error' => 'Password baru minimal 6 karakter.'], 422);
        }

        if ($newPassword !== $passwordConfirmation) {
            json_response(['error' => 'Konfirmasi password baru tidak sama.'], 422);
        }

        $stmt = $pdo->prepare("
            SELECT password_hash
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
            json_response(['error' => 'Password saat ini salah.'], 422);
        }

        if (password_verify($newPassword, (string) $account['password_hash'])) {
            json_response(['error' => 'Password baru tidak boleh sama dengan password saat ini.'], 422);
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :password_hash
            WHERE id = :id
        ");
        $stmt->execute([
            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $userId,
        ]);

        session_regenerate_id(true);
        audit_log($pdo, $outletId, $userId, 'password_change', 'auth', $userId, 'Password diubah dari halaman profil.');
        json_response(['message' => 'Password berhasil diubah.']);
    }

    if ($action === 'business') {
        deny_manager_access('Profil Bisnis');

        $name = trim((string) ($_POST['name'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $billingBankName = trim((string) ($_POST['billing_bank_name'] ?? ''));
        $billingAccountName = trim((string) ($_POST['billing_account_name'] ?? ''));
        $billingAccountNumber = trim((string) ($_POST['billing_account_number'] ?? ''));
        $taxEnabled = isset($_POST['tax_enabled']) ? 1 : 0;
        $taxRate = max(0, (float) ($_POST['tax_rate'] ?? 0));
        $quotationEnabled = isset($_POST['quotation_enabled']) ? 1 : 0;
        $whatsappNumber = trim((string) ($_POST['whatsapp_number'] ?? ''));

        if ($name === '') {
            json_response(['error' => 'Nama usaha wajib diisi.'], 422);
        }

        if ($taxRate > 100) {
            json_response(['error' => 'Persen pajak maksimal 100%.'], 422);
        }

        $logoPath = null;
        if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadError = (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_OK);
            if ($uploadError !== UPLOAD_ERR_OK) {
                json_response(['error' => upload_error_message($uploadError)], 422);
            }

            $size = (int) ($_FILES['logo']['size'] ?? 0);
            if ($size > 2 * 1024 * 1024) {
                json_response(['error' => 'Logo maksimal 2MB.'], 422);
            }

            $tmpName = (string) ($_FILES['logo']['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                json_response(['error' => 'File logo tidak valid.'], 422);
            }

            $mime = uploaded_image_mime($tmpName);
            $extensions = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];

            if (!isset($extensions[$mime])) {
                json_response(['error' => 'Logo harus PNG, JPG, atau WEBP.'], 422);
            }

            $uploadDir = dirname(__DIR__) . '/uploads/logos';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                json_response(['error' => 'Folder upload logo tidak bisa dibuat.'], 500);
            }

            $filename = 'outlet-' . $outletId . '-' . time() . '.' . $extensions[$mime];
            $target = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($tmpName, $target)) {
                json_response(['error' => 'Logo tidak bisa disimpan.'], 500);
            }

            $logoPath = 'uploads/logos/' . $filename;
        }

        if ($logoPath) {
            $stmt = $pdo->prepare("
                UPDATE outlets
                SET name = :name,
                    address = :address,
                    logo_path = :logo_path,
                    billing_bank_name = :billing_bank_name,
                    billing_account_name = :billing_account_name,
                    billing_account_number = :billing_account_number,
                    tax_enabled = :tax_enabled,
                    tax_rate = :tax_rate,
                    quotation_enabled = :quotation_enabled,
                    whatsapp_number = :whatsapp_number
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':address' => $address,
                ':logo_path' => $logoPath,
                ':billing_bank_name' => $billingBankName,
                ':billing_account_name' => $billingAccountName,
                ':billing_account_number' => $billingAccountNumber,
                ':tax_enabled' => $taxEnabled,
                ':tax_rate' => $taxRate,
                ':quotation_enabled' => $quotationEnabled,
                ':whatsapp_number' => $whatsappNumber !== '' ? $whatsappNumber : null,
                ':id' => $outletId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE outlets
                SET name = :name,
                    address = :address,
                    billing_bank_name = :billing_bank_name,
                    billing_account_name = :billing_account_name,
                    billing_account_number = :billing_account_number,
                    tax_enabled = :tax_enabled,
                    tax_rate = :tax_rate,
                    quotation_enabled = :quotation_enabled,
                    whatsapp_number = :whatsapp_number
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':address' => $address,
                ':billing_bank_name' => $billingBankName,
                ':billing_account_name' => $billingAccountName,
                ':billing_account_number' => $billingAccountNumber,
                ':tax_enabled' => $taxEnabled,
                ':tax_rate' => $taxRate,
                ':quotation_enabled' => $quotationEnabled,
                ':whatsapp_number' => $whatsappNumber !== '' ? $whatsappNumber : null,
                ':id' => $outletId,
            ]);
        }

        $loyverseWarning = null;
        try {
            if ($taxEnabled === 1 && $taxRate > 0) {
                loyverse_sync_outlet_tax($pdo, $outletId);
            } else {
                loyverse_disable_outlet_tax($pdo, $outletId);
            }
        } catch (Throwable $error) {
            $loyverseWarning = $error->getMessage();
        }

        $_SESSION['user']['outlet_name'] = $name;
        audit_log($pdo, $outletId, $userId, 'business_update', 'outlet', $outletId, 'Profil bisnis diperbarui.', [
            'name' => $name,
            'tax_enabled' => $taxEnabled,
            'tax_rate' => $taxRate,
            'quotation_enabled' => $quotationEnabled,
            'loyverse_warning' => $loyverseWarning,
        ]);
        json_response([
            'message' => $loyverseWarning
                ? 'Profil bisnis berhasil diperbarui, tapi sinkron pajak Loyverse gagal.'
                : 'Profil bisnis berhasil diperbarui.',
            'loyverse_warning' => $loyverseWarning,
            ...settings_payload($pdo, $userId, $outletId),
        ]);
    }

    json_response(['error' => 'Action pengaturan tidak dikenal.'], 422);
}

json_response(['error' => 'Method tidak didukung.'], 405);
