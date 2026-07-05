<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_method('GET');

json_response(['user' => require_auth()]);
