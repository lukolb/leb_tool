<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);
$notifyPrefEnabled = false;

function meta_read(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function is_class_field(array $meta): bool {
  $scope = isset($meta['scope']) ? strtolower(trim((string)$meta['scope'])) : '';
  if ($scope === 'class') return true;
  if (isset($meta['is_class_field']) && (int)$meta['is_class_field'] === 1) return true;
  return false;
}

function is_system_bound(array $meta): bool {
  $tpl = $meta['system_binding_tpl'] ?? null;
  if (is_string($tpl) && trim($tpl) !== '') return true;
  $one = $meta['system_binding'] ?? null;
  if (is_string($one) && trim($one) !== '') return true;
  return false;
}

function group_key_from_meta(array $meta): string {
  $g = (string)($meta['group'] ?? '');
  $g = trim($g);
  return $g !== '' ? $g : 'Allgemein';
}

function format_minutes_short(?float $minutes): string {
  if ($minutes === null) return (string)t('teacher.progress.time_unknown', '–');
  $m = (int)round($minutes);
  if ($m <= 0) return '<1 min';
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) {
    return $h . 'h' . ($r > 0 ? ' ' . $r . 'min' : '');
  }
  return $m . ' min';
}

function class_label(array $c): string {
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $label = (string)($c['label'] ?? '');
  if ($grade !== null && $label !== '') return $grade . $label;
  $name = (string)($c['name'] ?? '');
  return $name !== '' ? $name : '';
}

function period_label_display(?string $raw): string {
  $val = normalize_class_period_label($raw);
  return $val === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
}

// Mail notification preference
if (db_has_table($pdo, 'teacher_notification_preferences')) {
  $pst = $pdo->prepare("SELECT wants_email FROM teacher_notification_preferences WHERE user_id=? LIMIT 1");
  $pst->execute([$userId]);
  $notifyPrefEnabled = ((int)($pst->fetchColumn() ?: 0) === 1);
}

$dashboardSummary = teacher_notification_summary($pdo, $userId);

// Delegations inbox count (groups delegated to this teacher)
$delegationCount = 0;
try {
  $st = $pdo->prepare("SELECT COUNT(*) FROM class_group_delegations WHERE user_id=?");
  $st->execute([$userId]);
  $delegationCount = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
  // ignore
}

// Load classes assigned to teacher (admins see all)
if (($u['role'] ?? '') === 'admin') {
  $st = $pdo->query("SELECT id, school_year, period_label, grade_level, label, name, template_id FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
  $classes = $st->fetchAll();
  $hasOwnClasses = !empty($classes);
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name, c.template_id,
            uca.user_id AS assigned_user_id, d.user_id AS delegated_user_id
     FROM classes c
     LEFT JOIN user_class_assignments uca ON uca.class_id=c.id AND uca.user_id=?
     LEFT JOIN class_group_delegations d ON d.class_id=c.id AND d.user_id=?
     WHERE is_active = 1
       AND (uca.user_id IS NOT NULL OR d.user_id IS NOT NULL)
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId, $userId]);
  $classes = $st->fetchAll();
  $hasOwnClasses = false;
  foreach ($classes as $c) {
    if (isset($c['assigned_user_id'])) {
      $hasOwnClasses = true;
      break;
    }
  }
}

function load_completion_field_sets(PDO $pdo, array $templateIds): array {
  $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), fn($x)=>$x>0)));
  if (!$templateIds) return [];

  $ph = implode(',', array_fill(0, count($templateIds), '?'));
  $st = $pdo->prepare(
    "SELECT id, template_id, can_child_edit, can_teacher_edit, is_required, meta_json
       FROM template_fields
      WHERE template_id IN ($ph)"
  );
  $st->execute($templateIds);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tplId = (int)$r['template_id'];
    $fid = (int)$r['id'];
    $meta = meta_read($r['meta_json'] ?? null);
    if (is_system_bound($meta) || is_class_field($meta)) continue;
    if (!isset($out[$tplId])) $out[$tplId] = ['child' => [], 'teacher' => []];
    if ((int)($r['is_required'] ?? 0) !== 1) continue;
    if ((int)$r['can_child_edit'] === 1) $out[$tplId]['child'][] = $fid;
    if ((int)$r['can_teacher_edit'] === 1) $out[$tplId]['teacher'][] = $fid;
  }
  return $out;
}

function load_grouped_teacher_fields(PDO $pdo, array $templateIds): array {
  $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), fn($x)=>$x>0)));
  if (!$templateIds) return [];

  $ph = implode(',', array_fill(0, count($templateIds), '?'));
  $st = $pdo->prepare(
    "SELECT id, template_id, is_required, meta_json
       FROM template_fields
      WHERE template_id IN ($ph)
        AND can_teacher_edit=1"
  );
  $st->execute($templateIds);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tplId = (int)$r['template_id'];
    $fid = (int)$r['id'];
    $meta = meta_read($r['meta_json'] ?? null);
    if (is_system_bound($meta) || is_class_field($meta)) continue;
    if ((int)($r['is_required'] ?? 0) !== 1) continue;
    $gk = group_key_from_meta($meta);
    if (!isset($out[$tplId])) $out[$tplId] = [];
    if (!isset($out[$tplId][$gk])) $out[$tplId][$gk] = [];
    $out[$tplId][$gk][] = $fid;
  }
  return $out;
}

