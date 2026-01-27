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

function json_encode_safe(mixed $value): string {
  $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) {
    return json_encode(['error' => 'json_encode failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  return $encoded;
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

function detect_date_column(array $columns): ?string {
  foreach (['updated_at', 'created_at'] as $cand) {
    if (in_array($cand, $columns, true)) return $cand;
  }
  return null;
}

function max_date_from_rows(array $columns, array $rows, ?string $col): ?string {
  if (!$col) return null;
  $idx = array_search($col, $columns, true);
  if ($idx === false) return null;
  $max = '';
  foreach ($rows as $row) {
    if (!is_array($row) || !array_key_exists($idx, $row)) continue;
    $val = (string)($row[$idx] ?? '');
    if ($val === '') continue;
    if ($max === '' || $val > $max) $max = $val;
  }
  return $max !== '' ? $max : null;
}

function current_table_stats(PDO $pdo, string $table, ?string $dateColumn): array {
  $quoted = quote_ident($pdo, $table);
  $count = (int)$pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();
  $latest = null;
  if ($dateColumn && db_has_column($pdo, $table, $dateColumn)) {
    $col = quote_ident($pdo, $dateColumn);
    $latest = $pdo->query("SELECT MAX({$col}) FROM {$quoted}")->fetchColumn();
    if ($latest !== null) $latest = (string)$latest;
  }
  return ['count' => $count, 'latest' => $latest];
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

function apply_settings_payload(array $payload, array $allowed): void {
  $cfgPath = __DIR__ . '/../../config.php';
  $cfg = app_config();

  // Never overwrite DB credentials from a backup payload.
  if (array_key_exists('db', $payload)) {
    unset($payload['db']);
  }

  $allowedSet = array_fill_keys($allowed, true);

  if (isset($allowedSet['app']) && isset($payload['app']) && is_array($payload['app'])) {
    $cfg['app']['brand'] = $payload['app']['brand'] ?? ($cfg['app']['brand'] ?? []);
    $cfg['app']['default_school_year'] = $payload['app']['default_school_year'] ?? ($cfg['app']['default_school_year'] ?? '');
    $cfg['app']['uploads_dir'] = $payload['app']['uploads_dir'] ?? ($cfg['app']['uploads_dir'] ?? 'uploads');
  }
  foreach (['mail','ai','student','parent','signature'] as $key) {
    if (!isset($allowedSet[$key])) continue;
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

function upload_category_key(string $uploadsDirRel, string $path): ?string {
  $prefix = trim($uploadsDirRel, '/\\') . '/';
  if (!str_starts_with($path, $prefix)) return null;
  $rel = substr($path, strlen($prefix));
  $rel = ltrim($rel, '/\\');
  if ($rel === '') return null;
  $parts = preg_split('~/+~', $rel) ?: [];
  $first = $parts[0] ?? '';
  return $first !== '' ? $first : '_root';
}

function upload_category_label(string $key): string {
  if ($key === '_root') return 'Root';
  return ucfirst(str_replace('_', ' ', $key));
}

function uploads_categories_from_zip(ZipArchive $zip, string $uploadsDirRel): array {
  $counts = [];
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) continue;
    $name = $stat['name'] ?? '';
    if (str_ends_with($name, '/')) continue;
    $key = upload_category_key($uploadsDirRel, $name);
    if (!$key) continue;
    $counts[$key] = ($counts[$key] ?? 0) + 1;
  }
  ksort($counts);
  return $counts;
}

function uploads_categories_on_disk(string $uploadsDirRel): array {
  $root = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  $uploadsDirAbs = $root . '/' . trim($uploadsDirRel, '/\\');
  if (!is_dir($uploadsDirAbs)) return [];
  $counts = [];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadsDirAbs, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $rel = ltrim(str_replace($uploadsDirAbs, '', $file->getPathname()), '/\\');
    if ($rel === '') continue;
    $parts = preg_split('~/+~', $rel) ?: [];
    $key = $parts[0] ?? '';
    if ($key === '') $key = '_root';
    $counts[$key] = ($counts[$key] ?? 0) + 1;
  }
  ksort($counts);
  return $counts;
}

function extract_uploads_from_zip(ZipArchive $zip, string $uploadsDirRel, bool $overwrite, array $allowedCategories): int {
  $root = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  $uploadsDirAbs = $root . '/' . trim($uploadsDirRel, '/\\');
  if (!is_dir($uploadsDirAbs)) @mkdir($uploadsDirAbs, 0755, true);
  $count = 0;
  $allowedSet = array_fill_keys($allowedCategories, true);

  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) continue;
    $name = $stat['name'] ?? '';
    if (!str_starts_with($name, trim($uploadsDirRel, '/\\') . '/')) continue;
    if (str_contains($name, '..')) continue;
    if (str_ends_with($name, '/')) continue;
    if ($allowedSet) {
      $key = upload_category_key($uploadsDirRel, $name);
      if (!$key || !isset($allowedSet[$key])) continue;
    }

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

function count_uploads_in_zip(ZipArchive $zip, string $uploadsDirRel): int {
  $count = 0;
  $prefix = trim($uploadsDirRel, '/\\') . '/';
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) continue;
    $name = $stat['name'] ?? '';
    if (!str_starts_with($name, $prefix)) continue;
    if (str_ends_with($name, '/')) continue;
    $count++;
  }
  return $count;
}

function count_uploads_on_disk(string $uploadsDirRel): int {
  $root = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  $uploadsDirAbs = $root . '/' . trim($uploadsDirRel, '/\\');
  if (!is_dir($uploadsDirAbs)) return 0;
  $count = 0;
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadsDirAbs, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $file) {
    if ($file->isFile()) $count++;
  }
  return $count;
}

