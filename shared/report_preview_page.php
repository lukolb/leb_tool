<?php
declare(strict_types=1);

if (!isset($pdo, $u, $isAdmin)) {
  throw new RuntimeException('report_preview_page.php: context missing');
}
require_once __DIR__ . '/report_request_notifications.php';

function report_preview_period_label_display(string $raw): string {
  $val = normalize_class_period_label($raw);
  return $val === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
}

function report_preview_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = array_key_exists('grade_level', $c) && $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  $id = (int)($c['class_id'] ?? ($c['id'] ?? 0));
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . $id));
}

function report_preview_meta_read(?string $json): array {
  if (!$json) return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}

function report_preview_extract_date_format(array $meta): string {
  $mode = isset($meta['date_format_mode']) ? strtolower(trim((string)$meta['date_format_mode'])) : '';
  $preset = trim((string)($meta['date_format_preset'] ?? ''));
  $custom = trim((string)($meta['date_format_custom'] ?? ''));
  return $mode === 'custom' ? $custom : $preset;
}

function report_preview_normalize_rect($rect): ?array {
  if (!is_array($rect) || count($rect) !== 4) return null;
  $nums = array_map('floatval', $rect);
  foreach ($nums as $n) {
    if (!is_finite($n)) return null;
  }
  return $nums;
}

function report_preview_resolve_value(PDO $pdo, int $fieldId, ?string $valueText, ?string $valueJson): string {
  $txt = (string)($valueText ?? '');
  if (!$valueJson) return $txt;
  $j = json_decode($valueJson, true);
  if (!is_array($j)) return $txt;
  $optId = isset($j['option_item_id']) ? (int)$j['option_item_id'] : 0;
  if ($optId > 0) {
    $st = $pdo->prepare('SELECT value FROM option_list_items WHERE id=? LIMIT 1');
    $st->execute([$optId]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }
  return $txt;
}

function report_preview_class_for_report(
  PDO $pdo,
  int $canonicalStudentId,
  int $templateId,
  string $schoolYear,
  string $periodLabel,
  ?int $teacherUserId = null
): ?array {
  if ($canonicalStudentId <= 0 || $schoolYear === '') return null;
  $sql =
    "SELECT c.id AS class_id, c.grade_level, c.label, c.name, c.school_year, c.period_label, sca.is_active
     FROM student_class_assignments sca
     INNER JOIN classes c ON c.id=sca.class_id";
  $params = [];
  $sql .=
    " WHERE sca.student_id=? AND c.school_year=? AND c.period_label=?";
  array_push($params, $canonicalStudentId, $schoolYear, $periodLabel);
  if ($teacherUserId !== null) {
    $sql .=
      " AND (
          EXISTS (
            SELECT 1 FROM user_class_assignments uca
            WHERE uca.class_id=c.id AND uca.user_id=?
          )
          OR EXISTS (
            SELECT 1 FROM report_access_requests rar
            WHERE rar.class_id=c.id
              AND rar.requester_user_id=?
              AND rar.scope_type='class'
              AND rar.status='approved'
              AND (rar.expires_at IS NULL OR rar.expires_at>=NOW())
          )
          OR EXISTS (
            SELECT 1 FROM report_access_requests student_grant
            WHERE student_grant.requester_user_id=?
              AND student_grant.scope_type='student'
              AND student_grant.student_id=?
              AND student_grant.status='approved'
              AND (student_grant.expires_at IS NULL OR student_grant.expires_at>=NOW())
          )
        )";
    array_push($params, $teacherUserId, $teacherUserId, $teacherUserId, $canonicalStudentId);
  }
  $sql .=
    " ORDER BY (c.template_id=?) DESC, sca.is_active DESC, c.is_active DESC, c.id DESC
      LIMIT 1";
  $params[] = $templateId;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function report_preview_report_label(array $report): string {
  $label = trim((string)($report['school_year'] ?? ''));
  if ($label !== '') $label .= ' · ';
  $label .= report_preview_period_label_display((string)($report['period_label'] ?? 'H1'));
  return $label;
}

function report_preview_page_url(bool $isAdmin, array $params = []): string {
  $clean = [];
  foreach ($params as $key => $value) {
    if ($value === null || $value === '' || $value === 0 || $value === '0') continue;
    $clean[$key] = $value;
  }
  $path = ($isAdmin ? 'admin' : 'teacher') . '/report_preview.php';
  return url($path . ($clean ? ('?' . http_build_query($clean)) : ''));
}

$userId = (int)($u['id'] ?? 0);
$pageError = '';
$pageOk = '';
if ((int)($_GET['deleted'] ?? 0) === 1) {
  $pageOk = t('teacher.report_preview.delete_success', 'Der Bericht wurde vollständig gelöscht.');
} elseif ((int)($_GET['delete_requested'] ?? 0) === 1) {
  $pageOk = t('teacher.report_preview.delete_request_success', 'Die Löschung wurde beim Admin beantragt.');
}

if ((string)($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $deleteReportId = max(0, (int)($_POST['report_id'] ?? 0));
    if ($action === 'request_report_deletion') {
      if ($isAdmin) throw new RuntimeException(t('teacher.report_preview.delete_invalid', 'Ungültige Berichtsaktion.'));
      $reason = trim((string)($_POST['reason'] ?? ''));
      if ($deleteReportId <= 0 || $reason === '') {
        throw new RuntimeException(t('teacher.report_preview.delete_request_reason_required', 'Bitte geben Sie einen Grund für die Löschanfrage an.'));
      }
      $stRequestReport = $pdo->prepare(
        "SELECT ri.id, ri.template_id, ri.school_year, ri.period_label,
                COALESCE(NULLIF(s.master_student_id, 0), s.id) AS canonical_student_id,
                s.first_name, s.last_name, t.name AS template_name
         FROM report_instances ri
         INNER JOIN students s ON s.id=ri.student_id
         LEFT JOIN templates t ON t.id=ri.template_id
         WHERE ri.id=? AND ri.student_id IS NOT NULL
         LIMIT 1"
      );
      $stRequestReport->execute([$deleteReportId]);
      $requestReport = $stRequestReport->fetch(PDO::FETCH_ASSOC);
      if (!$requestReport) throw new RuntimeException(t('teacher.report_preview.delete_not_found', 'Der Bericht wurde nicht gefunden.'));
      $canonicalStudentId = (int)($requestReport['canonical_student_id'] ?? 0);
      if (!user_can_view_student_report(
        $pdo,
        $userId,
        $canonicalStudentId,
        (string)($requestReport['school_year'] ?? ''),
        (string)($requestReport['period_label'] ?? 'H1')
      )) {
        throw new RuntimeException(t('session.forbidden.message', 'Keine Berechtigung.'));
      }
      $pdo->beginTransaction();
      $stLockReport = $pdo->prepare("SELECT id FROM report_instances WHERE id=? AND student_id IS NOT NULL LIMIT 1 FOR UPDATE");
      $stLockReport->execute([$deleteReportId]);
      if (!$stLockReport->fetchColumn()) throw new RuntimeException(t('teacher.report_preview.delete_not_found', 'Der Bericht wurde nicht gefunden.'));
      $stPending = $pdo->prepare("SELECT id FROM report_deletion_requests WHERE report_instance_id=? AND status='pending' LIMIT 1");
      $stPending->execute([$deleteReportId]);
      if ($stPending->fetchColumn()) {
        throw new RuntimeException(t('teacher.report_preview.delete_request_pending', 'Für diesen Bericht liegt bereits eine Löschanfrage vor.'));
      }
      $studentName = trim((string)($requestReport['first_name'] ?? '') . ' ' . (string)($requestReport['last_name'] ?? ''));
      $pdo->prepare(
        "INSERT INTO report_deletion_requests
          (report_instance_id, report_id_snapshot, student_id, student_name, school_year, period_label, template_name,
           requested_by_user_id, reason, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())"
      )->execute([
        $deleteReportId,
        $deleteReportId,
        $canonicalStudentId,
        $studentName,
        (string)($requestReport['school_year'] ?? ''),
        (string)($requestReport['period_label'] ?? ''),
        (string)($requestReport['template_name'] ?? ''),
        $userId,
        $reason,
      ]);
      $pdo->commit();
      audit('teacher_report_delete_request', $userId, [
        'report_instance_id' => $deleteReportId,
        'student_id' => $canonicalStudentId,
      ]);
      notify_admins_about_report_deletion_request($pdo, [
        'requester_name' => (string)($u['display_name'] ?? $u['email'] ?? ''),
        'student_name' => $studentName,
        'school_year' => (string)($requestReport['school_year'] ?? ''),
        'period_label' => (string)($requestReport['period_label'] ?? ''),
        'reason' => $reason,
      ]);
      header('Location: ' . report_preview_page_url(false, [
        'student_id' => $canonicalStudentId,
        'report_id' => $deleteReportId,
        'class_id' => max(0, (int)($_GET['class_id'] ?? 0)),
        'q' => trim((string)($_GET['q'] ?? '')),
        'delete_requested' => 1,
      ]));
      exit;
    }

    if ($action !== 'delete_report') throw new RuntimeException(t('teacher.report_preview.delete_invalid', 'Ungültige Berichtsaktion.'));
    if (!$isAdmin) throw new RuntimeException(t('teacher.report_preview.delete_admin_only', 'Nur Administratoren dürfen Berichte löschen.'));
    $confirmAck = (int)($_POST['confirm_ack'] ?? 0) === 1;
    $confirmText = trim((string)($_POST['confirm_text'] ?? ''));
    $mustMatch = 'BERICHT-' . $deleteReportId;
    if ($deleteReportId <= 0 || !$confirmAck || !hash_equals($mustMatch, $confirmText)) {
      throw new RuntimeException(str_replace('{value}', $mustMatch, t('teacher.report_preview.delete_confirmation_failed', 'Sicherheitsprüfung fehlgeschlagen. Geben Sie exakt {value} ein.')));
    }

    $pdo->beginTransaction();
    $deleteReport = delete_student_report_permanently($pdo, $deleteReportId, $userId);
    if (!$deleteReport) throw new RuntimeException(t('teacher.report_preview.delete_not_found', 'Der Bericht wurde nicht gefunden.'));
    $canonicalStudentId = (int)($deleteReport['canonical_student_id'] ?? 0);
    audit('admin_report_delete', $userId, [
      'deleted_report_instance_id' => $deleteReportId,
      'student_id' => $canonicalStudentId,
      'school_year' => (string)($deleteReport['school_year'] ?? ''),
      'period_label' => (string)($deleteReport['period_label'] ?? ''),
      'template_id' => (int)($deleteReport['template_id'] ?? 0),
    ]);
    $pdo->commit();
    header('Location: ' . report_preview_page_url(true, [
      'student_id' => $canonicalStudentId,
      'class_id' => max(0, (int)($_GET['class_id'] ?? 0)),
      'q' => trim((string)($_GET['q'] ?? '')),
      'deleted' => 1,
    ]));
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pageError = $e->getMessage();
  }
}

$studentSql =
  "SELECT s.id AS canonical_student_id, s.first_name, s.last_name, s.is_active
   FROM students s
   WHERE COALESCE(NULLIF(s.master_student_id, 0), s.id)=s.id";
$studentParams = [];
if (!$isAdmin) {
  $studentSql .=
    " AND (
        EXISTS (
          SELECT 1
          FROM student_class_assignments sca
          INNER JOIN classes c ON c.id=sca.class_id AND c.is_active=1
          INNER JOIN user_class_assignments uca ON uca.class_id=sca.class_id
          WHERE sca.student_id=s.id AND sca.is_active=1 AND uca.user_id=?
        )
        OR EXISTS (
          SELECT 1
          FROM report_access_requests rar
          WHERE rar.requester_user_id=? AND rar.status='approved'
            AND (rar.expires_at IS NULL OR rar.expires_at>=NOW())
            AND (
              (rar.scope_type='student' AND rar.student_id=s.id)
              OR (rar.scope_type='class' AND EXISTS (
                SELECT 1 FROM student_class_assignments grant_sca
                WHERE grant_sca.class_id=rar.class_id AND grant_sca.student_id=s.id
              ))
            )
        )
      )";
  array_push($studentParams, $userId, $userId);
}
$studentSql .= " ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC";
$stStudents = $pdo->prepare($studentSql);
$stStudents->execute($studentParams);
$studentChoices = $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];

