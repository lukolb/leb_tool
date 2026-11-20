<?php
// teacher/classes.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

if (!headers_sent()) {
  header('Cross-Origin-Opener-Policy: same-origin');
  header('Cross-Origin-Embedder-Policy: require-corp');
}

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$err = '';
$ok = '';
$ttsGenerationClassId = 0;

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

// NEW: normalize wizard display (per-class)
function normalize_wizard_display(string $v): string {
  $v = strtolower(trim($v));
  return in_array($v, ['groups','items','beginner'], true) ? $v : 'groups';
}

// POST: toggle active/inactive (teachers can archive their classes; admins can toggle all)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'toggle_active') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      if (($u['role'] ?? '') !== 'admin' && !user_can_manage_class($pdo, $userId, $classId)) {
        throw new RuntimeException('Keine Berechtigung.');
      }

      $st = $pdo->prepare("SELECT is_active FROM classes WHERE id=?");
      $st->execute([$classId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Klasse nicht gefunden.');

      $new = ((int)$row['is_active'] === 1) ? 0 : 1;
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE classes SET is_active=?, inactive_at=IF(?, NULL, COALESCE(inactive_at, NOW())) WHERE id=?")
        ->execute([$new, $new, $classId]);
      $reportsFinalized = $new === 0 ? finalize_reports_for_class($pdo, $classId, $userId) : 0;
      $pdo->commit();

      audit('teacher_class_toggle_active', $userId, [
        'class_id'=>$classId,
        'is_active'=>$new,
        'reports_finalized'=>$reportsFinalized,
      ]);
      $ok = $new
        ? t('teacher.classes.alert_ok_active', 'Klasse aktiviert.')
        : str_replace(
            '{count}',
            (string)$reportsFinalized,
            t('teacher.classes.alert_ok_inactive_reports', 'Klasse archiviert; {count} Berichte wurden abgeschlossen.')
          );
    }

    elseif ($action === 'toggle_tts') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      if (($u['role'] ?? '') !== 'admin' && !user_can_manage_class($pdo, $userId, $classId)) {
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
      if ($new === 1) $ttsGenerationClassId = $classId;
    }

    elseif ($action === 'prepare_tts') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
      if (($u['role'] ?? '') !== 'admin' && !user_can_manage_class($pdo, $userId, $classId)) {
        throw new RuntimeException('Keine Berechtigung.');
      }
      $st = $pdo->prepare('SELECT tts_enabled FROM classes WHERE id=? LIMIT 1');
      $st->execute([$classId]);
      if ((int)($st->fetchColumn() ?: 0) !== 1) {
        throw new RuntimeException(t('teacher.classes.tts.enable_first', 'Bitte aktiviere Vorlesen zuerst.'));
      }
      $ttsGenerationClassId = $classId;
      $ok = t('teacher.classes.tts.preparing', 'Die Audio-Vorbereitung wurde gestartet.');
    }

    elseif ($action === 'toggle_syllable_coloring') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
      if (($u['role'] ?? '') !== 'admin' && !user_can_manage_class($pdo, $userId, $classId)) {
        throw new RuntimeException('Keine Berechtigung.');
      }
      $new = (int)($_POST['syllable_coloring_enabled'] ?? 0) === 1 ? 1 : 0;
      $pdo->prepare('UPDATE classes SET syllable_coloring_enabled=? WHERE id=?')->execute([$new, $classId]);
      audit('class_toggle_syllable_coloring', $userId, [
        'class_id' => $classId,
        'syllable_coloring_enabled' => $new,
      ]);
      $ok = $new
        ? t('teacher.classes.syllables.on', 'Silbenschrift aktiviert.')
        : t('teacher.classes.syllables.off', 'Silbenschrift deaktiviert.');
    }

    // NEW: teacher/admin can set per-class wizard display
    elseif ($action === 'set_wizard_display') {
      $classId = (int)($_POST['class_id'] ?? 0);
      if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

      if (($u['role'] ?? '') !== 'admin' && !user_can_manage_class($pdo, $userId, $classId)) {
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
    if ($pdo->inTransaction()) $pdo->rollBack();
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

<?php if ($ttsGenerationClassId > 0): ?>
  <div class="card" id="ttsPreparationCard">
    <h2><?=h(t('teacher.classes.tts.preparation_heading', 'Vorlese-Audios vorbereiten'))?></h2>
    <p class="muted" id="ttsPreparationStatus"><?=h(t('teacher.classes.tts.loading_plan', 'Kompetenztexte werden geprüft …'))?></p>
    <div style="height:12px; border-radius:999px; overflow:hidden; background:#e5e7eb;">
      <div id="ttsPreparationBar" style="height:100%; width:0; background:var(--primary); transition:width .2s;"></div>
    </div>
    <p class="muted" id="ttsPreparationDetail"></p>
  </div>
  <script type="importmap">
    {"imports":{"onnxruntime-web":<?=json_encode(url('assets/vits-web/dist/onnxruntime-web.js'))?>}}
  </script>
  <script type="module">
  const apiUrl = <?=json_encode(url('teacher/ajax/tts_audio_cache_api.php'))?>;
  const moduleUrl = <?=json_encode(url('assets/vits-web/dist/vits-web.js'))?>;
  const classId = <?=json_encode($ttsGenerationClassId)?>;
  let csrf = <?=json_encode(csrf_token())?>;
  const statusEl = document.getElementById('ttsPreparationStatus');
  const detailEl = document.getElementById('ttsPreparationDetail');
  const barEl = document.getElementById('ttsPreparationBar');
  const text = {
    generating: <?=json_encode(t('teacher.classes.tts.generating', 'Audiodatei {done} von {total} wird erzeugt …'))?>,
    done: <?=json_encode(t('teacher.classes.tts.done', 'Audio-Vorbereitung abgeschlossen.'))?>,
    failed: <?=json_encode(t('teacher.classes.tts.failed', 'Einige Audiodateien konnten nicht erzeugt werden.'))?>,
    cached: <?=json_encode(t('teacher.classes.tts.cached_detail', '{cached} bereits vorhanden · {created} neu erzeugt · {failed} fehlgeschlagen'))?>,
  };
  const fmt = (value, replacements) => Object.entries(replacements).reduce((out, [key, val]) => out.replaceAll('{' + key + '}', String(val)), value);
  async function postForm(form){
    const res = await fetch(apiUrl, { method:'POST', body:form, credentials:'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) throw new Error(data?.message || 'Audio-API error');
    if (data.csrf_token) csrf = data.csrf_token;
    return data;
  }
  async function run(){
    try {
      const planForm = new FormData();
      planForm.set('action', 'plan'); planForm.set('class_id', String(classId)); planForm.set('csrf_token', csrf);
      const plan = await postForm(planForm);
      const pending = (plan.items || []).filter(item => !item.cached);
      let created = 0, failed = 0, processed = 0;
      if (!pending.length) {
        barEl.style.width = '100%';
        statusEl.textContent = text.done;
        detailEl.textContent = fmt(text.cached, { cached: plan.cached || 0, created: 0, failed: 0 });
        return;
      }
      const modRaw = await import(moduleUrl);
      const mod = modRaw?.TtsSession ? modRaw : modRaw?.default;
      if (!mod?.TtsSession) throw new Error('vits-web could not be loaded');
      const sessions = new Map();
      for (const item of pending) {
        processed++;
        statusEl.textContent = fmt(text.generating, { done: processed, total: pending.length });
        try {
          let session = sessions.get(item.voice_id);
          if (!session) {
            session = await mod.TtsSession.create({ voiceId:item.voice_id });
            sessions.set(item.voice_id, session);
          }
          const blob = await session.predict(item.text);
          const upload = new FormData();
          upload.set('action', 'upload'); upload.set('class_id', String(classId)); upload.set('csrf_token', csrf);
          upload.set('cache_key', item.cache_key); upload.set('text', item.text);
          upload.set('language', item.language); upload.set('voice_id', item.voice_id);
          upload.set('source_type', item.source_type); upload.set('source_key', item.source_key);
          upload.set('source_label', item.source_label); upload.set('audio', blob, item.cache_key + '.wav');
          await postForm(upload);
          created++;
        } catch (error) {
          console.warn('TTS pre-generation failed', item.cache_key, error);
          failed++;
        }
        barEl.style.width = Math.round(processed / pending.length * 100) + '%';
        detailEl.textContent = fmt(text.cached, { cached: plan.cached || 0, created, failed });
      }
      statusEl.textContent = failed ? text.failed : text.done;
    } catch (error) {
      console.error(error);
      statusEl.textContent = text.failed;
      detailEl.textContent = error?.message || String(error);
    }
  }
  run();
  </script>
<?php endif; ?>

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
              <th><?=h(t('admin.classes.period_label', 'Halbjahr'))?></th>
              <th><?=h(t('teacher.classes.table.status', 'Status'))?></th>
              <th><?=h(t('teacher.classes.table.wizard', 'Wizard'))?></th>
              <th><?=h(t('teacher.classes.table.tts', 'Vorlesen'))?></th>
              <th><?=h(t('teacher.classes.table.syllables', 'Silbenschrift'))?></th>
              <th><?=h(t('teacher.classes.table.actions', 'Aktion'))?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $c): ?>
            <tr>
              <td><?=h(class_display($c))?></td>
              <td><?=h(period_label_display($c['period_label'] ?? 'Standard'))?></td>
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
                    <option value="beginner" <?=$cur==='beginner'?'selected':''?>><?=h(t('teacher.classes.wizard.beginner', 'Leseanfänger'))?></option>
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
                <?php if ($ttsEnabled): ?>
                  <form method="post" style="margin:6px 0 0;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="prepare_tts">
                    <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                    <button class="btn secondary" type="submit"><?=h(t('teacher.classes.tts.prepare', 'Audios vorbereiten'))?></button>
                  </form>
                <?php endif; ?>
              </td>

              <td>
                <?php $syllablesEnabled = (int)($c['syllable_coloring_enabled'] ?? 0) === 1; ?>
                <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
                  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="toggle_syllable_coloring">
                  <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                  <input type="hidden" name="syllable_coloring_enabled" value="<?=$syllablesEnabled ? '0' : '1'?>">
                  <button class="btn secondary" type="submit"><?=$syllablesEnabled ? h(t('teacher.classes.syllables.disable', 'Deaktivieren')) : h(t('teacher.classes.syllables.enable', 'Aktivieren'))?></button>
                  <span class="muted" style="font-size:12px;"><?=$syllablesEnabled ? h(t('teacher.classes.tts.status_on', 'Aktiv')) : h(t('teacher.classes.tts.status_off', 'Deaktiviert'))?></span>
                </form>
              </td>

              <td>
                <div class="action-menu">
                  <button class="btn secondary action-menu-toggle" type="button" aria-haspopup="menu" aria-expanded="false">
                    <?=h(t('teacher.classes.actions', 'Aktionen'))?> <span aria-hidden="true">▾</span>
                  </button>
                  <template class="action-menu-template">
                    <a class="btn primary" href="<?=h(url('teacher/students.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.classes.action.students', 'Schüler verwalten'))?></a>
                    <a class="btn primary" href="<?=h(url('teacher/entry.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.classes.action.entries', 'Eingaben'))?></a>
                    <a class="btn secondary" href="<?=h(url('teacher/class_intro.php?class_id=' . (int)$c['id']))?>"><?=h(t('teacher.classes.action.intro', 'Intro bearbeiten'))?></a>
                    <form id="classActiveForm" method="post">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="class_id" value="<?=h((string)$c['id'])?>">
                      <a class="btn secondary" type="submit" onclick="this.closest('form').submit(); return false;"><?=((int)$c['is_active']===1)?h(t('teacher.classes.action.toggle_inactive', 'Inaktiv setzen')):h(t('teacher.classes.action.toggle_active', 'Aktivieren'))?></a>
                    </form>
                  </template>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div id="rowActionMenu" class="action-dropdown-menu hidden" role="menu" aria-hidden="true"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const menu = document.getElementById('rowActionMenu');
  if (!menu) return;

  let currentButton = null;

  const closeMenu = () => {
    if (!currentButton) return;
    currentButton.setAttribute('aria-expanded', 'false');
    currentButton = null;
    menu.classList.add('hidden');
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    menu.innerHTML = '';
  };

  const positionMenu = () => {
    if (!currentButton) return;
    const rect = currentButton.getBoundingClientRect();
    const scrollX = window.scrollX || window.pageXOffset;
    const scrollY = window.scrollY || window.pageYOffset;
    const maxLeft = scrollX + document.documentElement.clientWidth - menu.offsetWidth - 8;
    const left = Math.max(scrollX + 8, Math.min(rect.right + scrollX - menu.offsetWidth, maxLeft));
    menu.style.left = `${left}px`;
    menu.style.top = `${rect.bottom + scrollY}px`;
  };

  const openMenu = (button, template) => {
    if (currentButton === button) {
      closeMenu();
      return;
    }
    if (currentButton) {
      currentButton.setAttribute('aria-expanded', 'false');
    }
    menu.innerHTML = '';
    if (template && template.content) {
      menu.appendChild(template.content.cloneNode(true));
    }
    menu.classList.remove('hidden');
    menu.classList.add('open');
    menu.setAttribute('aria-hidden', 'false');
    button.setAttribute('aria-expanded', 'true');
    currentButton = button;
    positionMenu();
  };

  document.addEventListener('click', function (event) {
    const button = event.target.closest('.action-menu-toggle');
    if (button) {
      event.preventDefault();
      const wrapper = button.closest('.action-menu');
      const template = wrapper ? wrapper.querySelector('.action-menu-template') : null;
      openMenu(button, template);
      return;
    }
    if (menu.contains(event.target)) return;
    closeMenu();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
    }
  });

  window.addEventListener('resize', closeMenu);
  window.addEventListener('scroll', closeMenu, true);
});
</script>

<?php
render_teacher_footer();
