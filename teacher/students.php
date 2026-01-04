<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$toClassesUrl = get_role() == "admin" ? 'admin/classes.php' : 'teacher/classes.php';

const AI_ICON = '<svg class="ai-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3l1.4 4.2L14.6 9 10.4 10.8 9 15l-1.4-4.2L3 9l4.6-1.8L9 3zm8-1l1.05 3.15L21.2 6.2 18.05 7.25 17 10.4 15.95 7.25 12.8 6.2l3.15-1.05L17 2zm-2 10l.9 2.7L18.6 16l-2.7.9L15 19.6l-.9-2.7L11.4 16l2.7-.9.9-2.7z"></path></svg>';

function student_custom_field_label(array $field): string {
  $labelEn = trim((string)($field['label_en'] ?? ''));
  if (ui_lang() === 'en' && $labelEn !== '') return $labelEn;
  return (string)($field['label'] ?? '');
}

$classId = (int)($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
if ($classId <= 0) {
  render_teacher_header(t('teacher.students.title', 'Schüler'));
  echo '<div class="card"><h2>'.h(t('teacher.students.card_access_codes', 'Schüler-Zugangscodes')).'</h2><div class="alert danger"><strong>'.h(t('teacher.students.error_missing_class_id', 'class_id fehlt.')).'</strong></div><a class="btn secondary" href="'.h(url($toClassesUrl)).'">'.h(t('teacher.students.back_to_classes', '← zurück zu den Klassen')).'</a></div>';
  render_teacher_footer();
  exit;
}

$customFields = list_student_custom_fields($pdo);

if (!user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo "403 Forbidden";
  exit;
}

$clsSt = $pdo->prepare("SELECT id, school_year, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
$clsSt->execute([$classId]);
$class = $clsSt->fetch();
if (!$class) {
  render_teacher_header(t('teacher.students.title', 'Schüler'));
  echo '<div class="card"><h2>'.h(t('teacher.students.card_access_codes', 'Schüler-Zugangscodes')).'</h2><div class="alert danger"><strong>'.h(t('teacher.students.error_class_not_found', 'Klasse nicht gefunden.')).'</strong></div><a class="btn secondary" href="'.h(url($toClassesUrl)).'">'.h(t('teacher.students.back_to_classes', '← zurück zu den Klassen')).'</a></div>';
  render_teacher_footer();
  exit;
}

$err = '';
$ok = '';

function ai_provider_enabled(): bool {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];
  $enabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
  if (!$enabled) return false;
  $apiKey = (string)($ai['api_key'] ?? getenv('OPENAI_API_KEY') ?: '');
  return trim($apiKey) !== '';
}

function normalize_name(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}

function normalize_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  // accept YYYY-MM-DD
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  // accept DD.MM.YYYY
  if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
    $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
    return $m[3] . '-' . $mo . '-' . $d;
  }
  throw new RuntimeException(t('teacher.students.error_dob_format', 'Geburtsdatum Format: YYYY-MM-DD oder DD.MM.YYYY'));
}

function parse_blackbaud_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '' || $s === '""') return null;

  // Blackbaud often exports as M/D/YYYY (e.g. 7/16/2019)
  $s = trim($s, "\" \t\n\r\0\x0B");
  if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $s)) {
    $dt = DateTimeImmutable::createFromFormat('n/j/Y', $s);
    if ($dt) return $dt->format('Y-m-d');
    $dt = DateTimeImmutable::createFromFormat('m/d/Y', $s);
    if ($dt) return $dt->format('Y-m-d');
  }
  // Fall back to existing normalize_date formats
  try { return normalize_date($s); } catch (Throwable $e) { return null; }
}

function read_csv_assoc(string $path): array {
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException(t('teacher.students.error_csv_open', 'CSV konnte nicht geöffnet werden.'));

  // Read header line (handle UTF-8 BOM)
  $rawHeader = fgets($fh);
  if ($rawHeader === false) { fclose($fh); return []; }
  $rawHeader = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader);

  // Put header line back into a temp stream so we can use fgetcsv consistently
  $tmp = fopen('php://temp', 'wb+');
  fwrite($tmp, $rawHeader);
  while (($line = fgets($fh)) !== false) fwrite($tmp, $line);
  fclose($fh);
  rewind($tmp);

  $header = fgetcsv($tmp, 0, ',', '"');
  if (!$header) { fclose($tmp); return []; }

  $header = array_map(static function($h) {
    $h = (string)$h;
    $h = trim($h);
    $h = trim($h, "\" \t\n\r\0\x0B");
    return $h;
  }, $header);

  $rows = [];
  while (($row = fgetcsv($tmp, 0, ',', '"')) !== false) {
    if (!$row) continue;
    $assoc = [];
    foreach ($header as $i => $h) {
      $assoc[$h] = $row[$i] ?? '';
    }
    // skip empty lines
    $allEmpty = true;
    foreach ($assoc as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
    if ($allEmpty) continue;
    $rows[] = $assoc;
  }
  fclose($tmp);
  return $rows;
}

function map_custom_headers(array $header, array $customFields): array {
  $map = [];
  $normalizedFields = [];
  foreach ($customFields as $cf) {
    $key = strtolower(trim((string)($cf['field_key'] ?? '')));
    if ($key === '') continue;
    $labDe = strtolower(trim((string)($cf['label'] ?? '')));
    $labEn = strtolower(trim((string)($cf['label_en'] ?? '')));
    $normalizedFields[] = [$key, $labDe, $labEn];
  }

  foreach ($header as $hRaw) {
    $h = strtolower(trim((string)$hRaw));
    $hNormalized = preg_replace('/^custom[:\s-]+/i', '', $h);
    foreach ($normalizedFields as [$key, $labDe, $labEn]) {
      if ($h === $key || ($labDe !== '' && $h === $labDe) || ($labEn !== '' && $h === $labEn)) {
        $map[$hRaw] = $key;
        break;
      }
      if ($hNormalized !== $h && ($hNormalized === $key || ($labDe !== '' && $hNormalized === $labDe) || ($labEn !== '' && $hNormalized === $labEn))) {
        $map[$hRaw] = $key;
        break;
      }
    }
  }
  return $map;
}

function find_master_student_id(PDO $pdo, string $first, string $last, ?string $dob): ?int {
  $first = trim($first);
  $last  = trim($last);
  if ($first === '' || $last === '') return null;
  if ($dob === null || $dob === '') return null;

  $q = $pdo->prepare(
    "SELECT id, master_student_id
     FROM students
     WHERE first_name=? AND last_name=? AND date_of_birth=?
     ORDER BY (master_student_id IS NULL) ASC, id ASC
     LIMIT 1"
  );
  $q->execute([$first, $last, $dob]);
  $row = $q->fetch();
  if (!$row) return null;

  $master = $row['master_student_id'] !== null ? (int)$row['master_student_id'] : 0;
  if ($master > 0) return $master;

  // If the found record has no master, set itself as master (future-proof)
  $sid = (int)$row['id'];
  if ($sid > 0) {
    $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?")->execute([$sid, $sid]);
    return $sid;
  }
  return null;
}