$studentsByCanonical = [];
foreach ($studentChoices as &$studentChoice) {
  $canonicalId = (int)($studentChoice['canonical_student_id'] ?? 0);
  $studentChoice['current_class_label'] = '';
  $studentChoice['latest_class_label'] = '';
  $studentChoice['display_class_label'] = '';
  $studentChoice['current_class_ids'] = [];
  $studentChoice['has_active_assignment'] = false;
  $studentChoice['has_access_grant'] = false;
  if ($canonicalId > 0) $studentsByCanonical[$canonicalId] = &$studentChoice;
}
unset($studentChoice);

if (!$isAdmin && $studentsByCanonical) {
  $stGrantedStudents = $pdo->prepare(
    "SELECT student_id
     FROM report_access_requests
     WHERE requester_user_id=? AND status='approved' AND (expires_at IS NULL OR expires_at>=NOW())
       AND scope_type='student' AND student_id IS NOT NULL
     UNION
     SELECT sca.student_id
     FROM report_access_requests rar
     INNER JOIN student_class_assignments sca ON sca.class_id=rar.class_id
     WHERE rar.requester_user_id=? AND rar.status='approved'
       AND (rar.expires_at IS NULL OR rar.expires_at>=NOW()) AND rar.scope_type='class'"
  );
  $stGrantedStudents->execute([$userId, $userId]);
  foreach ($stGrantedStudents->fetchAll(PDO::FETCH_COLUMN) ?: [] as $grantedStudentId) {
    $grantedStudentId = (int)$grantedStudentId;
    if (isset($studentsByCanonical[$grantedStudentId])) $studentsByCanonical[$grantedStudentId]['has_access_grant'] = true;
  }
}

$requestedClassFilter = max(0, (int)($_GET['class_id'] ?? 0));
$currentClassOptions = [];
if ($studentsByCanonical) {
  $canonicalIds = array_keys($studentsByCanonical);
  $in = implode(',', array_fill(0, count($canonicalIds), '?'));
  $assignmentSql =
    "SELECT sca.student_id, sca.is_active, c.is_active AS class_is_active,
            c.id AS class_id, c.grade_level, c.label, c.name, c.school_year, c.period_label
     FROM student_class_assignments sca
     INNER JOIN classes c ON c.id=sca.class_id";
  $assignmentParams = [];
  $assignmentSql .= " WHERE sca.student_id IN ($in)";
  $assignmentParams = array_merge($assignmentParams, $canonicalIds);
  if (!$isAdmin) {
    $assignmentSql .=
      " AND (
          EXISTS (
            SELECT 1 FROM user_class_assignments uca
            WHERE uca.class_id=c.id AND uca.user_id=?
          )
          OR EXISTS (
            SELECT 1 FROM report_access_requests rar
            WHERE rar.class_id=c.id
              AND rar.requester_user_id=?
              AND rar.scope_type='class'
              AND rar.status='approved'
              AND (rar.expires_at IS NULL OR rar.expires_at>=NOW())
          )
          OR EXISTS (
            SELECT 1 FROM report_access_requests student_grant
            WHERE student_grant.requester_user_id=?
              AND student_grant.scope_type='student'
              AND student_grant.student_id=sca.student_id
              AND student_grant.status='approved'
              AND (student_grant.expires_at IS NULL OR student_grant.expires_at>=NOW())
          )
        )";
    array_push($assignmentParams, $userId, $userId, $userId);
  }
  $assignmentSql .=
    " ORDER BY c.school_year DESC,
               CASE c.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC, c.id DESC";
  $stAssignments = $pdo->prepare($assignmentSql);
  $stAssignments->execute($assignmentParams);
  foreach ($stAssignments->fetchAll(PDO::FETCH_ASSOC) ?: [] as $assignment) {
    $sid = (int)($assignment['student_id'] ?? 0);
    if (!isset($studentsByCanonical[$sid])) continue;
    $assignmentClassId = (int)($assignment['class_id'] ?? 0);
    $classLabel = report_preview_class_display($assignment);
    if ($classLabel !== '' && $studentsByCanonical[$sid]['latest_class_label'] === '') {
      $studentsByCanonical[$sid]['latest_class_label'] = $classLabel;
    }
    if ((int)($assignment['is_active'] ?? 0) === 1 && (int)($assignment['class_is_active'] ?? 0) === 1) {
      $studentsByCanonical[$sid]['has_active_assignment'] = true;
      if ($classLabel !== '' && $studentsByCanonical[$sid]['current_class_label'] === '') {
        $studentsByCanonical[$sid]['current_class_label'] = $classLabel;
      }
    }
    if ($assignmentClassId > 0 && (
      ((int)($assignment['is_active'] ?? 0) === 1 && (int)($assignment['class_is_active'] ?? 0) === 1)
      || $assignmentClassId === $requestedClassFilter
    )) {
      $studentsByCanonical[$sid]['current_class_ids'][$assignmentClassId] = $assignmentClassId;
      $currentClassOptions[$assignmentClassId] = $classLabel;
    }
  }
}
foreach ($studentChoices as &$studentChoice) {
  $studentChoice['display_class_label'] = (string)($studentChoice['current_class_label'] ?: $studentChoice['latest_class_label']);
}
unset($studentChoice);
natcasesort($currentClassOptions);

$requestedReportId = max(0, (int)($_GET['report_id'] ?? 0));
$requestedStudentId = max(0, (int)($_GET['student_id'] ?? 0));
if ($requestedReportId > 0) {
  $reportOwnerSql =
    "SELECT COALESCE(NULLIF(s.master_student_id, 0), s.id)
     FROM report_instances ri
     INNER JOIN students s ON s.id=ri.student_id
     WHERE ri.id=?";
  $reportOwnerParams = [$requestedReportId];
  if (!$isAdmin) {
    $reportOwnerSql .=
      " AND (
          EXISTS (
            SELECT 1 FROM report_access_requests student_grant
            WHERE student_grant.requester_user_id=?
              AND student_grant.scope_type='student'
              AND student_grant.student_id=COALESCE(NULLIF(s.master_student_id, 0), s.id)
              AND student_grant.status='approved'
              AND (student_grant.expires_at IS NULL OR student_grant.expires_at>=NOW())
          )
          OR EXISTS (
            SELECT 1
            FROM student_class_assignments sca
            INNER JOIN classes c ON c.id=sca.class_id
            WHERE sca.student_id=COALESCE(NULLIF(s.master_student_id, 0), s.id)
              AND c.school_year=ri.school_year AND c.period_label=ri.period_label
              AND (
                EXISTS (SELECT 1 FROM user_class_assignments uca WHERE uca.class_id=c.id AND uca.user_id=?)
                OR EXISTS (
                  SELECT 1 FROM report_access_requests class_grant
                  WHERE class_grant.class_id=c.id
                    AND class_grant.requester_user_id=?
                    AND class_grant.scope_type='class'
                    AND class_grant.status='approved'
                    AND (class_grant.expires_at IS NULL OR class_grant.expires_at>=NOW())
                )
              )
          )
        )";
    array_push($reportOwnerParams, $userId, $userId, $userId);
  }
  $reportOwnerSql .= " LIMIT 1";
  $stReportOwner = $pdo->prepare($reportOwnerSql);
  $stReportOwner->execute($reportOwnerParams);
  $reportOwnerId = (int)($stReportOwner->fetchColumn() ?: 0);
  if ($reportOwnerId > 0 && isset($studentsByCanonical[$reportOwnerId])) {
    $requestedStudentId = $reportOwnerId;
  }
}

