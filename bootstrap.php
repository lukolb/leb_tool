<?php
// bootstrap.php
declare(strict_types=1);

$configPath = getenv('APP_CONFIG_FILE') ?: (__DIR__ . '/config.php');
define('APP_CONFIG_PATH', $configPath);
if (!file_exists(APP_CONFIG_PATH)) {
  // install not completed
  header('Location: install.php');
  exit;
}
$config = require APP_CONFIG_PATH;

session_name($config['app']['session_name'] ?? 'legtool_sess');
session_start();


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

function app_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
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
    ]);
  } else {
    $port = $db['port'] ?? 3306;
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset={$charset}";

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Lightweight, additive schema migrations (shared-hosting friendly).
    // We only add missing columns / indexes that newer features rely on.
    // If DB user lacks ALTER privileges, the app will still run (features may be limited).
    ensure_schema($pdo);
  }
  return $pdo;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// --------------------
// Schema (additive migrations)
// --------------------
function ensure_schema(PDO $pdo): void {
  static $did = false;
  if ($did) return;
  $did = true;

  try {
    // --- classes: add grade_level + label (keeps legacy `name`)
    if (!db_has_column($pdo, 'classes', 'grade_level')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN grade_level INT NULL AFTER school_year");
    }
    if (!db_has_column($pdo, 'classes', 'label')) {
      $pdo->exec("ALTER TABLE classes ADD COLUMN label VARCHAR(10) NULL AFTER grade_level");
    }

    // Helpful unique index: (school_year, grade_level, label)
    if (!db_has_index($pdo, 'classes', 'uq_classes_year_grade_label')) {
      $pdo->exec("CREATE UNIQUE INDEX uq_classes_year_grade_label ON classes (school_year, grade_level, label)");
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
    if (!db_has_column($pdo, 'students', 'login_code')) {
      $pdo->exec("ALTER TABLE students ADD COLUMN login_code VARCHAR(20) NULL AFTER qr_token");
    }
    if (!db_has_index($pdo, 'students', 'uq_students_qr_token')) {
      $pdo->exec("CREATE UNIQUE INDEX uq_students_qr_token ON students (qr_token)");
    }
    if (!db_has_index($pdo, 'students', 'idx_students_login_code')) {
      $pdo->exec("CREATE INDEX idx_students_login_code ON students (login_code)");
    }

    // --- field_values: ensure unique key for safe UPSERTs (system bindings, etc.)
    // If this index is missing, INSERT ... ON DUPLICATE KEY UPDATE will NOT update,
    // causing duplicate rows and unpredictable reads (e.g. only first_name appearing).
    if (!db_has_index($pdo, 'field_values', 'uq_field_values_instance_field')) {
      $pdo->exec("CREATE UNIQUE INDEX uq_field_values_instance_field ON field_values (report_instance_id, template_field_id)");
    }
    if (!db_has_index($pdo, 'field_values', 'idx_field_values_instance')) {
      $pdo->exec("CREATE INDEX idx_field_values_instance ON field_values (report_instance_id)");
    }
    if (!db_has_index($pdo, 'field_values', 'idx_field_values_field')) {
      $pdo->exec("CREATE INDEX idx_field_values_field ON field_values (template_field_id)");
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
    if ($role == 'teacher') {
      return "teacher";
    } else if($role == 'admin') {
        return "admin";
    } else {
        return null;
    }
}

function require_admin(): void {
  require_login();
  $u = current_user();
  if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
}

function require_teacher(): void {
  require_login();
  $u = current_user();
  $role = (string)($u['role'] ?? '');
  if ($role !== 'teacher' && $role !== 'admin') {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
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
// System bindings (master data -> template fields)
// --------------------

/**
 * Returns the system value for a binding key.
 * Binding keys are stored in template_fields.meta_json.system_binding.
 */
function resolve_system_binding_value(string $binding, array $student, array $class): ?string {
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
    "SELECT ri.id, ri.template_id, ri.student_id, ri.school_year, s.first_name, s.last_name, s.date_of_birth, s.class_id
     FROM report_instances ri
     JOIN students s ON s.id=ri.student_id
     WHERE ri.id=? LIMIT 1"
  );
  $ri->execute([$reportInstanceId]);
  $row = $ri->fetch(PDO::FETCH_ASSOC);
  if (!$row) return;

  $classId = (int)($row['class_id'] ?? 0);
  $class = [];
  if ($classId > 0) {
    $cs = $pdo->prepare("SELECT id, school_year, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
    $cs->execute([$classId]);
    $class = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
  }

  $student = [
    'first_name' => $row['first_name'] ?? '',
    'last_name' => $row['last_name'] ?? '',
    'date_of_birth' => $row['date_of_birth'] ?? '',
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
function create_password_reset_token(int $userId, int $minutesValid = 60, bool $invalidateOld = true): string {
  $pdo = db();
  if ($invalidateOld) {
    $pdo->prepare("UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL")->execute([$userId]);
  }

  $raw = bin2hex(random_bytes(32));
  $hash = hash('sha256', $raw);
  $expires = (new DateTimeImmutable('now'))->modify("+{$minutesValid} minutes")->format('Y-m-d H:i:s');

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

  return <<<HTML
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; line-height:1.4">
    <h2 style="margin:0 0 10px 0;">{$org} – Konto erstellt</h2>
    <p>Hallo {$safeName},</p>
    <p>für dich wurde ein Konto (<strong>{$safeEmail}</strong>) angelegt. Bitte setze dein Passwort über diesen Link:</p>
    <p><a href="{$safeLink}" style="display:inline-block; padding:10px 14px; background:{$primary}; color:#fff; text-decoration:none; border-radius:10px;">Passwort setzen</a></p>
    <p class="muted" style="color:#666;">Der Link ist zeitlich begrenzt gültig.</p>
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

  return <<<HTML
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; line-height:1.4">
    <h2 style="margin:0 0 10px 0;">{$org} – Passwort zurücksetzen</h2>
    <p>Hallo {$safeName},</p>
    <p>für dein Konto (<strong>{$safeEmail}</strong>) wurde ein Passwort-Reset angefordert. Nutze diesen Link:</p>
    <p><a href="{$safeLink}" style="display:inline-block; padding:10px 14px; background:{$primary}; color:#fff; text-decoration:none; border-radius:10px;">Passwort zurücksetzen</a></p>
    <p style="color:#666;">Wenn du das nicht warst, ignoriere diese E-Mail.</p>
  </div>
HTML;
}