if ($action === 'list_tables') {
  $tables = list_db_tables($pdo);
  json_out(['ok' => true, 'tables' => $tables]);
}

function analyze_store(string $token, array $data): void {
  if (!isset($_SESSION['backup_analyze']) || !is_array($_SESSION['backup_analyze'])) {
    $_SESSION['backup_analyze'] = [];
  }
  $_SESSION['backup_analyze'][$token] = $data;
}

function analyze_get(string $token): ?array {
  $all = $_SESSION['backup_analyze'] ?? [];
  if (!is_array($all) || !isset($all[$token]) || !is_array($all[$token])) return null;
  return $all[$token];
}

function analyze_clear(string $token): void {
  if (isset($_SESSION['backup_analyze'][$token])) {
    unset($_SESSION['backup_analyze'][$token]);
  }
}

function import_store(string $token, array $data): void {
  if (!isset($_SESSION['backup_import']) || !is_array($_SESSION['backup_import'])) {
    $_SESSION['backup_import'] = [];
  }
  $_SESSION['backup_import'][$token] = $data;
}

function import_get(string $token): ?array {
  $all = $_SESSION['backup_import'] ?? [];
  if (!is_array($all) || !isset($all[$token]) || !is_array($all[$token])) return null;
  return $all[$token];
}

function import_clear(string $token): void {
  if (isset($_SESSION['backup_import'][$token])) {
    unset($_SESSION['backup_import'][$token]);
  }
}