$selectedClassFilter = $requestedClassFilter;
if ($selectedClassFilter > 0 && !isset($currentClassOptions[$selectedClassFilter])) $selectedClassFilter = 0;
$studentSearch = trim((string)($_GET['q'] ?? ''));
$studentSearchNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', $studentSearch) ?? $studentSearch, 'UTF-8');
$filteredStudentChoices = array_values(array_filter(
  $studentChoices,
  static function(array $student) use ($selectedClassFilter, $studentSearchNormalized): bool {
    $currentClassIds = is_array($student['current_class_ids'] ?? null) ? $student['current_class_ids'] : [];
    if ($selectedClassFilter > 0 && !isset($currentClassIds[$selectedClassFilter])) return false;
    if ($studentSearchNormalized === '') return true;
    $first = trim((string)($student['first_name'] ?? ''));
    $last = trim((string)($student['last_name'] ?? ''));
    $haystack = mb_strtolower($first . ' ' . $last . ' ' . $last . ' ' . $first, 'UTF-8');
    return mb_strpos($haystack, $studentSearchNormalized, 0, 'UTF-8') !== false;
  }
));

if ($requestedReportId > 0 && $requestedStudentId > 0) {
  $requestedStudentVisible = false;
  foreach ($filteredStudentChoices as $studentChoice) {
    if ((int)($studentChoice['canonical_student_id'] ?? 0) === $requestedStudentId) {
      $requestedStudentVisible = true;
      break;
    }
  }
  if (!$requestedStudentVisible) {
    $selectedClassFilter = 0;
    $studentSearch = '';
    $studentSearchNormalized = '';
    $filteredStudentChoices = $studentChoices;
  }
}

$filteredActiveStudents = array_values(array_filter(
  $filteredStudentChoices,
  static fn(array $student): bool => (bool)($student['has_active_assignment'] ?? false)
));
$filteredArchivedStudents = $isAdmin ? array_values(array_filter(
  $filteredStudentChoices,
  static fn(array $student): bool => !(bool)($student['has_active_assignment'] ?? false)
)) : [];
$filteredGrantedArchivedStudents = !$isAdmin ? array_values(array_filter(
  $filteredStudentChoices,
  static fn(array $student): bool => !(bool)($student['has_active_assignment'] ?? false) && (bool)($student['has_access_grant'] ?? false)
)) : [];
$studentSelectGroups = [];
if ($filteredActiveStudents) {
  $studentSelectGroups[t('teacher.report_preview.active_students_group', 'Aktuelle Schüler')] = $filteredActiveStudents;
}
if ($filteredArchivedStudents) {
  $studentSelectGroups[t('teacher.report_preview.archived_students_group', 'Archivierte Schüler')] = $filteredArchivedStudents;
}
if ($filteredGrantedArchivedStudents) {
  $studentSelectGroups[t('teacher.report_preview.granted_students_group', 'Freigegebene archivierte Schüler')] = $filteredGrantedArchivedStudents;
}
$groupedStudentChoices = array_merge($filteredActiveStudents, $filteredArchivedStudents, $filteredGrantedArchivedStudents);

$selectedStudentId = $requestedStudentId;
$filteredStudentIds = array_values(array_map(static fn(array $r): int => (int)($r['canonical_student_id'] ?? 0), $groupedStudentChoices));
if ($selectedStudentId <= 0 || !in_array($selectedStudentId, $filteredStudentIds, true)) {
  $selectedStudentId = $groupedStudentChoices ? (int)($groupedStudentChoices[0]['canonical_student_id'] ?? 0) : 0;
}

$studentOrderIds = $filteredStudentIds;
$selectedStudentPos = array_search($selectedStudentId, $studentOrderIds, true);
if ($selectedStudentPos === false) $selectedStudentPos = 0;
$prevStudentId = ($selectedStudentPos > 0) ? (int)$studentOrderIds[$selectedStudentPos - 1] : 0;
$nextStudentId = ($selectedStudentPos < count($studentOrderIds) - 1) ? (int)$studentOrderIds[$selectedStudentPos + 1] : 0;
$prevStudentName = ($prevStudentId > 0 && isset($studentsByCanonical[$prevStudentId]))
  ? trim((string)($studentsByCanonical[$prevStudentId]['last_name'] ?? '') . ', ' . (string)($studentsByCanonical[$prevStudentId]['first_name'] ?? ''))
  : '';
$nextStudentName = ($nextStudentId > 0 && isset($studentsByCanonical[$nextStudentId]))
  ? trim((string)($studentsByCanonical[$nextStudentId]['last_name'] ?? '') . ', ' . (string)($studentsByCanonical[$nextStudentId]['first_name'] ?? ''))
  : '';

$cfg = app_config();
$exportCfg = is_array($cfg['export'] ?? null) ? $cfg['export'] : [];
$radioCrossColorMode = (string)($exportCfg['radio_cross_color_mode'] ?? 'pdf_text');
if (!in_array($radioCrossColorMode, ['pdf_text', 'admin'], true)) $radioCrossColorMode = 'pdf_text';
$radioCrossColorHex = trim((string)($exportCfg['radio_cross_color'] ?? '#0b57d0'));
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $radioCrossColorHex)) $radioCrossColorHex = '#0b57d0';

$studentReports = [];
if ($selectedStudentId > 0) {
  $reportSql =
    "SELECT ri.id, ri.template_id, ri.student_id, ri.school_year, ri.period_label, ri.status,
            ri.created_at, ri.updated_at, t.name AS template_name, t.template_version
     FROM report_instances ri
     INNER JOIN students s ON s.id=ri.student_id
     LEFT JOIN templates t ON t.id=ri.template_id
     WHERE COALESCE(NULLIF(s.master_student_id, 0), s.id)=?";
  $reportParams = [$selectedStudentId];
  if (!$isAdmin) {
    $reportSql .=
      " AND (
          EXISTS (
            SELECT 1 FROM report_access_requests student_grant
            WHERE student_grant.requester_user_id=?
              AND student_grant.scope_type='student'
              AND student_grant.student_id=?
              AND student_grant.status='approved'
              AND (student_grant.expires_at IS NULL OR student_grant.expires_at>=NOW())
          )
          OR EXISTS (
            SELECT 1
            FROM student_class_assignments sca
            INNER JOIN classes c ON c.id=sca.class_id
            WHERE sca.student_id=?
              AND c.school_year=ri.school_year AND c.period_label=ri.period_label
              AND (
                EXISTS (SELECT 1 FROM user_class_assignments uca WHERE uca.class_id=c.id AND uca.user_id=?)
                OR EXISTS (
                  SELECT 1 FROM report_access_requests class_grant
                  WHERE class_grant.class_id=c.id
                    AND class_grant.requester_user_id=?
                    AND class_grant.scope_type='class'
                    AND class_grant.status='approved'
                    AND (class_grant.expires_at IS NULL OR class_grant.expires_at>=NOW())
                )
              )
          )
        )";
    array_push($reportParams, $userId, $selectedStudentId, $selectedStudentId, $userId, $userId);
  }
  $reportSql .=
    " ORDER BY ri.school_year DESC,
              CASE ri.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC,
              ri.updated_at DESC, ri.id DESC";
  $stReports = $pdo->prepare($reportSql);
  $stReports->execute($reportParams);
  $studentReports = $stReports->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($studentReports as &$reportRow) {
    $reportClass = report_preview_class_for_report(
      $pdo,
      $selectedStudentId,
      (int)($reportRow['template_id'] ?? 0),
      (string)($reportRow['school_year'] ?? ''),
      (string)($reportRow['period_label'] ?? 'H1'),
      $isAdmin ? null : $userId
    );
    $reportRow['class_id'] = (int)($reportClass['class_id'] ?? 0);
    $reportRow['class_label'] = $reportClass ? report_preview_class_display($reportClass) : '';
  }
  unset($reportRow);
}

$previewStatus = '';
$previewStatusLabel = '';
$previewReportId = 0;
$previewTemplateUrl = '';
$previewStudentName = '';
$previewValues = [];
$previewFieldMeta = [];
$previewTemplateName = '';
$previewClassLabel = '';
$previewExportUrl = '';

$statusMap = [
  'draft' => t('teacher.report_preview.status_draft', 'In Bearbeitung'),
  'locked' => t('teacher.report_preview.status_locked', 'In Bearbeitung'),
  'submitted' => t('teacher.report_preview.status_submitted', 'Eingereicht'),
  'archived' => t('teacher.report_preview.status_archived', 'Archiviert'),
  'final' => t('teacher.report_preview.status_final', 'Final'),
];

