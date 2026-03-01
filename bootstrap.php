<?php
// bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/shared/translations.php';
require_once __DIR__ . '/shared/signatures.php';

$configPath = getenv('APP_CONFIG_FILE') ?: (__DIR__ . '/config.php');
define('APP_CONFIG_PATH', $configPath);
if (!file_exists(APP_CONFIG_PATH)) {
  // install not completed
  header('Location: install.php');
  exit;
}
$config = require APP_CONFIG_PATH;

// Harden session cookie: secure, HTTP-only, and scoped to the app base path.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
$cookiePath = (string)($config['app']['base_path'] ?? '/');
$cookiePath = '/' . ltrim($cookiePath, '/');
$cookiePath = rtrim($cookiePath, '/') ?: '/';
session_set_cookie_params([
  'lifetime' => 0,
  'path' => $cookiePath,
  'domain' => '',
  'secure' => $https,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_name($config['app']['session_name'] ?? 'legtool_sess');
session_start();

// Prevent browser "confirm form resubmission" prompt on reload by replacing history state.
ob_start(function (string $buffer): string {
  if (is_ajax_request()) return $buffer;
  if (stripos($buffer, '</body>') === false) return $buffer;
  if (strpos($buffer, 'data-history-replace-state') !== false) return $buffer;

  $header = '';
  foreach (headers_list() as $item) {
    if (stripos($item, 'Content-Type:') === 0) {
      $header = $item;
      break;
    }
  }
  if ($header && stripos($header, 'text/html') === false) return $buffer;

  $script = "\n  <script data-history-replace-state>\n" .
    "    if (window.history && window.history.replaceState) {\n" .
    "      window.history.replaceState(null, document.title, window.location.href);\n" .
    "    }\n" .
    "  </script>\n";
  return preg_replace('/<\/body>/i', $script . '</body>', $buffer, 1) ?? $buffer;
});


// --- UI language (only affects dynamic field labels/group titles) ---
function ui_lang(): string {
  $lang = (string)($_SESSION['ui_lang'] ?? 'de');
  $lang = strtolower(trim($lang));
  return in_array($lang, ['de','en'], true) ? $lang : 'de';
}

function ui_lang_set(string $lang): void {
  $lang = strtolower(trim($lang));
  if (!in_array($lang, ['de','en'], true)) return;
  $_SESSION['ui_lang'] = $lang;
}

function is_ajax_request(): bool {
  $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
  if (strpos($uri, '/ajax/') !== false) return true;
  $xrw = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  if (strtolower($xrw) === 'xmlhttprequest') return true;
  $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
  return stripos($accept, 'application/json') !== false;
}

/**
 * Build current URL with lang=... (keeps other query params)
 */
function url_with_lang(string $lang): string {
  $lang = strtolower(trim($lang));
  if (!in_array($lang, ['de','en'], true)) $lang = 'de';
  $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
  $parts = parse_url($uri);
  $path = (string)($parts['path'] ?? '');
  $qs = (string)($parts['query'] ?? '');
  parse_str($qs, $q);
  $q['lang'] = $lang;
  $newQs = http_build_query($q);
  return $path . ($newQs ? ('?' . $newQs) : '');
}

function normalize_class_period_label(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? $s : 'Standard';
}

function class_report_period_label(int $classId, ?string $periodLabel = null): string {
  $periodLabel = normalize_class_period_label($periodLabel);
  if ($periodLabel === 'Standard') return '__class__:' . $classId;
  return '__class__:' . $classId . ':' . $periodLabel;
}

function class_id_from_report_period_label(?string $label): int {
  $label = (string)$label;
  if (strpos($label, '__class__:') !== 0) return 0;
  $rest = substr($label, strlen('__class__:'));
  $parts = explode(':', $rest);
  $id = (int)($parts[0] ?? 0);
  return $id > 0 ? $id : 0;
}

// One-shot: allow switching language via ?lang=de|en
if (isset($_GET['lang'])) {
  ui_lang_set((string)$_GET['lang']);

  // Avoid breaking fetch/ajax calls. For normal GET navigations, strip ?lang=... and redirect.
  if (!is_ajax_request() && (string)($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $parts = parse_url($uri);
    $path = (string)($parts['path'] ?? '');
    $qs = (string)($parts['query'] ?? '');
    parse_str($qs, $q);
    unset($q['lang']);
    $newQs = http_build_query($q);
    header('Location: ' . $path . ($newQs ? ('?' . $newQs) : ''));
    exit;
  }
}


// Base path comes from config (prevents /admin/admin/... issues)
$basePath = (string)($config['app']['base_path'] ?? '');
$basePath = '/' . ltrim($basePath, '/');
$basePath = rtrim($basePath, '/');
if ($basePath === '/') $basePath = '';
define('APP_BASE_URL', $basePath);

function app_config(bool $forceReload = false): array {
  static $cfg = null;
  if ($cfg !== null && !$forceReload) return $cfg;
  $cfg = require APP_CONFIG_PATH;
  return $cfg;
}

function brand(): array {
  $cfg = app_config();
  return $cfg['app']['brand'] ?? [
    'primary' => '#0b57d0',
    'secondary' => '#111111',
    'logo_path' => '',
    'org_name' => 'LEG Tool',
  ];
}

function url(string $path): string {
  $path = '/' . ltrim($path, '/');
  return APP_BASE_URL . $path;
}

/**
 * Outputs favicon link tags.
 *
 * Centralized so all areas (admin/teacher/student) render the same favicon(s)
 * and it keeps working when the app is installed in a subfolder (APP_BASE_URL).
 */
function render_favicons(): void {
  // Existing files in assets/icons
  $ico16 = url('assets/icons/favicon-16x16.png');
  $ico32 = url('assets/icons/favicon-32x32.png');
  $icoIco = url('assets/icons/lebtool-icon-big.ico');
  $apple = url('assets/icons/lebtool-icon-512x512.png');

  echo "\n    <!-- Favicons -->\n";
  echo '    <link rel="icon" href="' . h($icoIco) . '" sizes="any">' . "\n";
  echo '    <link rel="icon" type="image/png" sizes="32x32" href="' . h($ico32) . '">' . "\n";
  echo '    <link rel="icon" type="image/png" sizes="16x16" href="' . h($ico16) . '">' . "\n";
  // iOS / iPadOS home screen icon (best available size in repo)
  echo '    <link rel="apple-touch-icon" href="' . h($apple) . '">' . "\n";
}

function redirect(string $path): never {
  header('Location: ' . url($path));
  exit;
}

function absolute_url(string $path): string {
  $cfg = app_config();
  $override = $cfg['app']['public_base_url'] ?? '';
  if ($override) {
    return rtrim($override, '/') . '/' . ltrim($path, '/');
  }
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme . '://' . $host . url($path);
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = app_config();
  $db = $cfg['db'];
  $driver = $db['driver'] ?? 'mysql';

  if ($driver === 'sqlite') {
    $dbPath = (string)($db['path'] ?? ':memory:');
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } else {
    $port = $db['port'] ?? 3306;
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset={$charset}";

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Lightweight, additive schema migrations (shared-hosting friendly).
    // We only add missing columns / indexes that newer features rely on.
    // If DB user lacks ALTER privileges, the app will still run (features may be limited).
    ensure_schema($pdo);
  }
  return $pdo;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function user_timezone_name(?string $override = null): string {
  $tz = $override ?? '';
  if (!is_string($tz) || trim($tz) === '') {
    $cfg = app_config();
    $tz = (string)($cfg['app']['timezone'] ?? '');
  }
  $tz = trim((string)$tz);
  if ($tz === '') return 'America/New_York';
  $normalized = normalize_db_timezone_string($tz, 'America/New_York');
  if (preg_match('/^[+-]\d{2}:\d{2}$/', $normalized)) return $normalized;
  try {
    return (new DateTimeZone($normalized))->getName();
  } catch (Throwable $e) {
    return 'America/New_York';
  }
}

function user_timezone(): DateTimeZone {
  return new DateTimeZone(user_timezone_name());
}

function normalize_db_timezone_string(string $tz, string $fallback = 'UTC'): string {
  $tz = trim($tz);
  if ($tz === '') return $fallback;
  if (preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) return $tz;
  if ($tz === 'SYSTEM') return $fallback;
  try {
    return (new DateTimeZone($tz))->getName();
  } catch (Throwable $e) {
    // continue with abbreviation lookup
  }

  $abbr = strtolower($tz);
  $abbrs = timezone_abbreviations_list();
  $candidates = $abbrs[$abbr] ?? [];
  $candidates = array_values(array_filter($candidates, static function ($item): bool {
    return is_array($item) && !empty($item['timezone_id']);
  }));
  usort($candidates, static function (array $a, array $b): int {
    return strcmp((string)$a['timezone_id'], (string)$b['timezone_id']);
  });

  $preferredPrefixes = ['America/', 'Europe/', 'UTC', 'Etc/', 'Australia/', 'Asia/', 'Africa/'];
  $selected = '';
  foreach ($preferredPrefixes as $prefix) {
    foreach ($candidates as $candidate) {
      $id = (string)$candidate['timezone_id'];
      if ($id === '') continue;
      if ($prefix === 'UTC') {
        if ($id === 'UTC') {
          $selected = $id;
          break 2;
        }
      } elseif (str_starts_with($id, $prefix)) {
        $selected = $id;
        break 2;
      }
    }
  }
  if ($selected === '' && $candidates) {
    $selected = (string)$candidates[0]['timezone_id'];
  }

  if ($selected !== '') {
    try {
      return (new DateTimeZone($selected))->getName();
    } catch (Throwable $e) {
      return $fallback;
    }
  }
  return $fallback;
}

function persist_config_define(string $key, string $value): bool {
  $path = APP_CONFIG_PATH;
  if (!is_file($path) || !is_writable($path)) return false;

  $contents = file_get_contents($path);
  if ($contents === false) return false;

  $escapedValue = addcslashes($value, "\\'");
  $defineLine = "define('" . $key . "', '" . $escapedValue . "');";
  $pattern = '~define\\(\\s*[\'"]' . preg_quote($key, '~') . '[\'"]\\s*,\\s*[\'"].*?[\'"]\\s*\\)\\s*;~s';
  $newContents = $contents;
  if (preg_match($pattern, $contents)) {
    $newContents = preg_replace($pattern, $defineLine, $contents, 1) ?? $contents;
  } else {
    if (preg_match('/<\\?php\\s*/', $contents, $m, PREG_OFFSET_CAPTURE)) {
      $pos = $m[0][1] + strlen($m[0][0]);
      $newContents = substr_replace($contents, "\n" . $defineLine . "\n", $pos, 0);
    } else {
      $newContents = "<?php\n" . $defineLine . "\n" . $contents;
    }
  }

  $dir = dirname($path);
  $tmp = tempnam($dir, 'cfg');
  if ($tmp !== false) {
    if (file_put_contents($tmp, $newContents) !== false) {
      if (@rename($tmp, $path)) return true;
    }
    @unlink($tmp);
  }
  return file_put_contents($path, $newContents) !== false;
}

function db_detect_timezone(PDO $pdo, string $fallback = 'UTC'): string {
  static $cached = null;
  if (is_string($cached) && $cached !== '') return $cached;

  if (defined('DB_DATETIME_TZ') && DB_DATETIME_TZ !== '') {
    $tz = normalize_db_timezone_string((string)DB_DATETIME_TZ, $fallback);
    $cached = $tz;
    return $tz;
  }

  $detected = $fallback;
  try {
    $row = $pdo->query(
      "SELECT\n" .
      "  @@global.time_zone   AS global_tz,\n" .
      "  @@session.time_zone  AS session_tz,\n" .
      "  @@system_time_zone   AS system_tz"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $session = trim((string)($row['session_tz'] ?? ''));
    $global = trim((string)($row['global_tz'] ?? ''));
    $system = trim((string)($row['system_tz'] ?? ''));

    $candidate = '';
    if ($session !== '' && strtoupper($session) !== 'SYSTEM') {
      $candidate = $session;
    } elseif ($global !== '' && strtoupper($global) !== 'SYSTEM') {
      $candidate = $global;
    } elseif ($system !== '') {
      $candidate = $system;
    }

    $detected = normalize_db_timezone_string($candidate, $fallback);
  } catch (Throwable $e) {
    $detected = $fallback;
  }

  $cached = $detected;
  persist_config_define('DB_DATETIME_TZ', $detected);
  return $detected;
}

function db_datetime_to_user_datetime(?string $dbDateTime, ?string $userTz = null): ?DateTimeImmutable {
  if ($dbDateTime === null) return null;
  $dbDateTime = trim((string)$dbDateTime);
  if ($dbDateTime === '' || $dbDateTime === '0000-00-00 00:00:00') return null;
  try {
    $pdo = db();
    $dbTz = db_detect_timezone($pdo);
    $userTz = user_timezone_name($userTz);
    $dt = new DateTimeImmutable($dbDateTime, new DateTimeZone($dbTz));
    return $dt->setTimezone(new DateTimeZone($userTz));
  } catch (Throwable $e) {
    return null;
  }
}

function db_datetime_to_user_local(?string $dbDateTime, ?string $userTz = null, string $format = 'd.m.Y H:i'): ?string {
  $dt = db_datetime_to_user_datetime($dbDateTime, $userTz);
  return $dt ? $dt->format($format) : null;
}

function db_datetime_to_user_date(?string $dbDateTime, ?string $userTz = null, string $format = 'd.m.Y'): ?string {
  return db_datetime_to_user_local($dbDateTime, $userTz, $format);
}

function user_local_datetime_to_db(?DateTimeImmutable $localDateTime, ?string $userTz = null): ?DateTimeImmutable {
  if (!$localDateTime) return null;
  try {
    $pdo = db();
    $dbTz = db_detect_timezone($pdo);
    $userTz = user_timezone_name($userTz);
    $localized = $localDateTime->setTimezone(new DateTimeZone($userTz));
    return $localized->setTimezone(new DateTimeZone($dbTz));
  } catch (Throwable $e) {
    return null;
  }
}

function render_local_datetime(?string $value, string $format = 'd.m.Y H:i', string $empty = '–'): string {
  $dt = db_datetime_to_user_datetime($value);
  if (!$dt) return h($empty);
  $iso = $dt->format('c');
  $formatted = $dt->format($format);
  return '<time data-dt="' . h($iso) . '">' . h($formatted) . '</time>';
}

function render_local_datetime_title_attr(?string $value, string $format = 'd.m.Y H:i'): string {
  $dt = db_datetime_to_user_datetime($value);
  if (!$dt) return '';
  $iso = $dt->format('c');
  $formatted = $dt->format($format);
  return ' data-dt-title="' . h($iso) . '" title="' . h($formatted) . '"';
}

function end_of_day_after_days(int $days, ?DateTimeImmutable $base = null): string {
  if ($days < 0) $days = 0;
  $tz = user_timezone();
  $base = $base ? $base->setTimezone($tz) : new DateTimeImmutable('today', $tz);
  $start = $base->setTime(0, 0, 0);
  $target = $start->modify('+' . $days . ' days')->setTime(23, 59, 59);
  $dbTarget = user_local_datetime_to_db($target);
  return ($dbTarget ?? $target)->format('Y-m-d H:i:s');
}

function submission_deadline_types(): array {
  return [
    'student' => ['label' => t('deadline.type.student', 'Schüler-Abgabe')],
    'delegation' => ['label' => t('deadline.type.delegation', 'Delegations-Abgabe')],
    'teacher' => ['label' => t('deadline.type.teacher', 'Lehrkraft-Abgabe')],
  ];
}

function fetch_submission_deadlines(PDO $pdo, string $schoolYear, ?string $periodLabel = null): array {
  $schoolYear = trim($schoolYear);
  if ($schoolYear === '') return [];
  if (!db_has_table($pdo, 'submission_deadlines')) return [];
  $periodLabel = normalize_class_period_label($periodLabel);
  $st = $pdo->prepare("SELECT deadline_key, due_at FROM submission_deadlines WHERE school_year=? AND period_label=?");
  $st->execute([$schoolYear, $periodLabel]);
  $rows = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)($row['deadline_key'] ?? '');
    if ($key === '') continue;
    $rows[$key] = $row;
  }
  return $rows;
}

function parse_user_datetime_local(?string $input): ?DateTimeImmutable {
  $value = trim((string)$input);
  if ($value === '') return null;
  $tz = user_timezone();
  $formats = ['Y-m-d\TH:i', 'Y-m-d H:i'];
  foreach ($formats as $format) {
    $dt = DateTimeImmutable::createFromFormat($format, $value, $tz);
    if ($dt instanceof DateTimeImmutable) return $dt;
  }
  try {
    return new DateTimeImmutable($value, $tz);
  } catch (Throwable $e) {
    return null;
  }
}

function deadline_remaining_info(?string $dbDateTime, ?DateTimeImmutable $now = null): ?array {
  $dt = db_datetime_to_user_datetime($dbDateTime);
  if (!$dt) return null;
  $now = $now ?: new DateTimeImmutable('now', user_timezone());
  $diffSeconds = $dt->getTimestamp() - $now->getTimestamp();
  $absSeconds = abs($diffSeconds);
  if ($absSeconds < 60) {
    $value = 1;
    $unit = 'minute';
  } elseif ($absSeconds < 3600) {
    $value = (int)ceil($absSeconds / 60);
    $unit = 'minute';
  } elseif ($absSeconds < 86400) {
    $value = (int)ceil($absSeconds / 3600);
    $unit = 'hour';
  } else {
    $value = (int)ceil($absSeconds / 86400);
    $unit = 'day';
  }
  $unitKey = $value === 1 ? 'deadline.unit.' . $unit : 'deadline.unit.' . $unit . '_plural';
  $unitLabel = t($unitKey, $unit);
  $timeLabel = $value . ' ' . $unitLabel;
  $template = $diffSeconds >= 0
    ? t('deadline.remaining.in', 'noch {time}')
    : t('deadline.remaining.overdue', '{time} überfällig');
  $label = str_replace('{time}', $timeLabel, $template);
  if ($diffSeconds <= 0) {
    $status = 'red';
  } elseif ($diffSeconds <= 3 * 86400) {
    $status = 'red';
  } elseif ($diffSeconds <= 7 * 86400) {
    $status = 'yellow';
  } else {
    $status = 'green';
  }
  return [
    'label' => $label,
    'status' => $status,
    'seconds' => $diffSeconds,
  ];
}

function render_history_replace_state_script(): void {
  echo "\n  <script data-history-replace-state>\n";
  echo "    if (window.history && window.history.replaceState) {\n";
  echo "      window.history.replaceState(null, document.title, window.location.href);\n";
  echo "    }\n";
  echo "  </script>\n";
}

function report_cleanup_delete_instances(PDO $pdo, array $reportIds): void {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($v) => $v > 0)));
  if (!$reportIds) return;
  $in = implode(',', array_fill(0, count($reportIds), '?'));
  $pdo->prepare("DELETE FROM field_values WHERE report_instance_id IN ($in)")->execute($reportIds);
  if (db_has_table($pdo, 'field_value_history')) {
    $pdo->prepare("DELETE FROM field_value_history WHERE report_instance_id IN ($in)")->execute($reportIds);
  }
  $pdo->prepare("DELETE FROM report_instances WHERE id IN ($in)")->execute($reportIds);
}

