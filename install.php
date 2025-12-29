<?php
// install.php (standalone; shared-hosting friendly)
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function base_path(): string {
  $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
  $appRoot = realpath(__DIR__);
  $base = '';
  if ($docRoot && $appRoot && str_starts_with($appRoot, $docRoot)) {
    $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
  }
  $base = '/' . ltrim($base, '/');
  return rtrim($base, '/');
}

function url_local(string $path): string {
  $p = '/' . ltrim($path, '/');
  return base_path() . $p;
}
function redirect_local(string $path): never {
  header('Location: ' . url_local($path));
  exit;
}

function absolute_base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme . '://' . $host . base_path();
}

$configPath = __DIR__ . '/config.php';
$samplePath = __DIR__ . '/config.sample.php';

if (file_exists($configPath)) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_installer') {
    $deleted = @unlink(__FILE__);

    if ($deleted) {
      header('Location: ' . url_local('login.php'));
      exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="de"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Installation abgeschlossen</title>
      <link rel="stylesheet" href="<?=h(url_local('assets/app.css'))?>">
    </head><body class="page">
      <div class="card" style="max-width:820px; margin:24px auto;">
        <h1>Installation abgeschlossen</h1>
        <p><strong>Hinweis:</strong> <code>install.php</code> konnte nicht automatisch gelöscht werden (fehlende Rechte).</p>
        <p>Bitte lösche die Datei manuell per FTP.</p>
        <p>👉 <a class="btn" href="<?= h(url_local('login.php')) ?>">Zum Login</a></p>
      </div>
    </body></html>
    <?php
    exit;
  }
  redirect_local('login.php');
}

$errors = [];
$ok = false;

$dbHost = $_POST['db_host'] ?? '';
$dbPort = $_POST['db_port'] ?? '3306';
$dbName = $_POST['db_name'] ?? '';
$dbUser = $_POST['db_user'] ?? '';
$dbPass = $_POST['db_pass'] ?? '';

$adminEmail = $_POST['admin_email'] ?? '';
$adminName  = $_POST['admin_name'] ?? 'Admin';
$adminPass  = $_POST['admin_pass'] ?? '';

