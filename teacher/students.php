<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$toClassesUrl = get_role() == "admin" ? 'admin/classes.php' : 'teacher/classes.php';

$classId = (int)($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
if ($classId <= 0) {
  render_teacher_header('Schüler');
  echo '<div class="card"><h2>Schüler-Zugangscodes</h2><div class="alert danger"><strong>class_id fehlt.</strong></div><a class="btn secondary" href="'.h(url($toClassesUrl)).'">← Zurück zu den Klassen</a></div>';
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
  render_teacher_header('Schüler');
  echo '<div class="card"><h2>Schüler-Zugangscodes</h2><div class="alert danger"><strong>Klasse nicht gefunden.</strong></div><a class="btn secondary" href="'.h(url($toClassesUrl)).'">← Zurück zu den Klassen</a></div>';
  render_teacher_footer();
  exit;
}

$err = '';
$ok = '';

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
  throw new RuntimeException('Geburtsdatum Format: YYYY-MM-DD oder DD.MM.YYYY');
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
  if (!$fh) throw new RuntimeException('CSV konnte nicht geöffnet werden.');

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
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
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
function get_active_template(PDO $pdo): ?array {
  $st = $pdo->query(
    "SELECT id, name, template_version
     FROM templates
     WHERE is_active=1
     ORDER BY created_at DESC, id DESC
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

  $in = implode(',', array_fill(0, $total, '?'));

  $q = $pdo->prepare(
    "SELECT status, COUNT(*) AS c
     FROM report_instances
     WHERE template_id=? AND school_year=? AND period_label='Standard'
       AND student_id IN ($in)
     GROUP BY status"
  );
  $q->execute(array_merge([$templateId, $schoolYear], $studentIds));
  $m = ['draft'=>0,'locked'=>0,'submitted'=>0,'total'=>$total];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $stt = (string)$r['status'];
    $m[$stt] = (int)$r['c'];
  }
  return $m;
}

/**
 * NEW: per-student child status map + badge rendering
 */
function load_child_status_map(PDO $pdo, int $templateId, string $schoolYear, array $studentIds): array {
  $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($x)=>$x>0));
  if (!$studentIds) return [];

  $in = implode(',', array_fill(0, count($studentIds), '?'));
  $sql =
    "SELECT student_id, status
     FROM report_instances
     WHERE template_id=? AND school_year=? AND period_label='Standard'
       AND student_id IN ($in)";
  $q = $pdo->prepare($sql);
  $q->execute(array_merge([$templateId, $schoolYear], $studentIds));

  $map = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $map[(int)$r['student_id']] = (string)$r['status'];
  }
  return $map;
}

function child_status_badge(?string $status): string {
  $status = (string)$status;
  if ($status === 'submitted') return '<span class="badge success">Abgegeben</span>';
  if ($status === 'locked') return '<span class="badge danger">Gesperrt</span>';
  if ($status === 'draft') return '<span class="badge">Entwurf</span>';
  return '<span class="badge">Nicht angelegt</span>';
}

