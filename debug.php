<?php
require __DIR__ . '/bootstrap.php';
require_admin();

header('Content-Type: text/plain; charset=utf-8');

echo "PHP OK\n";
echo "Version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n";

try {
  $pdo = db();
  $v = $pdo->query("SELECT VERSION()")->fetchColumn();
  echo "DB OK\n";
  echo "DB Version: $v\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB FAIL\n";
}