function option_list_id_from_meta(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function base_field_key(string $fieldName): string {
  $s = strtolower(trim($fieldName));
  $s = explode('-', $s, 2)[0];
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  return trim($s);
}

function load_lock_field_sets(PDO $pdo, array $templateIds): array {
  $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), fn($x)=>$x>0)));
  if (!$templateIds) return ['child' => [], 'teacher' => []];

  $ph = implode(',', array_fill(0, count($templateIds), '?'));
  $st = $pdo->prepare(
    "SELECT id, template_id, field_name, field_type, can_child_edit, can_teacher_edit, meta_json
       FROM template_fields
      WHERE template_id IN ($ph)"
  );
  $st->execute($templateIds);

  $out = ['child' => [], 'teacher' => []];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tplId = (int)$r['template_id'];
    $meta = meta_read($r['meta_json'] ?? null);
    if (is_system_bound($meta) || is_class_field($meta)) continue;
    if (!isset($out['child'][$tplId])) $out['child'][$tplId] = [];
    if (!isset($out['teacher'][$tplId])) $out['teacher'][$tplId] = [];
    if ((int)$r['can_child_edit'] === 1) {
      $out['child'][$tplId][] = [
        'id' => (int)$r['id'],
        'template_id' => $tplId,
        'field_name' => (string)($r['field_name'] ?? ''),
      ];
    }
    if ((int)$r['can_teacher_edit'] === 1) {
      $out['teacher'][$tplId][] = [
        'id' => (int)$r['id'],
        'template_id' => $tplId,
        'field_name' => (string)($r['field_name'] ?? ''),
        'field_type' => (string)($r['field_type'] ?? ''),
        'meta_json' => $r['meta_json'] ?? null,
      ];
    }
  }
  return $out;
}

function load_teacher_values_raw(PDO $pdo, array $reportIds, array $fieldIds): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds);

  $st = $pdo->prepare(
    "SELECT report_instance_id, template_field_id, value_text, value_json
     FROM field_values
     WHERE report_instance_id IN ($inR)
       AND template_field_id IN ($inF)
       AND source='teacher'"
  );
  $st->execute($params);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (int)$r['template_field_id'];
    if (!isset($out[$rid])) $out[$rid] = [];
    $out[$rid][$fid] = [
      'text' => $r['value_text'] !== null ? (string)$r['value_text'] : null,
      'json' => $r['value_json'] !== null ? (string)$r['value_json'] : null,
    ];
  }
  return $out;
}

function option_list_lock_map(PDO $pdo, int $listId, array &$cache): array {
  if ($listId <= 0) return ['by_id' => [], 'by_value' => []];
  if (isset($cache[$listId])) return $cache[$listId];
  $st = $pdo->prepare(
    "SELECT id, value, meta_json
     FROM option_list_items
     WHERE list_id=?"
  );
  $st->execute([$listId]);
  $byId = [];
  $byValue = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)($r['id'] ?? 0);
    $value = trim((string)($r['value'] ?? ''));
    $meta = meta_read($r['meta_json'] ?? null);
    $lock = !empty($meta['lock_child']);
    $byId[$id] = [
      'value' => $value,
      'lock_child' => $lock,
    ];
    if ($value !== '' && !isset($byValue[$value])) $byValue[$value] = $id;
  }
  $cache[$listId] = ['by_id' => $byId, 'by_value' => $byValue];
  return $cache[$listId];
}

function teacher_value_locks_child(PDO $pdo, array $teacherField, ?array $teacherValue, array &$cache): bool {
  if (!$teacherValue) return false;
  $type = (string)($teacherField['field_type'] ?? '');
  if (!in_array($type, ['radio','select','grade'], true)) return false;
  $meta = meta_read($teacherField['meta_json'] ?? null);
  $listId = option_list_id_from_meta($meta);
  if ($listId <= 0) return false;
  $map = option_list_lock_map($pdo, $listId, $cache);
  $optId = 0;
  if (!empty($teacherValue['json'])) {
    $decoded = json_decode((string)$teacherValue['json'], true);
    if (is_array($decoded) && isset($decoded['option_item_id'])) {
      $optId = (int)$decoded['option_item_id'];
    }
  }
  if ($optId <= 0) {
    $txt = trim((string)($teacherValue['text'] ?? ''));
    if ($txt !== '' && isset($map['by_value'][$txt])) {
      $optId = (int)$map['by_value'][$txt];
    }
  }
  if ($optId <= 0) return false;
  return !empty($map['by_id'][$optId]['lock_child']);
}