// POST actions: add, deactivate, copy, lock/unlock class
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    // class-wide child input lock/unlock
    if ($action === 'child_lock_class' || $action === 'child_unlock_class') {
      $tpl = get_active_template($pdo);
      if (!$tpl) throw new RuntimeException('Kein aktives Template gefunden.');

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

      $ok = ($mode === 'lock')
        ? "Kinder-Eingabe gesperrt ({$changed} Reports)."
        : "Kinder-Eingabe freigegeben ({$changed} Reports).";
    }

    elseif ($action === 'add') {
      $first = normalize_name((string)($_POST['first_name'] ?? ''));
      $last  = normalize_name((string)($_POST['last_name'] ?? ''));
      $dob   = normalize_date($_POST['date_of_birth'] ?? null);
      $customInput = read_custom_field_input($customFields, $_POST['custom'] ?? []);

      if ($first === '' || $last === '') throw new RuntimeException('Vorname und Nachname sind erforderlich.');

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
      $ok = 'Schüler wurde angelegt.';
    }

    elseif ($action === 'toggle_active') {
      $studentId = (int)($_POST['student_id'] ?? 0);
      if ($studentId <= 0) throw new RuntimeException('Ungültige student_id.');

      $q = $pdo->prepare("SELECT is_active FROM students WHERE id=? AND class_id=? LIMIT 1");
      $q->execute([$studentId, $classId]);
      $row = $q->fetch();
      if (!$row) throw new RuntimeException('Schüler nicht gefunden.');

      $new = ((int)$row['is_active'] === 1) ? 0 : 1;
      $pdo->prepare("UPDATE students SET is_active=? WHERE id=?")->execute([$new, $studentId]);
      audit('teacher_student_toggle_active', $userId, ['class_id'=>$classId,'student_id'=>$studentId,'is_active'=>$new]);
      $ok = $new ? 'Schüler wurde reaktiviert.' : 'Schüler wurde deaktiviert.';
    }

    elseif ($action === 'import_csv') {
      if (empty($_FILES['csv_file']) || !isset($_FILES['csv_file']['tmp_name'])) {
        throw new RuntimeException('Bitte CSV-Datei auswählen.');
      }
      $tmpPath = (string)$_FILES['csv_file']['tmp_name'];
      if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Upload fehlgeschlagen.');
      }

      $rows = read_csv_assoc($tmpPath);
      if (!$rows) throw new RuntimeException('CSV ist leer oder konnte nicht gelesen werden.');

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
          if (!$copiedCustom) save_student_custom_values($pdo, $newId, [], true);
        } else {
          save_student_custom_values($pdo, $newId, [], true);
        }
        $created++;
      }

      $pdo->commit();

      audit('teacher_students_import_csv', $userId, ['class_id'=>$classId,'created'=>$created,'skipped'=>$skipped]);
      $ok = "CSV-Import: angelegt {$created}, übersprungen {$skipped}.";
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
        $ok = 'Keine aktiven Schüler in dieser Klasse.';
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
          if ($token === '') throw new RuntimeException('Konnte keinen eindeutigen QR-Token erzeugen.');

          $code = '';
          for ($i=0; $i<40; $i++) {
            $cand = random_login_code();
            $chkCode->execute([$cand]);
            if (!$chkCode->fetch()) { $code = $cand; break; }
          }
          if ($code === '') throw new RuntimeException('Konnte keinen eindeutigen Login-Code erzeugen.');

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
          ? "Login-Codes/QR neu generiert: {$generated} (unverändert: {$skipped})."
          : "Login-Codes/QR erstellt: {$generated} (bereits vorhanden: {$skipped}).";
      }
    }

    elseif ($action === 'copy_from') {
      $sourceClassId = (int)($_POST['source_class_id'] ?? 0);
      $exclude = $_POST['exclude_ids'] ?? [];
      if ($sourceClassId <= 0) throw new RuntimeException('Quelle fehlt.');
      if (!is_array($exclude)) $exclude = [];

      if (!user_can_access_class($pdo, $userId, $sourceClassId)) {
        throw new RuntimeException('Keine Berechtigung für die Quellklasse.');
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
      $ok = "Schüler übernommen: {$copied}";
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
$activeTpl = get_active_template($pdo);
$tplIdForUi = $activeTpl ? (int)$activeTpl['id'] : 0;
$schoolYearUi = (string)($class['school_year'] ?? '');
if ($schoolYearUi === '') $schoolYearUi = (string)(app_config()['app']['default_school_year'] ?? '');

$counts = $tplIdForUi ? class_child_status_counts($pdo, $tplIdForUi, $classId, $schoolYearUi) : ['draft'=>0,'locked'=>0,'submitted'=>0,'total'=>0];

$studentIds = array_map(fn($r)=>(int)($r['id'] ?? 0), $students ?: []);
$childStatusMap = $tplIdForUi ? load_child_status_map($pdo, $tplIdForUi, $schoolYearUi, $studentIds) : [];

render_teacher_header('Schüler – ' . (string)$class['school_year'] . ' · ' . class_display($class));
?>

<div class="card">
    <div class="row-actions" style="float: right;">
    <a class="btn secondary" href="<?=h(url($toClassesUrl))?>">← zurück zu den Klassen</a>
  </div>

  <h1>Klasse <?=h(class_display($class))?> <span class="muted">(<?=h((string)$class['school_year'])?>)</span></h1>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
    <h2>Schüler-Zugangscodes</h2>
  <div class="actions" style="justify-content:flex-start; flex-wrap:wrap;">
    <a class="btn primary" href="<?=h(url('teacher/qr_print.php?class_id='.(int)$classId))?>" target="_blank">QR-Codes drucken</a>

    <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
      <input type="hidden" name="action" value="generate_login">
      <a class="btn secondary" type="submit" onclick="this.parentNode.submit(); return false;">Login-Codes/QR erstellen</a>
    </form>

    <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
      <input type="hidden" name="action" value="generate_login">
      <input type="hidden" name="regen" value="1">
      <a class="btn secondary" type="submit" onclick="if(confirm('Wirklich ALLE Login-Codes/QR neu generieren? Alte Ausdrucke sind dann ungültig.')) { this.parentNode.submit(); return false; }">Neu generieren</a>
    </form>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Kinder-Eingabe (Klasse)</h2>

  <?php if (!$activeTpl): ?>
    <div class="alert">Kein aktives Template – Kinder-Eingabe kann nicht gesteuert werden.</div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 10px 0;">
      Status (<?=h($schoolYearUi)?>): Entwurf: <strong><?= (int)$counts['draft'] ?></strong>,
      Gesperrt: <strong><?= (int)$counts['locked'] ?></strong>,
      Abgegeben: <strong><?= (int)$counts['submitted'] ?></strong>
    </p>

    <div class="actions" style="justify-content:flex-start; flex-wrap:wrap;">
      <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
        <input type="hidden" name="action" value="child_unlock_class">
        <a class="btn primary" type="submit"onclick="if(confirm('Kinder-Eingabe wirklich freigeben?')) { this.parentNode.submit(); return false; }">Für Kinder freigeben</a>
      </form>

      <form method="post" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
        <input type="hidden" name="action" value="child_lock_class">
        <a class="btn danger" type="submit" onclick="if(confirm('Kinder-Eingabe wirklich sperren?')) { this.parentNode.submit(); return false; }">Für Kinder sperren</a>
      </form>
    </div>
  <?php endif; ?>
</div>


<div class="card">
  <h2 style="margin-top:0;">PDF-Export</h2>
  <p class="muted" style="margin:0 0 10px 0;">
    Exportiert die ausgefüllten Daten in die der Klasse zugeordnete PDF-Vorlage. Die PDFs werden <strong>nicht</strong> dauerhaft auf dem Server gespeichert.
  </p>
  <div class="actions" style="justify-content:flex-start; flex-wrap:wrap;">
    <a class="btn primary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId))?>">Export öffnen</a>
    <a class="btn secondary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=zip'))?>">ZIP (alle)</a>
    <a class="btn secondary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=merged'))?>">Eine PDF (alle)</a>
    <a class="btn secondary" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=zip&only_submitted=1'))?>">ZIP (nur abgegebene)</a>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">Schüler</h2>

  <?php if (!$students): ?>
    <p class="muted">Noch keine Schüler in dieser Klasse.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Geburtsdatum</th>
          <th>Status</th>
          <th>Kinder-Status</th>
          <th style="width:220px;">Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <?php $sid = (int)($s['id'] ?? 0); ?>
          <tr>
            <td><?=h((string)$s['last_name'])?>, <?=h((string)$s['first_name'])?></td>
            <td><?=h((string)($s['date_of_birth'] ?? ''))?></td>
            <td><?=((int)$s['is_active']===1)?'<span class="badge success">aktiv</span>':'<span class="badge">inaktiv</span>'?></td>
            <td>
              <?php if (!$tplIdForUi): ?>
                <span class="muted">—</span>
              <?php else: ?>
                <?= child_status_badge($childStatusMap[$sid] ?? null) ?>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="student_id" value="<?=h((string)$sid)?>">
                <a class="btn secondary" type="submit" onclick="this.parentNode.submit(); return false;"><?=((int)$s['is_active']===1)?'Deaktivieren':'Aktivieren'?></a>
              </form>
              <a class="btn primary" style="margin-left:6px;" href="<?=h(url('teacher/export.php?class_id=' . (int)$classId . '&mode=single&student_id=' . (int)$sid))?>">PDF</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="muted" style="margin-top:8px;">
      „Nicht angelegt“ heißt: Es gibt noch keinen passenden Eintrag in <code>report_instances</code> (für aktives Template / Schuljahr / Standard).
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Schüler manuell anlegen</h2>
  <form method="post" class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">

    <div>
      <label>Vorname</label>
      <input name="first_name" type="text" required>
    </div>
    <div>
      <label>Nachname</label>
      <input name="last_name" type="text" required>
    </div>
    <div>
      <label>Geburtsdatum</label>
      <input name="date_of_birth" type="text" placeholder="YYYY-MM-DD oder DD.MM.YYYY">
    </div>
    <?php if ($customFields): ?>
      <div style="grid-column: 1 / span 3; margin-top:6px;">
        <h3 style="margin:0 0 8px 0;">Zusätzliche Felder</h3>
      </div>
      <?php foreach ($customFields as $cf): ?>
        <div>
          <?php $labEn = trim((string)($cf['label_en'] ?? '')); ?>
          <label><?=h((string)$cf['label'])?><?php if ($labEn !== ''): ?> <span class="muted">(EN: <?=h($labEn)?>)</span><?php endif; ?></label>
          <input name="custom[<?=h((string)$cf['field_key'])?>]" type="text" value="<?=h((string)($cf['default_value'] ?? ''))?>">
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="actions" style="justify-content:flex-start;">
      <button class="btn primary" type="submit">Anlegen</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Schüler per Blackbaud-CSV importieren</h2>
  <p class="muted">CSV-Export aus Blackbaud (oder ähnlich). Erwartete Spalten: <code>Student First Name</code>, <code>Student Last Name</code>, <code>Birth Date</code>.</p>

  <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="import_csv">
    <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">

    <div>
      <label>CSV-Datei</label>
      <input type="file" name="csv_file" accept=".csv,text/csv" required>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <a class="btn primary" type="submit" onclick="this.parentNode.parentNode.submit(); return false;">Importieren</a>
    </div>
  </form>
</div>

<div class="card">
  <h2>Schüler aus Vorjahr übernehmen</h2>
  <p class="muted">Kopiert aktive Schüler aus einer anderen Klasse in diese Klasse. Du kannst einzelne Schüler ausschließen.</p>

  <?php if (!$sourceClasses): ?>
    <div class="alert">Keine Quellklassen verfügbar (dir sind keine weiteren Klassen zugeordnet).</div>
  <?php else: ?>
    <form method="post" id="copyForm">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="copy_from">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">

      <div class="grid" style="grid-template-columns: 320px auto; gap:12px; align-items:start;">
        <div>
          <label>Quelle</label>
          <select name="source_class_id" id="sourceClass" required>
            <option value="">— wählen —</option>
            <?php foreach ($sourceClasses as $c): ?>
              <option value="<?=h((string)$c['id'])?>"><?=h((string)$c['school_year'])?> · <?=h(class_display($c))?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Schüler ausschließen</label>
          <div class="panel" style="max-height: 260px; overflow:auto;" id="excludeBox">
            <div class="muted">Wähle zuerst eine Quelle.</div>
          </div>
        </div>
      </div>

      <div class="actions" style="justify-content:flex-start; margin-top:12px;">
        <a class="btn secondary" type="button" id="btnSelectNone">Keinen ausschließen</a>
        <a class="btn secondary" type="button" id="btnSelectAll">Alle ausschließen</a>
        <a class="btn primary" type="submit" onclick="this.parentNode.parentNode.submit(); return false;">Übernehmen</a>
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
        excludeBox.innerHTML = '<div class="muted">Lade…</div>';
        const url = <?=json_encode(url('teacher/students_source_api.php'))?> + '?source_class_id=' + encodeURIComponent(classId);
        const res = await fetch(url, { headers: { 'X-CSRF-Token': csrfToken } });
        const data = await res.json();
        if (!data.ok) {
          excludeBox.innerHTML = '<div class="alert danger"><strong>' + (data.error || 'Fehler') + '</strong></div>';
          return;
        }
        const items = data.students || [];
        if (!items.length) {
          excludeBox.innerHTML = '<div class="muted">Keine aktiven Schüler in der Quelle.</div>';
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
