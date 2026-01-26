<?php
// admin/ajax/backup_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_admin();

header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$pdo = db();

function json_out(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function db_driver(PDO $pdo): string {
  try {
    return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  } catch (Throwable $e) {
    return 'unknown';
  }
}

function list_db_tables(PDO $pdo): array {
  $driver = db_driver($pdo);
  if ($driver === 'sqlite') {
    $st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
    return array_values(array_map(fn($r) => (string)$r['name'], $st->fetchAll(PDO::FETCH_ASSOC)));
  }
  $st = $pdo->query("SHOW TABLES");
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
    if (isset($row[0])) $out[] = (string)$row[0];
  }
  sort($out);
  return $out;
}

function quote_ident(PDO $pdo, string $name): string {
  $driver = db_driver($pdo);
  if ($driver === 'sqlite') return '"' . str_replace('"', '""', $name) . '"';
  return '`' . str_replace('`', '``', $name) . '`';
}

function valid_table_name(string $name): bool {
  return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
}

function export_table(PDO $pdo, string $table): array {
  $quoted = quote_ident($pdo, $table);
  $metaStmt = $pdo->query("SELECT * FROM {$quoted} LIMIT 0");
  $cols = [];
  for ($i = 0; $i < $metaStmt->columnCount(); $i++) {
    $meta = $metaStmt->getColumnMeta($i);
    $cols[] = $meta['name'] ?? 'col_' . $i;
  }

  $rows = [];
  $st = $pdo->query("SELECT * FROM {$quoted}");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $ordered = [];
    foreach ($cols as $col) {
      $ordered[] = array_key_exists($col, $row) ? $row[$col] : null;
    }
    $rows[] = $ordered;
  }

  return [
    'table' => $table,
    'columns' => $cols,
    'rows' => $rows,
    'row_count' => count($rows),
  ];
}

function export_settings_payload(): array {
  $cfg = app_config();
  $app = $cfg['app'] ?? [];
  $out = [
    'app' => [
      'brand' => $app['brand'] ?? [],
      'default_school_year' => $app['default_school_year'] ?? '',
      'uploads_dir' => $app['uploads_dir'] ?? 'uploads',
    ],
    'mail' => $cfg['mail'] ?? [],
    'ai' => $cfg['ai'] ?? [],
    'student' => $cfg['student'] ?? [],
    'parent' => $cfg['parent'] ?? [],
    'signature' => $cfg['signature'] ?? [],
  ];
  return $out;
}

function apply_settings_payload(array $payload): void {
  $cfgPath = __DIR__ . '/../../config.php';
  $cfg = app_config();

  if (isset($payload['app']) && is_array($payload['app'])) {
    $cfg['app']['brand'] = $payload['app']['brand'] ?? ($cfg['app']['brand'] ?? []);
    $cfg['app']['default_school_year'] = $payload['app']['default_school_year'] ?? ($cfg['app']['default_school_year'] ?? '');
    $cfg['app']['uploads_dir'] = $payload['app']['uploads_dir'] ?? ($cfg['app']['uploads_dir'] ?? 'uploads');
  }
  foreach (['mail','ai','student','parent','signature'] as $key) {
    if (isset($payload[$key]) && is_array($payload[$key])) {
      $cfg[$key] = $payload[$key];
    }
  }

  $export = "<?php\n// config.php (updated by admin/backup)\nreturn " . var_export($cfg, true) . ";\n";
  if (file_put_contents($cfgPath, $export, LOCK_EX) === false) {
    throw new RuntimeException('Konnte config.php nicht schreiben.');
  }
}

function add_uploads_to_zip(ZipArchive $zip, string $uploadsDirRel): int {
  $root = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  $uploadsDirAbs = $root . '/' . trim($uploadsDirRel, '/\\');
  if (!is_dir($uploadsDirAbs)) return 0;

  $count = 0;
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadsDirAbs, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $abs = $file->getPathname();
    $rel = trim($uploadsDirRel, '/\\') . '/' . ltrim(str_replace($uploadsDirAbs, '', $abs), '/\\');
    if ($zip->addFile($abs, $rel)) $count++;
  }
  return $count;
}

function extract_uploads_from_zip(ZipArchive $zip, string $uploadsDirRel, bool $overwrite): int {
  $root = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  $uploadsDirAbs = $root . '/' . trim($uploadsDirRel, '/\\');
  if (!is_dir($uploadsDirAbs)) @mkdir($uploadsDirAbs, 0755, true);
  $count = 0;

  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) continue;
    $name = $stat['name'] ?? '';
    if (!str_starts_with($name, trim($uploadsDirRel, '/\\') . '/')) continue;
    if (str_contains($name, '..')) continue;

    $dest = $root . '/' . $name;
    $destDir = dirname($dest);
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    if (file_exists($dest) && !$overwrite) continue;
    $stream = $zip->getStream($name);
    if (!$stream) continue;
    $out = fopen($dest, 'wb');
    if (!$out) {
      fclose($stream);
      continue;
    }
    stream_copy_to_stream($stream, $out);
    fclose($stream);
    fclose($out);
    $count++;
  }
  return $count;
}

if ($action === 'list_tables') {
  $tables = list_db_tables($pdo);
  json_out(['ok' => true, 'tables' => $tables]);
}