$orgName    = $_POST['org_name'] ?? 'LEG Tool';
$brandPrimary   = $_POST['brand_primary'] ?? '#0b57d0';
$brandSecondary = $_POST['brand_secondary'] ?? '#111111';
$defaultSchoolYear = $_POST['default_school_year'] ?? '';
$aiKey = $_POST['ai_key'] ?? '';
$aiEnabled = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (isset($_POST['ai_enabled']) ? 1 : 0) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!file_exists($samplePath)) $errors[] = "config.sample.php fehlt.";
  if ($dbHost === '') $errors[] = "DB-Host ist erforderlich.";
  if ($dbName === '' || $dbUser === '') $errors[] = "DB-Name und DB-User sind erforderlich.";
  if (!ctype_digit((string)$dbPort)) $errors[] = "DB-Port muss eine Zahl sein (z.B. 3306).";
  if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "Bitte gültige Admin-E-Mail eingeben.";
  if (strlen($adminPass) < 10) $errors[] = "Admin-Passwort muss mindestens 10 Zeichen haben.";
  if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandPrimary)) $errors[] = "Primary Color muss ein Hex-Wert wie #0b57d0 sein.";
  if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandSecondary)) $errors[] = "Secondary Color muss ein Hex-Wert wie #111111 sein.";

  if (!$errors) {
    try {
      $portInt = (int)$dbPort;
      $dsn = "mysql:host={$dbHost};port={$portInt};dbname={$dbName};charset=utf8mb4";
      $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);

      $schema = getSchemaSql();
      foreach (splitSqlStatements($schema) as $sql) {
        $pdo->exec($sql);
      }

      $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1 AND deleted_at IS NULL");
      $count = (int)($stmt->fetch()['c'] ?? 0);

      $pepper = bin2hex(random_bytes(32));
      $passHash = password_hash($adminPass . $pepper, PASSWORD_DEFAULT);

      if ($count === 0) {
        $ins = $pdo->prepare(
          "INSERT INTO users (email, password_hash, password_set_at, display_name, role, is_active, must_change_password)
           VALUES (?, ?, NOW(), ?, 'admin', 1, 0)"
        );
        $ins->execute([$adminEmail, $passHash, $adminName]);
      }

      $cfg = require $samplePath;
      $cfg['db']['host'] = $dbHost;
      $cfg['db']['port'] = $portInt;
      $cfg['db']['name'] = $dbName;
      $cfg['db']['user'] = $dbUser;
      $cfg['db']['pass'] = $dbPass;
      $cfg['db']['charset'] = 'utf8mb4';

      $cfg['app']['password_pepper'] = $pepper;
      $cfg['app']['base_path'] = base_path();
      $cfg['app']['public_base_url'] = absolute_base_url();
      $cfg['app']['default_school_year'] = $defaultSchoolYear;

      $cfg['app']['brand']['primary'] = $brandPrimary;
      $cfg['app']['brand']['secondary'] = $brandSecondary;
      $cfg['app']['brand']['org_name'] = $orgName;

      if (!isset($cfg['ai']) || !is_array($cfg['ai'])) $cfg['ai'] = [];
      $cfg['ai']['enabled'] = ($aiEnabled === 1);
      $cfg['ai']['api_key'] = trim((string)$aiKey);

      // Logo Upload (optional)
      if (isset($_FILES['brand_logo']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $uploadsDir = __DIR__ . '/' . ($cfg['app']['uploads_dir'] ?? 'uploads');
        $brandingDir = $uploadsDir . '/branding';
        if (!is_dir($brandingDir)) @mkdir($brandingDir, 0755, true);

        $tmp = $_FILES['brand_logo']['tmp_name'];
        $mime = mime_content_type($tmp) ?: '';
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) throw new RuntimeException('Logo muss PNG/JPG/WEBP sein.');
        $ext = $allowed[$mime];

        $destAbs = $brandingDir . '/logo.' . $ext;
        if (!move_uploaded_file($tmp, $destAbs)) throw new RuntimeException('Konnte Logo nicht speichern.');

        $cfg['app']['brand']['logo_path'] = ($cfg['app']['uploads_dir'] ?? 'uploads') . '/branding/logo.' . $ext;
      }

      $export = "<?php\n// config.php (auto-generated)\nreturn " . var_export($cfg, true) . ";\n";
      if (file_put_contents($configPath, $export, LOCK_EX) === false) {
        throw new RuntimeException("Konnte config.php nicht schreiben. Rechte prüfen.");
      }

      $ok = true;
    } catch (Throwable $e) {
      $errors[] = "Fehler: " . $e->getMessage();
    }
  }
}

function splitSqlStatements(string $sql): array {
  $sql = preg_replace('/^\s*--.*$/m', '', $sql);
  $sql = trim($sql);
  $parts = preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql);
  $out = [];
  foreach ($parts as $p) {
    $p = trim($p);
    if ($p !== '') $out[] = $p . ';';
  }
  return $out;
}