function import_table_from_zip(PDO $pdo, ZipArchive $zip, string $table, bool $replaceTables, string $conflictMode): array {
  $entry = "data/{$table}.json";
  $raw = $zip->getFromName($entry);
  if ($raw === false) {
    return ['imported' => 0, 'skipped' => 'missing'];
  }
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['columns'], $data['rows']) || !is_array($data['columns']) || !is_array($data['rows'])) {
    return ['imported' => 0, 'skipped' => 'invalid'];
  }

  $columns = array_values(array_map('strval', $data['columns']));
  $rows = $data['rows'];
  if (!$columns) {
    return ['imported' => 0, 'skipped' => 'no_columns'];
  }
  if (!valid_table_name($table)) {
    return ['imported' => 0, 'skipped' => 'invalid_table'];
  }

  $quoted = quote_ident($pdo, $table);
  if ($replaceTables) {
    $pdo->exec("DELETE FROM {$quoted}");
  }

  $placeholders = implode(',', array_fill(0, count($columns), '?'));
  $colSql = implode(',', array_map(fn($c) => quote_ident($pdo, $c), $columns));
  $insertSql = "INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
  $driver = db_driver($pdo);
  if (!$replaceTables) {
    if ($driver === 'sqlite') {
      $insertSql = $conflictMode === 'overwrite'
        ? "INSERT OR REPLACE INTO {$quoted} ({$colSql}) VALUES ({$placeholders})"
        : "INSERT OR IGNORE INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
    } else {
      if ($conflictMode === 'overwrite') {
        $updates = implode(', ', array_map(fn($c) => quote_ident($pdo, $c) . '=VALUES(' . quote_ident($pdo, $c) . ')', $columns));
        $insertSql = "INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}";
      } else {
        $insertSql = "INSERT IGNORE INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
      }
    }
  }
  $stmt = $pdo->prepare($insertSql);

  $imported = 0;
  $conflicts = 0;
  $updated = 0;
  try {
    $pdo->beginTransaction();
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $stmt->execute($row);
      $affected = $stmt->rowCount();
      if ($replaceTables) {
        $imported++;
        continue;
      }
      if ($driver !== 'sqlite' && $conflictMode === 'overwrite' && $affected === 2) {
        $updated++;
        $conflicts++;
        continue;
      }
      if ($affected >= 1) {
        $imported++;
      } else {
        $conflicts++;
      }
    }
    $pdo->commit();
    return ['imported' => $imported, 'conflicts' => $conflicts, 'updated' => $updated];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['imported' => $imported, 'conflicts' => $conflicts, 'updated' => $updated, 'error' => $e->getMessage()];
  }
}

function analyze_table_compare(PDO $pdo, ZipArchive $zip, string $table): ?array {
  $entry = "data/{$table}.json";
  $raw = $zip->getFromName($entry);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['columns'], $data['rows'])) return null;
  $columns = is_array($data['columns']) ? $data['columns'] : [];
  $rows = is_array($data['rows']) ? $data['rows'] : [];
  $dateCol = detect_date_column($columns);
  $backupLatest = max_date_from_rows($columns, $rows, $dateCol);
  $backupCount = isset($data['row_count']) ? (int)$data['row_count'] : count($rows);

  $current = current_table_stats($pdo, $table, $dateCol);
  $currentCount = $current['count'];
  $currentLatest = $current['latest'];

  $same = ($backupCount === $currentCount) && ($backupLatest === $currentLatest);
  $backupLocal = db_datetime_to_user_datetime($backupLatest);
  $currentLocal = db_datetime_to_user_datetime($currentLatest);

  return [
    'table' => $table,
    'backup_count' => $backupCount,
    'current_count' => $currentCount,
    'backup_latest' => $backupLatest,
    'current_latest' => $currentLatest,
    'backup_latest_local' => $backupLocal ? $backupLocal->format('c') : null,
    'current_latest_local' => $currentLocal ? $currentLocal->format('c') : null,
    'same' => $same,
  ];
}

