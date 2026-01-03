<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

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
  $st = $pdo->query("SELECT id, school_year, grade_level, label, name, template_id FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
  $classes = $st->fetchAll();
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.grade_level, c.label, c.name, c.template_id
     FROM classes c
     JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=? AND is_active = 1
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll();
}

function load_completion_field_sets(PDO $pdo, array $templateIds): array {
  $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), fn($x)=>$x>0)));
  if (!$templateIds) return [];

  $ph = implode(',', array_fill(0, count($templateIds), '?'));
  $st = $pdo->prepare(
    "SELECT id, template_id, can_child_edit, can_teacher_edit, meta_json
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
    if ((int)$r['can_child_edit'] === 1) $out[$tplId]['child'][] = $fid;
    if ((int)$r['can_teacher_edit'] === 1) $out[$tplId]['teacher'][] = $fid;
  }
  return $out;
}

function build_progress(PDO $pdo, array $classes): array {
  if (!$classes) return [];

  $classIds = array_map(fn($c) => (int)($c['id'] ?? 0), $classes);
  $tplIds = array_values(array_unique(array_filter(array_map(fn($c) => (int)($c['template_id'] ?? 0), $classes), fn($x)=>$x>0)));
  $fieldSets = load_completion_field_sets($pdo, $tplIds);

  $progress = [];
  foreach ($classes as $c) {
    $id = (int)($c['id'] ?? 0);
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
    ];
  }

  $inClass = implode(',', array_fill(0, count($classIds), '?'));
  $stReports = $pdo->prepare(
    "SELECT ri.id, ri.template_id, ri.created_at, ri.updated_at, s.class_id
       FROM report_instances ri
       JOIN students s ON s.id=ri.student_id
      WHERE ri.period_label='Standard'
        AND s.class_id IN ($inClass)"
  );
  $stReports->execute($classIds);
  $reports = [];
  $reportIds = [];
  foreach ($stReports->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (int)$r['id'];
    $tplId = (int)$r['template_id'];
    $cid = (int)$r['class_id'];
    if (!isset($progress[$cid])) continue;
    $reportIds[] = $rid;
    $reqChild = isset($fieldSets[$tplId]) ? count($fieldSets[$tplId]['child']) : 0;
    $reqTeacher = isset($fieldSets[$tplId]) ? count($fieldSets[$tplId]['teacher']) : 0;
    $reports[$rid] = [
      'class_id' => $cid,
      'template_id' => $tplId,
      'child_required' => $reqChild,
      'teacher_required' => $reqTeacher,
      'child_filled' => 0,
      'teacher_filled' => 0,
    ];
    $progress[$cid]['forms_total']++;

    $minutes = strtotime((string)$r['updated_at']) - strtotime((string)$r['created_at']);
    if ($minutes > 0) {
      $progress[$cid]['avg_minutes_sum'] += ((float)$minutes) / 60.0;
      $progress[$cid]['avg_minutes_count']++;
    }
  }

  if ($reportIds) {
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
  }

  foreach ($reports as $rid => $info) {
    $cid = $info['class_id'];
    $reqChild = $info['child_required'];
    $reqTeacher = $info['teacher_required'];
    if ($reqChild > 0 && $info['child_filled'] >= $reqChild) $progress[$cid]['students_done']++;
    if ($reqTeacher > 0 && $info['teacher_filled'] >= $reqTeacher) $progress[$cid]['teachers_done']++;
  }

  $stDel = $pdo->prepare(
    "SELECT class_id, status, COUNT(*) AS c
       FROM class_group_delegations
      WHERE class_id IN ($inClass)
        AND period_label='Standard'
      GROUP BY class_id, status"
  );
  $stDel->execute($classIds);
  foreach ($stDel->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cid = (int)$r['class_id'];
    if (!isset($progress[$cid])) continue;
    $count = (int)$r['c'];
    $progress[$cid]['delegations_total'] += $count;
    if ((string)($r['status'] ?? '') === 'done') $progress[$cid]['delegations_done'] += $count;
  }

  $stDelRecent = $pdo->prepare(
    "SELECT class_id, COUNT(*) AS c
       FROM class_group_delegations
      WHERE class_id IN ($inClass)
        AND period_label='Standard'
        AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      GROUP BY class_id"
  );
  $stDelRecent->execute($classIds);
  foreach ($stDelRecent->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cid = (int)$r['class_id'];
    if (isset($progress[$cid])) $progress[$cid]['recent_delegations'] = (int)$r['c'];
  }

  foreach ($progress as $cid => $p) {
    $forms = max(0, (int)$p['forms_total']);
    $progress[$cid]['students_percent'] = $forms > 0 ? round(($p['students_done'] / $forms) * 100) : null;
    $progress[$cid]['teachers_percent'] = $forms > 0 ? round(($p['teachers_done'] / $forms) * 100) : null;
    $delTotal = max(0, (int)$p['delegations_total']);
    $progress[$cid]['delegations_percent'] = $delTotal > 0 ? round(($p['delegations_done'] / $delTotal) * 100) : null;
    $progress[$cid]['avg_minutes'] = ($p['avg_minutes_count'] > 0)
      ? ($p['avg_minutes_sum'] / $p['avg_minutes_count'])
      : null;
  }

  return $progress;
}