$selectedReportId = 0;
$ri = null;
foreach ($studentReports as $reportRow) {
  $rid = (int)($reportRow['id'] ?? 0);
  if ($rid <= 0) continue;
  if ($selectedReportId === 0) {
    $selectedReportId = $rid;
    $ri = $reportRow;
  }
  if ($requestedReportId > 0 && $rid === $requestedReportId) {
    $selectedReportId = $rid;
    $ri = $reportRow;
    break;
  }
}

$selectedSchoolYear = (string)($ri['school_year'] ?? '');
$selectedPeriodLabel = normalize_class_period_label((string)($ri['period_label'] ?? 'H1'));

if ($ri) {
    $previewReportId = (int)$ri['id'];
    $previewStatus = (string)($ri['status'] ?? '');
    $previewStatusLabel = $statusMap[$previewStatus] ?? ($previewStatus !== '' ? strtoupper($previewStatus) : '—');
    $selectedStudent = $studentsByCanonical[$selectedStudentId] ?? [];
    $previewStudentName = trim((string)($selectedStudent['first_name'] ?? '') . ' ' . (string)($selectedStudent['last_name'] ?? ''));
    $previewTemplateName = trim((string)($ri['template_name'] ?? ''));
    if ((int)($ri['template_version'] ?? 0) > 0) {
      $previewTemplateName .= ($previewTemplateName !== '' ? ' ' : '') . 'v' . (int)$ri['template_version'];
    }
    $previewClassLabel = trim((string)($ri['class_label'] ?? ''));
    $previewTemplateUrl = url(($isAdmin ? 'admin' : 'teacher') . '/report_template_file.php?report_id=' . $previewReportId);
    $previewClassId = (int)($ri['class_id'] ?? 0);
    if ($previewClassId > 0) {
      $previewExportUrl = url(($isAdmin ? 'admin' : 'teacher') . '/export.php?' . http_build_query([
        'class_id' => $previewClassId,
        'mode' => 'single',
        'student_id' => $selectedStudentId,
        'report_id' => $previewReportId,
      ]));
    }

    $stVals = $pdo->prepare(
      "SELECT fv.id, fv.template_field_id, fv.value_text, fv.value_json, fv.source, fv.updated_at,
              tf.field_name, tf.field_type
       FROM field_values fv
       JOIN template_fields tf ON tf.id=fv.template_field_id
       WHERE fv.report_instance_id=?
       ORDER BY fv.template_field_id ASC,
                CASE fv.source WHEN 'teacher' THEN 30 WHEN 'child' THEN 20 WHEN 'system' THEN 10 ELSE 0 END DESC,
                fv.updated_at DESC, fv.id DESC"
    );
    $stVals->execute([$previewReportId]);
    $valuesByField = [];
    $valuesByName = [];
    foreach (($stVals->fetchAll(PDO::FETCH_ASSOC) ?: []) as $vRow) {
      $fid = (int)($vRow['template_field_id'] ?? 0);
      if ($fid <= 0) continue;
      $resolved = report_preview_resolve_value(
        $pdo,
        $fid,
        $vRow['value_text'] !== null ? (string)$vRow['value_text'] : '',
        $vRow['value_json'] !== null ? (string)$vRow['value_json'] : null
      );
      $fieldName = trim((string)($vRow['field_name'] ?? ''));
      if ($fieldName === '' || array_key_exists($fieldName, $valuesByName)) continue;
      $valuesByField[$fid] = $resolved;
      $valuesByName[$fieldName] = $resolved;
    }

    // Current reports may not have materialized their automatic bindings yet.
    // Resolve them in memory so opening the historical preview never mutates
    // the selected report. Final reports continue to show their stored snapshot.
    if ($previewStatus !== 'final' && function_exists('resolved_system_bindings_for_report')) {
      $resolvedBindings = resolved_system_bindings_for_report($pdo, $previewReportId);
      foreach (($resolvedBindings['values'] ?? []) as $fid => $entry) {
        $fieldName = trim((string)($entry['field_name'] ?? ''));
        if ($fieldName === '') continue;
        $valuesByField[(int)$fid] = (string)($entry['value'] ?? '');
        $valuesByName[$fieldName] = (string)($entry['value'] ?? '');
      }
    }

    $stFields = $pdo->prepare(
      "SELECT id, field_name, label, field_type, meta_json
       FROM template_fields
       WHERE template_id=?
       ORDER BY sort_order ASC, id ASC"
    );
    $stFields->execute([(int)$ri['template_id']]);
    $classFieldNames = [];
    $fieldsRows = $stFields->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($fieldsRows as $fRow) {
      $meta = report_preview_meta_read($fRow['meta_json'] ?? null);
      $fname = trim((string)($fRow['field_name'] ?? ''));
      if ($fname !== '') {
        $entry = ['field_type' => (string)($fRow['field_type'] ?? 'text')];
        $df = report_preview_extract_date_format($meta);
        if ($df !== '') $entry['date_format'] = $df;
        $previewFieldMeta[$fname] = $entry;
      }
      if ((string)($meta['scope'] ?? '') === 'class') {
        $classFieldNames[(string)($fRow['field_name'] ?? '')] = true;
      }
    }
    $selectedClassId = (int)($ri['class_id'] ?? 0);
    if ($classFieldNames && $selectedClassId > 0) {
      $stClassRi = $pdo->prepare(
        "SELECT id
         FROM report_instances
         WHERE template_id=? AND student_id IS NULL AND school_year=? AND period_label=?
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
      );
      $stClassRi->execute([(int)$ri['template_id'], $selectedSchoolYear, class_report_period_label($selectedClassId, $selectedPeriodLabel)]);
      $classRiId = (int)($stClassRi->fetchColumn() ?: 0);
      if ($classRiId > 0) {
        $stClassVals = $pdo->prepare(
          "SELECT fv.id, fv.template_field_id, fv.value_text, fv.value_json, fv.source, fv.updated_at,
                  tf.field_name, tf.field_type
           FROM field_values fv
           JOIN template_fields tf ON tf.id=fv.template_field_id
           WHERE fv.report_instance_id=?
           ORDER BY fv.template_field_id ASC,
                    CASE fv.source WHEN 'teacher' THEN 30 WHEN 'child' THEN 20 WHEN 'system' THEN 10 ELSE 0 END DESC,
                    fv.updated_at DESC, fv.id DESC"
        );
        $stClassVals->execute([$classRiId]);
        $loadedClassFields = [];
        foreach (($stClassVals->fetchAll(PDO::FETCH_ASSOC) ?: []) as $cv) {
          $fname = (string)($cv['field_name'] ?? '');
          if (!isset($classFieldNames[$fname]) || isset($loadedClassFields[$fname])) continue;
          $fid = (int)($cv['template_field_id'] ?? 0);
          if ($fid <= 0) continue;
          $loadedClassFields[$fname] = true;
          $valuesByField[$fid] = report_preview_resolve_value(
            $pdo,
            $fid,
            $cv['value_text'] !== null ? (string)$cv['value_text'] : '',
            $cv['value_json'] !== null ? (string)$cv['value_json'] : null
          );
          if ($fname !== '') $valuesByName[$fname] = (string)$valuesByField[$fid];
        }
      }
    }
    $previewValues = $valuesByName;
}
$pendingDeleteRequest = false;
if (!$isAdmin && $previewReportId > 0) {
  $stPendingDelete = $pdo->prepare("SELECT 1 FROM report_deletion_requests WHERE report_instance_id=? AND status='pending' LIMIT 1");
  $stPendingDelete->execute([$previewReportId]);
  $pendingDeleteRequest = (bool)$stPendingDelete->fetchColumn();
}
?>