function cleanup_report_instances_missing_period(PDO $pdo): void {
  if (!db_has_table($pdo, 'report_instances') || !db_has_table($pdo, 'students') || !db_has_table($pdo, 'classes')) return;

  $pdo->exec(
    "UPDATE report_instances ri
     JOIN students s ON s.id=ri.student_id
     JOIN classes c ON c.id=s.class_id
     SET ri.school_year = CASE WHEN COALESCE(TRIM(ri.school_year), '')='' THEN c.school_year ELSE ri.school_year END,
         ri.period_label = CASE WHEN COALESCE(TRIM(ri.period_label), '')='' THEN c.period_label ELSE ri.period_label END
     WHERE ri.student_id IS NOT NULL
       AND (COALESCE(TRIM(ri.school_year), '')='' OR COALESCE(TRIM(ri.period_label), '')='')"
  );

  $st = $pdo->query(
    "SELECT id
     FROM report_instances
     WHERE student_id IS NOT NULL
       AND (COALESCE(TRIM(school_year), '')='' OR COALESCE(TRIM(period_label), '')='')"
  );
  $reportIds = array_map(fn($r) => (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
  report_cleanup_delete_instances($pdo, $reportIds);
}

function cleanup_duplicate_student_report_instances(PDO $pdo): void {
  if (!db_has_table($pdo, 'report_instances') || !db_has_table($pdo, 'students')) return;

  $st = $pdo->query(
    "SELECT COALESCE(s.master_student_id, s.id) AS canonical_student_id,
            ri.school_year,
            ri.period_label,
            GROUP_CONCAT(ri.id ORDER BY ri.updated_at DESC, ri.id DESC SEPARATOR ',') AS report_ids,
            COUNT(*) AS cnt
     FROM report_instances ri
     JOIN students s ON s.id=ri.student_id
     WHERE ri.student_id IS NOT NULL
       AND COALESCE(TRIM(ri.school_year), '')<>''
       AND COALESCE(TRIM(ri.period_label), '')<>''
     GROUP BY canonical_student_id, ri.school_year, ri.period_label
     HAVING COUNT(*) > 1"
  );

  $deleteIds = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($row['report_ids'] ?? ''))), fn($v) => $v > 0));
    if (count($ids) <= 1) continue;
    $deleteIds = array_merge($deleteIds, array_slice($ids, 1));
  }

  report_cleanup_delete_instances($pdo, $deleteIds);
}

