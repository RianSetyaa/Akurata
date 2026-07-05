<?php
declare(strict_types=1);

// Load PDO wrapper jika ekstensi PDO tidak tersedia (gunakan MySQLi sebagai pengganti)
if (!extension_loaded('pdo')) {
    require_once __DIR__ . '/pdo_wrapper.php';
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Deteksi otomatis jika berjalan di lokal (macOS atau localhost)
    $isLocal = (PHP_OS === 'Darwin')
        || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000', 'localhost:3000'], true)
        || (isset($_SERVER['SERVER_ADDR']) && in_array($_SERVER['SERVER_ADDR'], ['127.0.0.1', '::1'], true));

    if ($isLocal) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'akurata_erp';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
    } else {
        // Kredensial untuk hosting production (akurata.my.id)
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'zzckthsk_akurata';
        $user = getenv('DB_USER') ?: 'zzckthsk_user';
        $pass = getenv('DB_PASS') ?: '@Jajay2134567';
    }

    $timeout = (int) (getenv('DB_TIMEOUT') ?: 5);
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ]);

    return $pdo;
}

function app_url(string $path = ''): string
{
    $base = rtrim(getenv('APP_URL') ?: 'https://akurata.my.id', '/');
    return $base . '/' . ltrim($path, '/');
}

function loyverse_config(): array
{
    return [
        'client_id' => getenv('LOYVERSE_CLIENT_ID') ?: 'abKlIVWKa6pl4SYriOWY',
        'client_secret' => getenv('LOYVERSE_CLIENT_SECRET') ?: '77BgFiSinmZYINEL2d5VxkkLmdSnFh5vL3O_TgpdnCYarVMDZGZjSw==',
        'redirect_uri' => getenv('LOYVERSE_REDIRECT_URI') ?: app_url('api/loyverse.php?action=callback'),
        'authorize_url' => 'https://api.loyverse.com/oauth/authorize',
        'token_url' => 'https://api.loyverse.com/oauth/token',
        'api_base' => 'https://api.loyverse.com/v1.0',
        'scope' => getenv('LOYVERSE_SCOPE') ?: 'OPENID MERCHANT_READ STORES_READ ITEMS_READ ITEMS_WRITE INVENTORY_READ INVENTORY_WRITE TAXES_READ TAXES_WRITE RECEIPTS_READ RECEIPTS_WRITE PAYMENT_TYPES_READ SUPPLIERS_READ',
    ];
}

function brevo_config(): array
{
    $localSecrets = [];
    $localSecretsPath = __DIR__ . '/secrets.php';

    if (is_file($localSecretsPath)) {
        $loadedSecrets = require $localSecretsPath;
        if (is_array($loadedSecrets)) {
            $localSecrets = $loadedSecrets;
        }
    }

    return [
        'api_key' => getenv('BREVO_API_KEY') ?: ($localSecrets['BREVO_API_KEY'] ?? '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'),
        'api_url' => getenv('BREVO_API_URL') ?: ($localSecrets['BREVO_API_URL'] ?? 'https://api.brevo.com/v3/smtp/email'),
        'sender_email' => getenv('BREVO_SENDER_EMAIL') ?: ($localSecrets['BREVO_SENDER_EMAIL'] ?? 'noreply@akurata.my.id'),
        'sender_name' => getenv('BREVO_SENDER_NAME') ?: ($localSecrets['BREVO_SENDER_NAME'] ?? 'Akurata POS'),
    ];
}

function fonnte_config(): array
{
    $localSecrets = [];
    $localSecretsPath = __DIR__ . '/secrets.php';

    if (is_file($localSecretsPath)) {
        $loadedSecrets = require $localSecretsPath;
        if (is_array($loadedSecrets)) {
            $localSecrets = $loadedSecrets;
        }
    }

    return [
        'token' => getenv('FONNTE_TOKEN') ?: ($localSecrets['FONNTE_TOKEN'] ?? ''),
        'api_url' => getenv('FONNTE_API_URL') ?: ($localSecrets['FONNTE_API_URL'] ?? 'https://api.fonnte.com/send'),
    ];
}
