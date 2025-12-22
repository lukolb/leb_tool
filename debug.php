<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "PHP OK\n";
echo "Version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n";

try {
  $pdo = new PDO("mysql:host=localhost;dbname=INTERNET_DBNAME;charset=utf8mb4", "INTERNET_DBUSER", "INTERNET_DBPASS", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  echo "DB OK\n";
  $v = $pdo->query("SELECT VERSION()")->fetchColumn();
  echo "DB Version: $v\n";
} catch (Throwable $e) {
  echo "DB FAIL: " . $e->getMessage() . "\n";
}