// --------------------
// Schema (additive migrations)
// --------------------
function ensure_schema(PDO $pdo): void {
  static $did = false;
  if ($did) return;
  $did = true;

  try {
    // --- classes: add period_label + grade_level + label (keeps legacy `name`)
    if (!db_has_column($pdo, 'classes', 'period_label')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN period_label VARCHAR(50) NOT NULL DEFAULT 'Standard' AFTER school_year");
    }
    if (!db_has_column($pdo, 'classes', 'grade_level')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN grade_level INT NULL AFTER school_year");
    }
    if (!db_has_column($pdo, 'classes', 'label')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN label VARCHAR(10) NULL AFTER grade_level");
    }

    // Helpful unique indexes: allow multiple half-years per class
    if (db_has_index($pdo, 'classes', 'uq_classes_year_name')) {
      try {
        $pdo->exec("DROP INDEX uq_classes_year_name ON classes");
      } catch (Throwable $e) {
        // ignore (shared hosting without ALTER privilege)
      }
    }
    if (!db_has_index($pdo, 'classes', 'uq_classes_year_name_period')) {
      try {
        $pdo->exec("CREATE UNIQUE INDEX uq_classes_year_name_period ON classes (school_year, period_label, name)");
      } catch (Throwable $e) {
        // ignore (shared hosting without ALTER privilege)
      }
    }
    if (db_has_index($pdo, 'classes', 'uq_classes_year_grade_label')) {
      try {
        $pdo->exec("DROP INDEX uq_classes_year_grade_label ON classes");
      } catch (Throwable $e) {
        // ignore (shared hosting without ALTER privilege)
      }
    }
    if (!db_has_index($pdo, 'classes', 'uq_classes_year_grade_label_period')) {
      try {
        $pdo->exec("CREATE UNIQUE INDEX uq_classes_year_grade_label_period ON classes (school_year, period_label, grade_level, label)");
      } catch (Throwable $e) {
        // ignore (shared hosting without ALTER privilege)
      }
    }

    // --- students: add master_student_id to support rollover/copy without re-entry
    if (!db_has_column($pdo, 'students', 'master_student_id')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN master_student_id BIGINT UNSIGNED NULL AFTER id");
    }
    if (!db_has_index($pdo, 'students', 'idx_students_master')) {
      $pdo->exec("CREATE INDEX idx_students_master ON students (master_student_id)");
    }

    // --- classes: add is_active + inactive_at for archiving/hiding old school years
    if (!db_has_column($pdo, 'classes', 'is_active')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name");
    }
    if (!db_has_column($pdo, 'classes', 'tts_enabled')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN tts_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER template_id");
    }
    if (!db_has_column($pdo, 'classes', 'inactive_at')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN inactive_at DATETIME NULL AFTER is_active");
    }
    if (!db_has_index($pdo, 'classes', 'idx_classes_active_year')) {
      $pdo->exec("CREATE INDEX idx_classes_active_year ON classes (is_active, school_year)");
    }

    // --- students: add qr_token + login_code for QR and manual login
    if (!db_has_column($pdo, 'students', 'qr_token')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN qr_token VARCHAR(80) NULL AFTER external_ref");
    }
    if (!db_has_column($pdo, 'students', 'email_student')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN email_student VARCHAR(255) NULL AFTER external_ref");
    }
    if (!db_has_column($pdo, 'students', 'email_parent1')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN email_parent1 VARCHAR(255) NULL AFTER email_student");
    }
    if (!db_has_column($pdo, 'students', 'email_parent2')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN email_parent2 VARCHAR(255) NULL AFTER email_parent1");
    }
    if (!db_has_column($pdo, 'students', 'login_code')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN login_code VARCHAR(20) NULL AFTER qr_token");
    }
    if (!db_has_index($pdo, 'students', 'uq_students_qr_token')) {
      $pdo->exec("CREATE UNIQUE INDEX uq_students_qr_token ON students (qr_token)");
    }
    if (!db_has_index($pdo, 'students', 'idx_students_login_code')) {
      $pdo->exec("CREATE INDEX idx_students_login_code ON students (login_code)");
    }

    // --- report_instances: normalize invalid rows and remove duplicates by canonical student + semester
    cleanup_report_instances_missing_period($pdo);
    cleanup_duplicate_student_report_instances($pdo);

    // --- report_instances: prevent duplicate reports for the same student+semester
    if (!db_has_index($pdo, 'report_instances', 'uq_report_student_period')) {
      try {
        $pdo->exec("CREATE UNIQUE INDEX uq_report_student_period ON report_instances (student_id, school_year, period_label)");
      } catch (Throwable $e) {
        // ignore when existing data still contains duplicates; runtime logic prevents creating new ones
      }
    }

    // --- field_values: ensure SOURCE-AWARE unique key so child + teacher values coexist safely
    if (!db_has_index($pdo, 'field_values', 'uq_field_values_instance_field_source')) {
      // drop legacy unique index if present (would block separate child/teacher rows)
      if (db_has_index($pdo, 'field_values', 'uq_field_values_instance_field')) {
        try {
          $pdo->exec("ALTER TABLE field_values DROP INDEX uq_field_values_instance_field");
        } catch (Throwable $e) {
          // ignore (shared hosting without ALTER privilege)
        }
      }

      try {
        $pdo->exec(
          "CREATE UNIQUE INDEX uq_field_values_instance_field_source " .
          "ON field_values (report_instance_id, template_field_id, source)"
        );
      } catch (Throwable $e) {
        // ignore silently; without this index, child+teacher cannot coexist but app keeps working
      }
    }
    if (!db_has_index($pdo, 'field_values', 'idx_field_values_instance')) {
      $pdo->exec("CREATE INDEX idx_field_values_instance ON field_values (report_instance_id)");
    }
    if (!db_has_index($pdo, 'field_values', 'idx_field_values_field')) {
      $pdo->exec("CREATE INDEX idx_field_values_field ON field_values (template_field_id)");
    }

    // --- student_fields: configurable metadata for students (labels, defaults, bilingual)
    if (!db_has_table($pdo, 'student_fields')) {
      $pdo->exec(
        "CREATE TABLE student_fields (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  field_key VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  label VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  label_en VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT '',\n" .
        "  default_value TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n" .
        "  sort_order INT NOT NULL DEFAULT 0,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_student_fields_key (field_key),\n" .
        "  KEY idx_student_fields_sort (sort_order, id)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- student_field_values: per-student values for custom fields
    if (!db_has_table($pdo, 'student_field_values')) {
      $pdo->exec(
        "CREATE TABLE student_field_values (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  student_id BIGINT UNSIGNED NOT NULL,\n" .
        "  field_id BIGINT UNSIGNED NOT NULL,\n" .
        "  value_text TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_student_field_values (student_id, field_id),\n" .
        "  KEY idx_student_field_values_student (student_id),\n" .
        "  KEY idx_student_field_values_field (field_id)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- text_snippets: helpers for reusable Bausteine
    if (!db_has_table($pdo, 'text_snippets')) {
      $pdo->exec(
        "CREATE TABLE text_snippets (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  title VARCHAR(190) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  category VARCHAR(190) COLLATE utf8mb4_unicode_ci DEFAULT '',\n" .
        "  content TEXT COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  created_by BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  is_generated TINYINT(1) NOT NULL DEFAULT '0',\n" .
        "  is_deleted TINYINT(1) NOT NULL DEFAULT '0',\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  KEY idx_text_snippets_category (category),\n" .
        "  KEY idx_text_snippets_created (created_at)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- parent_portal_links: admin-approved, time-boxed Eltern-Zugänge
    if (!db_has_table($pdo, 'parent_portal_links')) {
      $pdo->exec(
        "CREATE TABLE parent_portal_links (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  student_id BIGINT UNSIGNED NOT NULL,\n" .
        "  report_instance_id BIGINT UNSIGNED NOT NULL,\n" .
        "  token VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  status ENUM('requested','approved','revoked','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',\n" .
        "  requested_by_user_id BIGINT UNSIGNED NOT NULL,\n" .
        "  approved_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  approved_at DATETIME DEFAULT NULL,\n" .
        "  published_at DATETIME DEFAULT NULL,\n" .
        "  expires_at DATETIME DEFAULT NULL,\n" .
        "  preferred_lang VARCHAR(8) COLLATE utf8mb4_unicode_ci DEFAULT 'de',\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_parent_portal_token (token),\n" .
        "  KEY idx_parent_portal_student (student_id),\n" .
        "  KEY idx_parent_portal_report (report_instance_id),\n" .
        "  KEY idx_parent_portal_status (status, expires_at)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- parent_feedback: moderierte Rückmeldungen
    if (!db_has_table($pdo, 'parent_feedback')) {
      $pdo->exec(
        "CREATE TABLE parent_feedback (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  link_id BIGINT UNSIGNED NOT NULL,\n" .
        "  feedback_type ENUM('question','ack') COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  message TEXT COLLATE utf8mb4_unicode_ci,\n" .
        "  language VARCHAR(8) COLLATE utf8mb4_unicode_ci DEFAULT 'de',\n" .
        "  auto_translated TINYINT(1) NOT NULL DEFAULT 0,\n" .
        "  is_reviewed TINYINT(1) NOT NULL DEFAULT 0,\n" .
        "  reviewed_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  reviewed_at DATETIME DEFAULT NULL,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  KEY idx_parent_feedback_link (link_id),\n" .
        "  KEY idx_parent_feedback_state (feedback_type, is_reviewed)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- parent_meeting_feedback: Feedback zum Lernentwicklungsgespräch
    if (!db_has_table($pdo, 'parent_meeting_feedback')) {
      $pdo->exec(
        "CREATE TABLE parent_meeting_feedback (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  student_id BIGINT UNSIGNED NOT NULL,\n" .
        "  class_id BIGINT UNSIGNED NOT NULL,\n" .
        "  school_year VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  grade_level INT DEFAULT NULL,\n" .
        "  link_id BIGINT UNSIGNED NOT NULL,\n" .
        "  q1 TINYINT UNSIGNED NOT NULL,\n" .
        "  q2 TINYINT UNSIGNED NOT NULL,\n" .
        "  q3 TINYINT UNSIGNED NOT NULL,\n" .
        "  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,\n" .
        "  message TEXT COLLATE utf8mb4_unicode_ci,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_parent_meeting_feedback_student (student_id),\n" .
        "  KEY idx_parent_meeting_feedback_class (class_id),\n" .
        "  KEY idx_parent_meeting_feedback_grade (grade_level),\n" .
        "  KEY idx_parent_meeting_feedback_year (school_year)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    } elseif (!db_has_column($pdo, 'parent_meeting_feedback', 'is_anonymous')) {
      $pdo->exec("ALTER TABLE parent_meeting_feedback ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0");
    }

    // --- teacher_signatures: encrypted vector signatures for parent export
    if (!db_has_table($pdo, 'teacher_signatures')) {
      $pdo->exec(
        "CREATE TABLE teacher_signatures (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  user_id BIGINT UNSIGNED NOT NULL,\n" .
        "  purpose VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  enc_key VARBINARY(255) NOT NULL,\n" .
        "  enc_key_iv VARBINARY(32) NOT NULL,\n" .
        "  enc_key_tag VARBINARY(32) NOT NULL,\n" .
        "  iv VARBINARY(32) NOT NULL,\n" .
        "  tag VARBINARY(32) NOT NULL,\n" .
        "  ciphertext LONGBLOB NOT NULL,\n" .
        "  is_active TINYINT(1) NOT NULL DEFAULT 1,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_teacher_signatures_user_purpose (user_id, purpose),\n" .
        "  KEY idx_teacher_signatures_active (user_id, purpose, is_active),\n" .
        "  CONSTRAINT fk_teacher_signatures_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- class_child_group_unlocks: per-class category unlocks for student wizard
    if (!db_has_table($pdo, 'class_child_group_unlocks')) {
      $pdo->exec(
        "CREATE TABLE class_child_group_unlocks (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  class_id BIGINT UNSIGNED NOT NULL,\n" .
        "  school_year VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  period_label VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Standard',\n" .
        "  group_key VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  is_unlocked TINYINT(1) NOT NULL DEFAULT '1',\n" .
        "  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  updated_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_class_child_group_unlock (class_id, school_year, period_label, group_key),\n" .
        "  KEY idx_class_child_group_unlock_class (class_id)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    // --- submission_deadlines: per-school-year deadlines for submissions
    if (!db_has_table($pdo, 'submission_deadlines')) {
      $pdo->exec(
        "CREATE TABLE submission_deadlines (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  school_year VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  period_label VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Standard',\n" .
        "  deadline_key VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  due_at DATETIME DEFAULT NULL,\n" .
        "  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  updated_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_submission_deadlines_year_period_key (school_year, period_label, deadline_key),\n" .
        "  KEY idx_submission_deadlines_year (school_year, period_label),\n" .
        "  KEY idx_submission_deadlines_due (due_at)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    } else {
      if (!db_has_column($pdo, 'submission_deadlines', 'period_label')) {
        $pdo->exec("ALTER TABLE submission_deadlines ADD COLUMN period_label VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Standard' AFTER school_year");
      }
      if (db_has_index($pdo, 'submission_deadlines', 'uq_submission_deadlines_year_key')) {
        $pdo->exec("ALTER TABLE submission_deadlines DROP INDEX uq_submission_deadlines_year_key");
      }
      if (!db_has_index($pdo, 'submission_deadlines', 'uq_submission_deadlines_year_period_key')) {
        $pdo->exec("ALTER TABLE submission_deadlines ADD UNIQUE KEY uq_submission_deadlines_year_period_key (school_year, period_label, deadline_key)");
      }
      if (!db_has_index($pdo, 'submission_deadlines', 'idx_submission_deadlines_year')) {
        $pdo->exec("ALTER TABLE submission_deadlines ADD KEY idx_submission_deadlines_year (school_year, period_label)");
      }
    }


    // --- AG catalog + assignments
    if (!db_has_table($pdo, 'ag_catalog')) {
      $pdo->exec(
        "CREATE TABLE ag_catalog (
" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
" .
        "  school_year VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,
" .
        "  period_label VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Standard',
" .
        "  ag_name VARCHAR(190) COLLATE utf8mb4_unicode_ci NOT NULL,
" .
        "  is_active TINYINT(1) NOT NULL DEFAULT 1,
" .
        "  sort_order INT NOT NULL DEFAULT 0,
" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
" .
        "  PRIMARY KEY (id),
" .
        "  UNIQUE KEY uq_ag_catalog_scope_name (school_year, period_label, ag_name),
" .
        "  KEY idx_ag_catalog_scope (school_year, period_label, is_active, sort_order)
" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }

    if (!db_has_table($pdo, 'student_ag_assignments')) {
      $pdo->exec(
        "CREATE TABLE student_ag_assignments (
" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
" .
        "  student_id BIGINT UNSIGNED NOT NULL,
" .
        "  class_id BIGINT UNSIGNED NOT NULL,
" .
        "  ag_id BIGINT UNSIGNED NOT NULL,
" .
        "  school_year VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,
" .
        "  period_label VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Standard',
" .
        "  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
" .
        "  PRIMARY KEY (id),
" .
        "  UNIQUE KEY uq_student_ag_scope (student_id, ag_id, school_year, period_label),
" .
        "  KEY idx_student_ag_lookup (class_id, school_year, period_label),
" .
        "  CONSTRAINT fk_student_ag_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
" .
        "  CONSTRAINT fk_student_ag_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
" .
        "  CONSTRAINT fk_student_ag_ag FOREIGN KEY (ag_id) REFERENCES ag_catalog(id) ON DELETE CASCADE
" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
      );
    }
  } catch (Throwable $e) {
    // Never hard-fail the app on shared hosting where ALTER privileges may be missing.
  }
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $stmt->execute([$table, $column]);
  return (int)($stmt->fetch()['c'] ?? 0) > 0;
}

function db_has_index(PDO $pdo, string $table, string $index): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
  $stmt->execute([$table, $index]);
  return (int)($stmt->fetch()['c'] ?? 0) > 0;
}

function db_has_table(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $stmt->execute([$table]);
  return (int)($stmt->fetch()['c'] ?? 0) > 0;
}

// --------------------
// Auth helpers
// --------------------
function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) redirect('login.php');
}

function get_role(): string {
  $u = current_user();
  $role = (string)($u['role'] ?? '');
  if ($role === '') {
    $role = (string)($u['actual_role'] ?? '');
  }
  if ($role === 'admin' || $role === 'teacher') {
    return $role;
  }
  return '';
}

function require_admin(): void {
  require_login();
  if (get_role() !== 'admin') {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
}

function require_teacher(): void {
  require_login();
  $role = get_role();
  if ($role !== 'teacher' && $role !== 'admin') {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
}

function is_actual_admin(): bool {
  $u = current_user();
  $actualRole = (string)($u['actual_role'] ?? $u['role'] ?? '');
  return $actualRole === 'admin';
}

function user_can_access_class(PDO $pdo, int $userId, int $classId): bool {
  // Admins can access everything.
  $u = current_user();
  if (($u['role'] ?? '') === 'admin') return true;

  // 1) Explicit class assignments (Klassenzuordnung)
  $q = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
  $q->execute([$userId, $classId]);
  if ((bool)$q->fetch()) return true;

  // 2) Delegations: a teacher may access a class if at least one group was delegated to them.
  //    (IMPORTANT: This must NOT create user_class_assignments, otherwise the delegate appears as class teacher.)
  try {
    $q2 = $pdo->prepare("SELECT 1 FROM class_group_delegations WHERE class_id=? AND user_id=? LIMIT 1");
    $q2->execute([$classId, $userId]);
    if ((bool)$q2->fetch()) return true;
  } catch (Throwable $e) {
    // If table doesn't exist yet (during migration), ignore and fall back to assignments only.
  }

  return false;
}

// --------------------
// Student session helpers
// --------------------
function current_student(): ?array {
  return isset($_SESSION['student']) && is_array($_SESSION['student']) ? $_SESSION['student'] : null;
}

function require_student(): void {
  if (!isset($_SESSION['student']['id'])) {
    header('Location: ' . url('student/login.php'));
    exit;
  }
}

// --------------------
// CSRF helpers
// --------------------
function csrf_token(): string {
  if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 16) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_verify(): void {
  $t = $_POST['csrf_token'] ?? '';
  if (!is_string($t) || $t === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
    throw new RuntimeException('CSRF Token ungültig.');
  }
}

// --------------------
// Audit
// --------------------
function audit(string $event, ?int $userId = null, array $details = []): void {
  try {
    $pdo = db();
    $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $stmt = $pdo->prepare(
      "INSERT INTO audit_log (event_type, user_id, ip_address, user_agent, details_json)
       VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
      $event,
      $userId,
      $ip,
      $ua,
      $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
    ]);
  } catch (Throwable $e) {
    // never crash app because of audit
  }
}

// --------------------
// Student custom fields
// --------------------

function list_student_custom_fields(PDO $pdo): array {
  if (!db_has_table($pdo, 'student_fields')) return [];
  return $pdo->query(
    "SELECT id, field_key, label, label_en, default_value, sort_order\n" .
    "FROM student_fields\n" .
    "ORDER BY sort_order ASC, id ASC"
  )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function student_custom_value_map(PDO $pdo, int $studentId): array {
  if ($studentId <= 0) return [];
  if (!db_has_table($pdo, 'student_fields')) return [];

  $sql =
    "SELECT sf.field_key, sf.default_value, v.value_text\n" .
    "FROM student_fields sf\n" .
    "LEFT JOIN student_field_values v ON v.field_id = sf.id AND v.student_id = ?\n" .
    "ORDER BY sf.sort_order ASC, sf.id ASC";

  $st = $pdo->prepare($sql);
  $st->execute([$studentId]);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = trim((string)($row['field_key'] ?? ''));
    if ($key === '') continue;
    $val = $row['value_text'] ?? $row['default_value'] ?? '';
    $out[$key] = (string)$val;
  }
  return $out;
}

function save_student_custom_values(PDO $pdo, int $studentId, array $values, bool $fillDefaults = false): void {
  if ($studentId <= 0) return;
  $fields = list_student_custom_fields($pdo);
  if (!$fields) return;

  $ins = $pdo->prepare(
    "INSERT INTO student_field_values (student_id, field_id, value_text)\n" .
    "VALUES (?, ?, ?)\n" .
    "ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = CURRENT_TIMESTAMP"
  );

  $map = [];
  foreach ($fields as $f) {
    $key = (string)($f['field_key'] ?? '');
    if ($key === '') continue;
    $map[$key] = [
      'id' => (int)$f['id'],
      'default' => (string)($f['default_value'] ?? ''),
    ];
  }

  foreach ($map as $key => $meta) {
    $hasInput = array_key_exists($key, $values);
    $val = $hasInput ? trim((string)$values[$key]) : null;

    if ($val === null && $fillDefaults) {
      $val = $meta['default'];
    }

    if ($val === null) continue;
    $ins->execute([$studentId, (int)$meta['id'], $val]);
  }
}

function copy_student_custom_values(PDO $pdo, int $sourceStudentId, int $targetStudentId): bool {
  if ($sourceStudentId <= 0 || $targetStudentId <= 0) return false;
  if (!db_has_table($pdo, 'student_field_values')) return false;

  $sel = $pdo->prepare(
    "SELECT field_id, value_text FROM student_field_values WHERE student_id = ?"
  );
  $sel->execute([$sourceStudentId]);
  $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return false;

  $ins = $pdo->prepare(
    "INSERT INTO student_field_values (student_id, field_id, value_text)\n" .
    "VALUES (?, ?, ?)\n" .
    "ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = CURRENT_TIMESTAMP"
  );

  $copied = false;
  foreach ($rows as $r) {
    $fid = (int)($r['field_id'] ?? 0);
    if ($fid <= 0) continue;
    $ins->execute([$targetStudentId, $fid, (string)($r['value_text'] ?? '')]);
    $copied = true;
  }
  return $copied;
}

// --------------------
// System bindings (master data -> template fields)
// --------------------

/**
 * Returns the system value for a binding key.
 * Binding keys are stored in template_fields.meta_json.system_binding.
 */
function resolve_system_binding_value(string $binding, array $student, array $class): ?string {
  if (strpos($binding, 'student.custom.') === 0) {
    $key = substr($binding, strlen('student.custom.'));
    if ($key === '') return null;
    $custom = $student['custom_fields'] ?? [];
    if (array_key_exists($key, $custom)) {
      return (string)$custom[$key];
    }
    return '';
  }

  switch ($binding) {
    case 'student.first_name':
      return (string)($student['first_name'] ?? '');
    case 'student.last_name':
      return (string)($student['last_name'] ?? '');
    case 'student.date_of_birth':
      // Default format: YYYY-MM-DD (PDF date fields can be formatted later)
      return (string)($student['date_of_birth'] ?? '');
    case 'class.school_year':
      return (string)($class['school_year'] ?? '');
    case 'class.grade_level':
      return $class['grade_level'] !== null ? (string)(int)$class['grade_level'] : '';
    case 'class.label':
      return (string)($class['label'] ?? '');
    case 'class.display':
      $g = $class['grade_level'] !== null ? (int)$class['grade_level'] : null;
      $l = (string)($class['label'] ?? '');
      $n = (string)($class['name'] ?? '');
      if ($g !== null && $l !== '') return (string)$g . $l;
      return $n;
    default:
      return null;
  }
}

/**
 * Format an ISO-ish date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS) to a Moment-like pattern.
 * Supported tokens: DD, D, MM, M, YYYY, YY, MMM, MMMM
 * Examples: "DD.MM.YYYY", "MM/DD/YYYY", "D. MMMM YYYY".
 */
// Zeilen 325–382
function format_date_pattern(?string $iso, string $pattern): string {
  $iso = trim((string)$iso);
  if ($iso === '') return '';

  // Accept YYYY-MM-DD or any string DateTime can parse.
  try {
    $dt = new DateTimeImmutable($iso);
  } catch (Throwable $e) {
    $datePart = substr($iso, 0, 10);
    try {
      $dt = new DateTimeImmutable($datePart);
    } catch (Throwable $e2) {
      return $iso; // fallback: keep original
    }
  }

  // German month names
  $monthsShort = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
    7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez'
  ];
  $monthsLong = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
    7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
  ];

  $m = (int)$dt->format('n');

  $repl = [
    'MMMM' => $monthsLong[$m] ?? $dt->format('F'),
    'MMM'  => $monthsShort[$m] ?? $dt->format('M'),
    'YYYY' => $dt->format('Y'),
    'YY'   => $dt->format('y'),
    'DD'   => $dt->format('d'),
    'D'    => (string)(int)$dt->format('j'),
    'MM'   => $dt->format('m'),
    'M'    => (string)(int)$dt->format('n'),
  ];

  // Replace ONLY tokens in the original pattern (longest first to avoid partial matches)
  $out = preg_replace_callback(
    '/(MMMM|MMM|YYYY|YY|DD|MM|D|M)/',
    static function(array $m) use ($repl): string {
      $tok = $m[1];
      return $repl[$tok] ?? $tok;
    },
    $pattern
  );

  return (string)$out;
}

/**
 * Resolve a binding template like:
 *   "{{student.first_name}} {{student.last_name}} ({{class.display}})"
 */
function resolve_system_binding_template(string $tpl, array $student, array $class, array $fieldMeta = [], ?string $fieldType = null): string {
  $tpl = (string)$tpl;
  if ($tpl === '') return '';

  $dateFmt = '';
  if (($fieldType ?? '') === 'date' || isset($fieldMeta['date_format_mode']) || isset($fieldMeta['date_format_preset']) || isset($fieldMeta['date_format_custom'])) {
    $mode = (string)($fieldMeta['date_format_mode'] ?? 'preset');
    if ($mode === 'custom') $dateFmt = (string)($fieldMeta['date_format_custom'] ?? '');
    else $dateFmt = (string)($fieldMeta['date_format_preset'] ?? '');
    $dateFmt = trim($dateFmt);
  }

  $out = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function($m) use ($student, $class, $dateFmt) {
    $key = (string)($m[1] ?? '');
    $val = resolve_system_binding_value($key, $student, $class);
    if ($val === null) return '';
    if ($key === 'student.date_of_birth' && $dateFmt !== '') {
      return format_date_pattern((string)$val, $dateFmt);
    }
    return (string)$val;
  }, $tpl);

  return $out === null ? '' : (string)$out;
}

/**
 * Upserts all bound (system) template fields into field_values for a report instance.
 * This is safe to call multiple times.
 */
function apply_system_bindings(PDO $pdo, int $reportInstanceId): void {
  $ri = $pdo->prepare(
    "SELECT ri.id, ri.template_id, ri.student_id, ri.school_year, ri.period_label,
            s.first_name, s.last_name, s.date_of_birth, s.class_id
     FROM report_instances ri
     LEFT JOIN students s ON s.id=ri.student_id
     WHERE ri.id=? LIMIT 1"
  );
  $ri->execute([$reportInstanceId]);
  $row = $ri->fetch(PDO::FETCH_ASSOC);
  if (!$row) return;

  $classId = (int)($row['class_id'] ?? 0);
  if ($classId <= 0) {
    $classId = class_id_from_report_period_label($row['period_label'] ?? null);
  }
  $class = [];
  if ($classId > 0) {
    $cs = $pdo->prepare("SELECT id, school_year, period_label, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
    $cs->execute([$classId]);
    $class = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
  }

  $studentId = (int)($row['student_id'] ?? 0);
  $student = [
    'first_name' => $row['first_name'] ?? '',
    'last_name' => $row['last_name'] ?? '',
    'date_of_birth' => $row['date_of_birth'] ?? '',
    'custom_fields' => $studentId > 0 ? student_custom_value_map($pdo, $studentId) : [],
  ];

  $tf = $pdo->prepare(
    "SELECT id, field_type, meta_json
     FROM template_fields
     WHERE template_id=?"
  );
  $tf->execute([(int)$row['template_id']]);

  $up = $pdo->prepare(
    "INSERT INTO field_values (report_instance_id, template_field_id, value_text, source, updated_by_user_id, updated_at)
     VALUES (?, ?, ?, 'system', NULL, NOW())
     ON DUPLICATE KEY UPDATE value_text=VALUES(value_text), source='system', updated_by_user_id=NULL, updated_at=NOW()"
  );

  foreach ($tf->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $meta = [];
    if (!empty($f['meta_json'])) {
      $meta = json_decode((string)$f['meta_json'], true);
      if (!is_array($meta)) $meta = [];
    }

    $fieldType = isset($f['field_type']) ? (string)$f['field_type'] : null;

    $tpl = isset($meta['system_binding_tpl']) ? trim((string)$meta['system_binding_tpl']) : '';
    if ($tpl !== '') {
      $val = resolve_system_binding_template($tpl, $student, $class, $meta, $fieldType);
      $up->execute([$reportInstanceId, (int)$f['id'], $val]);
      continue;
    }

    $binding = isset($meta['system_binding']) ? trim((string)$meta['system_binding']) : '';
    if ($binding === '') continue;
    $val = resolve_system_binding_value($binding, $student, $class);
    if ($val === null) continue;

    if ($binding === 'student.date_of_birth') {
      $mode = (string)($meta['date_format_mode'] ?? 'preset');
      $fmt = $mode === 'custom' ? (string)($meta['date_format_custom'] ?? '') : (string)($meta['date_format_preset'] ?? '');
      $fmt = trim($fmt);
      if ($fmt !== '' && (($fieldType ?? '') === 'date' || isset($meta['date_format_mode']))) {
        $val = format_date_pattern((string)$val, $fmt);
      }
    }

    $up->execute([$reportInstanceId, (int)$f['id'], (string)$val]);
  }
}


function ag_sentence_for_names(string $firstName, array $agNames): string {
  $firstName = trim($firstName);
  $clean = [];
  foreach ($agNames as $name) {
    $n = trim((string)$name);
    if ($n !== '') $clean[] = $n;
  }
  if (!$clean) return '';
  $parts = array_map(static fn(string $n): string => 'der ' . $n, $clean);
  $count = count($parts);
  if ($count === 1) $list = $parts[0];
  elseif ($count === 2) $list = $parts[0] . ' und ' . $parts[1];
  else $list = implode(', ', array_slice($parts, 0, -1)) . ' und ' . $parts[$count - 1];
  return $firstName . ' hat erfolgreich an ' . $list . ' teilgenommen.';
}

function report_instance_ag_text(PDO $pdo, int $reportInstanceId): string {
  if ($reportInstanceId <= 0 || !db_has_table($pdo, 'student_ag_assignments')) return '';
  $st = $pdo->prepare(
    "SELECT s.first_name, a.ag_name
" .
    "FROM report_instances ri
" .
    "JOIN students s ON s.id=ri.student_id
" .
    "LEFT JOIN student_ag_assignments saa ON saa.student_id=s.id AND saa.school_year=ri.school_year AND saa.period_label=ri.period_label
" .
    "LEFT JOIN ag_catalog a ON a.id=saa.ag_id
" .
    "WHERE ri.id=?
" .
    "ORDER BY a.sort_order ASC, a.ag_name ASC"
  );
  $st->execute([$reportInstanceId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$rows) return '';
  $firstName = (string)($rows[0]['first_name'] ?? '');
  $names = [];
  foreach ($rows as $r) {
    $name = trim((string)($r['ag_name'] ?? ''));
    if ($name !== '') $names[] = $name;
  }
  return ag_sentence_for_names($firstName, $names);
}

// --------------------
// Mail (simple PHP mail wrapper)
// --------------------
function send_email(string $to, string $subject, string $htmlBody): bool {
  $cfg = app_config();
  $fromEmail = $cfg['mail']['from_email'] ?? 'no-reply@example.org';
  $fromName  = $cfg['mail']['from_name'] ?? 'LEG Tool';

  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: text/html; charset=utf-8';
  $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$fromEmail}>";

  return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
}

// --------------------
// Password reset tokens
// --------------------
function create_password_reset_token(int $userId, int $hoursValid = 1, bool $invalidateOld = true): string {
  $pdo = db();
  if ($invalidateOld) {
    $pdo->prepare("UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL")->execute([$userId]);
  }

  $raw = bin2hex(random_bytes(32));
  $hash = hash('sha256', $raw);
  $expires = (new DateTimeImmutable('now'))->modify("+{$hoursValid} hours")->format('Y-m-d H:i:s');

  $stmt = $pdo->prepare(
    "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)"
  );
  $stmt->execute([$userId, $hash, $expires]);

  return $raw;
}

// --------------------
// Email templates
// --------------------
function build_set_password_email(string $name, string $email, string $link): string {
  $b = brand();
  $org = h($b['org_name'] ?? 'LEG Tool');
  $primary = h($b['primary'] ?? '#0b57d0');

  $safeName = h($name);
  $safeEmail = h($email);
  $safeLink = h($link);
  $title = strtr(t('auth.email.set_password.title'), ['{org}' => $org]);
  $greeting = strtr(t('auth.email.greeting'), ['{name}' => $safeName]);
  $intro = strtr(t('auth.email.set_password.intro'), ['{email}' => $safeEmail]);
  $button = t('auth.email.set_password.button');
  $note = t('auth.email.set_password.note');

  return <<<HTML
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; line-height:1.4">
    <h2 style="margin:0 0 10px 0;">{$title}</h2>
    <p>{$greeting}</p>
    <p>{$intro}</p>
    <p><a href="{$safeLink}" style="display:inline-block; padding:10px 14px; background:{$primary}; color:#fff; text-decoration:none; border-radius:10px;">{$button}</a></p>
    <p class="muted" style="color:#666;">{$note}</p>
  </div>
HTML;
}

function build_reset_link_email(string $name, string $email, string $link): string {
  $b = brand();
  $org = h($b['org_name'] ?? 'LEG Tool');
  $primary = h($b['primary'] ?? '#0b57d0');

  $safeName = h($name);
  $safeEmail = h($email);
  $safeLink = h($link);
  $title = strtr(t('auth.email.reset.title'), ['{org}' => $org]);
  $greeting = strtr(t('auth.email.greeting'), ['{name}' => $safeName]);
  $intro = strtr(t('auth.email.reset.intro'), ['{email}' => $safeEmail]);
  $button = t('auth.email.reset.button');
  $note = t('auth.email.reset.note');

  return <<<HTML
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; line-height:1.4">
    <h2 style="margin:0 0 10px 0;">{$title}</h2>
    <p>{$greeting}</p>
    <p>{$intro}</p>
    <p><a href="{$safeLink}" style="display:inline-block; padding:10px 14px; background:{$primary}; color:#fff; text-decoration:none; border-radius:10px;">{$button}</a></p>
    <p style="color:#666;">{$note}</p>
  </div>
HTML;
}