function locked_child_field_ids_for_reports(PDO $pdo, array $teacherFields, array $childFields, array $reportIds, array $reportTemplateMap): array {
  if (!$reportIds) return [];
  $teacherByTpl = [];
  $teacherFieldIds = [];
  foreach ($teacherFields as $f) {
    $fid = (int)($f['id'] ?? 0);
    if ($fid <= 0) continue;
    $tplId = (int)($f['template_id'] ?? 0);
    if ($tplId <= 0) continue;
    $teacherFieldIds[] = $fid;
    $base = base_field_key((string)($f['field_name'] ?? ''));
    if ($base === '') continue;
    if (!isset($teacherByTpl[$tplId])) $teacherByTpl[$tplId] = [];
    if (!isset($teacherByTpl[$tplId][$base])) $teacherByTpl[$tplId][$base] = $f;
  }
  if (!$teacherFieldIds || !$teacherByTpl) return [];
  $teacherValues = load_teacher_values_raw($pdo, $reportIds, $teacherFieldIds);
  if (!$teacherValues) return [];

  $childByTpl = [];
  foreach ($childFields as $cf) {
    $tplId = (int)($cf['template_id'] ?? 0);
    if ($tplId <= 0) continue;
    $base = base_field_key((string)($cf['field_name'] ?? ''));
    if ($base === '') continue;
    if (!isset($childByTpl[$tplId])) $childByTpl[$tplId] = [];
    $childByTpl[$tplId][] = $cf;
  }

  $lockCache = [];
  $out = [];
  foreach ($reportIds as $rid) {
    $ridKey = (string)(int)$rid;
    $tplId = (int)($reportTemplateMap[$ridKey] ?? 0);
    if ($tplId <= 0) continue;
    $reportTeacherValues = $teacherValues[$ridKey] ?? [];
    if (!$reportTeacherValues) continue;
    $teacherByBase = $teacherByTpl[$tplId] ?? [];
    if (!$teacherByBase) continue;
    foreach (($childByTpl[$tplId] ?? []) as $cf) {
      $cfId = (int)($cf['id'] ?? 0);
      if ($cfId <= 0) continue;
      $base = base_field_key((string)($cf['field_name'] ?? ''));
      if ($base === '') continue;
      $teacherField = $teacherByBase[$base] ?? null;
      if (!$teacherField) continue;
      $teacherValue = $reportTeacherValues[(int)($teacherField['id'] ?? 0)] ?? null;
      if (!teacher_value_locks_child($pdo, $teacherField, $teacherValue, $lockCache)) continue;
      if (!isset($out[$ridKey])) $out[$ridKey] = [];
      $out[$ridKey][$cfId] = true;
    }
  }
  return $out;
}

