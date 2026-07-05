<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('POST');

$user = current_user();
if ($user) {
    audit_log(db(), (int) $user['outlet_id'], (int) $user['id'], 'logout', 'auth', (int) $user['id'], 'User logout dari sistem.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

json_response(['message' => 'Logout berhasil.']);