function ensure_master_id(PDO $pdo, int $studentId): int {
  $q = $pdo->prepare("SELECT id, master_student_id FROM students WHERE id=? LIMIT 1");
  $q->execute([$studentId]);
  $row = $q->fetch();
  if (!$row) return $studentId;

  $master = $row['master_student_id'] !== null ? (int)$row['master_student_id'] : 0;
  if ($master > 0) return $master;

  // Set self as master
  $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?")->execute([$studentId, $studentId]);
  return $studentId;
}

function read_custom_field_input(array $fields, array $src): array {
  $out = [];
  foreach ($fields as $f) {
    $key = (string)($f['field_key'] ?? '');
    if ($key === '') continue;
    if (!array_key_exists($key, $src)) continue;
    $out[$key] = trim((string)$src[$key]);
  }
  return $out;
}

function random_student_token(): string {
  // 40 hex chars, safe for URLs
  return bin2hex(random_bytes(20));
}

function random_login_code(): string {
  // Avoid ambiguous chars (0,O,1,I)
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ123456789';
  $len = strlen($alphabet);
  $raw = '';
  for ($i = 0; $i < 8; $i++) {
    $raw .= $alphabet[random_int(0, $len - 1)];
  }
  return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

/**
 * Child input lock/unlock helpers (class-wide)
 */
function get_active_template(PDO $pdo, int $classId): ?array {
  $st = $pdo->query(
    "SELECT t.id AS id, t.name AS name, template_version
     FROM templates t INNER JOIN classes c ON c.template_id = t.id
     WHERE t.is_active=1 AND c.id = $classId
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 1"
  );
  $t = $st->fetch(PDO::FETCH_ASSOC);
  return $t ?: null;
}

function ensure_reports_for_class(PDO $pdo, int $templateId, int $classId, string $schoolYear, int $userId): void {
  // Create report_instances for all active students (idempotent via INSERT IGNORE)
  $st = $pdo->prepare("SELECT id FROM students WHERE class_id=? AND is_active=1");
  $st->execute([$classId]);
  $ids = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
  if (!$ids) return;

  $ins = $pdo->prepare(
    "INSERT IGNORE INTO report_instances (template_id, student_id, period_label, school_year, status, created_by_user_id)
     VALUES (?, ?, 'Standard', ?, 'draft', ?)"
  );
  foreach ($ids as $sid) {
    $ins->execute([$templateId, $sid, $schoolYear, $userId]);
  }
}

function lock_or_unlock_class(PDO $pdo, int $templateId, int $classId, string $schoolYear, int $userId, string $mode): int {
  // mode: 'lock' => draft -> locked, keep submitted untouched
  // mode: 'unlock' => locked -> draft, keep submitted untouched
  $st = $pdo->prepare("SELECT id FROM students WHERE class_id=?");
  $st->execute([$classId]);
  $studentIds = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
  if (!$studentIds) return 0;

  $in = implode(',', array_fill(0, count($studentIds), '?'));

  if ($mode === 'lock') {
    $sql =
      "UPDATE report_instances
       SET status='locked', locked_by_user_id=?, locked_at=NOW()
       WHERE template_id=? AND school_year=? AND period_label='Standard'
         AND student_id IN ($in)
         AND status='draft'";
    $params = array_merge([$userId, $templateId, $schoolYear], $studentIds);
  } else {
    $sql =
      "UPDATE report_instances
       SET status='draft', locked_by_user_id=NULL, locked_at=NULL
       WHERE template_id=? AND school_year=? AND period_label='Standard'
         AND student_id IN ($in)
         AND status='locked'";
    $params = array_merge([$templateId, $schoolYear], $studentIds);
  }

  $q = $pdo->prepare($sql);
  $q->execute($params);
  return $q->rowCount();
}

function class_child_status_counts(PDO $pdo, int $templateId, int $classId, string $schoolYear): array {
  $st = $pdo->prepare("SELECT id FROM students WHERE class_id=?");
  $st->execute([$classId]);
  $studentIds = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
  $total = count($studentIds);
  if ($total === 0) return ['draft'=>0,'locked'=>0,'submitted'=>0,'total'=>0];

  $statusMap = load_child_status_map($pdo, $studentIds);

  $counts = ['draft'=>0,'locked'=>0,'submitted'=>0,'total'=>$total];
  foreach ($statusMap as $status) {
    if (!isset($counts[$status])) continue;
    $counts[$status]++;
  }

  return $counts;
}

/**
 * NEW: per-student child status map + badge rendering
 */
function load_child_status_map(PDO $pdo, array $studentIds): array {
  $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($x)=>$x>0));
  if (!$studentIds) return [];

  $in = implode(',', array_fill(0, count($studentIds), '?'));
  $sql =
    "SELECT student_id, status, created_at, updated_at, id
     FROM report_instances
     WHERE student_id IN ($in)
     ORDER BY IFNULL(updated_at, created_at) DESC, id DESC";
  $q = $pdo->prepare($sql);
  $q->execute($studentIds);

  $map = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int)$r['student_id'];
    if (isset($map[$sid])) continue; // keep latest (first in ordered list)
    $map[$sid] = (string)$r['status'];
  }
  return $map;
}


/**
 * NEW: report_instance_id map per student (for active template / school year / Standard)
 */
function load_report_instance_map(PDO $pdo, array $studentIds): array {
  $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($x)=>$x>0));
  if (!$studentIds) return [];

  $in = implode(',', array_fill(0, count($studentIds), '?'));
  $sql =
    "SELECT student_id, id AS report_instance_id, status, created_at, updated_at
     FROM report_instances
     WHERE student_id IN ($in)
     ORDER BY IFNULL(updated_at, created_at) DESC, id DESC";
  $q = $pdo->prepare($sql);
  $q->execute($studentIds);

  $map = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int)$r['student_id'];
    if (isset($map[$sid])) continue; // keep latest (first in ordered list)
    $map[$sid] = [
      'report_instance_id' => (int)$r['report_instance_id'],
      'status' => (string)$r['status'],
    ];
  }
  return $map;
}

function child_status_badge(?string $status): string {
  $status = (string)$status;
  if ($status === 'submitted') return '<span class="badge success">Abgegeben</span>';
  if ($status === 'locked') return '<span class="badge danger">Gesperrt</span>';
  if ($status === 'draft') return '<span class="badge blue">Entwurf</span>';
  return '<span class="badge">Nicht angelegt</span>';
}