function getSchemaSql(): string {
  $JSON = "LONGTEXT";
  return <<<SQL
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_set_at` datetime DEFAULT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','teacher') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'teacher',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `master_student_id` bigint UNSIGNED DEFAULT NULL,
  `class_id` bigint UNSIGNED DEFAULT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `external_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_token` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `login_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_qr_token` (`qr_token`),
  KEY `idx_students_class` (`class_id`),
  KEY `idx_students_name` (`last_name`,`first_name`),
  KEY `idx_students_master` (`master_student_id`),
  KEY `idx_students_login_code` (`login_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_fields` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_en` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `default_value` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_fields_key` (`field_key`),
  KEY `idx_student_fields_sort` (`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_field_values` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` bigint UNSIGNED NOT NULL,
  `field_id` bigint UNSIGNED NOT NULL,
  `value_text` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_field_values` (`student_id`,`field_id`),
  KEY `idx_student_field_values_student` (`student_id`),
  KEY `idx_student_field_values_field` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `templates` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_version` int UNSIGNED NOT NULL DEFAULT '1',
  `pdf_storage_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf_original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf_sha256` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_templates_name_version` (`name`,`template_version`),
  KEY `idx_templates_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  
CREATE TABLE IF NOT EXISTS `template_fields` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` bigint UNSIGNED NOT NULL,
  `field_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` enum('text','multiline','date','number','grade','checkbox','radio','select','signature') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'radio',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label_en` VARCHAR(255) NULL
  `help_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_multiline` tinyint(1) NOT NULL DEFAULT '0',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `can_child_edit` tinyint(1) NOT NULL DEFAULT '0',
  `can_teacher_edit` tinyint(1) NOT NULL DEFAULT '1',
  `allowed_roles_json` $JSON COLLATE utf8mb4_unicode_ci,
  `options_json` $JSON COLLATE utf8mb4_unicode_ci,
  `meta_json` $JSON COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_fields_template_fieldname` (`template_id`,`field_name`),
  KEY `idx_template_fields_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_instances` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` bigint UNSIGNED NOT NULL,
  `student_id` bigint UNSIGNED NOT NULL,
  `period_label` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_year` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','submitted','locked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `locked_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_unique` (`template_id`,`student_id`,`school_year`,`period_label`),
  KEY `idx_report_student` (`student_id`),
  KEY `idx_report_template` (`template_id`),
  KEY `idx_report_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `field_values` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_instance_id` bigint UNSIGNED NOT NULL,
  `template_field_id` bigint UNSIGNED NOT NULL,
  `value_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `value_json` $JSON COLLATE utf8mb4_unicode_ci,
  `source` enum('child','teacher','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'teacher',
  `updated_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `updated_by_student_id` bigint UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_field_values_instance_field_source` (`report_instance_id`,`template_field_id`,`source`),
  KEY `idx_field_values_instance` (`report_instance_id`),
  KEY `idx_field_values_field` (`template_field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `field_value_history` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_instance_id` bigint UNSIGNED NOT NULL,
  `template_field_id` bigint UNSIGNED NOT NULL,
  `value_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `value_json` $JSON COLLATE utf8mb4_unicode_ci,
  `source` enum('child','teacher','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'teacher',
  `updated_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `updated_by_student_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fvh_instance` (`report_instance_id`),
  KEY `idx_fvh_field` (`template_field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_collaborators` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_instance_id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `role_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `can_edit` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_collab` (`report_instance_id`,`user_id`),
  KEY `idx_collab_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qr_tokens` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` bigint UNSIGNED NOT NULL,
  `report_instance_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('child_edit','child_view') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'child_edit',
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qr_token_hash` (`token_hash`),
  KEY `idx_qr_student` (`student_id`),
  KEY `idx_qr_report` (`report_instance_id`),
  KEY `idx_qr_validity` (`expires_at`,`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `student_id` bigint UNSIGNED DEFAULT NULL,
  `report_instance_id` bigint UNSIGNED DEFAULT NULL,
  `template_field_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details_json` $JSON COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_student` (`student_id`),
  KEY `idx_audit_report` (`report_instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prt_token_hash` (`token_hash`),
  KEY `idx_prt_user` (`user_id`),
  KEY `idx_prt_valid` (`expires_at`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `user_class_assignments` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `class_id` bigint UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_class` (`user_id`,`class_id`),
  KEY `idx_uca_user` (`user_id`),
  KEY `idx_uca_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
          
CREATE TABLE IF NOT EXISTS `option_scales` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applies_to` enum('radio','select','grade','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `options_json` $JSON COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_option_scales_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `option_list_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_option_list_templates_name` (`name`),
  KEY `idx_option_list_templates_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `option_list_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `list_id` int NOT NULL,
  `value` varchar(190) NOT NULL,
  `label` varchar(190) NOT NULL,
  `label_en` VARCHAR(190) NOT NULL DEFAULT ''
  `icon_id` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `meta_json` $JSON CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_list_id_sort` (`list_id`,`sort_order`),
  KEY `idx_option_list_items_icon` (`icon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
          
CREATE TABLE IF NOT EXISTS `icon_library` (
  `id` int NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `file_ext` varchar(16) NOT NULL,
  `mime_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `created_by_user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_icon_storage_path` (`storage_path`),
  KEY `idx_icon_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `text_snippets` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `is_generated` tinyint(1) NOT NULL DEFAULT '0',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_text_snippets_category` (`category`),
  KEY `idx_text_snippets_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `classes` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade_level` int DEFAULT NULL,
  `label` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` bigint UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `inactive_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_year_name` (`school_year`,`name`),
  UNIQUE KEY `uq_classes_year_grade_label` (`school_year`,`grade_level`,`label`),
  KEY `idx_classes_active_year` (`is_active`,`school_year`),
  KEY `idx_classes_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
          
CREATE TABLE IF NOT EXISTS `class_group_delegations` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_id` bigint UNSIGNED NOT NULL,
  `school_year` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_label` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Standard',
  `group_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `status` enum('open','done') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `updated_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_group_deleg` (`class_id`,`school_year`,`period_label`,`group_key`),
  KEY `idx_class_group_deleg_user` (`user_id`),
  KEY `idx_class_group_deleg_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
          
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_classes_template_id` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `option_list_items`
  ADD CONSTRAINT `fk_option_list_items_list` FOREIGN KEY (`list_id`) REFERENCES `option_list_templates` (`id`) ON DELETE CASCADE;

ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `user_class_assignments`
  ADD CONSTRAINT `fk_uca_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_uca_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
          
CREATE TRIGGER `delete_old_password_tokens` BEFORE INSERT ON `audit_log` FOR EACH ROW DELETE FROM password_reset_tokens WHERE expires_at < NOW();
          
COMMIT;
          
SQL;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LEG Tool – Installation</title>
  <link rel="stylesheet" href="<?=h(url_local('assets/app.css'))?>">
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <div>
        <div class="brand-title" id="installPreviewTitle">Installation</div>
        <div class="brand-subtitle" id="installPreviewSub"><?=h(absolute_base_url())?></div>
      </div>
    </div>
  </div>

  <div class="container" style="max-width:980px;">
    <h1>LEG Tool – Installation</h1>

    <?php if ($ok): ?>
      <div class="alert success">
        <strong>Fertig!</strong> Datenbank ist initialisiert, Tabellen sind angelegt, Admin ist erstellt (falls keiner existierte).
        <div class="actions">
          <a class="btn" href="<?= h(url_local('login.php')) ?>">➡️ Zum Login</a>
          <form method="post" action="<?= h(url_local('install.php')) ?>" style="margin:0;">
            <input type="hidden" name="action" value="delete_installer">
            <button class="btn danger" type="submit">install.php jetzt löschen</button>
          </form>
        </div>
        <p class="muted">
          Wenn das Löschen nicht klappt: bitte <code>install.php</code> per FTP löschen oder serverseitig sperren.
        </p>
      </div>

    <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert danger">
          <strong>Bitte korrigieren:</strong>
          <ul>
            <?php foreach ($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- Live Preview -->
      <div class="card" id="installPreviewCard">
        <h2>Live-Preview</h2>
        <p class="muted">Branding-Änderungen werden hier sofort sichtbar (ohne Speichern). Installiert wird erst mit „Installieren“.</p>

        <div style="border:1px solid var(--border); border-radius:16px; overflow:hidden;">
          <div style="background:#fff; border-bottom:1px solid var(--border);">
            <div style="display:flex; align-items:center; gap:12px; padding:14px 16px;">
              <img id="installPreviewLogo" src="" alt="Logo"
                   style="height:34px; width:auto; display:none; background:#fff;">
              <div>
                <div id="installPreviewOrg" style="font-weight:750; letter-spacing:.2px;"><?=h($orgName)?></div>
                <div style="color:var(--muted); font-size:12px;">Beispielansicht</div>
              </div>
            </div>
          </div>

          <div style="padding:16px; background:var(--bg);">
            <div class="actions">
              <a class="btn primary" href="javascript:void(0)">Primary Button</a>
              <a class="btn secondary" href="javascript:void(0)">Secondary Button</a>
              <a class="btn danger" href="javascript:void(0)">Danger Button</a>
            </div>
            <div style="margin-top:12px;">
              <span class="pill">Pill</span>
              <span class="pill">Badge</span>
            </div>
            <div class="card" style="margin-top:14px;">
              <h3 style="margin:0 0 8px;">Beispiel-Card</h3>
              <p class="muted" style="margin:0;">So sieht der Content-Bereich mit deinen Farben aus.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>1) Datenbankzugang</h2>
        <form method="post" enctype="multipart/form-data" autocomplete="off" id="installForm">
          <div class="grid">
            <div>
              <label>DB Host</label>
              <input name="db_host" value="<?=h($dbHost)?>" required>
            </div>
            <div>
              <label>DB Port</label>
              <input name="db_port" value="<?=h((string)$dbPort)?>" required>
            </div>
            <div>
              <label>DB Name</label>
              <input name="db_name" value="<?=h($dbName)?>" required>
            </div>
            <div>
              <label>DB User</label>
              <input name="db_user" value="<?=h($dbUser)?>" required>
            </div>
          </div>

          <label>DB Passwort</label>
          <input name="db_pass" type="password" value="<?=h($dbPass)?>">

          <h2 style="margin-top:18px;">2) Initialer Admin</h2>
          <div class="grid">
            <div>
              <label>Admin E-Mail</label>
              <input name="admin_email" value="<?=h($adminEmail)?>" required>
            </div>
            <div>
              <label>Admin Name</label>
              <input name="admin_name" value="<?=h($adminName)?>" required>
            </div>
          </div>

          <label>Admin Passwort (min. 10 Zeichen)</label>
          <input name="admin_pass" type="password" required>

          <h2 style="margin-top:18px;">3) Branding</h2>
          <div class="grid">
            <div>
              <label>Organisation / Schule</label>
              <input id="orgName" name="org_name" value="<?=h($orgName)?>" required>
            </div>
            <div>
              <label>Default Schuljahr (optional, z.B. 2025/26)</label>
              <input name="default_school_year" value="<?=h($defaultSchoolYear)?>">
            </div>

            <div>
              <label>Primary Color</label>
              <div class="grid" style="grid-template-columns:140px 1fr;">
                <div><input id="primaryPicker" type="color" value="<?=h($brandPrimary)?>" style="height:42px; padding:0; border-radius:12px;"></div>
                <div><input id="primaryHex" name="brand_primary" value="<?=h($brandPrimary)?>" required></div>
              </div>
              <div class="muted">Picker oder Hex. (Live-Preview oben)</div>
            </div>

            <div>
              <label>Secondary Color</label>
              <div class="grid" style="grid-template-columns:140px 1fr;">
                <div><input id="secondaryPicker" type="color" value="<?=h($brandSecondary)?>" style="height:42px; padding:0; border-radius:12px;"></div>
                <div><input id="secondaryHex" name="brand_secondary" value="<?=h($brandSecondary)?>" required></div>
              </div>
              <div class="muted">Picker oder Hex. (Live-Preview oben)</div>
            </div>
          </div>

          <label>Logo (optional, PNG/JPG/WEBP)</label>
          <input id="brandLogoInput" type="file" name="brand_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">

          <h2 style="margin-top:18px;">4) KI-Schlüssel (optional)</h2>
          <p class="muted">Für automatische Vorschläge wird ein externer KI-Provider (z.B. OpenAI-kompatible API) genutzt. Ohne Schlüssel wird der KI-Button später nicht angezeigt.</p>
          <label class="chk">
            <input type="checkbox" name="ai_enabled" value="1" <?=$aiEnabled ? 'checked' : ''?>> KI-Vorschläge für Lehrkräfte aktivieren
          </label>
          <p class="muted">Kann jederzeit in den Einstellungen deaktiviert werden, falls kein Guthaben verbraucht werden soll.</p>
          <label>API Key</label>
          <input name="ai_key" value="<?=h($aiKey)?>" placeholder="z.B. sk-...">
          <p class="muted">Tipp: In OpenAI unter <strong>API Keys</strong> einen Secret Key anlegen und unter <strong>Billing › Usage</strong> prüfen, ob Guthaben verfügbar ist.</p>

          <div class="actions" style="margin-top:16px;">
            <button class="btn primary" type="submit">Installieren</button>
            <button class="btn secondary" type="button" id="logoPreviewReset" style="display:none;">Logo-Vorschau zurücksetzen</button>
          </div>

          <p class="muted" style="margin-top:10px;">
            Base-Path wird automatisch gespeichert als: <code><?=h(base_path())?></code>
          </p>
        </form>
      </div>

      <script>
      (function(){
        const root = document.documentElement;

        const orgInput = document.getElementById('orgName');
        const previewOrg = document.getElementById('installPreviewOrg');

        const pPick = document.getElementById('primaryPicker');
        const pHex  = document.getElementById('primaryHex');
        const sPick = document.getElementById('secondaryPicker');
        const sHex  = document.getElementById('secondaryHex');

        const logoInput = document.getElementById('brandLogoInput');
        const previewLogo = document.getElementById('installPreviewLogo');
        const logoResetBtn = document.getElementById('logoPreviewReset');

        let objectUrl = null;

        function validHex(v){ return /^#[0-9a-fA-F]{6}$/.test((v||'').trim()); }
        function setVar(name, value){ root.style.setProperty(name, value); }
        function applyColors(){
          const p = pHex.value.trim();
          const s = sHex.value.trim();
          if (validHex(p)) setVar('--primary', p);
          if (validHex(s)) setVar('--secondary', s);
        }

        // Org live
        orgInput.addEventListener('input', () => {
          previewOrg.textContent = orgInput.value.trim() || 'LEG Tool';
        });

        // Picker -> Hex + apply
        pPick.addEventListener('input', () => { pHex.value = pPick.value; applyColors(); });
        sPick.addEventListener('input', () => { sHex.value = sPick.value; applyColors(); });

        // Hex -> Picker (only when valid) + apply
        pHex.addEventListener('input', () => {
          if (validHex(pHex.value)) pPick.value = pHex.value.trim();
          applyColors();
        });
        sHex.addEventListener('input', () => {
          if (validHex(sHex.value)) sPick.value = sHex.value.trim();
          applyColors();
        });

        // Initial apply
        previewOrg.textContent = orgInput.value.trim() || 'LEG Tool';
        applyColors();

        // Logo preview
        if (logoInput) {
          logoInput.addEventListener('change', () => {
            const file = logoInput.files && logoInput.files[0];
            if (!file) return;
            if (!file.type || !file.type.startsWith('image/')) return;

            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);

            previewLogo.src = objectUrl;
            previewLogo.style.display = 'block';
            if (logoResetBtn) logoResetBtn.style.display = 'inline-flex';
          });
        }

        // Reset logo preview
        if (logoResetBtn) {
          logoResetBtn.addEventListener('click', () => {
            if (objectUrl) {
              URL.revokeObjectURL(objectUrl);
              objectUrl = null;
            }
            previewLogo.src = '';
            previewLogo.style.display = 'none';
            if (logoInput) logoInput.value = '';
            logoResetBtn.style.display = 'none';
          });
        }

        window.addEventListener('beforeunload', () => {
          if (objectUrl) URL.revokeObjectURL(objectUrl);
        });
      })();
      </script>

    <?php endif; ?>
  </div>
</body>
</html>
