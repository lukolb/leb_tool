<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$isAdmin = get_role() === 'admin';
$ok = null;
$err = null;


function ag_teacher_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

function ag_teacher_period_display(?string $periodLabel): string {
  $p = normalize_class_period_label($periodLabel);
  if ($p === 'H2') return t('admin.classes.period.h2', '2. Halbjahr');
  return t('admin.classes.period.h1', '1. Halbjahr');
}

function ag_safe_table_exists(PDO $pdo, string $table): bool {
  try {
    return db_has_table($pdo, $table);
  } catch (Throwable $e) {
    return false;
  }
}

try { ensure_ag_tables($pdo); } catch (Throwable $e) {}

if (!ag_safe_table_exists($pdo, 'ag_catalog') || !ag_safe_table_exists($pdo, 'student_ag_assignments')) {
  render_teacher_header('AG-Eingaben');
  echo '<div class="card"><h1>AG-Eingaben</h1><div class="alert danger">AG-Tabellen fehlen in der Datenbank.</div></div>';
  render_teacher_footer();
  exit;
}

$classes = [];
try {
  if ($isAdmin) {
    $classStmt = $pdo->query("SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name
      FROM classes c
      WHERE c.is_active=1
        AND EXISTS (SELECT 1 FROM template_fields tf WHERE tf.template_id=c.template_id AND tf.field_type='ag')
      ORDER BY c.school_year DESC, c.grade_level ASC, c.label ASC");
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    $classStmt = $pdo->prepare("SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name
      FROM classes c
      INNER JOIN user_class_assignments uca ON uca.class_id=c.id
      WHERE uca.user_id=? AND c.is_active=1
        AND EXISTS (SELECT 1 FROM template_fields tf WHERE tf.template_id=c.template_id AND tf.field_type='ag')
      ORDER BY c.school_year DESC, c.grade_level ASC, c.label ASC");
    $classStmt->execute([$userId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  $classes = [];
  if ($err === null) $err = 'Klassen konnten nicht geladen werden.';
}

$defaultClassId = (int)($classes[0]['id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? $defaultClassId);
$selectedClass = null;
foreach ($classes as $c) {
  if ((int)$c['id'] === $classId) {
    $selectedClass = $c;
    break;
  }
}
if (!$selectedClass && $defaultClassId > 0) {
  $classId = $defaultClassId;
  foreach ($classes as $c) {
    if ((int)$c['id'] === $classId) {
      $selectedClass = $c;
      break;
    }
  }
}

$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest' || ((int)($_POST['ajax'] ?? 0) === 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedClass) {
  try {
    csrf_verify();
    $schoolYear = (string)$selectedClass['school_year'];
    $periodLabel = normalize_class_period_label((string)$selectedClass['period_label']);
    $posted = $_POST['ag'] ?? [];
    if (!is_array($posted)) $posted = [];

    $students = $pdo->prepare("SELECT id FROM students WHERE class_id=? ORDER BY last_name, first_name");
    $students->execute([$classId]);
    $studentIds = array_map('intval', $students->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $validAgStmt = $pdo->prepare("SELECT id FROM ag_catalog WHERE school_year=? AND period_label=? AND is_active=1");
    $validAgStmt->execute([$schoolYear, $periodLabel]);
    $validAgIds = array_map('intval', $validAgStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $pdo->beginTransaction();
    $del = $pdo->prepare("DELETE FROM student_ag_assignments WHERE class_id=? AND school_year=? AND period_label=?");
    $del->execute([$classId, $schoolYear, $periodLabel]);
    $ins = $pdo->prepare("INSERT INTO student_ag_assignments (student_id, class_id, ag_id, school_year, period_label, created_by_user_id) VALUES (?,?,?,?,?,?)");
    $count = 0;
    foreach ($posted as $studentIdRaw => $agIdsRaw) {
      $sid = (int)$studentIdRaw;
      if (!in_array($sid, $studentIds, true)) continue;
      $agIds = is_array($agIdsRaw) ? $agIdsRaw : [];
      foreach ($agIds as $aidRaw) {
        $aid = (int)$aidRaw;
        if ($aid <= 0 || !in_array($aid, $validAgIds, true)) continue;
        $ins->execute([$sid, $classId, $aid, $schoolYear, $periodLabel, $userId]);
        $count++;
      }
    }
    $pdo->commit();
    audit('teacher_ag_assignment_save', $userId, ['class_id' => $classId, 'count' => $count]);
    $ok = 'AG-Zuordnungen gespeichert.';

    if ($isAjaxRequest) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => true, 'message' => $ok, 'saved' => $count], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
    if ($isAjaxRequest) {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

$students = [];
$ags = [];
$selectedMap = [];
if ($selectedClass) {
  try {
    $schoolYear = (string)$selectedClass['school_year'];
    $periodLabel = normalize_class_period_label((string)$selectedClass['period_label']);

    $stStudents = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE class_id=? ORDER BY last_name ASC, first_name ASC");
    $stStudents->execute([$classId]);
    $students = $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stAg = $pdo->prepare("SELECT id, ag_name FROM ag_catalog WHERE school_year=? AND period_label=? AND is_active=1 ORDER BY sort_order ASC, ag_name ASC");
    $stAg->execute([$schoolYear, $periodLabel]);
    $ags = $stAg->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stSel = $pdo->prepare("SELECT student_id, ag_id FROM student_ag_assignments WHERE class_id=? AND school_year=? AND period_label=?");
    $stSel->execute([$classId, $schoolYear, $periodLabel]);
    foreach ($stSel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $sid = (int)$r['student_id'];
      $aid = (int)$r['ag_id'];
      $selectedMap[$sid][$aid] = true;
    }
  } catch (Throwable $e) {
    $students = [];
    $ags = [];
    $selectedMap = [];
    if ($err === null) $err = 'AG-Daten konnten nicht geladen werden.';
  }
}

render_teacher_header('AG-Eingaben');
?>
<div class="card">
  <h1>AG-Eingaben</h1>
</div>
  <?php if ($ok): ?><div class="alert success"><?=h($ok)?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert danger"><?=h($err)?></div><?php endif; ?>
  <div class="card">
  <div class="muted" id="agAutoSaveHint">Änderungen werden automatisch gespeichert.</div>

  <form method="get" class="row-actions" id="agClassForm" style="margin: 20px 0;">
    <label>Klasse</label>
    <select class="input" name="class_id" onchange="this.form.submit()" style="width: auto;">
      <?php foreach ($classes as $c): ?>
        <option value="<?=h((string)$c['id'])?>" <?=((int)$c['id']===$classId)?'selected':''?>><?=h((string)$c['school_year'].' · '.ag_teacher_class_display($c).' · '.ag_teacher_period_display((string)$c['period_label']))?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!$selectedClass): ?>
    <div class="alert">Keine Klasse verfügbar.</div>
  <?php else: ?>
    <form method="post" id="agAssignForm">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="class_id" value="<?=h((string)$classId)?>">
      <input type="hidden" name="ajax" value="1">
      <style>
        .ag-choices { display:flex; flex-wrap:wrap; gap:8px; }
        .ag-chip {
          position: relative;
          display: inline-flex;
          align-items: center;
          border: 1px solid var(--border, #d0d7de);
          background: #fff;
          color: var(--text, #1f2328);
          border-radius: 999px;
          padding: 6px 12px;
          font-size: 14px;
          line-height: 1.2;
          cursor: pointer;
          user-select: none;
          transition: all .15s ease;
        }
        .ag-chip:hover { border-color: var(--primary, #0b57d0); }
        .ag-chip.is-selected {
          background: #eaf2ff;
          border-color: var(--primary, #0b57d0);
          color: var(--primary, #0b57d0);
          font-weight: 600;
        }
        .ag-chip input {
          position: absolute;
          opacity: 0;
          pointer-events: none;
        }
      </style>
      <table class="table">
        <tr><th>Schüler</th><th>Besuchte AGs</th></tr>
        <?php foreach ($students as $s): $sid=(int)$s['id']; ?>
          <tr>
            <td><?=h((string)$s['last_name'].', '.(string)$s['first_name'])?></td>
            <td>
              <div class="ag-choices">
                <?php foreach ($ags as $ag): $aid=(int)$ag['id']; $isChecked=!empty($selectedMap[$sid][$aid]); ?>
                  <label class="ag-chip <?=$isChecked?'is-selected':''?>">
                    <input type="checkbox" data-ag-checkbox="1" name="ag[<?=h((string)$sid)?>][]" value="<?=h((string)$aid)?>" <?=$isChecked?'checked':''?>>
                    <span><?=h((string)$ag['ag_name'])?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </form>
  <?php endif; ?>
</div>
<script>
(function(){
  const form = document.getElementById('agAssignForm');
  const hint = document.getElementById('agAutoSaveHint');
  if (!form) return;
  let timer = null;
  let inFlight = false;
  let queued = false;

  const doSave = async ()=>{
    if (inFlight) { queued = true; return; }
    inFlight = true;
    queued = false;
    if (hint) hint.textContent = 'Speichere…';
    try {
      const fd = new FormData(form);
      const resp = await fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });
      const json = await resp.json().catch(()=>({ok:false,error:'Ungültige Serverantwort.'}));
      if (!resp.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${resp.status}`);
      }
      if (hint) hint.textContent = json.message || 'Gespeichert.';
    } catch (e) {
      if (hint) hint.textContent = 'Fehler beim Speichern: ' + String(e?.message || e);
    } finally {
      inFlight = false;
      if (queued) setTimeout(doSave, 10);
    }
  };

  form.addEventListener('change', (ev)=>{
    const t = ev.target;
    if (!(t instanceof HTMLInputElement) || t.getAttribute('data-ag-checkbox') !== '1') return;
    const chip = t.closest('.ag-chip');
    if (chip) chip.classList.toggle('is-selected', t.checked);
    if (timer) clearTimeout(timer);
    timer = setTimeout(doSave, 250);
  });
})();
</script>
<?php render_teacher_footer();
