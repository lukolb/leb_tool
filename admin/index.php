<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();

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

function format_minutes_admin(?float $minutes): string {
  if ($minutes === null) return '–';
  $m = (int)round($minutes);
  if ($m <= 0) return '<1 min';
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) {
    return $h . 'h' . ($r > 0 ? ' ' . $r . 'min' : '');
  }
  return $m . ' min';
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
  $inClass = implode(',', array_fill(0, count($classIds), '?'));
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

  // delegations
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

  // derive averages + percentages
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

$classesStmt = $pdo->query("SELECT id, school_year, grade_level, label, name, template_id FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC");
$classes = $classesStmt->fetchAll();

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
  $overall['avg_minutes_sum'] += (float)($p['avg_minutes_sum'] ?? 0.0);
  $overall['avg_minutes_count'] += (int)($p['avg_minutes_count'] ?? 0);
}
$overall['students_percent'] = $overall['forms_total'] > 0 ? round(($overall['students_done'] / $overall['forms_total']) * 100) : null;
$overall['teachers_percent'] = $overall['forms_total'] > 0 ? round(($overall['teachers_done'] / $overall['forms_total']) * 100) : null;
$overall['delegations_percent'] = $overall['delegations_total'] > 0 ? round(($overall['delegations_done'] / $overall['delegations_total']) * 100) : null;
$overall['avg_minutes'] = $overall['avg_minutes_count'] > 0 ? ($overall['avg_minutes_sum'] / $overall['avg_minutes_count']) : null;

$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($selectedClassId !== 0 && !isset($progressByClass[$selectedClassId])) $selectedClassId = 0;

$u = current_user();
render_admin_header('Admin – Dashboard');
?>
<div class="card">
    <h1>Dashboard</h1>
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
  </div>
</div>

<?php
  $scope = $selectedClassId === 0 ? $overall : ($progressByClass[$selectedClassId] ?? []);
  $classTabs = [
    ['id' => 0, 'label' => t('admin.progress.tab_all', 'Gesamt')],
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
  <h2><?=h(t('admin.progress.headline', 'Gesamt-Bearbeitungsstand'))?></h2>
  <p class="muted"><?=h(t('admin.progress.description', 'Überblick über alle Berichte und Delegationen.'))?></p>

  <div class="tab-switcher">
    <?php foreach ($classTabs as $tab): $active = $tab['id'] === $selectedClassId; ?>
      <a class="tab-btn <?= $active ? 'active' : '' ?>" href="<?=h(url('admin/index.php' . ($tab['id'] ? ('?class_id='.(int)$tab['id']) : '')))?>"><?=h((string)$tab['label'])?></a>
    <?php endforeach; ?>
  </div>

  <?php if (($scope['forms_total'] ?? 0) === 0): ?>
    <div class="alert"><?=h(t('admin.progress.empty', 'Keine Daten verfügbar.'))?></div>
  <?php else: ?>
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-value"><?=h((string)($scope['forms_total'] ?? 0))?></div>
        <div class="stat-label"><?=h(t('admin.progress.total_forms', 'Formulare insgesamt'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['students_done'] ?? 0))?>
          <span class="muted small"> / <?=h((string)($scope['forms_total'] ?? 0))?> (<?=h((string)($scope['students_percent'] ?? '–'))?> %)</span>
        </div>
        <div class="stat-label"><?=h(t('admin.progress.students_done', 'fertige Schülereingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['teachers_done'] ?? 0))?>
          <span class="muted small"> / <?=h((string)($scope['forms_total'] ?? 0))?> (<?=h((string)($scope['teachers_percent'] ?? '–'))?> %)</span>
        </div>
        <div class="stat-label"><?=h(t('admin.progress.teacher_done', 'abgeschlossene Lehrkraft-Eingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h(format_minutes_admin($scope['avg_minutes'] ?? null))?></div>
        <div class="stat-label"><?=h(t('admin.progress.avg_time', 'Ø Bearbeitungszeit'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value">
          <?=h((string)($scope['delegations_done'] ?? 0))?>
          <span class="muted small">/ <?=h((string)($scope['delegations_total'] ?? 0))?><?php if (($scope['delegations_total'] ?? 0) > 0): ?> (<?=h((string)($scope['delegations_percent'] ?? '–'))?> %)<?php endif; ?></span>
        </div>
        <div class="stat-label"><?=h(t('admin.progress.delegations_total', 'Delegationen (fertig/gesamt)'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)($scope['recent_delegations'] ?? 0))?></div>
        <div class="stat-label"><?=h(t('admin.progress.delegation_feedback', 'neue Delegations-Rückmeldungen'))?></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Verwaltung</h2>
  <div class="nav-grid">
    <a class="nav-tile primary" href="<?=h(url('admin/classes.php'))?>">
      <div class="nav-title">Klassen</div>
      <p class="nav-desc">Klassen strukturieren, Schuljahre pflegen und Zuweisungen erledigen.</p>
    </a>
    <a class="nav-tile primary" href="<?=h(url('admin/users.php'))?>">
      <div class="nav-title">Nutzer</div>
      <p class="nav-desc">Accounts verwalten, Rollen vergeben und Zugänge aktuell halten.</p>
    </a>
    <a class="nav-tile primary" href="<?=h(url('admin/students.php'))?>">
      <div class="nav-title">Schüler</div>
      <p class="nav-desc">Schüler importieren oder erfassen und Klassen zuordnen.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/student_fields.php'))?>">
      <div class="nav-title">Schüler-Felder</div>
      <p class="nav-desc">Zusätzliche Felder anlegen, Labels pflegen und Standardwerte definieren.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/settings.php'))?>">
      <div class="nav-title">Branding & Einstellungen</div>
      <p class="nav-desc">Logo, Farben, Sprache und weitere Grundeinstellungen anpassen.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/log.php'))?>">
      <div class="nav-title">Audit-Log</div>
      <p class="nav-desc">Protokoll aller Datenbank-Änderungen.</p>
    </a>
  </div>
</div>

<div class="card">
  <h2>Vorlagen & Exporte</h2>
  <div class="nav-grid">
    <a class="nav-tile" href="<?=h(url('admin/templates.php'))?>">
      <div class="nav-title">Templates</div>
      <p class="nav-desc">PDF-Vorlagen hochladen, strukturieren und für Eingaben vorbereiten.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/icon_library.php'))?>">
      <div class="nav-title">Optionen & Skalen</div>
      <p class="nav-desc">Antwortoptionen, Skalen und Auswahllisten verwalten.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/text_snippets.php'))?>">
      <div class="nav-title">Textbausteine</div>
      <p class="nav-desc">Textbausteine für die Eingabe in freien Eingabefeldern der Lernentwicklungsberichte verwalten.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/export.php'))?>">
      <div class="nav-title">PDF-Export</div>
      <p class="nav-desc">Reports als PDF bündeln und für den Versand herunterladen.</p>
    </a>
  </div>
  <p class="muted">Empfohlene Reihenfolge: Klassen anlegen & zuordnen → Templates hochladen → Felder auslesen → Schüler importieren/erfassen → Reports pro Kind erzeugen.</p>
</div>
<?php render_admin_footer(); ?>