function build_progress(PDO $pdo, array $classes, int $userId): array {
  if (!$classes) return [];

  $classIds = array_map(fn($c) => (int)($c['id'] ?? 0), $classes);
  $tplIds = array_values(array_unique(array_filter(array_map(fn($c) => (int)($c['template_id'] ?? 0), $classes), fn($x)=>$x>0)));
  $fieldSets = load_completion_field_sets($pdo, $tplIds);
  $groupedTeacherFields = load_grouped_teacher_fields($pdo, $tplIds);
  $lockFieldSets = load_lock_field_sets($pdo, $tplIds);
  $inClass = implode(',', array_fill(0, count($classIds), '?'));

  $progress = [];
  $delegatedGroupsByClass = [];
  $delegatedFieldsByClass = [];
  foreach ($classes as $c) {
    $id = (int)($c['id'] ?? 0);
    $isDelegateOnly = !isset($c['assigned_user_id']) && isset($c['delegated_user_id']);
    $progress[$id] = [
      'class' => $c,
      'forms_total' => 0,
      'students_done' => 0,
      'teachers_done' => 0,
      'avg_minutes_sum' => 0.0,
      'avg_minutes_count' => 0,
      'delegations_total' => 0,
      'delegations_done' => 0,
      'recent_delegations' => 0,
      'delegate_only' => $isDelegateOnly,
    ];
    $delegatedGroupsByClass[$id] = [];
    $delegatedFieldsByClass[$id] = [];
  }

  $stDelegatedGroups = $pdo->prepare(
    "SELECT d.class_id, d.group_key
       FROM class_group_delegations d
       JOIN classes c ON c.id=d.class_id
      WHERE d.class_id IN ($inClass)
        AND d.period_label=c.period_label
        AND d.user_id=?"
  );
  $stDelegatedGroups->execute(array_merge($classIds, [$userId]));
  foreach ($stDelegatedGroups->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cid = (int)$r['class_id'];
    if (!isset($progress[$cid])) continue;
    $gk = trim((string)($r['group_key'] ?? ''));
    if ($gk === '') continue;
    $delegatedGroupsByClass[$cid][] = $gk;
  }

  foreach ($classes as $c) {
    $cid = (int)($c['id'] ?? 0);
    if (!isset($progress[$cid])) continue;
    $tplId = (int)($c['template_id'] ?? 0);
    $gks = array_values(array_unique($delegatedGroupsByClass[$cid] ?? []));
    $fieldIds = [];
    foreach ($gks as $gk) {
      foreach (($groupedTeacherFields[$tplId][$gk] ?? []) as $fid) {
        $fieldIds[] = $fid;
      }
    }
    $delegatedFieldsByClass[$cid] = array_values(array_unique($fieldIds));
  }

  // total forms per class equals active students in class
  $stStudents = $pdo->prepare(
    "SELECT class_id, COUNT(*) AS c
       FROM students
      WHERE class_id IN ($inClass)
        AND is_active=1
      GROUP BY class_id"
  );
  $stStudents->execute($classIds);
  foreach ($stStudents->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cid = (int)$r['class_id'];
    if (isset($progress[$cid])) $progress[$cid]['forms_total'] = (int)$r['c'];
  }
  $stReports = $pdo->prepare(
    "SELECT ri.id, ri.template_id, ri.created_at, ri.updated_at, s.class_id
       FROM report_instances ri
       JOIN students s ON s.id=ri.student_id
       JOIN classes c ON c.id=s.class_id
      WHERE ri.period_label=c.period_label
        AND s.class_id IN ($inClass)"
  );
  $stReports->execute($classIds);
  $reports = [];
  $reportIds = [];
  $reportTemplateMap = [];
  foreach ($stReports->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (int)$r['id'];
    $tplId = (int)$r['template_id'];
    $cid = (int)$r['class_id'];
    if (!isset($progress[$cid])) continue;
    $reportIds[] = $rid;
    $reportTemplateMap[(string)$rid] = $tplId;
    $reqChild = isset($fieldSets[$tplId]) ? count($fieldSets[$tplId]['child']) : 0;
    $reqTeacher = isset($fieldSets[$tplId]) ? count($fieldSets[$tplId]['teacher']) : 0;
    $reports[$rid] = [
      'class_id' => $cid,
      'template_id' => $tplId,
      'child_required' => $reqChild,
      'teacher_required' => $reqTeacher,
      'child_field_ids' => $fieldSets[$tplId]['child'] ?? [],
      'child_filled' => 0,
      'teacher_filled' => 0,
      'delegated_required' => count($delegatedFieldsByClass[$cid] ?? []),
      'delegated_filled' => 0,
      'locked_child_ids' => [],
    ];
    $minutes = strtotime((string)$r['updated_at']) - strtotime((string)$r['created_at']);
    if ($minutes > 0) {
      $progress[$cid]['avg_minutes_sum'] += ((float)$minutes) / 60.0;
      $progress[$cid]['avg_minutes_count']++;
    }
  }

  if ($reportIds) {
    $allTeacherFields = [];
    foreach ($lockFieldSets['teacher'] as $fields) {
      foreach ($fields as $f) $allTeacherFields[] = $f;
    }
    $allChildFields = [];
    foreach ($lockFieldSets['child'] as $fields) {
      foreach ($fields as $f) $allChildFields[] = $f;
    }
    $lockedChildIdsByReport = locked_child_field_ids_for_reports($pdo, $allTeacherFields, $allChildFields, $reportIds, $reportTemplateMap);
    foreach ($reports as $rid => &$info) {
      $locked = $lockedChildIdsByReport[(string)$rid] ?? [];
      $info['locked_child_ids'] = $locked;
      if ($locked) {
        $lockedCount = 0;
        foreach ($info['child_field_ids'] as $fid) {
          if (!empty($locked[$fid])) $lockedCount++;
        }
        $info['child_required'] = max(0, $info['child_required'] - $lockedCount);
      }
    }
    unset($info);

    $phR = implode(',', array_fill(0, count($reportIds), '?'));

    $childIds = [];
    foreach ($fieldSets as $set) {
      foreach ($set['child'] ?? [] as $fid) $childIds[] = $fid;
    }
    $childIds = array_values(array_unique($childIds));
    if ($childIds) {
      $phC = implode(',', array_fill(0, count($childIds), '?'));
      $stChild = $pdo->prepare(
        "SELECT report_instance_id, template_field_id, value_text, value_json
           FROM field_values
          WHERE report_instance_id IN ($phR)
            AND template_field_id IN ($phC)
            AND source='child'"
      );
      $stChild->execute(array_merge($reportIds, $childIds));
      foreach ($stChild->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int)$r['report_instance_id'];
        if (!isset($reports[$rid])) continue;
        if (!empty(($reports[$rid]['locked_child_ids'] ?? [])[(int)$r['template_field_id']])) continue;
        $valTxt = trim((string)($r['value_text'] ?? ''));
        $valJson = trim((string)($r['value_json'] ?? ''));
        if ($valTxt === '' && $valJson === '') continue;
        $reports[$rid]['child_filled']++;
      }
    }

    $teacherIds = [];
    foreach ($fieldSets as $set) {
      foreach ($set['teacher'] ?? [] as $fid) $teacherIds[] = $fid;
    }
    $teacherIds = array_values(array_unique($teacherIds));
    if ($teacherIds) {
      $phT = implode(',', array_fill(0, count($teacherIds), '?'));
      $stTeacher = $pdo->prepare(
        "SELECT report_instance_id, template_field_id, value_text, value_json
           FROM field_values
          WHERE report_instance_id IN ($phR)
            AND template_field_id IN ($phT)
            AND source='teacher'"
      );
      $stTeacher->execute(array_merge($reportIds, $teacherIds));
      foreach ($stTeacher->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int)$r['report_instance_id'];
        if (!isset($reports[$rid])) continue;
        $valTxt = trim((string)($r['value_text'] ?? ''));
        $valJson = trim((string)($r['value_json'] ?? ''));
        if ($valTxt === '' && $valJson === '') continue;
        $reports[$rid]['teacher_filled']++;
      }
    }

    $delegatedFieldIds = [];
    foreach ($delegatedFieldsByClass as $fieldIds) {
      foreach ($fieldIds as $fid) $delegatedFieldIds[] = $fid;
    }
    $delegatedFieldIds = array_values(array_unique($delegatedFieldIds));
    if ($delegatedFieldIds) {
      $phD = implode(',', array_fill(0, count($delegatedFieldIds), '?'));
      $stDelegatedValues = $pdo->prepare(
        "SELECT report_instance_id, template_field_id, value_text, value_json
           FROM field_values
          WHERE report_instance_id IN ($phR)
            AND template_field_id IN ($phD)
            AND source='teacher'"
      );
      $stDelegatedValues->execute(array_merge($reportIds, $delegatedFieldIds));
      foreach ($stDelegatedValues->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int)$r['report_instance_id'];
        if (!isset($reports[$rid])) continue;
        $valTxt = trim((string)($r['value_text'] ?? ''));
        $valJson = trim((string)($r['value_json'] ?? ''));
        if ($valTxt === '' && $valJson === '') continue;
        $reports[$rid]['delegated_filled']++;
      }
    }
  }

  foreach ($reports as $rid => $info) {
    $cid = $info['class_id'];
    $reqChild = $info['child_required'];
    $reqTeacher = $info['teacher_required'];
    $reqDelegated = $info['delegated_required'];
    if ($info['child_filled'] >= $reqChild) $progress[$cid]['students_done']++;
    if ($reqTeacher > 0 && $info['teacher_filled'] >= $reqTeacher) $progress[$cid]['teachers_done']++;
    if ($reqDelegated > 0 && $info['delegated_filled'] >= $reqDelegated) $progress[$cid]['delegations_done']++;
  }

  foreach ($progress as $cid => $p) {
    $hasDelegatedFields = !empty($delegatedFieldsByClass[$cid]);
    $progress[$cid]['delegations_total'] = $hasDelegatedFields ? (int)$p['forms_total'] : 0;
  }

  $stDelRecent = $pdo->prepare(
    "SELECT d.class_id, COUNT(*) AS c
       FROM class_group_delegations d
       JOIN classes c ON c.id=d.class_id
      WHERE d.class_id IN ($inClass)
        AND d.period_label=c.period_label
        AND d.user_id=?
        AND d.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      GROUP BY d.class_id"
  );
  $stDelRecent->execute(array_merge($classIds, [$userId]));
  foreach ($stDelRecent->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cid = (int)$r['class_id'];
    if (isset($progress[$cid])) $progress[$cid]['recent_delegations'] = (int)$r['c'];
  }

  foreach ($progress as $cid => $p) {
    $forms = max(0, (int)$p['forms_total']);
    $progress[$cid]['students_percent'] = $forms > 0 ? round(($p['students_done'] / $forms) * 100) : 100;
    $progress[$cid]['teachers_percent'] = $forms > 0 ? round(($p['teachers_done'] / $forms) * 100) : 100;
    $delTotal = max(0, (int)$p['delegations_total']);
    $progress[$cid]['delegations_percent'] = $delTotal > 0 ? round(($p['delegations_done'] / $delTotal) * 100) : 100;
    $progress[$cid]['avg_minutes'] = ($p['avg_minutes_count'] > 0)
      ? ($p['avg_minutes_sum'] / $p['avg_minutes_count'])
      : null;
  }

  return $progress;
}