if ($action === 'analyze_start') {
  try {
    csrf_verify();
    if (!isset($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Keine gültige ZIP-Datei hochgeladen.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'leb_analyze_');
    if ($tmp === false) throw new RuntimeException('Konnte temporäre Datei nicht erstellen.');
    if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $tmp)) {
      throw new RuntimeException('Konnte Upload nicht speichern.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
      @unlink($tmp);
      throw new RuntimeException('Konnte ZIP-Datei nicht öffnen.');
    }

    $manifestRaw = $zip->getFromName('manifest.json');
    $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
    if (!is_array($manifest)) $manifest = [];
    $tables = $manifest['tables'] ?? [];
    if (!is_array($tables)) $tables = [];

    $tableList = array_values(array_filter(array_map('strval', $tables), fn($t) => $t !== ''));
    $tableCount = count($tableList);
    $isSame = true;
    $settingsPending = !empty($manifest['settings']);
    $uploadsPending = !empty($manifest['uploads']);
    $metaTotal = (int)$settingsPending + (int)$uploadsPending;
    $settingsSame = null;
    $uploadsSame = null;
    $uploadsBackupCount = null;
    $uploadsCurrentCount = null;

    if ($tableCount === 0 && empty($manifest['settings']) && empty($manifest['uploads'])) {
      $zip->close();
      @unlink($tmp);
      throw new RuntimeException('Backup enthält keine Daten.');
    }

    $zip->close();
    $token = bin2hex(random_bytes(16));
    analyze_store($token, [
      'path' => $tmp,
      'manifest' => $manifest,
      'tables' => $tableList,
      'index' => 0,
      'compare' => [],
      'table_count' => $tableCount,
      'is_same' => $isSame,
      'meta_total' => $metaTotal,
      'settings_pending' => $settingsPending,
      'uploads_pending' => $uploadsPending,
      'settings_same' => $settingsSame,
      'uploads_same' => $uploadsSame,
      'uploads_backup_count' => $uploadsBackupCount,
      'uploads_current_count' => $uploadsCurrentCount,
    ]);

    json_out([
      'ok' => true,
      'token' => $token,
    ]);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
}

if ($action === 'analyze_step') {
  try {
    csrf_verify();
    $token = (string)($_POST['token'] ?? '');
    if ($token === '') throw new RuntimeException('Token fehlt.');
    $state = analyze_get($token);
    if (!$state) throw new RuntimeException('Analyse-Session abgelaufen.');

    $path = (string)($state['path'] ?? '');
    if ($path === '' || !is_file($path)) throw new RuntimeException('Analyse-Datei fehlt.');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
      throw new RuntimeException('Konnte ZIP-Datei nicht öffnen.');
    }

    $tables = $state['tables'] ?? [];
    if (!is_array($tables)) $tables = [];
    $index = (int)($state['index'] ?? 0);
    $batchSize = 3;
    $compareChunk = [];
    $progressLabel = 'Analyse läuft …';

    if (!empty($state['settings_pending'])) {
      $settingsRaw = $zip->getFromName('settings.json');
      if (is_string($settingsRaw)) {
        $settingsBackup = json_decode($settingsRaw, true);
        $state['settings_same'] = is_array($settingsBackup)
          ? (json_encode_safe($settingsBackup) === json_encode_safe(export_settings_payload()))
          : false;
      } else {
        $state['settings_same'] = false;
      }
      if ($state['settings_same'] === false) $state['is_same'] = false;
      $state['settings_pending'] = false;
      $state['meta_done'] = (int)($state['meta_done'] ?? 0) + 1;
      $progressLabel = 'Einstellungen werden geprüft …';
    }

    if (!empty($state['uploads_pending'])) {
      $uploadsDir = (string)((app_config()['app']['uploads_dir'] ?? 'uploads'));
      $backupCats = uploads_categories_from_zip($zip, $uploadsDir);
      $currentCats = uploads_categories_on_disk($uploadsDir);
      $state['uploads_backup_count'] = array_sum($backupCats);
      $state['uploads_current_count'] = array_sum($currentCats);
      $cats = [];
      foreach ($backupCats as $key => $count) {
        $cats[] = [
          'key' => $key,
          'label' => upload_category_label($key),
          'backup_count' => $count,
          'current_count' => $currentCats[$key] ?? 0,
        ];
      }
      $state['uploads_categories'] = $cats;
      $state['uploads_same'] = ($state['uploads_backup_count'] === $state['uploads_current_count']);
      if ($state['uploads_same'] === false) $state['is_same'] = false;
      $state['uploads_pending'] = false;
      $state['meta_done'] = (int)($state['meta_done'] ?? 0) + 1;
      $progressLabel = 'Uploads werden geprüft …';
    }

    if ($tables) {
      $end = min(count($tables), $index + $batchSize);
      for ($i = $index; $i < $end; $i++) {
        $table = (string)($tables[$i] ?? '');
        if ($table === '') continue;
        $cmp = analyze_table_compare($pdo, $zip, $table);
        if ($cmp) {
          $compareChunk[] = $cmp;
          $state['compare'][] = $cmp;
          if (!($cmp['same'] ?? true)) $state['is_same'] = false;
        }
      }
      $state['index'] = $end;
      $progressLabel = 'Tabellen werden geprüft … (' . $end . '/' . count($tables) . ')';
    }

    $metaTotal = (int)($state['meta_total'] ?? 0);
    $metaDone = (int)($state['meta_done'] ?? 0);
    $total = (int)($state['table_count'] ?? count($tables)) + $metaTotal;
    $processed = min((int)($state['table_count'] ?? 0), (int)($state['index'] ?? 0)) + $metaDone;
    $progressPct = $total > 0 ? (int)round(($processed / $total) * 100) : 100;
    $done = ($processed >= $total);

    $zip->close();

    if ($done) {
      @unlink($path);
      analyze_clear($token);
    } else {
      analyze_store($token, $state);
    }

    json_out([
      'ok' => true,
      'compare_chunk' => $compareChunk,
      'progress_pct' => $progressPct,
      'progress_label' => $progressLabel,
      'done' => $done,
      'manifest' => $state['manifest'] ?? [],
      'table_count' => $total,
      'is_same' => $state['is_same'] ?? false,
      'settings_same' => $state['settings_same'] ?? null,
      'uploads_same' => $state['uploads_same'] ?? null,
      'uploads_backup_count' => $state['uploads_backup_count'] ?? null,
      'uploads_current_count' => $state['uploads_current_count'] ?? null,
      'uploads_categories' => $state['uploads_categories'] ?? [],
    ]);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
}

if ($action === 'import_start') {
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
    $conflictMode = (string)($_POST['conflict_mode'] ?? 'skip');
    if (!$replaceTables && !in_array($conflictMode, ['skip', 'overwrite'], true)) {
      throw new RuntimeException('Konfliktverhalten fehlt.');
    }

    if (!$tables && !$importSettings && !$importUploads) {
      throw new RuntimeException('Keine Import-Option ausgewählt.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'leb_import_');
    if ($tmp === false) throw new RuntimeException('Konnte temporäre Datei nicht erstellen.');
    if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $tmp)) {
      throw new RuntimeException('Konnte Upload nicht speichern.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
      @unlink($tmp);
      throw new RuntimeException('Konnte ZIP-Datei nicht öffnen.');
    }
    $zip->close();

    $selectedSettings = $_POST['selected_settings'] ?? [];
    if (!is_array($selectedSettings)) $selectedSettings = [];
    $selectedSettings = array_values(array_unique(array_filter(array_map('strval', $selectedSettings))));

    $selectedUploads = $_POST['selected_uploads'] ?? [];
    if (!is_array($selectedUploads)) $selectedUploads = [];
    $selectedUploads = array_values(array_unique(array_filter(array_map('strval', $selectedUploads))));

    $totalSteps = count($tables) + ($importSettings ? 1 : 0) + ($importUploads ? 1 : 0);
    if ($totalSteps < 1) $totalSteps = 1;

    $token = bin2hex(random_bytes(16));
    import_store($token, [
      'tmp' => $tmp,
      'tables' => $tables,
      'index' => 0,
      'import_settings' => $importSettings,
      'import_uploads' => $importUploads,
      'replace_tables' => $replaceTables,
      'conflict_mode' => $conflictMode,
      'selected_settings' => $selectedSettings,
      'selected_uploads' => $selectedUploads,
      'table_stats' => [],
      'settings_applied' => false,
      'uploads_imported' => 0,
      'total_steps' => $totalSteps,
      'steps_done' => 0,
    ]);
    json_out(['ok' => true, 'token' => $token, 'progress_pct' => 0]);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
}

if ($action === 'import_step') {
  try {
    csrf_verify();
    $token = (string)($_POST['token'] ?? '');
    if ($token === '') throw new RuntimeException('Token fehlt.');
    $state = import_get($token);
    if (!$state) throw new RuntimeException('Import-Session abgelaufen.');

    $tmp = (string)($state['tmp'] ?? '');
    if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Import-Datei fehlt.');

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
      throw new RuntimeException('Konnte ZIP-Datei nicht öffnen.');
    }

    $driver = db_driver($pdo);
    if ($driver === 'sqlite') {
      $pdo->exec('PRAGMA foreign_keys=OFF');
    } else {
      $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    }

    $tables = $state['tables'] ?? [];
    if (!is_array($tables)) $tables = [];
    $index = (int)($state['index'] ?? 0);
    $tableStats = $state['table_stats'] ?? [];

    if ($index < count($tables)) {
      $table = (string)$tables[$index];
      $tableStats[$table] = import_table_from_zip(
        $pdo,
        $zip,
        $table,
        (bool)($state['replace_tables'] ?? false),
        (string)($state['conflict_mode'] ?? 'skip')
      );
      $state['index'] = $index + 1;
      $state['table_stats'] = $tableStats;
      $state['steps_done'] = (int)($state['steps_done'] ?? 0) + 1;
    } elseif (!empty($state['import_settings']) && empty($state['settings_applied'])) {
      $raw = $zip->getFromName('settings.json');
      if ($raw !== false) {
        $settings = json_decode($raw, true);
        if (is_array($settings)) {
          $selected = $state['selected_settings'] ?? [];
          if (!is_array($selected)) $selected = [];
          if ($selected) {
            apply_settings_payload($settings, $selected);
            $state['settings_applied'] = true;
          }
        }
      }
      $state['steps_done'] = (int)($state['steps_done'] ?? 0) + 1;
    } elseif (!empty($state['import_uploads']) && (int)($state['uploads_imported'] ?? 0) === 0) {
      $uploadsDir = (string)((app_config()['app']['uploads_dir'] ?? 'uploads'));
      $selected = $state['selected_uploads'] ?? [];
      if (!is_array($selected)) $selected = [];
      if ($selected) {
        $state['uploads_imported'] = extract_uploads_from_zip($zip, $uploadsDir, true, $selected);
      } else {
        $state['uploads_imported'] = 0;
      }
      $state['steps_done'] = (int)($state['steps_done'] ?? 0) + 1;
    }

    if ($driver === 'sqlite') {
      $pdo->exec('PRAGMA foreign_keys=ON');
    } else {
      $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    $zip->close();
    import_store($token, $state);

    $totalSteps = (int)($state['total_steps'] ?? 1);
    $stepsDone = (int)($state['steps_done'] ?? 0);
    $progress = (int)round(($stepsDone / max(1, $totalSteps)) * 100);
    $progressLabel = 'Import läuft …';
    $tablesTotal = is_array($tables) ? count($tables) : 0;
    if ($stepsDone <= $tablesTotal) {
      $progressLabel = 'Tabelle wird importiert … (' . min($stepsDone, $tablesTotal) . '/' . $tablesTotal . ')';
    } elseif (!empty($state['import_settings']) && empty($state['settings_applied'])) {
      $progressLabel = 'Einstellungen werden importiert …';
    } elseif (!empty($state['import_uploads']) && (int)($state['uploads_imported'] ?? 0) === 0) {
      $progressLabel = 'Uploads werden importiert …';
    }

    $done = ($stepsDone >= $totalSteps);
    if ($done) {
      $tableStats = $state['table_stats'] ?? [];
      $settingsApplied = !empty($state['settings_applied']);
      $uploadsImported = (int)($state['uploads_imported'] ?? 0);

      audit('admin_backup_import', (int)current_user()['id'], [
        'tables' => array_keys(is_array($tableStats) ? $tableStats : []),
        'settings' => $settingsApplied,
        'uploads' => $uploadsImported,
      ]);

      $conflictsTotal = 0;
      $updatedTotal = 0;
      foreach ($tableStats as $stats) {
        $conflictsTotal += (int)($stats['conflicts'] ?? 0);
        $updatedTotal += (int)($stats['updated'] ?? 0);
      }
      $msg = 'Import abgeschlossen. Tabellen: ' . count($tableStats) . ', Uploads: ' . $uploadsImported . ', Einstellungen: ' . ($settingsApplied ? 'ja' : 'nein');
      if (empty($state['replace_tables'])) {
        $msg .= '. Konflikte: ' . $conflictsTotal . ', Überschrieben: ' . $updatedTotal;
      }

      @unlink($tmp);
      import_clear($token);
      json_out([
        'ok' => true,
        'done' => true,
        'progress_pct' => $progress,
        'progress_label' => $progressLabel,
        'message' => $msg,
        'tables' => $tableStats,
      ]);
    }

    json_out([
      'ok' => true,
      'done' => false,
      'progress_pct' => $progress,
      'progress_label' => $progressLabel,
    ]);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
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
    $zip->addFromString('manifest.json', json_encode_safe($manifest));

    foreach ($tables as $table) {
      if (!valid_table_name($table)) continue;
      $payload = export_table($pdo, $table);
      $zip->addFromString("data/{$table}.json", json_encode_safe($payload));
    }

    if ($includeSettings) {
      $settings = export_settings_payload();
      $zip->addFromString('settings.json', json_encode_safe($settings));
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
    $conflictMode = (string)($_POST['conflict_mode'] ?? 'skip');
    if (!$replaceTables && !in_array($conflictMode, ['skip', 'overwrite'], true)) {
      throw new RuntimeException('Konfliktverhalten fehlt.');
    }

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
      $insertSql = "INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
      if (!$replaceTables) {
        if ($driver === 'sqlite') {
          $insertSql = $conflictMode === 'overwrite'
            ? "INSERT OR REPLACE INTO {$quoted} ({$colSql}) VALUES ({$placeholders})"
            : "INSERT OR IGNORE INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
        } else {
          if ($conflictMode === 'overwrite') {
            $updates = implode(', ', array_map(fn($c) => quote_ident($pdo, $c) . '=VALUES(' . quote_ident($pdo, $c) . ')', $columns));
            $insertSql = "INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}";
          } else {
            $insertSql = "INSERT IGNORE INTO {$quoted} ({$colSql}) VALUES ({$placeholders})";
          }
        }
      }
      $stmt = $pdo->prepare($insertSql);

      $imported = 0;
      $conflicts = 0;
      $updated = 0;
      try {
        $pdo->beginTransaction();
        foreach ($rows as $row) {
          if (!is_array($row)) continue;
          $stmt->execute($row);
          $affected = $stmt->rowCount();
          if ($replaceTables) {
            $imported++;
            continue;
          }
          if ($driver !== 'sqlite' && $conflictMode === 'overwrite' && $affected === 2) {
            $updated++;
            $conflicts++;
            continue;
          }
          if ($affected >= 1) {
            $imported++;
          } else {
            $conflicts++;
          }
        }
        $pdo->commit();
        $tableStats[$table] = ['imported' => $imported, 'conflicts' => $conflicts, 'updated' => $updated];
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $tableStats[$table] = ['imported' => $imported, 'conflicts' => $conflicts, 'updated' => $updated, 'error' => $e->getMessage()];
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
          $selected = $_POST['selected_settings'] ?? [];
          if (!is_array($selected)) $selected = [];
          $selected = array_values(array_unique(array_filter(array_map('strval', $selected))));
          if ($selected) {
            apply_settings_payload($settings, $selected);
            $settingsApplied = true;
          }
        }
      }
    }

    $uploadsImported = 0;
    if ($importUploads) {
      $uploadsDir = (string)((app_config()['app']['uploads_dir'] ?? 'uploads'));
      $selected = $_POST['selected_uploads'] ?? [];
      if (!is_array($selected)) $selected = [];
      $selected = array_values(array_unique(array_filter(array_map('strval', $selected))));
      if ($selected) {
        $uploadsImported = extract_uploads_from_zip($zip, $uploadsDir, true, $selected);
      }
    }

    $zip->close();

    audit('admin_backup_import', (int)current_user()['id'], [
      'tables' => array_keys($tableStats),
      'settings' => $settingsApplied,
      'uploads' => $uploadsImported,
    ]);

    $conflictsTotal = 0;
    $updatedTotal = 0;
    foreach ($tableStats as $stats) {
      $conflictsTotal += (int)($stats['conflicts'] ?? 0);
      $updatedTotal += (int)($stats['updated'] ?? 0);
    }
    $msg = 'Import abgeschlossen. Tabellen: ' . count($tableStats) . ', Uploads: ' . $uploadsImported . ', Einstellungen: ' . ($settingsApplied ? 'ja' : 'nein');
    if (!$replaceTables) {
      $msg .= '. Konflikte: ' . $conflictsTotal . ', Überschrieben: ' . $updatedTotal;
    }
    json_out(['ok' => true, 'message' => $msg, 'tables' => $tableStats]);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
  }
}

json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
