<?php
// teacher/classes.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$err = '';
$ok = '';

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

// NEW: normalize wizard display (per-class)
function normalize_wizard_display(string $v): string {
  $v = strtolower(trim($v));
  return in_array($v, ['groups','items'], true) ? $v : 'groups';
}

// POST: toggle active/inactive (teachers can archive their classes; admins can toggle all)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'toggle_active') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
        throw new RuntimeException('Keine Berechtigung.');
      }

      $st = $pdo->prepare("SELECT is_active FROM classes WHERE id=?");
      $st->execute([$classId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Klasse nicht gefunden.');

      $new = ((int)$row['is_active'] === 1) ? 0 : 1;
      $pdo->prepare("UPDATE classes SET is_active=?, inactive_at=IF(?, NULL, COALESCE(inactive_at, NOW())) WHERE id=?")
          ->execute([$new, $new, $classId]);

      audit('teacher_class_toggle_active', $userId, ['class_id'=>$classId,'is_active'=>$new]);
      $ok = $new ? t('teacher.classes.alert_ok_active', 'Klasse aktiviert.') : t('teacher.classes.alert_ok_inactive', 'Klasse inaktiv gesetzt.');
    }

    elseif ($action === 'toggle_tts') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
        throw new RuntimeException('Keine Berechtigung.');
      }

      $new = (int)($_POST['tts_enabled'] ?? 0) === 1 ? 1 : 0;
      $pdo->prepare("UPDATE classes SET tts_enabled=? WHERE id=?")
          ->execute([$new, $classId]);

      audit('class_toggle_tts', $userId, [
        'class_id' => $classId,
        'tts_enabled' => $new,
      ]);

      $ok = $new
        ? t('teacher.classes.alert_ok_tts_on', 'Vorlesefunktion aktiviert.')
        : t('teacher.classes.alert_ok_tts_off', 'Vorlesefunktion deaktiviert.');
    }

    // NEW: teacher/admin can set per-class wizard display
    elseif ($action === 'set_wizard_display') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
        throw new RuntimeException('Keine Berechtigung.');
      }

      $mode = normalize_wizard_display((string)($_POST['student_wizard_display'] ?? 'groups'));

      $pdo->prepare("UPDATE classes SET student_wizard_display=? WHERE id=?")
          ->execute([$mode, $classId]);

      audit('class_set_student_wizard_display', $userId, [
        'class_id'=>$classId,
        'student_wizard_display'=>$mode
      ]);

      $ok = t('teacher.classes.alert_ok_wizard', 'Wizard-Anzeige wurde gespeichert.');
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$showInactive = (int)($_GET['show_inactive'] ?? 0) === 1;

// Load classes assigned to teacher (admins see all)
if (($u['role'] ?? '') === 'admin') {
  $where = $showInactive ? '' : 'WHERE c.is_active=1';
  $st = $pdo->query(
    "SELECT c.*
     FROM classes c
     $where
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  $where = $showInactive ? '' : 'AND c.is_active=1';
  $st = $pdo->prepare(
    "SELECT c.*
     FROM classes c
     INNER JOIN user_class_assignments uca ON uca.class_id=c.id
     WHERE uca.user_id=? $where
     ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Group by school_year
$grouped = [];
foreach ($classes as $c) {
  $y = (string)$c['school_year'];
  if (!isset($grouped[$y])) $grouped[$y] = [];
  $grouped[$y][] = $c;
}

render_teacher_header(t('teacher.classes.title', 'Klassen'));
?>

<div class="card">
    <h1><?=h(t('teacher.classes.card_title', 'Klassen'))?></h1>

</div>
  <?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <div class="actions" style="justify-content:flex-start;">
    <?php if ($showInactive): ?>
      <a class="btn secondary" href="<?=h(url('teacher/classes.php'))?>"><?=h(t('teacher.classes.hide_inactive', 'Inaktive ausblenden'))?></a>
    <?php else: ?>
      <a class="btn secondary" href="<?=h(url('teacher/classes.php?show_inactive=1'))?>"><?=h(t('teacher.classes.show_inactive', 'Inaktive anzeigen'))?></a>
    <?php endif; ?>
  </div>

  <?php if (!$grouped): ?>
    <div class="alert"><?=h(t('teacher.classes.none', 'Keine Klassen zugeordnet.'))?></div>
  <?php else: ?>
    <?php foreach ($grouped as $year => $items): ?>
      <details open style="margin-top:10px;">
        <summary style="cursor:pointer; font-weight:700; padding:10px 0;"><?=h($year)?> (<?=count($items)?>)</summary>
        <table class="table">
          <thead>
            <tr>
              <th><?=h(t('teacher.classes.table.class', 'Klasse'))?></th>
              <th><?=h(t('teacher.classes.table.status', 'Status'))?></th>
              <th><?=h(t('teacher.classes.table.wizard', 'Wizard'))?></th>
              <th><?=h(t('teacher.classes.table.tts', 'Vorlesen'))?></th>
              <th><?=h(t('teacher.classes.table.actions', 'Aktion'))?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $c): ?>
            <tr>
              <td><?=h(class_display($c))?></td>
              <td><?=((int)$c['is_active']===1) ? '<span class="badge">' . h(t('teacher.classes.status.active', 'aktiv')) . '</span>' : '<span class="badge">' . h(t('teacher.classes.status.inactive', 'inaktiv')) . '</span>'?></td>

              <td>
                <?php $cur = normalize_wizard_display((string)($c['student_wizard_display'] ?? 'groups')); ?>
                <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="set_wizard_display">
                  <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                  <select name="student_wizard_display" style="min-width:160px;">
                    <option value="groups" <?=$cur==='groups'?'selected':''?>><?=h(t('teacher.classes.wizard.groups', 'Gruppen'))?></option>
                    <option value="items" <?=$cur==='items'?'selected':''?>><?=h(t('teacher.classes.wizard.items', 'Items'))?></option>
                  </select>
                  <button class="btn secondary" type="submit"><?=h(t('teacher.classes.wizard.save', 'Speichern'))?></button>
                </form>
              </td>

              <td>
                <?php $ttsEnabled = (int)($c['tts_enabled'] ?? 0) === 1; ?>
                <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="toggle_tts">
                  <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                  <input type="hidden" name="tts_enabled" value="<?= $ttsEnabled ? '0' : '1' ?>">
                  <button class="btn secondary" type="submit"><?= $ttsEnabled ? h(t('teacher.classes.tts.disable', 'Vorlesen deaktivieren')) : h(t('teacher.classes.tts.enable', 'Vorlesen aktivieren')) ?></button>
                  <span class="muted" style="font-size:12px;">
                    <?= $ttsEnabled ? h(t('teacher.classes.tts.status_on', 'Aktiv')) : h(t('teacher.classes.tts.status_off', 'Deaktiviert')) ?>
                  </span>
                </form>
              </td>

              <td style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn primary" href="<?=h(url('teacher/students.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.classes.action.students', 'Schüler verwalten'))?></a>
                <a class="btn primary" href="<?=h(url('teacher/entry.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.classes.action.entries', 'Eingaben'))?></a>
                <form id="classActiveForm" method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                  <a class="btn secondary" type="submit" onclick="this.closest('form').submit(); return false;"><?=((int)$c['is_active']===1)?h(t('teacher.classes.action.toggle_inactive', 'Inaktiv setzen')):h(t('teacher.classes.action.toggle_active', 'Aktivieren'))?></a>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
render_teacher_footer();