$progressByClass = build_progress($pdo, $classes, $userId);
$overall = [
  'forms_total' => 0,
  'students_done' => 0,
  'teachers_done' => 0,
  'delegations_total' => 0,
  'delegations_done' => 0,
  'recent_delegations' => 0,
  'avg_minutes_sum' => 0.0,
  'avg_minutes_count' => 0,
  'delegate_only' => true,
];
foreach ($progressByClass as $p) {
  $overall['forms_total'] += (int)$p['forms_total'];
  $overall['students_done'] += (int)$p['students_done'];
  $overall['teachers_done'] += (int)$p['teachers_done'];
  $overall['delegations_total'] += (int)$p['delegations_total'];
  $overall['delegations_done'] += (int)$p['delegations_done'];
  $overall['recent_delegations'] += (int)$p['recent_delegations'];
  $overall['avg_minutes_sum'] += (float)($p['avg_minutes_sum'] ?? 0.0);
  $overall['avg_minutes_count'] += (int)($p['avg_minutes_count'] ?? 0);
  if (!($p['delegate_only'] ?? false)) $overall['delegate_only'] = false;
}
$overall['students_percent'] = $overall['forms_total'] > 0 ? round(($overall['students_done'] / $overall['forms_total']) * 100) : 100;
$overall['teachers_percent'] = $overall['forms_total'] > 0 ? round(($overall['teachers_done'] / $overall['forms_total']) * 100) : 100;
$overall['delegations_percent'] = $overall['delegations_total'] > 0 ? round(($overall['delegations_done'] / $overall['delegations_total']) * 100) : 100;
$overall['avg_minutes'] = $overall['avg_minutes_count'] > 0 ? ($overall['avg_minutes_sum'] / $overall['avg_minutes_count']) : null;

$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($selectedClassId !== 0 && !isset($progressByClass[$selectedClassId])) $selectedClassId = 0;

