<?php
// teacher/entry.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$classId = (int)($_GET['class_id'] ?? 0);
$delegatedMode = ((int)($_GET['delegated'] ?? 0) === 1);
$childMode = ((int)($_GET['child'] ?? 0) === 1);
$childEditOverride = ((int)($_GET['child_edit'] ?? 0) === 1);
$meetingMode = ((int)($_GET['meeting'] ?? 0) === 1);
if ($meetingMode && $childMode) $meetingMode = false;

$jsDelegatedMode = $delegatedMode ? 1 : 0;
$jsUserId = $userId;
$jsCanDelegate = $delegatedMode ? 0 : 1;
$cfg = app_config();
$delegationCfg = $cfg['delegation'] ?? [];
$delegationShowOtherFieldsReadonly = (bool)($delegationCfg['show_other_fields_readonly'] ?? false);
$jsDelegationShowOtherFieldsReadonly = $delegationShowOtherFieldsReadonly ? 1 : 0;
$delegationNotice = $delegationShowOtherFieldsReadonly
  ? t('teacher.entry.delegation_notice_readonly')
  : t('teacher.entry.delegation_notice');

if (($u['role'] ?? '') === 'admin') {
  $st = $pdo->query("SELECT id, school_year, period_label, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name
     FROM classes c
     JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=? AND c.is_active=1
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Delegations must be accessed ONLY via inbox (separate from own classes).
$ownClassIds = array_map(fn($r) => (int)($r['id'] ?? 0), $classes);
$hasOwnClass = ($classId > 0 && in_array($classId, $ownClassIds, true));

if (($u['role'] ?? '') !== 'admin') {
  if (!$delegatedMode) {
    // Own work: allow only own classes
    if ($classId <= 0 && $classes) {
      $classId = (int)($classes[0]['id'] ?? 0);
    }
    $hasOwnClass = ($classId > 0 && in_array($classId, $ownClassIds, true));

    if ($classId > 0 && !$hasOwnClass) {
      render_teacher_header(t('teacher.entry.title'));
      ?>
        <div class=<"card">
            <div class="row-actions" style="float: right;">
              <?php if (!$delegatedMode): ?>
                  <button class="btn" type="button" id="btnDelegationsTop"><?=h(t('teacher.entry.delegate_action'))?></button>
                <?php else: ?>
                  <button class="btn" type="button" id="btnDelegationDoneTop"><?=h(t('teacher.entry.complete_delegation'))?></button>
                <?php endif; ?>
            </div>
          <h1><?=h($delegatedMode ? t('teacher.entry.heading_delegated') : t('teacher.entry.heading_fill'))?></h1>
        </div>
      <div class="card">
        <h1 style="margin-top:0;"><?=h(t('teacher.entry.delegations_separate'))?></h1>
        <p class="muted">
          <?=h(t('teacher.entry.own_classes_only'))?>
          <?=str_replace('{link}', '<a href="' . h(url('teacher/delegations.php')) . '">' . h(t('teacher.entry.delegations_inbox')) . '</a>', t('teacher.entry.delegations_inbox_hint'))?>
        </p>
      </div>
      <?php
      render_teacher_footer();
      exit;
    }
  } else {
    // Delegated work: class_id must be provided and must be accessible via delegation.
    if ($classId <= 0) {
      render_teacher_header(t('teacher.entry.delegation_title'));
      ?>
      <div class="card">
          <h1 style="margin-top:0;"><?=h($delegatedMode ? t('teacher.entry.heading_delegated') : t('teacher.entry.heading_fill'))?></h1>
        </div>
      <div class="card">
        <div class="alert danger"><strong><?=h(t('teacher.entry.no_class_selected'))?></strong></div>
      </div>
      <?php
      render_teacher_footer();
      exit;
    }

    if (!user_can_access_class($pdo, $userId, $classId)) {
      http_response_code(403);
      echo h(t('teacher.entry.forbidden'));
      exit;
    }

    // IMPORTANT: Do not show other classes here.
    $stc = $pdo->prepare("SELECT id, school_year, period_label, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
    $stc->execute([$classId]);
    $only = $stc->fetch(PDO::FETCH_ASSOC);
    $classes = $only ? [$only] : [];
  }
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

function period_label_display(?string $raw): string {
  $val = normalize_class_period_label($raw);
  return $val === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
}

if ($classId > 0 && ($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
  http_response_code(403);
  echo h(t('teacher.entry.forbidden'));
  exit;
}

function user_is_class_teacher_entry(PDO $pdo, int $userId, int $classId): bool {
  if ($userId <= 0 || $classId <= 0) return false;
  $st = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
  $st->execute([$userId, $classId]);
  return (bool)$st->fetch();
}

function finalmarks_normalize_name(string $name): string {
  $name = finalmarks_collapse_spaced_letters($name);
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  return mb_strtolower($name);
}

function finalmarks_subject_key(string $label): ?string {
  $label = trim(preg_replace('/\s+/u', ' ', $label));
  $label = mb_strtolower($label);
  $map = [
    'de' => 'de',
    'deutsch' => 'de',
    'german' => 'de',
    'englisch' => 'en',
    'english' => 'en',
    'ethik' => 'eth',
    'kunst' => 'art',
    'mathematik' => 'math',
    'mathe' => 'math',
    'sachunterricht' => 'sci',
    'musik' => 'music',
    'sachkunde' => 'sci',
    'sport' => 'pe',
    'pe' => 'pe',
  ];
  return $map[$label] ?? null;
}

function finalmarks_student_display(array $student): string {
  $first = trim((string)($student['first_name'] ?? ''));
  $last = trim((string)($student['last_name'] ?? ''));
  return trim($first . ' ' . $last);
}

function finalmarks_meta_read(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function finalmarks_option_list_id_from_meta(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function finalmarks_subject_key_from_candidates(array $candidates): ?string {
  foreach ($candidates as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate === '') continue;
    $key = finalmarks_subject_key($candidate);
    if ($key !== null) return $key;
  }
  return null;
}

function finalmarks_collapse_spaced_letters(string $value): string {
  $value = preg_replace_callback('/(?:\p{L}\s+){2,}\p{L}/u', function ($m) {
    $chunk = preg_replace('/\s+/u', ' ', $m[0]);
    return str_replace(' ', '', $chunk);
  }, $value);
  return $value ?? '';
}

function finalmarks_name_keys(string $name): array {
  $normalized = finalmarks_normalize_name($name);
  if ($normalized === '') return [];
  $keys = [$normalized];
  $noSpace = str_replace(' ', '', $normalized);
  if ($noSpace !== $normalized) $keys[] = $noSpace;
  return array_values(array_unique($keys));
}

function finalmarks_name_tokens(string $name): array {
  $name = finalmarks_collapse_spaced_letters($name);
  $name = str_replace([',', ';'], ' ', $name);
  $name = preg_replace('/([a-zäöüß])([A-ZÄÖÜ])/u', '$1 $2', $name);
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  if ($name === '') return [];
  $parts = preg_split('/\s+/u', $name) ?: [];
  $tokens = [];
  foreach ($parts as $part) {
    $part = mb_strtolower(trim($part));
    if ($part !== '') $tokens[] = $part;
  }
  return array_values(array_unique($tokens));
}

function finalmarks_tokens_match(array $pageTokens, array $studentTokens): bool {
  if (!$pageTokens || !$studentTokens) return false;
  $pageSet = array_fill_keys($pageTokens, true);
  $studentSet = array_fill_keys($studentTokens, true);
  $pageCount = count($pageSet);
  $matchCount = 0;
  foreach ($pageSet as $token => $_) {
    if (isset($studentSet[$token])) $matchCount++;
  }
  if ($pageCount === 0 || $matchCount === 0) return false;
  if ($matchCount === $pageCount) return true;
  if ($matchCount >= 2) return true;
  return false;
}

function finalmarks_subject_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, label, field_type, meta_json
     FROM template_fields
     WHERE template_id=? AND field_type IN ('grade','select','radio') AND can_teacher_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  $map = [];
  $duplicates = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $meta = finalmarks_meta_read($row['meta_json'] ?? null);
    $group = trim((string)($meta['group'] ?? ''));
    if ($group !== '' && strpos($group, '/') !== false) {
      $group = trim(explode('/', $group, 2)[0]);
    }
    $candidates = [
      (string)($row['label'] ?? ''),
      (string)($row['field_name'] ?? ''),
      $group,
    ];
    $subjectKey = finalmarks_subject_key_from_candidates($candidates);
    if ($subjectKey === null) continue;
    if (isset($map[$subjectKey])) {
      $duplicates[$subjectKey][] = (int)$row['id'];
      continue;
    }
    $map[$subjectKey] = [
      'id' => (int)$row['id'],
      'label' => trim((string)($row['label'] ?? $row['field_name'] ?? '')),
      'field_type' => (string)($row['field_type'] ?? ''),
      'meta' => $meta,
    ];
  }
  return [$map, $duplicates];
}

function finalmarks_has_grade_fields(PDO $pdo, int $templateId): bool {
  $st = $pdo->prepare(
    "SELECT 1
     FROM template_fields
     WHERE template_id=? AND field_type IN ('grade','select','radio')
     LIMIT 1"
  );
  $st->execute([$templateId]);
  return (bool)$st->fetchColumn();
}

function finalmarks_parse_blocks(array $blocks): array {
  $blocks = array_values(array_filter(array_map('strval', $blocks), fn($b) => trim($b) !== ''));
  $results = [];
  foreach ($blocks as $block) {
    $block = trim((string)$block);
    if ($block === '') continue;
    $name = '';
    $lines = preg_split('/\R/u', $block) ?: [];
    $subjects = [];
    $warnings = [];
    $unknownSubjects = [];
    $duplicateSubjects = [];
    $invalidGrades = [];
    $validGrades = ['1+','1-','1','2+','2-','2','3+','3-','3','4+','4-','4','5+','5-','5','6'];

    $normalizeHeader = function (string $line): string {
      $line = finalmarks_collapse_spaced_letters($line);
      $line = preg_replace('/E\s*n\s*d\s*n\s*o\s*t\s*e\s*n\s*v\s*o\s*n/iu', 'Endnoten von', $line);
      $line = preg_replace('/Endnotenvon/iu', 'Endnoten von', $line);
      $line = preg_replace('/\bvon(?=\p{L})/iu', 'von ', $line);
      return trim(preg_replace('/\s+/u', ' ', $line));
    };

    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $headerLine = $normalizeHeader($line);
      if (preg_match('/^Endnoten\s*von\s*(.+?)\s*$/iu', $headerLine, $m)) {
        if ($name === '') {
          $name = trim(preg_replace('/\s+/u', ' ', finalmarks_collapse_spaced_letters($m[1])));
        }
        continue;
      }
      if (preg_match('/^Endnoten\b/i', $headerLine)) continue;
      if (preg_match('/^Stand\b/i', $headerLine)) continue;
      if (preg_match('/^Fach\s+Note/i', $line)) continue;

      if (preg_match('/^(.*?)\s+(1\+|1\-|1|2\+|2\-|2|3\+|3\-|3|4\+|4\-|4|5\+|5\-|5|6)\s*$/u', $line, $m)) {
        $label = trim(preg_replace('/\s+/u', ' ', $m[1]));
        $grade = trim($m[2]);
        $key = finalmarks_subject_key($label);
        if ($key === null) {
          $unknownSubjects[] = $label;
          $warnings[] = str_replace('{label}', $label, t('teacher.entry.finalmarks.warning.unknown_subject'));
          continue;
        }
        if (isset($subjects[$key])) {
          $duplicateSubjects[] = $label;
          $warnings[] = str_replace('{label}', $label, t('teacher.entry.finalmarks.warning.duplicate_subject'));
        }
        $subjects[$key] = ['label' => $label, 'grade' => $grade];
        continue;
      }

      if (preg_match('/^(.*?)\s+(\S+)\s*$/u', $line, $m)) {
        $label = trim(preg_replace('/\s+/u', ' ', finalmarks_collapse_spaced_letters($m[1])));
        $grade = trim($m[2]);
        if (!in_array($grade, $validGrades, true)) {
          $invalidGrades[] = $label . ' ' . $grade;
          $warnings[] = str_replace(['{label}', '{grade}'], [$label, $grade], t('teacher.entry.finalmarks.warning.invalid_grade'));
        }
      }
    }

    if (!$subjects) {
      $warnings[] = t('teacher.entry.finalmarks.warning.no_grades');
    }

    $results[] = [
      'name' => $name,
      'subjects' => $subjects,
      'warnings' => $warnings,
      'unknown_subjects' => $unknownSubjects,
      'duplicate_subjects' => $duplicateSubjects,
      'invalid_grades' => $invalidGrades,
    ];
  }

  return $results;
}

if ($childMode && ($u['role'] ?? '') !== 'admin') {
  $isClassTeacher = $classId > 0 && user_is_class_teacher_entry($pdo, $userId, $classId);
  if (!$isClassTeacher) {
    http_response_code(403);
    render_teacher_header(t('teacher.child_entry.title'));
    ?>
    <div class="card">
      <div class="alert danger"><strong><?=h(t('teacher.entry.forbidden'))?></strong></div>
    </div>
    <?php
    render_teacher_footer();
    exit;
  }
}

$isTeacherRole = (($u['role'] ?? '') === 'teacher');
$finalmarksPreview = null;
$finalmarksErrors = [];
$finalmarksSuccess = null;
$finalmarksSummary = null;
$finalmarksToken = '';
$finalmarksFormSchoolYear = '';
$finalmarksFormPeriodLabel = '';
$finalmarksHasImportable = false;
$currentClass = null;
$finalmarksTemplateId = 0;
$finalmarksHasSubjects = false;
$finalmarksYearPeriodOptions = [];
foreach ($classes as $c) {
  if ((int)($c['id'] ?? 0) === $classId) {
    $currentClass = $c;
    break;
  }
}
$classPeriodLabel = normalize_class_period_label($currentClass ? ($currentClass['period_label'] ?? 'Standard') : 'Standard');
$finalmarksFormSchoolYear = trim((string)($currentClass['school_year'] ?? ''));
$finalmarksFormPeriodLabel = $classPeriodLabel;
if ($classId > 0) {
  $stClass = $pdo->prepare("SELECT template_id FROM classes WHERE id=? LIMIT 1");
  $stClass->execute([$classId]);
  $finalmarksTemplateId = (int)($stClass->fetchColumn() ?: 0);
  if ($finalmarksTemplateId > 0) {
    $finalmarksHasSubjects = finalmarks_has_grade_fields($pdo, $finalmarksTemplateId);
  }

  $addPeriodOption = function(string $schoolYear, string $periodLabel) use (&$finalmarksYearPeriodOptions): void {
    $schoolYear = trim($schoolYear);
    if ($schoolYear === '') return;
    $periodLabel = normalize_class_period_label($periodLabel);
    $key = $schoolYear . '|' . $periodLabel;
    if (!isset($finalmarksYearPeriodOptions[$key])) {
      $finalmarksYearPeriodOptions[$key] = [
        'school_year' => $schoolYear,
        'period_label' => $periodLabel,
      ];
    }
  };

  $stPeriods = $pdo->prepare(
    "SELECT DISTINCT ri.school_year, ri.period_label
     FROM report_instances ri
     JOIN students s ON s.id=ri.student_id
     WHERE s.class_id=?
     ORDER BY ri.school_year DESC, ri.period_label DESC"
  );
  $stPeriods->execute([$classId]);
  foreach ($stPeriods->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $addPeriodOption((string)($row['school_year'] ?? ''), (string)($row['period_label'] ?? 'Standard'));
  }
  $addPeriodOption($finalmarksFormSchoolYear, $finalmarksFormPeriodLabel);
  if ($finalmarksFormSchoolYear === '' && $finalmarksYearPeriodOptions) {
    $first = reset($finalmarksYearPeriodOptions);
    $finalmarksFormSchoolYear = (string)($first['school_year'] ?? '');
    $finalmarksFormPeriodLabel = normalize_class_period_label((string)($first['period_label'] ?? $finalmarksFormPeriodLabel));
  }
  if ($finalmarksFormSchoolYear === '') {
    $finalmarksFormSchoolYear = trim((string)(app_config()['app']['default_school_year'] ?? ''));
    $finalmarksFormPeriodLabel = $classPeriodLabel;
    $addPeriodOption($finalmarksFormSchoolYear, $finalmarksFormPeriodLabel);
  }
}

if ($isTeacherRole && !$childMode && (string)($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['finalmarks_action'])) {
  if ($delegatedMode) {
    $finalmarksErrors[] = t('teacher.entry.finalmarks.error.delegated_no_import');
  } else {
    $action = (string)$_POST['finalmarks_action'];
    $selectedPeriodKey = (string)($_POST['finalmarks_period'] ?? '');
    if ($selectedPeriodKey !== '' && isset($finalmarksYearPeriodOptions[$selectedPeriodKey])) {
      $finalmarksFormSchoolYear = (string)$finalmarksYearPeriodOptions[$selectedPeriodKey]['school_year'];
      $finalmarksFormPeriodLabel = (string)$finalmarksYearPeriodOptions[$selectedPeriodKey]['period_label'];
    } else {
      $finalmarksErrors[] = t('teacher.entry.finalmarks.error.select_period');
    }

    if ($classId <= 0) {
      $finalmarksErrors[] = t('teacher.entry.finalmarks.error.select_class_first');
    } elseif ($action === 'preview') {
    $blocksJson = (string)($_POST['finalmarks_blocks'] ?? '');
    $blocks = $blocksJson !== '' ? json_decode($blocksJson, true) : [];
    if (!is_array($blocks) || !$blocks) {
      $finalmarksErrors[] = t('teacher.entry.finalmarks.error.pdf_parse_failed');
    } else {
      $token = bin2hex(random_bytes(16));
      $parsed = finalmarks_parse_blocks($blocks);
      $stClass = $pdo->prepare("SELECT template_id FROM classes WHERE id=? LIMIT 1");
      $stClass->execute([$classId]);
      $templateId = (int)($stClass->fetchColumn() ?: 0);
      if ($templateId <= 0) {
        $finalmarksErrors[] = t('teacher.entry.finalmarks.error.template_missing');
      }
      if (!$finalmarksErrors) {
        $_SESSION['finalmarks_import'][$token] = [
          'class_id' => $classId,
          'school_year' => $finalmarksFormSchoolYear,
          'period_label' => $finalmarksFormPeriodLabel,
          'template_id' => $templateId,
          'file_hash' => (string)($_POST['finalmarks_file_hash'] ?? ''),
          'file_name' => (string)($_POST['finalmarks_file_name'] ?? ''),
          'blocks' => $blocks,
          'created_at' => time(),
        ];

            [$subjectFields] = finalmarks_subject_fields($pdo, $templateId);

            $st = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE class_id=? AND is_active=1 ORDER BY last_name, first_name");
            $st->execute([$classId]);
            $classStudents = $st->fetchAll(PDO::FETCH_ASSOC);
            $classMap = [];
            foreach ($classStudents as $student) {
              $studentName = finalmarks_student_display($student);
              foreach (finalmarks_name_keys($studentName) as $nameKey) {
                $classMap[$nameKey][] = $student;
              }
            }
            $globalMap = null;
            $needsGlobal = false;
            foreach ($parsed as $page) {
              $pageKeys = finalmarks_name_keys((string)($page['name'] ?? ''));
              $found = false;
              foreach ($pageKeys as $nameKey) {
                if (isset($classMap[$nameKey])) {
                  $found = true;
                  break;
                }
              }
              if (!$found) {
                $needsGlobal = true;
                break;
              }
            }
            if ($needsGlobal) {
              $stGlobal = $pdo->query("SELECT id, class_id, first_name, last_name FROM students WHERE is_active=1 ORDER BY last_name, first_name");
              $allStudents = $stGlobal->fetchAll(PDO::FETCH_ASSOC);
              $globalMap = [];
              foreach ($allStudents as $student) {
                $studentName = finalmarks_student_display($student);
                foreach (finalmarks_name_keys($studentName) as $nameKey) {
                  $globalMap[$nameKey][] = $student;
                }
              }
            }

        $statusCounts = [
          'FOUND_IN_CLASS' => 0,
          'FOUND_NOT_IN_CLASS' => 0,
          'NOT_FOUND' => 0,
          'AMBIGUOUS' => 0,
        ];
        $importableNotes = 0;
        $ignoredNotes = 0;
        $reportByStudent = [];
        if ($classStudents) {
          $studentIds = array_map(fn($s) => (int)$s['id'], $classStudents);
          $in = implode(',', array_fill(0, count($studentIds), '?'));
          $params = array_merge([$templateId, $finalmarksFormSchoolYear, $finalmarksFormPeriodLabel], $studentIds);
          $stRep = $pdo->prepare(
            "SELECT id, student_id
             FROM report_instances
             WHERE template_id=? AND school_year=? AND period_label=? AND student_id IN ($in)"
          );
          $stRep->execute($params);
          foreach ($stRep->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reportByStudent[(int)$r['student_id']] = (int)$r['id'];
          }
        }
        $existingValues = [];
        if ($reportByStudent && $subjectFields) {
          $reportIds = array_values(array_unique(array_values($reportByStudent)));
          $fieldIds = array_values(array_unique(array_map(fn($f) => (int)$f['id'], $subjectFields)));
          $inReports = implode(',', array_fill(0, count($reportIds), '?'));
          $inFields = implode(',', array_fill(0, count($fieldIds), '?'));
          $params = array_merge($reportIds, $fieldIds);
          $stVals = $pdo->prepare(
            "SELECT report_instance_id, template_field_id, value_text
             FROM field_values
             WHERE report_instance_id IN ($inReports)
               AND template_field_id IN ($inFields)
               AND source='teacher'"
          );
          $stVals->execute($params);
          foreach ($stVals->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['report_instance_id'];
            $fid = (int)$row['template_field_id'];
            if (!isset($existingValues[$rid])) $existingValues[$rid] = [];
            $existingValues[$rid][$fid] = (string)($row['value_text'] ?? '');
          }
        }
        $finalmarksPreview = [];
        $matchedStudentIds = [];

        foreach ($parsed as $idx => $page) {
          $name = (string)($page['name'] ?? '');
          $nameKeys = finalmarks_name_keys($name);
          $status = 'NOT_FOUND';
          $matchedStudent = null;
          $matches = [];
          foreach ($nameKeys as $nameKey) {
            if (isset($classMap[$nameKey])) {
              $matches = $classMap[$nameKey];
              break;
            }
          }
          if ($matches) {
            if (count($matches) === 1) {
              $status = 'FOUND_IN_CLASS';
              $matchedStudent = $matches[0];
            } else {
              $status = 'AMBIGUOUS';
            }
          } else {
            $partialMatches = [];
            $pageTokens = finalmarks_name_tokens($name);
            if ($pageTokens) {
              foreach ($classStudents as $student) {
                $studentName = finalmarks_student_display($student);
                $studentTokens = finalmarks_name_tokens($studentName);
                if (finalmarks_tokens_match($pageTokens, $studentTokens)) {
                  $partialMatches[] = $student;
                }
              }
            }
            if (count($partialMatches) === 1) {
              $status = 'FOUND_IN_CLASS';
              $matchedStudent = $partialMatches[0];
            } elseif ($globalMap !== null) {
              $matches = [];
              foreach ($nameKeys as $nameKey) {
                if (isset($globalMap[$nameKey])) {
                  $matches = $globalMap[$nameKey];
                  break;
                }
              }
              if (count($matches) === 1) {
                $status = 'FOUND_NOT_IN_CLASS';
                $matchedStudent = $matches[0];
              } elseif (count($partialMatches) > 1) {
                $status = 'AMBIGUOUS';
              }
            } elseif (count($partialMatches) > 1) {
              $status = 'AMBIGUOUS';
            }
          }

          $statusCounts[$status]++;
          $subjects = (array)($page['subjects'] ?? []);
          $warnings = (array)($page['warnings'] ?? []);
          $knownSubjects = [];
          $reportId = null;
          if ($status === 'FOUND_IN_CLASS' && $matchedStudent) {
            $reportId = $reportByStudent[(int)($matchedStudent['id'] ?? 0)] ?? null;
            if (!$reportId) {
              $warnings[] = t('teacher.entry.finalmarks.error.report_missing');
            }
            $matchedStudentIds[] = (int)($matchedStudent['id'] ?? 0);
          }
          foreach ($subjects as $key => $entry) {
            $grade = (string)($entry['grade'] ?? '');
            $field = $subjectFields[$key] ?? null;
            if ($field === null) {
              $warnings[] = str_replace('{subject}', $key, t('teacher.entry.finalmarks.error.subject_missing'));
              $ignoredNotes++;
            } else {
              $listId = finalmarks_option_list_id_from_meta($field['meta']);
              if ($listId > 0) {
                $stOpt = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
                $stOpt->execute([$listId, $grade]);
                $optId = (int)($stOpt->fetchColumn() ?: 0);
                if ($optId <= 0) {
                  $warnings[] = str_replace(['{subject}', '{grade}'], [$key, $grade], t('teacher.entry.finalmarks.error.grade_not_in_list'));
                  $ignoredNotes++;
                } else {
                  if ($status === 'FOUND_IN_CLASS' && $reportId) {
                    $importableNotes++;
                  }
                }
              } else {
                if ($status === 'FOUND_IN_CLASS' && $reportId) {
                  $importableNotes++;
                }
              }
            }
            $compareStatus = null;
            $existingValue = null;
            if ($reportId && $field) {
              $existingValue = $existingValues[$reportId][(int)$field['id']] ?? null;
              if ($existingValue === null || $existingValue === '') {
                $compareStatus = 'new';
              } elseif (trim($existingValue) === trim($grade)) {
                $compareStatus = 'match';
              } else {
                $compareStatus = 'diff';
              }
            }
            $knownSubjects[$key] = [
              'grade' => $grade,
              'status' => $compareStatus,
              'existing' => $existingValue,
            ];
          }
          $ignoredNotes += count((array)($page['unknown_subjects'] ?? []));
          $ignoredNotes += count((array)($page['invalid_grades'] ?? []));
          $finalmarksPreview[] = [
            'page_index' => $idx,
            'name' => $name,
            'status' => $status,
            'student' => $matchedStudent,
            'subjects' => $knownSubjects,
            'warnings' => $warnings,
            'has_grades' => count($subjects) > 0,
            'report_id' => $reportId,
          ];
        }

        $remainingStudents = [];
        if ($classStudents) {
          $matchedStudentIds = array_values(array_unique($matchedStudentIds));
          foreach ($classStudents as $student) {
            $sid = (int)($student['id'] ?? 0);
            if ($sid > 0 && !in_array($sid, $matchedStudentIds, true)) {
              $remainingStudents[] = $student;
            }
          }
        }

        $finalmarksSummary = [
          'pages' => count($parsed),
          'status_counts' => $statusCounts,
          'importable_notes' => $importableNotes,
          'ignored_notes' => $ignoredNotes,
        ];
        foreach ($finalmarksPreview as $row) {
          if ($row['status'] === 'FOUND_IN_CLASS' && $row['has_grades'] && $row['report_id']) {
            $finalmarksHasImportable = true;
            break;
          }
        }
        $finalmarksToken = $token;
      }
    }
  } elseif ($action === 'commit') {
    $token = trim((string)($_POST['finalmarks_token'] ?? ''));
    $sessionData = $_SESSION['finalmarks_import'][$token] ?? null;
    if (!$sessionData || !is_array($sessionData)) {
      $finalmarksErrors[] = t('teacher.entry.finalmarks.error.token_invalid');
    } elseif ((int)($sessionData['class_id'] ?? 0) !== $classId) {
      $finalmarksErrors[] = t('teacher.entry.finalmarks.error.class_context_mismatch');
    }

    if (!$finalmarksErrors) {
      $finalmarksFormSchoolYear = trim((string)($sessionData['school_year'] ?? $finalmarksFormSchoolYear));
      $finalmarksFormPeriodLabel = normalize_class_period_label((string)($sessionData['period_label'] ?? $finalmarksFormPeriodLabel));
      $templateId = (int)($sessionData['template_id'] ?? 0);
      if ($templateId <= 0) {
        $finalmarksErrors[] = t('teacher.entry.finalmarks.error.template_missing_short');
      }
      $blocks = (array)($sessionData['blocks'] ?? []);
      if (!$blocks) {
        $finalmarksErrors[] = t('teacher.entry.finalmarks.error.pdf_data_missing');
      } else {
        $parsed = finalmarks_parse_blocks($blocks);
      }
    }

    if (!$finalmarksErrors) {
      [$subjectFields] = finalmarks_subject_fields($pdo, $templateId);
      $st = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE class_id=? AND is_active=1 ORDER BY last_name, first_name");
      $st->execute([$classId]);
      $classStudents = $st->fetchAll(PDO::FETCH_ASSOC);
      $classMap = [];
      $classMapById = [];
      foreach ($classStudents as $student) {
        $studentName = finalmarks_student_display($student);
        $sid = (int)($student['id'] ?? 0);
        if ($sid > 0) $classMapById[$sid] = $student;
        foreach (finalmarks_name_keys($studentName) as $nameKey) {
          $classMap[$nameKey][] = $student;
        }
      }
      $selectedIds = array_map('intval', (array)($_POST['finalmarks_import_ids'] ?? []));
      $hasSelection = array_key_exists('finalmarks_import_ids_present', $_POST);
      $manualMap = $_POST['finalmarks_manual_map'] ?? [];
      if (!is_array($manualMap)) $manualMap = [];

      $rowsToInsert = [];
      $skippedStudents = 0;
      $skippedNotes = 0;
      $reportByStudent = [];
      if ($classStudents) {
        $studentIds = array_map(fn($s) => (int)$s['id'], $classStudents);
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $params = array_merge([$templateId, $finalmarksFormSchoolYear, $finalmarksFormPeriodLabel], $studentIds);
        $stRep = $pdo->prepare(
          "SELECT id, student_id
           FROM report_instances
           WHERE template_id=? AND school_year=? AND period_label=? AND student_id IN ($in)"
        );
        $stRep->execute($params);
        foreach ($stRep->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $reportByStudent[(int)$r['student_id']] = (int)$r['id'];
        }
      }
      $usedManualIds = [];
      foreach ($parsed as $idx => $page) {
        $pageKeys = finalmarks_name_keys((string)($page['name'] ?? ''));
        $matches = [];
        foreach ($pageKeys as $nameKey) {
          if (isset($classMap[$nameKey])) {
            $matches = $classMap[$nameKey];
            break;
          }
        }
        $student = null;
        if ($matches && count($matches) === 1) {
          $student = $matches[0];
        } else {
          $partialMatches = [];
          $pageTokens = finalmarks_name_tokens((string)($page['name'] ?? ''));
          if ($pageTokens) {
            foreach ($classStudents as $cand) {
              $studentTokens = finalmarks_name_tokens(finalmarks_student_display($cand));
              if (finalmarks_tokens_match($pageTokens, $studentTokens)) {
                $partialMatches[] = $cand;
              }
            }
          }
          if (count($partialMatches) === 1) {
            $student = $partialMatches[0];
          }
        }
        if (!$student) {
          $manualId = (int)($manualMap[(string)$idx] ?? 0);
          if ($manualId > 0 && isset($classMapById[$manualId]) && !in_array($manualId, $usedManualIds, true)) {
            $student = $classMapById[$manualId];
            $usedManualIds[] = $manualId;
          }
        }
        if (!$student) {
          $skippedStudents++;
          continue;
        }
        $studentId = (int)($student['id'] ?? 0);
        if ($hasSelection && !in_array($studentId, $selectedIds, true)) {
          $skippedStudents++;
          continue;
        }
        $reportId = $reportByStudent[$studentId] ?? 0;
        if ($reportId <= 0) {
          $skippedStudents++;
          continue;
        }
        $subjects = (array)($page['subjects'] ?? []);
        if (!$subjects) {
          $skippedStudents++;
          continue;
        }
        foreach ($subjects as $key => $entry) {
          $grade = (string)($entry['grade'] ?? '');
          if ($grade === '') {
            $skippedNotes++;
            continue;
          }
          $field = $subjectFields[$key] ?? null;
          if ($field === null) {
            $skippedNotes++;
            continue;
          }
          $listId = finalmarks_option_list_id_from_meta($field['meta']);
          $valueJson = null;
          if ($listId > 0) {
            $stOpt = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
            $stOpt->execute([$listId, $grade]);
            $optId = (int)($stOpt->fetchColumn() ?: 0);
            if ($optId <= 0) {
              $skippedNotes++;
              continue;
            }
            $valueJson = json_encode(['option_item_id' => $optId], JSON_UNESCAPED_UNICODE);
          }
          $rowsToInsert[] = [
            'report_instance_id' => $reportId,
            'template_field_id' => (int)$field['id'],
            'value_text' => $grade,
            'value_json' => $valueJson,
          ];
        }
      }

      if (!$rowsToInsert) {
        $finalmarksErrors[] = t('teacher.entry.finalmarks.error.no_importable_grades');
      } else {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
          "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_at)
           VALUES (?, ?, ?, ?, 'teacher', ?, NOW())
           ON DUPLICATE KEY UPDATE
             value_text=VALUES(value_text),
             value_json=VALUES(value_json),
             source='teacher',
             updated_by_user_id=VALUES(updated_by_user_id),
             updated_at=NOW()"
        );
        $inserted = 0;
        $updated = 0;
        foreach ($rowsToInsert as $row) {
          $stmt->execute([
            $row['report_instance_id'],
            $row['template_field_id'],
            $row['value_text'],
            $row['value_json'],
            $userId,
          ]);
          $affected = $stmt->rowCount();
          if ($affected === 1) {
            $inserted++;
          } elseif ($affected === 2) {
            $updated++;
          }
        }
        $pdo->commit();

        $finalmarksSuccess = 'Endnoten importiert.';
        $finalmarksSummary = [
          'inserted' => $inserted,
          'updated' => $updated,
          'skipped_students' => $skippedStudents,
          'skipped_notes' => $skippedNotes,
          'rows' => count($rowsToInsert),
        ];
        if (isset($_SESSION['finalmarks_import'][$token])) {
          unset($_SESSION['finalmarks_import'][$token]);
        }
        // Token invalidieren
      }
    }
    }
  }
}

$pageTitle = $childMode
  ? t('teacher.child_entry.title')
  : t('teacher.entry.title');

$meetingParams = ['meeting' => 1];
if ($classId > 0) $meetingParams['class_id'] = $classId;
if ($delegatedMode) $meetingParams['delegated'] = 1;
$meetingUrl = url('teacher/entry.php' . ($meetingParams ? ('?' . http_build_query($meetingParams)) : ''));

$meetingExitParams = [];
if ($classId > 0) $meetingExitParams['class_id'] = $classId;
if ($delegatedMode) $meetingExitParams['delegated'] = 1;
$meetingExitUrl = url('teacher/entry.php' . ($meetingExitParams ? ('?' . http_build_query($meetingExitParams)) : ''));

render_teacher_header($pageTitle);
?>

<div class="card" id="meetingHeaderCard">
  <div class="row-actions" style="float: right;">
    <?php if ($meetingMode): ?>
      <button
        class="btn secondary"
        type="button"
        id="btnMeetingFullscreen"
        data-label-enter="<?=h(t('teacher.entry.meeting_fullscreen'))?>"
        data-label-exit="<?=h(t('teacher.entry.meeting_fullscreen_exit'))?>"
      >
        <?=h(t('teacher.entry.meeting_fullscreen'))?>
      </button>
      <a class="btn" id="btnMeetingExit" href="<?=h($meetingExitUrl)?>">
        <?=h(t('teacher.entry.meeting_exit'))?>
      </a>
    <?php else: ?>
      <?php if (!$delegatedMode): ?>
        <button class="btn" type="button" id="btnDelegationsTop"><?=h(t('teacher.entry.delegate_action'))?></button>
      <?php else: ?>
        <button class="btn" type="button" id="btnDelegationDoneTop"><?=h(t('teacher.entry.complete_delegation'))?></button>
      <?php endif; ?>
      <?php if (!$delegatedMode): ?>
        <?php if ($childMode): ?>
            <a class="btn secondary" data-switch-view="teacher" href="<?=h(url('teacher/entry.php' . ($classId > 0 ? ('?class_id=' . (int)$classId) : '')))?>">
              <?=h(t('teacher.child_entry.to_teacher'))?>
            </a>
          <?php else: ?>
            <a class="btn secondary" data-switch-view="child" href="<?=h(url('teacher/child_entry.php' . ($classId > 0 ? ('?class_id=' . (int)$classId) : '')))?>">
              <?=h(t('teacher.child_entry.to_child'))?>
            </a>
          <?php endif; ?>
          <?php if (!$childMode): ?>
            <a class="btn secondary" id="btnMeetingView" href="<?=h($meetingUrl)?>">
              <?=h(t('teacher.entry.meeting_view'))?>
            </a>
          <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <h1>
    <?=h($meetingMode ? t('teacher.entry.meeting_heading') : ($childMode ? t('teacher.child_entry.heading') : ($delegatedMode ? t('teacher.entry.heading_delegated') : t('teacher.entry.heading_fill'))))?>
  </h1>
  <?php if ($meetingMode): ?>
    <div class="muted" style="margin-top:6px;"><?=h(t('teacher.entry.meeting_hint'))?></div>
  <?php endif; ?>
</div>

<?php if ($meetingMode): ?>
  <button
    class="btn secondary"
    type="button"
    id="btnMeetingFullscreenFloat"
    data-label-enter="<?=h(t('teacher.entry.meeting_fullscreen'))?>"
    data-label-exit="<?=h(t('teacher.entry.meeting_fullscreen_exit'))?>"
    aria-label="<?=h(t('teacher.entry.meeting_fullscreen'))?>"
  >
    <?=h(t('teacher.entry.meeting_fullscreen'))?>
  </button>
<?php endif; ?>

<?php if ($childMode): ?>
    <div class="alert danger">
      <strong><?=h(t('teacher.child_entry.warning_title'))?></strong>
      <?=h(t('teacher.child_entry.warning_text'))?>
    </div>
<?php endif; ?>

<div class="card" id="classSelectCard">
  <?php if (!$meetingMode): ?>
    <p class="muted" style="margin-top:-6px;">
      <?=h(t('teacher.entry.tips'))?> <strong>Tab</strong> <?=h(t('teacher.entry.tip_next'))?> · <strong>Shift+Tab</strong> <?=h(t('teacher.entry.tip_prev'))?> ·
      <?php if ($childMode): ?>
        <strong>Alt+M</strong> <?=h(t('teacher.entry.tip_switch_view'))?>
      <?php else: ?>
        <strong>Alt+S</strong> <?=h(t('teacher.entry.tip_toggle_child'))?> · <strong>Alt+M</strong> <?=h(t('teacher.entry.tip_switch_view'))?>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($delegatedMode): ?>
    <div class="alert" style="margin-top:10px;"><strong><?=h(t('teacher.entry.delegation'))?></strong> <?=h($delegationNotice)?></div>
    <?php if ($delegationShowOtherFieldsReadonly): ?>
      <label class="toggle-switch" style="margin-top:8px;">
        <input type="checkbox" id="toggleDelegationOtherFields" checked>
        <span class="toggle-slider" aria-hidden="true"></span>
        <span class="toggle-label"><?=h(t('teacher.entry.delegation_show_other_fields'))?></span>
      </label>
      <div class="muted" style="font-size:12px; margin-top:4px;"><?=h(t('teacher.entry.delegation_show_other_fields_hint'))?></div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div style="min-width:260px;">
      <label class="label"><?=h(t('teacher.entry.class_label'))?></label>
      <select class="input" id="classSelect" style="width:100%;" <?= $delegatedMode ? 'disabled' : '' ?>>
        <?php foreach ($classes as $c): $id = (int)$c['id']; ?>
          <option value="<?=h((string)$id)?>" <?= $id===$classId ? 'selected' : '' ?>>
            <?=h((string)$c['school_year'] . ' · ' . period_label_display($c['period_label'] ?? 'Standard') . ' · ' . class_display($c))?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <span class="pill-mini" id="savePill" style="display:none;"><span class="spin"></span> <?=h(t('teacher.entry.save_status_saving'))?></span>
      <div class="save-status" id="saveStatus" aria-live="polite" style="display:none;"></div>
    </div>
  </div>
</div>

<?php if ($isTeacherRole && !$childMode && !$delegatedMode && !$meetingMode && $finalmarksHasSubjects): ?>
  <div class="card">
    <h2 style="margin-top:0;"><?=h(t('teacher.entry.finalmarks.title'))?></h2>
    <?php if ($classId <= 0): ?>
      <div class="alert"><?=h(t('teacher.entry.finalmarks.error.select_class_first'))?></div>
    <?php else: ?>
      <?php if ($finalmarksErrors): ?>
        <div class="alert danger">
          <strong><?=h(t('teacher.entry.finalmarks.error_prefix'))?></strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach ($finalmarksErrors as $err): ?>
              <li><?=h($err)?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($finalmarksSuccess): ?>
        <div class="alert success">
          <strong><?=h($finalmarksSuccess)?></strong>
          <?php if ($finalmarksSummary): ?>
            <div class="muted" style="margin-top:6px;">
              <?=h(t('teacher.entry.finalmarks.summary.entries'))?>: <?=h((string)($finalmarksSummary['rows'] ?? 0))?> ·
              <?=h(t('teacher.entry.finalmarks.summary.inserted'))?>: <?=h((string)($finalmarksSummary['inserted'] ?? 0))?> ·
              <?=h(t('teacher.entry.finalmarks.summary.updated'))?>: <?=h((string)($finalmarksSummary['updated'] ?? 0))?> ·
              <?=h(t('teacher.entry.finalmarks.summary.skipped_students'))?>: <?=h((string)($finalmarksSummary['skipped_students'] ?? 0))?> ·
              <?=h(t('teacher.entry.finalmarks.summary.skipped_notes'))?>: <?=h((string)($finalmarksSummary['skipped_notes'] ?? 0))?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:10px;" id="finalmarksForm">
        <input type="hidden" name="finalmarks_action" value="preview">
        <input type="hidden" name="finalmarks_blocks" id="finalmarksBlocks" value="">
        <input type="hidden" name="finalmarks_file_hash" id="finalmarksFileHash" value="">
        <input type="hidden" name="finalmarks_file_name" id="finalmarksFileName" value="">
        <div class="row" style="gap:10px;align-items: flex-start;flex-wrap:wrap;display: inline-flex;">
          <div>
            <label class="label" for="finalmarksPdf"><?=h(t('teacher.entry.finalmarks.pdf_label'))?></label>
            <input class="input" type="file" id="finalmarksPdf" name="finalmarks_pdf" accept="application/pdf" required>
          </div>
          <div>
            <label class="label" for="finalmarksYear"><?=h(t('teacher.entry.finalmarks.school_year_label'))?></label>
            <select class="input" id="finalmarksYear" name="finalmarks_period" required>
              <?php foreach ($finalmarksYearPeriodOptions as $key => $opt): ?>
                <option value="<?=h($key)?>" <?= ($opt['school_year'] === $finalmarksFormSchoolYear && $opt['period_label'] === $finalmarksFormPeriodLabel) ? 'selected' : '' ?>>
                  <?=h($opt['school_year'] . ' · ' . period_label_display($opt['period_label'] ?? 'Standard'))?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
            <div>
                
                <label class="label" for="finalmarksSubmitbtn">&nbsp;</label>
          <button class="btn" type="submit" id="finalmarksSubmitbtn"><?=h(t('teacher.entry.finalmarks.read_button'))?></button>
            </div>
        </div>
      </form>

      <?php if ($finalmarksPreview !== null && $finalmarksSummary): ?>
        <div style="margin-top:16px;">
          <h3 style="margin:0 0 8px;"><?=h(t('teacher.entry.finalmarks.review_title'))?></h3>
          <form method="post">
            <input type="hidden" name="finalmarks_action" value="commit">
            <input type="hidden" name="finalmarks_token" value="<?=h($finalmarksToken)?>">
            <input type="hidden" name="finalmarks_import_ids_present" value="1">
            <div style="overflow:auto; border:1px solid var(--border); border-radius:12px;">
              <table class="table" style="margin:0;">
                <thead>
                  <tr>
                    <th><?=h(t('teacher.entry.finalmarks.table.pdf_name'))?></th>
                    <th><?=h(t('teacher.entry.finalmarks.table.status'))?></th>
                    <th><?=h(t('teacher.entry.finalmarks.table.student'))?></th>
                    <th><?=h(t('teacher.entry.finalmarks.table.grades'))?></th>
                    <th><?=h(t('teacher.entry.finalmarks.table.warnings'))?></th>
                    <th><?=h(t('teacher.entry.finalmarks.table.import'))?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($finalmarksPreview as $row): ?>
                    <?php
                      $student = $row['student'];
                      $status = (string)$row['status'];
                      $canImport = ($status === 'FOUND_IN_CLASS' && $row['has_grades'] && $row['report_id']);
                    ?>
                    <tr>
                      <td><?=h($row['name'] !== '' ? $row['name'] : '—')?></td>
                      <td><span class="pill-mini"><?=h($status)?></span></td>
                      <td>
                        <?php if ($status !== 'FOUND_IN_CLASS'): ?>
                          <select class="input finalmarks-manual-select" name="finalmarks_manual_map[<?=h((string)$row['page_index'])?>]" data-row="<?=h((string)$row['page_index'])?>">
                            <option value=""><?=h(t('teacher.entry.finalmarks.table.student_placeholder'))?></option>
                            <?php foreach ($remainingStudents ?? [] as $cand): ?>
                              <option value="<?=h((string)($cand['id'] ?? ''))?>"><?=h(finalmarks_student_display($cand))?></option>
                            <?php endforeach; ?>
                          </select>
                        <?php elseif ($student): ?>
                          <?=h(finalmarks_student_display($student))?> (ID <?=h((string)($student['id'] ?? ''))?>)
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td>
                      <?php if ($row['subjects']): ?>
                        <?php foreach ($row['subjects'] as $key => $subject): ?>
                          <?php
                            $grade = is_array($subject) ? (string)($subject['grade'] ?? '') : (string)$subject;
                            $gradestatus = is_array($subject) ? (string)($subject['status'] ?? '') : '';
                            $existing = is_array($subject) ? (string)($subject['existing'] ?? '') : '';
                            $style = '';
                            if ($gradestatus === 'match') $style = 'background: rgba(46, 125, 50, 0.15); color: #1b5e20;';
                            elseif ($gradestatus === 'diff') $style = 'background: rgba(245, 124, 0, 0.18); color: #e65100;';
                            elseif ($gradestatus === 'new') $style = 'background: rgba(30, 136, 229, 0.15); color: #0d47a1;';
                          ?>
                          <span class="pill-mini" style="margin-right:4px; <?=h($style)?>" title="<?=h($existing !== '' ? str_replace('{grade}', $existing, t('teacher.entry.finalmarks.table.existing')) : t('teacher.entry.finalmarks.table.new'))?>"><?=h($key)?>:<?=h($grade)?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($row['warnings']): ?>
                          <ul style="margin:0 0 0 16px;">
                            <?php foreach ($row['warnings'] as $warning): ?>
                              <li><?=h($warning)?></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($status === 'FOUND_IN_CLASS'): ?>
                          <input type="checkbox" name="finalmarks_import_ids[]" value="<?=h((string)($student['id'] ?? ''))?>" <?= $canImport ? 'checked' : 'disabled' ?>>
                        <?php else: ?>
                          <input type="checkbox" class="finalmarks-import-toggle" name="finalmarks_import_ids[]" value="" disabled>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="row" style="gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px;">
              <div class="muted">
                <?=h(t('teacher.entry.finalmarks.summary.pages'))?>: <?=h((string)($finalmarksSummary['pages'] ?? 0))?> ·
                <?=h(t('teacher.entry.finalmarks.summary.status_found_in_class'))?>: <?=h((string)($finalmarksSummary['status_counts']['FOUND_IN_CLASS'] ?? 0))?> ·
                <?=h(t('teacher.entry.finalmarks.summary.status_found_not_in_class'))?>: <?=h((string)($finalmarksSummary['status_counts']['FOUND_NOT_IN_CLASS'] ?? 0))?> ·
                <?=h(t('teacher.entry.finalmarks.summary.status_not_found'))?>: <?=h((string)($finalmarksSummary['status_counts']['NOT_FOUND'] ?? 0))?> ·
                <?=h(t('teacher.entry.finalmarks.summary.status_ambiguous'))?>: <?=h((string)($finalmarksSummary['status_counts']['AMBIGUOUS'] ?? 0))?> ·
                <?=h(t('teacher.entry.finalmarks.summary.importable'))?>: <?=h((string)($finalmarksSummary['importable_notes'] ?? 0))?> ·
                <?=h(t('teacher.entry.finalmarks.summary.ignored'))?>: <?=h((string)($finalmarksSummary['ignored_notes'] ?? 0))?>
              </div>
            </div>

            <button class="btn primary" type="submit" style="margin-top:12px;" <?= $finalmarksHasImportable ? '' : 'disabled' ?>><?=h(t('teacher.entry.finalmarks.commit_button'))?></button>
          </form>
        </div>
        <script>
          (() => {
            const selects = Array.from(document.querySelectorAll('.finalmarks-manual-select'));
            if (!selects.length) return;
            const refreshOptions = () => {
              const chosen = new Set(selects.map(sel => sel.value).filter(Boolean));
              selects.forEach(sel => {
                const current = sel.value;
                Array.from(sel.options).forEach(opt => {
                  if (!opt.value) return;
                  opt.disabled = opt.value !== current && chosen.has(opt.value);
                });
                const toggle = sel.closest('td')?.querySelector('.finalmarks-import-toggle');
                if (toggle) {
                  if (current) {
                    toggle.disabled = false;
                    toggle.value = current;
                    toggle.checked = true;
                  } else {
                    toggle.disabled = true;
                    toggle.value = '';
                    toggle.checked = false;
                  }
                }
              });
            };
            selects.forEach(sel => sel.addEventListener('change', refreshOptions));
            refreshOptions();
          })();
        </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card" id="snippetBar" style="display:none;">
  <div class="row" style="align-items:flex-end; gap:10px; flex-wrap:wrap;">
    <div style="flex:1; min-width:240px;">
      <label class="label"><?=h(t('teacher.entry.snippets.title_label'))?></label>
      <input class="input" id="snippetTitle" type="text" placeholder="<?=h(t('teacher.entry.snippets.title_placeholder'))?>" style="width:100%;">
    </div>
    <div style="flex:1; min-width:200px;">
      <label class="label"><?=h(t('teacher.entry.snippets.category_label'))?></label>
      <select class="input" id="snippetCategory" style="width:100%;">
        <option value=""><?=h(t('teacher.entry.snippets.category_placeholder'))?></option>
      </select>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button class="btn" type="button" id="btnSnippetSave" disabled><?=h(t('teacher.entry.snippets.save'))?></button>
      <button class="btn secondary" type="button" id="btnSnippetToggle"><?=h(t('teacher.entry.snippets.show'))?></button>
    </div>
  </div>
  <div class="muted" id="snippetSelection" style="margin-top:8px;"><?=h(t('teacher.entry.snippets.selection_hint'))?></div>
</div>

<div id="errBox" class="card" style="display:none;"><div class="alert danger"><strong id="errMsg"></strong></div></div>
<div id="loadingOverlay" class="loading-overlay" style="display:none;">
  <div class="loading-pill"><span class="spin"></span> <?=h(t('teacher.entry.loading'))?></div>
</div>

<div class="card" id="snippetDrawer" style="display:none;">
  <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
    <div>
      <h2 style="margin:0;"><?=h(t('teacher.entry.snippets.drawer_title'))?></h2>
      <div class="muted"><?=h(t('teacher.entry.snippets.drawer_hint'))?></div>
    </div>
    <button class="btn secondary" type="button" id="btnSnippetClose"><?=h(t('teacher.entry.snippets.close'))?></button>
  </div>
  <div id="snippetList" style="margin-top:10px; display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:10px;"></div>
</div>

  <div id="dlgAi" class="modal" style="display:none;">
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-card">
      <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
        <div>
          <h3 style="margin:0;"><?=h(t('teacher.entry.ai.title'))?></h3>
          <div class="muted" id="aiMeta"><?=h(t('teacher.entry.ai.loading'))?></div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top: 10px;">
          <a class="btn secondary ai-btn" type="button" id="btnAiRefresh" style="display:none;">
            <svg class="ai-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3l1.4 4.2L14.6 9 10.4 10.8 9 15l-1.4-4.2L3 9l4.6-1.8L9 3zm8-1l1.05 3.15L21.2 6.2 18.05 7.25 17 10.4 15.95 7.25 12.8 6.2l3.15-1.05L17 2zm-2 10l.9 2.7L18.6 16l-2.7.9L15 19.6l-.9-2.7L11.4 16l2.7-.9.9-2.7z"></path></svg>
            <?=h(t('teacher.entry.ai.refresh'))?>
          </a>
          <a class="btn secondary" type="button" id="btnAiClose"><?=h(t('teacher.entry.ai.close'))?></a>
        </div>
      </div>
      <div id="aiStatus" class="alert" style="margin-top:10px; display:none;"></div>
    <div class="ai-grid" style="margin-top:10px;">
      <div class="ai-card">
        <div class="h"><?=h(t('teacher.entry.ai.strengths'))?></div>
        <div id="aiStrengths" class="c">-</div>
      </div>
      <div class="ai-card">
        <div class="h"><?=h(t('teacher.entry.ai.goals'))?></div>
        <div id="aiGoals" class="c">-</div>
      </div>
      <div class="ai-card">
        <div class="h"><?=h(t('teacher.entry.ai.steps'))?></div>
        <div id="aiSteps" class="c">-</div>
      </div>
    </div>
    <p class="muted" style="margin-top:10px;"><?=h(t('teacher.entry.ai.tip'))?></p>
  </div>
</div>

<?php if ($delegatedMode): ?>
<div id="dlgDelegationDone" class="modal" style="display:none;">
  <div class="modal-backdrop" data-close="1"></div>
  <div class="modal-card">
    <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
      <h3 style="margin:0;"><?=h(t('teacher.entry.delegation_done.title'))?></h3>
    </div>

    <div class="muted" style="margin-top:6px;">
      <?=str_replace('{status}', '<strong>' . h(t('teacher.entry.status.done')) . '</strong>', t('teacher.entry.delegation_done.hint'))?>
    </div>

    <div class="row" style="gap:10px; margin-top:12px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:240px;">
        <label class="label"><?=h(t('teacher.entry.delegation_done.group_label'))?></label>
        <select class="input" id="dlgDoneGroup" style="width:100%;"></select>
      </div>
      <div style="min-width:160px;">
        <label class="label"><?=h(t('teacher.entry.delegation_done.status_label'))?></label>
        <select class="input" id="dlgDoneStatus" style="width:100%;">
          <option value="open"><?=h(t('teacher.entry.status.open'))?></option>
          <option value="done"><?=h(t('teacher.entry.status.done'))?></option>
        </select>
      </div>
      <div style="flex:1; min-width:240px;">
        <label class="label"><?=h(t('teacher.entry.delegation_done.comment_label'))?></label>
        <input class="input" id="dlgDoneNote" type="text" placeholder="<?=h(t('teacher.entry.delegation_done.comment_placeholder'))?>" style="width:100%;">
      </div>
      <div style="display:flex; gap:8px; margin-top: 10px;">
        <button class="btn secondary" type="button" data-close="1"><?=h(t('teacher.entry.dialog.close'))?></button>
        <button class="btn" type="button" id="dlgDoneSave"><?=h(t('teacher.entry.dialog.save'))?></button>
      </div>
    </div>

    <div style="margin-top:14px; border-top:1px solid var(--border); padding-top:12px;">
      <div class="muted" style="margin-bottom:8px;"><?=h(t('teacher.entry.delegation_done.list_title'))?></div>
      <div id="dlgDoneList" style="display:flex; flex-direction:column; gap:8px;"></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$delegatedMode): ?>
    <div id="dlgDelegations" class="modal" style="display:none;">
      <div class="modal-backdrop" data-close="1"></div>
      <div class="modal-card">
        <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
          <h3 style="margin:0;"><?=h(t('teacher.entry.delegation_edit.title'))?></h3>
        </div>
        <div class="muted" style="margin-top:6px;">
          <?=t('teacher.entry.delegation_edit.hint')?>
        </div>

        <div class="row" style="gap:10px; margin-top:12px; align-items:flex-end; flex-wrap:wrap;">
          <div style="min-width:240px;">
            <label class="label"><?=h(t('teacher.entry.delegation_edit.group_label'))?></label>
            <select class="input" id="dlgGroup" style="width:100%;"></select>
          </div>
          <div style="min-width:280px;">
            <label class="label"><?=h(t('teacher.entry.delegation_edit.users_label'))?></label>
            <div id="dlgUsers" class="input" style="width:100%; padding:8px; max-height:220px; overflow:auto;"></div>
            <div class="muted" style="font-size:12px; margin-top:4px;"><?=h(t('teacher.entry.delegation_edit.clear_hint'))?></div>
          </div>
          <div style="min-width:160px;">
            <label class="label"><?=h(t('teacher.entry.delegation_edit.status_label'))?></label>
            <select class="input" id="dlgStatus" style="width:100%;">
              <option value="open"><?=h(t('teacher.entry.status.open'))?></option>
              <option value="done"><?=h(t('teacher.entry.status.done'))?></option>
            </select>
          </div>
          <div style="flex:1; min-width:240px;">
            <label class="label"><?=h(t('teacher.entry.delegation_edit.note_label'))?></label>
            <input class="input" id="dlgNote" type="text" placeholder="<?=h(t('teacher.entry.delegation_edit.note_placeholder'))?>" style="width:100%;">
          </div>
      <div style="display:flex; gap:8px; margin-top: 10px;">
        <button class="btn secondary" type="button" data-close="1"><?=h(t('teacher.entry.dialog.close'))?></button>
        <button class="btn" type="button" id="dlgSave"><?=h(t('teacher.entry.dialog.save'))?></button>
      </div>
        </div>

        <div style="margin-top:14px; border-top:1px solid var(--border); padding-top:12px;">
          <div class="muted" style="margin-bottom:8px;"><?=h(t('teacher.entry.delegation_edit.list_title'))?></div>
          <div id="dlgList" style="display:flex; flex-direction:column; gap:8px;"></div>
        </div>
      </div>
    </div>
<?php endif; ?>

  <div id="classFieldsBox" class="card" style="margin:12px 0; display:none;">
    <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
      <div>
        <h2><?=h(t('teacher.entry.class_fields.title'))?></h2>
        <div style="opacity:.85; font-size:13px;"><?=t('teacher.entry.class_fields.hint')?></div>
      </div>
    </div>

    <div id="classFieldsProgressWrap" class="progress-wrap" style="display:none; margin-top:10px;">
      <div class="progress-meta"><span id="classFieldsProgressText">—</span><span id="classFieldsProgressPct"></span></div>
      <div class="progress"><div id="classFieldsProgressBar" class="progress-bar"></div></div>
    </div>

    <div id="classFieldsForm" style="margin-top:10px;"></div>
  </div>

<div id="app" class="card" style="display:none;">
    <h2><?=h(t('teacher.entry.student_fields.title'))?></h2>
      <?php if (!$childMode): ?>
        <label id="showStudentEntries" class="pill-mini" style="cursor:pointer; user-select:none;">
        <label class="toggle-switch">
          <input type="checkbox" id="toggleChild">
          <span class="toggle-slider" aria-hidden="true"></span>
          <span class="toggle-label"><?=h(t('teacher.entry.show_child_entries'))?></span>
        </label>
        </label>
      <?php else: ?>
        <span id="showStudentEntries" style="display:none;"></span>
      <?php endif; ?>
  <div id="metaTop" class="muted" style="margin-bottom:10px;"><?=h(t('teacher.entry.loading'))?></div>
  <div id="entryFilterRow" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
    <label class="pill-mini" for="studentMissingOnly" style="cursor:pointer; user-select:none; white-space:nowrap;">
      <label class="toggle-switch">
        <input type="checkbox" id="studentMissingOnly">
        <span class="toggle-slider" aria-hidden="true"></span>
        <span class="toggle-label"><?=h(t('teacher.entry.only_open'))?></span>
      </label>
    </label>
    <label class="pill-mini" for="optionButtonsToggle" style="cursor:pointer; user-select:none; white-space:nowrap;">
      <label class="toggle-switch">
        <input type="checkbox" id="optionButtonsToggle">
        <span class="toggle-slider" aria-hidden="true"></span>
        <span class="toggle-label"><?=h(t('teacher.entry.option_buttons'))?></span>
      </label>
    </label>
  </div>
  <div class="row" id="viewSelectRow" style="gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:8px;">
    <div style="min-width:260px;">
      <label class="label"><?=h(t('teacher.entry.view_label'))?></label>
      <select class="input" id="viewSelect" style="width:100%;">
        <?php if (!$childMode): ?>
          <option value="grades"><?=h(t('teacher.entry.view_grades'))?></option>
        <?php endif; ?>
        <option value="student"><?=h(t('teacher.entry.view_students'))?></option>
        <option value="item"><?=h(t('teacher.entry.view_items'))?></option>
      </select>
    </div>
  </div>

  <div id="formsProgressWrap" class="progress-wrap" style="display:none; margin-bottom:14px;">
    <div class="progress-meta"><span id="formsProgressText">—</span><span id="formsProgressPct"></span></div>
    <div class="progress"><div id="formsProgressBar" class="progress-bar"></div></div>
  </div>

  <!-- Grades view -->
  <div id="viewGrades" style="display:none;">
    <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label class="label"><?=h(t('teacher.entry.group_label'))?></label>
        <select class="input" id="gradeGroupSelect" style="width:100%;"></select>
      </div>

      <div style="min-width:260px;">
        <label class="label"><?=h(t('teacher.entry.table_label'))?></label>
        <select class="input" id="gradeOrientation" style="width:100%;">
          <option value="students_rows"><?=h(t('teacher.entry.grade_orientation.rows'))?></option>
          <option value="students_cols"><?=h(t('teacher.entry.grade_orientation.cols'))?></option>
        </select>
      </div>

      <div style="min-width:220px;">
        <label class="label"><?=h(t('teacher.entry.search_label'))?></label>
        <input class="input" id="gradeSearch" type="search" placeholder="<?=h(t('teacher.entry.search_grade_placeholder'))?>" style="width:100%;">
      </div>
      <div class="muted" style="padding-bottom:10px;">
        <?=t('teacher.entry.grades_hint')?>
      </div>
    </div>

    <!-- Split head/body tables so sticky header isn't trapped by the horizontal scroller. -->
    <div id="gradeTableWrap" class="grade-table-wrap">
      <div id="gradeHeadSticky" class="grade-head-sticky">
        <div id="gradeHeadScroller" class="grade-head-scroller" aria-hidden="true">
        <table id="gradeTableHead" class="table grade-table" aria-hidden="true">
          <colgroup id="gradeColGroupHead"></colgroup>
          <thead id="gradeHead"></thead>
        </table>
        </div>
      </div>
      <div id="gradeBodyScroller" class="grade-body-scroller">
        <table id="gradeTableBody" class="table grade-table">
          <colgroup id="gradeColGroupBody"></colgroup>
          <tbody id="gradeBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Student view -->
  <div id="viewStudent" style="display:none;">
    <div id="studentViewGrid" style="display:grid; grid-template-columns: 300px 1fr; gap:12px; align-items:start;">
      <div style="top:14px; align-self:start;">
        <div style="display:flex; gap:8px; align-items:center;">
          <input class="input" id="studentSearch" type="search" placeholder="<?=h(t('teacher.entry.student_search_placeholder'))?>" style="width:100%;">
        </div>
        <div style="margin-top:8px;">
          <label class="label" for="studentGroupSelect"><?=h(t('teacher.entry.group_label'))?></label>
          <select class="input" id="studentGroupSelect" style="width:100%;"></select>
        </div>
        <div id="studentList" style="margin-top:10px; display:flex; flex-direction:column; gap:8px;"></div>
      </div>
      <div>
        <div class="row-actions" style="justify-content:space-between;">
            <div class="pill-mini" id="studentBadge" style="font-weight: bold">—</div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn secondary" type="button" id="btnPdfEntry">
              <?=h(t('teacher.students.btn_pdf_entry'))?>
            </button>
            <button class="btn secondary" type="button" id="btnPrevStudent"><?=h(t('teacher.entry.prev_student'))?></button>
            <button class="btn secondary" type="button" id="btnNextStudent"><?=h(t('teacher.entry.next_student'))?></button>
          </div>
        </div>

        <div id="studentForm"></div>
      </div>
    </div>

    <div id="meetingWizShell" class="meeting-wiz" style="display:none;">
      <div class="meeting-sidebar">
        <div class="card">
          <div style="display:flex; flex-direction:column; gap:10px;">
            <div>
              <label class="label" for="meetingStudentSelect"><?=h(t('teacher.entry.student_label'))?></label>
              <select class="input" id="meetingStudentSelect" style="width:100%;"></select>
              <div class="row" style="gap:6px; margin-top:8px;">
                <button class="btn secondary" type="button" id="btnMeetingStudentPrev"><?=h(t('teacher.entry.prev_student'))?></button>
                <button class="btn secondary" type="button" id="btnMeetingStudentNext"><?=h(t('teacher.entry.next_student'))?></button>
              </div>
            </div>
          </div>
          <div style="margin-top:12px;" class="meeting-nav" id="meetingNav"></div>
        </div>
      </div>
      <div class="meeting-content">
        <div class="card">
          <div class="row-actions" style="justify-content:space-between; margin-bottom:10px;">
            <div class="pill-mini" id="meetingStudentBadge" style="font-weight:bold;">—</div>
          </div>
          <h2 id="meetingStepTitle">—</h2>
          <div class="step-meta" id="meetingStepSub"></div>
          <div id="meetingStepBody"></div>
          <div class="meeting-actions">
            <button class="btn secondary" type="button" id="btnMeetingPrev"><?=h(t('teacher.entry.meeting_prev_step'))?></button>
            <button class="btn primary" type="button" id="btnMeetingNext"><?=h(t('teacher.entry.meeting_next_step'))?></button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Item view -->
  <div id="viewItem" style="display:none;">
    <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label class="label"><?=h(t('teacher.entry.group_label'))?></label>
        <select class="input" id="groupSelect" style="width:100%;"></select>
      </div>
      <div style="min-width:220px;">
        <label class="label"><?=h(t('teacher.entry.search_label'))?></label>
        <input class="input" id="itemSearch" type="search" placeholder="<?=h(t('teacher.entry.item_search_placeholder'))?>" style="width:100%;">
      </div>
      <div class="muted" id="itemHint" style="padding-bottom:10px;"><?=h(t('teacher.entry.item_hint'))?></div>
    </div>

    <div style="overflow:auto; margin-top:12px; border:1px solid var(--border); border-radius:12px;">
      <table class="table" id="itemTable" style="margin:0;">
        <thead id="itemHead"></thead>
        <tbody id="itemBody"></tbody>
      </table>
    </div>
  </div>
</div>

<style>
  <?php if ($childMode): ?>
  body.page{
    background: #fdecec;
  }
  <?php endif; ?>
  <?php if ($meetingMode): ?>
  body.page.meeting-mode{
    background: #f4f7ff;
  }
  body.page.meeting-mode .fixedHeader{
    display: none;
  }
  body.page.meeting-mode .container{
    max-width: 100%;
    padding: 16px 20px 24px;
  }
  body.page.meeting-mode #classSelectCard,
  body.page.meeting-mode #classFieldsBox,
  body.page.meeting-mode #entryFilterRow,
  body.page.meeting-mode #viewSelectRow,
  body.page.meeting-mode #viewGrades,
  body.page.meeting-mode #viewItem,
  body.page.meeting-mode #showStudentEntries,
  body.page.meeting-mode #studentViewGrid{
    display: none !important;
  }
  body.page.meeting-mode #meetingWizShell{
    display: grid !important;
  }
  body.page.meeting-fullscreen #meetingHeaderCard{
    display: none !important;
  }
  body.page.meeting-mode #btnMeetingFullscreenFloat{
    display: none;
  }
  body.page.meeting-fullscreen #btnMeetingExit{
    display: none !important;
  }
  body.page.meeting-fullscreen #btnMeetingFullscreen{
    position: fixed;
    top: 16px;
    right: 16px;
    z-index: 10001;
    width: 42px;
    height: 42px;
    padding: 0;
    border-radius: 999px;
    font-size: 20px;
    font-weight: 800;
    box-shadow: 0 8px 24px rgba(16,24,40,0.2);
  }
  body.page.meeting-fullscreen #btnMeetingFullscreen{
    display: none !important;
  }
  body.page.meeting-fullscreen #btnMeetingFullscreenFloat{
    display: inline-flex !important;
    position: fixed;
    top: 16px;
    right: 16px;
    z-index: 10001;
    width: 42px;
    height: 42px;
    padding: 0;
    border-radius: 999px;
    font-size: 20px;
    font-weight: 800;
    box-shadow: 0 8px 24px rgba(16,24,40,0.2);
  }
  body.page.meeting-mode .history-inline,
  body.page.meeting-mode .child-actions{
    display: none !important;
  }
  body.page.meeting-mode .meeting-content,
  body.page.meeting-mode .meeting-sidebar{
    font-size: 17px;
  }
  body.page.meeting-mode .meeting-nav-item{
    font-size: 16px;
  }
  body.page.meeting-mode .meeting-nav-item .sub{
    font-size: 13px;
  }
  body.page.meeting-mode #meetingStepTitle{
    font-size: 24px;
  }
  body.page.meeting-mode #meetingStepSub{
    font-size: 16px;
    color: rgba(0,0,0,0.7);
    margin-bottom: 15px;
    font-weight: 600;
  }
  body.page.meeting-mode .field{
    border-left: 4px solid rgba(11,87,208,0.55);
  }
  body.page.meeting-mode .field .lbl{
    
  }
  body.page.meeting-mode .field.show-child .child{
    display:block;
    border-left: 4px solid rgba(11,122,11,0.6);
    background: rgba(11,122,11,0.08);
    color: #0b5f2a;
    padding-left: 8px;
  }
  body.page.meeting-mode .field.show-child .child strong{
    color: #0b7a0b;
  }
  body.page.meeting-mode .opts{
    flex-wrap: nowrap;
    gap: 12px;
  }
  body.page.meeting-mode .opt{
    flex: 1 1 0;
    justify-content: center;
    text-align: center;
    font-size: 1rem;
    padding: 10px 12px;
  }
  body.page.meeting-mode .opt .ico{
    width: 34px;
    height: 34px;
  }
  body.page.meeting-mode .opt .ico.placeholder{
    font-size: 16px;
  }
  <?php endif; ?>
  .spin{ width:16px; height:16px; border-radius:999px; border:2px solid rgba(0,0,0,0.15); border-top-color: rgba(0,0,0,0.65); display:inline-block; animation: s 0.8s linear infinite; }
  @keyframes s{ to{ transform: rotate(360deg); } }
  .srow{ border:1px solid var(--border); border-radius:14px; padding:10px; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .srow:hover{ background: rgba(0,0,0,0.02); }
  .srow.active{ outline:2px solid rgba(11,87,208,0.18); background: rgba(11,87,208,0.06); }
  .smeta{ display:flex; flex-direction:column; gap:2px; min-width:0; width:100%; }
  .smeta .n{ font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .smeta .sub{ color:var(--muted); font-size:12px; }

  .save-status{
    margin-top:4px;
    font-size:12px;
    color: var(--muted);
    display:flex;
    align-items:center;
    gap:6px;
    min-height:18px;
  }
  .save-status[data-state="saving"]{ color: #0b57d0; font-weight:750; }
  .save-status[data-state="ok"]{ color: #0b7a0b; font-weight:750; }
  .save-status[data-state="error"]{ color: #b00020; font-weight:800; }
  .loading-overlay{
    position:fixed;
    inset:0;
    background:rgba(255,255,255,0.6);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:9999;
  }
  .loading-pill{
    background:#fff;
    border:1px solid var(--border);
    border-radius:999px;
    padding:10px 16px;
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:700;
    box-shadow:0 6px 24px rgba(0,0,0,0.12);
  }

  .field{ border:1px solid var(--border); border-radius:14px; padding:12px; background:#fff; margin-bottom:10px; }
  .field .lbl{ font-weight:800; }
  .field .help{ color:var(--muted); font-size:12px; margin-top:6px; }
  .field .child{ display:none; margin-top:8px; border-top:1px dashed var(--border); padding-top:8px; color:var(--muted); font-size:12px; }
  .field .child strong{ color: rgba(0,0,0,0.75); }
  .field.show-child .child{ display:block; }
  .subgroup-h{
    margin:12px 0 8px;
    font-weight:700;
    color: rgba(0,0,0,0.82);
    border-left:4px solid rgba(0,122,51,0.4);
    background: rgba(0,122,51,0.06);
    padding:6px 10px;
    border-radius:10px;
  }

  .opts{ display:flex; gap:8px; margin-top:8px; flex-wrap:wrap; align-items:stretch; }
  .opt{ display:inline-flex; gap:8px; align-items:center; padding:8px 10px; border-radius:12px; border:1px solid var(--border); background: #fff; cursor:pointer; user-select:none; flex:0 0 auto; text-align:left; color: inherit; min-height:36px; }
  .opt:hover{ background: rgba(0,0,0,0.02); }
  .opt.selected{
    outline: 2px solid rgba(11,87,208,0.45);
    background: rgba(11,87,208,0.16);
    border-color: rgba(11,87,208,0.35);
  }
  .opt.child-val{
    border-color: rgba(11, 122, 11, 0.6);
    background: rgba(11, 122, 11, 0.18);
    box-shadow: 0 0 0 1px rgba(11, 122, 11, 0.35);
  }
  .opt.child-val .lbl{  }
  .opt.selected.child-val{
      outline-color: rgba(11, 122, 11, 0.55);
      background: linear-gradient(134deg, rgba(11, 122, 11, 0.18) 0%, rgba(11, 122, 11, 0.3) 50%, rgba(11,87,208,0.3) 51%, rgba(11,87,208,0.16) 100%);
  }
  .opt:disabled{ opacity:0.5; cursor:not-allowed; }
  .opt .lbl{ font-weight:750; }
  .opt .ico{ width:26px; height:26px; border-radius:10px; background: rgba(0,0,0,0.04); display:inline-flex; align-items:center; justify-content:center; }
  .opt .ico img{ width:100%; height:100%; object-fit:contain; display:block; }
  .opt .ico.placeholder{ color: rgba(0,0,0,0.35); font-size:14px; }
  .opt:focus-visible{ outline: 2px solid rgba(11,87,208,0.5); outline-offset:2px; }
  .field:focus-within{ outline: 2px solid rgba(11,87,208,0.2); }
  .field-actions{ display:flex; align-items:center; gap:6px; margin-top:6px; }
  .combined-tip{ display:inline-flex; align-items:center; position:relative; }
  .combined-tip-btn{
    display:inline-flex; align-items:center; justify-content:center;
    line-height:1;
  }
  .combined-tip-btn:hover{ color:#0b57d0; }
  .combined-tip-bubble{
    position:absolute; bottom: calc(100% + 6px); left:0;
    background:#fff; border:1px solid var(--border); border-radius:10px;
    padding:8px 10px; font-size:12px; color:var(--text);
    box-shadow:0 8px 24px rgba(0,0,0,0.12);
    min-width:600px; max-width:600px; z-index:30; display:none;
  }
  .combined-tip.open .combined-tip-bubble{ display:block; }
  .combined-tip-bubble::after{
    content:""; position:absolute; top:100%; left:10px;
    border-width:6px; border-style:solid;
    border-color:#fff transparent transparent transparent;
  }
  .combined-inline{ margin-top:8px; padding:10px; border:1px dashed var(--border); border-radius:10px; background:#fafafa; }
  .combined-inline-label{ font-size:12px; text-transform:uppercase; letter-spacing:.02em; color:#6b6b6b; margin-bottom:4px; }
  .combined-inline-text{ font-size:14px; line-height:1.5; }
  .combined-inline-entry{ padding:6px 0; border-top:1px solid var(--border); }
  .combined-inline-entry:first-child{ border-top:0; padding-top:0; }
  .combined-inline-text .delegate-part,
  .combined-tip-bubble .delegate-part{
    background: rgba(255, 193, 7, 0.25);
    border-left: 3px solid #f59e0b;
    padding: 1px 4px;
    border-radius: 4px;
    display: inline-block;
  }

  #itemTable { table-layout: auto; width: max-content; }
  .grade-table-wrap{ margin-top:12px; border:1px solid var(--border); border-radius:12px; }
  .grade-head-sticky{ position:sticky; top: var(--fixed-header-height, 0px); z-index:5; background:#fff; overflow:hidden; }
  .grade-head-scroller{ overflow-x:auto; scrollbar-width:none; }
  .grade-head-scroller::-webkit-scrollbar{ display:none; }
  .grade-body-scroller{ overflow-x:auto; }
  .grade-table{ table-layout: auto; width: max-content; border-collapse: separate; border-spacing: 0; margin:0; }
  #itemTable th, #itemTable td, .grade-table th, .grade-table td { vertical-align: top; }

  #itemTable th.sticky, #itemTable td.sticky,
  .grade-table th.sticky, .grade-table td.sticky{
    position:sticky; left:0; background:#fff; z-index:2;
    min-width: 220px; max-width: 320px;
  }

  #itemTable thead th, .grade-table thead th{ position:sticky; top:0; background:#fff; z-index:3; }
  #itemTable thead th.sticky, .grade-table thead th.sticky{ z-index:4; }
  .grade-head-sticky th{ position:sticky; top: var(--fixed-header-height, 0px); background:#fff; z-index:5; }
  .grade-head-sticky th.sticky{ left:0; z-index:6; }

  #itemTable th:not(.sticky), #itemTable td:not(.sticky),
  .grade-table th:not(.sticky), .grade-table td:not(.sticky){
    max-width: 260px;
  }

  .gradeInput{ width: 6ch; max-width: 8ch; padding: 6px 8px; }

  .cellWrap{ display:flex; flex-direction:column; gap:6px; }
  .cellChild{ display:none; padding:6px 8px; border:1px dashed var(--border); border-radius:10px; color:var(--muted); font-size:12px; background: rgba(0,0,0,0.02); }
  .show-child .cellChild{ display:block; }

  .missing{ outline:2px solid rgba(200,20,20,0.5); background: rgba(200,20,20,0.2); border-radius:12px; padding:4px; }
  .missing:not(.field){ width: fit-content; }

  .modal{ position:fixed; inset:0; z-index:9999; }
  .modal-backdrop{ position:absolute; inset:0; background: rgba(0,0,0,0.35); }
  .modal-card{ position:relative; width:min(980px, calc(100vw - 24px)); max-height: calc(100vh - 24px); overflow:auto; margin:12px auto; background:#fff; border-radius:16px; padding:14px; box-shadow: 0 12px 40px rgba(0,0,0,0.22); border:1px solid rgba(0,0,0,0.08); }
  .del-row{ border:1px solid var(--border); border-radius:12px; padding:10px; display:flex; justify-content:space-between; gap:10px; align-items:center; background:#fff; }
  .del-row .l{ min-width:0; }
  .del-row .l .t{ font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .del-row .l .s{ color:var(--muted); font-size:12px; margin-top:2px; }
  .badge-del{ display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; border:1px solid rgba(11,87,208,0.22); background: rgba(11,87,208,0.08); font-size:12px; color: rgba(11,87,208,0.9); }
  .snippet-card{ border:1px solid var(--border); border-radius:12px; padding:10px; background:#fff; display:flex; flex-direction:column; gap:6px; }
  .snippet-card .h{ display:flex; justify-content:space-between; align-items:center; gap:8px; }
  .snippet-card .c{ color:var(--muted); font-size:12px; }
  .snippet-card .txt{ white-space:pre-wrap; }
  .snippet-menu{ position:absolute; z-index:9999; background:#fff; border:1px solid var(--border); box-shadow:0 8px 24px rgba(0,0,0,0.16); border-radius:12px; padding:10px; min-width:260px; max-width:360px; max-height:60vh; overflow:auto; }
  .snippet-menu h4{ margin:3px 0; font-size:12px; border-top: solid lightgray; padding-top: 4px; }
  .snippet-menu .item{ padding:5px 7px; border-radius:7px; cursor:pointer; }
  .snippet-menu .item:hover{ background: rgba(0,0,0,0.04); }
  .snippet-save{ border:1px dashed var(--border); border-radius:10px; padding:8px; display:flex; flex-direction:column; gap:6px; position: sticky;
    top: 0;
    background: #ffffff;
    margin: 0px -5px 10px -5px; }
  .ai-grid{ display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:12px; }
  .ai-card{ border:1px solid var(--border); border-radius:10px; padding:10px; background:#fff; display:flex; flex-direction:column; gap:8px; }
  .ai-card .h{ display:flex; justify-content:space-between; gap:8px; align-items:center; font-weight:700; }
  .ai-card .c{ white-space:pre-wrap; }
  .ai-card .pill {
    cursor: pointer;
    margin-bottom: 10px;
    padding: 5px 10px;
    border-radius: 15px;
  }
  .ai-banner{ border:1px dashed var(--border); border-radius:12px; padding:10px; display:flex; justify-content:space-between; align-items:center; gap:12px; background: rgba(11,87,208,0.03); }
  .ai-banner .t{ font-weight:700; }
  .ai-icon{ width:16px; height:16px; display:inline-block; vertical-align:middle; fill: dodgerblue; }
  .ai-btn{ display:inline-flex; align-items:center; gap:6px; }
  .snippet-save textarea{ width:100%; min-height:80px; }
  .snippet-save .row{ gap:6px; flex-wrap:wrap; }
  .section-h{
    border-left:10px solid rgba(0,122,51,0.85);
    background: rgba(0,122,51,0.16);
    padding:10px 14px;
    border-radius:14px;
    box-shadow: 0 8px 18px rgba(0,122,51,0.12);
    font-weight:900;
  }
  .subgroup-label{ color: rgba(0,0,0,0.7); font-size:12px; }
  .meeting-wiz{
    display:grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap:14px;
    align-items:start;
  }
  .meeting-nav{
    display:flex;
    flex-direction:column;
    gap:6px;
    margin-top:10px;
  }
  .meeting-nav-item{
    padding:8px 10px;
    border-radius:10px;
    border:1px solid transparent;
    cursor:pointer;
    font-weight:600;
    display:flex;
    flex-direction:column;
    gap:2px;
    background: transparent;
    text-align: left;
    width: 100%;
  }
  .meeting-nav-item .sub{
    color:var(--muted);
    font-size:12px;
    font-weight:500;
  }
  .meeting-nav-item:hover{ background: rgba(0,0,0,0.04); }
  .meeting-nav-item.active{
    background: rgba(11,87,208,0.08);
    border-color: rgba(11,87,208,0.25);
    color: var(--primary);
  }
  .meeting-actions{
    display:flex;
    gap:8px;
    justify-content:flex-end;
    margin-top:14px;
  }
  @media (max-width: 980px){
    .meeting-wiz{ grid-template-columns: 1fr; }
  }
</style>

<script>
(function(){
  const DELEGATED_MODE = (<?= (int)$jsDelegatedMode ?> === 1);
  const CURRENT_USER_ID = Number(<?= (int)$jsUserId ?>);
  const CAN_DELEGATE = (<?= (int)$jsCanDelegate ?> === 1);
  const DELEGATED_READONLY_VISIBLE = (<?= (int)$jsDelegationShowOtherFieldsReadonly ?> === 1);
  const DELEGATION_OTHER_FIELDS_KEY = 'delegation_show_other_fields';

  // ✅ NEW: UI language for option label rendering (de/en)
  const UI_LANG = <?= json_encode(ui_lang()) ?>;
  const I18N = <?=json_encode([
    'status_open' => t('teacher.entry.status.open'),
    'status_done' => t('teacher.entry.status.done'),
    'delegation_edit' => t('teacher.entry.delegation_edit.edit_button'),
    'delegation_clear' => t('teacher.entry.delegation_edit.clear_button'),
    'delegation_done_empty' => t('teacher.entry.delegation_done.empty'),
    'delegation_empty' => t('teacher.entry.delegation_edit.empty'),
    'snippet_no_text' => t('teacher.entry.snippets.no_text'),
    'snippet_no_text_fallback' => t('teacher.entry.snippets.no_text_fallback'),
    'snippet_no_target' => t('teacher.entry.snippets.no_target'),
    'snippet_empty' => t('teacher.entry.snippets.empty'),
    'snippet_menu_title' => t('teacher.entry.snippets.menu_title'),
    'snippet_menu_title_placeholder' => t('teacher.entry.snippets.menu_title_placeholder'),
    'snippet_menu_category_placeholder' => t('teacher.entry.snippets.menu_category_placeholder'),
    'snippet_menu_save' => t('teacher.entry.snippets.menu_save'),
    'snippet_selection' => t('teacher.entry.snippets.selection'),
    'snippet_default_category' => t('teacher.entry.snippets.default_category'),
    'snippet_untitled' => t('teacher.entry.snippets.untitled'),
    'snippet_generated' => t('teacher.entry.snippets.generated'),
    'snippet_insert_current' => t('teacher.entry.snippets.insert_current'),
    'ai_copy_success' => t('teacher.entry.ai.copy_success'),
    'ai_copy_fail' => t('teacher.entry.ai.copy_fail'),
    'ai_none' => t('teacher.entry.ai.none'),
    'ai_empty' => t('teacher.entry.ai.empty'),
    'ai_require_options' => t('teacher.entry.ai.require_options'),
    'ai_cached' => t('teacher.entry.ai.cached_notice'),
    'ai_loading' => t('teacher.entry.ai.loading_status'),
    'ai_loaded' => t('teacher.entry.ai.loaded_notice'),
    'ai_error' => t('teacher.entry.ai.error'),
    'ai_option_ideas' => t('teacher.entry.ai.option_ideas'),
    'ai_open' => t('teacher.entry.ai.open'),
    'ai_open_disabled_title' => t('teacher.entry.ai.open_disabled_title'),
    'ai_dialog_title' => t('teacher.entry.ai.dialog_title'),
    'ai_banner_title' => t('teacher.entry.ai.banner_title'),
    'class_label' => t('teacher.entry.class_label'),
    'grade_level' => t('teacher.entry.grade_level'),
    'field_fallback' => t('teacher.entry.field_fallback'),
    'field_label' => t('teacher.entry.field_label'),
    'option_fallback' => t('teacher.entry.option_fallback'),
    'yes_no' => t('teacher.entry.yes_no'),
    'filter_all' => t('teacher.entry.filter_all'),
    'student_label' => t('teacher.entry.student_label'),
    'student_header' => t('teacher.entry.student_header'),
    'name_label' => t('teacher.entry.name_label'),
    'edit' => t('teacher.entry.edit'),
    'delete' => t('teacher.entry.delete'),
    'prompt_new_child_value' => t('teacher.entry.prompt_new_child_value'),
    'prompt_clear_child_value' => t('teacher.entry.prompt_clear_child_value'),
    'merge_prompt_title' => t('teacher.entry.merge_prompt.title'),
    'merge_prompt_choice' => t('teacher.entry.merge_prompt.choice'),
    'save_child_unlocking' => t('teacher.entry.save.child_unlocking'),
    'save_child_unlocked' => t('teacher.entry.save.child_unlocked'),
    'save_child_unlock_error' => t('teacher.entry.save.child_unlock_error'),
    'save_child_deleting' => t('teacher.entry.save.child_deleting'),
    'save_child_updating' => t('teacher.entry.save.child_updating'),
    'save_child_deleted' => t('teacher.entry.save.child_deleted'),
    'save_child_updated' => t('teacher.entry.save.child_updated'),
    'save_child_save_error' => t('teacher.entry.save.child_save_error'),
    'save_saving' => t('teacher.entry.save.saving'),
    'save_saved_at' => t('teacher.entry.save.saved_at'),
    'save_error_offline' => t('teacher.entry.save.error_offline'),
    'save_error' => t('teacher.entry.save.error'),
    'save_dirty' => t('teacher.entry.save.dirty'),
    'save_unsaved_changes' => t('teacher.entry.save.unsaved_changes'),
    'save_failed_unsaved' => t('teacher.entry.save.failed_unsaved'),
    'save_blocked_switch' => t('teacher.entry.save.blocked_switch'),
    'save_retry_failed_auto' => t('teacher.entry.save.retry_failed_auto'),
    'save_retrying' => t('teacher.entry.save.retrying'),
    'save_permanent_error' => t('teacher.entry.save.permanent_error'),
    'save_connection_interrupted' => t('teacher.entry.save.connection_interrupted'),
    'ajax_network_error' => t('teacher.entry.ajax.network_error'),
    'ajax_session_expired' => t('teacher.entry.ajax.session_expired'),
    'ajax_non_json' => t('teacher.entry.ajax.non_json'),
    'ajax_invalid_json' => t('teacher.entry.ajax.invalid_json'),
    'ajax_timeout' => t('teacher.entry.ajax.timeout'),
    'ajax_request_failed' => t('teacher.entry.ajax.request_failed'),
    'error_save' => t('teacher.entry.error_save'),
    'error_unlock' => t('teacher.entry.error_unlock'),
    'error_update' => t('teacher.entry.error_update'),
    'save_idle' => t('teacher.entry.save.idle'),
    'progress_child_complete' => t('teacher.entry.progress.child_complete'),
    'progress_delegated_complete' => t('teacher.entry.progress.delegated_complete'),
    'progress_forms_complete' => t('teacher.entry.progress.forms_complete'),
    'progress_class_fields' => t('teacher.entry.progress.class_fields'),
    'progress_missing_child' => t('teacher.entry.progress.missing_child'),
    'progress_missing_teacher' => t('teacher.entry.progress.missing_teacher'),
    'progress_status_line' => t('teacher.entry.progress.status_line'),
    'progress_badge_open' => t('teacher.entry.progress.badge_open'),
    'progress_open_breakdown' => t('teacher.entry.progress.open_breakdown'),
    'student_badge_child' => t('teacher.entry.progress.student_badge_child'),
    'student_badge_both' => t('teacher.entry.progress.student_badge_both'),
    'no_results' => t('teacher.entry.no_results'),
    'no_students_found' => t('teacher.entry.no_students_found'),
    'unlock_child' => t('teacher.entry.unlock_child'),
    'locked_cannot_edit' => t('teacher.entry.locked_cannot_edit'),
    'locked_teacher_can_edit' => t('teacher.entry.locked_teacher_can_edit'),
    'locked_title' => t('teacher.entry.locked_title'),
    'locked_notice' => t('teacher.entry.locked_notice'),
    'notice_label' => t('teacher.entry.notice_label'),
    'child_missing_title' => t('teacher.entry.child_missing_title'),
    'child_missing_fields' => t('teacher.entry.child_missing_fields'),
    'child_missing_more' => t('teacher.entry.child_missing_more'),
    'ai_require_options_start' => t('teacher.entry.ai.require_options_start'),
    'delegated_badge' => t('teacher.entry.delegated_badge'),
    'delegated_badge_done' => t('teacher.entry.delegated_badge_done'),
    'readonly_badge' => t('teacher.entry.readonly_badge'),
    'delegate_action_short' => t('teacher.entry.delegate_action_short'),
    'group_progress' => t('teacher.entry.group_progress'),
    'role_child' => t('teacher.entry.role_child'),
    'role_teacher' => t('teacher.entry.role_teacher'),
    'no_open_fields' => t('teacher.entry.no_open_fields'),
    'no_options' => t('teacher.entry.no_options'),
    'no_grade_fields' => t('teacher.entry.no_grade_fields'),
    'grade_header' => t('teacher.entry.grade_header'),
    'item_header' => t('teacher.entry.item_header'),
    'no_items_found' => t('teacher.entry.no_items_found'),
    'no_delegations' => t('teacher.entry.no_delegations'),
    'no_delegations_class' => t('teacher.entry.no_delegations_class'),
    'no_class_available' => t('teacher.entry.no_class_available'),
    'status_locked' => t('teacher.entry.status.locked'),
    'status_submitted' => t('teacher.entry.status.submitted'),
    'status_draft' => t('teacher.entry.status.draft'),
    'meta_top' => t('teacher.entry.meta_top'),
    'template_fallback' => t('teacher.entry.template_fallback'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
  const tEntry = (key) => I18N[key] ?? key;
  const tfmtEntry = (key, vars = {}) => {
    const base = tEntry(key);
    return base.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
  };

  const btnDelegationsTop = document.getElementById('btnDelegationsTop');
  const apiUrl = <?=json_encode(url('teacher/ajax/entry_api.php'))?>;
  const CHILD_MODE = <?=json_encode($childMode ? 1 : 0)?>;
  const CHILD_EDIT_OVERRIDE = <?=json_encode($childEditOverride ? 1 : 0)?>;
  const CHILD_CLEAR_CONFIRM = <?=json_encode(t('teacher.child_entry.clear_confirm'))?>;
  const CHILD_CLEAR_LABEL = <?=json_encode(t('teacher.child_entry.clear'))?>;
  const TEMPLATE_CONFLICT_CONFIRM_FALLBACK = <?=json_encode(t('teacher.entry.template_conflict.message'))?>;
  const csrf = <?=json_encode(csrf_token())?>;
  const DEBUG = (new URLSearchParams(location.search).get('debug') === '1');
  const MEETING_MODE = (<?=json_encode($meetingMode ? 1 : 0)?> === 1);

  const elApp = document.getElementById('app');
  const classFieldsBox = document.getElementById('classFieldsBox');
  const classFieldsForm = document.getElementById('classFieldsForm');
  const elErrBox = document.getElementById('errBox');
  const elErrMsg = document.getElementById('errMsg');
  const loadingOverlay = document.getElementById('loadingOverlay');
  const elMetaTop = document.getElementById('metaTop');
  const formsProgressWrap = document.getElementById('formsProgressWrap');
  const formsProgressBar = document.getElementById('formsProgressBar');
  const formsProgressText = document.getElementById('formsProgressText');
  const formsProgressPct = document.getElementById('formsProgressPct');

  const classFieldsProgressWrap = document.getElementById('classFieldsProgressWrap');
  const classFieldsProgressBar = document.getElementById('classFieldsProgressBar');
  const classFieldsProgressText = document.getElementById('classFieldsProgressText');
  const classFieldsProgressPct = document.getElementById('classFieldsProgressPct');

  const elSavePill = document.getElementById('savePill');
  const elSaveStatus = document.getElementById('saveStatus');
  const dlg = document.getElementById('dlgDelegations');
  const dlgGroup = document.getElementById('dlgGroup');
  const dlgUsers = document.getElementById('dlgUsers');
  const dlgStatus = document.getElementById('dlgStatus');
  const dlgNote = document.getElementById('dlgNote');
  const dlgSave = document.getElementById('dlgSave');
  const dlgList = document.getElementById('dlgList');

  const btnDelegationDoneTop = document.getElementById('btnDelegationDoneTop');
  const dlgDone = document.getElementById('dlgDelegationDone');
  const dlgDoneGroup = document.getElementById('dlgDoneGroup');
  const dlgDoneStatus = document.getElementById('dlgDoneStatus');
  const dlgDoneNote = document.getElementById('dlgDoneNote');
  const dlgDoneSave = document.getElementById('dlgDoneSave');
  const dlgDoneList = document.getElementById('dlgDoneList');

  const classSelect = document.getElementById('classSelect');
  const viewSelect = document.getElementById('viewSelect');
  const toggleChild = document.getElementById('toggleChild');

  const viewGrades = document.getElementById('viewGrades');
  const viewStudent = document.getElementById('viewStudent');
  const viewItem = document.getElementById('viewItem');
  const showStudentEntries = document.getElementById('showStudentEntries');

  const gradeGroupSelect = document.getElementById('gradeGroupSelect');
  const gradeOrientation = document.getElementById('gradeOrientation');
  const gradeSearch = document.getElementById('gradeSearch');
  const gradeHead = document.getElementById('gradeHead');
  const gradeBody = document.getElementById('gradeBody');
  const gradeBodyScroller = document.getElementById('gradeBodyScroller');
  const gradeTableHead = document.getElementById('gradeTableHead');
  const gradeTableBody = document.getElementById('gradeTableBody');
  const gradeColGroupHead = document.getElementById('gradeColGroupHead');
  const gradeColGroupBody = document.getElementById('gradeColGroupBody');
  const gradeHeadScroller = document.getElementById('gradeHeadScroller');
  const fixedHeader = document.querySelector('.fixedHeader');
  const btnMeetingFullscreen = document.getElementById('btnMeetingFullscreen');
  const btnMeetingExit = document.getElementById('btnMeetingExit');
  const btnMeetingFullscreenFloat = document.getElementById('btnMeetingFullscreenFloat');
  const meetingWizShell = document.getElementById('meetingWizShell');
  const meetingNav = document.getElementById('meetingNav');
  const meetingStepTitle = document.getElementById('meetingStepTitle');
  const meetingStepSub = document.getElementById('meetingStepSub');
  const meetingStepBody = document.getElementById('meetingStepBody');
  const meetingStudentBadge = document.getElementById('meetingStudentBadge');
  const meetingStudentSelect = document.getElementById('meetingStudentSelect');
  const btnMeetingPrev = document.getElementById('btnMeetingPrev');
  const btnMeetingNext = document.getElementById('btnMeetingNext');
  const btnMeetingStudentPrev = document.getElementById('btnMeetingStudentPrev');
  const btnMeetingStudentNext = document.getElementById('btnMeetingStudentNext');

  if (MEETING_MODE) {
    document.body.classList.add('meeting-mode');
  }

  if (gradeBodyScroller) {
    gradeBodyScroller.addEventListener('scroll', syncGradeHeaderScroll);
  }
  window.addEventListener('resize', scheduleGradeSync);
  window.addEventListener('resize', updateFixedHeaderHeight);

  function updateMeetingFullscreenLabel(){
    const enterLabel = btnMeetingFullscreen?.dataset.labelEnter || btnMeetingFullscreenFloat?.dataset.labelEnter || '';
    const exitLabel = btnMeetingFullscreen?.dataset.labelExit || btnMeetingFullscreenFloat?.dataset.labelExit || '';
    const isFull = !!document.fullscreenElement;
    document.body.classList.toggle('meeting-fullscreen', isFull);
    const setBtn = (btn) => {
      if (!btn) return;
      if (isFull) {
        btn.textContent = '⤫';
        btn.setAttribute('aria-label', exitLabel);
        btn.setAttribute('title', exitLabel);
      } else {
        btn.textContent = enterLabel;
        btn.removeAttribute('title');
        btn.setAttribute('aria-label', enterLabel);
      }
    };
    setBtn(btnMeetingFullscreen);
    setBtn(btnMeetingFullscreenFloat);
  }

  if (MEETING_MODE) {
    if (viewSelect) {
      viewSelect.value = 'student';
      viewSelect.disabled = true;
    }
    updateMeetingFullscreenLabel();
    document.addEventListener('fullscreenchange', updateMeetingFullscreenLabel);
    const toggleFullscreen = async () => {
        const root = document.documentElement;
        if (!document.fullscreenElement && root.requestFullscreen) {
          try { await root.requestFullscreen(); } catch (e) {}
        } else if (document.exitFullscreen) {
          try { await document.exitFullscreen(); } catch (e) {}
        }
      };
    if (btnMeetingFullscreen) {
      btnMeetingFullscreen.addEventListener('click', toggleFullscreen);
    }
    if (btnMeetingFullscreenFloat) {
      btnMeetingFullscreenFloat.addEventListener('click', toggleFullscreen);
    }
    if (btnMeetingPrev) {
      btnMeetingPrev.addEventListener('click', () => meetingStepMove(-1));
    }
    if (btnMeetingNext) {
      btnMeetingNext.addEventListener('click', () => meetingStepMove(1));
    }
    if (btnMeetingStudentPrev) {
      btnMeetingStudentPrev.addEventListener('click', () => {
        ui.activeStudentIndex = Math.max(0, ui.activeStudentIndex - 1);
        renderMeetingView();
      });
    }
    if (btnMeetingStudentNext) {
      btnMeetingStudentNext.addEventListener('click', () => {
        const maxIdx = Math.max(0, currentStudents().length - 1);
        ui.activeStudentIndex = Math.min(maxIdx, ui.activeStudentIndex + 1);
        renderMeetingView();
      });
    }
    if (meetingStudentSelect) {
      meetingStudentSelect.addEventListener('change', () => {
        ui.activeStudentIndex = Number(meetingStudentSelect.value || 0);
        renderMeetingView();
      });
    }
  }

  const studentSearch = document.getElementById('studentSearch');
  const studentGroupSelect = document.getElementById('studentGroupSelect');
  const studentList = document.getElementById('studentList');
  const studentForm = document.getElementById('studentForm');
  const studentBadge = document.getElementById('studentBadge');
  const btnPdfEntry = document.getElementById('btnPdfEntry');
  const delegationOtherFieldsToggle = document.getElementById('toggleDelegationOtherFields');
  const btnPrevStudent = document.getElementById('btnPrevStudent');
  const btnNextStudent = document.getElementById('btnNextStudent');
  const studentMissingOnly = document.getElementById('studentMissingOnly');

  const groupSelect = document.getElementById('groupSelect');
  const itemSearch = document.getElementById('itemSearch');
  const itemHead = document.getElementById('itemHead');
  const itemBody = document.getElementById('itemBody');

  const snippetBar = document.getElementById('snippetBar');
  const snippetDrawer = document.getElementById('snippetDrawer');
  const snippetList = document.getElementById('snippetList');
  const snippetSelection = document.getElementById('snippetSelection');
  const snippetTitle = document.getElementById('snippetTitle');
  const snippetCategory = document.getElementById('snippetCategory');
  const btnSnippetSave = document.getElementById('btnSnippetSave');
  const btnSnippetToggle = document.getElementById('btnSnippetToggle');
  const btnSnippetClose = document.getElementById('btnSnippetClose');

  const dlgAi = document.getElementById('dlgAi');
  const aiBackdrop = document.querySelector('#dlgAi .modal-backdrop');
  const btnAiClose = document.getElementById('btnAiClose');
  const aiMeta = document.getElementById('aiMeta');
  const aiStatus = document.getElementById('aiStatus');
  const aiStrengths = document.getElementById('aiStrengths');
  const aiGoals = document.getElementById('aiGoals');
  const aiSteps = document.getElementById('aiSteps');
  const btnAiRefresh = document.getElementById('btnAiRefresh');

  const MERGE_STORAGE_KEY = 'leb_merge_memory_v1';
  const OPTION_STYLE_KEY = 'leb_option_style';
  const VIEW_STORAGE_KEY = CHILD_MODE ? 'leb_view_mode_child' : 'leb_view_mode';
  const pdfEntryBase = <?=json_encode(url('teacher/pdf_entry.php'))?>;

  let state = {
    class_id: 0,
    template: null,
    groups: [],
    child_groups: [],
    text_snippets: [],
    delegation_users: [],
    delegations: [],
    period_label: <?=json_encode($classPeriodLabel)?>,
    students: [],
    values_teacher: {},
    values_teacher_own: {},
    values_teacher_parts: {},
    values_child: {},
    locked_child_fields: {},
    value_history: {},
    class_report_instance_id: 0,
    class_fields: null,
    progress_summary: null,
    ai_enabled: false,
    class_grade_level: null,
    fieldMap: {},
    is_class_teacher: false,
  };

  let lastSaveAt = null;

  let ui = {
    view: 'grades',
    showChild: false,
    activeStudentIndex: 0,
    studentFilter: '',
    studentMissingOnly: false,
    studentGroupKey: 'ALL',
    groupKey: 'ALL',
    itemFilter: '',
    gradeGroupKey: 'ALL',
    gradeFilter: '',
    gradeOrientation: localStorage.getItem('leb_grade_orientation') || 'students_rows',
    optionMode: (localStorage.getItem(OPTION_STYLE_KEY) === 'buttons') ? 'buttons' : 'dropdown',
    saveTimers: new Map(),
    pendingPayloads: new Map(),
    saveChains: new Map(),
    retryTimers: new Map(),
    retryAttempts: new Map(),
    saveInFlight: 0,
    navigationLocked: false,
    mergeDecisions: new Map(),
  };

  if (MEETING_MODE) {
    ui.studentMissingOnly = false;
    ui.optionMode = 'buttons';
  }

  let meetingState = {
    steps: [],
    activeStep: 0,
    activeStudentId: 0,
  };

  function mergeDecisionKey(reportId, fieldId){
    const cid = Number(state.class_id || 0);
    return `${cid}:${reportId}:${fieldId}`;
  }

  function readMergeMemory(){
    try {
      const raw = localStorage.getItem(MERGE_STORAGE_KEY);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return (parsed && typeof parsed === 'object') ? parsed : {};
    } catch (e) {
      console.warn('merge memory read failed', e);
      return {};
    }
  }

  function writeMergeMemory(mem){
    try {
      localStorage.setItem(MERGE_STORAGE_KEY, JSON.stringify(mem));
    } catch (e) {
      console.warn('merge memory write failed', e);
    }
  }

  const snippetMenu = document.createElement('div');
  snippetMenu.className = 'snippet-menu';
  snippetMenu.style.display = 'none';
  document.body.appendChild(snippetMenu);

  let lastSnippetTarget = null;
  let lastSnippetSelection = '';
  let aiCache = new Map();
  let aiCurrentStudent = null;
  let aiLoading = false;
  let delegatedShowOtherFields = DELEGATED_READONLY_VISIBLE;

  if (DELEGATED_MODE && DELEGATED_READONLY_VISIBLE) {
    const stored = window.localStorage.getItem(DELEGATION_OTHER_FIELDS_KEY);
    if (stored !== null) delegatedShowOtherFields = stored === '1';
    if (delegationOtherFieldsToggle) {
      delegationOtherFieldsToggle.checked = delegatedShowOtherFields;
      delegationOtherFieldsToggle.addEventListener('change', () => {
        delegatedShowOtherFields = delegationOtherFieldsToggle.checked;
        window.localStorage.setItem(DELEGATION_OTHER_FIELDS_KEY, delegatedShowOtherFields ? '1' : '0');
        if (state.class_id) {
          loadClass(state.class_id);
        }
      });
    }
  }

  const AI_ICON = '<svg class="ai-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3l1.4 4.2L14.6 9 10.4 10.8 9 15l-1.4-4.2L3 9l4.6-1.8L9 3zm8-1l1.05 3.15L21.2 6.2 18.05 7.25 17 10.4 15.95 7.25 12.8 6.2l3.15-1.05L17 2zm-2 10l.9 2.7L18.6 16l-2.7.9L15 19.6l-.9-2.7L11.4 16l2.7-.9.9-2.7z"></path></svg>';

  if (btnPdfEntry) {
    btnPdfEntry.addEventListener('click', () => {
      const sid = Number(btnPdfEntry.dataset.studentId || 0);
      const cid = Number(state.class_id || 0);
      if (!sid || !cid) return;
      const url = new URL(pdfEntryBase, window.location.origin);
      url.searchParams.set('class_id', String(cid));
      url.searchParams.set('student_id', String(sid));
      if (DELEGATED_MODE) {
        url.searchParams.set('delegated', '1');
      }
      window.location.href = url.toString();
    });
  }

  function dbg(...args){ if (DEBUG) console.log('[LEB entry]', ...args); }

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function fmtPipeItalic(s){
    const raw = String(s ?? '');
    const idx = raw.indexOf('|');
    if (idx < 0) return esc(raw);
    return `${esc(raw.slice(0, idx + 1))}<i>${esc(raw.slice(idx + 1))}</i>`;
  }
  function normalize(s){ return String(s ?? '').toLowerCase().trim(); }

  function groupFilterValue(groupKey, subgroup){
    const sub = String(subgroup || '').trim();
    return sub ? `${groupKey}::${sub}` : String(groupKey || '');
  }

  function subgroupLabelForLang(subgroupKey, subgroupTitleEn){
    const key = String(subgroupKey || '').trim();
    if (!key) return '';
    if (UI_LANG === 'en') {
      const titleEn = String(subgroupTitleEn || '').trim();
      if (titleEn) return titleEn;
    }
    return key;
  }

  function parseGroupFilterValue(value){
    const raw = String(value || '').trim();
    if (!raw || raw === 'ALL') return { groupKey: 'ALL', subgroup: '' };
    const parts = raw.split('::');
    if (parts.length > 1) {
      return { groupKey: parts[0] || 'ALL', subgroup: parts.slice(1).join('::') };
    }
    return { groupKey: raw, subgroup: '' };
  }

  function collectGroupFilterOptions(){
    const out = [];
    activeGroups().forEach(g => {
      const groupTitle = g.title || g.key;
      const subgroupLabels = new Map();
      (g.fields || []).forEach(f => {
        const sub = String(f.subgroup || '').trim();
        if (sub) {
          const label = subgroupLabelForLang(sub, f.subgroup_title_en);
          if (!subgroupLabels.has(sub)) subgroupLabels.set(sub, label);
        }
      });
      out.push({ value: String(g.key), label: String(groupTitle) });
      const subgroups = Array.from(subgroupLabels.entries())
        .sort((a, b) => a[0].localeCompare(b[0], undefined, { sensitivity: 'base' }));
      subgroups.forEach(([sub, label]) => {
        out.push({ value: groupFilterValue(g.key, sub), label: `${groupTitle} / ${label}` });
      });
    });
    return out;
  }

  function isEditableField(field, group){
    const groupEditable = Number(group?.can_edit || 0) === 1;
    const fieldEditable = Number(field?.can_edit || 0) === 1;
    return groupEditable && fieldEditable;
  }

  function activeProgressFieldIds(reportId){
    const ids = [];
    const groups = CHILD_MODE ? activeGroupsForReport(reportId) : activeGroups();
    groups.forEach(g => {
      (g.fields || []).forEach(f => {
        if (!isEditableField(f, g)) return;
        ids.push(Number(f.id));
      });
    });
    return ids;
  }

  function activeProgressForStudent(reportId){
    const ids = activeProgressFieldIds(reportId);
    const total = ids.length;
    let done = 0;
    ids.forEach(fid => {
      const v = activeFieldValue(reportId, fid);
      if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') done++;
    });
    const missing = Math.max(0, total - done);
    return { total, done, missing, complete: total > 0 && missing === 0 };
  }

  function studentHasActiveMissing(student){
    const rid = Number(student?.report_instance_id || 0);
    if (!rid) return false;
    return activeProgressForStudent(rid).missing > 0;
  }

  function filterStudentsForMissing(list){
    const base = Array.isArray(list) ? list : [];
    return ui.studentMissingOnly ? base.filter(studentHasActiveMissing) : base;
  }

  function isTeacherFieldMissing(reportId, fieldId){
    return String(teacherEditVal(reportId, fieldId) ?? '').trim() === '';
  }

  function optionCompletionForStudent(reportId){
    let total = 0;
    let missing = 0;

    (state.groups || []).forEach(g => {
      (g.fields || []).forEach(f => {
        if (!isEditableField(f, g)) return;
        const type = String(f.field_type || '');
        const hasOptions = Array.isArray(f.options) && f.options.length > 0;
        if (!hasOptions) return;
        if (!(type === 'radio' || type === 'select' || type === 'grade')) return;

        total++;
        if (isTeacherFieldMissing(reportId, f.id)) missing++;
      });
    });

    return { total, missing };
  }

  function fieldMissingForAnyStudent(field, students){
    return (students || []).some(s => isActiveFieldMissing(s.report_instance_id, field.id));
  }

  function optionLabel(options, value){
    const v = String(value ?? '');
    if (!v) return '';
    if (!Array.isArray(options)) return v;

    const hit = options.find(o => String(o?.value ?? '') === v);
    if (!hit) return v;

    // ✅ NEW: language-aware option labels
    if (UI_LANG === 'en') {
      const le = String(hit?.label_en ?? '').trim();
      if (le) return le;
    }
    const ld = String(hit?.label ?? '').trim();
    return ld ? ld : String(hit?.value ?? v);
  }

  function teacherDisplay(f, raw){
    const v = String(raw ?? '');
    if (!v) return '';
    const type = String(f?.field_type ?? '');
    if (type === 'select' || type === 'radio' || type === 'grade') {
      return optionLabel(Array.isArray(f.options) ? f.options : null, v);
    }
    return v;
  }

  function childDisplay(f, raw){
    const v = String(raw ?? '');
    if (!v) return '';
    const child = f && f.child ? f.child : null;
    const childType = String(child?.field_type ?? '');
    const opts = child && Array.isArray(child.options) ? child.options : null;

    if (childType === 'select' || childType === 'radio' || childType === 'grade') {
      return optionLabel(opts, v);
    }
    return v;
  }

  function childLabel(field){
    if (!field || !field.child) return '';
    const c = field.child;
    return String(c.label || c.field_name || tEntry('field_fallback'));
  }

  function childFieldDisplay(f, raw){
    const v = String(raw ?? '');
    if (!v) return '';
    const type = String(f?.field_type ?? '');
    if (type === 'select' || type === 'radio' || type === 'grade') {
      return optionLabel(Array.isArray(f.options) ? f.options : null, v);
    }
    return v;
  }

  function lockedChildFieldMap(reportId){
    const key = String(reportId || '');
    const raw = (state.locked_child_fields && state.locked_child_fields[key]) ? state.locked_child_fields[key] : {};
    return raw && typeof raw === 'object' ? raw : {};
  }

  function isChildFieldLocked(reportId, fieldId){
    if (!CHILD_MODE) return false;
    const locked = lockedChildFieldMap(reportId);
    const key = String(fieldId || '');
    return !!(locked[key] || locked[fieldId]);
  }

  function activeGroups(){
    return CHILD_MODE ? (state.child_groups || []) : (state.groups || []);
  }

  function activeGroupsForReport(reportId){
    if (!CHILD_MODE) return activeGroups();
    const locked = lockedChildFieldMap(reportId);
    return (state.child_groups || []).map(g => {
      const fields = (g.fields || []).filter(f => !locked[String(f.id)] && !locked[f.id]);
      if (!fields.length) return null;
      return { ...g, fields };
    }).filter(Boolean);
  }

  function activeFieldValue(reportId, fieldId){
    return CHILD_MODE ? childVal(reportId, fieldId) : teacherEditVal(reportId, fieldId);
  }

  function activeFieldDisplay(field, raw){
    return CHILD_MODE ? childFieldDisplay(field, raw) : teacherDisplay(field, raw);
  }

  function isActiveFieldMissing(reportId, fieldId){
    if (isChildFieldLocked(reportId, fieldId)) return false;
    return String(activeFieldValue(reportId, fieldId) ?? '').trim() === '';
  }

  function childInfoHtml(f, reportId){
    if (!f || !f.child || !f.child.id) return '';
    const type = String(f.field_type || '');
    if (type === 'radio' || type === 'select' || type === 'grade') return '';
    const childId = Number(f.child.id);
    const rawChild = childVal(reportId, childId);
    const shownChild = rawChild ? childDisplay(f, rawChild) : '';
    const label = childLabel(f);
    const baseAttrs = `data-child-field="${esc(childId)}" data-child-label="${esc(label)}"`;
    const deleteDisabled = rawChild ? '' : 'disabled';
    const actionsAllowed = !DELEGATED_MODE;
    const actionsHtml = actionsAllowed
      ? `
        <div class="child-actions" style="display:flex; gap:6px; margin-top:6px; flex-wrap:wrap;">
          <button class="btn secondary" type="button" data-edit-child="${esc(reportId)}" ${baseAttrs}>${esc(tEntry('edit'))}</button>
          <button class="btn secondary" type="button" data-delete-child="${esc(reportId)}" ${baseAttrs} ${deleteDisabled}>${esc(tEntry('delete'))}</button>
        </div>
      `
      : '';

    return `
      <div class="child">
        <div><strong>${esc(tEntry('student_label'))}</strong> ${shownChild ? esc(shownChild) : '<span class="muted">—</span>'}</div>
        ${actionsHtml}
      </div>
    `;
  }

  function resolveMergeWithChild(reportId, fieldId, nextValue){
    const f = state.fieldMap[String(fieldId)];
    if (!f || !f.child || !f.child.id) return String(nextValue ?? '');

    const type = String(f.field_type || '');
    if (type !== 'multiline' && type !== 'text' && Number(f.is_multiline || 0) !== 1) {
      return String(nextValue ?? '');
    }

    const childRaw = childVal(reportId, f.child.id);
    if (!childRaw) return String(nextValue ?? '');

    const key = mergeDecisionKey(reportId, fieldId);
    const entry = ui.mergeDecisions.get(key);

    // If the current teacher value already contains the child text (e.g. from a
    // prior merge on another device), consider it combined and remember it to
    // avoid duplicate prompts and repeated concatenation.
    const ownTrimmed = String(nextValue ?? '').trim();
    const baseTrimmed = String(childRaw).trim();
    if (!entry && ownTrimmed && baseTrimmed && ownTrimmed.includes(baseTrimmed)) {
      const autoCombined = { decision: 'combine', settled: true };
      ui.mergeDecisions.set(key, autoCombined);
      const mem = readMergeMemory();
      mem[key] = autoCombined;
      writeMergeMemory(mem);
      return ownTrimmed;
    }

    if (entry && entry.settled) {
      return String(nextValue ?? '');
    }

    let decision = entry?.decision;

    if (!decision) {
      const msg = [
        tEntry('merge_prompt_title'),
        '',
        childDisplay(f, childRaw) || childRaw,
        '',
        tEntry('merge_prompt_choice')
      ].join('\n');
      decision = window.confirm(msg) ? 'combine' : 'overwrite';
    }

    const finalEntry = { decision, settled: true };
    ui.mergeDecisions.set(key, finalEntry);
    const mem = readMergeMemory();
    mem[key] = finalEntry;
    writeMergeMemory(mem);

    if (decision === 'combine') {
      const own = String(nextValue ?? '').trim();
      const base = String(childRaw).trim();
      if (!own) return base;
      if (own === base) return base;
      return `${base} · ${own}`;
    }

    return String(nextValue ?? '');
  }

  function ensureDatalistForField(fieldId){
    const f = state.fieldMap[String(fieldId)];
    if (!f) return;
    const type = String(f.field_type || '');
    if (!(type === 'radio' || type === 'select' || type === 'grade')) return;
    const dlId = `dl_${String(fieldId)}`;

    let dl = document.getElementById(dlId);
    if (!dl) {
      dl = document.createElement('datalist');
      dl.id = dlId;
      document.body.appendChild(dl);
    }

    const opts = Array.isArray(f.options) ? f.options : [];
    const items = [];

    opts.forEach(o => {
      const v = String(o?.value ?? '').trim();
      const ld = String(o?.label ?? '').trim();
      const le = String(o?.label_en ?? '').trim();

      const labelShown = (UI_LANG === 'en' && le) ? le : (ld || v);

      // allow typing the canonical value
      if (v) items.push({ value: v, label: labelShown || v });

      // allow typing DE label
      if (ld && ld !== v) items.push({ value: ld, label: ld });

      // allow typing EN label
      if (le && le !== v && le !== ld) items.push({ value: le, label: le });
    });

    dl.innerHTML = '';
    items.forEach(it => {
      const op = document.createElement('option');
      op.value = it.value;
      op.textContent = it.label;
      dl.appendChild(op);
    });
  }

  function resolveTypedToValue(f, typed){
    const t = String(typed ?? '').trim();
    if (!t) return { value: '', valid: true };
    const opts = Array.isArray(f?.options) ? f.options : [];

    // exact value
    const hitV = opts.find(o => String(o?.value ?? '') === t);
    if (hitV) return { value: String(hitV.value ?? t), valid: true };

    const low = t.toLowerCase();

    // match DE label
    const hitLD = opts.find(o => String(o?.label ?? '').toLowerCase() === low);
    if (hitLD) return { value: String(hitLD.value ?? t), valid: true };

    // ✅ NEW: match EN label
    const hitLE = opts.find(o => String(o?.label_en ?? '').toLowerCase() === low);
    if (hitLE) return { value: String(hitLE.value ?? t), valid: true };

    return { value: t, valid: false };
  }

  function buildFieldNameIndex(){
    const idx = new Map();

    // teacher fields (in groups)
    (state.groups || []).forEach(g => {
      (g.fields || []).forEach(f => {
        const n = String(f.field_name || '').trim();
        if (!n) return;
        idx.set(n, f);
        idx.set(n.toLowerCase(), f);
      });
    });

    // class fields (not in groups)
    if (state.class_fields && Array.isArray(state.class_fields.fields)) {
      state.class_fields.fields.forEach(f => {
        const n = String(f.field_name || '').trim();
        if (!n) return;
        idx.set(n, f);
        idx.set(n.toLowerCase(), f);
      });
    }

    return idx;
  }

  function resolveLabelTemplate(tpl){
    const s = String(tpl ?? '');
    if (!s || s.indexOf('{{') === -1) return s;

    const idx = buildFieldNameIndex();

    // values come from class report instance (only class-wide interpolation!)
    const rid = classReportId();
    const classValueByName = (state.class_fields && state.class_fields.value_by_name) ? state.class_fields.value_by_name : {};

    return s.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, rawTok) => {
      const token = String(rawTok || '').trim();
      if (!token) return '';

      let kind = 'field';
      let key = token;
      const p = token.indexOf(':');
      if (p !== -1) {
        kind = token.slice(0, p).trim().toLowerCase();
        key = token.slice(p + 1).trim();
      }
      if (!key) return '';

      // 1) fastest: value_by_name from API (exact field_name)
      if (kind === 'field' || kind === 'value') {
        if (classValueByName && Object.prototype.hasOwnProperty.call(classValueByName, key)) {
          return String(classValueByName[key] ?? '');
        }

        // 2) try case-insensitive field lookup -> then read value via teacherVal (will use class report for class fields)
        const ref = idx.get(key) || idx.get(key.toLowerCase());
        if (ref && ref.id) {
          const raw = teacherVal(rid, Number(ref.id));
          return teacherDisplay(ref, raw);
        }
      }

      return '';
    });
  }

  function buildChildGroups(){
    const list = [];
    (state.groups || []).forEach(g => {
      const fields = [];
      (g.fields || []).forEach(f => {
        if (!f || !f.child || !f.child.id) return;
        const child = f.child;
        const canEditField = Number(f.can_edit || 0) === 1;
        const groupEditable = Number(g.can_edit || 0) === 1;
        fields.push({
          id: Number(child.id),
          field_name: String(child.field_name || f.field_name || ''),
          field_type: String(child.field_type || ''),
          label: String(child.label || f.label || f.field_name || ''),
          help_text: String(child.help_text || ''),
          is_multiline: Number(child.is_multiline || 0),
          options: Array.isArray(child.options) ? child.options : [],
          subgroup: String(f.subgroup || ''),
          subgroup_title_en: String(f.subgroup_title_en || ''),
          can_edit: (canEditField && groupEditable) ? 1 : 0,
        });
      });
      if (!fields.length) return;
      list.push({
        key: g.key,
        title: g.title,
        fields,
        can_edit: fields.some(f => Number(f.can_edit || 0) === 1) ? 1 : 0,
        delegation: g.delegation || null,
      });
    });
    return list;
  }

  function rebuildFieldMap(){
    const map = {};
    const groups = activeGroups();
    groups.forEach(g => {
      const delegatedUserIds = Array.isArray(g?.delegation?.user_ids)
        ? g.delegation.user_ids.map(x => Number(x)).filter(x => x > 0)
        : [];
      (g.fields || []).forEach(f => { map[String(f.id)] = { ...f, _group_key: g.key, _delegated_user_ids: delegatedUserIds }; });
    });
    if (!CHILD_MODE) {
      // class fields are NOT in groups (by design), so add them too:
      if (state.class_fields && Array.isArray(state.class_fields.fields)) {
        state.class_fields.fields.forEach(f => { map[String(f.id)] = f; });
      }
    }
    state.fieldMap = map;
  }

  function friendlyFetchError(err, saveContext=false){
    const code = String(err?.code || err?.error || '');
    const status = Number(err?.status || 0);
    if (code === 'timeout') return tEntry('ajax_timeout');
    if (code === 'csrf_failed' || code === 'session_expired' || status === 401) return tEntry('ajax_session_expired');
    if (code === 'invalid_json') return tEntry('ajax_invalid_json');
    if (code === 'non_json') return tEntry('ajax_non_json');
    if (code === 'network') return tEntry('ajax_network_error');
    if (saveContext) return tEntry('save_failed_unsaved');
    return String(err?.message || tEntry('ajax_request_failed'));
  }

  function isRetryableSaveError(err){
    if (typeof err?.retryable === 'boolean') return !!err.retryable;
    const status = Number(err?.status || 0);
    const code = String(err?.code || '').toLowerCase();
    if ([401, 403, 404, 409, 422].includes(status)) return false;
    if (status >= 500 || status === 0) return true;
    return ['network','timeout','invalid_json','non_json'].includes(code);
  }

  function retryDelayForAttempt(attempt){
    const delays = [2000, 5000, 10000, 20000, 30000];
    return delays[Math.min(Math.max(0, attempt - 1), delays.length - 1)];
  }

  async function apiFetchJson(url, payload, options = {}){
    const controller = new AbortController();
    const timeoutMs = Number(options.timeoutMs || 30000);
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const res = await fetch(url, {
        method: options.method || 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(options.headers || {}) },
        body: payload !== null && typeof payload !== 'undefined' ? JSON.stringify(payload) : undefined,
        keepalive: !!options.keepalive,
        signal: controller.signal,
      });
      const contentType = String(res.headers.get('content-type') || '').toLowerCase();
      const text = await res.text();
      let json = null;
      if (contentType.includes('application/json')) {
        try { json = text ? JSON.parse(text) : null; } catch (parseErr) {
          const err = new Error(tEntry('ajax_invalid_json'));
          err.code = 'invalid_json';
          err.status = res.status;
          err.bodySnippet = text.slice(0, 300);
          err.retryable = res.status >= 500 || res.status === 0 || res.status === 200;
          throw err;
        }
      } else if (text.trim().startsWith('{') || text.trim().startsWith('[')) {
        try { json = JSON.parse(text); } catch (parseErr) { /* handled below as non-json */ }
      }
      if (!json) {
        const err = new Error(tEntry('ajax_non_json'));
        err.code = 'non_json';
        err.status = res.status;
        err.bodySnippet = text.slice(0, 300);
        err.retryable = res.status >= 500 || res.status === 0 || res.status === 200;
        throw err;
      }
      if (!res.ok || json.ok === false) {
        const err = new Error(String(json.message || json.error || tEntry('ajax_request_failed')));
        err.code = String(json.error || 'http_error');
        err.status = res.status;
        err.bodySnippet = text.slice(0, 300);
        err.retryable = typeof json.retryable === 'boolean' ? !!json.retryable : isRetryableSaveError(err);
        throw err;
      }
      return json;
    } catch (err) {
      if (err && err.name === 'AbortError') {
        err.code = 'timeout';
        err.status = 0;
        err.retryable = true;
        err.message = tEntry('ajax_timeout');
      } else if (!err.code) {
        err.code = 'network';
        err.status = 0;
        err.retryable = true;
        err.message = tEntry('save_connection_interrupted');
      }
      console.warn('entry api request failed', { code: err.code, status: err.status || 0, body: err.bodySnippet || '' });
      throw err;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  async function api(action, payload, options = {}){
    const delegated = DELEGATED_MODE ? { delegated: 1 } : {};
    return apiFetchJson(apiUrl, { action, csrf_token: csrf, ...delegated, ...payload }, options);
  }

  function fireAndForget(action, payload){
    const delegated = DELEGATED_MODE ? { delegated: 1 } : {};
    const body = JSON.stringify({ action, csrf_token: csrf, ...delegated, ...payload });
    if (navigator.sendBeacon) {
      const blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(apiUrl, blob);
      return;
    }
    fetch(apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body,
      keepalive: true,
    }).catch(()=>{});
  }

  function hasPendingSaves(){
    return ui.pendingPayloads.size > 0 || ui.saveTimers.size > 0 || ui.saveChains.size > 0 || ui.retryTimers.size > 0 || ui.saveInFlight > 0;
  }

  function setNavigationLocked(on){
    ui.navigationLocked = !!on;
    [btnPrevStudent, btnNextStudent, viewSelect, groupSelect, gradeGroupSelect, studentGroupSelect, gradeOrientation].forEach(el => {
      if (!el) return;
      el.disabled = !!on;
    });
  }

  function markSaveDirty(){
    setSaveStatus('dirty', tEntry('save_dirty'));
  }

  function clearSaveRetry(key){
    if (ui.retryTimers.has(key)) {
      clearTimeout(ui.retryTimers.get(key));
      ui.retryTimers.delete(key);
    }
  }

  function scheduleSaveRetry(key){
    if (!ui.pendingPayloads.has(key) || ui.retryTimers.has(key)) return;
    const attempt = (ui.retryAttempts.get(key) || 0) + 1;
    ui.retryAttempts.set(key, attempt);
    const delay = retryDelayForAttempt(attempt);
    setSaveStatus('retrying', tEntry('save_retry_failed_auto'));
    ui.retryTimers.set(key, setTimeout(() => {
      ui.retryTimers.delete(key);
      if (!ui.pendingPayloads.has(key) || ui.saveChains.has(key)) return;
      setSaveStatus('retrying', tEntry('save_retrying'));
      void runQueuedSave(key);
    }, delay));
  }

  async function runQueuedSave(key){
    if (ui.saveChains.has(key)) return ui.saveChains.get(key);
    const chain = (async () => {
      try {
        while (ui.pendingPayloads.has(key)) {
          clearSaveRetry(key);
          const info = ui.pendingPayloads.get(key);
          ui.pendingPayloads.delete(key);
          ui.saveInFlight++;
          setSaving(true);
          setSaveStatus('saving', tEntry('save_saving'));
          try {
            const res = await api(info.action, info.payload);
            const hasNewerPending = ui.pendingPayloads.has(key);
            if (!hasNewerPending && typeof info.onSuccess === 'function') info.onSuccess(res);
            ui.retryAttempts.delete(key);
            clearErr();
            lastSaveAt = new Date();
            setSaveStatus('ok', tfmtEntry('save_saved_at', { time: formatTime(lastSaveAt) }));
          } catch (e) {
            if (!ui.pendingPayloads.has(key)) ui.pendingPayloads.set(key, info);
            const msg = friendlyFetchError(e, true);
            if (isRetryableSaveError(e)) {
              showErr(tEntry('save_retry_failed_auto'));
              setSaveStatus('retrying', tEntry('save_retry_failed_auto'));
              scheduleSaveRetry(key);
            } else {
              showErr(tfmtEntry('save_permanent_error', { msg }));
              setSaveStatus('failed_permanent', tfmtEntry('save_permanent_error', { msg }));
            }
            return false;
          } finally {
            ui.saveInFlight = Math.max(0, ui.saveInFlight - 1);
            if (ui.saveInFlight === 0) setSaving(false);
          }
        }
        return true;
      } finally {
        ui.saveChains.delete(key);
      }
    })();
    ui.saveChains.set(key, chain);
    return chain;
  }

  function queueSave(key, action, payload, onSuccess, delayMs=350){
    if (ui.saveTimers.has(key)) clearTimeout(ui.saveTimers.get(key));
    ui.pendingPayloads.set(key, { action, payload, onSuccess });
    markSaveDirty();
    ui.saveTimers.set(key, setTimeout(() => {
      ui.saveTimers.delete(key);
      void runQueuedSave(key);
    }, delayMs));
  }

  async function flushPendingSavesBlocking(){
    ui.saveTimers.forEach((timer, key) => {
      clearTimeout(timer);
      ui.saveTimers.delete(key);
    });
    ui.retryTimers.forEach((timer) => clearTimeout(timer));
    ui.retryTimers.clear();
    const keys = new Set([...ui.pendingPayloads.keys(), ...ui.saveChains.keys()]);
    if (!keys.size) return true;
    const results = await Promise.all([...keys].map(key => runQueuedSave(key)));
    const ok = results.every(Boolean) && ui.pendingPayloads.size === 0;
    if (!ok) setSaveStatus('retrying', tEntry('save_retry_failed_auto'));
    return ok;
  }

  async function withSavedChanges(fn){
    if (ui.navigationLocked) return;
    setNavigationLocked(true);
    try {
      const ok = await flushPendingSavesBlocking();
      if (!ok) return;
      await fn();
    } finally {
      setNavigationLocked(false);
    }
  }

  function flushPendingSaves(){
    if (!ui.pendingPayloads.size) return;
    ui.retryTimers.forEach((timer) => clearTimeout(timer));
    ui.retryTimers.clear();
    ui.pendingPayloads.forEach((info, key) => {
      if (!info) return;
      if (ui.saveTimers.has(key)) {
        clearTimeout(ui.saveTimers.get(key));
        ui.saveTimers.delete(key);
      }
      fireAndForget(info.action, info.payload);
    });
  }

  function showErr(msg){
    elErrMsg.textContent = msg;
    elErrBox.style.display = 'block';
  }
  function clearErr(){
    elErrBox.style.display = 'none';
    elErrMsg.textContent = '';
  }
  function formatTime(ts){
    const d = ts instanceof Date ? ts : new Date(ts ?? Date.now());
    return d.toLocaleTimeString('de-DE', { hour:'2-digit', minute:'2-digit' });
  }
  function formatDateTime(ts){
    const d = ts instanceof Date ? ts : new Date(ts ?? Date.now());
    return d.toLocaleString('de-DE', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }
  function setSaveStatus(state, text){
    if (!elSaveStatus) return;
    elSaveStatus.textContent = text || '';
    elSaveStatus.dataset.state = state || 'idle';
    elSaveStatus.style.display = text ? 'flex' : 'none';
  }
  function setSaving(on){
    elSavePill.style.display = on ? 'inline-flex' : 'none';
  }
  function setLoading(on){
    if (!loadingOverlay) return;
    loadingOverlay.style.display = on ? 'flex' : 'none';
  }

  function renderWithLoading(){
    setLoading(true);
    requestAnimationFrame(() => {
      render();
      requestAnimationFrame(() => setLoading(false));
    });
  }

  function normalizeViewMode(mode){
    const m = String(mode || '').toLowerCase();
    if (CHILD_MODE && m === 'grades') return 'student';
    if (m === 'student' || m === 'item' || m === 'grades') return m;
    return 'grades';
  }

  function applyStoredView(){
    if (!viewSelect) return;
    if (MEETING_MODE) {
      viewSelect.value = 'student';
      return;
    }
    const saved = normalizeViewMode(localStorage.getItem(VIEW_STORAGE_KEY));
    viewSelect.value = saved;
  }

  function updateSwitchLinks(){
    if (!classSelect) return;
    const classId = Number(classSelect.value || 0);
    document.querySelectorAll('[data-switch-view="teacher"]').forEach(link => {
      const base = <?=json_encode(url('teacher/entry.php'))?>;
      link.href = classId > 0 ? `${base}?class_id=${classId}` : base;
    });
    document.querySelectorAll('[data-switch-view="child"]').forEach(link => {
      const base = <?=json_encode(url('teacher/child_entry.php'))?>;
      link.href = classId > 0 ? `${base}?class_id=${classId}` : base;
    });
    updateMeetingLink(classId);
  }

  function updateMeetingLink(classIdOverride){
    const meetingLink = document.getElementById('btnMeetingView');
    if (!meetingLink) return;
    const classId = Number(classIdOverride ?? classSelect?.value ?? 0);
    const base = <?=json_encode(url('teacher/entry.php'))?>;
    const params = new URLSearchParams();
    params.set('meeting', '1');
    if (classId > 0) params.set('class_id', String(classId));
    meetingLink.href = `${base}?${params.toString()}`;
  }

  function saveViewSelection(){
    if (!viewSelect) return;
    const value = normalizeViewMode(viewSelect.value);
    viewSelect.value = value;
    localStorage.setItem(VIEW_STORAGE_KEY, value);
  }

  async function unlockChildEntry(reportId){
    if (!reportId) return;

    try {
      setSaveStatus('saving', tEntry('save_child_unlocking'));
      const res = await api('unlock_child_entry', { report_instance_id: reportId });
      if (res && res.status) {
        const hit = (state.students || []).find(s => Number(s.report_instance_id || 0) === Number(reportId));
        if (hit) hit.status = String(res.status);
        renderStudentView();
      }
      setSaveStatus('ok', tEntry('save_child_unlocked'));
    } catch (e) {
      console.error(e);
      showErr(friendlyFetchError(e));
      setSaveStatus('error', tEntry('save_child_unlock_error'));
    }
  }

  function isClassFieldId(fieldId){
    const cf = state.class_fields;
    if (!cf || !Array.isArray(cf.field_ids)) return false;
    return cf.field_ids.includes(Number(fieldId));
  }

  function classReportId(){
    return Number(state.class_report_instance_id || 0);
  }

  function combineTextParts(classText, delegateText){
    const ct = String(classText ?? '').replace(/\s+$/, '');
    const dt = String(delegateText ?? '').replace(/\s+$/, '');
    const parts = [];
    if (ct.trim() !== '') parts.push(ct);
    if (dt.trim() !== '') parts.push(dt);
    return parts.join('\n\n');
  }

  function combineTextPartsHtml(classText, delegateText, highlightDelegate){
    const ct = String(classText ?? '').replace(/\s+$/, '');
    const dt = String(delegateText ?? '').replace(/\s+$/, '');
    const parts = [];
    if (ct.trim() !== '') parts.push(esc(ct).replace(/\n/g, '<br>'));
    if (dt.trim() !== '') {
      const raw = esc(dt).replace(/\n/g, '<br>');
      parts.push(highlightDelegate ? `<span class="delegate-part">${raw}</span>` : raw);
    }
    return parts.join('<br><br>');
  }

  function teacherVal(reportId, fieldId){
    if (isClassFieldId(fieldId)) {
      const rid = classReportId();
      const parts = state.values_teacher_parts[String(rid)]?.[String(fieldId)];
      if (parts) {
        return combineTextParts(parts.class_text ?? '', parts.delegate_text ?? '');
      }
      const r = state.values_teacher[String(rid)] || {};
      const v = r[String(fieldId)];
      return (v === null || typeof v === 'undefined') ? '' : String(v);
    }
    const parts = state.values_teacher_parts[String(reportId)]?.[String(fieldId)];
    if (parts) {
      return combineTextParts(parts.class_text ?? '', parts.delegate_text ?? '');
    }
    const r = state.values_teacher[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? '' : String(v);
  }

  function teacherEditVal(reportId, fieldId){
    const part = delegatedEditPart(fieldId);
    if (part) {
      const rid = isClassFieldId(fieldId) ? classReportId() : Number(reportId || 0);
      const parts = state.values_teacher_parts[String(rid)]?.[String(fieldId)];
      if (parts && typeof parts === 'object') {
        if (part === 'delegate' && parts.delegate_texts && typeof parts.delegate_texts === 'object') {
          const own = parts.delegate_texts[String(CURRENT_USER_ID)];
          return String(own ?? '');
        }
        return part === 'delegate'
          ? String(parts.delegate_text ?? '')
          : String(parts.class_text ?? '');
      }
      return '';
    }
    if (isClassFieldId(fieldId)) {
      const rid = classReportId();
      const r = state.values_teacher_own[String(rid)] || {};
      const v = r[String(fieldId)];
      return (v === null || typeof v === 'undefined') ? teacherVal(reportId, fieldId) : String(v);
    }
    const r = state.values_teacher_own[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? teacherVal(reportId, fieldId) : String(v);
  }

  function delegatedEditPart(fieldId){
    const f = state.fieldMap?.[String(fieldId)];
    if (!f) return null;
    if (!isFreeTextField(f)) return null;
    const delegatedUserIds = Array.isArray(f._delegated_user_ids)
      ? f._delegated_user_ids.map(x => Number(x)).filter(x => x > 0)
      : [];
    if (!delegatedUserIds.length) return null;
    const isDelegate = delegatedUserIds.includes(CURRENT_USER_ID) && !state.is_class_teacher;
    return isDelegate ? 'delegate' : 'class';
  }

  function setTeacherFreeTextPart(reportId, fieldId, value){
    const part = delegatedEditPart(fieldId);
    if (!part) return false;

    const ridKey = String(reportId);
    const fidKey = String(fieldId);
    if (!state.values_teacher_parts[ridKey]) state.values_teacher_parts[ridKey] = {};
    const existing = state.values_teacher_parts[ridKey][fidKey] || {};
    const delegatedUserIds = Array.isArray(state.fieldMap?.[String(fieldId)]?._delegated_user_ids)
      ? state.fieldMap[String(fieldId)]._delegated_user_ids.map(x => Number(x)).filter(x => x > 0)
      : [];
    const delegatedUserId = delegatedUserIds.length === 1 ? delegatedUserIds[0] : 0;
    const next = {
      class_text: existing.class_text ?? '',
      delegate_text: existing.delegate_text ?? '',
      delegate_texts: (existing.delegate_texts && typeof existing.delegate_texts === 'object') ? { ...existing.delegate_texts } : {},
      delegate_user_id: delegatedUserId,
    };
    if (part === 'delegate') {
      next.delegate_texts[String(CURRENT_USER_ID)] = String(value ?? '');
      next.delegate_text = Object.values(next.delegate_texts)
        .map(x => String(x ?? '').trim())
        .filter(x => x !== '')
        .join('\n\n');
    } else {
      next.class_text = String(value ?? '');
    }
    state.values_teacher_parts[ridKey][fidKey] = next;

    if (!state.values_teacher_own[ridKey]) state.values_teacher_own[ridKey] = {};
    state.values_teacher_own[ridKey][fidKey] = String(value ?? '');

    if (!state.values_teacher[ridKey]) state.values_teacher[ridKey] = {};
    state.values_teacher[ridKey][fidKey] = combineTextParts(next.class_text, next.delegate_text);
    return true;
  }

  function isFreeTextField(f){
    const t = String(f.field_type || 'text').toLowerCase();
    if (t === 'multiline' || t === 'text') return true;
    return Number(f.is_multiline || 0) === 1;
  }

  function delegatedPeerInputsHtml(parts){
    if (!parts || typeof parts !== 'object') return '';
    const entries = [];
    const classText = String(parts.class_text ?? '').trim();
    if (classText !== '') {
      entries.push({ label: 'Klassenlehrkraft', text: classText });
    }
    const delegateTexts = (parts.delegate_texts && typeof parts.delegate_texts === 'object') ? parts.delegate_texts : {};
    const userNameById = new Map((state.delegation_users || []).map(u => [String(u.id), String(u.name || `Nutzer #${u.id}`)]));
    Object.entries(delegateTexts).forEach(([uid, txt]) => {
      if (String(uid) === String(CURRENT_USER_ID)) return;
      const text = String(txt ?? '').trim();
      if (text === '') return;
      entries.push({ label: userNameById.get(String(uid)) || `Nutzer #${uid}`, text });
    });
    const body = entries.length
      ? entries.map(item => `<div class="combined-inline-entry"><strong>${esc(item.label)}</strong><br>${esc(item.text).replace(/\n/g, '<br>')}</div>`).join('')
      : '<span class="muted">Noch keine Eingaben von Kolleg:innen vorhanden.</span>';
    return `
      <div class="combined-inline">
        <div class="combined-inline-label">Eingaben der Kolleg:innen</div>
        <div class="combined-inline-text">${body}</div>
      </div>
    `;
  }

  function combinedPreviewHtml(reportId, field){
    if (!field) return '';
    if (!isFreeTextField(field)) return '';
    const delegatedUserIds = Array.isArray(field._delegated_user_ids)
      ? field._delegated_user_ids.map(x => Number(x)).filter(x => x > 0)
      : (Array.isArray(state.fieldMap?.[String(field.id)]?._delegated_user_ids)
        ? state.fieldMap[String(field.id)]._delegated_user_ids.map(x => Number(x)).filter(x => x > 0)
        : []);
    if (!delegatedUserIds.length) return '';
    const rid = isClassFieldId(field.id) ? classReportId() : reportId;
    const parts = state.values_teacher_parts[String(rid)]?.[String(field.id)] || null;
    const hasParts = !!parts;
    const highlightDelegate = !DELEGATED_MODE && !!(state.is_class_teacher);
    const html = hasParts
      ? combineTextPartsHtml(parts.class_text ?? '', parts.delegate_text ?? '', highlightDelegate)
      : (() => {
          const combined = teacherVal(reportId, field.id);
          return combined ? esc(String(combined)).replace(/\n/g, '<br>') : '<span class="muted">—</span>';
        })();
    if (DELEGATED_MODE) {
      return delegatedPeerInputsHtml(parts || {});
    }
    return `
      <span class="combined-tip" data-tip="1">
        <button type="button" class="btn ghost icon combined-tip-btn js-combined-tip" aria-label="Gesamtwert anzeigen">👥</button>
        <span class="combined-tip-bubble">${html}</span>
      </span>
    `;
  }

  function childVal(reportId, fieldId){
    const r = state.values_child[String(reportId)] || {};
    const v = r[String(fieldId)];
    return (v === null || typeof v === 'undefined') ? '' : String(v);
  }

  function historyEntries(reportId, fieldId){
    const rid = String(reportId ?? '');
    const fid = String(fieldId ?? '');
    const map = state.value_history || {};
    const list = (map[rid] && Array.isArray(map[rid][fid])) ? map[rid][fid] : [];
    return list;
  }

  function closeHistoryMenus(except){
    document.querySelectorAll('[data-history-wrap="1"].open').forEach(w => {
      if (except && w === except) return;
      w.classList.remove('open');
    });
  }

  function addHistoryEntry(reportId, fieldId, text, source, valueText = null, valueJson = null){
    const rid = String(reportId ?? '');
    const fid = String(fieldId ?? '');
    if (!state.value_history) state.value_history = {};
    if (!state.value_history[rid]) state.value_history[rid] = {};
    if (!state.value_history[rid][fid]) state.value_history[rid][fid] = [];

    const list = state.value_history[rid][fid];

    const prev = list[0];
    const sameVal = prev
      && prev.value_text === valueText
      && prev.value_json === valueJson;
    if (sameVal) {
      prev.text = text ?? '';
      prev.source = source || 'teacher';
      prev.created_at = new Date().toISOString();
      return;
    }

    list.unshift({
      text: text ?? '',
      source: source || 'teacher',
      created_at: new Date().toISOString(),
      value_text: valueText,
      value_json: valueJson,
    });

    if (list.length > 5) list.length = 5;
  }

  function adjustChildProgress(reportId, label, prevRaw, nextRaw){
    const stu = (state.students || []).find(s => Number(s.report_instance_id || 0) === Number(reportId));
    if (!stu) return;

    const wasDone = String(prevRaw ?? '').trim() !== '';
    const isDone = String(nextRaw ?? '').trim() !== '';
    if (wasDone === isDone) return;

    const total = Number(stu.progress_child_total || 0);
    let done = Number(stu.progress_child_done || 0);
    done = wasDone && !isDone ? Math.max(0, done - 1) : done;
    done = !wasDone && isDone ? done + 1 : done;
    stu.progress_child_done = Math.min(total, Math.max(0, done));
    stu.progress_child_missing = Math.max(0, total - stu.progress_child_done);

    const teacherDone = Number(stu.progress_teacher_done || 0);
    const overallTotal = Number(stu.progress_overall_total || 0);
    stu.progress_overall_done = teacherDone + stu.progress_child_done;
    stu.progress_overall_missing = Math.max(0, overallTotal - stu.progress_overall_done);
    stu.progress_is_complete = stu.progress_overall_missing === 0;

    const lbl = String(label || '').trim();
    if (lbl !== '') {
      const missingList = Array.isArray(stu.child_missing_fields) ? [...stu.child_missing_fields] : [];
      const inList = missingList.includes(lbl);
      const updatedList = isDone ? missingList.filter(x => x !== lbl) : (inList ? missingList : missingList.concat([lbl]));
      stu.child_missing_fields = updatedList;
    }
  }

  async function updateChildValue(reportId, childFieldId, nextValue, childLabelText, opts = {}){
    const rid = Number(reportId || 0);
    const fid = Number(childFieldId || 0);
    if (!rid || !fid) return;

    const prevRaw = childVal(rid, fid);
    const deleting = nextValue === null || typeof nextValue === 'undefined';
    const shouldRender = opts.render !== false;

    try {
      setSaveStatus('saving', deleting ? tEntry('save_child_deleting') : tEntry('save_child_updating'));
      const res = await api('child_value_update', {
        report_instance_id: rid,
        child_field_id: fid,
        value_text: deleting ? null : String(nextValue ?? ''),
      });

      const ridKey = String(rid);
      const fidKey = String(fid);
      if (!state.values_child[ridKey]) state.values_child[ridKey] = {};
      state.values_child[ridKey][fidKey] = res.value_text ?? '';

      addHistoryEntry(rid, fid, res.value_text || '', 'child', res.raw_value_text ?? null, res.value_json ?? null);
      adjustChildProgress(rid, childLabelText || '', prevRaw, res.raw_value_text ?? (deleting ? '' : nextValue));

      if (shouldRender) {
        render();
      } else {
        onChildValueChanged(rid);
      }
      setSaveStatus('ok', deleting ? tEntry('save_child_deleted') : tEntry('save_child_updated'));
    } catch (e) {
      console.error(e);
      showErr(friendlyFetchError(e));
      setSaveStatus('error', tEntry('save_child_save_error'));
    }
  }

  function wireChildValueControls(root){
    const cont = root || document;

    cont.querySelectorAll('[data-edit-child]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-edit-child') || 0);
        const fid = Number(btn.getAttribute('data-child-field') || 0);
        const lbl = String(btn.getAttribute('data-child-label') || tEntry('field_fallback'));
        const current = childVal(rid, fid);
        const next = window.prompt(tfmtEntry('prompt_new_child_value', { label: lbl }), current);
        if (next === null) return;
        await updateChildValue(rid, fid, next, lbl);
      });
    });

    cont.querySelectorAll('[data-delete-child]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-delete-child') || 0);
        const fid = Number(btn.getAttribute('data-child-field') || 0);
        const lbl = String(btn.getAttribute('data-child-label') || tEntry('field_fallback'));
        const confirmMsg = String(CHILD_CLEAR_CONFIRM || tfmtEntry('prompt_clear_child_value', { label: lbl }))
          .replace('{label}', lbl);
        if (!window.confirm(confirmMsg)) return;
        await updateChildValue(rid, fid, null, lbl);
      });
    });
  }

  function renderHistoryHtml(reportId, fieldId){
    const entries = historyEntries(reportId, fieldId);
    if (!entries.length) return '';

      const rows = entries.map(e => {
        const role = (e.source === 'child') ? tEntry('role_child') : tEntry('role_teacher');
        const ts = formatDateTime(e.created_at);
        const val = String(e.text ?? '');
        return `
          <div class="history-row">
            <div class="history-meta"><span>${esc(role)}</span><span>${esc(ts)}</span></div>
            <div class="history-val">${val ? esc(val) : '<span class="muted">—</span>'}</div>
            <div class="history-actions">
            <button class="btn tiny secondary" type="button" tabindex="-1"
              style="padding:4px 8px; font-size:11px;"
              data-history-restore="1"
              data-report-id="${esc(reportId)}"
              data-field-id="${esc(fieldId)}"
              data-value-text="${esc(e.value_text ?? '')}"
              data-value-json="${esc(e.value_json ?? '')}"
            >↩︎</button>
          </div>
        </div>
      `;
    }).join('');

    return `
      <div class="history-inline" data-history-wrap="1">
        <button class="btn ghost icon" type="button" aria-label="Verlauf anzeigen" tabindex="-1"
          data-history-toggle="1" data-report-id="${esc(reportId)}" data-field-id="${esc(fieldId)}">🕒</button>
        <div class="history-popover" data-history-menu="1">
          <div class="history-rows">${rows}</div>
        </div>
      </div>
    `;
  }

  function applyHistoryValue(reportId, fieldId, valueText, valueJson){
    const rid = Number(reportId);
    const fid = Number(fieldId);
    const f = state.fieldMap?.[String(fid)];
    const rawVal = valueText ?? '';

    const inputs = document.querySelectorAll(`[data-teacher-input="1"][data-report-id="${CSS.escape(String(rid))}"][data-field-id="${CSS.escape(String(fid))}"]`);
    if (!inputs.length) return;

    inputs.forEach(inp => {
      const merged = resolveMergeWithChild(rid, fid, rawVal);

      if (inp.dataset.combo === '1') {
        inp.dataset.actual = merged;
        inp.value = f ? teacherDisplay(f, merged) : String(merged ?? '');
      } else if (inp.type === 'checkbox') {
        inp.checked = String(merged) === '1';
      } else {
        inp.value = String(merged ?? '');
      }

      if (isClassFieldId(fid)) scheduleSaveClass(fid, merged);
      else scheduleSave(rid, fid, merged);
    });
  }

  function syncMissingClass(inp, value){
    if (!inp) return;
    const wrap = inp.closest('.cellWrap') || inp.closest('.field');
    if (!wrap) return;
    const missing = String(value ?? '').trim() === '';
    wrap.classList.toggle('missing', missing);
  }

  // --- progress helpers ---
  function teacherProgressFieldIds(){
    const ids = [];
    activeGroups().forEach(g => {
      (g.fields || []).forEach(f => {
        if (!isEditableField(f, g)) return;
        ids.push(Number(f.id));
      });
    });
    return ids;
  }

  function computeDoneFromTeacherValues(reportId, fieldIds){
    let done = 0;
    fieldIds.forEach(fid => {
      const v = activeFieldValue(reportId, fid);
      if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') done++;
    });
    return done;
  }

  function findStudentByReportId(reportId){
    return (state.students || []).find(s => Number(s.report_instance_id) === Number(reportId)) || null;
  }

  function recomputeStudentProgress(student){
    if (!student) return;

    if (CHILD_MODE) {
      const prog = activeProgressForStudent(Number(student.report_instance_id || 0));
      student.progress_child_total = prog.total;
      student.progress_child_done = prog.done;
      student.progress_child_missing = prog.missing;
      student.progress_overall_total = prog.total;
      student.progress_overall_done = prog.done;
      student.progress_overall_missing = prog.missing;
      student.progress_is_complete = prog.complete;
      return;
    }

    // teacher fields = ONLY current state.groups (already filtered in delegated mode)
    const tIds = teacherProgressFieldIds();
    const tTotal = tIds.length;
    const tDone = computeDoneFromTeacherValues(Number(student.report_instance_id || 0), tIds);

    // delegated mode: completion = only delegated teacher fields
    const cTotal = DELEGATED_MODE ? 0 : Number(student.progress_child_total || 0);
    const cDone  = DELEGATED_MODE ? 0 : Number(student.progress_child_done || 0);

    const overallTotal = tTotal + cTotal;
    const overallDone  = tDone + cDone;
    const overallMissing = Math.max(0, overallTotal - overallDone);

    student.progress_teacher_total = tTotal;
    student.progress_teacher_done = tDone;
    student.progress_teacher_missing = Math.max(0, tTotal - tDone);

    student.progress_child_total = cTotal;
    student.progress_child_done = cDone;
    student.progress_child_missing = Math.max(0, cTotal - cDone);

    student.progress_overall_total = overallTotal;
    student.progress_overall_done = overallDone;
    student.progress_overall_missing = overallMissing;

    // in delegated mode, this indicates "my delegated part complete"
    student.progress_is_complete = (overallTotal > 0 && overallMissing === 0);
  }

  function recomputeFormsSummary(){
    const total = (state.students || []).length;
    let complete = 0;
    (state.students || []).forEach(s => { if (s.progress_is_complete) complete++; });

    if (!state.progress_summary) state.progress_summary = {};
    state.progress_summary.students_total = total;
    state.progress_summary.forms_complete = complete;
    state.progress_summary.forms_incomplete = Math.max(0, total - complete);
    state.progress_summary.teacher_fields_total = teacherProgressFieldIds().length;
  }

  function updateFormsProgressUI(){
    if (!formsProgressWrap || !formsProgressBar) return;
    if (MEETING_MODE) {
      formsProgressWrap.style.display = 'none';
      return;
    }

    const total = Number(state.progress_summary?.students_total ?? (state.students || []).length);
    const complete = CHILD_MODE
      ? (state.students || []).filter(s => activeProgressForStudent(Number(s.report_instance_id || 0)).complete).length
      : Number(state.progress_summary?.forms_complete ?? 0);

    if (!total) {
      formsProgressWrap.style.display = 'none';
      return;
    }

    const pct = Math.round((complete / total) * 100);
    formsProgressWrap.style.display = '';
    if (formsProgressText) {
      if (CHILD_MODE) {
        formsProgressText.textContent = tfmtEntry('progress_child_complete', { complete, total });
      } else if (DELEGATED_MODE) {
        formsProgressText.textContent = tfmtEntry('progress_delegated_complete', { complete, total });
      } else {
        formsProgressText.textContent = tfmtEntry('progress_forms_complete', { complete, total });
      }
    }
    if (formsProgressPct) formsProgressPct.textContent = `${pct}%`;
    formsProgressBar.style.width = `${pct}%`;
    formsProgressBar.classList.toggle('ok', complete === total);
  }

  function updateClassFieldsProgressUI(){
    if (!classFieldsProgressWrap || !classFieldsProgressBar) return;

    const cf = state.class_fields;
    const ids = (cf && Array.isArray(cf.field_ids)) ? cf.field_ids : [];
    const rid = classReportId();

    if (!ids.length || !rid) {
      classFieldsProgressWrap.style.display = 'none';
      return;
    }

    let done = 0;
    ids.forEach(fid => {
      const v = teacherVal(rid, Number(fid));
      if (String(v).trim() !== '') done++;
    });

    const total = ids.length;
    const missing = Math.max(0, total - done);
    const pct = Math.round((done / total) * 100);

    classFieldsProgressWrap.style.display = '';
    if (classFieldsProgressText) {
      classFieldsProgressText.textContent = tfmtEntry('progress_class_fields', { done, total, missing });
    }
    if (classFieldsProgressPct) classFieldsProgressPct.textContent = `${pct}%`;
    classFieldsProgressBar.style.width = `${pct}%`;
    classFieldsProgressBar.classList.toggle('ok', missing === 0);
  }

  function shouldShowOpenBreakdown(){
    return !CHILD_MODE && !DELEGATED_MODE && !!state.is_class_teacher;
  }

  function progressBreakdownForStudent(student){
    if (!student) return null;
    const reportId = Number(student.report_instance_id || 0);
    let ownMissing = 0;
    let ownTotal = 0;
    let delegatedMissing = 0;
    let delegatedTotal = 0;
    activeGroups().forEach(g => {
      const delegatedUsers = Array.isArray(g?.delegation?.users) ? g.delegation.users : [];
      const delegatedToOthers = delegatedUsers.length > 0
        && !delegatedUsers.some(u => Number(u?.user_id || 0) === CURRENT_USER_ID);
      (g.fields || []).forEach(f => {
        const raw = delegatedToOthers ? teacherVal(reportId, f.id) : teacherEditVal(reportId, f.id);
        const missing = String(raw ?? '').trim() === '';
        if (!delegatedToOthers && isEditableField(f, g)) {
          ownTotal++;
          if (missing) ownMissing++;
        } else {
          delegatedTotal++;
          if (missing) delegatedMissing++;
        }
      });
    });
    const childTotal = Number(student.progress_child_total || 0);
    const childDone = Math.min(childTotal, Math.max(0, Number(student.progress_child_done || 0)));
    const childMissing = Math.max(0, Number(student.progress_child_missing ?? (childTotal - childDone)));
    const totalMissing = ownMissing + delegatedMissing + childMissing;
    const totalFields = ownTotal + delegatedTotal + childTotal;
    const ownDone = Math.max(0, ownTotal - ownMissing);
    const delegatedDone = Math.max(0, delegatedTotal - delegatedMissing);
    return {
      totalMissing,
      totalFields,
      childMissing,
      childTotal,
      childDone,
      delegatedMissing,
      delegatedTotal,
      delegatedDone,
      ownMissing,
      ownTotal,
      ownDone,
    };
  }

  function updateStudentRowUI(student){
    if (!student) return;
    const row = document.getElementById(`srow-${student.id}`);
    if (!row) return;

    const prog = CHILD_MODE
      ? activeProgressForStudent(Number(student.report_instance_id || 0))
      : {
          total: Number(student.progress_overall_total || 0),
          done: Number(student.progress_overall_done || 0),
          missing: Number(student.progress_overall_missing || 0),
          complete: !!student.progress_is_complete,
        };
    const pct = prog.total > 0 ? Math.round((prog.done / prog.total) * 100) : 0;
    const sub = row.querySelector('.js-srow-sub');
    const breakdown = shouldShowOpenBreakdown() ? progressBreakdownForStudent(student) : null;
    if (sub) {
      const statusLbl = String(sub.getAttribute('data-statuslbl') || '');
      const statusLine = tfmtEntry('progress_status_line', {
        status: statusLbl,
      });
      const breakdownText = breakdown
        ? tfmtEntry('progress_open_breakdown', {
          childDone: breakdown.childDone,
          childTotal: breakdown.childTotal,
          delegatedDone: breakdown.delegatedDone,
          delegatedTotal: breakdown.delegatedTotal,
          ownDone: breakdown.ownDone,
          ownTotal: breakdown.ownTotal,
        })
        : '';
      sub.innerHTML = `${esc(statusLine)}${breakdownText ? ` <span class="muted js-srow-breakdown">${esc(breakdownText)}</span>` : ''}`;
    }

    const bar = row.querySelector('.js-prog-bar');
    if (bar) {
      bar.style.width = `${pct}%`;
      bar.classList.toggle('ok', !!prog.complete);
    }

    const badge = row.querySelector('.js-prog-badge');
    if (badge) {
      const badgeMissing = CHILD_MODE
        ? prog.missing
        : Number(student.progress_teacher_missing || 0);
      badge.textContent = prog.complete ? '✓' : tfmtEntry('progress_badge_open', { missing: badgeMissing });
      badge.classList.toggle('ok', !!prog.complete);
    }
  }

  function updateActiveStudentBadge(){
    const s = activeStudent();
    if (!s || !studentBadge) return;
    if (CHILD_MODE) {
      const prog = activeProgressForStudent(Number(s.report_instance_id || 0));
      const chk = prog.complete ? '✓' : '';
      const breakdown = shouldShowOpenBreakdown() ? progressBreakdownForStudent(s) : null;
      const breakdownText = breakdown
        ? tfmtEntry('progress_open_breakdown', {
          childDone: breakdown.childDone,
          childTotal: breakdown.childTotal,
          delegatedDone: breakdown.delegatedDone,
          delegatedTotal: breakdown.delegatedTotal,
          ownDone: breakdown.ownDone,
          ownTotal: breakdown.ownTotal,
        })
        : '';
      studentBadge.textContent = tfmtEntry('student_badge_child', {
        name: s.name,
        done: Math.max(0, prog.done),
        total: prog.total,
        check: chk,
      }).trim() + (breakdownText ? ` · ${breakdownText}` : '');
      return;
    }
    const tDone = Number(s.progress_teacher_done || 0);
    const tTotal = Number(s.progress_teacher_total || 0);
    const cDone = Number(s.progress_child_done || 0);
    const cTotal = Number(s.progress_child_total || 0);
    const chk = s.progress_is_complete ? '✓' : '';
    const breakdown = shouldShowOpenBreakdown() ? progressBreakdownForStudent(s) : null;
    const revokedSuffix = Number(s.revoked_delegation_comments_count || 0) > 0
      ? ` · ⚠ zurückgezogene Delegationstexte prüfen`
      : '';
    studentBadge.textContent = tfmtEntry('student_badge_both', {
      name: s.name,
      childDone: breakdown?.childDone ?? Math.max(0, cDone),
      childTotal: breakdown?.childTotal ?? cTotal,
      delegatedDone: breakdown?.delegatedDone ?? 0,
      delegatedTotal: breakdown?.delegatedTotal ?? 0,
      ownDone: breakdown?.ownDone ?? Math.max(0, tDone),
      ownTotal: breakdown?.ownTotal ?? tTotal,
      check: chk,
    }).trim() + revokedSuffix;
  }

  function updatePdfEntryButton(student){
    if (!btnPdfEntry) return;
    const sid = Number(student?.id || 0);
    const cid = Number(state.class_id || 0);
    const active = !!sid && !!cid;
    btnPdfEntry.disabled = !active;
    btnPdfEntry.dataset.studentId = active ? String(sid) : '';
  }

  function onTeacherValueChanged(reportId, fieldId){
    if (isClassFieldId(fieldId)) {
      updateClassFieldsProgressUI();
      return;
    }

    const st = findStudentByReportId(reportId);
    if (!st) return;
    recomputeStudentProgress(st);
    recomputeFormsSummary();
    updateFormsProgressUI();
    updateStudentRowUI(st);
    updateActiveStudentBadge();
  }

  function scheduleSave(reportId, fieldId, value){
    const key = `${reportId}:${fieldId}`;
    const updated = setTeacherFreeTextPart(reportId, fieldId, value);
    if (!updated) {
      if (!state.values_teacher[String(reportId)]) state.values_teacher[String(reportId)] = {};
      state.values_teacher[String(reportId)][String(fieldId)] = value;
      if (!state.values_teacher_own[String(reportId)]) state.values_teacher_own[String(reportId)] = {};
      state.values_teacher_own[String(reportId)][String(fieldId)] = value;
    }
    onTeacherValueChanged(reportId, fieldId);

    queueSave(
      key,
      'save',
      { report_instance_id: reportId, template_field_id: fieldId, value_text: value },
      () => {
        const fDef = state.fieldMap?.[String(fieldId)];
        const combinedValue = teacherVal(reportId, fieldId);
        const displayVal = fDef ? teacherDisplay(fDef, combinedValue) : String(combinedValue ?? '');
        addHistoryEntry(reportId, fieldId, displayVal, 'teacher', combinedValue);
      }
    );
  }

  function onChildValueChanged(reportId){
    const st = findStudentByReportId(reportId);
    if (!st) return;
    if (CHILD_MODE) recomputeStudentProgress(st);
    recomputeFormsSummary();
    updateFormsProgressUI();
    updateStudentRowUI(st);
    updateActiveStudentBadge();
  }

  function scheduleChildSave(reportId, fieldId, value, labelText){
    const key = `child:${reportId}:${fieldId}`;
    const ridKey = String(reportId);
    const fidKey = String(fieldId);
    const prevRaw = childVal(reportId, fieldId);
    if (!state.values_child[ridKey]) state.values_child[ridKey] = {};
    state.values_child[ridKey][fidKey] = String(value ?? '');

    adjustChildProgress(reportId, labelText || '', prevRaw, value ?? '');
    onChildValueChanged(reportId);

    queueSave(
      key,
      'child_value_update',
      { report_instance_id: reportId, child_field_id: fieldId, value_text: String(value ?? '') },
      (res) => {
        if (!state.values_child[ridKey]) state.values_child[ridKey] = {};
        state.values_child[ridKey][fidKey] = res.value_text ?? '';
        addHistoryEntry(reportId, fieldId, res.value_text || '', 'child', res.raw_value_text ?? null, res.value_json ?? null);
      }
    );
  }

  function scheduleSaveClass(fieldId, value){
    const rid = classReportId();
    const key = `class:${rid}:${fieldId}`;

    const updated = setTeacherFreeTextPart(rid, fieldId, value);
    if (!updated) {
      if (!state.values_teacher[String(rid)]) state.values_teacher[String(rid)] = {};
      state.values_teacher[String(rid)][String(fieldId)] = value;
      if (!state.values_teacher_own[String(rid)]) state.values_teacher_own[String(rid)] = {};
      state.values_teacher_own[String(rid)][String(fieldId)] = value;
    }
    onTeacherValueChanged(rid, fieldId);

    queueSave(
      key,
      'save_class',
      { class_id: state.class_id, report_instance_id: rid, template_field_id: fieldId, value_text: value },
      () => {
        const fDef = state.fieldMap?.[String(fieldId)];
        const combinedValue = teacherVal(rid, fieldId);
        const displayVal = fDef ? teacherDisplay(fDef, combinedValue) : String(combinedValue ?? '');
        addHistoryEntry(rid, fieldId, displayVal, 'teacher', combinedValue);
      }
    );
  }

  function isVisibleElement(el){
    if (!el || el.disabled) return false;
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    return !!(el.offsetParent || el.getClientRects().length);
  }

  function collectTeacherFields(){
    const selector = CHILD_MODE
      ? ['[data-child-input="1"]','[data-option-card="1"]'].join(',')
      : ['[data-teacher-input="1"]','[data-option-card="1"]'].join(',');
    return Array.from(document.querySelectorAll(selector))
      .filter(el => {
        if (el.matches('[data-option-card="1"]') && el.getAttribute('tabindex') === '-1') return false;
        return !el.disabled && !el.getAttribute('aria-disabled') && isVisibleElement(el);
      });
  }

  function focusNextTeacherField(currentEl, dir=1){
    const list = collectTeacherFields();
    if (!list.length) return;
    const idx = list.indexOf(currentEl);
    const nextIdx = idx >= 0 ? idx + dir : (dir > 0 ? 0 : list.length - 1);
    if (nextIdx < 0 || nextIdx >= list.length) return;
    const target = list[nextIdx];
    if (target && typeof target.focus === 'function') target.focus();
  }

  function wireTeacherInputs(rootEl){
    if (!rootEl) return;

    rootEl.querySelectorAll('[data-teacher-input="1"]').forEach(inp => {
      const reportId = Number(inp.getAttribute('data-report-id') || '0');
      const fieldId = Number(inp.getAttribute('data-field-id') || '0');
      if (!reportId || !fieldId) return;

      const f = state.fieldMap[String(fieldId)];

      if (f && String(f.field_type || '') === 'grade') {
        inp.classList.add('gradeInput');
      }

      const isClass = isClassFieldId(fieldId);
      const saveMerged = (val) => {
        const finalVal = resolveMergeWithChild(reportId, fieldId, val);
        if (isClass) scheduleSaveClass(fieldId, finalVal);
        else scheduleSave(reportId, fieldId, finalVal);
        return finalVal;
      };

      if (inp.dataset.combo === '1') {
        ensureDatalistForField(fieldId);

        const actual = String(inp.dataset.actual ?? '');
        inp.dataset.actual = actual;
        if (f) inp.value = teacherDisplay(f, actual);

        const commit = () => {
          const typed = inp.value;
          const res = f ? resolveTypedToValue(f, typed) : { value: String(typed ?? '').trim(), valid: true };

          if (!res.valid) {
            inp.setCustomValidity(tEntry('invalid_value'));
            inp.reportValidity();
            inp.value = teacherDisplay(f, inp.dataset.actual ?? '');
            return;
          }

          inp.setCustomValidity('');
          const merged = saveMerged(res.value);
          inp.dataset.actual = merged;
          if (f) inp.value = teacherDisplay(f, merged);
          syncMissingClass(inp, merged);
        };

        inp.addEventListener('change', commit);
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            commit();
            inp.blur();
            focusNextTeacherField(inp, ev.shiftKey ? -1 : 1);
          }
        });
        return;
      }

      if (inp.type === 'checkbox') {
        inp.addEventListener('change', () => {
          const merged = saveMerged(inp.checked ? '1' : '0');
          inp.checked = (merged === '1');
          syncMissingClass(inp, merged);
        });
      } else {
        inp.addEventListener('input', () => {
          const merged = saveMerged(inp.value);
          if (!inp.dataset.combo && f && String(f.field_type || '') !== 'checkbox') {
            // keep UI in sync when Werte kombiniert werden
            inp.value = merged;
          }
          syncMissingClass(inp, merged);
        });
      }

      inp.addEventListener('focus', () => {
        const wrap = inp.closest('.field');
        if (wrap) wrap.scrollIntoView({block:'nearest'});
      });

      inp.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter') return;
        if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
        const tag = (inp.tagName || '').toLowerCase();
        if (tag === 'textarea') return;
        ev.preventDefault();
        focusNextTeacherField(inp, ev.shiftKey ? -1 : 1);
      });

      if ((inp.tagName || '').toLowerCase() === 'textarea') {
        if (MEETING_MODE) {
          autoResizeTextarea(inp);
          inp.addEventListener('input', () => autoResizeTextarea(inp));
        }
        inp.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' && (ev.ctrlKey || ev.altKey)) {
            ev.preventDefault();
            focusNextTeacherField(inp, ev.shiftKey ? -1 : 1);
          }
        });
      }

      if (eligibleForSnippetInput(inp)) {
        ['select','mouseup','keyup','focus'].forEach(ev => {
          inp.addEventListener(ev, () => rememberSelection(inp));
        });
        inp.addEventListener('contextmenu', (ev) => {
          if (!eligibleForSnippetInput(inp)) return;
          ev.preventDefault();
          rememberSelection(inp);
          const x = ev.pageX ?? (ev.clientX + window.scrollX);
          const y = ev.pageY ?? (ev.clientY + window.scrollY);
          openSnippetMenu(x, y, inp);
        });
      }
    });

    rootEl.querySelectorAll('[data-option-card="1"]').forEach(card => {
      const wrap = card.closest('[data-option-block]');
      if (!wrap) return;

      const reportId = Number(wrap.getAttribute('data-report-id') || '0');
      const fieldId = Number(wrap.getAttribute('data-field-id') || '0');
      if (!reportId || !fieldId) return;

      const disabled = wrap.getAttribute('data-disabled') === '1' || card.disabled;
      if (disabled) return;

      const isClass = isClassFieldId(fieldId);
      const saveMerged = (val) => {
        const finalVal = resolveMergeWithChild(reportId, fieldId, val);
        if (isClass) scheduleSaveClass(fieldId, finalVal);
        else scheduleSave(reportId, fieldId, finalVal);
        return finalVal;
      };

      const val = String(card.getAttribute('data-value') || '');
      const select = () => {
        if (card.classList.contains('selected')) {
          const merged = saveMerged('');
          updateOptionCardGroup(wrap, null);
          syncMissingClass(card, merged);
          return;
        }
        const merged = saveMerged(val);
        updateOptionCardGroup(wrap, val);
        syncMissingClass(card, merged);
      };

      card.addEventListener('click', select);
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          select();
          focusNextTeacherField(card, e.shiftKey ? -1 : 1);
          return;
        }
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) {
          e.preventDefault();
          const cards = Array.from(wrap.querySelectorAll('[data-option-card="1"]'))
            .filter(btn => !btn.disabled && isVisibleElement(btn));
          if (!cards.length) return;
          const idx = cards.indexOf(card);
          const dir = (e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1;
          const nextIdx = Math.min(Math.max(idx + dir, 0), cards.length - 1);
          const target = cards[nextIdx];
          if (target && typeof target.focus === 'function') target.focus();
          return;
        }
        if (e.key && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
          const needle = e.key.toLowerCase();
          const cards = Array.from(wrap.querySelectorAll('[data-option-card="1"]'))
            .filter(btn => !btn.disabled && isVisibleElement(btn));
          if (!cards.length) return;
          if (needle >= '0' && needle <= '9') {
            const idx = needle === '0' ? 9 : Number(needle) - 1;
            const target = cards[idx];
            if (target) {
              e.preventDefault();
              target.focus();
              target.click();
            }
            return;
          }
          const startIdx = Math.max(cards.indexOf(card), 0);
          const ordered = cards.slice(startIdx + 1).concat(cards.slice(0, startIdx + 1));
          const orderedLabels = ordered.map(btn => {
            const lbl = btn.querySelector('.lbl');
            return (lbl ? lbl.textContent : btn.textContent || '').trim().toLowerCase();
          });
          const matchIdx = orderedLabels.findIndex(text => text.startsWith(needle));
          if (matchIdx >= 0) {
            const target = ordered[matchIdx];
            if (target) {
              e.preventDefault();
              target.focus();
              target.click();
            }
          }
        }
      });
    });
  }

  function wireChildInputs(rootEl){
    if (!rootEl) return;

    rootEl.querySelectorAll('[data-child-input="1"]').forEach(inp => {
      const reportId = Number(inp.getAttribute('data-report-id') || '0');
      const fieldId = Number(inp.getAttribute('data-field-id') || '0');
      if (!reportId || !fieldId) return;

      const f = state.fieldMap[String(fieldId)];
      const labelText = String(f?.label || f?.field_name || tEntry('field_fallback'));

      if (f && String(f.field_type || '') === 'grade') {
        inp.classList.add('gradeInput');
      }

      if (inp.dataset.combo === '1') {
        ensureDatalistForField(fieldId);

        const actual = String(inp.dataset.actual ?? '');
        inp.dataset.actual = actual;
        if (f) inp.value = childFieldDisplay(f, actual);

        const commit = () => {
          const typed = inp.value;
          const res = f ? resolveTypedToValue(f, typed) : { value: String(typed ?? '').trim(), valid: true };

          if (!res.valid) {
            inp.setCustomValidity(tEntry('invalid_value'));
            inp.reportValidity();
            inp.value = childFieldDisplay(f, inp.dataset.actual ?? '');
            return;
          }

          inp.setCustomValidity('');
          inp.dataset.actual = res.value;
          if (f) inp.value = childFieldDisplay(f, res.value);
          syncMissingClass(inp, res.value);
          scheduleChildSave(reportId, fieldId, res.value, labelText);
        };

        inp.addEventListener('change', commit);
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            commit();
            inp.blur();
            focusNextTeacherField(inp, ev.shiftKey ? -1 : 1);
          }
        });
        return;
      }

      if (inp.type === 'checkbox') {
        inp.addEventListener('change', () => {
          const val = inp.checked ? '1' : '0';
          syncMissingClass(inp, val);
          scheduleChildSave(reportId, fieldId, val, labelText);
        });
      } else {
        inp.addEventListener('input', () => {
          syncMissingClass(inp, inp.value);
          scheduleChildSave(reportId, fieldId, inp.value, labelText);
        });
      }

      inp.addEventListener('focus', () => {
        const wrap = inp.closest('.field');
        if (wrap) wrap.scrollIntoView({block:'nearest'});
      });

      inp.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter') return;
        if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
        const tag = (inp.tagName || '').toLowerCase();
        if (tag === 'textarea') return;
        ev.preventDefault();
        focusNextTeacherField(inp, ev.shiftKey ? -1 : 1);
      });

      if ((inp.tagName || '').toLowerCase() === 'textarea') {
        if (MEETING_MODE) {
          autoResizeTextarea(inp);
          inp.addEventListener('input', () => autoResizeTextarea(inp));
        }
        inp.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' && (ev.ctrlKey || ev.altKey)) {
            ev.preventDefault();
            focusNextTeacherField(inp, ev.shiftKey ? -1 : 1);
          }
        });
      }

      if (eligibleForSnippetInput(inp)) {
        ['select','mouseup','keyup','focus'].forEach(ev => {
          inp.addEventListener(ev, () => rememberSelection(inp));
        });
        inp.addEventListener('contextmenu', (ev) => {
          if (!eligibleForSnippetInput(inp)) return;
          ev.preventDefault();
          rememberSelection(inp);
          const x = ev.pageX ?? (ev.clientX + window.scrollX);
          const y = ev.pageY ?? (ev.clientY + window.scrollY);
          openSnippetMenu(x, y, inp);
        });
      }
    });

    rootEl.querySelectorAll('[data-option-card="1"]').forEach(card => {
      const wrap = card.closest('[data-option-block]');
      if (!wrap) return;

      const reportId = Number(wrap.getAttribute('data-report-id') || '0');
      const fieldId = Number(wrap.getAttribute('data-field-id') || '0');
      if (!reportId || !fieldId) return;

      const disabled = wrap.getAttribute('data-disabled') === '1' || card.disabled;
      if (disabled) return;

      const f = state.fieldMap[String(fieldId)];
      const labelText = String(f?.label || f?.field_name || tEntry('field_fallback'));
      const val = String(card.getAttribute('data-value') || '');

      const select = () => {
        if (card.classList.contains('selected')) {
          scheduleChildSave(reportId, fieldId, '', labelText);
          updateOptionCardGroup(wrap, null);
          syncMissingClass(card, '');
          return;
        }
        scheduleChildSave(reportId, fieldId, val, labelText);
        updateOptionCardGroup(wrap, val);
        syncMissingClass(card, val);
      };

      card.addEventListener('click', select);
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          select();
          focusNextTeacherField(card, e.shiftKey ? -1 : 1);
          return;
        }
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) {
          e.preventDefault();
          const cards = Array.from(wrap.querySelectorAll('[data-option-card="1"]'))
            .filter(btn => !btn.disabled && isVisibleElement(btn));
          if (!cards.length) return;
          const idx = cards.indexOf(card);
          const dir = (e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1;
          const nextIdx = Math.min(Math.max(idx + dir, 0), cards.length - 1);
          const target = cards[nextIdx];
          if (target && typeof target.focus === 'function') target.focus();
          return;
        }
        if (e.key && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
          const needle = e.key.toLowerCase();
          const cards = Array.from(wrap.querySelectorAll('[data-option-card="1"]'))
            .filter(btn => !btn.disabled && isVisibleElement(btn));
          if (!cards.length) return;
          if (needle >= '0' && needle <= '9') {
            const idx = needle === '0' ? 9 : Number(needle) - 1;
            const target = cards[idx];
            if (target) {
              e.preventDefault();
              target.focus();
              target.click();
            }
            return;
          }
          const startIdx = Math.max(cards.indexOf(card), 0);
          const ordered = cards.slice(startIdx + 1).concat(cards.slice(0, startIdx + 1));
          const orderedLabels = ordered.map(btn => {
            const lbl = btn.querySelector('.lbl');
            return (lbl ? lbl.textContent : btn.textContent || '').trim().toLowerCase();
          });
          const matchIdx = orderedLabels.findIndex(text => text.startsWith(needle));
          if (matchIdx >= 0) {
            const target = ordered[matchIdx];
            if (target) {
              e.preventDefault();
              target.focus();
              target.click();
            }
          }
        }
      });
    });
  }

  function wireActiveInputs(rootEl){
    if (CHILD_MODE) wireChildInputs(rootEl);
    else wireTeacherInputs(rootEl);
  }

  function eligibleForSnippetInput(inp){
    if (!inp || inp.disabled) return false;
    if (inp.dataset && inp.dataset.combo === '1') return false; // option combos
    const tag = (inp.tagName || '').toLowerCase();
    if (tag === 'textarea') return true;
    const t = (inp.getAttribute('type') || 'text').toLowerCase();
    return ['text', 'search'].includes(t);
  }

  function updateSnippetSelectionUI(){
    if (!snippetSelection) return;
    const current = lastSnippetSelection || '';
    const trimmed = current.trim();
    const preview = trimmed.length > 120 ? trimmed.slice(0, 120) + '…' : trimmed;
    if (preview) {
      snippetSelection.textContent = tfmtEntry('snippet_selection', { preview });
    } else if (lastSnippetTarget) {
      snippetSelection.textContent = tEntry('snippet_no_text_fallback');
    } else {
      snippetSelection.textContent = tEntry('snippet_no_text');
    }
    if (btnSnippetSave) {
      const fallbackText = lastSnippetTarget ? String(lastSnippetTarget.value || '').trim() : '';
      btnSnippetSave.disabled = (!trimmed && !fallbackText);
    }
  }

  function rememberSelection(inp){
    lastSnippetTarget = inp || lastSnippetTarget;
    if (!inp) { updateSnippetSelectionUI(); return; }
    if (typeof inp.selectionStart === 'number' && typeof inp.selectionEnd === 'number') {
      if (inp.selectionEnd > inp.selectionStart) {
        lastSnippetSelection = inp.value.slice(inp.selectionStart, inp.selectionEnd);
      } else {
        lastSnippetSelection = '';
      }
    }
    updateSnippetSelectionUI();
  }

  function refreshSnippetCategoryList(){
    if (!snippetCategory) return;
    const cats = new Set();
    (state.text_snippets || []).forEach(s => { if (s.category) cats.add(String(s.category)); });
    const currentVal = String(snippetCategory.value || '');
    snippetCategory.innerHTML = '';
    const emptyOpt = document.createElement('option');
    emptyOpt.value = '';
    emptyOpt.textContent = tEntry('snippet_menu_category_placeholder');
    snippetCategory.appendChild(emptyOpt);
    cats.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c;
      opt.textContent = c;
      snippetCategory.appendChild(opt);
    });
    snippetCategory.value = currentVal;
    if (snippetCategory.value !== currentVal) snippetCategory.value = '';
  }

  function insertSnippetText(target, text){
    const el = target || lastSnippetTarget;
    if (!el) { alert(tEntry('snippet_no_target')); return; }
    const snippet = String(text ?? '');
    const start = typeof el.selectionStart === 'number' ? el.selectionStart : (el.value || '').length;
    const end = typeof el.selectionEnd === 'number' ? el.selectionEnd : start;
    const val = String(el.value || '');
    el.value = val.slice(0, start) + snippet + val.slice(end);
    const pos = start + snippet.length;
    if (typeof el.setSelectionRange === 'function') {
      el.setSelectionRange(pos, pos);
    }
    el.focus();
    el.dispatchEvent(new Event('input', { bubbles: true }));
    lastSnippetTarget = el;
  }

  function renderSnippetList(){
    if (!snippetList) return;
    const list = state.text_snippets || [];
    snippetList.innerHTML = '';
    if (!list.length) {
      snippetList.innerHTML = `<div class="muted">${esc(tEntry('snippet_empty'))}</div>`;
      return;
    }
    const grouped = {};
    list.forEach(s => {
      const cat = s.category && String(s.category).trim() !== '' ? String(s.category) : tEntry('snippet_default_category');
      if (!grouped[cat]) grouped[cat] = [];
      grouped[cat].push(s);
    });

    Object.entries(grouped).forEach(([cat, items]) => {
      items.forEach(s => {
        const card = document.createElement('div');
        card.className = 'snippet-card';
        card.innerHTML = `
          <div class="h">
            <div style="font-weight:800;">${fmtPipeItalic(s.title || tEntry('snippet_untitled'))}</div>
            <span class="pill-mini">${esc(cat)}</span>
          </div>
          <div class="txt">${fmtPipeItalic(s.content || '')}</div>
          <div class="c">${esc(s.created_by_name || '')}${s.is_generated ? ` · ${esc(tEntry('snippet_generated'))}` : ''}</div>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button class="btn secondary" type="button">${esc(tEntry('snippet_insert_current'))}</button>
          </div>
        `;
        card.querySelector('button')?.addEventListener('click', () => {
          insertSnippetText(lastSnippetTarget, s.content || '');
        });
        snippetList.appendChild(card);
      });
    });
  }

  function openSnippetDrawer(show=true){
    if (!snippetDrawer) return;
    snippetDrawer.style.display = show ? 'block' : 'none';
  }

  function hideSnippetMenu(){
    snippetMenu.style.display = 'none';
    lastSnippetTarget = null;
    lastSnippetSelection = '';
  }

  function resetAiDialog(){
    if (aiStatus) {
      aiStatus.style.display = 'none';
      aiStatus.className = 'alert';
      aiStatus.textContent = '';
    }
    if (aiStrengths) aiStrengths.innerHTML = `<span class="muted">${esc(tEntry('ai_empty'))}</span>`;
    if (aiGoals) aiGoals.innerHTML = `<span class="muted">${esc(tEntry('ai_empty'))}</span>`;
    if (aiSteps) aiSteps.innerHTML = `<span class="muted">${esc(tEntry('ai_empty'))}</span>`;
    if (btnAiRefresh) btnAiRefresh.disabled = false;
  }

  function closeAiDialog(){
    dlgAi.style.display = 'none';
  }

  function openAiDialog(meta){
    resetAiDialog();
    aiMeta.textContent = meta || '';
    dlgAi.style.display = 'block';
    if (btnAiRefresh) btnAiRefresh.style.display = 'inline-flex';
  }

  async function copyToClipboard(text){
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      aiStatus.textContent = tEntry('ai_copy_success');
      aiStatus.className = 'alert success';
      aiStatus.style.display = 'block';
    } catch (e) {
        const ok = copyHttp(text);
        if(ok) {
            aiStatus.textContent = tEntry('ai_copy_success');
            aiStatus.className = 'alert success';
            aiStatus.style.display = 'block';
        } else {
            aiStatus.textContent = tfmtEntry('ai_copy_fail', { error: e });
            aiStatus.className = 'alert danger';
            aiStatus.style.display = 'block';
        }
    }
  }
  
  function copyHttp(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);

    ta.focus();
    ta.select();

    try {
      document.execCommand('copy');
      return true;
    } catch {
      return false;
    } finally {
      document.body.removeChild(ta);
    }
  }

  function renderAiList(el, list){
    if (!el) return;
    el.innerHTML = '';
    const items = Array.isArray(list) ? list.filter(x => String(x).trim() !== '') : [];
    if (!items.length) {
      el.innerHTML = `<span class="muted">${esc(tEntry('ai_none'))}</span>`;
      return;
    }
    items.forEach(txt => {
      const div = document.createElement('div');
      div.className = 'pill';
      div.style.cursor = 'pointer';
      div.textContent = txt;
      div.addEventListener('click', () => copyToClipboard(txt));
      el.appendChild(div);
    });
  }

  async function requestAiSuggestionsForStudent(student){
    if (!student || !student.report_instance_id) return;
    aiCurrentStudent = student;
    const reportId = student.report_instance_id;
    const gradeInfo = state.class_grade_level
      ? tfmtEntry('grade_level', { grade: state.class_grade_level })
      : tEntry('class_label');
    openAiDialog(tfmtEntry('ai_dialog_title', { name: student.name, gradeInfo }));

    const optionStatus = optionCompletionForStudent(reportId);
    if (optionStatus.missing > 0) {
      if (aiStatus) {
        aiStatus.textContent = tfmtEntry('ai_require_options', {
          missing: optionStatus.missing,
          total: optionStatus.total,
        });
        aiStatus.style.display = 'block';
        aiStatus.className = 'alert danger';
      }
      return;
    }

    const cached = aiCache.get(reportId);
    if (cached && !aiLoading) {
      renderAiList(aiStrengths, cached.strengths || []);
      renderAiList(aiGoals, cached.goals || []);
      renderAiList(aiSteps, cached.steps || []);
      if (aiStatus) {
        aiStatus.textContent = tEntry('ai_cached');
        aiStatus.className = 'alert info';
        aiStatus.style.display = 'block';
      }
      return;
    }

    if (aiStatus) {
      aiStatus.textContent = tEntry('ai_loading');
      aiStatus.style.display = 'block';
      aiStatus.className = 'alert';
    }
    aiLoading = true;
    if (btnAiRefresh) btnAiRefresh.disabled = true;
    try {
      const j = await api('ai_suggestions', {
        class_id: state.class_id,
        report_instance_id: student.report_instance_id,
      });
      aiCache.set(reportId, j.suggestions || {});
      renderAiList(aiStrengths, j.suggestions?.strengths || []);
      renderAiList(aiGoals, j.suggestions?.goals || []);
      renderAiList(aiSteps, j.suggestions?.steps || []);
      if (aiStatus) {
        aiStatus.textContent = tEntry('ai_loaded');
        aiStatus.className = 'alert success';
        aiStatus.style.display = 'block';
      }
    } catch (e) {
      if (aiStatus) {
        aiStatus.textContent = e?.message || tEntry('ai_error');
        aiStatus.className = 'alert danger';
        aiStatus.style.display = 'block';
      }
    } finally {
      aiLoading = false;
      if (btnAiRefresh) btnAiRefresh.disabled = false;
    }
  }

  function openSnippetMenu(x, y, target){
    lastSnippetTarget = target || lastSnippetTarget;
    const allSnippets = state.text_snippets || [];
    const fieldId = Number(lastSnippetTarget?.getAttribute?.('data-field-id') || '0');
    const fieldDef = fieldId > 0 ? state.fieldMap?.[String(fieldId)] : null;
    const allowedCategoryIds = Array.isArray(fieldDef?.snippet_category_ids)
      ? fieldDef.snippet_category_ids.map(c => String(c || '').trim()).filter(Boolean)
      : null;
    const allowedCategories = Array.isArray(fieldDef?.snippet_categories)
      ? fieldDef.snippet_categories.map(c => String(c || '').trim()).filter(Boolean)
      : null;
    const list = allSnippets.filter(s => {
      const cat = s?.category && String(s.category).trim() !== '' ? String(s.category).trim() : tEntry('snippet_default_category');
      const catId = String(s?.category_id || '').trim();
      if (allowedCategoryIds !== null) return catId !== '' && allowedCategoryIds.includes(catId);
      if (allowedCategories !== null) return allowedCategories.includes(cat);
      return true;
    });
    snippetMenu.innerHTML = '';

    const trimmedSel = (lastSnippetSelection || '').trim();
    if (trimmedSel) {
      const saveBox = document.createElement('div');
      saveBox.className = 'snippet-save';
      const preview = trimmedSel.length > 240 ? trimmedSel.slice(0, 240) + '…' : trimmedSel;
      const derivedTitle = preview.length > 60 ? preview.slice(0, 60) + '…' : preview;
      saveBox.innerHTML = `
        <div style="font-weight:800;">${esc(tEntry('snippet_menu_title'))}</div>
        <div class="muted" style="font-size:12px;">${esc(preview)}</div>
        <div class="row" style="align-items:center;">
          <input class="input" type="text" placeholder="${esc(tEntry('snippet_menu_title_placeholder'))}" style="flex:1; min-width:180px;">
          <select class="input" style="flex:1; min-width:160px;"><option value="">${esc(tEntry('snippet_menu_category_placeholder'))}</option></select>
          <button class="btn" type="button">${esc(tEntry('snippet_menu_save'))}</button>
        </div>
      `;
      const titleInput = saveBox.querySelector('input');
      const catInput = saveBox.querySelector('select');
      if (catInput) {
        const cats = new Set();
        (state.text_snippets || []).forEach(s => { if (s.category) cats.add(String(s.category)); });
        cats.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c;
          opt.textContent = c;
          catInput.appendChild(opt);
        });
      }
      const saveBtn = saveBox.querySelector('button');
      saveBtn?.addEventListener('click', async () => {
        const title = titleInput ? String(titleInput.value || '').trim() : '';
        const cat = catInput ? String(catInput.value || '').trim() : '';
        const finalTitle = title || derivedTitle;
        try {
          const j = await api('snippet_save', { title: finalTitle, category: cat, content: trimmedSel });
          if (j.snippet) state.text_snippets.push(j.snippet);
          renderSnippetList();
          refreshSnippetCategoryList();
          hideSnippetMenu();
        } catch (e) {
          alert(friendlyFetchError(e));
        }
      });
      snippetMenu.appendChild(saveBox);
    }

    if (!list.length) {
      snippetMenu.innerHTML = `<div class="muted">${esc(tEntry('snippet_empty'))}</div>`;
    } else {
      const grouped = {};
      list.forEach(s => {
        const cat = s.category && String(s.category).trim() !== '' ? String(s.category) : tEntry('snippet_default_category');
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(s);
      });
      Object.entries(grouped).forEach(([cat, items]) => {
        const h = document.createElement('h4');
        h.innerHTML = fmtPipeItalic(cat);
        snippetMenu.appendChild(h);
        items.forEach(s => {
          const div = document.createElement('div');
          div.className = 'item';
          div.innerHTML = `<div style="font-size:12px;font-weight:800; line-height:1.2;">${fmtPipeItalic(s.title || tEntry('snippet_untitled'))}</div><div class="muted" style="font-size:11px; line-height:1.25;">${fmtPipeItalic((s.content || '').slice(0, 120))}</div>`;
          div.addEventListener('click', () => {
            insertSnippetText(target, s.content || '');
            hideSnippetMenu();
          });
          snippetMenu.appendChild(div);
        });
      });
    }
    const printWrap = document.createElement('div');
    printWrap.style.marginTop = '10px';
    printWrap.style.paddingTop = '8px';
    printWrap.style.borderTop = '1px solid var(--border)';
    const printBtn = document.createElement('button');
    printBtn.type = 'button';
    printBtn.className = 'btn secondary';
    printBtn.textContent = 'Übersicht drucken';
    printBtn.addEventListener('click', () => {
      const grouped = {};
      list.forEach(s => {
        const cat = s.category && String(s.category).trim() !== '' ? String(s.category) : tEntry('snippet_default_category');
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(s);
      });
      const html = Object.entries(grouped).map(([cat, items]) => {
        const rows = items.map(s => `<li><strong>${fmtPipeItalic(s.title || tEntry('snippet_untitled'))}</strong>${fmtPipeItalic(String(s.content || ''))}</li>`).join('');
        return `<h3>${fmtPipeItalic(cat)}</h3><ul>${rows}</ul>`;
      }).join('');
      const w = window.open('', '_blank', 'width=900,height=700');
      if (!w) return;
      w.document.open();
      w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Textbaustein-Übersicht</title><style>
        body{font-family:Inter,Arial,sans-serif;padding:18px;color:#1f2937;font-size:12px;line-height:1.4;background:#f8fafc;}
        .sheet{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;}
        h1{margin:0 0 4px;font-size:18px;line-height:1.2;}
        .sub{margin:0 0 12px;color:#6b7280;font-size:11px;}
        h3{margin:14px 0 6px;font-size:13px;padding-bottom:4px;border-bottom:1px solid #e5e7eb;}
        ul{margin:0;padding:0;list-style:none;}
        li{margin:0 0 8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fcfcfd;white-space:pre-wrap;}
        li strong{display:block;font-size:12px;margin-bottom:1px;color:#111827;}
        @media print{body{background:#fff;padding:0}.sheet{border:none;border-radius:0;padding:0;max-width:none}li{break-inside:avoid;page-break-inside:avoid}}
      </style></head><body><div class="sheet"><h1>Textbaustein-Übersicht</h1><p class="sub">Verfügbare Bausteine für das aktuell gewählte Feld</p>${html || '<p>Keine Bausteine vorhanden.</p>'}</div></body></html>`);
      w.document.close();
      w.addEventListener('load', () => {
        w.focus();
        w.print();
      }, { once: true });
    });
    printWrap.appendChild(printBtn);
    snippetMenu.appendChild(printWrap);
    snippetMenu.style.display = 'block';
    // anchor to page coordinates so menu scrolls with content
    const px = Number(x || 0);
    const py = Number(y || 0);
    snippetMenu.style.left = `${px}px`;
    snippetMenu.style.top = `${py}px`;
  }

  function closeCombinedTips(except){
    document.querySelectorAll('.combined-tip.open').forEach(t => {
      if (except && t === except) return;
      t.classList.remove('open');
    });
  }

  document.addEventListener('click', (ev) => {
    const tipBtn = ev.target && ev.target.closest('.js-combined-tip');
    if (tipBtn) {
      ev.preventDefault();
      const tipWrap = tipBtn.closest('.combined-tip');
      if (tipWrap) {
        const open = tipWrap.classList.contains('open');
        closeCombinedTips(open ? null : tipWrap);
        tipWrap.classList.toggle('open', !open);
      }
      return;
    }

    const restoreBtn = ev.target && ev.target.closest('[data-history-restore="1"]');
    if (restoreBtn) {
      ev.preventDefault();
      applyHistoryValue(
        restoreBtn.getAttribute('data-report-id'),
        restoreBtn.getAttribute('data-field-id'),
        restoreBtn.getAttribute('data-value-text') ?? '',
        restoreBtn.getAttribute('data-value-json') ?? ''
      );
      return;
    }

    const historyToggle = ev.target && ev.target.closest('[data-history-toggle="1"]');
    if (historyToggle) {
      ev.preventDefault();
      const wrap = historyToggle.closest('[data-history-wrap="1"]');
      if (wrap) {
        const open = wrap.classList.contains('open');
        closeHistoryMenus(open ? null : wrap);
        wrap.classList.toggle('open', !open);
      }
      return;
    }

    if (ev.target && ev.target.closest('[data-history-menu="1"]')) return;
    closeHistoryMenus();
    closeCombinedTips();

    if (ev.target && snippetMenu.contains(ev.target)) return;
    hideSnippetMenu();
  });

  // --- rendering helpers
  function fieldMaxLen(f){
    const raw = f?.meta?.pdf_max_len ?? null;
    const n = Number(raw);
    if (!Number.isFinite(n) || n <= 0) return null;
    return Math.floor(n);
  }

  function maxLenAttr(f){
    const n = fieldMaxLen(f);
    return n ? `maxlength="${esc(String(n))}"` : '';
  }

  function autoResizeTextarea(el){
    if (!el) return;
    el.style.height = 'auto';
    const styles = window.getComputedStyle(el);
    const lineHeight = Number.parseFloat(styles.lineHeight) || Number.parseFloat(styles.fontSize) || 0;
    const extraLine = lineHeight > 0 ? lineHeight : 0;
    el.style.height = `${el.scrollHeight + extraLine}px`;
  }

  function renderInputHtml(f, reportId, value, locked, canEdit=true){
    const dis = (locked || !canEdit) ? 'disabled' : '';
    const common = `class="input" data-teacher-input="1" data-report-id="${esc(reportId)}" data-field-id="${esc(f.id)}" ${dis}`;

    const type = String(f.field_type || 'text');

    if (MEETING_MODE && type === 'grade') {
      const shown = teacherDisplay(f, value);
      return `<div class="input" data-static="1" aria-readonly="true">${esc(shown)}</div>`;
    }

    if (type === 'checkbox') {
      const checked = (String(value) === '1') ? 'checked' : '';
      return `<label style="display:flex; align-items:center; gap:10px;"><input type="checkbox" ${common} value="1" ${checked} onchange="this.value=this.checked?'1':'0'"> <span class="muted">${esc(tEntry('yes_no'))}</span></label>`;
    }

    if (type === 'multiline' || Number(f.is_multiline||0) === 1) {
      const maxAttr = maxLenAttr(f);
      const rows = MEETING_MODE ? 1 : 4;
      const meetingStyle = MEETING_MODE ? 'font-size:1.1rem; line-height:1.5; overflow:hidden; resize:none; height:auto;' : '';
      return `<textarea rows="${rows}" ${common} ${maxAttr} style="width:100%; ${meetingStyle}">${esc(value)}</textarea>`;
    }

    if (type === 'radio' || type === 'select' || type === 'grade') {
      const opts = Array.isArray(f.options) ? f.options : [];

      if (ui.optionMode === 'buttons' && opts.length > 0) {
        const disabledAttr = (locked || !canEdit) ? 'data-disabled="1"' : '';
        const currentVal = String(value ?? '').trim();
        const childValRaw = (ui.showChild && f.child && f.child.id) ? String(childVal(reportId, f.child.id) ?? '').trim() : '';
        const hasSelected = currentVal !== '' && opts.some(o => {
          const oVal = String(o?.value ?? '');
          const lblDe = String(o?.label ?? '').trim();
          const lblEn = String(o?.label_en ?? '').trim();
          const lbl = optionLabel(opts, oVal) || oVal || tEntry('option_fallback');
          return currentVal === oVal || currentVal === lblDe || currentVal === lblEn || currentVal === lbl;
        });
        const hasAnyIcon = opts.some(o => !!(o && o.icon_url));
        const cards = opts.map((o, idx) => {
          const oVal = String(o?.value ?? '');
          const lblDe = String(o?.label ?? '').trim();
          const lblEn = String(o?.label_en ?? '').trim();
          const lbl = optionLabel(opts, oVal) || oVal || tEntry('option_fallback');
          const selected = currentVal !== '' && (currentVal === oVal || currentVal === lblDe || currentVal === lblEn || currentVal === lbl);
          const matchesChild = childValRaw && (childValRaw === oVal || childValRaw === lblDe || childValRaw === lblEn || childValRaw === lbl);
          const dis = (locked || !canEdit) ? 'disabled' : '';
          const tabIndex = (selected || (!hasSelected && idx === 0)) ? '0' : '-1';
          const iconUrl = o && o.icon_url ? String(o.icon_url) : '';
          const ico = iconUrl
            ? `<span class="ico"><img src="${esc(iconUrl)}" alt=""></span>`
            : (hasAnyIcon ? `<span class="ico placeholder" aria-hidden="true">•</span>` : '');
          const classes = ['opt'];
          if (selected) classes.push('selected');
          if (matchesChild) classes.push('child-val');
          return `<button type="button" class="${classes.join(' ')}" tabindex="${tabIndex}" data-option-card="1" data-value="${esc(oVal)}" aria-pressed="${selected ? 'true' : 'false'}" ${dis}>${ico}<span class="lbl">${esc(lbl)}</span></button>`;
        }).join('');

        return `
          <div class="opts" data-option-block="1" data-report-id="${esc(reportId)}" data-field-id="${esc(f.id)}" ${disabledAttr}>
            ${cards || `<div class="muted">${esc(tEntry('no_options'))}</div>`}
          </div>
        `;
      }

      const dlId = `dl_${String(f.id)}`;
      const shown = teacherDisplay(f, value);
      const actual = String(value ?? '');
      return `
        <input type="text" ${common}
          data-combo="1"
          data-dlid="${esc(dlId)}"
          data-actual="${esc(actual)}"
          list="${esc(dlId)}"
          autocomplete="off"
          style="width:100%;"
          value="${esc(shown)}"
        >
      `;
    }

    const inputType = (type === 'number') ? 'number' : ((type === 'date') ? 'date' : 'text');
    const maxAttr = inputType === 'text' ? maxLenAttr(f) : '';
    return `<input type="${esc(inputType)}" ${common} ${maxAttr} style="width:100%;" value="${esc(value)}">`;
  }

  function renderChildInputHtml(f, reportId, value, locked, canEdit=true){
    const dis = (locked || !canEdit) ? 'disabled' : '';
    const common = `class="input" data-child-input="1" data-report-id="${esc(reportId)}" data-field-id="${esc(f.id)}" ${dis}`;

    const type = String(f.field_type || 'text');

    if (MEETING_MODE && type === 'grade') {
      const shown = childFieldDisplay(f, value);
      return `<div class="input" data-static="1" aria-readonly="true">${esc(shown)}</div>`;
    }

    if (type === 'checkbox') {
      const checked = (String(value) === '1') ? 'checked' : '';
      return `<label style="display:flex; align-items:center; gap:10px;"><input type="checkbox" ${common} value="1" ${checked} onchange="this.value=this.checked?'1':'0'"> <span class="muted">${esc(tEntry('yes_no'))}</span></label>`;
    }

    if (type === 'multiline' || Number(f.is_multiline||0) === 1) {
      const maxAttr = maxLenAttr(f);
      const rows = MEETING_MODE ? 1 : 4;
      const meetingStyle = MEETING_MODE ? 'font-size:1.1rem; line-height:1.5; overflow:hidden; resize:none; height:auto;' : '';
      return `<textarea rows="${rows}" ${common} ${maxAttr} style="width:100%; ${meetingStyle}">${esc(value)}</textarea>`;
    }

    if (type === 'radio' || type === 'select' || type === 'grade') {
      const opts = Array.isArray(f.options) ? f.options : [];

      if (ui.optionMode === 'buttons' && opts.length > 0) {
        const disabledAttr = (locked || !canEdit) ? 'data-disabled="1"' : '';
        const currentVal = String(value ?? '').trim();
        const hasSelected = currentVal !== '' && opts.some(o => {
          const oVal = String(o?.value ?? '');
          const lblDe = String(o?.label ?? '').trim();
          const lblEn = String(o?.label_en ?? '').trim();
          const lbl = optionLabel(opts, oVal) || oVal || tEntry('option_fallback');
          return currentVal === oVal || currentVal === lblDe || currentVal === lblEn || currentVal === lbl;
        });
        const hasAnyIcon = opts.some(o => !!(o && o.icon_url));
        const cards = opts.map((o, idx) => {
          const oVal = String(o?.value ?? '');
          const lblDe = String(o?.label ?? '').trim();
          const lblEn = String(o?.label_en ?? '').trim();
          const lbl = optionLabel(opts, oVal) || oVal || tEntry('option_fallback');
          const selected = currentVal !== '' && (currentVal === oVal || currentVal === lblDe || currentVal === lblEn || currentVal === lbl);
          const dis = (locked || !canEdit) ? 'disabled' : '';
          const tabIndex = (selected || (!hasSelected && idx === 0)) ? '0' : '-1';
          const iconUrl = o && o.icon_url ? String(o.icon_url) : '';
          const ico = iconUrl
            ? `<span class="ico"><img src="${esc(iconUrl)}" alt=""></span>`
            : (hasAnyIcon ? `<span class="ico placeholder" aria-hidden="true">•</span>` : '');
          const classes = ['opt'];
          if (selected) classes.push('selected');
          return `<button type="button" class="${classes.join(' ')}" tabindex="${tabIndex}" data-option-card="1" data-value="${esc(oVal)}" aria-pressed="${selected ? 'true' : 'false'}" ${dis}>${ico}<span class="lbl">${esc(lbl)}</span></button>`;
        }).join('');

        return `
          <div class="opts" data-option-block="1" data-report-id="${esc(reportId)}" data-field-id="${esc(f.id)}" ${disabledAttr}>
            ${cards || `<div class="muted">${esc(tEntry('no_options'))}</div>`}
          </div>
        `;
      }

      const dlId = `dl_${String(f.id)}`;
      const shown = childFieldDisplay(f, value);
      const actual = String(value ?? '');
      return `
        <input type="text" ${common}
          data-combo="1"
          data-dlid="${esc(dlId)}"
          data-actual="${esc(actual)}"
          list="${esc(dlId)}"
          autocomplete="off"
          style="width:100%;"
          value="${esc(shown)}"
        >
      `;
    }

    const inputType = (type === 'number') ? 'number' : ((type === 'date') ? 'date' : 'text');
    const maxAttr = inputType === 'text' ? maxLenAttr(f) : '';
    return `<input type="${esc(inputType)}" ${common} ${maxAttr} style="width:100%;" value="${esc(value)}">`;
  }

  function renderActiveInputHtml(f, reportId, value, locked, canEdit=true){
    return CHILD_MODE
      ? renderChildInputHtml(f, reportId, value, locked, canEdit)
      : renderInputHtml(f, reportId, value, locked, canEdit);
  }

  function isStudentInputLocked(status){
    return String(status || 'draft') === 'locked';
  }

  function isTeacherInputLocked(status){
    return CHILD_MODE ? (!CHILD_EDIT_OVERRIDE && isStudentInputLocked(status)) : false;
  }

  function currentStudents(){
    const f = normalize(ui.studentFilter);
    let list = filterStudentsForMissing(state.students);

    if (!f) return list;
    return list.filter(s => normalize(s.name).includes(f));
  }

  function activeStudent(){
    const list = currentStudents();
    if (!list.length) return null;
    if (ui.activeStudentIndex < 0) ui.activeStudentIndex = 0;
    if (ui.activeStudentIndex >= list.length) ui.activeStudentIndex = list.length - 1;
    return list[ui.activeStudentIndex];
  }

  function gradeFields(groups){
    const out = [];
    groups.forEach(g => {
      g.fields.forEach(f => {
        if (String(f.field_type) === 'grade') out.push({...f, _group_key:g.key, _group_title:g.title});
      });
    });
    return out;
  }

  function delegationNames(del){
    const users = Array.isArray(del?.users) ? del.users : [];
    if (!users.length) return '';
    return users.map(u => u.user_name || ('#' + u.user_id)).filter(Boolean).join(', ');
  }

  function delegationAllDone(del){
    const users = Array.isArray(del?.users) ? del.users : [];
    if (!users.length) return false;
    return users.every(u => String(u.status || 'open') === 'done');
  }

  function delegationSelfEntry(del){
    const users = Array.isArray(del?.users) ? del.users : [];
    return users.find(u => Number(u.user_id || 0) === CURRENT_USER_ID) || null;
  }

  function ensureSelect(selectEl){
    if (!selectEl) return;
    if (!selectEl.options.length) {
      selectEl.innerHTML = '';

      const optAll = document.createElement('option');
      optAll.value = 'ALL';
      optAll.textContent = 'Alle';
      selectEl.appendChild(optAll);

      const options = collectGroupFilterOptions();
      options.forEach(optData => {
        const opt = document.createElement('option');

        // Subgroup/Sorting beibehalten
        opt.value = optData.value;

        // Gruppe zum Optionseintrag finden (value kann z.B. "groupKey::subKey" sein)
        const groupKey = String(optData.value).split('::')[0];
        const g = activeGroups().find(x => String(x.key) === groupKey);

        // Delegation-Text: bevorzugt master-Logik, sonst Fallback
        const del = g?.delegation;
        const delNames = del ? delegationNames(del) : '';
        const delTxt = delNames
          ? ` → ${delNames}`
          : (del && del.user_id ? ` → ${del.user_name || ('#' + del.user_id)}` : '');

        // Lock beibehalten
        const lockTxt = (g && g.can_edit === 0) ? ' 🔒' : '';

        // Label aus optData (damit Subgroup-Labels korrekt bleiben)
        opt.textContent = (optData.label || (g?.title || g?.key || String(optData.value))) + delTxt + lockTxt;

        selectEl.appendChild(opt);
      });
    }
    if (!selectEl.value) selectEl.value = 'ALL';
  }

  function ensureGroupsSelect(){
    if (!groupSelect.options.length) {
      groupSelect.innerHTML = '';
      const optAll = document.createElement('option');
      optAll.value = 'ALL';
      optAll.textContent = 'Alle';
      groupSelect.appendChild(optAll);
      const options = collectGroupFilterOptions();
      options.forEach(optData => {
        const opt = document.createElement('option');
        opt.value = optData.value;
        opt.textContent = optData.label;
        groupSelect.appendChild(opt);
      });
    }
    if (!groupSelect.value) groupSelect.value = 'ALL';
    ui.groupKey = groupSelect.value;
  }

  function ensureStudentGroupsSelect(){
    if (!studentGroupSelect) return;
    if (!studentGroupSelect.options.length) {
      studentGroupSelect.innerHTML = '';
      const optAll = document.createElement('option');
      optAll.value = 'ALL';
      optAll.textContent = tEntry('filter_all');
      studentGroupSelect.appendChild(optAll);
      const options = collectGroupFilterOptions();
      options.forEach(optData => {
        const opt = document.createElement('option');
        opt.value = optData.value;
        opt.textContent = optData.label;
        studentGroupSelect.appendChild(opt);
      });
    }
    if (!studentGroupSelect.value) studentGroupSelect.value = 'ALL';
    ui.studentGroupKey = studentGroupSelect.value || 'ALL';
  }

  function updateOptionCardGroup(wrap, selectedValue){
    const cards = Array.from(wrap.querySelectorAll('[data-option-card="1"]'));
    cards.forEach(btn => {
      const match = selectedValue !== null && String(btn.getAttribute('data-value') || '') === selectedValue;
      btn.classList.toggle('selected', match);
      btn.setAttribute('aria-pressed', match ? 'true' : 'false');
      btn.setAttribute('tabindex', match ? '0' : '-1');
    });
    if (selectedValue === null) {
      const first = cards.find(btn => !btn.disabled && isVisibleElement(btn)) || cards[0];
      if (first) first.setAttribute('tabindex', '0');
    }
  }

  function filterFieldsBySubgroup(list, subgroup){
    const sub = String(subgroup || '').trim();
    if (!sub) return list || [];
    return (list || []).filter(f => String(f.subgroup || '').trim() === sub);
  }

  function renderStudentFields(fields, reportId, locked, opts = {}){
    const showSubgroups = opts.showSubgroups !== false;
    let html = '';
    let currentSubKey = '';
    (fields || []).forEach(f => {
      const subKey = String(f.subgroup || '').trim();
      const subLabel = subgroupLabelForLang(subKey, f.subgroup_title_en);
      if (showSubgroups && subKey && subKey !== currentSubKey) {
        html += `<div class="subgroup-h">${esc(subLabel)}</div>`;
        currentSubKey = subKey;
      } else if (!subKey) {
        currentSubKey = '';
      }

      const v = activeFieldValue(reportId, f.id);
      const canEditField = (Number(f.can_edit || 0) === 1);
      const childInfo = CHILD_MODE ? '' : childInfoHtml(f, reportId);
      const lbl = resolveLabelTemplate(String(f.label || f.field_name || tEntry('field_label')));
      const help = resolveLabelTemplate(String(f.help_text || ''));
      const missingCls = (v === '') ? 'missing' : '';
      const combinedHtml = CHILD_MODE ? '' : combinedPreviewHtml(reportId, f);
      const historyHtml = CHILD_MODE ? '' : renderHistoryHtml(reportId, f.id);
      const clearBtn = (CHILD_MODE && !DELEGATED_MODE)
        ? `<button class="btn secondary" type="button" data-clear-child="${esc(reportId)}" data-child-field="${esc(f.id)}" data-child-label="${esc(lbl)}">${esc(CHILD_CLEAR_LABEL)}</button>`
        : '';
      const actionsHtml = (combinedHtml || historyHtml || clearBtn)
        ? `<div class="field-actions">${combinedHtml}${historyHtml}${clearBtn}</div>`
        : '';
        const helpDisp = MEETING_MODE ? '' : `<div class="help" style="${help.trim() ? '' : 'display:none;'}">${esc(help)}</div>`;
      html += `
        <div class="field ${missingCls}" data-fieldwrap="1" data-field-id="${esc(f.id)}">
          <div class="lbl">${esc(lbl)}</div>
          ${helpDisp}
          ${renderActiveInputHtml(f, reportId, v, locked, canEditField)}
          ${actionsHtml}
          ${childInfo}
        </div>
      `;
    });
    return html;
  }

  function renderClassFields(){
    if (CHILD_MODE || MEETING_MODE) {
      if (classFieldsBox) classFieldsBox.style.display = 'none';
      if (classFieldsForm) classFieldsForm.innerHTML = '';
      return;
    }
    const cf = state.class_fields;
    dbg('class_fields', cf);

    if (!cf || !Array.isArray(cf.fields) || !cf.fields.length || !classReportId()) {
      if (classFieldsBox) classFieldsBox.style.display = 'none';
      if (classFieldsForm) classFieldsForm.innerHTML = '';
      return;
    }

    classFieldsBox.style.display = 'block';
    // status/progress handled by updateClassFieldsProgressUI()
    updateClassFieldsProgressUI();

    const rid = classReportId();
    const locked = false;

    const html = cf.fields.map(f => {
      const fid = Number(f.id);
      const v = teacherEditVal(rid, fid);
      const lbl = String(f.label_resolved || f.label || f.field_name || '');
      const help = String(f.help_text_resolved || f.help_text || '');
      const canEditField = (Number(f.can_edit || 0) === 1);
      const combinedHtml = combinedPreviewHtml(rid, f);
      const historyHtml = renderHistoryHtml(rid, fid);
      const actionsHtml = (combinedHtml || historyHtml)
        ? `<div class="field-actions">${combinedHtml}${historyHtml}</div>`
        : '';
      return `
        <div class="field" data-fieldwrap="1" data-field-id="${esc(fid)}">
          <div class="lbl" data-dyn="label">${esc(lbl)}</div>
          <div class="help" data-dyn="help" style="${help.trim() ? '' : 'display:none;'}">${esc(help)}</div>
          ${renderInputHtml(f, rid, v, locked, canEditField)}
          ${actionsHtml}
        </div>
      `;
    }).join('');

    classFieldsForm.innerHTML = html;
    wireTeacherInputs(classFieldsForm);
  }

  function render(){
    elApp.style.display = 'block';
    const groups = activeGroups();
    elMetaTop.textContent = tfmtEntry('meta_top', {
      template: state.template?.name ?? tEntry('template_fallback'),
      students: state.students.length,
      fields: groups.reduce((a, g) => a + g.fields.length, 0),
    });

    // ✅ always render class fields (independent from view)
    renderClassFields();
    updateClassFieldsProgressUI();

    // ✅ progress: how many forms are complete
    updateFormsProgressUI();

    if (MEETING_MODE && viewSelect) {
      viewSelect.value = 'student';
    }
    ui.view = (viewSelect.value === 'item') ? 'item' : (viewSelect.value === 'student' ? 'student' : 'grades');
    if (CHILD_MODE && ui.view === 'grades') {
      ui.view = 'student';
      viewSelect.value = 'student';
    }
    ui.showChild = MEETING_MODE ? true : (CHILD_MODE ? false : !!(toggleChild && toggleChild.checked));

    viewGrades.style.display = (ui.view === 'grades') ? 'block' : 'none';
    viewStudent.style.display = (ui.view === 'student') ? 'block' : 'none';
    viewItem.style.display = (ui.view === 'item') ? 'block' : 'none';
    showStudentEntries.style.display = (ui.view === 'student' || ui.view === 'item') ? 'block' : 'none';

    if (studentMissingOnly && studentMissingOnly.checked !== !!ui.studentMissingOnly) {
      studentMissingOnly.checked = !!ui.studentMissingOnly;
    }

    if (optionButtonsToggle && optionButtonsToggle.checked !== (ui.optionMode === 'buttons')) {
      optionButtonsToggle.checked = (ui.optionMode === 'buttons');
    }

    if (ui.showChild) elApp.classList.add('show-child');
    else elApp.classList.remove('show-child');

    if (ui.view === 'grades') renderGradesView();
    else if (ui.view === 'student') {
      if (MEETING_MODE) renderMeetingView();
      else renderStudentView();
    } else renderItemView();
  }

  function renderStudentView(){
    ensureStudentGroupsSelect();
    const groupFilter = parseGroupFilterValue(ui.studentGroupKey);
    const list = currentStudents();
    const hasPrev = ui.activeStudentIndex > 0;
    const hasNext = ui.activeStudentIndex < list.length - 1;

    btnPrevStudent.disabled = !hasPrev;
    btnPrevStudent.setAttribute('aria-disabled', String(!hasPrev));
    btnNextStudent.disabled = !hasNext;
    btnNextStudent.setAttribute('aria-disabled', String(!hasNext));

    studentList.innerHTML = '';
    list.forEach((s, idx) => {
      const div = document.createElement('div');
      div.className = 'srow' + (idx === ui.activeStudentIndex ? ' active' : '');
      const status = String(s.status || 'draft');
      const statusLbl = (status === 'locked')
        ? tEntry('status_locked')
        : (status === 'submitted' ? tEntry('status_submitted') : tEntry('status_draft'));
      const reportId = Number(s.report_instance_id || 0);
      const prog = CHILD_MODE ? activeProgressForStudent(reportId) : {
        total: Number(s.progress_overall_total || 0),
        done: Number(s.progress_overall_done || 0),
        missing: Number(s.progress_overall_missing || 0),
        complete: !!s.progress_is_complete,
      };
      const pct = prog.total > 0 ? Math.round((prog.done / prog.total) * 100) : 0;
      const complete = !!prog.complete;
      const breakdown = shouldShowOpenBreakdown() ? progressBreakdownForStudent(s) : null;
      const breakdownHtml = breakdown
        ? ` <span class="muted js-srow-breakdown">${esc(tfmtEntry('progress_open_breakdown', {
            childDone: breakdown.childDone,
            childTotal: breakdown.childTotal,
            delegatedDone: breakdown.delegatedDone,
            delegatedTotal: breakdown.delegatedTotal,
            ownDone: breakdown.ownDone,
            ownTotal: breakdown.ownTotal,
          }))}</span>`
        : '';
      const revokedCount = Number(s.revoked_delegation_comments_count || 0);
      const revokedNames = Array.isArray(s.revoked_delegation_comment_names)
        ? s.revoked_delegation_comment_names.filter(x => String(x).trim() !== '')
        : [];
      const revokedTitle = revokedNames.length
        ? `Zurückgezogene Delegations-Kommentare von ${revokedNames.join(', ')}`
        : 'Zurückgezogene Delegations-Kommentare prüfen';
      const revokedHtml = revokedCount > 0
        ? ` <span class="pill yellow" title="${esc(revokedTitle)}">⚠ Delegationstext</span>`
        : '';

      div.id = `srow-${s.id}`;
      div.innerHTML = `
        <div class="smeta">
          <div class="n">${esc(s.name)}${revokedHtml}</div>
          <div class="sub js-srow-sub" data-statuslbl="${esc(statusLbl)}">${esc(tfmtEntry('progress_status_line', { status: statusLbl }))}${breakdownHtml}</div>
          <div style="margin-top:6px;">
            <div class="progress sm"><div class="progress-bar js-prog-bar${complete ? ' ok' : ''}" style="width:${pct}%;"></div></div>
          </div>
        </div>
        <span class="badge js-prog-badge${complete ? ' ok' : ''}">${complete ? '✓' : esc(tfmtEntry('progress_badge_open', { missing: (CHILD_MODE ? prog.missing : Number(s.progress_teacher_missing || 0)) }))}</span>
      `;
      div.addEventListener('click', () => {
        ui.activeStudentIndex = idx;
        renderStudentView();
      });
      studentList.appendChild(div);
    });

    const s = activeStudent();
    if (!s) {
      studentBadge.textContent = tEntry('no_results');
      studentForm.innerHTML = `<div class="alert">${esc(tEntry('no_students_found'))}</div>`;
      updatePdfEntryButton(null);
      return;
    }
    updatePdfEntryButton(s);
    updateActiveStudentBadge();

    const reportId = s.report_instance_id;
    const status = String(s.status || 'draft');
    const childLocked = isStudentInputLocked(status);
    const locked = isTeacherInputLocked(status);
    let childMissingFields = Array.isArray(s.child_missing_fields) ? s.child_missing_fields.filter(x => String(x).trim() !== '') : [];
    if (CHILD_MODE) {
      childMissingFields = [];
      activeGroupsForReport(reportId).forEach(g => {
        (g.fields || []).forEach(f => {
          const v = activeFieldValue(reportId, f.id);
          if (String(v ?? '').trim() === '') {
            const lbl = String(f.label || f.field_name || '');
            if (lbl) childMissingFields.push(lbl);
          }
        });
      });
    }
    const unlockBtn = DELEGATED_MODE
      ? ''
      : `<div style="margin-top:8px;"><button class="btn secondary" type="button" data-unlock-child="${esc(reportId)}">${esc(tEntry('unlock_child'))}</button></div>`;

    let html = '';
    if (childLocked) {
      const info = CHILD_MODE
        ? tEntry('locked_cannot_edit')
        : tEntry('locked_teacher_can_edit');
      html += `<div class="alert ${CHILD_MODE ? 'danger' : 'info'}"><strong>${esc(tEntry('locked_title'))}</strong> ${esc(info)}${unlockBtn}</div>`;
    } else if (status === 'submitted') {
      html += `<div class="alert info"><strong>${esc(tEntry('notice_label'))}</strong> ${esc(tEntry('locked_notice'))}${unlockBtn}</div>`;
    } else if (childMissingFields.length > 0) {
      const missingList = childMissingFields.slice(0, 8).map(esc).join(', ');
      const more = childMissingFields.length > 8
        ? ` … (${esc(tfmtEntry('child_missing_more', { count: childMissingFields.length - 8 }))})`
        : '';
      html += `<div class="alert warning"><strong>${esc(tEntry('child_missing_title'))}</strong> ${esc(tEntry('child_missing_fields'))} ${missingList}${more}</div>`;
    }

    const optionStatus = optionCompletionForStudent(reportId);
    if (state.ai_enabled && !CHILD_MODE) {
      const missingOpt = optionStatus.total > 0 ? optionStatus.missing : 0;
      const aiSubtitle = missingOpt > 0
        ? tfmtEntry('ai_require_options_start', { missing: missingOpt, total: optionStatus.total })
        : tEntry('ai_option_ideas');
      const aiButton = missingOpt > 0
        ? `<a class="btn secondary ai-btn" type="button" disabled title="${esc(tEntry('ai_open_disabled_title'))}">${AI_ICON} ${esc(tEntry('ai_open'))}</a>`
        : `<a class="btn secondary ai-btn" type="button" data-ai-student="${esc(reportId)}">${AI_ICON} ${esc(tEntry('ai_open'))}</a>`;
      html += `
        <div class="ai-banner">
          <div>
            <div class="t">${AI_ICON} ${esc(tfmtEntry('ai_banner_title', { name: s.name }))}</div>
            <div class="muted">${esc(aiSubtitle)}</div>
          </div>
          ${aiButton}
        </div>
      `;
    }

    activeGroupsForReport(reportId).forEach(g => {
      if (groupFilter.groupKey !== 'ALL' && String(g.key) !== String(groupFilter.groupKey)) return;
      let fields = filterFieldsBySubgroup((g.fields || []), groupFilter.subgroup);
      const progressFields = fields.filter(f => isEditableField(f, g));
      if (ui.studentMissingOnly) {
        fields = progressFields.filter(f => isActiveFieldMissing(reportId, f.id));
      }

      if (!fields.length) return;

      const _gtTotal = progressFields.length;
      let _gtDone = 0;
      progressFields.forEach(_f => {
        const _v = activeFieldValue(reportId, _f.id);
        if (String(_v).trim() !== '') _gtDone++;
      });
      const _gtMiss = Math.max(0, _gtTotal - _gtDone);
      const _gtPct = _gtTotal > 0 ? Math.round((_gtDone / _gtTotal) * 100) : 0;
      const canEditGroup = (Number(g.can_edit||0) === 1);
      const del = g.delegation;
      const delNames = delegationNames(del);
      const delDone = delegationAllDone(del);
      const delBadge = delNames
        ? `<span class="badge-del">${esc(tfmtEntry(delDone ? 'delegated_badge_done' : 'delegated_badge', { names: delNames, status: tEntry('status_done') }))}</span>`
        : '';
      const lockBadge = (!canEditGroup && !locked) ? `<span class="badge-del">🔒 ${esc(tEntry('readonly_badge'))}</span>` : '';
      const delegBtn = (!CHILD_MODE && CAN_DELEGATE)
  ? `<button class="btn" type="button" tabindex="-1" data-open-deleg="${esc(g.key)}" style="padding:6px 10px; font-size:12px;">${esc(tEntry('delegate_action_short'))}</button>`
  : '';
      html += `
          <div class="section-h" style="margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
            <div class="t">${esc(g.title)} ${delBadge} ${lockBadge}</div>
            <div style="display:flex; gap:10px; align-items:center;">
              <div class="s">${esc(tfmtEntry('group_progress', { done: _gtDone, total: _gtTotal, missing: _gtMiss }))}</div>
              ${delegBtn}
            </div>
          </div>
        `;
      html += `<div class="progress sm" style="margin:6px 0 10px;"><div class="progress-bar${_gtMiss === 0 ? ' ok' : ''}" style="width:${_gtPct}%;"></div></div>`;
      html += renderStudentFields(fields, reportId, locked);
    });

    studentForm.innerHTML = html || `<div class="alert">${esc(tEntry('no_open_fields'))}</div>`;
    
    studentForm.querySelectorAll('[data-open-deleg]').forEach(b => {
        b.addEventListener('click', (ev) => {
          ev.preventDefault();
          const gk = String(b.getAttribute('data-open-deleg') || '');
          if (gk) openDelegations(gk);
        });
      });

    studentForm.querySelectorAll('[data-fieldwrap="1"]').forEach(el => {
      if (ui.showChild) el.classList.add('show-child');
      else el.classList.remove('show-child');
    });

    studentForm.querySelectorAll('[data-ai-student]').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-ai-student') || 0);
        const stu = (state.students || []).find(x => Number(x.report_instance_id || 0) === rid);
        if (stu) requestAiSuggestionsForStudent(stu);
      });
    });

    studentForm.querySelectorAll('[data-unlock-child]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-unlock-child') || 0);
        if (rid > 0) await unlockChildEntry(rid);
      });
    });

    studentForm.querySelectorAll('[data-clear-child]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-clear-child') || 0);
        const fid = Number(btn.getAttribute('data-child-field') || 0);
        const lbl = String(btn.getAttribute('data-child-label') || tEntry('field_fallback'));
        if (!rid || !fid) return;
        const confirmMsg = String(CHILD_CLEAR_CONFIRM || tfmtEntry('prompt_clear_child_value', { label: lbl }))
          .replace('{label}', lbl);
        if (!window.confirm(confirmMsg)) return;
        await updateChildValue(rid, fid, null, lbl, { render: false });
        const current = childVal(rid, fid);
        const val = (current ?? '');
        const wrap = btn.closest('.field') || btn.closest('.cellWrap');
        if (wrap) {
          wrap.classList.toggle('missing', String(val).trim() === '');
          const input = wrap.querySelector('[data-child-input="1"]');
          if (input) {
            if (input.dataset.combo === '1') {
              input.dataset.actual = String(val ?? '');
              input.value = childFieldDisplay(state.fieldMap[String(fid)], val);
            } else if (input.type === 'checkbox') {
              input.checked = String(val) === '1';
            } else {
              input.value = String(val ?? '');
            }
          }
          wrap.querySelectorAll('[data-option-card="1"]').forEach(card => {
            const match = String(card.getAttribute('data-value') || '') === String(val ?? '');
            card.classList.toggle('selected', match);
            card.setAttribute('aria-pressed', match ? 'true' : 'false');
            card.setAttribute('tabindex', match ? '0' : '-1');
          });
        }
      });
    });

    if (!CHILD_MODE && !DELEGATED_MODE) {
      wireChildValueControls(studentForm);
    }
    wireActiveInputs(studentForm);

  }

  function collectMeetingSteps(reportId){
    const steps = [];
    activeGroupsForReport(reportId).forEach(g => {
      const fields = Array.isArray(g.fields) ? g.fields : [];
      if (!fields.length) return;
      const subgroupMap = new Map();
      fields.forEach(f => {
        const sub = String(f.subgroup || '').trim();
        const key = sub !== '' ? sub : '__none__';
        if (!subgroupMap.has(key)) subgroupMap.set(key, []);
        subgroupMap.get(key).push(f);
      });
      subgroupMap.forEach((subFields, subKey) => {
        const subgroup = subKey === '__none__' ? '' : subKey;
        const subgroupLabel = subgroup
          ? subgroupLabelForLang(subgroup, subFields[0]?.subgroup_title_en)
          : '';
        steps.push({
          id: `${g.key}::${subgroup}`,
          group: g,
          subgroup,
          subgroupLabel,
          fields: subFields,
        });
      });
    });
    return steps;
  }

  function updateMeetingStudentBadge(){
    const s = activeStudent();
    if (!s || !meetingStudentBadge) return;
    meetingStudentBadge.textContent = `${s.name}`;
  }

  function ensureMeetingStudentSelect(list){
    if (!meetingStudentSelect) return;
    meetingStudentSelect.innerHTML = '';
    list.forEach((s, idx) => {
      const opt = document.createElement('option');
      opt.value = String(idx);
      opt.textContent = s.name;
      meetingStudentSelect.appendChild(opt);
    });
    meetingStudentSelect.value = String(Math.max(0, Math.min(ui.activeStudentIndex, list.length - 1)));
  }

  function meetingStepMove(delta){
    if (!meetingState.steps.length) return;
    const next = Math.max(0, Math.min(meetingState.activeStep + delta, meetingState.steps.length - 1));
    if (next === meetingState.activeStep) return;
    meetingState.activeStep = next;
    renderMeetingView();
  }

  function renderMeetingView(){
    const list = currentStudents();
    if (!meetingWizShell || !meetingStepBody || !meetingNav) return;
    if (!list.length) {
      if (meetingStudentSelect) meetingStudentSelect.innerHTML = '';
      meetingStepTitle.textContent = '—';
      meetingStepSub.textContent = '';
      meetingStepSub.style.display = 'none';
      meetingStepBody.innerHTML = `<div class="alert">${esc(tEntry('no_students_found'))}</div>`;
      return;
    }

    if (ui.activeStudentIndex < 0) ui.activeStudentIndex = 0;
    if (ui.activeStudentIndex >= list.length) ui.activeStudentIndex = list.length - 1;

    ensureMeetingStudentSelect(list);

    const hasPrevStudent = ui.activeStudentIndex > 0;
    const hasNextStudent = ui.activeStudentIndex < list.length - 1;
    if (btnMeetingStudentPrev) btnMeetingStudentPrev.disabled = !hasPrevStudent;
    if (btnMeetingStudentNext) btnMeetingStudentNext.disabled = !hasNextStudent;

    const s = activeStudent();
    if (!s) {
      meetingStepBody.innerHTML = `<div class="alert">${esc(tEntry('no_students_found'))}</div>`;
      return;
    }

    updateMeetingStudentBadge();

    const reportId = s.report_instance_id;
    if (meetingState.activeStudentId !== s.id) {
      meetingState.activeStudentId = s.id;
      meetingState.steps = collectMeetingSteps(reportId);
      meetingState.activeStep = 0;
    }

    if (!meetingState.steps.length) {
      meetingStepTitle.textContent = '—';
      meetingStepSub.textContent = '';
      meetingStepSub.style.display = 'none';
      meetingStepBody.innerHTML = `<div class="alert">${esc(tEntry('no_open_fields'))}</div>`;
      meetingNav.innerHTML = '';
      return;
    }

    if (meetingState.activeStep >= meetingState.steps.length) {
      meetingState.activeStep = meetingState.steps.length - 1;
    }

    meetingNav.innerHTML = '';
    meetingState.steps.forEach((step, idx) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'meeting-nav-item' + (idx === meetingState.activeStep ? ' active' : '');
      item.innerHTML = `
        <span>${esc(step.group.title || step.group.key)}</span>
        ${step.subgroupLabel ? `<span class="sub">${esc(step.subgroupLabel)}</span>` : ''}
      `;
      item.addEventListener('click', () => {
        meetingState.activeStep = idx;
        renderMeetingView();
      });
      meetingNav.appendChild(item);
    });

    const step = meetingState.steps[meetingState.activeStep];
    meetingStepTitle.textContent = String(step.group.title || step.group.key || '');
    meetingStepSub.textContent = step.subgroupLabel ? String(step.subgroupLabel) : '';
    meetingStepSub.style.display = step.subgroupLabel ? '' : 'none';

    const status = String(s.status || 'draft');
    const childLocked = isStudentInputLocked(status);
    const locked = isTeacherInputLocked(status);
    let html = '';
    if (!MEETING_MODE) {
      let childMissingFields = Array.isArray(s.child_missing_fields) ? s.child_missing_fields.filter(x => String(x).trim() !== '') : [];
      const unlockBtn = DELEGATED_MODE
        ? ''
        : `<div style="margin-top:8px;"><button class="btn secondary" type="button" data-unlock-child="${esc(reportId)}">${esc(tEntry('unlock_child'))}</button></div>`;

      if (childLocked) {
        const info = CHILD_MODE
          ? tEntry('locked_cannot_edit')
          : tEntry('locked_teacher_can_edit');
        html += `<div class="alert ${CHILD_MODE ? 'danger' : 'info'}"><strong>${esc(tEntry('locked_title'))}</strong> ${esc(info)}${unlockBtn}</div>`;
      } else if (status === 'submitted') {
        html += `<div class="alert info"><strong>${esc(tEntry('notice_label'))}</strong> ${esc(tEntry('locked_notice'))}${unlockBtn}</div>`;
      } else if (childMissingFields.length > 0) {
        const missingList = childMissingFields.slice(0, 8).map(esc).join(', ');
        const more = childMissingFields.length > 8
          ? ` … (${esc(tfmtEntry('child_missing_more', { count: childMissingFields.length - 8 }))})`
          : '';
        html += `<div class="alert warning"><strong>${esc(tEntry('child_missing_title'))}</strong> ${esc(tEntry('child_missing_fields'))} ${missingList}${more}</div>`;
      }

      if (state.ai_enabled && !CHILD_MODE) {
        const optionStatus = optionCompletionForStudent(reportId);
        const missingOpt = optionStatus.total > 0 ? optionStatus.missing : 0;
        const aiSubtitle = missingOpt > 0
          ? tfmtEntry('ai_require_options_start', { missing: missingOpt, total: optionStatus.total })
          : tEntry('ai_option_ideas');
        const aiButton = missingOpt > 0
          ? `<a class="btn secondary ai-btn" type="button" disabled title="${esc(tEntry('ai_open_disabled_title'))}">${AI_ICON} ${esc(tEntry('ai_open'))}</a>`
          : `<a class="btn secondary ai-btn" type="button" data-ai-student="${esc(reportId)}">${AI_ICON} ${esc(tEntry('ai_open'))}</a>`;
        html += `
          <div class="ai-banner">
            <div>
              <div class="t">${AI_ICON} ${esc(tfmtEntry('ai_banner_title', { name: s.name }))}</div>
              <div class="muted">${esc(aiSubtitle)}</div>
            </div>
            ${aiButton}
          </div>
        `;
      }
    }

    html += renderStudentFields(step.fields, reportId, locked, { showSubgroups: false });
    meetingStepBody.innerHTML = html || `<div class="alert">${esc(tEntry('no_open_fields'))}</div>`;

    if (!CHILD_MODE && !DELEGATED_MODE) {
      wireChildValueControls(meetingStepBody);
    }
    wireActiveInputs(meetingStepBody);

    if (ui.showChild) {
      meetingStepBody.querySelectorAll('[data-fieldwrap="1"]').forEach(el => {
        el.classList.add('show-child');
      });
    }

    meetingStepBody.querySelectorAll('[data-ai-student]').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-ai-student') || 0);
        const stu = (state.students || []).find(x => Number(x.report_instance_id || 0) === rid);
        if (stu) requestAiSuggestionsForStudent(stu);
      });
    });

    meetingStepBody.querySelectorAll('[data-unlock-child]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        const rid = Number(btn.getAttribute('data-unlock-child') || 0);
        if (rid > 0) await unlockChildEntry(rid);
      });
    });

    if (btnMeetingPrev) btnMeetingPrev.disabled = meetingState.activeStep <= 0;
    if (btnMeetingNext) btnMeetingNext.disabled = meetingState.activeStep >= meetingState.steps.length - 1;
  }

  function syncGradeHeaderScroll(){
    if (!gradeBodyScroller || !gradeHeadScroller) return;
    gradeHeadScroller.scrollLeft = gradeBodyScroller.scrollLeft;
  }

  function updateFixedHeaderHeight(){
    const h = fixedHeader ? Math.ceil(fixedHeader.getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty('--fixed-header-height', `${h}px`);
  }

  function syncGradeColWidths(){
    if (!gradeTableHead || !gradeTableBody || !gradeColGroupHead || !gradeColGroupBody) return;
    const bodyRow = gradeTableBody.querySelector('tbody tr');
    const refCells = bodyRow ? Array.from(bodyRow.children) : Array.from(gradeTableHead.querySelectorAll('thead th'));
    if (!refCells.length) return;

    gradeColGroupHead.innerHTML = '';
    gradeColGroupBody.innerHTML = '';

    let total = 0;
    refCells.forEach(cell => {
      const w = Math.ceil(cell.getBoundingClientRect().width);
      total += w;
      const c1 = document.createElement('col');
      const c2 = document.createElement('col');
      c1.style.width = `${w}px`;
      c2.style.width = `${w}px`;
      gradeColGroupHead.appendChild(c1);
      gradeColGroupBody.appendChild(c2);
    });

    gradeTableHead.style.width = `${total}px`;
    gradeTableBody.style.width = `${total}px`;
  }

  let gradeResizeTimer = null;
  function scheduleGradeSync(){
    if (gradeResizeTimer) window.clearTimeout(gradeResizeTimer);
    gradeResizeTimer = window.setTimeout(() => {
      updateFixedHeaderHeight();
      syncGradeColWidths();
      syncGradeHeaderScroll();
    }, 120);
  }

  function renderGradesView(){
    ensureSelect(gradeGroupSelect);

    if (gradeOrientation && gradeOrientation.value !== ui.gradeOrientation) {
      gradeOrientation.value = ui.gradeOrientation;
    }

    ui.gradeGroupKey = gradeGroupSelect.value || 'ALL';
    const groupFilter = parseGroupFilterValue(ui.gradeGroupKey);
    const filter = normalize(ui.gradeFilter);

    let fields = gradeFields(activeGroups());
    if (groupFilter.groupKey !== 'ALL') fields = fields.filter(f => f._group_key === groupFilter.groupKey);
    fields = filterFieldsBySubgroup(fields, groupFilter.subgroup);
    if (filter) fields = fields.filter(f => normalize(f.label || f.field_name).includes(filter) || normalize(f.field_name).includes(filter));

    const sCols = filterStudentsForMissing(state.students);

    if (ui.studentMissingOnly) {
      fields = fields.filter(f => fieldMissingForAnyStudent(f, sCols));
    }

    if (fields.length === 0 || sCols.length === 0) {
      gradeHead.innerHTML = `<tr><th class="sticky">—</th><th>${esc(tEntry('no_grade_fields'))}</th></tr>`;
      gradeBody.innerHTML = '';
      return;
    }

    if (ui.gradeOrientation === 'students_cols') {
      gradeHead.innerHTML = '';
      const tr = document.createElement('tr');
      const th0 = document.createElement('th');
      th0.className = 'sticky';
      th0.textContent = tEntry('grade_header');
      tr.appendChild(th0);

      sCols.forEach(s => {
        const th = document.createElement('th');
        const status = String(s.status || 'draft');
        const statusLbl = (status === 'locked')
          ? tEntry('status_locked')
          : (status === 'submitted' ? tEntry('status_submitted') : tEntry('status_draft'));
        th.innerHTML = `<div style="font-weight:800;">${esc(s.name)}</div><div class="muted" style="font-size:12px;">${esc(statusLbl)}</div>`;
        tr.appendChild(th);
      });
      gradeHead.appendChild(tr);

      gradeBody.innerHTML = '';
      fields.forEach(f => {
        const row = document.createElement('tr');

        const tdLabel = document.createElement('td');
        tdLabel.className = 'sticky';
        const lbl = resolveLabelTemplate(String(f.label || f.field_name));
        tdLabel.innerHTML = `<div style="font-weight:800;">${esc(f._group_title || '')}</div><div class="muted" style="font-size:12px;">${esc(lbl)}</div>`;
        row.appendChild(tdLabel);

        sCols.forEach(s => {
          const td = document.createElement('td');
          const reportId = s.report_instance_id;
          const status = String(s.status||'draft');
          const locked = isTeacherInputLocked(status);
          const v = activeFieldValue(reportId, f.id);
          const canEditField = (Number(f.can_edit || 0) === 1);

          const missingCls = (v === '') ? 'missing' : '';
          const combinedHtml = CHILD_MODE ? '' : combinedPreviewHtml(reportId, f);
          const historyHtml = CHILD_MODE ? '' : renderHistoryHtml(reportId, f.id);
          const actionsHtml = (combinedHtml || historyHtml)
            ? `<div class="field-actions">${combinedHtml}${historyHtml}</div>`
            : '';
          td.innerHTML = `
            <div class="cellWrap ${missingCls}">
              ${renderActiveInputHtml(f, reportId, v, locked, canEditField)}
              ${actionsHtml}
              ${(!CHILD_MODE && f.child && f.child.id) ? `<div class="cellChild">${childInfoHtml(f, reportId)}</div>` : ''}
            </div>
          `;
          row.appendChild(td);
        });

      gradeBody.appendChild(row);
    });

    requestAnimationFrame(() => {
      updateFixedHeaderHeight();
      syncGradeColWidths();
      syncGradeHeaderScroll();
    });
    wireActiveInputs(gradeBody);
    if (!CHILD_MODE && !DELEGATED_MODE) {
      wireChildValueControls(gradeBody);
    }
    return;
  }

    gradeHead.innerHTML = '';
    const tr1 = document.createElement('tr');
    const th0 = document.createElement('th');
    th0.className = 'sticky';
    th0.textContent = tEntry('student_header');
    tr1.appendChild(th0);

    const groupOrder = [];
    const groupCounts = {};
    fields.forEach(f => {
      const k = f._group_title || '—';
      if (!groupCounts[k]) { groupCounts[k] = 0; groupOrder.push(k); }
      groupCounts[k]++;
    });
    groupOrder.forEach(k => {
      const th = document.createElement('th');
      th.colSpan = groupCounts[k];
      th.style.textAlign = 'left';
      th.innerHTML = `<div style="font-weight:800;">${esc(k)}</div>`;
      tr1.appendChild(th);
    });
    gradeHead.appendChild(tr1);

    const tr2 = document.createElement('tr');
    const thS = document.createElement('th');
    thS.className = 'sticky';
    thS.innerHTML = `<span class="muted">${esc(tEntry('name_label'))}</span>`;
    tr2.appendChild(thS);

    fields.forEach(f => {
      const th = document.createElement('th');
      const lbl = resolveLabelTemplate(String(f.label || f.field_name));
      th.innerHTML = `<div  class="muted" style="font-size:12px;">${esc(lbl)}</div>`;
      tr2.appendChild(th);
    });
    gradeHead.appendChild(tr2);

    gradeBody.innerHTML = '';
    sCols.forEach(s => {
      const tr = document.createElement('tr');

      const tdName = document.createElement('td');
      tdName.className = 'sticky';
      const status = String(s.status || 'draft');
      const statusLbl = (status === 'locked')
        ? tEntry('status_locked')
        : (status === 'submitted' ? tEntry('status_submitted') : tEntry('status_draft'));
      tdName.innerHTML = `<div style="font-weight:800;">${esc(s.name)}</div><div class="muted" style="font-size:12px;">${esc(statusLbl)}</div>`;
      tr.appendChild(tdName);

      fields.forEach(f => {
        const td = document.createElement('td');
        const reportId = s.report_instance_id;
        const locked = isTeacherInputLocked(status);
        const v = activeFieldValue(reportId, f.id);
        const canEditField = (Number(f.can_edit || 0) === 1);

        const missingCls = (v === '') ? 'missing' : '';
        td.innerHTML = `
          <div class="cellWrap ${missingCls}">
            ${renderActiveInputHtml(f, reportId, v, locked, canEditField)}
            ${(!CHILD_MODE && f.child && f.child.id) ? `<div class="cellChild">${childInfoHtml(f, reportId)}</div>` : ''}
          </div>
        `;
        tr.appendChild(td);
      });

      gradeBody.appendChild(tr);
    });

    requestAnimationFrame(() => {
      updateFixedHeaderHeight();
      syncGradeColWidths();
      syncGradeHeaderScroll();
    });
    wireActiveInputs(gradeBody);
    if (!CHILD_MODE && !DELEGATED_MODE) {
      wireChildValueControls(gradeBody);
    }
  }

  function renderItemView(){
    ensureGroupsSelect();
    ui.groupKey = groupSelect.value || 'ALL';
    const groupFilter = parseGroupFilterValue(ui.groupKey);

    const filter = normalize(ui.itemFilter);
    const groups = (groupFilter.groupKey === 'ALL') ? activeGroups() : activeGroups().filter(g => g.key === groupFilter.groupKey);
    let fields = [];
    groups.forEach(g => fields.push(...g.fields.map(f => ({...f, _group_title:g.title, _group_key:g.key}))));
    fields = filterFieldsBySubgroup(fields, groupFilter.subgroup);

    if (filter) fields = fields.filter(f => normalize(f.label || f.field_name).includes(filter) || normalize(f.field_name).includes(filter));

    const sCols = filterStudentsForMissing(state.students);

    if (ui.studentMissingOnly) {
      fields = fields.filter(f => fieldMissingForAnyStudent(f, sCols));
    }

    if (fields.length === 0 || sCols.length === 0) {
      itemHead.innerHTML = `<tr><th class="sticky">—</th><th>${esc(tEntry('no_items_found'))}</th></tr>`;
      itemBody.innerHTML = '';
      return;
    }

    itemHead.innerHTML = '';
    const tr = document.createElement('tr');
    const th0 = document.createElement('th');
    th0.className = 'sticky';
    th0.textContent = tEntry('item_header');
    tr.appendChild(th0);
    sCols.forEach(s => {
      const th = document.createElement('th');
      th.textContent = s.name;
      tr.appendChild(th);
    });
    itemHead.appendChild(tr);

    itemBody.innerHTML = '';
    fields.forEach(f => {
      const row = document.createElement('tr');
      const tdLabel = document.createElement('td');
      tdLabel.className = 'sticky';
      const lbl = resolveLabelTemplate(String(f.label || f.field_name));
      const subgroupKey = String(f.subgroup || '').trim();
      const subgroupLabel = subgroupLabelForLang(subgroupKey, f.subgroup_title_en);
      const subgroupHtml = subgroupLabel ? `<div class="subgroup-label">${esc(subgroupLabel)}</div>` : '';
      tdLabel.innerHTML = `<div style="font-weight:800;">${esc(lbl)}</div><div class="muted" style="font-size:12px;">${esc(f._group_title)}</div>${subgroupHtml}`;
      row.appendChild(tdLabel);

      sCols.forEach(s => {
        const td = document.createElement('td');
        const reportId = s.report_instance_id;
        const status = String(s.status||'draft');
        const locked = isTeacherInputLocked(status);
        const v = activeFieldValue(reportId, f.id);
        const canEditField = (Number(f.can_edit || 0) === 1);

        const missingCls = (v === '') ? 'missing' : '';
          const combinedHtml = CHILD_MODE ? '' : combinedPreviewHtml(reportId, f);
          const historyHtml = CHILD_MODE ? '' : renderHistoryHtml(reportId, f.id);
          const actionsHtml = (combinedHtml || historyHtml)
            ? `<div class="field-actions">${combinedHtml}${historyHtml}</div>`
            : '';
          td.innerHTML = `
          <div class="cellWrap ${missingCls}">
            ${renderActiveInputHtml(f, reportId, v, locked, canEditField)}
            ${actionsHtml}
            ${(!CHILD_MODE && f.child && f.child.id) ? `<div class="cellChild">${childInfoHtml(f, reportId)}</div>` : ''}
          </div>
        `;
        row.appendChild(td);
      });

      itemBody.appendChild(row);
    });

    wireActiveInputs(itemBody);
    if (!CHILD_MODE && !DELEGATED_MODE) {
      wireChildValueControls(itemBody);
    }
  }

  async function loadClass(classId, options = {}){
    const forceTemplateReplace = !!options.forceTemplateReplace;
    setLoading(true);
    try {
      clearErr();
      elApp.style.display = 'none';
      setSaveStatus('idle', tEntry('save_idle'));
      const payload = { class_id: classId };
      if (forceTemplateReplace) payload.confirm_template_replace = 1;
      const j = await api('load', payload);

      state.class_id = classId;
      state.template = j.template;
      state.groups = j.groups;
      
      // In delegated mode: show ONLY groups delegated to current user (hide everything else completely)
      // unless read-only visibility is enabled for other fields.
      if (DELEGATED_MODE && (!DELEGATED_READONLY_VISIBLE || !delegatedShowOtherFields)) {
        const uid = CURRENT_USER_ID;
        state.groups = (state.groups || []).filter(g => {
          const delUids = Array.isArray(g?.delegation?.user_ids)
            ? g.delegation.user_ids.map(x => Number(x)).filter(x => x > 0)
            : [];
          return delUids.includes(uid);
        });
      }

      state.child_groups = buildChildGroups();
      
      state.delegation_users = j.delegation_users || [];
      state.delegations = j.delegations || [];
      state.period_label = j.period_label || <?=json_encode($classPeriodLabel)?>;

      // reset group selects (delegation badges etc.)
      groupSelect.innerHTML = '';
      gradeGroupSelect.innerHTML = '';
      if (studentGroupSelect) studentGroupSelect.innerHTML = '';
      state.students = j.students;
      state.values_teacher = j.values_teacher || {};
      state.values_teacher_own = j.values_teacher_own || {};
      state.values_teacher_parts = j.values_teacher_parts || {};
      state.values_child = j.values_child || {};
      state.locked_child_fields = j.locked_child_fields || {};
      state.value_history = j.value_history || {};
      state.class_report_instance_id = j.class_report_instance_id || 0;
      state.class_fields = j.class_fields || null;
      state.progress_summary = j.progress_summary || null;
      state.text_snippets = j.text_snippets || [];
      state.ai_enabled = !!j.ai_enabled;
      state.class_grade_level = j.class_grade_level || null;
      state.is_class_teacher = !!j.is_class_teacher;
      aiCache = new Map();
      aiCurrentStudent = null;
      ui.mergeDecisions = new Map();
      const savedDecisions = readMergeMemory();
      Object.entries(savedDecisions).forEach(([k, v]) => {
        if (!v || typeof v !== 'object') return;
        const decision = (v.decision === 'combine' || v.decision === 'overwrite') ? v.decision : null;
        const settled = v.settled === true;
        if (decision) ui.mergeDecisions.set(k, { decision, settled });
      });

      // In delegated mode: class fields should not be visible/editable here
      // unless read-only visibility is enabled.
      if (DELEGATED_MODE && (!DELEGATED_READONLY_VISIBLE || !delegatedShowOtherFields)) {
        state.class_fields = null;
      }

      rebuildFieldMap();
      
      if (DELEGATED_MODE && (!state.groups || state.groups.length === 0)) {
        elApp.style.display = 'block';
        if (classFieldsBox) classFieldsBox.style.display = 'none';
        elMetaTop.textContent = tEntry('no_delegations');
        viewGrades.style.display = 'none';
        viewStudent.style.display = 'none';
        viewItem.style.display = 'none';
        showErr(tEntry('no_delegations_class'));
        return;
      }
      
      // keep client-side progress consistent (teacher edits update live)
      (state.students||[]).forEach(recomputeStudentProgress);
      recomputeFormsSummary();
      dbg('loaded', { class_id: state.class_id, class_report_instance_id: state.class_report_instance_id, class_fields_count: (state.class_fields?.fields||[]).length });

      renderSnippetList();
      refreshSnippetCategoryList();
      updateSnippetSelectionUI();

      applyStoredView();
      ui.activeStudentIndex = 0;
      groupSelect.innerHTML = '';
      gradeGroupSelect.innerHTML = '';
      if (studentGroupSelect) studentGroupSelect.innerHTML = '';
      gradeSearch.value = '';
      itemSearch.value = '';
      studentSearch.value = '';
      ui.studentFilter = '';
      ui.studentGroupKey = 'ALL';
      ui.itemFilter = '';
      ui.gradeFilter = '';

      elApp.style.display = 'block';
      render();
    } catch (e) {
      const rawMsg = String(e?.message || e || '');
      const prefix = '__TEMPLATE_SWITCH_REQUIRED__';
      if (!forceTemplateReplace && rawMsg.startsWith(prefix)) {
        let info = null;
        try { info = JSON.parse(rawMsg.slice(prefix.length)); } catch (_err) { info = null; }
        const confirmMsg = String(info?.message || TEMPLATE_CONFLICT_CONFIRM_FALLBACK);
        if (window.confirm(confirmMsg)) {
          await loadClass(classId, { forceTemplateReplace: true });
          return;
        }
      }
      throw e;
    } finally {
      setLoading(false);
    }
  }

// --- delegations modal ---
function openDelegations(preselectGroupKey){
  if (!dlg) return;
  if (DELEGATED_MODE) return; // delegierter darf hier nicht delegieren
  dlg.style.display = 'block';

  // groups dropdown
  dlgGroup.innerHTML = '';
  (state.groups||[]).forEach(g => {
    const opt = document.createElement('option');
    opt.value = g.key;
    opt.textContent = g.title || g.key;
    dlgGroup.appendChild(opt);
  });

  if (preselectGroupKey) {
    dlgGroup.value = String(preselectGroupKey);
  }
  if (!dlgGroup.value && dlgGroup.options.length) dlgGroup.value = dlgGroup.options[0].value;

  // users dropdown
  dlgUsers.innerHTML = '';
  (state.delegation_users||[]).forEach(u => {
    const wrap = document.createElement('label');
    wrap.style.display = 'flex';
    wrap.style.alignItems = 'center';
    wrap.style.gap = '8px';
    wrap.style.padding = '4px 2px';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = String(u.id);
    cb.dataset.userCheckbox = '1';

    const txt = document.createElement('span');
    txt.textContent = `${u.name}${u.role==='admin' ? ' (Admin)' : ''}`;

    wrap.appendChild(cb);
    wrap.appendChild(txt);
    dlgUsers.appendChild(wrap);
  });

  // sync form with selected group
  syncDelegationForm();
  renderDelegationsList();
}

function closeDelegations(){
  if (!dlg) return;
  dlg.style.display = 'none';
}

  function openDoneModal(preselectGroupKey){
    if (!dlgDone) return;
    dlgDone.style.display = 'block';

    // dropdown groups = state.groups (already delegated-only)
    dlgDoneGroup.innerHTML = '';
    (state.groups||[]).forEach(g => {
      const opt = document.createElement('option');
      opt.value = g.key;
      opt.textContent = g.title || g.key;
      dlgDoneGroup.appendChild(opt);
    });

    if (preselectGroupKey) dlgDoneGroup.value = String(preselectGroupKey);
    if (!dlgDoneGroup.value && dlgDoneGroup.options.length) dlgDoneGroup.value = dlgDoneGroup.options[0].value;

    syncDoneForm();
    renderDoneList();
  }

  function closeDoneModal(){
    if (!dlgDone) return;
    dlgDone.style.display = 'none';
  }

  function syncDoneForm(){
    const gk = String(dlgDoneGroup.value || '');
    const g = (state.groups||[]).find(x => String(x.key) === gk);
    const del = g && g.delegation ? g.delegation : null;
    const mine = delegationSelfEntry(del);

    dlgDoneStatus.value = (mine && mine.status) ? String(mine.status) : 'open';
    dlgDoneNote.value = (mine && mine.note) ? String(mine.note) : '';
  }

  function renderDoneList(){
    if (!dlgDoneList) return;
    const rows = [];

    (state.groups||[]).forEach(g => {
      const del = g.delegation || null;
      const mine = delegationSelfEntry(del);
      const delNames = delegationNames(del);
      const statusLbl = (mine && mine.status === 'done') ? tEntry('status_done') : tEntry('status_open');
      const note = String(mine?.note || '').trim();

      rows.push(`
        <div class="del-row">
          <div class="l">
            <div class="t">${esc(g.title || g.key)}</div>
            <div class="s">${delNames ? '→ ' + esc(delNames) + ' · ' : ''}${esc(statusLbl)}${note ? ' · ' + esc(note) : ''}</div>
          </div>
          <button class="btn secondary" type="button" data-done-edit="${esc(g.key)}">${esc(tEntry('delegation_edit'))}</button>
        </div>
      `);
    });

    dlgDoneList.innerHTML = rows.length ? rows.join('') : `<div class="muted">${esc(tEntry('delegation_done_empty'))}</div>`;

    dlgDoneList.querySelectorAll('[data-done-edit]').forEach(btn => {
      btn.addEventListener('click', () => {
        const gk = String(btn.getAttribute('data-done-edit') || '');
        if (gk) openDoneModal(gk);
      });
    });
  }

function syncDelegationForm(){
  const gk = String(dlgGroup.value || '');
  const g = (state.groups||[]).find(x => String(x.key) === gk);
  const del = g && g.delegation ? g.delegation : null;

  const ids = Array.isArray(del?.user_ids) ? del.user_ids.map(x => Number(x)).filter(x => x > 0) : [];
  dlgUsers.querySelectorAll('input[data-user-checkbox="1"]').forEach(cb => {
    cb.checked = ids.includes(Number(cb.value || 0));
  });
  dlgStatus.value = (del && del.status) ? String(del.status) : 'open';
  dlgNote.value = (del && del.note) ? String(del.note) : '';
}

function renderDelegationsList(){
  if (!dlgList) return;
  const rows = [];
  (state.groups||[]).forEach(g => {
    const del = g.delegation;
    const users = Array.isArray(del?.users) ? del.users : [];
    if (!users.length) return;
    const names = users.map(u => {
      const nm = u.user_name || ('#'+u.user_id);
      if (!nm) return '';
      return (u.status === 'done') ? `${nm} ✓` : nm;
    }).filter(Boolean).join(', ');
    const statusLbl = delegationAllDone(del) ? tEntry('status_done') : tEntry('status_open');
    const note = String(del.note || '').trim();
    rows.push(`
      <div class="del-row">
        <div class="l">
          <div class="t">${esc(g.title || g.key)}</div>
          <div class="s">→ ${esc(names)} · ${esc(statusLbl)}${note ? ' · ' + esc(note) : ''}</div>
        </div>
        <button class="btn secondary" type="button" data-clear-deleg="${esc(g.key)}">${esc(tEntry('delegation_clear'))}</button>
      </div>
    `);
  });
  dlgList.innerHTML = rows.length ? rows.join('') : `<div class="muted">${esc(tEntry('delegation_empty'))}</div>`;

  dlgList.querySelectorAll('[data-clear-deleg]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const gk = String(btn.getAttribute('data-clear-deleg') || '');
      if (!gk) return;
      await api('delegations_save', { class_id: state.class_id, period_label: state.period_label, delegations: [{ group_key: gk, user_id: 0 }] });
      await loadClass(state.class_id);
      openDelegations();
    });
  });
}

if (btnDelegationsTop) btnDelegationsTop.addEventListener('click', () => openDelegations());

  if (btnDelegationDoneTop) {
    btnDelegationDoneTop.addEventListener('click', () => openDoneModal());
  }

  if (dlgDone) {
    dlgDone.querySelectorAll('[data-close="1"]').forEach(el => el.addEventListener('click', () => closeDoneModal()));
  }

  if (dlgDoneGroup) {
    dlgDoneGroup.addEventListener('change', () => syncDoneForm());
  }

  if (dlgDoneSave) {
    dlgDoneSave.addEventListener('click', async () => {
      const gk = String(dlgDoneGroup.value || '').trim();
      if (!gk) return;

      const status = String(dlgDoneStatus.value || 'open');
      const note = String(dlgDoneNote.value || '');

      await api('delegations_mark', {
        class_id: state.class_id,
        period_label: state.period_label,
        group_key: gk,
        status,
        note
      });

      await loadClass(state.class_id);
      openDoneModal(gk);
    });
  }

if (dlg) {
  dlg.querySelectorAll('[data-close="1"]').forEach(el => el.addEventListener('click', () => closeDelegations()));
}

if (dlgGroup) {
  dlgGroup.addEventListener('change', () => syncDelegationForm());
}

if (dlgSave) {
  dlgSave.addEventListener('click', async () => {
    const gk = String(dlgGroup.value || '').trim();
    if (!gk) return;
    const userIds = Array.from(dlgUsers.querySelectorAll('input[data-user-checkbox="1"]:checked'))
      .map(cb => Number(cb.value || 0))
      .filter(v => v > 0);
    const status = String(dlgStatus.value || 'open');
    const note = String(dlgNote.value || '');

    await api('delegations_save', {
      class_id: state.class_id,
      period_label: state.period_label,
      delegations: [{ group_key: gk, user_ids: userIds, status, note }]
    });

    await loadClass(state.class_id);
    openDelegations();
  });
}

  if (btnSnippetToggle) {
    btnSnippetToggle.addEventListener('click', () => {
      const show = !snippetDrawer || snippetDrawer.style.display === 'none';
      openSnippetDrawer(show);
    });
  }

  if (btnSnippetClose) {
    btnSnippetClose.addEventListener('click', () => openSnippetDrawer(false));
  }

  if (btnAiClose) {
    btnAiClose.addEventListener('click', closeAiDialog);
  }
  if (aiBackdrop) {
    aiBackdrop.addEventListener('click', closeAiDialog);
  }
  if (btnAiRefresh) {
    btnAiRefresh.addEventListener('click', () => {
      if (aiCurrentStudent) requestAiSuggestionsForStudent(aiCurrentStudent, true);
    });
  }

  if (btnSnippetSave) {
    btnSnippetSave.addEventListener('click', async () => {
      const rawText = (lastSnippetSelection && lastSnippetSelection.trim()) || (lastSnippetTarget ? String(lastSnippetTarget.value || '').trim() : '');
      if (!rawText) { alert(tEntry('snippet_no_text')); return; }
      const titleTyped = snippetTitle ? String(snippetTitle.value || '').trim() : '';
      const cat = snippetCategory ? String(snippetCategory.value || '').trim() : '';
      const derivedTitle = titleTyped !== '' ? titleTyped : (rawText.length > 40 ? rawText.slice(0, 40) + '…' : rawText);
      try {
        const j = await api('snippet_save', { title: derivedTitle, category: cat, content: rawText });
        if (j.snippet) state.text_snippets.push(j.snippet);
        renderSnippetList();
        refreshSnippetCategoryList();
        if (snippetTitle) snippetTitle.value = '';
        updateSnippetSelectionUI();
      } catch (e) {
        showErr(friendlyFetchError(e));
      }
    });
  }

  classSelect.addEventListener('change', () => {
    void withSavedChanges(async () => {
      const cid = Number(classSelect.value || '0');
      if (cid > 0) {
        history.replaceState(null, '', `?class_id=${encodeURIComponent(String(cid))}`);
        await loadClass(cid);
      }
    }).catch(e => showErr(friendlyFetchError(e)));
  });

  applyStoredView();
  viewSelect.addEventListener('change', () => {
    void withSavedChanges(async () => {
      saveViewSelection();
      renderWithLoading();
    });
  });
  if (toggleChild) {
    toggleChild.addEventListener('change', () => render());
  }
  updateSwitchLinks();
  updateMeetingLink();
  if (classSelect) {
    classSelect.addEventListener('change', updateSwitchLinks);
  }

  studentSearch.addEventListener('input', () => {
    ui.studentFilter = studentSearch.value;
    ui.activeStudentIndex = 0;
    renderStudentView();
  });

  if (studentGroupSelect) {
    studentGroupSelect.addEventListener('change', () => {
      void withSavedChanges(async () => {
        ui.studentGroupKey = studentGroupSelect.value || 'ALL';
        ui.activeStudentIndex = 0;
        renderStudentView();
      });
    });
  }

  studentMissingOnly.addEventListener('change', () => {
    ui.studentMissingOnly = !!studentMissingOnly.checked;
    ui.activeStudentIndex = 0;
    render();
  });

  optionButtonsToggle.addEventListener('change', () => {
    ui.optionMode = optionButtonsToggle.checked ? 'buttons' : 'dropdown';
    localStorage.setItem(OPTION_STYLE_KEY, ui.optionMode);
    render();
  });

  btnPrevStudent.addEventListener('click', () => {
    void withSavedChanges(async () => {
      ui.activeStudentIndex = Math.max(0, ui.activeStudentIndex - 1);
      renderStudentView();
    });
  });
  btnNextStudent.addEventListener('click', () => {
    void withSavedChanges(async () => {
      ui.activeStudentIndex = ui.activeStudentIndex + 1;
      renderStudentView();
    });
  });

  groupSelect.addEventListener('change', () => {
    void withSavedChanges(async () => renderItemView());
  });
  itemSearch.addEventListener('input', () => { ui.itemFilter = itemSearch.value; renderItemView(); });

  gradeGroupSelect.addEventListener('change', () => {
    void withSavedChanges(async () => renderGradesView());
  });
  gradeSearch.addEventListener('input', () => { ui.gradeFilter = gradeSearch.value; renderGradesView(); });

  gradeOrientation.value = ui.gradeOrientation;
  gradeOrientation.addEventListener('change', () => {
    void withSavedChanges(async () => {
      ui.gradeOrientation = gradeOrientation.value || 'students_rows';
      localStorage.setItem('leb_grade_orientation', ui.gradeOrientation);
      renderGradesView();
    });
  });

  window.addEventListener('keydown', (ev) => {
    if (ev.altKey && !ev.ctrlKey && !ev.metaKey) {
      const k = ev.key.toLowerCase();
    if (k === 's' && toggleChild) { ev.preventDefault(); toggleChild.checked = !toggleChild.checked; render(); }
      if (k === 'm') {
        ev.preventDefault();
        const order = ['grades','student','item'];
        const cur = viewSelect.value || 'grades';
        const idx = order.indexOf(cur);
        viewSelect.value = order[(idx + 1) % order.length];
        void withSavedChanges(async () => {
          saveViewSelection();
          renderWithLoading();
        });
      }
      if (k === 'arrowleft') {
        if (btnPrevStudent && !btnPrevStudent.disabled) {
          ev.preventDefault();
          btnPrevStudent.click();
        }
      }
      if (k === 'arrowright') {
        if (btnNextStudent && !btnNextStudent.disabled) {
          ev.preventDefault();
          btnNextStudent.click();
        }
      }
    }
  });

  window.addEventListener('beforeunload', (event) => {
    if (!hasPendingSaves()) return;
    flushPendingSaves();
    const msg = tEntry('save_unsaved_changes');
    event.preventDefault();
    event.returnValue = msg;
    return msg;
  });
  window.addEventListener('pagehide', flushPendingSaves);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flushPendingSaves();
  });

  const initialClassId = Number(classSelect.value || <?=json_encode((int)$classId)?> || 0);
  if (initialClassId > 0) {
    loadClass(initialClassId).catch(e => showErr(friendlyFetchError(e)));
  } else {
    showErr(tEntry('no_class_available'));
  }
})();
</script>

<script src="<?=h(url('assets/pdf-lib.min.js'))?>"></script>
<script type="module">
  import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
  pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

  const finalmarksForm = document.getElementById('finalmarksForm');
  const finalmarksPdf = document.getElementById('finalmarksPdf');
  const finalmarksBlocks = document.getElementById('finalmarksBlocks');
  const finalmarksFileHash = document.getElementById('finalmarksFileHash');
  const finalmarksFileName = document.getElementById('finalmarksFileName');
  const FINALMARKS_SELECT_PDF = <?=json_encode(t('teacher.entry.finalmarks.select_pdf_alert'))?>;
  const FINALMARKS_PARSE_ERROR = <?=json_encode(t('teacher.entry.finalmarks.parse_error_alert'))?>;

  async function fileHashHex(buffer) {
    if (!crypto?.subtle) return '';
    const digest = await crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
  }

  function textBlocksFromItems(items) {
    const lines = new Map();
    for (const item of items) {
      const y = Math.round(item.transform[5]);
      const x = item.transform[4];
      if (!lines.has(y)) lines.set(y, []);
      lines.get(y).push({ x, str: item.str });
    }
    const sortedYs = Array.from(lines.keys()).sort((a, b) => b - a);
    return sortedYs.map(y => {
      const parts = lines.get(y) || [];
      parts.sort((a, b) => a.x - b.x);
      return parts.map(p => p.str).join(' ').trim();
    }).filter(Boolean);
  }

  async function parsePdfToBlocks(file) {
    const buffer = await file.arrayBuffer();
    const pdfDoc = await pdfjsLib.getDocument({ data: buffer }).promise;
    const blocks = [];
    for (let i = 1; i <= pdfDoc.numPages; i += 1) {
      const page = await pdfDoc.getPage(i);
      const content = await page.getTextContent({ normalizeWhitespace: true });
      const lines = textBlocksFromItems(content.items || []);
      blocks.push(lines.join("\n"));
    }
    return { blocks, hash: await fileHashHex(buffer) };
  }

  if (finalmarksForm && finalmarksPdf && finalmarksBlocks) {
    finalmarksForm.addEventListener('submit', async (ev) => {
      if (finalmarksForm.dataset.parsed === '1') return;
      ev.preventDefault();
      const file = finalmarksPdf.files && finalmarksPdf.files[0];
      if (!file) {
        alert(FINALMARKS_SELECT_PDF);
        return;
      }
      try {
        finalmarksForm.dataset.parsed = '1';
        const result = await parsePdfToBlocks(file);
        finalmarksBlocks.value = JSON.stringify(result.blocks);
        if (finalmarksFileHash) finalmarksFileHash.value = result.hash || '';
        if (finalmarksFileName) finalmarksFileName.value = file.name || '';
        finalmarksForm.submit();
      } catch (err) {
        finalmarksForm.dataset.parsed = '';
        alert(FINALMARKS_PARSE_ERROR);
      }
    });
  }
</script>

<?php
render_teacher_footer();
?>
