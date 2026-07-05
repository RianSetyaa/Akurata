<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// Ini adalah script uji coba (test) untuk memastikan Fonnte berjalan.
// Pastikan menghapus file ini nanti jika sudah selesai dicoba di production.

$config = fonnte_config();
if (empty($config['token']) || $config['token'] === 'ISI_TOKEN_FONNTE_ANDA_DISINI') {
    die("Token Fonnte belum diatur dengan benar di secrets.php");
}

$target = $_GET['wa'] ?? '';
if (empty($target)) {
    die("Tambahkan nomor WA di URL. Contoh: test_wa.php?wa=081234567890");
}

echo "Mencoba mengirim pesan ke: " . htmlspecialchars($target) . "<br>";
echo "Menggunakan URL API: " . htmlspecialchars($config['api_url']) . "<br>";
echo "Token: " . substr($config['token'], 0, 5) . "*****<br><br>";

$data = [
    'target' => $target,
    'message' => 'Tes pesan dari sistem Akurata POS!',
];

$curl = curl_init($config['api_url']);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $config['token']
    ],
    // Sengaja dipanjangkan timeoutnya untuk test ini
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($curl);
$error = curl_error($curl);
curl_close($curl);

if ($error) {
    echo "<b>GAGAL (cURL Error):</b> " . htmlspecialchars($error);
} else {
    echo "<b>Respons dari Fonnte:</b><br>";
    echo "<pre>" . htmlspecialchars((string) $response) . "</pre>";
}