$cfg = app_config();
$deadlineTypes = submission_deadline_types();
$deadlineSchoolYear = '';
$deadlinePeriodLabel = 'Standard';
$deadlineScopeLabel = '';
if ($selectedClassId !== 0 && isset($progressByClass[$selectedClassId]['class'])) {
  $classRow = $progressByClass[$selectedClassId]['class'] ?? [];
  $deadlineSchoolYear = (string)($classRow['school_year'] ?? '');
  $deadlinePeriodLabel = normalize_class_period_label($classRow['period_label'] ?? 'Standard');
  $deadlineScopeLabel = class_label($classRow);
  if ($deadlineScopeLabel !== '') {
    $deadlineScopeLabel .= ' · ' . period_label_display($classRow['period_label'] ?? 'Standard');
  }
}
if ($deadlineSchoolYear === '') {
  $years = array_values(array_unique(array_filter(array_map(
    static fn($c) => trim((string)($c['school_year'] ?? '')),
    $classes
  ), static fn($v) => $v !== '')));
  if (count($years) === 1) $deadlineSchoolYear = $years[0];
}
if ($deadlineSchoolYear === '') {
  $deadlineSchoolYear = (string)($cfg['app']['default_school_year'] ?? '');
}
$deadlinePeriodLabel = normalize_class_period_label($deadlinePeriodLabel);
if ($deadlineSchoolYear !== '' && $selectedClassId === 0) {
  $periods = array_values(array_unique(array_filter(array_map(
    static fn($c) => ((string)($c['school_year'] ?? '') === $deadlineSchoolYear)
      ? normalize_class_period_label($c['period_label'] ?? 'Standard')
      : '',
    $classes
  ), static fn($v) => $v !== '')));
  if (count($periods) === 1) $deadlinePeriodLabel = $periods[0];
}
$deadlineRows = $deadlineSchoolYear !== '' ? fetch_submission_deadlines($pdo, $deadlineSchoolYear, $deadlinePeriodLabel) : [];

