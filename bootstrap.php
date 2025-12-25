<?php
// bootstrap.php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
  // install not completed
  header('Location: install.php');
  exit;
}
$config = require $configPath;

session_name($config['app']['session_name'] ?? 'legtool_sess');
session_start();

// Base path comes from config (prevents /admin/admin/... issues)
$basePath = (string)($config['app']['base_path'] ?? '');
$basePath = '/' . ltrim($basePath, '/');
$basePath = rtrim($basePath, '/');
if ($basePath === '/') $basePath = '';
define('APP_BASE_URL', $basePath);

function app_config(): array {
  return require __DIR__ . '/config.php';
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
  $icoIco = url('assets/icons/lebtool-favicon.ico');
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

  $q = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
  $q->execute([$userId, $classId]);
  return (bool)$q->fetch();
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
    "SELECT id, meta_json
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
    $binding = isset($meta['system_binding']) ? (string)$meta['system_binding'] : '';
    if ($binding === '') continue;
    $val = resolve_system_binding_value($binding, $student, $class);
    if ($val === null) continue;
    $up->execute([$reportInstanceId, (int)$f['id'], $val]);
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