if ($action === 'export') {
  try {
    csrf_verify();
    $tables = $_POST['tables'] ?? [];
    if (!is_array($tables)) $tables = [];

    $allTables = list_db_tables($pdo);
    $tables = array_values(array_filter(array_unique(array_map('strval', $tables))));
    $includeSettings = isset($_POST['include_settings']);
    $includeUploads = isset($_POST['include_uploads']);
    $tables = array_values(array_intersect($tables, $allTables));

    if (!$tables && !$includeSettings && !$includeUploads) {
      throw new RuntimeException('Keine Export-Option ausgewählt.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'leb_backup_');
    if ($tmp === false) throw new RuntimeException('Konnte temporäre Datei nicht erstellen.');

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
      throw new RuntimeException('Konnte ZIP nicht öffnen.');
    }

    $manifest = [
      'created_at' => date('c'),
      'tables' => $tables,
      'settings' => $includeSettings,
      'uploads' => $includeUploads,
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    foreach ($tables as $table) {
      if (!valid_table_name($table)) continue;
      $payload = export_table($pdo, $table);
      $zip->addFromString("data/{$table}.json", json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($includeSettings) {
      $settings = export_settings_payload();
      $zip->addFromString('settings.json', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($includeUploads) {
      $uploadsDir = (string)((app_config()['app']['uploads_dir'] ?? 'uploads'));
      add_uploads_to_zip($zip, $uploadsDir);
    }

    $zip->close();

    $filename = 'lebtool-backup-' . date('Ymd-Hi') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
}

if ($action === 'import') {
  try {
    csrf_verify();
    if (!isset($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Keine gültige ZIP-Datei hochgeladen.');
    }

    $tables = $_POST['tables'] ?? [];
    if (!is_array($tables)) $tables = [];
    $allTables = list_db_tables($pdo);
    $tables = array_values(array_filter(array_unique(array_map('strval', $tables))));
    $tables = array_values(array_intersect($tables, $allTables));

    $importSettings = isset($_POST['import_settings']);
    $importUploads = isset($_POST['import_uploads']);
    $replaceTables = isset($_POST['import_replace']);

    if (!$tables && !$importSettings && !$importUploads) {
      throw new RuntimeException('Keine Import-Option ausgewählt.');
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup_file']['tmp_name']) !== true) {
      throw new RuntimeException('Konnte ZIP-Datei nicht öffnen.');
    }

    $driver = db_driver($pdo);
    if ($driver === 'sqlite') {
      $pdo->exec('PRAGMA foreign_keys=OFF');
    } else {
      $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    }

    $tableStats = [];
    foreach ($tables as $table) {
      $entry = "data/{$table}.json";
      $raw = $zip->getFromName($entry);
      if ($raw === false) {
        $tableStats[$table] = ['imported' => 0, 'skipped' => 'missing'];
        continue;
      }
      $data = json_decode($raw, true);
      if (!is_array($data) || !isset($data['columns'], $data['rows']) || !is_array($data['columns']) || !is_array($data['rows'])) {
        $tableStats[$table] = ['imported' => 0, 'skipped' => 'invalid'];
        continue;
      }

      $columns = array_values(array_map('strval', $data['columns']));
      $rows = $data['rows'];
      if (!$columns) {
        $tableStats[$table] = ['imported' => 0, 'skipped' => 'no_columns'];
        continue;
      }
      if (!valid_table_name($table)) {
        $tableStats[$table] = ['imported' => 0, 'skipped' => 'invalid_table'];
        continue;
      }

      $quoted = quote_ident($pdo, $table);
      if ($replaceTables) {
        $pdo->exec("DELETE FROM {$quoted}");
      }

      $placeholders = implode(',', array_fill(0, count($columns), '?'));
      $colSql = implode(',', array_map(fn($c) => quote_ident($pdo, $c), $columns));
      $stmt = $pdo->prepare("INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders})");

      $imported = 0;
      try {
        $pdo->beginTransaction();
        foreach ($rows as $row) {
          if (!is_array($row)) continue;
          $stmt->execute($row);
          $imported++;
        }
        $pdo->commit();
        $tableStats[$table] = ['imported' => $imported];
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $tableStats[$table] = ['imported' => $imported, 'error' => $e->getMessage()];
      }
    }

    if ($driver === 'sqlite') {
      $pdo->exec('PRAGMA foreign_keys=ON');
    } else {
      $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    $settingsApplied = false;
    if ($importSettings) {
      $raw = $zip->getFromName('settings.json');
      if ($raw !== false) {
        $settings = json_decode($raw, true);
        if (is_array($settings)) {
          apply_settings_payload($settings);
          $settingsApplied = true;
        }
      }
    }

    $uploadsImported = 0;
    if ($importUploads) {
      $uploadsDir = (string)((app_config()['app']['uploads_dir'] ?? 'uploads'));
      $uploadsImported = extract_uploads_from_zip($zip, $uploadsDir, true);
    }

    $zip->close();

    audit('admin_backup_import', (int)current_user()['id'], [
      'tables' => array_keys($tableStats),
      'settings' => $settingsApplied,
      'uploads' => $uploadsImported,
    ]);

    $msg = 'Import abgeschlossen. Tabellen: ' . count($tableStats) . ', Uploads: ' . $uploadsImported . ', Einstellungen: ' . ($settingsApplied ? 'ja' : 'nein');
    json_out(['ok' => true, 'message' => $msg, 'tables' => $tableStats]);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
}

json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