$progressByClass = build_progress($pdo, $classes);
$overall = [
  'forms_total' => 0,
  'students_done' => 0,
  'teachers_done' => 0,
  'delegations_total' => 0,
  'delegations_done' => 0,
  'recent_delegations' => 0,
  'avg_minutes_sum' => 0.0,
  'avg_minutes_count' => 0,
];
foreach ($progressByClass as $p) {
  $overall['forms_total'] += (int)$p['forms_total'];
  $overall['students_done'] += (int)$p['students_done'];
  $overall['teachers_done'] += (int)$p['teachers_done'];
  $overall['delegations_total'] += (int)$p['delegations_total'];
  $overall['delegations_done'] += (int)$p['delegations_done'];
  $overall['recent_delegations'] += (int)$p['recent_delegations'];
  $overall['avg_minutes_sum'] += (float)($p['avg_minutes'] ?? 0) * (int)($p['forms_total'] ?? 0);
  $overall['avg_minutes_count'] += (int)$p['forms_total'];
}
$overall['students_percent'] = $overall['forms_total'] > 0 ? round(($overall['students_done'] / $overall['forms_total']) * 100) : null;
$overall['teachers_percent'] = $overall['forms_total'] > 0 ? round(($overall['teachers_done'] / $overall['forms_total']) * 100) : null;
$overall['delegations_percent'] = $overall['delegations_total'] > 0 ? round(($overall['delegations_done'] / $overall['delegations_total']) * 100) : null;
$overall['avg_minutes'] = $overall['avg_minutes_count'] > 0 ? ($overall['avg_minutes_sum'] / $overall['avg_minutes_count']) : null;

$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($selectedClassId !== 0 && !isset($progressByClass[$selectedClassId])) $selectedClassId = 0;

render_teacher_header(t('teacher.title'));
?>

<div class="card">
    <h1><?=h(t('teacher.dashboard'))?></h1>
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
  </div>
</div>

<?php
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
    $classTabs[] = ['id' => $cid, 'label' => $display];
  }
?>

<div class="card">
  <h2><?=h(t('teacher.progress.headline', 'Aktueller Bearbeitungsstand'))?></h2>
  <p class="muted"><?=h(t('teacher.progress.description', 'Statusüberblick für deine Klassen.'))?></p>

  <div class="tab-switcher">
    <?php foreach ($classTabs as $tab): $active = $tab['id'] === $selectedClassId; ?>
      <a class="tab-btn <?= $active ? 'active' : '' ?>" href="<?=h(url('teacher/index.php' . ($tab['id'] ? ('?class_id='.(int)$tab['id']) : '')))?>"><?=h((string)$tab['label'])?></a>
    <?php endforeach; ?>
  </div>

  <?php if (($scope['forms_total'] ?? 0) === 0): ?>
    <div class="alert"><?=h(t('teacher.progress.empty', 'Keine Daten verfügbar.'))?></div>
  <?php else: ?>
    <div class="stats-grid">
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
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['teachers_done'] ?? 0))?>
          <span class="muted small"> / <?=h((string)($scope['forms_total'] ?? 0))?> (<?=h((string)($scope['teachers_percent'] ?? '–'))?> %)</span>
        </div>
        <div class="stat-label"><?=h(t('teacher.progress.teacher_done', 'abgeschlossene Lehrkraft-Eingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h(format_minutes_short($scope['avg_minutes'] ?? null))?></div>
        <div class="stat-label"><?=h(t('teacher.progress.avg_time', 'Ø Bearbeitungszeit'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['delegations_done'] ?? 0))?>
          <span class="muted small">/ <?=h((string)($scope['delegations_total'] ?? 0))?><?php if (($scope['delegations_total'] ?? 0) > 0): ?> (<?=h((string)($scope['delegations_percent'] ?? '–'))?> %)<?php endif; ?></span>
        </div>
        <div class="stat-label"><?=h(t('teacher.progress.delegations_total', 'Delegationen (fertig/gesamt)'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)($scope['recent_delegations'] ?? 0))?></div>
        <div class="stat-label"><?=h(t('teacher.progress.delegation_feedback', 'neue Rückmeldungen zu Delegationen'))?></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?=h(t('teacher.management'))?></h2>
  <p class="muted"><?=h(t('teacher.management_hint'))?></p>

  <div class="nav-grid">
    <a class="nav-tile primary" href="<?=h(url('teacher/classes.php'))?>">
      <div class="nav-title"><?=h(t('teacher.my_classes'))?></div>
      <p class="nav-desc"><?=h(t('teacher.my_classes_desc'))?></p>
    </a>
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

<div class="card">
  <h2><?=h(t('teacher.class_list'))?></h2>

  <?php if (!$classes): ?>
    <div class="alert"><?=h(t('teacher.class_none'))?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?=h(t('teacher.table.school_year'))?></th>
          <th><?=h(t('teacher.table.class'))?></th>
          <th><?=h(t('teacher.table.actions'))?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($classes as $c):
        $label = (string)($c['label'] ?? '');
        $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
        $name = (string)($c['name'] ?? '');
        $display = ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
      ?>
        <tr>
          <td><?=h((string)$c['school_year'])?></td>
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

<?php
render_teacher_footer();
