<?php
header('Content-Type: text/plain; charset=utf-8');

echo "PHP Version: " . PHP_VERSION . "\n\n";

echo "--- PDO Check ---\n";
echo "PDO loaded: " . (extension_loaded('pdo') ? 'YES' : 'NO') . "\n";
echo "PDO MySQL loaded: " . (extension_loaded('pdo_mysql') ? 'YES' : 'NO') . "\n";
echo "MySQLi loaded: " . (extension_loaded('mysqli') ? 'YES' : 'NO') . "\n\n";

echo "--- All Loaded Extensions ---\n";
$extensions = get_loaded_extensions();
sort($extensions);
echo implode(', ', $extensions) . "\n\n";

echo "--- PDO class exists ---\n";
echo "class_exists('PDO'): " . (class_exists('PDO') ? 'YES' : 'NO') . "\n";
