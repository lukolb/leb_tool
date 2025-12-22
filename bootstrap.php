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
  return $pdo;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

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