// POST actions: add, deactivate, copy, lock/unlock class
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    // class-wide child input lock/unlock
    if ($action === 'child_lock_class' || $action === 'child_unlock_class') {
      $tpl = get_active_template($pdo, $classId);
        if (!$tpl) throw new RuntimeException(t('teacher.students.error_no_active_template', 'Kein aktives Template gefunden.'));

      $templateId = (int)$tpl['id'];
      $schoolYear = (string)($class['school_year'] ?? '');
      if ($schoolYear === '') $schoolYear = (string)(app_config()['app']['default_school_year'] ?? '');

      ensure_reports_for_class($pdo, $templateId, $classId, $schoolYear, $userId);

      $mode = ($action === 'child_lock_class') ? 'lock' : 'unlock';
      $changed = lock_or_unlock_class($pdo, $templateId, $classId, $schoolYear, $userId, $mode);

      audit('teacher_child_' . $mode . '_class', $userId, [
        'class_id'=>$classId,
        'template_id'=>$templateId,
        'school_year'=>$schoolYear,
        'changed'=>$changed
      ]);

        if ($mode === 'lock') {
          $ok = strtr(t('teacher.students.ok_child_locked', 'Kinder-Eingabe gesperrt ({count} Reports).'), ['{count}' => (string)$changed]);
        } else {
          $ok = strtr(t('teacher.students.ok_child_unlocked', 'Kinder-Eingabe freigegeben ({count} Reports).'), ['{count}' => (string)$changed]);
        }
    }

    elseif ($action === 'add') {
      $first = normalize_name((string)($_POST['first_name'] ?? ''));
      $last  = normalize_name((string)($_POST['last_name'] ?? ''));
      $dob   = normalize_date($_POST['date_of_birth'] ?? null);
      $customInput = read_custom_field_input($customFields, $_POST['custom'] ?? []);

      if ($first === '' || $last === '') throw new RuntimeException(t('teacher.students.error_name_required', 'Vorname und Nachname sind erforderlich.'));

      $ins = $pdo->prepare(
        "INSERT INTO students (class_id, first_name, last_name, date_of_birth, is_active)
         VALUES (?, ?, ?, ?, 1)"
      );
      $ins->execute([$classId, $first, $last, $dob]);
      $newId = (int)$pdo->lastInsertId();
      // master_student_id defaults to NULL; set to itself
      $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?")->execute([$newId, $newId]);

      save_student_custom_values($pdo, $newId, $customInput, true);

        audit('teacher_student_add', $userId, ['class_id'=>$classId,'student_id'=>$newId]);
        $ok = t('teacher.students.ok_added', 'Schüler wurde angelegt.');
    }

    elseif ($action === 'toggle_active') {
      $studentId = (int)($_POST['student_id'] ?? 0);
      if ($studentId <= 0) throw new RuntimeException(t('teacher.students.error_invalid_student', 'Ungültige student_id.'));

      $q = $pdo->prepare("SELECT is_active FROM students WHERE id=? AND class_id=? LIMIT 1");
      $q->execute([$studentId, $classId]);
      $row = $q->fetch();
      if (!$row) throw new RuntimeException(t('teacher.students.error_student_not_found', 'Schüler nicht gefunden.'));

      $new = ((int)$row['is_active'] === 1) ? 0 : 1;
      $pdo->prepare("UPDATE students SET is_active=? WHERE id=?")->execute([$new, $studentId]);
      audit('teacher_student_toggle_active', $userId, ['class_id'=>$classId,'student_id'=>$studentId,'is_active'=>$new]);
        $ok = $new
          ? t('teacher.students.ok_reactivated', 'Schüler wurde reaktiviert.')
          : t('teacher.students.ok_deactivated', 'Schüler wurde deaktiviert.');
    }

    elseif ($action === 'import_csv') {
      if (empty($_FILES['csv_file']) || !isset($_FILES['csv_file']['tmp_name'])) {
        throw new RuntimeException(t('teacher.students.error_no_csv', 'Bitte CSV-Datei auswählen.'));
      }
      $tmpPath = (string)$_FILES['csv_file']['tmp_name'];
      if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException(t('teacher.students.error_upload_failed', 'Upload fehlgeschlagen.'));
      }

      $rows = read_csv_assoc($tmpPath);
      if (!$rows) throw new RuntimeException(t('teacher.students.error_empty_csv', 'CSV ist leer oder konnte nicht gelesen werden.'));

      $header = array_keys(reset($rows));
      $customHeaderMap = $customFields ? map_custom_headers($header, $customFields) : [];

      $created = 0;
      $skipped = 0;

      $pdo->beginTransaction();

      $check = $pdo->prepare(
        "SELECT id FROM students WHERE class_id=? AND first_name=? AND last_name=? AND (date_of_birth <=> ?) LIMIT 1"
      );
      $ins = $pdo->prepare(
        "INSERT INTO students (master_student_id, class_id, first_name, last_name, date_of_birth, is_active)
         VALUES (?, ?, ?, ?, ?, 1)"
      );
      $setSelfMaster = $pdo->prepare("UPDATE students SET master_student_id=? WHERE id=?");

      foreach ($rows as $r) {
        $first = normalize_name((string)($r['Student First Name'] ?? $r['First Name'] ?? $r['Student Firstname'] ?? ''));
        $last  = normalize_name((string)($r['Student Last Name'] ?? $r['Last Name'] ?? $r['Student Lastname'] ?? ''));
        $dob   = parse_blackbaud_date($r['Birth Date'] ?? $r['DOB'] ?? $r['Date of Birth'] ?? null);

        $customInput = [];
        foreach ($customHeaderMap as $col => $fieldKey) {
          $customInput[$fieldKey] = trim((string)($r[$col] ?? ''));
        }

        if ($first === '' && $last === '') continue;
        if ($first === '' || $last === '') { $skipped++; continue; }

        $check->execute([$classId, $first, $last, $dob]);
        if ($check->fetch()) { $skipped++; continue; }

        $master = find_master_student_id($pdo, $first, $last, $dob);
        $ins->execute([$master, $classId, $first, $last, $dob]);
        $newId = (int)$pdo->lastInsertId();
        if (!$master) {
          $setSelfMaster->execute([$newId, $newId]);
        }
        if ($master) {
          $copiedCustom = copy_student_custom_values($pdo, $master, $newId);
          if ($customInput) {
            save_student_custom_values($pdo, $newId, $customInput, false);
          } elseif (!$copiedCustom) {
            save_student_custom_values($pdo, $newId, [], true);
          }
        } else {
          save_student_custom_values($pdo, $newId, $customInput, true);
        }
        $created++;
      }

      $pdo->commit();

        audit('teacher_students_import_csv', $userId, ['class_id'=>$classId,'created'=>$created,'skipped'=>$skipped]);
        $ok = strtr(t('teacher.students.ok_import', 'CSV-Import: angelegt {created}, übersprungen {skipped}.'), [
          '{created}' => (string)$created,
          '{skipped}' => (string)$skipped,
        ]);
    }

    elseif ($action === 'generate_login') {
      $regen = (int)($_POST['regen'] ?? 0) === 1;

      $st = $pdo->prepare(
        "SELECT id, qr_token, login_code
         FROM students
         WHERE class_id=? AND is_active=1
         ORDER BY last_name ASC, first_name ASC"
      );
      $st->execute([$classId]);
        $students = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$students) {
          $ok = t('teacher.students.ok_no_active_students', 'Keine aktiven Schüler in dieser Klasse.');
        } else {
        $pdo->beginTransaction();

        $upd = $pdo->prepare("UPDATE students SET qr_token=?, login_code=? WHERE id=?");
        $chkToken = $pdo->prepare("SELECT 1 FROM students WHERE qr_token=? LIMIT 1");
        $chkCode  = $pdo->prepare("SELECT 1 FROM students WHERE login_code=? LIMIT 1");

        $generated = 0;
        $skipped = 0;

        foreach ($students as $s) {
          $sid = (int)$s['id'];
          $hasToken = (string)($s['qr_token'] ?? '');
          $hasCode  = (string)($s['login_code'] ?? '');

          if (!$regen && $hasToken !== '' && $hasCode !== '') {
            $skipped++;
            continue;
          }

          $token = '';
          for ($i=0; $i<40; $i++) {
            $cand = random_student_token();
            $chkToken->execute([$cand]);
            if (!$chkToken->fetch()) { $token = $cand; break; }
          }
            if ($token === '') throw new RuntimeException(t('teacher.students.error_token_generation', 'Konnte keinen eindeutigen QR-Token erzeugen.'));

          $code = '';
          for ($i=0; $i<40; $i++) {
            $cand = random_login_code();
            $chkCode->execute([$cand]);
            if (!$chkCode->fetch()) { $code = $cand; break; }
          }
            if ($code === '') throw new RuntimeException(t('teacher.students.error_code_generation', 'Konnte keinen eindeutigen Login-Code erzeugen.'));

          $upd->execute([$token, $code, $sid]);
          $generated++;
        }

        $pdo->commit();

        audit('teacher_students_generate_login', $userId, [
          'class_id' => $classId,
          'regen' => $regen,
          'generated' => $generated,
          'skipped' => $skipped,
        ]);

          $ok = $regen
            ? strtr(t('teacher.students.ok_regenerated', 'Login-Codes/QR neu generiert: {generated} (unverändert: {skipped}).'), [
                '{generated}' => (string)$generated,
                '{skipped}' => (string)$skipped,
              ])
            : strtr(t('teacher.students.ok_generated', 'Login-Codes/QR erstellt: {generated} (bereits vorhanden: {skipped}).'), [
                '{generated}' => (string)$generated,
                '{skipped}' => (string)$skipped,
              ]);
        }
      }

    elseif ($action === 'copy_from') {
      $sourceClassId = (int)($_POST['source_class_id'] ?? 0);
      $exclude = $_POST['exclude_ids'] ?? [];
      if ($sourceClassId <= 0) throw new RuntimeException(t('teacher.students.error_missing_source', 'Quelle fehlt.'));
      if (!is_array($exclude)) $exclude = [];

      if (!user_can_access_class($pdo, $userId, $sourceClassId)) {
        throw new RuntimeException(t('teacher.students.error_source_forbidden', 'Keine Berechtigung für die Quellklasse.'));
      }

      $st = $pdo->prepare(
        "SELECT id, master_student_id, first_name, last_name, date_of_birth
         FROM students
         WHERE class_id=? AND is_active=1
         ORDER BY last_name ASC, first_name ASC"
      );
      $st->execute([$sourceClassId]);
      $src = $st->fetchAll();

      $excludeIds = array_map('intval', $exclude);
      $excludeMap = array_flip($excludeIds);

      $pdo->beginTransaction();
      $ins = $pdo->prepare(
        "INSERT INTO students (master_student_id, class_id, first_name, last_name, date_of_birth, is_active)
         VALUES (?, ?, ?, ?, ?, 1)"
      );

      $copied = 0;
      foreach ($src as $s) {
        $sid = (int)$s['id'];
        if (isset($excludeMap[$sid])) continue;

        $master = $s['master_student_id'] !== null ? (int)$s['master_student_id'] : 0;
        if ($master <= 0) $master = ensure_master_id($pdo, $sid);

        $q = $pdo->prepare("SELECT id FROM students WHERE class_id=? AND master_student_id=? LIMIT 1");
        $q->execute([$classId, $master]);
        if ($q->fetch()) continue;

        $ins->execute([$master, $classId, $s['first_name'], $s['last_name'], $s['date_of_birth']]);
        $newId = (int)$pdo->lastInsertId();
        $copiedCustom = copy_student_custom_values($pdo, $sid, $newId);
        if (!$copiedCustom) save_student_custom_values($pdo, $newId, [], true);
        $copied++;
      }
      $pdo->commit();

      audit('teacher_students_copy', $userId, ['from_class_id'=>$sourceClassId,'to_class_id'=>$classId,'copied'=>$copied,'exclude_ids'=>$excludeIds]);
      $ok = strtr(t('teacher.students.ok_copied', 'Schüler übernommen: {count}'), ['{count}' => (string)$copied]);

    } elseif ($action === 'update') {
      $studentId = (int)($_POST['student_id'] ?? 0);
      if ($studentId <= 0) throw new RuntimeException(t('teacher.students.error_invalid_student', 'Ungültige student_id.'));

      $q = $pdo->prepare("SELECT id FROM students WHERE id=? AND class_id=? LIMIT 1");
      $q->execute([$studentId, $classId]);
      if (!$q->fetch()) throw new RuntimeException(t('teacher.students.error_student_not_found', 'Schüler nicht gefunden.'));

      $first = normalize_name((string)($_POST['first_name'] ?? ''));
      $last  = normalize_name((string)($_POST['last_name'] ?? ''));
      $dob   = normalize_date($_POST['date_of_birth'] ?? null);
      $customInput = read_custom_field_input($customFields, $_POST['custom'] ?? []);

      if ($first === '' || $last === '') throw new RuntimeException(t('teacher.students.error_name_required', 'Vorname und Nachname sind erforderlich.'));

      $upd = $pdo->prepare("UPDATE students SET first_name=?, last_name=?, date_of_birth=? WHERE id=?");
      $upd->execute([$first, $last, $dob, $studentId]);
      save_student_custom_values($pdo, $studentId, $customInput, false);

      audit('teacher_student_update', $userId, ['class_id'=>$classId,'student_id'=>$studentId]);
      $ok = t('teacher.students.ok_updated', 'Schüler wurde aktualisiert.');
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

// Load students in this class
$st = $pdo->prepare(
  "SELECT id, first_name, last_name, date_of_birth, is_active
   FROM students
   WHERE class_id=?
   ORDER BY last_name ASC, first_name ASC"
);
$st->execute([$classId]);
$students = $st->fetchAll();
$studentCustomValues = [];
if ($customFields && $students) {
  foreach ($students as $idx => $s) {
    $sid = (int)($s['id'] ?? 0);
    if ($sid <= 0) continue;
    $studentCustomValues[$sid] = student_custom_value_map($pdo, $sid);
    $students[$idx]['custom_values'] = $studentCustomValues[$sid];
  }
}
$studentsForJs = [];
foreach ($students as $s) {
  $sid = (int)($s['id'] ?? 0);
  if ($sid <= 0) continue;
  $studentsForJs[$sid] = [
    'id' => $sid,
    'first_name' => (string)($s['first_name'] ?? ''),
    'last_name' => (string)($s['last_name'] ?? ''),
    'date_of_birth' => (string)($s['date_of_birth'] ?? ''),
    'custom' => $studentCustomValues[$sid] ?? [],
  ];
}

// Source classes for copy
if (($u['role'] ?? '') === 'admin') {
  $cs = $pdo->prepare("SELECT id, school_year, grade_level, label, name FROM classes WHERE id<>? ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
  $cs->execute([$classId]);
  $sourceClasses = $cs->fetchAll();
} else {
  $cs = $pdo->prepare(
    "SELECT c.id, c.school_year, c.grade_level, c.label, c.name
     FROM classes c
     JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=? AND c.id<>?
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $cs->execute([$userId, $classId]);
  $sourceClasses = $cs->fetchAll();
}

// Status overview + per-student status map
$activeTpl = get_active_template($pdo, $classId);
$tplIdForUi = $activeTpl ? (int)$activeTpl['id'] : 0;
$schoolYearUi = (string)($class['school_year'] ?? '');
if ($schoolYearUi === '') $schoolYearUi = (string)(app_config()['app']['default_school_year'] ?? '');

$counts = $tplIdForUi ? class_child_status_counts($pdo, $tplIdForUi, $classId, $schoolYearUi) : ['draft'=>0,'locked'=>0,'submitted'=>0,'total'=>0];

$studentIds = array_map(fn($r)=>(int)($r['id'] ?? 0), $students ?: []);
$childStatusMap = $tplIdForUi ? load_child_status_map($pdo, $studentIds) : [];

$reportMap = $tplIdForUi ? load_report_instance_map($pdo, $studentIds) : [];

$ai_enabled = ai_provider_enabled();

render_teacher_header(t('teacher.students.title', 'Schüler') . ' – ' . (string)$class['school_year'] . ' · ' . class_display($class));
?>

<div class="card">
    <div class="row-actions" style="float: right;">
    <a class="btn secondary" href="<?=h(url($toClassesUrl))?>"><?=h(t('teacher.students.back_to_classes', '← zurück zu den Klassen'))?></a>
  </div>
    
    <h1><?=h(t('teacher.students.class_heading', 'Klasse'))?> <?=h(class_display($class))?> <span class="muted">(<?=h((string)$class['school_year'])?>)</span></h1>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
    <h2><?=h(t('teacher.students.card_access_codes', 'Schüler-Zugangscodes'))?></h2>
  <div class="actions" style="justify-content:flex-start; flex-wrap:wrap;">
    <a class="btn primary" href="<?=h(url('teacher/qr_print.php?class_id='.(int)$classId))?>" target="_blank"><?=h(t('teacher.students.print_qr', 'QR-Codes drucken'))?></a>

    <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
      <input type="hidden" name="action" value="generate_login">
      <a class="btn secondary" type="submit" onclick="this.closest('form').submit(); return false;">
        <?=h(t('teacher.students.generate_logins', 'Login-Codes/QR erstellen'))?>
      </a>
    </form>

    <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
      <input type="hidden" name="action" value="generate_login">
      <input type="hidden" name="regen" value="1">
      <a class="btn secondary" type="submit" onclick="if(confirm('<?=h(t('teacher.students.confirm_regenerate', 'Wirklich ALLE Login-Codes/QR neu generieren? Alte Ausdrucke sind dann ungültig.'))?>')) { this.closest('form').submit(); return false; }">
        <?=h(t('teacher.students.regenerate_logins', 'Neu generieren'))?>
      </a>
    </form>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('teacher.students.child_entry_card', 'Kinder-Eingabe (Klasse)'))?></h2>

  <?php if (!$activeTpl): ?>
    <div class="alert"><?=h(t('teacher.students.no_active_template', 'Kein aktives Template – Kinder-Eingabe kann nicht gesteuert werden.'))?></div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 10px 0;">
      <?php
        echo h(strtr(
          t('teacher.students.child_status_intro', 'Status ({year}): Entwurf: {draft}, Gesperrt: {locked}, Abgegeben: {submitted}'),
          [
            '{year}' => $schoolYearUi,
            '{draft}' => (string)(int)$counts['draft'],
            '{locked}' => (string)(int)$counts['locked'],
            '{submitted}' => (string)(int)$counts['submitted'],
          ]
        ));
      ?>
    </p>

    <div class="actions" style="justify-content:flex-start; flex-wrap:wrap;">
      <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
        <input type="hidden" name="action" value="child_unlock_class">
        <a class="btn primary" type="submit"onclick="if(confirm('<?=h(t('teacher.students.confirm_child_unlock', 'Kinder-Eingabe wirklich freigeben?'))?>')) { this.closest('form').submit(); return false; }">
          <?=h(t('teacher.students.child_unlock', 'Für Kinder freigeben'))?>
        </a>
      </form>

      <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
        <input type="hidden" name="action" value="child_lock_class">
        <a class="btn danger" type="submit" onclick="if(confirm('<?=h(t('teacher.students.confirm_child_lock', 'Kinder-Eingabe wirklich sperren?'))?>')) { this.closest('form').submit(); return false; }">
          <?=h(t('teacher.students.child_lock', 'Für Kinder sperren'))?>
        </a>
      </form>
    </div>
  <?php endif; ?>
</div>


<div class="card">
  <h2 style="margin-top:0;"><?=h(t('teacher.students.pdf_export', 'PDF-Export'))?></h2>
  <p class="muted" style="margin:0 0 10px 0;">
    <?=t('teacher.students.pdf_export_hint', 'Exportiert die ausgefüllten Daten in die der Klasse zugeordnete PDF-Vorlage. Die PDFs werden <strong>nicht</strong> dauerhaft auf dem Server gespeichert.')?>
  </p>
  <div class="actions" style="justify-content:flex-start; flex-wrap:wrap;">
    <a class="btn primary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId))?>"><?=h(t('teacher.students.export_open', 'Export öffnen'))?></a>
    <a class="btn secondary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=zip'))?>"><?=h(t('teacher.students.export_zip_all', 'ZIP (alle)'))?></a>
    <a class="btn secondary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=merged'))?>"><?=h(t('teacher.students.export_one_pdf', 'Eine PDF (alle)'))?></a>
    <a class="btn secondary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=zip&only_submitted=1'))?>"><?=h(t('teacher.students.export_zip_submitted', 'ZIP (nur abgegebene)'))?></a>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?=h(t('teacher.students.list_title', 'Schüler'))?></h2>

  <?php if (!$students): ?>
    <p class="muted"><?=h(t('teacher.students.none', 'Noch keine Schüler in dieser Klasse.'))?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?=h(t('teacher.students.col_name', 'Name'))?></th>
          <th><?=h(t('teacher.students.col_dob', 'Geburtsdatum'))?></th>
          <th><?=h(t('teacher.students.col_status', 'Status'))?></th>
          <th><?=h(t('teacher.students.col_child_status', 'Kinder-Status'))?></th>
          <th style="width:260px;"><?=h(t('teacher.students.col_actions', 'Aktion'))?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <?php $sid = (int)($s['id'] ?? 0); $rid = (int)($reportMap[$sid]['report_instance_id'] ?? 0); ?>
          <tr>
            <td><?=h((string)$s['last_name'])?>, <?=h((string)$s['first_name'])?></td>
            <td><?=h((string)((new DateTime($s['date_of_birth']))->format('d.m.Y') ?? ''))?></td>
            <td><?=((int)$s['is_active']===1)?'<span class="badge success">'.h(t('teacher.students.status_active', 'aktiv')).'</span>':'<span class="badge warn">'.h(t('teacher.students.status_inactive', 'inaktiv')).'</span>'?></td>
            <td>
              <?php if (!$tplIdForUi): ?>
                <span class="muted">—</span>
              <?php else: ?>
                <?= child_status_badge($childStatusMap[$sid] ?? null) ?>
              <?php endif; ?>
            </td>
            <td style="display: flex; gap: 5px;">
              <a class="btn secondary" type="button" onclick="openEditModal(<?=h((string)$sid)?>); return false;" style="margin-right:6px;"><?=h(t('teacher.students.btn_edit', 'Bearbeiten…'))?></a>
              
              <?php if ($ai_enabled) : ?>
              <a class="btn secondary ai-btn" type="button" onclick='openAiSupportModal(<?=h((string)$sid)?>, <?=json_encode(trim((string)($s['first_name'] ?? '').' '.(string)($s['last_name'] ?? '')))?>); return false;' style="margin-right:6px;"><svg class="ai-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3l1.4 4.2L14.6 9 10.4 10.8 9 15l-1.4-4.2L3 9l4.6-1.8L9 3zm8-1l1.05 3.15L21.2 6.2 18.05 7.25 17 10.4 15.95 7.25 12.8 6.2l3.15-1.05L17 2zm-2 10l.9 2.7L18.6 16l-2.7.9L15 19.6l-.9-2.7L11.4 16l2.7-.9.9-2.7z"></path></svg> <?=h(t('teacher.students.btn_support', 'Förderideen'))?></a>
              
              <?php endif; ?>
              
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="student_id" value="<?=h((string)$sid)?>">
                <a class="btn secondary" type="submit" onclick="this.closest('form').submit(); return false;">
                  <?=((int)$s['is_active']===1?h(t('teacher.students.btn_deactivate', 'Deaktivieren')):h(t('teacher.students.btn_activate', 'Aktivieren')))?></a>
              </form>
              <a class="btn primary" style="margin-left:6px;" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=single&student_id=' . (int)$sid))?>"><?=h(t('teacher.students.btn_pdf', 'PDF'))?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="muted" style="margin-top:8px;">
      <?=t('teacher.students.child_status_hint', '„Nicht angelegt“ heißt: Es gibt noch keinen passenden Eintrag in <code>report_instances</code> (für aktives Template / Schuljahr / Standard).')?>
    </div>
  <?php endif; ?>
</div>

<?php if ($students): ?>
  <div id="editModal" class="modal-overlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
      <div class="modal-header">
        <h3 id="editModalTitle" style="margin:0;"><?=h(t('teacher.students.edit_modal_title', 'Schüler bearbeiten'))?></h3>
      </div>
      <form method="post" id="editForm" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; align-items:end; margin-top:10px;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="student_id" id="edit_student_id" value="">

        <div>
          <label><?=h(t('teacher.students.label_first_name', 'Vorname'))?></label>
          <input name="first_name" id="edit_first_name" type="text" required>
        </div>
        <div>
          <label><?=h(t('teacher.students.label_last_name', 'Nachname'))?></label>
          <input name="last_name" id="edit_last_name" type="text" required>
        </div>
        <div>
          <label><?=h(t('teacher.students.label_dob', 'Geburtsdatum'))?></label>
          <input name="date_of_birth" id="edit_date_of_birth" type="date">
        </div>

        <?php if ($customFields): ?>
          <div style="grid-column: 1 / -1; margin-top:6px;">
            <h3 style="margin:10px 0 0 0;"><?=h(t('teacher.students.additional_fields', 'Zusätzliche Felder'))?></h3>
          </div>
          <?php foreach ($customFields as $cf): ?>
            <?php $key = (string)($cf['field_key'] ?? ''); if ($key === '') continue; ?>
            <?php $label = student_custom_field_label($cf); ?>
            <div>
              <label><?=h($label)?></label>
              <input name="custom[<?=h($key)?>]" data-custom-key="<?=h($key)?>" type="text">
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="actions" style="justify-content:flex-start; grid-column: 1 / -1; gap:8px;">
          <button class="btn secondary" type="button" onclick="closeEditModal()"><?=h(t('teacher.students.edit_modal_cancel', 'Abbrechen'))?></button>
          <button class="btn primary" type="submit"><?=h(t('teacher.students.btn_save', 'Speichern'))?></button>
        </div>
      </form>
    </div>
  </div>
  
  <div id="aiSupportModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="aiSupportModalTitle" style="max-width:980px;">
      <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
        <div>
          <h3 id="aiSupportModalTitle" style="margin:0;"><?=h(t('teacher.students.support_modal_title', 'Fördermöglichkeiten'))?></h3>
          <div class="muted" id="aiSupportModalSub" style="margin-top:4px;"></div>
        </div>
        <button class="btn secondary" type="button" onclick="closeAiSupportModal(); return false;"><?=h(t('teacher.students.btn_close', 'Schließen'))?></button>
      </div>

      <div class="row" style="gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">
        <button class="btn" type="button" id="btnAiSupportRefresh"><?=h(t('teacher.students.btn_support_refresh', 'Neu generieren'))?></button>
        <span class="muted" id="aiSupportMeta"></span>
      </div>

      <div id="aiSupportStatus" class="alert" style="display:none; margin-top:10px;"></div>

      <div id="aiSupportContent" style="margin-top:12px;">
        <div class="muted"><?=h(t('teacher.students.support_hint', 'Fächerübergreifende Förderideen auf Basis aller bisherigen Eingaben.'))?></div>
      </div>
    </div>
  </div>

<style>
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 12px; }
    .modal-overlay.is-open { display: flex; }
    .modal { background: #fff; color: inherit; border-radius: 8px; padding: 16px; width: min(720px, 100%); box-shadow: 0 10px 30px rgba(0,0,0,0.2); border: 1px solid var(--border); max-height: 90vh; overflow:auto; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    
    .ai-icon{ width:16px; height:16px; display:inline-block; vertical-align:middle; fill: currentColor; }
  .ai-btn{ display:inline-flex; align-items:center; gap:6px; }
  </style>
  <script>
    const studentData = <?=json_encode($studentsForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>;
    const customFieldKeys = <?=json_encode(array_values(array_map(fn($cf)=>(string)($cf['field_key'] ?? ''), $customFields ?? [])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>;
    const editModal = document.getElementById('editModal');
    const editForm = document.getElementById('editForm');

    function openEditModal(id) {
      if (!editModal || !editForm) return;
      const s = studentData[String(id)] || studentData[id];
      if (!s) return;
      document.getElementById('edit_student_id').value = s.id || '';
      document.getElementById('edit_first_name').value = s.first_name || '';
      document.getElementById('edit_last_name').value = s.last_name || '';
      document.getElementById('edit_date_of_birth').value = s.date_of_birth || '';
      customFieldKeys.forEach(key => {
        if (!key) return;
        const input = editForm.querySelector('[data-custom-key="' + key + '"]');
        if (input) input.value = (s.custom && s.custom[key]) ? s.custom[key] : '';
      });
      editModal.classList.add('is-open');
      editModal.setAttribute('aria-hidden', 'false');
      const first = document.getElementById('edit_first_name');
      if (first) first.focus();
    }

    function closeEditModal() {
      if (!editModal) return;
      editModal.classList.remove('is-open');
      editModal.setAttribute('aria-hidden', 'true');
    }

    if (editModal) {
      editModal.addEventListener('click', function(ev){
        if (ev.target === editModal) closeEditModal();
      });
    }
    document.addEventListener('keydown', function(ev){
      if (ev.key === 'Escape') closeEditModal();
  });


    // ===== KI: Fördermöglichkeiten (Popup + Cache) =====
    const aiApiUrl = <?=json_encode(url('teacher/ajax/student_ai_api.php'))?>;
    const classIdForAi = Number(<?= (int)$classId ?>);

    const aiSupportModal = document.getElementById('aiSupportModal');
    const aiSupportSub = document.getElementById('aiSupportModalSub');
    const aiSupportContent = document.getElementById('aiSupportContent');
    const aiSupportStatus = document.getElementById('aiSupportStatus');
    const aiSupportMeta = document.getElementById('aiSupportMeta');
    const btnAiSupportRefresh = document.getElementById('btnAiSupportRefresh');

    let aiSupport = { studentId: 0, studentName: '' };
    let aiSupportLoading = false;

    function escapeHtml(s){
      return String(s ?? '').replace(/[&<>"']/g, (c)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }

    function showAiSupportStatus(msg, kind){
      if (!aiSupportStatus) return;
      aiSupportStatus.style.display = 'block';
      aiSupportStatus.className = 'alert ' + (kind || '');
      aiSupportStatus.textContent = msg || '';
    }

    function clearAiSupportStatus(){
      if (!aiSupportStatus) return;
      aiSupportStatus.style.display = 'none';
      aiSupportStatus.className = 'alert';
      aiSupportStatus.textContent = '';
    }

    function renderSupportPlan(plan){
      if (!aiSupportContent) return;

      const section = (title, arr)=>{
        const items = Array.isArray(arr) ? arr.filter(x=>String(x||'').trim()!=='') : [];
        if (!items.length) return '';
        return `
          <div class="card" style="margin-top:10px; background:#f8f9fb;">
            <h4 style="margin:0 0 8px 0;">${escapeHtml(title)}</h4>
            <ul style="margin:0; padding-left:18px;">
              ${items.map(it=>`<li style="margin:6px 0;">${escapeHtml(it)}</li>`).join('')}
            </ul>
          </div>
        `;
      };

      const kurzprofil = (plan && plan.kurzprofil) ? String(plan.kurzprofil) : '';
      let html = '';
      if (kurzprofil.trim() !== '') {
        html += `
          <div class="card" style="margin-top:10px; background:#f8f9fb;">
            <h4 style="margin:0 0 8px 0;"><?=h(t('teacher.students.support_short_profile', 'Kurzprofil'))?></h4>
            <div>${escapeHtml(kurzprofil)}</div>
          </div>
        `;
      }

      html += section('<?=h(t('teacher.students.support_cross', 'Übergreifend'))?>', plan?.foerder_uebergreifend);
      html += section('<?=h(t('teacher.students.support_deutsch', 'Deutsch'))?>', plan?.deutsch);
      html += section('<?=h(t('teacher.students.support_mathe', 'Mathe'))?>', plan?.mathe);
      html += section('<?=h(t('teacher.students.support_sachkunde', 'Sachkunde'))?>', plan?.sachkunde);
      html += section('<?=h(t('teacher.students.support_organization', 'Lernorganisation'))?>', plan?.lernorganisation);
      html += section('<?=h(t('teacher.students.support_social', 'Sozial/Emotional'))?>', plan?.sozial_emotional);
      html += section('<?=h(t('teacher.students.support_home', 'Zu Hause'))?>', plan?.zu_hause);
      html += section('<?=h(t('teacher.students.support_next', 'Diagnostik und nächste Schritte'))?>', plan?.diagnostik_naechste_schritte);

      if (html.trim() === '') {
        html = '<div class="muted"><?=h(t('teacher.students.support_no_data', 'Keine verwertbaren Vorschläge erhalten.'))?></div>';
      }

      aiSupportContent.innerHTML = html;
    }

    async function loadAiSupportPlan(force){
      if (aiSupportLoading) return;
aiSupportLoading = true;
      if (btnAiSupportRefresh) btnAiSupportRefresh.disabled = true;

      clearAiSupportStatus();
      aiSupportMeta.textContent = '<?=h(t('teacher.students.support_loading', 'Lädt…'))?>';
      aiSupportContent.innerHTML = '<div class="muted"><?=h(t('teacher.students.support_loading2', 'KI erstellt Vorschläge…'))?></div>';

      try {
        const res = await fetch(aiApiUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({
            action: 'ai_student_support_plan',
            class_id: classIdForAi,
            student_id: aiSupport.studentId,
            force: force ? 1 : 0
          })
        });

        const json = await res.json();
        if (!res.ok || !json || json.ok !== true) {
          throw new Error((json && (json.error || json.message)) ? (json.error || json.message) : ('HTTP ' + res.status));
        }

        const plan = json.support_plan || {};
        renderSupportPlan(plan);

        const meta = json.meta || {};
        const cached = meta.cached === true;
        const age = (typeof meta.cache_age_seconds === 'number') ? meta.cache_age_seconds : null;
        const filled = (typeof meta.filled_fields === 'number') ? meta.filled_fields : null;

        let metaTxt = '';
        if (cached) {
          metaTxt = '<?=h(t('teacher.students.support_cached', 'Cache:'))?> ' + (age !== null ? (Math.round(age/60) + ' min') : '<?=h(t('teacher.students.support_cached_yes', 'ja'))?>');
        } else if (filled !== null) {
          metaTxt = '<?=h(t('teacher.students.support_meta', 'Berücksichtigte Felder:'))?> ' + filled;
        }
        aiSupportMeta.textContent = metaTxt;

      } catch (e) {
        showAiSupportStatus('<?=h(t('teacher.students.support_error', 'Konnte Fördermöglichkeiten nicht laden:'))?> ' + (e && e.message ? e.message : String(e)), 'danger');
        aiSupportMeta.textContent = '';
        aiSupportContent.innerHTML = '<div class="muted"><?=h(t('teacher.students.support_try_again', 'Bitte erneut versuchen.'))?></div>';
      } finally {
        aiSupportLoading = false;
        if (btnAiSupportRefresh) btnAiSupportRefresh.disabled = false;
      }
    }

    function openAiSupportModal(studentId, studentName){
      aiSupport = { studentId: Number(studentId||0), studentName: String(studentName||'') };

      if (aiSupportSub) {
        aiSupportSub.textContent = aiSupport.studentName ? aiSupport.studentName : ('#' + aiSupport.studentId);
      }

      if (aiSupportModal) {
        aiSupportModal.style.display = 'flex';
        aiSupportModal.setAttribute('aria-hidden', 'false');
      }
      clearAiSupportStatus();
      aiSupportMeta.textContent = '';
      aiSupportContent.innerHTML = '<div class="muted"><?=h(t('teacher.students.support_loading2', 'KI erstellt Vorschläge…'))?></div>';

      // Autoload (uses cache)
      loadAiSupportPlan(false);
    }

    function closeAiSupportModal(){
      if (!aiSupportModal) return;
      aiSupportModal.style.display = 'none';
      aiSupportModal.setAttribute('aria-hidden', 'true');
      aiSupport = { studentId: 0, studentName: '' };
      aiSupportLoading = false;
    }

    if (aiSupportModal) {
      aiSupportModal.addEventListener('click', (ev)=>{
        if (ev.target === aiSupportModal) closeAiSupportModal();
      });
    }
    if (btnAiSupportRefresh) btnAiSupportRefresh.addEventListener('click', ()=>{
      loadAiSupportPlan(true); // force refresh, bypass cache
    });
  </script>
<?php endif; ?>

<div class="card">
  <h2><?=h(t('teacher.students.add_manual', 'Schüler manuell anlegen'))?></h2>
  <form method="post" class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">

    <div>
      <label><?=h(t('teacher.students.label_first_name', 'Vorname'))?></label>
      <input name="first_name" type="text" required>
    </div>
    <div>
      <label><?=h(t('teacher.students.label_last_name', 'Nachname'))?></label>
      <input name="last_name" type="text" required>
    </div>
    <div>
      <label><?=h(t('teacher.students.label_dob', 'Geburtsdatum'))?></label>
      <input name="date_of_birth" type="date" placeholder="<?=h(t('teacher.students.placeholder_dob', 'YYYY-MM-DD oder DD.MM.YYYY'))?>">
    </div>
    <?php if ($customFields): ?>
      <div style="grid-column: 1 / span 3; margin-top:6px;">
        <h3 style="margin:10px 0 0 0;"><?=h(t('teacher.students.additional_fields', 'Zusätzliche Felder'))?></h3>
      </div>
      <?php foreach ($customFields as $cf): ?>
        <div>
          <?php $label = student_custom_field_label($cf); ?>
          <label><?=h($label)?></label>
          <input name="custom[<?=h((string)$cf['field_key'])?>]" type="text" value="<?=h((string)($cf['default_value'] ?? ''))?>">
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="actions" style="justify-content:flex-start;">
      <button class="btn primary" type="submit"><?=h(t('teacher.students.btn_add', 'Anlegen'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('teacher.students.import_title', 'Schüler per Blackbaud-CSV importieren'))?></h2>
  <p class="muted"><?=t('teacher.students.import_hint', 'CSV-Export aus Blackbaud (oder ähnlich). Erwartete Spalten: <code>Student First Name</code>, <code>Student Last Name</code>, <code>Birth Date</code>. Zusätzliche Felder werden per Spaltenname (Feld-Schlüssel oder Beschriftung, optional mit Präfix „Custom:“) zugeordnet.')?></p>

  <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="import_csv">
    <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">

    <div>
      <label><?=h(t('teacher.students.import_label', 'CSV-Datei'))?></label>
      <input type="file" name="csv_file" accept=".csv,text/csv" required>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;">
        <?=h(t('teacher.students.btn_import', 'Importieren'))?>
      </a>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('teacher.students.copy_title', 'Schüler aus Vorjahr übernehmen'))?></h2>
  <p class="muted"><?=h(t('teacher.students.copy_hint', 'Kopiert aktive Schüler aus einer anderen Klasse in diese Klasse. Du kannst einzelne Schüler ausschließen.'))?></p>

  <?php if (!$sourceClasses): ?>
    <div class="alert"><?=h(t('teacher.students.copy_none', 'Keine Quellklassen verfügbar (dir sind keine weiteren Klassen zugeordnet).'))?></div>
  <?php else: ?>
    <form method="post" id="copyForm">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="copy_from">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">

      <div class="grid" style="grid-template-columns: 320px auto; gap:12px; align-items:start;">
        <div>
          <label><?=h(t('teacher.students.copy_source', 'Quelle'))?></label>
          <select name="source_class_id" id="sourceClass" required>
            <option value=""><?=h(t('teacher.students.copy_choose', '— wählen —'))?></option>
            <?php foreach ($sourceClasses as $c): ?>
              <option value="<?=h((string)$c['id'])?>"><?=h((string)$c['school_year'])?> · <?=h(class_display($c))?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label><?=h(t('teacher.students.copy_exclude', 'Schüler ausschließen'))?></label>
          <div class="panel" style="max-height: 260px; overflow:auto;" id="excludeBox">
            <div class="muted"><?=h(t('teacher.students.copy_choose_first', 'Wähle zuerst eine Quelle.'))?></div>
          </div>
        </div>
      </div>

      <div class="actions" style="justify-content:flex-start; margin-top:12px;">
        <a class="btn secondary" type="button" id="btnSelectNone"><?=h(t('teacher.students.copy_select_none', 'Keinen ausschließen'))?></a>
        <a class="btn secondary" type="button" id="btnSelectAll"><?=h(t('teacher.students.copy_select_all', 'Alle ausschließen'))?></a>
        <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;">
          <?=h(t('teacher.students.copy_submit', 'Übernehmen'))?>
        </a>
      </div>
    </form>

    <script>
      const csrfToken = <?=json_encode(csrf_token())?>;
      const sourceSel = document.getElementById('sourceClass');
      const excludeBox = document.getElementById('excludeBox');
      const btnNone = document.getElementById('btnSelectNone');
      const btnAll = document.getElementById('btnSelectAll');

      function setAllChecked(checked){
        excludeBox.querySelectorAll('input[type="checkbox"]').forEach(cb=>{ cb.checked = checked; });
      }
      btnNone.addEventListener('click', ()=>setAllChecked(false));
      btnAll.addEventListener('click', ()=>setAllChecked(true));

      async function loadSourceStudents(classId){
        excludeBox.innerHTML = '<div class="muted"><?=h(t('teacher.students.copy_loading', 'Lade…'))?></div>';
        const url = <?=json_encode(url('teacher/students_source_api.php'))?> + '?source_class_id=' + encodeURIComponent(classId);
        const res = await fetch(url, { headers: { 'X-CSRF-Token': csrfToken } });
        const data = await res.json();
        if (!data.ok) {
          excludeBox.innerHTML = '<div class="alert danger"><strong>' + (data.error || <?=json_encode(t('teacher.students.copy_error_fallback', 'Fehler'))?>) + '</strong></div>';
          return;
        }
        const items = data.students || [];
        if (!items.length) {
          excludeBox.innerHTML = '<div class="muted"><?=h(t('teacher.students.copy_no_students', 'Keine aktiven Schüler in der Quelle.'))?></div>';
          return;
        }
        excludeBox.innerHTML = '';
        items.forEach(s=>{
          const id = Number(s.id);
          const line = document.createElement('label');
          line.style.display = 'flex';
          line.style.gap = '10px';
          line.style.alignItems = 'center';
          line.style.padding = '6px 4px';
          line.style.borderBottom = '1px solid var(--border)';
          const cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.name = 'exclude_ids[]';
          cb.value = String(id);
          const txt = document.createElement('div');
          txt.textContent = (s.last_name || '') + ', ' + (s.first_name || '') + (s.date_of_birth ? (' (' + s.date_of_birth + ')') : '');
          line.appendChild(cb);
          line.appendChild(txt);
          excludeBox.appendChild(line);
        });
      }

      sourceSel.addEventListener('change', ()=>{
        const v = sourceSel.value;
        if (!v) {
          excludeBox.innerHTML = '<div class="muted">Wähle zuerst eine Quelle.</div>';
          return;
        }
        loadSourceStudents(v);
      });
    </script>
  <?php endif; ?>
</div>

<?php render_teacher_footer(); ?>