<style>
  .rp-browser{display:grid;grid-template-columns:minmax(260px,340px) minmax(0,1fr);gap:18px;align-items:start}
  .rp-filters{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0 0 16px;padding:12px;background:#f8fafc;border:1px solid var(--border);border-radius:10px}
  .rp-filters .rp-search{min-width:220px;flex:1 1 260px}
  .rp-filters .rp-class-filter{min-width:190px;flex:0 1 260px}
  .rp-student-panel{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:16px}
  .rp-student-panel .input{width:100%}
  .rp-class-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .rp-class-tag{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:3px 9px;font-size:12px;color:#475569}
  .rp-report-list{display:grid;gap:8px}
  .rp-report-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px 14px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:#fff;color:inherit;text-decoration:none;transition:border-color .15s,box-shadow .15s,background .15s}
  .rp-report-item:hover{border-color:#94a3b8;box-shadow:0 2px 8px rgba(15,23,42,.08)}
  .rp-report-item.active{border-color:#3157c8;background:#eef3ff;box-shadow:0 0 0 2px rgba(49,87,200,.12)}
  .rp-report-title{font-weight:750;color:#0f172a}
  .rp-report-meta{grid-column:1/-1;color:#64748b;font-size:13px}
  .rp-status{align-self:start;border-radius:999px;background:#e2e8f0;padding:3px 8px;font-size:12px;font-weight:650;white-space:nowrap}
  .rp-report-item.active .rp-status{background:#dbe6ff;color:#1e3a8a}
  .rp-current-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);color:#475569;font-size:14px}
  @media (max-width:800px){.rp-browser{grid-template-columns:1fr}}
</style>

<div class="card">
  <h1 style="margin:0 0 6px;"><?=h(t('teacher.report_preview.title', 'Berichtsvorschau'))?></h1>
  <p class="muted" style="margin:0 0 18px;"><?=h(t('teacher.report_preview.intro', 'Wählen Sie einen Schüler und anschließend einen seiner gespeicherten Berichte. Die Berichte werden unabhängig von der aktuellen Klassenzuordnung geladen.'))?></p>

  <?php if ($pageError !== ''): ?><div class="alert danger" style="margin-bottom:14px;"><?=h($pageError)?></div><?php endif; ?>
  <?php if ($pageOk !== ''): ?><div class="alert success" style="margin-bottom:14px;"><?=h($pageOk)?></div><?php endif; ?>

  <form method="get" class="rp-filters">
    <div class="rp-search">
      <label class="label" for="rpStudentSearch"><?=h(t('teacher.report_preview.search_label', 'Schüler suchen'))?></label>
      <input id="rpStudentSearch" class="input" type="search" name="q" value="<?=h($studentSearch)?>" placeholder="<?=h(t('teacher.report_preview.search_placeholder', 'Vor- oder Nachname'))?>">
    </div>
    <div class="rp-class-filter">
      <label class="label" for="rpClassFilter"><?=h(t('teacher.report_preview.current_class_filter', 'Aktuelle Klasse'))?></label>
      <select id="rpClassFilter" class="input" name="class_id">
        <option value="0"><?=h(t('teacher.report_preview.all_current_classes', 'Alle aktuellen Klassen'))?></option>
        <?php foreach ($currentClassOptions as $classId => $classLabel): ?>
          <option value="<?=h((string)$classId)?>" <?= (int)$classId === $selectedClassFilter ? 'selected' : '' ?>><?=h($classLabel)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn" type="submit"><?=h(t('teacher.report_preview.apply_filter', 'Filtern'))?></button>
    <?php if ($studentSearch !== '' || $selectedClassFilter > 0): ?>
      <a class="btn secondary" href="<?=h(report_preview_page_url($isAdmin))?>"><?=h(t('teacher.report_preview.reset_filter', 'Filter zurücksetzen'))?></a>
    <?php endif; ?>
    <span class="muted" style="margin-left:auto;align-self:center;"><?=h(str_replace('{count}', (string)count($filteredStudentChoices), t('teacher.report_preview.filter_count', '{count} Schüler')))?></span>
  </form>

  <div class="rp-browser">
    <section class="rp-student-panel">
      <form method="get">
        <label class="label" for="rpStudent"><?=h(t('teacher.report_preview.student', 'Schüler'))?></label>
        <input type="hidden" name="class_id" value="<?=h((string)$selectedClassFilter)?>">
        <input type="hidden" name="q" value="<?=h($studentSearch)?>">
        <select id="rpStudent" name="student_id" class="input" onchange="this.form.submit()" <?= !$filteredStudentChoices ? 'disabled' : '' ?>>
          <?php if (!$filteredStudentChoices): ?><option><?=h(t('teacher.report_preview.no_students_match', 'Keine passenden Schüler gefunden'))?></option><?php endif; ?>
          <?php foreach ($studentSelectGroups as $groupLabel => $groupStudents): ?>
            <optgroup label="<?=h($groupLabel)?>">
              <?php foreach ($groupStudents as $s):
                $sid = (int)($s['canonical_student_id'] ?? 0);
                $nm = trim((string)($s['last_name'] ?? '') . ', ' . (string)($s['first_name'] ?? ''));
                $active = (bool)($s['has_active_assignment'] ?? false);
                $displayClassLabel = trim((string)($s['display_class_label'] ?? ''));
                $classSuffix = $displayClassLabel !== '' ? (' · ' . $displayClassLabel) : '';
                ?>
                <option value="<?=h((string)$sid)?>" <?= $sid === $selectedStudentId ? 'selected' : '' ?>>
                  <?=h($nm . $classSuffix . ($active ? '' : ' (' . t('teacher.report_preview.student_archived', 'archiviert') . ')'))?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn" type="submit" style="margin-top:8px;"><?=h(t('ui.save', 'Anzeigen'))?></button></noscript>
      </form>

      <?php $selectedStudent = $studentsByCanonical[$selectedStudentId] ?? null; ?>
      <?php if ($selectedStudent): ?>
        <div class="rp-class-tags" aria-label="<?=h(t('teacher.report_preview.class_assignments', 'Klassenzuordnung'))?>">
          <?php if (trim((string)($selectedStudent['display_class_label'] ?? '')) !== ''): ?>
            <span class="rp-class-tag"><?=h((string)$selectedStudent['display_class_label'])?></span>
          <?php else: ?>
            <span class="muted"><?=h(t('teacher.report_preview.no_class_assignment', 'Keine Klassenzuordnung'))?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="actions" style="justify-content:space-between;gap:8px;margin-top:16px;">
        <?php
          $prevUrl = report_preview_page_url($isAdmin, ['student_id'=>$prevStudentId, 'class_id'=>$selectedClassFilter, 'q'=>$studentSearch]);
          $nextUrl = report_preview_page_url($isAdmin, ['student_id'=>$nextStudentId, 'class_id'=>$selectedClassFilter, 'q'=>$studentSearch]);
        ?>
        <button class="btn secondary" type="button"
          <?= $prevStudentId > 0 ? 'onclick="window.location.href=' . h(json_encode($prevUrl)) . '"' : 'disabled aria-disabled="true"' ?>
          title="<?=h($prevStudentName !== '' ? $prevStudentName : '—')?>">← <?=h(t('teacher.pdf_entry.prev_student', 'Vorheriger Schüler'))?></button>
        <button class="btn secondary" type="button"
          <?= $nextStudentId > 0 ? 'onclick="window.location.href=' . h(json_encode($nextUrl)) . '"' : 'disabled aria-disabled="true"' ?>
          title="<?=h($nextStudentName !== '' ? $nextStudentName : '—')?>"><?=h(t('teacher.pdf_entry.next_student', 'Nächster Schüler'))?> →</button>
      </div>
    </section>

    <section>
      <h2 style="margin:0 0 10px;font-size:18px;">
        <?=h(t('teacher.report_preview.reports', 'Gespeicherte Berichte'))?>
        <span class="muted" style="font-weight:400;">(<?=h((string)count($studentReports))?>)</span>
      </h2>
      <?php if ($studentReports): ?>
        <div class="rp-report-list">
          <?php foreach ($studentReports as $report):
            $rid = (int)($report['id'] ?? 0);
            $status = (string)($report['status'] ?? '');
            $statusLabel = $statusMap[$status] ?? ($status !== '' ? strtoupper($status) : '—');
            $templateLabel = trim((string)($report['template_name'] ?? ''));
            if ((int)($report['template_version'] ?? 0) > 0) $templateLabel .= ($templateLabel !== '' ? ' ' : '') . 'v' . (int)$report['template_version'];
            $reportUrl = report_preview_page_url($isAdmin, ['student_id'=>$selectedStudentId, 'report_id'=>$rid, 'class_id'=>$selectedClassFilter, 'q'=>$studentSearch]);
            $updatedFormat = ui_lang() === 'en' ? 'm/d/Y g:i A' : 'd.m.Y H:i';
            ?>
            <a class="rp-report-item <?= $rid === $selectedReportId ? 'active' : '' ?>" href="<?=h($reportUrl)?>" <?= $rid === $selectedReportId ? 'aria-current="true"' : '' ?>>
              <span class="rp-report-title"><?=h(report_preview_report_label($report))?></span>
              <span class="rp-status"><?=h($statusLabel)?></span>
              <span class="rp-report-meta">
                <?=h($templateLabel !== '' ? $templateLabel : ('#' . (int)($report['template_id'] ?? 0)))?>
                <?php if (trim((string)($report['class_label'] ?? '')) !== ''): ?> · <?=h((string)$report['class_label'])?><?php endif; ?>
                · <?=h(t('teacher.report_preview.updated', 'Aktualisiert'))?> <?=render_local_datetime((string)($report['updated_at'] ?? ''), $updatedFormat)?>
                · #<?=h((string)$rid)?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="alert"><?=h(t('teacher.report_preview.no_reports_for_student', 'Für diesen Schüler sind keine gespeicherten Berichte vorhanden.'))?></div>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($previewTemplateUrl !== ''): ?>
    <div class="rp-current-meta">
      <span><strong><?=h($previewStudentName)?></strong></span>
      <span><?=h(report_preview_report_label($ri ?: []))?></span>
      <?php if ($previewTemplateName !== ''): ?><span><?=h($previewTemplateName)?></span><?php endif; ?>
      <?php if ($previewClassLabel !== ''): ?><span><?=h(t('teacher.report_preview.class_context', 'Klasse des Berichts'))?>: <?=h($previewClassLabel)?></span><?php endif; ?>
      <span><?=h(t('teacher.report_preview.status', 'Status'))?>: <strong><?=h($previewStatusLabel)?></strong></span>
    </div>
    <div class="actions" style="justify-content:flex-start;align-items:flex-start;gap:10px;margin-top:12px;flex-wrap:wrap;">
      <?php if ($previewExportUrl !== ''): ?><a id="rpDownload" class="btn" href="<?=h($previewExportUrl)?>"><?=h(t('teacher.report_preview.export_pdf', 'Diesen Bericht als PDF exportieren'))?></a><?php endif; ?>
      <?php if ($isAdmin):
        $deleteConfirmationValue = 'BERICHT-' . $previewReportId;
        $deleteActionUrl = report_preview_page_url(true, [
          'student_id'=>$selectedStudentId,
          'report_id'=>$previewReportId,
          'class_id'=>$selectedClassFilter,
          'q'=>$studentSearch,
        ]);
        ?>
        <details style="max-width:620px;">
          <summary class="btn danger" style="cursor:pointer;list-style:none;"><?=h(t('teacher.report_preview.delete_open', 'Bericht löschen…'))?></summary>
          <form method="post" action="<?=h($deleteActionUrl)?>" class="card" style="margin-top:8px;border-color:#dc2626;background:#fff7f7;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="delete_report">
            <input type="hidden" name="report_id" value="<?=h((string)$previewReportId)?>">
            <strong><?=h(t('teacher.report_preview.delete_heading', 'Sicherheitsprüfung vor dem Löschen'))?></strong>
            <p style="margin:8px 0;"><?=h(t('teacher.report_preview.delete_warning', 'Nur dieser Bericht wird einschließlich seiner Feldwerte, Änderungshistorie und Elternfreigaben endgültig gelöscht. Der Schüler und andere Berichte bleiben erhalten.'))?></p>
            <label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0;">
              <input type="checkbox" name="confirm_ack" value="1" required>
              <span><?=h(t('teacher.report_preview.delete_ack', 'Ich habe geprüft, dass genau der angezeigte Bericht gelöscht werden soll.'))?></span>
            </label>
            <label class="label" for="rpDeleteConfirm"><?=h(str_replace('{value}', $deleteConfirmationValue, t('teacher.report_preview.delete_type', 'Zur Bestätigung exakt {value} eingeben:')))?></label>
            <input id="rpDeleteConfirm" class="input" name="confirm_text" autocomplete="off" required pattern="<?=h($deleteConfirmationValue)?>">
            <button class="btn danger" type="submit" style="margin-top:10px;"><?=h(t('teacher.report_preview.delete_confirm', 'Diesen Bericht endgültig löschen'))?></button>
          </form>
        </details>
        <a class="btn secondary" href="<?=h(url('admin/report_requests.php#deletion-requests'))?>"><?=h(t('teacher.report_preview.delete_requests_manage', 'Löschanfragen verwalten'))?></a>
      <?php elseif ($pendingDeleteRequest): ?>
        <span class="alert info" style="margin:0;padding:9px 12px;"><?=h(t('teacher.report_preview.delete_request_pending', 'Für diesen Bericht liegt bereits eine Löschanfrage vor.'))?></span>
      <?php else:
        $deleteRequestUrl = report_preview_page_url(false, [
          'student_id'=>$selectedStudentId,
          'report_id'=>$previewReportId,
          'class_id'=>$selectedClassFilter,
          'q'=>$studentSearch,
        ]);
        ?>
        <details style="max-width:620px;">
          <summary class="btn secondary" style="cursor:pointer;list-style:none;"><?=h(t('teacher.report_preview.delete_request_open', 'Löschung beantragen…'))?></summary>
          <form method="post" action="<?=h($deleteRequestUrl)?>" class="card" style="margin-top:8px;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="request_report_deletion">
            <input type="hidden" name="report_id" value="<?=h((string)$previewReportId)?>">
            <p style="margin-top:0"><?=h(t('teacher.report_preview.delete_request_hint', 'Der Bericht wird nicht sofort gelöscht. Ein Admin prüft und bestätigt die Anfrage.'))?></p>
            <label class="label" for="rpDeleteRequestReason"><?=h(t('teacher.report_preview.delete_request_reason', 'Begründung'))?></label>
            <textarea id="rpDeleteRequestReason" class="input" name="reason" rows="3" maxlength="2000" required></textarea>
            <button class="btn" type="submit" style="margin-top:10px;"><?=h(t('teacher.report_preview.delete_request_submit', 'Löschanfrage senden'))?></button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div id="rpPreview" class="card" style="background:#f8f9fb; border:1px solid var(--border); min-height:120px;">
  <?php if ($previewTemplateUrl === ''): ?>
    <div class="muted"><?=h(t('teacher.report_preview.no_preview', 'Keine Vorschau verfügbar.'))?></div>
  <?php else: ?>
    <div class="pdf-loader" role="status"><span class="spinner"></span> <span class="txt"><?=h(t('ui.loading'))?></span></div>
  <?php endif; ?>
</div>

<?php if ($previewTemplateUrl !== ''): ?>
<script type="module">
  import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
  pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";
  const FONT_MANIFEST_URL = <?= json_encode(url('shared/font_manifest.php')) ?>;
  const FONTKIT_URL = 'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js';
  const RADIO_CROSS_COLOR_MODE = <?= json_encode($radioCrossColorMode) ?>;
  const RADIO_CROSS_COLOR_HEX = <?= json_encode($radioCrossColorHex) ?>;

  const preview = document.getElementById('rpPreview');
  const templateUrl = <?=json_encode($previewTemplateUrl)?>;
  const fieldValues = <?=json_encode($previewValues, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const fieldMeta = <?=json_encode($previewFieldMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const prevStudentId = <?= json_encode($prevStudentId) ?>;
  const nextStudentId = <?= json_encode($nextStudentId) ?>;
  const previewBasePath = <?= json_encode(url(($isAdmin ? 'admin' : 'teacher') . '/report_preview.php')) ?>;
  const selectedClassFilter = <?= json_encode($selectedClassFilter) ?>;
  const studentSearch = <?= json_encode($studentSearch) ?>;

  function goToStudent(studentId){
    if (!studentId) return;
    const u = new URL(previewBasePath, window.location.origin);
    u.searchParams.set('student_id', String(studentId));
    if (Number(selectedClassFilter) > 0) u.searchParams.set('class_id', String(selectedClassFilter));
    if (String(studentSearch).trim() !== '') u.searchParams.set('q', String(studentSearch));
    window.location.href = u.toString();
  }

  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    const target = event.target;
    const tag = target && target.tagName ? String(target.tagName).toLowerCase() : '';
    const isTyping = !!(target && (target.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select'));
    if (isTyping) return;

    if (event.key === 'ArrowLeft' && Number(prevStudentId) > 0) {
      event.preventDefault();
      goToStudent(Number(prevStudentId));
      return;
    }
    if (event.key === 'ArrowRight' && Number(nextStudentId) > 0) {
      event.preventDefault();
      goToStudent(Number(nextStudentId));
    }
  });

  const monthNames = {
    de: {
      full: ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
      short: ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez']
    },
    en: {
      full: ['January','February','March','April','May','June','July','August','September','October','November','December'],
      short: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
    }
  };

  function pad2(n){ return String(n).padStart(2, '0'); }
  function numberToMonthName(m, lang='de', mode='full'){
    const l = monthNames[lang] ? lang : 'de';
    const arr = monthNames[l][mode] || monthNames[l].full;
    const idx = Math.max(1, Math.min(12, Number(m))) - 1;
    return arr[idx] || '';
  }
  function parseFlexibleDate(raw){
    const s = (raw ?? '').toString().trim();
    if (!s) return null;
    let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (m) return { y:+m[1], m:+m[2], d:+m[3] };
    m = s.match(/^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})$/);
    if (m) {
      let y = +m[3];
      if (y < 100) y += 2000;
      return { y, m:+m[2], d:+m[1] };
    }
    return null;
  }
  function matchesFormat(raw, fmt){
    const p = parseFlexibleDate(raw);
    if (!p) return false;
    const f = formatDate(p, fmt);
    return f === raw;
  }
  function formatDate(parts, fmt){
    if (!parts || !fmt) return '';
    const y = parts.y, m = parts.m, d = parts.d;
    const yy = String(y).slice(-2);
    const lang = <?= json_encode(ui_lang()) ?>;
    const tokenMap = {
      'MMMM': numberToMonthName(m, lang, 'full'),
      'MMM': numberToMonthName(m, lang, 'short'),
      'YYYY': String(y),
      'YY': yy,
      'DD': pad2(d),
      'D': String(d),
      'MM': pad2(m),
      'M': String(m),
    };
    return fmt.replace(/(?<!\p{L})(MMMM|MMM|YYYY|YY|DD|MM|D|M)(?!\p{L})/gu, (tok) => tokenMap[tok] ?? tok);
  }
  function normalizeDateIfNeeded(rawValue, expectedFmt){
    const raw = (rawValue ?? '').toString().trim();
    const fmt = (expectedFmt ?? '').toString().trim();
    if (!raw || !fmt) return raw;
    if (matchesFormat(raw, fmt)) return raw;
    const parsed = parseFlexibleDate(raw);
    if (!parsed) return raw;
    return formatDate(parsed, fmt) || raw;
  }

  let __fontManifest = null;
  let __embeddedFonts = new Map();
  function normalizeFontName(raw){
    if (!raw) return '';
    let name = String(raw).trim().replace(/^\//, '').replace(/^[A-Z]{6}\+/, '');
    name = name.replace(/\s+/g, ' ').toLowerCase().trim();
    return name.replace(/[^a-z0-9._-]+/g, '_').replace(/^[_\-.]+|[_\-.]+$/g, '');
  }
  function expandFontKeys(base){
    if (!base) return [];
    const keys = new Set([base, base.replace(/-/g, '_'), base.replace(/_/g, '-')]);
    return Array.from(keys);
  }
  function pdfNameToString(name){
    if (!name) return '';
    if (typeof name === 'string') return name.replace(/^\//, '');
    if (typeof name?.decodeText === 'function') return name.decodeText().replace(/^\//, '');
    if (typeof name?.asString === 'function') return name.asString().replace(/^\//, '');
    if (typeof name?.key === 'string') return name.key.replace(/^\//, '');
    return String(name).replace(/^\//, '');
  }
  function pdfStringToText(val){
    if (!val) return '';
    if (typeof val.decodeText === 'function') return val.decodeText();
    if (typeof val.asString === 'function') return val.asString();
    if (typeof val.value === 'string') return val.value;
    return String(val);
  }
  function parseDaFontKey(daText){
    if (!daText) return '';
    const m = /\/([^\s]+)\s+[\d.]+\s+Tf/.exec(daText);
    return m ? m[1] : '';
  }
  function getFieldDefaultAppearance(field, PDFName){
    try {
      const da = field?.acroField?.dict?.lookup?.(PDFName.of('DA'));
      if (da) return pdfStringToText(da);
    } catch {}
    return '';
  }
  function resolveBaseFontName(field, fontKey, PDFName, form){
    if (!fontKey) return '';
    try {
      const dr = field?.acroField?.dict?.lookup?.(PDFName.of('DR')) || form?.acroForm?.dict?.lookup?.(PDFName.of('DR'));
      const fonts = dr?.lookup?.(PDFName.of('Font'));
      const font = fonts?.lookup?.(PDFName.of(fontKey));
      const base = font?.lookup?.(PDFName.of('BaseFont')) || font?.dict?.lookup?.(PDFName.of('BaseFont'));
      if (base) return pdfNameToString(base);
    } catch {}
    return fontKey;
  }
  async function loadFontManifest(){
    if (__fontManifest) return __fontManifest;
    const resp = await fetch(FONT_MANIFEST_URL, { credentials: 'same-origin' });
    if (!resp.ok) { __fontManifest = new Map(); return __fontManifest; }
    const data = await resp.json();
    const map = new Map();
    (data.fonts || []).forEach((f) => {
      const key = normalizeFontName(f.name || f.key || '');
      if (!key) return;
      expandFontKeys(key).forEach((k) => map.set(k, f));
    });
    __fontManifest = map;
    return map;
  }
  async function ensureFontkit(){
    if (window.fontkit || window.PDFLib?.fontkit) return window.fontkit;
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = FONTKIT_URL;
      s.onload = resolve;
      s.onerror = () => reject(new Error('fontkit load failed'));
      document.head.appendChild(s);
    });
    return window.fontkit;
  }
  function standardFontNameMap(PDFLib){
    if (!PDFLib?.StandardFonts) return {};
    return {
      'helvetica': PDFLib.StandardFonts.Helvetica,
      'helvetica-bold': PDFLib.StandardFonts.HelveticaBold,
      'helvetica-oblique': PDFLib.StandardFonts.HelveticaOblique,
      'helvetica-boldoblique': PDFLib.StandardFonts.HelveticaBoldOblique,
      'times-roman': PDFLib.StandardFonts.TimesRoman,
      'times-bold': PDFLib.StandardFonts.TimesBold,
      'times-italic': PDFLib.StandardFonts.TimesItalic,
      'times-bolditalic': PDFLib.StandardFonts.TimesBoldItalic,
      'courier': PDFLib.StandardFonts.Courier,
      'courier-bold': PDFLib.StandardFonts.CourierBold,
      'courier-oblique': PDFLib.StandardFonts.CourierOblique,
      'courier-boldoblique': PDFLib.StandardFonts.CourierBoldOblique,
      'symbol': PDFLib.StandardFonts.Symbol,
      'zapfdingbats': PDFLib.StandardFonts.ZapfDingbats,
    };
  }
  async function getEmbeddedFont(pdfDoc, fontName, manifest){
    const baseKey = normalizeFontName(fontName);
    if (!baseKey) return null;
    const lookupKeys = expandFontKeys(baseKey);
    for (const key of lookupKeys) if (__embeddedFonts.has(key)) return __embeddedFonts.get(key);
    const PDFLib = window.PDFLib;
    const standardMap = standardFontNameMap(PDFLib);
    for (const key of lookupKeys) {
      if (standardMap[key] && typeof pdfDoc.embedFont === 'function') {
        const font = await pdfDoc.embedFont(standardMap[key]);
        __embeddedFonts.set(key, font);
        return font;
      }
    }
    for (const key of lookupKeys) {
      const custom = manifest.get(key);
      if (!custom?.url || typeof pdfDoc.embedFont !== 'function') continue;
      await ensureFontkit();
      try { if (typeof pdfDoc.registerFontkit === 'function' && window.fontkit) pdfDoc.registerFontkit(window.fontkit); } catch {}
      const res = await fetch(custom.url, { credentials: 'same-origin' });
      if (!res.ok) return null;
      const bytes = await res.arrayBuffer();
      const font = await pdfDoc.embedFont(bytes);
      __embeddedFonts.set(key, font);
      return font;
    }
    return null;
  }
  async function updateFieldAppearancesWithFonts(form, pdfDoc, fallbackFont){
    const PDFLib = window.PDFLib;
    const { PDFName, PDFTextField, PDFDropdown, PDFOptionList } = PDFLib;
    const fontManifest = await loadFontManifest();
    const fields = form.getFields();
    for (const field of fields) {
      const isText = PDFTextField && field instanceof PDFTextField;
      const isDropdown = PDFDropdown && field instanceof PDFDropdown;
      const isOptionList = PDFOptionList && field instanceof PDFOptionList;
      if (!isText && !isDropdown && !isOptionList) continue;
      const da = getFieldDefaultAppearance(field, PDFName);
      const fontKey = parseDaFontKey(da);
      const base = resolveBaseFontName(field, fontKey, PDFName, form);
      let font = null;
      if (base) font = await getEmbeddedFont(pdfDoc, base, fontManifest);
      if (!font && fontKey) font = await getEmbeddedFont(pdfDoc, fontKey, fontManifest);
      if (!font) font = fallbackFont;
      try {
        if (font && typeof field.updateAppearances === 'function') field.updateAppearances(font);
        else if (typeof field.updateAppearances === 'function') field.updateAppearances();
      } catch {}
    }
  }

  function pdfArrayToNumbers(arr, PDFArray, PDFNumber){
    if (!arr) return null;
    const isPdfArray = PDFArray && arr instanceof PDFArray;
    const size = isPdfArray && typeof arr.size === 'function' ? arr.size() : null;
    const out = [];
    if (size !== null) {
      for (let i = 0; i < size; i++) {
        const obj = arr.get(i);
        if (PDFNumber && obj instanceof PDFNumber) out.push(obj.asNumber());
        else if (typeof obj?.asNumber === 'function') out.push(obj.asNumber());
        else {
          const n = Number(obj);
          if (Number.isFinite(n)) out.push(n);
        }
      }
      return out.length ? out : null;
    }
    if (Array.isArray(arr)) {
      for (const obj of arr) {
        const n = Number(obj);
        if (Number.isFinite(n)) out.push(n);
      }
      return out.length ? out : null;
    }
    return null;
  }

  function parseDaColor(da){
    const s = (da ?? '').toString();
    if (!s) return null;
    const rg = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+RG\b/);
    if (rg) return { model: 'rgb', values: rg.slice(1, 4).map(Number) };
    const rgFill = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+rg\b/);
    if (rgFill) return { model: 'rgb', values: rgFill.slice(1, 4).map(Number) };
    const g = s.match(/([\d.]+)\s+G\b/);
    if (g) return { model: 'gray', values: [Number(g[1])] };
    const gFill = s.match(/([\d.]+)\s+g\b/);
    if (gFill) return { model: 'gray', values: [Number(gFill[1])] };
    const k = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+K\b/);
    if (k) return { model: 'cmyk', values: k.slice(1, 5).map(Number) };
    const kFill = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+k\b/);
    if (kFill) return { model: 'cmyk', values: kFill.slice(1, 5).map(Number) };
    return null;
  }

  function colorOperators(color){
    if (!color || !Array.isArray(color.values)) return '';
    const vals = color.values.map(v => (Number.isFinite(v) ? String(v) : '0'));
    if (color.model === 'rgb' && vals.length >= 3) return `${vals[0]} ${vals[1]} ${vals[2]} RG`;
    if (color.model === 'gray' && vals.length >= 1) return `${vals[0]} G`;
    if (color.model === 'cmyk' && vals.length >= 4) return `${vals[0]} ${vals[1]} ${vals[2]} ${vals[3]} K`;
    return '';
  }

  function hexToRgbColor(hex){
    const m = /^#([\da-fA-F]{6})$/.exec((hex || '').trim());
    if (!m) return null;
    const raw = m[1];
    return {
      model: 'rgb',
      values: [
        parseInt(raw.slice(0, 2), 16) / 255,
        parseInt(raw.slice(2, 4), 16) / 255,
        parseInt(raw.slice(4, 6), 16) / 255,
      ],
    };
  }

  function getWidgetTextColor(widget, radioField, PDFName){
    try {
      const da = widget?.dict?.lookup?.(PDFName.of('DA'));
      if (da) {
        const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
        if (color) return color;
      }
    } catch {}
    try {
      const da = radioField?.acroField?.dict?.lookup?.(PDFName.of('DA'));
      if (da) {
        const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
        if (color) return color;
      }
    } catch {}
    return null;
  }

  function resolveRadioCrossColor(widget, radioField, PDFName){
    if (RADIO_CROSS_COLOR_MODE === 'admin') {
      return hexToRgbColor(RADIO_CROSS_COLOR_HEX) || { model: 'rgb', values: [0, 0, 0] };
    }
    return getWidgetTextColor(widget, radioField, PDFName);
  }

  function buildCrossAppearanceStream(pdfDoc, rect, color){
    const { context } = pdfDoc;
    const w = Math.max(1, rect.width || 1);
    const h = Math.max(1, rect.height || 1);
    const minDim = Math.min(w, h);
    const inset = minDim * 0.18;
    const lw = Math.min(2.5, Math.max(0.8, minDim * 0.1));
    const x1 = inset;
    const y1 = inset;
    const x2 = w - inset;
    const y2 = h - inset;
    const colorOp = colorOperators(color);
    const content = ['q', colorOp ? `${colorOp}` : '', `${lw} w`, `${x1} ${y1} m ${x2} ${y2} l`, `${x1} ${y2} m ${x2} ${y1} l`, 'S', 'Q']
      .filter(Boolean).join('\n');
    const stream = context.flateStream(content, {
      Type: 'XObject',
      Subtype: 'Form',
      BBox: [0, 0, w, h],
      Resources: {},
    });
    return context.register(stream);
  }

  function buildOffAppearanceStream(pdfDoc, rect){
    const { context } = pdfDoc;
    const w = Math.max(1, rect.width || 1);
    const h = Math.max(1, rect.height || 1);
    const stream = context.flateStream('', {
      Type: 'XObject',
      Subtype: 'Form',
      BBox: [0, 0, w, h],
      Resources: {},
    });
    return context.register(stream);
  }

  function getWidgetOnName(widget, PDFName, PDFDict){
    try {
      const ap = widget?.dict?.lookup?.(PDFName.of('AP'));
      if (ap && (!PDFDict || ap instanceof PDFDict) && ap.lookup) {
        const n = ap.lookup(PDFName.of('N'));
        if (n && (!PDFDict || n instanceof PDFDict) && typeof n.keys === 'function') {
          const keys = n.keys();
          for (const k of keys) {
            const name = pdfNameToString(k);
            if (name && name.toLowerCase() !== 'off') return name;
          }
        }
      }
    } catch {}
    return '';
  }

  function getRadioGroupValue(radioField, PDFName){
    try {
      const selected = radioField?.getSelected?.();
      if (selected) return pdfNameToString(selected);
    } catch {}
    try {
      const v = radioField?.acroField?.dict?.lookup?.(PDFName.of('V'));
      if (v) return pdfNameToString(v);
    } catch {}
    return '';
  }

  function getRadioGroupOptions(radioField, PDFName){
    try {
      const opts = radioField?.getOptions?.();
      if (Array.isArray(opts) && opts.length) return opts.map(o => pdfNameToString(o));
    } catch {}
    try {
      const opt = radioField?.acroField?.dict?.lookup?.(PDFName.of('Opt'));
      const arr = opt?.asArray?.() || opt;
      if (Array.isArray(arr)) return arr.map(o => pdfNameToString(o)).filter(Boolean);
    } catch {}
    return [];
  }

  function getWidgetAppearanceState(widget, PDFName){
    try {
      const as = widget?.dict?.lookup?.(PDFName.of('AS'));
      const name = pdfNameToString(as);
      return name && name.toLowerCase() !== 'off' ? name : '';
    } catch {}
    return '';
  }

  function applyRadioCrossAppearances(pdfDoc, form){
    const PDFLib = window.PDFLib;
    const { PDFName, PDFDict } = PDFLib;
    const fields = form.getFields();
    for (const field of fields) {
      if (!(field instanceof PDFLib.PDFRadioGroup)) continue;

      let selectedValue = getRadioGroupValue(field, PDFName);
      const widgets = field?.acroField?.getWidgets?.() || [];
      const options = getRadioGroupOptions(field, PDFName);
      if (!selectedValue) {
        for (const widget of widgets) {
          const widgetSelected = getWidgetAppearanceState(widget, PDFName);
          if (widgetSelected) { selectedValue = widgetSelected; break; }
        }
      }

      for (let i = 0; i < widgets.length; i++) {
        const widget = widgets[i];
        const rect = widget.getRectangle();
        let onName = getWidgetOnName(widget, PDFName, PDFDict);
        if (!onName && options[i]) onName = options[i];
        if (!onName && selectedValue) onName = selectedValue;
        if (!onName) continue;

        const normalizedOn = pdfNameToString(onName);
        const isSelected = selectedValue && pdfNameToString(selectedValue).toLowerCase() === normalizedOn.toLowerCase();
        const color = resolveRadioCrossColor(widget, field, PDFName);
        const onRef = buildCrossAppearanceStream(pdfDoc, rect, color);
        const offRef = buildOffAppearanceStream(pdfDoc, rect);

        const apN = pdfDoc.context.obj({ Off: offRef, [normalizedOn]: onRef });
        const apDict = pdfDoc.context.obj({ N: apN });
        widget.dict.set(PDFName.of('AP'), apDict);
        widget.dict.set(PDFName.of('AS'), PDFName.of(isSelected ? normalizedOn : 'Off'));
      }

      if (selectedValue) {
        try {
          field.acroField?.setValue?.(PDFName.of(pdfNameToString(selectedValue)));
          field.acroField?.dict?.set?.(PDFName.of('V'), PDFName.of(pdfNameToString(selectedValue)));
          field.acroField?.dict?.set?.(PDFName.of('DV'), PDFName.of(pdfNameToString(selectedValue)));
        } catch {}
      }
    }
  }

  function loadPdfLib(){
    return new Promise((resolve, reject) => {
      if (window.PDFLib) return resolve(window.PDFLib);
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
      s.onload = () => resolve(window.PDFLib);
      s.onerror = () => reject(new Error('pdf-lib konnte nicht geladen werden.'));
      document.head.appendChild(s);
    });
  }

  async function loadTemplateBytes(){
    const resp = await fetch(templateUrl, { credentials: 'same-origin' });
    if (!resp.ok) throw new Error(`Template-Download fehlgeschlagen (HTTP ${resp.status})`);
    return new Uint8Array(await resp.arrayBuffer());
  }

  function fillPdfForm(pdfDoc, values){
    const PDFLib = window.PDFLib;
    const { PDFName, PDFBool } = PDFLib || {};
    const form = pdfDoc.getForm();
    const fields = form.getFields();
    fields.forEach((field) => {
      const name = field.getName();
      if (!Object.prototype.hasOwnProperty.call(values, name)) return;
      const meta = fieldMeta?.[name] || null;
      const expectedFmt = (meta?.date_format || '').toString().trim();
      let value = String(values[name] ?? '');
      if ((meta?.field_type || '').toString().toLowerCase() === 'date') {
        value = normalizeDateIfNeeded(value, expectedFmt);
      }
      try {
        if (typeof field.setText === 'function') {
          field.setText(value);
        } else if (typeof field.check === 'function') {
          const vv = value.trim().toLowerCase();
          if (['1', 'ja', 'yes', 'true', 'x', 'on'].includes(vv)) {
            field.check();
          } else {
            if (typeof field.uncheck === 'function') field.uncheck();
          }
        } else if (typeof field.select === 'function') {
          if (value !== '') field.select(value);
        } else {
          field.setText?.(value);
        }
      } catch {
        // ignore unsupported field write errors
      }
    });

    try {
      const acro = form.acroForm;
      if (acro && acro.dict && PDFName) {
        const key = PDFName.of('NeedAppearances');
        try { acro.dict.delete(key); } catch {}
        if (PDFBool) acro.dict.set(key, PDFBool.False);
      }
    } catch {}

    return form;
  }

  (async () => {
    try {
      const PDFLib = await loadPdfLib();
      const tplBytes = await loadTemplateBytes();
      const editable = await PDFLib.PDFDocument.load(tplBytes);
      const form = fillPdfForm(editable, fieldValues);
      let defaultFont = null;
      try {
        if (!defaultFont && PDFLib?.StandardFonts && typeof editable.embedFont === 'function') {
          defaultFont = await editable.embedFont(PDFLib.StandardFonts.Helvetica);
        }
      } catch {}
      await updateFieldAppearancesWithFonts(form, editable, defaultFont || undefined);
      applyRadioCrossAppearances(editable, form);
      const renderedBytes = await editable.save();

      const pdf = await pdfjsLib.getDocument({ data: renderedBytes }).promise;
      preview.innerHTML = '';
      const width = preview.clientWidth || 900;

      for (let p = 1; p <= pdf.numPages; p++) {
        const page = await pdf.getPage(p);
        const base = page.getViewport({ scale: 1 });
        const scale = Math.min(1.6, Math.max(0.6, (width - 24) / base.width));
        const viewport = page.getViewport({ scale });

        const wrap = document.createElement('div');
        wrap.style.position = 'relative';
        wrap.style.width = `${viewport.width}px`;
        wrap.style.height = `${viewport.height}px`;
        wrap.style.marginBottom = '16px';
        wrap.style.border = '1px solid var(--border)';
        wrap.style.background = '#fff';

        const canvas = document.createElement('canvas');
        const ratio = window.devicePixelRatio || 1;
        canvas.width = viewport.width * ratio;
        canvas.height = viewport.height * ratio;
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        wrap.appendChild(canvas);

        preview.appendChild(wrap);
        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport, transform: ratio !== 1 ? [ratio,0,0,ratio,0,0] : undefined }).promise;
      }
    } catch (e) {
      preview.innerHTML = `<div class="alert danger"><strong>${String(e?.message || 'Preview failed')}</strong></div>`;
    }
  })();
</script>
<?php endif; ?>