$scope = $selectedClassId === 0 ? $overall : ($progressByClass[$selectedClassId] ?? []);
$classTabs = [
  ['id' => 0, 'label' => t('teacher.progress.tab_all', 'Gesamt')],
];
foreach ($progressByClass as $cid => $p) {
  $c = $p['class'] ?? [];
  $label = (string)($c['name'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $clabel = (string)($c['label'] ?? '');
  $display = ($grade !== null && $clabel !== '') ? ($grade . $clabel) : ($label !== '' ? $label : ('#' . $cid));
  $display .= ' · ' . period_label_display($c['period_label'] ?? 'Standard');
  $classTabs[] = ['id' => $cid, 'label' => $display];
}

render_teacher_header(t('teacher.title'));
?>

<div class="card">
    <h1><?=h(t('teacher.dashboard'))?></h1>
    <?php if ($deadlineSchoolYear !== ''): ?>
      <p class="muted" style="margin:6px 0 0; font-size:1.05rem; font-weight:600;">
        <?=h(str_replace('{year}', $deadlineSchoolYear, t('deadline.section.school_year', 'Schuljahr {year}')))?> · <?=h(period_label_display($deadlinePeriodLabel))?>
      </p>
    <?php endif; ?>
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
  </div>
</div>

<?php if ($deadlineSchoolYear !== ''): ?>
  <div class="card">
    <h2><?=h(t('deadline.section.title', 'Fristen'))?></h2>
    <p class="muted">
      <?=h(str_replace('{year}', $deadlineSchoolYear, t('deadline.section.school_year', 'Schuljahr {year}')))?> · <?=h(period_label_display($deadlinePeriodLabel))?>
      <?php if ($deadlineScopeLabel !== ''): ?>
        · <?=h($deadlineScopeLabel)?>
      <?php endif; ?>
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th><?=h(t('deadline.table.type', 'Bereich'))?></th>
            <th><?=h(t('deadline.table.due_at', 'Fällig am'))?></th>
            <th><?=h(t('deadline.table.remaining', 'Restzeit'))?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deadlineTypes as $key => $meta): ?>
            <?php $row = $deadlineRows[$key] ?? null; ?>
            <?php $info = deadline_remaining_info($row['due_at'] ?? null); ?>
            <?php
              $done = false;
              $na = false;
              if ($key === 'student') {
                $total = (int)($scope['forms_total'] ?? 0);
                $done = $total > 0 && (int)($scope['students_done'] ?? 0) >= $total;
                $na = $total === 0;
              } elseif ($key === 'teacher') {
                $total = (int)($scope['forms_total'] ?? 0);
                $done = $total > 0 && (int)($scope['teachers_done'] ?? 0) >= $total;
                $na = $total === 0;
              } elseif ($key === 'delegation') {
                $total = (int)($scope['delegations_total'] ?? 0);
                $done = $total > 0 && (int)($scope['delegations_done'] ?? 0) >= $total;
                $na = $total === 0;
              }
            ?>
            <tr>
              <td><?=h((string)($meta['label'] ?? $key))?></td>
              <td><?=render_local_datetime($row['due_at'] ?? null, 'd.m.Y H:i', t('deadline.none', '–'))?></td>
              <td>
                <?php if ($done): ?>
                  <span class="badge ok"><?=h(t('deadline.status.done', 'Erledigt'))?></span>
                <?php elseif ($na): ?>
                  <span class="muted"><?=h(t('deadline.status.na', 'Keine Aufgaben'))?></span>
                <?php elseif ($info): ?>
                  <span class="badge <?=h($info['status'])?>"><?=h($info['label'])?></span>
                <?php else: ?>
                  <span class="muted"><?=h(t('deadline.remaining.none', 'Keine Frist gesetzt'))?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2><?=h(t('teacher.progress.headline', 'Aktueller Bearbeitungsstand'))?></h2>
  <p class="muted"><?=h(t('teacher.progress.description', 'Statusüberblick für deine Klassen.'))?></p>

  <div class="tab-switcher">
    <?php foreach ($classTabs as $tab): $active = $tab['id'] === $selectedClassId; ?>
      <a class="tab-btn <?= $active ? 'active' : '' ?>" href="<?=h(url('teacher/index.php' . ($tab['id'] ? ('?class_id='.(int)$tab['id']) : '')))?>"><?=h((string)$tab['label'])?></a>
    <?php endforeach; ?>
  </div>

  <?php if (($scope['forms_total'] ?? 0) === 0 && ($scope['delegations_total'] ?? 0) === 0): ?>
    <div class="alert"><?=h(t('teacher.progress.empty', 'Keine Daten verfügbar.'))?></div>
  <?php else: ?>
    <?php
      $studentsBar = is_numeric($scope['students_percent'] ?? null) ? max(0, min(100, (int)$scope['students_percent'])) : 0;
      $teachersBar = is_numeric($scope['teachers_percent'] ?? null) ? max(0, min(100, (int)$scope['teachers_percent'])) : 0;
      $delegationsBar = is_numeric($scope['delegations_percent'] ?? null) ? max(0, min(100, (int)$scope['delegations_percent'])) : 0;
    ?>
    <style>
      .progress-bar{ height:6px; background: var(--border); border-radius:999px; overflow:hidden; margin-top:6px; }
      .progress-fill{ height:100%; background: var(--primary, #0b57d0); }
      .progress-fill.teacher{ background: #6c5ce7; }
      .progress-fill.delegation{ background: #1e8e3e; }
      .progress-pie{ width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:600; color:#1f2937; background: var(--border); margin-top:8px; }
      .progress-pie span{ background:#fff; border-radius:999px; padding:2px 6px; box-shadow:0 0 0 1px rgba(0,0,0,0.05); }
      .progress-pie.student{ background: conic-gradient(#1e8e3e 0deg, #1e8e3e calc(var(--percent) * 3.6deg), var(--border) 0deg); }
      .progress-pie.teacher{ background: conic-gradient(#0b57d0 0deg, #0b57d0 calc(var(--percent) * 3.6deg), var(--border) 0deg); }
      .progress-pie.delegation{ background: conic-gradient(#f29900 0deg, #f29900 calc(var(--percent) * 3.6deg), var(--border) 0deg); }
    </style>
    <div class="stats-grid">
      <?php if (!($scope['delegate_only'] ?? false)): ?>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)($scope['forms_total'] ?? 0))?></div>
        <div class="stat-label"><?=h(t('teacher.progress.total_forms', 'Formulare insgesamt'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['students_done'] ?? 0))?>
          <span class="muted small"> / <?=h((string)($scope['forms_total'] ?? 0))?> (<?=h((string)($scope['students_percent'] ?? '–'))?> %)</span>
        </div>
        <div class="stat-label"><?=h(t('teacher.progress.students_done', 'fertige Schülereingaben'))?></div>
        <div class="progress-pie student" role="img" aria-label="<?=h(t('teacher.progress.students_done', 'fertige Schülereingaben'))?> <?=h((string)$studentsBar)?>%" style="--percent: <?=h((string)$studentsBar)?>;">
          <span><?=h((string)$studentsBar)?>%</span>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['teachers_done'] ?? 0))?>
          <span class="muted small"> / <?=h((string)($scope['forms_total'] ?? 0))?> (<?=h((string)($scope['teachers_percent'] ?? '–'))?> %)</span>
        </div>
        <div class="stat-label"><?=h(t('teacher.progress.teacher_done', 'abgeschlossene Lehrkraft-Eingaben'))?></div>
        <div class="progress-pie teacher" role="img" aria-label="<?=h(t('teacher.progress.teacher_done', 'abgeschlossene Lehrkraft-Eingaben'))?> <?=h((string)$teachersBar)?>%" style="--percent: <?=h((string)$teachersBar)?>;">
          <span><?=h((string)$teachersBar)?>%</span>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h(format_minutes_short($scope['avg_minutes'] ?? null))?></div>
        <div class="stat-label"><?=h(t('teacher.progress.avg_time', 'Ø Bearbeitungszeit'))?></div>
      </div>
      <?php endif; ?>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['delegations_done'] ?? 0))?>
          <span class="muted small">/ <?=h((string)($scope['delegations_total'] ?? 0))?> (<?=h((string)($scope['delegations_percent'] ?? '–'))?> %)</span>
        </div>
        <div class="stat-label"><?=h(t('teacher.progress.delegations_total', 'Delegationen (fertig/gesamt)'))?></div>
        <div class="progress-pie delegation" role="img" aria-label="<?=h(t('teacher.progress.delegations_total', 'Delegationen (fertig/gesamt)'))?> <?=h((string)$delegationsBar)?>%" style="--percent: <?=h((string)$delegationsBar)?>;">
          <span><?=h((string)$delegationsBar)?>%</span>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)($scope['recent_delegations'] ?? 0))?></div>
        <div class="stat-label"><?=h(t('teacher.progress.delegation_feedback', 'neue Rückmeldungen zu Delegationen'))?></div>
      </div>
    </div>
  <?php endif; ?>
</div>


<?php if (($dashboardSummary['delegations_open'] ?? 0) > 0 || ($dashboardSummary['tasks_open'] ?? 0) > 0 || !empty($dashboardSummary['deadlines'])): ?>
  <div class="card">
    <h2>Benachrichtigungen</h2>
    <p class="muted">Es gibt neue Hinweise auf deinem Dashboard.</p>
    <ul>
      <li>Offene Delegationen: <strong><?=h((string)($dashboardSummary['delegations_open'] ?? 0))?></strong></li>
      <li>Offene Lehrkrafteingaben gesamt: <strong><?=h((string)($dashboardSummary['tasks_open'] ?? 0))?> von <?=h((string)($dashboardSummary['tasks_total'] ?? 0))?></strong></li>
      <li>Fristen gesamt: <strong><?=h((string)count((array)($dashboardSummary['deadlines'] ?? [])))?></strong></li>
    </ul>
    <?php if (!empty($dashboardSummary['classes'])): ?>
      <div class="table-wrap" style="margin-top:10px;">
        <table>
          <thead><tr><th>Klasse</th><th>Delegationen offen</th><th>Lehrkrafteingaben offen</th></tr></thead>
          <tbody>
            <?php foreach ((array)$dashboardSummary['classes'] as $row): ?>
              <tr>
                <td><?=h((string)($row['class_label'] ?? ''))?></td>
                <td><?=h((string)($row['delegations_open'] ?? 0))?></td>
                <td><?=h((string)($row['tasks_open'] ?? 0))?> / <?=h((string)($row['tasks_total'] ?? 0))?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2><?=h(t('teacher.management'))?></h2>
  <p class="muted"><?=h(t('teacher.management_hint'))?></p>

  <div class="nav-grid">
    <?php if ($hasOwnClasses): ?>
      <a class="nav-tile primary" href="<?=h(url('teacher/classes.php'))?>">
        <div class="nav-title"><?=h(t('teacher.my_classes'))?></div>
        <p class="nav-desc"><?=h(t('teacher.my_classes_desc'))?></p>
      </a>
    <?php endif; ?>
    <a class="nav-tile primary" href="<?=h(url('teacher/entry.php'))?>">
      <div class="nav-title"><?=h(t('teacher.fill_entries'))?></div>
      <p class="nav-desc"><?=h(t('teacher.fill_entries_desc'))?></p>
    </a>
    <a class="nav-tile" href="<?=h(url('teacher/delegations.php'))?>">
      <div class="nav-title"><?=h(t('teacher.delegations'))?></div>
      <p class="nav-desc"><?=h(t('teacher.delegations_desc'))?></p>
      <div class="nav-meta">
        <?php if ($delegationCount>0): ?>
          <span class="badge"><?=h((string)$delegationCount)?></span>
          <span class="small"><?=h(t('teacher.delegations_open'))?></span>
        <?php else: ?>
          <span class="small muted"><?=h(t('teacher.delegations_none'))?></span>
        <?php endif; ?>
      </div>
    </a>
    <a class="nav-tile" href="<?=h(url('teacher/export.php'))?>">
      <div class="nav-title"><?=h(t('teacher.pdf_export'))?></div>
      <p class="nav-desc"><?=h(t('teacher.pdf_export_desc'))?></p>
    </a>
  </div>
</div>

<?php if ($hasOwnClasses): ?>
  <div class="card">
    <h2><?=h(t('teacher.class_list'))?></h2>

    <?php if (!$classes): ?>
      <div class="alert"><?=h(t('teacher.class_none'))?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?=h(t('teacher.table.school_year'))?></th>
            <th><?=h(t('admin.classes.period_label', 'Halbjahr'))?></th>
            <th><?=h(t('teacher.table.class'))?></th>
            <th><?=h(t('teacher.table.actions'))?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($classes as $c):
          if (!isset($c['assigned_user_id']) && ($u['role'] ?? '') !== 'admin') continue;
          $label = (string)($c['label'] ?? '');
          $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
          $name = (string)($c['name'] ?? '');
          $display = ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
        ?>
          <tr>
            <td><?=h((string)$c['school_year'])?></td>
            <td><?=h(period_label_display($c['period_label'] ?? 'Standard'))?></td>
            <td><?=h($display)?></td>
            <td>
              <a class="btn secondary" href="<?=h(url('teacher/students.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.table.students'))?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>


<div class="card" id="mail-pref-card">
  <h2>E-Mail-Benachrichtigungen</h2>
  <p class="muted">Wenn aktiviert, versendet der Cronjob Benachrichtigungen zu Delegationen und nahenden Fristen.</p>
  <label style="display:flex;gap:10px;align-items:center;">
    <input type="checkbox" id="mail_pref_toggle" <?= $notifyPrefEnabled ? 'checked' : '' ?>>
    E-Mails erhalten
  </label>
  <p class="small muted" id="mail_pref_status"></p>
</div>
<script>
(function(){
  const el = document.getElementById('mail_pref_toggle');
  const status = document.getElementById('mail_pref_status');
  const csrf = <?=json_encode(csrf_token())?>;
  if (!el) return;
  el.addEventListener('change', async () => {
    status.textContent = 'Speichere …';
    try {
      const res = await fetch('ajax/notification_settings_api.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({enabled: el.checked, csrf_token: csrf})
      });
      const data = await res.json();
      if (!data.ok) throw new Error('save_failed');
      status.textContent = el.checked
        ? 'Aktiviert. Eine Bestätigungs-E-Mail wird über den Cronjob versendet.'
        : 'Deaktiviert. Es werden keine E-Mails mehr versendet.';
    } catch (e) {
      status.textContent = 'Speichern fehlgeschlagen.';
    }
  });
})();
</script>

<?php
render_teacher_footer();
