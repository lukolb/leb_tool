<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$user = current_user() ?? [];
$userId = (int)($user['id'] ?? 0);
$error = '';

function demo_class_label(array $class): string {
  $grade = $class['grade_level'] !== null ? (int)$class['grade_level'] : null;
  $label = trim((string)($class['label'] ?? ''));
  $name = trim((string)($class['name'] ?? ''));
  $className = $grade !== null && $label !== '' ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$class['id']));
  $period = normalize_class_period_label($class['period_label'] ?? 'Standard') === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
  return $className . ' · ' . (string)$class['school_year'] . ' · ' . $period;
}

if (($user['role'] ?? '') === 'admin') {
  $classes = $pdo->query(
    "SELECT id, school_year, period_label, grade_level, label, name, template_id, syllable_coloring_enabled
     FROM classes
     WHERE is_active=1
     ORDER BY school_year DESC, CASE period_label WHEN 'H2' THEN 2 ELSE 1 END DESC,
              grade_level ASC, label ASC, name ASC"
  )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
  $stClasses = $pdo->prepare(
    "SELECT DISTINCT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name, c.template_id, c.syllable_coloring_enabled
     FROM classes c
     LEFT JOIN user_class_assignments uca ON uca.class_id=c.id AND uca.user_id=?
     LEFT JOIN class_group_delegations d ON d.class_id=c.id AND d.user_id=? AND d.status='open'
     WHERE c.is_active=1 AND (uca.user_id IS NOT NULL OR d.user_id IS NOT NULL)
     ORDER BY c.school_year DESC, CASE c.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC,
              c.grade_level ASC, c.label ASC, c.name ASC"
  );
  $stClasses->execute([$userId, $userId]);
  $classes = $stClasses->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$demoStudentsByClass = [];
$demoClassIds = array_values(array_unique(array_filter(array_map(fn($class) => (int)($class['id'] ?? 0), $classes), fn($id) => $id > 0)));
if ($demoClassIds) {
  $inClasses = implode(',', array_fill(0, count($demoClassIds), '?'));
  $studentsSt = $pdo->prepare(
    "SELECT sca.class_id, s.id, s.first_name, s.last_name
     FROM student_class_assignments sca
     INNER JOIN students s ON s.id=sca.student_id
     WHERE sca.class_id IN ($inClasses) AND sca.is_active=1
     ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC"
  );
  $studentsSt->execute($demoClassIds);
  foreach ($studentsSt->fetchAll(PDO::FETCH_ASSOC) as $student) {
    $studentClassId = (int)($student['class_id'] ?? 0);
    $studentId = (int)($student['id'] ?? 0);
    if ($studentClassId <= 0 || $studentId <= 0) continue;
    $demoStudentsByClass[$studentClassId][] = [
      'id' => $studentId,
      'name' => trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? '')),
    ];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $classId = (int)($_POST['class_id'] ?? 0);
    $referenceStudentId = (int)($_POST['reference_student_id'] ?? 0);
    $displayMode = strtolower(trim((string)($_POST['display_mode'] ?? 'groups')));
    $syllableColoring = (int)($_POST['syllable_coloring_enabled'] ?? 0) === 1;
    if ($classId <= 0) throw new RuntimeException(t('student_demo.error.class_required', 'Bitte wähle eine Klasse.'));
    if (!in_array($displayMode, ['groups', 'items', 'beginner'], true)) {
      throw new RuntimeException(t('student_demo.error.mode_invalid', 'Die gewählte Ansicht ist ungültig.'));
    }
    if (($user['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException(t('session.forbidden.message', 'Keine Berechtigung.'));
    }
    $stClass = $pdo->prepare('SELECT id, template_id, is_active FROM classes WHERE id=? LIMIT 1');
    $stClass->execute([$classId]);
    $selectedClass = $stClass->fetch(PDO::FETCH_ASSOC);
    if (!$selectedClass || (int)($selectedClass['is_active'] ?? 0) !== 1) {
      throw new RuntimeException(t('student_demo.error.class_unavailable', 'Die Klasse ist nicht aktiv oder nicht mehr vorhanden.'));
    }
    if ((int)($selectedClass['template_id'] ?? 0) <= 0) {
      throw new RuntimeException(t('student_demo.error.no_template', 'Der Klasse ist noch keine Vorlage zugeordnet.'));
    }
    $referenceValid = false;
    foreach (($demoStudentsByClass[$classId] ?? []) as $demoStudent) {
      if ((int)($demoStudent['id'] ?? 0) === $referenceStudentId) {
        $referenceValid = true;
        break;
      }
    }
    if (!$referenceValid) {
      throw new RuntimeException(t('student_demo.error.reference_required', 'Bitte wähle einen Referenzschüler für die individuellen n/a-Sperren.'));
    }

    unset($_SESSION['student']);
    $_SESSION['student_demo'] = [
      'class_id' => $classId,
      'reference_student_id' => $referenceStudentId,
      'display_mode' => $displayMode,
      'syllable_coloring_enabled' => $syllableColoring,
      'values' => [],
      'started_by_user_id' => $userId,
      'started_at' => time(),
    ];
    audit('student_demo_start', $userId, [
      'class_id' => $classId,
      'reference_student_id' => $referenceStudentId,
      'display_mode' => $displayMode,
      'syllable_coloring_enabled' => $syllableColoring,
    ]);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    redirect('student/index.php');
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$activeDemo = current_student_demo();
render_teacher_header(t('student_demo.title', 'Demoschüler'));
?>

<div class="card">
  <h1><?=h(t('student_demo.heading', 'Demoschüler starten'))?></h1>
  <p class="muted"><?=h(t('student_demo.intro', 'Teste die Schüleransicht oder führe sie der Klasse vor. Eingaben bleiben ausschließlich in dieser Sitzung und verändern keine Schülerberichte.'))?></p>
</div>

<?php if ($error): ?><div class="alert danger"><strong><?=h($error)?></strong></div><?php endif; ?>

<div class="card" style="max-width:760px;">
  <?php if (!$classes): ?>
    <div class="alert"><?=h(t('student_demo.no_classes', 'Es ist keine aktive, zugängliche Klasse vorhanden.'))?></div>
  <?php else: ?>
    <?php
      $requestedClassId = (int)($_POST['class_id'] ?? ($_GET['class_id'] ?? 0));
      $requestedStudentId = (int)($_POST['reference_student_id'] ?? ($_GET['student_id'] ?? 0));
      $requestedClassSyllables = false;
      foreach ($classes as $classOption) {
        if ((int)($classOption['id'] ?? 0) === $requestedClassId) {
          $requestedClassSyllables = (int)($classOption['syllable_coloring_enabled'] ?? 0) === 1;
          break;
        }
      }
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedSyllables = (int)($_POST['syllable_coloring_enabled'] ?? 0) === 1;
      } elseif (array_key_exists('syllable_coloring_enabled', $_GET)) {
        $selectedSyllables = (int)$_GET['syllable_coloring_enabled'] === 1;
      } else {
        $selectedSyllables = $requestedClassSyllables;
      }
    ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <label class="label" for="demoClass"><?=h(t('student_demo.class', 'Klasse'))?></label>
      <select class="input" id="demoClass" name="class_id" required style="width:100%; max-width:560px;">
        <option value=""><?=h(t('student_demo.choose_class', 'Bitte wählen…'))?></option>
        <?php foreach ($classes as $class): ?>
          <option value="<?=(int)$class['id']?>" data-syllables="<?=((int)($class['syllable_coloring_enabled'] ?? 0) === 1) ? '1' : '0'?>" <?=($requestedClassId === (int)$class['id']) ? 'selected' : ''?> <?=((int)($class['template_id'] ?? 0) <= 0) ? 'disabled' : ''?>>
            <?=h(demo_class_label($class) . ((int)($class['template_id'] ?? 0) <= 0 ? ' · ' . t('student_demo.without_template', 'ohne Vorlage') : ''))?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="margin-top:14px; max-width:560px;">
        <label class="label" for="demoReferenceStudent"><?=h(t('student_demo.reference_student', 'Referenzschüler für n/a-Sperren'))?></label>
        <select class="input" id="demoReferenceStudent" name="reference_student_id" required style="width:100%;">
          <option value=""><?=h(t('student_demo.choose_reference_student', 'Bitte wählen…'))?></option>
          <?php foreach (($demoStudentsByClass[$requestedClassId] ?? []) as $demoStudent): ?>
            <option value="<?=(int)$demoStudent['id']?>" <?=((int)$demoStudent['id'] === $requestedStudentId) ? 'selected' : ''?>><?=h((string)$demoStudent['name'])?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" style="margin:6px 0 0;"><?=h(t('student_demo.reference_student_hint', 'Die Demo übernimmt nur die individuellen n/a-Sperren dieses Kindes. Demo-Eingaben bleiben weiterhin getrennt und verändern keinen Schülerbericht.'))?></p>
      </div>

      <fieldset style="border:0; padding:0; margin:20px 0 0;">
        <legend class="label"><?=h(t('student_demo.view', 'Ansicht'))?></legend>
        <?php $selectedMode = strtolower(trim((string)($_POST['display_mode'] ?? ($_GET['display_mode'] ?? 'groups')))); ?>
        <?php if (!in_array($selectedMode, ['groups', 'items', 'beginner'], true)) $selectedMode = 'groups'; ?>
        <div style="display:grid; gap:10px; max-width:560px;">
          <label class="panel" style="padding:12px; cursor:pointer;"><input type="radio" name="display_mode" value="groups" <?=$selectedMode === 'groups' ? 'checked' : ''?>> <strong><?=h(t('teacher.classes.wizard.groups', 'Gruppen'))?></strong><br><span class="muted"><?=h(t('student_demo.view.groups_hint', 'Mehrere Kompetenzen einer Kategorie gemeinsam anzeigen.'))?></span></label>
          <label class="panel" style="padding:12px; cursor:pointer;"><input type="radio" name="display_mode" value="items" <?=$selectedMode === 'items' ? 'checked' : ''?>> <strong><?=h(t('teacher.classes.wizard.items', 'Items'))?></strong><br><span class="muted"><?=h(t('student_demo.view.items_hint', 'Kompetenzen einzeln Schritt für Schritt anzeigen.'))?></span></label>
          <label class="panel" style="padding:12px; cursor:pointer;"><input type="radio" name="display_mode" value="beginner" <?=$selectedMode === 'beginner' ? 'checked' : ''?>> <strong><?=h(t('teacher.classes.wizard.beginner', 'Leseanfänger'))?></strong><br><span class="muted"><?=h(t('student_demo.view.beginner_hint', 'Große, reduzierte Schrittansicht mit automatischer Vorlese-Unterstützung.'))?></span></label>
        </div>
      </fieldset>

      <div style="margin-top:14px; max-width:560px;">
        <label class="panel" style="padding:12px; cursor:pointer; display:block;"><input id="demoSyllableColoring" type="checkbox" name="syllable_coloring_enabled" value="1" <?=$selectedSyllables ? 'checked' : ''?>> <strong><?=h(t('student_demo.syllable_label', 'Silbenschrift verwenden'))?></strong><br><span class="muted"><?=h(t('student_demo.syllable_hint', 'Optional und unabhängig von der gewählten Ansicht; gilt nur für diese Demo-Sitzung.'))?></span></label>
      </div>

      <div class="actions" style="justify-content:flex-start; margin-top:18px;">
        <button class="btn primary" type="submit"><?=h(t('student_demo.start', 'Demo starten'))?></button>
        <?php if ($activeDemo): ?><a class="btn secondary" href="<?=h(url('student/index.php'))?>"><?=h(t('student_demo.continue', 'Laufende Demo fortsetzen'))?></a><?php endif; ?>
      </div>
    </form>
    <script>
    (() => {
      const classSelect = document.getElementById('demoClass');
      const studentSelect = document.getElementById('demoReferenceStudent');
      const syllableToggle = document.getElementById('demoSyllableColoring');
      const studentsByClass = <?=json_encode($demoStudentsByClass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
      if (!classSelect || !studentSelect || !syllableToggle) return;
      const chooseStudentLabel = <?=json_encode(t('student_demo.choose_reference_student', 'Bitte wählen…'), JSON_UNESCAPED_UNICODE)?>;
      let changedByUser = false;
      syllableToggle.addEventListener('change', () => { changedByUser = true; });
      function updateStudents(){
        const keep = studentSelect.value;
        studentSelect.textContent = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = chooseStudentLabel;
        studentSelect.append(placeholder);
        const students = studentsByClass[String(classSelect.value)] || [];
        students.forEach(student => {
          const option = document.createElement('option');
          option.value = String(student.id);
          option.textContent = String(student.name || '');
          studentSelect.append(option);
        });
        if (students.some(student => String(student.id) === keep)) studentSelect.value = keep;
        else if (students.length) studentSelect.value = String(students[0].id);
      }
      classSelect.addEventListener('change', () => {
        updateStudents();
        if (changedByUser) return;
        const option = classSelect.options[classSelect.selectedIndex];
        syllableToggle.checked = option?.dataset?.syllables === '1';
      });
      if (!studentSelect.value) updateStudents();
    })();
    </script>
  <?php endif; ?>
</div>

<?php render_teacher_footer(); ?>
