<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/tts_audio_cache.php';
require_admin();

if (!headers_sent()) {
  header('Cross-Origin-Opener-Policy: same-origin');
  header('Cross-Origin-Embedder-Policy: require-corp');
}

$pdo = db();
$u = current_user();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_period') {
      $schoolYear = trim((string)($_POST['school_year'] ?? ''));
      $periodLabel = normalize_class_period_label((string)($_POST['period_label'] ?? ''));
      if ((string)($_POST['confirm'] ?? '') !== '1') {
        throw new RuntimeException(t('tts_audio.admin.confirm_required', 'Bitte bestätige die Löschung des Schulhalbjahres.'));
      }
      $result = tts_audio_delete_period($pdo, $schoolYear, $periodLabel);
      audit('tts_audio_delete_period', (int)($u['id'] ?? 0), [
        'school_year' => $schoolYear,
        'period_label' => $periodLabel,
        'links' => $result['links'],
        'files' => $result['files'],
      ]);
      $ok = strtr(t('tts_audio.admin.deleted_period', '{files} Audiodateien für {year} · {period} wurden gelöscht.'), [
        '{files}' => (string)$result['files'],
        '{year}' => $schoolYear,
        '{period}' => $periodLabel,
      ]);
    } elseif ($action === 'delete_file') {
      $audioId = (int)($_POST['audio_id'] ?? 0);
      $result = tts_audio_delete_file($pdo, $audioId);
      audit('tts_audio_delete_file', (int)($u['id'] ?? 0), ['audio_id' => $audioId]);
      $ok = $result['files'] > 0
        ? t('tts_audio.admin.deleted_file', 'Audiodatei wurde gelöscht.')
        : t('tts_audio.admin.file_missing', 'Audiodatei war bereits nicht mehr vorhanden.');
    } else {
      throw new RuntimeException(t('tts_audio.error.action', 'Unbekannte Audio-Aktion.'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$selectedYear = trim((string)($_GET['school_year'] ?? ''));
$selectedPeriod = trim((string)($_GET['period_label'] ?? ''));
$selectedClass = (int)($_GET['class_id'] ?? 0);

$periods = $pdo->query(
  "SELECT DISTINCT c.school_year, c.period_label
   FROM classes c
   ORDER BY c.school_year DESC, CASE c.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query(
  "SELECT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name,
          c.is_active, c.tts_enabled
   FROM classes c
   WHERE c.is_active=1
      OR EXISTS (SELECT 1 FROM tts_audio_class_links l WHERE l.class_id=c.id)
   ORDER BY c.school_year DESC, c.grade_level, c.label, c.name"
)->fetchAll(PDO::FETCH_ASSOC);
$generationClasses = array_values(array_filter($classes, static function (array $class) use ($selectedYear, $selectedPeriod, $selectedClass): bool {
  if ((int)($class['is_active'] ?? 0) !== 1) return false;
  if ($selectedYear !== '' && (string)$class['school_year'] !== $selectedYear) return false;
  if ($selectedPeriod !== '' && normalize_class_period_label((string)$class['period_label']) !== normalize_class_period_label($selectedPeriod)) return false;
  if ($selectedClass > 0 && (int)$class['id'] !== $selectedClass) return false;
  return true;
}));

$where = [];
$params = [];
if ($selectedYear !== '') { $where[] = 'c.school_year=?'; $params[] = $selectedYear; }
if ($selectedPeriod !== '') { $where[] = 'c.period_label=?'; $params[] = normalize_class_period_label($selectedPeriod); }
if ($selectedClass > 0) { $where[] = 'c.id=?'; $params[] = $selectedClass; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stRows = $pdo->prepare(
  "SELECT f.id, f.cache_key, f.language, f.voice_id, f.text_content, f.mime_type,
          f.file_size, f.created_at, f.updated_at,
          l.source_type, l.source_key, l.source_label,
          c.id AS class_id, c.school_year, c.period_label, c.grade_level, c.label, c.name
   FROM tts_audio_class_links l
   INNER JOIN tts_audio_files f ON f.id=l.audio_id
   INNER JOIN classes c ON c.id=l.class_id
   $whereSql
   ORDER BY c.school_year DESC, c.grade_level, c.label, c.name, f.language, f.created_at DESC"
);
$stRows->execute($params);
$linkRows = $stRows->fetchAll(PDO::FETCH_ASSOC);
$rowsByAudio = [];
foreach ($linkRows as $row) {
  $audioId = (int)$row['id'];
  if (!isset($rowsByAudio[$audioId])) {
    $row['class_usages'] = [];
    $row['source_types'] = [];
    $rowsByAudio[$audioId] = $row;
  }
  $rowsByAudio[$audioId]['class_usages'][(int)$row['class_id']] = [
    'class_id' => (int)$row['class_id'],
    'school_year' => (string)$row['school_year'],
    'period_label' => (string)$row['period_label'],
    'grade_level' => $row['grade_level'],
    'label' => (string)$row['label'],
    'name' => (string)$row['name'],
  ];
  $sourceType = trim((string)($row['source_type'] ?? ''));
  if ($sourceType !== '') $rowsByAudio[$audioId]['source_types'][$sourceType] = $sourceType;
}
$rows = array_values($rowsByAudio);
$totalBytes = array_sum(array_map(static fn(array $row): int => (int)($row['file_size'] ?? 0), $rows));

function tts_admin_class_name(array $row): string {
  $grade = $row['grade_level'] !== null ? (int)$row['grade_level'] : null;
  $label = trim((string)($row['label'] ?? ''));
  return $grade !== null && $label !== '' ? ($grade . $label) : ((string)($row['name'] ?? '') ?: ('#' . (int)($row['class_id'] ?? $row['id'] ?? 0)));
}

function tts_admin_bytes(int $bytes): string {
  if ($bytes >= 1024 * 1024) return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
  if ($bytes >= 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
  return $bytes . ' B';
}

render_admin_header(t('tts_audio.admin.title', 'Audioverwaltung'));
?>
<div class="card">
  <h1><?=h(t('tts_audio.admin.heading', 'Vorlese-Audiodateien'))?></h1>
  <p class="muted"><?=h(t('tts_audio.admin.intro', 'Hier können fehlende Audiodateien für mehrere Klassen erzeugt sowie vorhandene Dateien angehört und verwaltet werden.'))?></p>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card" id="ttsBulkCard">
  <h2><?=h(t('tts_audio.admin.bulk_heading', 'Audiodateien gesammelt erzeugen'))?></h2>
  <p class="muted"><?=h(t('tts_audio.admin.bulk_intro', 'Wähle aktive Klassen aus. Die Audios können auch vorbereitet werden, wenn Vorlesen für eine Klasse noch nicht aktiviert ist. Identische Texte verwenden klassenübergreifend dieselbe Datei; nur noch nicht vorhandene Texte werden erzeugt. Die Auswahl folgt den Filtern dieser Seite.'))?></p>
  <?php if (!$generationClasses): ?>
    <p class="muted"><?=h(t('tts_audio.admin.bulk_empty', 'Für die aktuelle Auswahl gibt es keine aktive Klasse.'))?></p>
  <?php else: ?>
    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
      <label style="display:flex; gap:8px; align-items:center; font-weight:700;">
        <input type="checkbox" id="ttsSelectAll">
        <?=h(t('tts_audio.admin.bulk_select_all', 'Alle angezeigten Klassen auswählen'))?>
      </label>
      <button class="btn primary" type="button" id="ttsBulkStart"><?=h(t('tts_audio.admin.bulk_generate', 'Audios für Auswahl erzeugen'))?></button>
      <button class="btn secondary" type="button" id="ttsBulkRefresh" hidden><?=h(t('tts_audio.admin.bulk_refresh', 'Dateiliste aktualisieren'))?></button>
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; margin-bottom:14px;">
      <?php foreach ($generationClasses as $class): ?>
        <?php $className = tts_admin_class_name($class); ?>
        <label style="display:flex; gap:8px; align-items:flex-start; border:1px solid var(--border, #d1d5db); border-radius:8px; padding:10px;">
          <input type="checkbox" class="tts-bulk-class" value="<?=(int)$class['id']?>" data-class-label="<?=h($className)?>">
          <span>
            <strong><?=h($className)?></strong><br>
            <small class="muted"><?=h((string)$class['school_year'] . ' · ' . (string)$class['period_label'])?></small><br>
            <small class="muted"><?=h((int)($class['tts_enabled'] ?? 0) === 1 ? t('tts_audio.admin.bulk_tts_on', 'Vorlesen aktiv') : t('tts_audio.admin.bulk_tts_off', 'Vorlesen noch nicht aktiviert'))?></small>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <div id="ttsBulkProgress" hidden>
      <p id="ttsBulkStatus" style="margin-bottom:8px;"></p>
      <div style="height:12px; border-radius:999px; overflow:hidden; background:#e5e7eb;">
        <div id="ttsBulkBar" style="height:100%; width:0; background:var(--primary); transition:width .2s;"></div>
      </div>
      <p class="muted" id="ttsBulkDetail"></p>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <form method="get" class="actions" style="justify-content:flex-start; align-items:flex-end;">
    <label><?=h(t('tts_audio.admin.school_year', 'Schuljahr'))?><br>
      <select name="school_year">
        <option value=""><?=h(t('tts_audio.admin.all', 'Alle'))?></option>
        <?php foreach (array_values(array_unique(array_column($periods, 'school_year'))) as $year): ?>
          <option value="<?=h((string)$year)?>" <?=$selectedYear===(string)$year?'selected':''?>><?=h((string)$year)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?=h(t('tts_audio.admin.period', 'Halbjahr'))?><br>
      <select name="period_label">
        <option value=""><?=h(t('tts_audio.admin.all', 'Alle'))?></option>
        <option value="Standard" <?=$selectedPeriod==='Standard'?'selected':''?>><?=h(t('admin.classes.period.h1', '1. HJ'))?></option>
        <option value="H2" <?=$selectedPeriod==='H2'?'selected':''?>><?=h(t('admin.classes.period.h2', '2. HJ'))?></option>
      </select>
    </label>
    <label><?=h(t('tts_audio.admin.class', 'Klasse'))?><br>
      <select name="class_id">
        <option value="0"><?=h(t('tts_audio.admin.all', 'Alle'))?></option>
        <?php foreach ($classes as $class): ?>
          <option value="<?=(int)$class['id']?>" <?=$selectedClass===(int)$class['id']?'selected':''?>>
            <?=h((string)$class['school_year'] . ' · ' . (string)$class['period_label'] . ' · ' . tts_admin_class_name($class))?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn secondary" type="submit"><?=h(t('tts_audio.admin.filter', 'Filtern'))?></button>
    <a class="btn secondary" href="<?=h(url('admin/tts_audio.php'))?>"><?=h(t('tts_audio.admin.reset', 'Zurücksetzen'))?></a>
  </form>
  <p><strong><?=count($rows)?></strong> <?=h(t('tts_audio.admin.files', 'Audiodateien'))?> · <strong><?=h(tts_admin_bytes($totalBytes))?></strong></p>
</div>

<div class="card">
  <h2><?=h(t('tts_audio.admin.delete_period_heading', 'Schulhalbjahr gesammelt löschen'))?></h2>
  <form method="post" class="actions" style="justify-content:flex-start; align-items:flex-end;" onsubmit="return confirm(<?=h(json_encode(t('tts_audio.admin.delete_period_confirm', 'Audiodateien dieses Schulhalbjahres wirklich löschen? Gemeinsam von anderen Klassen verwendete Dateien bleiben erhalten.')))?>);">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="delete_period">
    <label><?=h(t('tts_audio.admin.period_scope', 'Schulhalbjahr'))?><br>
      <select name="school_year" required>
        <option value=""><?=h(t('tts_audio.admin.choose', 'Bitte wählen…'))?></option>
        <?php foreach (array_values(array_unique(array_column($periods, 'school_year'))) as $year): ?>
          <option value="<?=h((string)$year)?>"><?=h((string)$year)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?=h(t('tts_audio.admin.period', 'Halbjahr'))?><br>
      <select name="period_label" required>
        <option value="Standard"><?=h(t('admin.classes.period.h1', '1. HJ'))?></option>
        <option value="H2"><?=h(t('admin.classes.period.h2', '2. HJ'))?></option>
      </select>
    </label>
    <label style="display:flex; gap:8px; align-items:center;"><input type="checkbox" name="confirm" value="1" required> <?=h(t('tts_audio.admin.confirm', 'Löschung bestätigen'))?></label>
    <button class="btn danger" type="submit"><?=h(t('tts_audio.admin.delete_period', 'Audios löschen'))?></button>
  </form>
</div>

<div class="card">
  <h2><?=h(t('tts_audio.admin.list_heading', 'Vorhandene Audiodateien'))?></h2>
  <?php if (!$rows): ?>
    <p class="muted"><?=h(t('tts_audio.admin.empty', 'Keine Audiodateien für diese Auswahl vorhanden.'))?></p>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="table">
        <thead><tr>
          <th><?=h(t('tts_audio.admin.used_in', 'Verwendet in'))?></th>
          <th><?=h(t('tts_audio.admin.language', 'Sprache/Stimme'))?></th>
          <th><?=h(t('tts_audio.admin.text', 'Text'))?></th>
          <th><?=h(t('tts_audio.admin.listen', 'Anhören'))?></th>
          <th><?=h(t('tts_audio.admin.size', 'Größe'))?></th>
          <th><?=h(t('tts_audio.admin.actions', 'Aktion'))?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <?php foreach (array_values($row['class_usages']) as $usage): ?>
                <div style="margin-bottom:4px;"><strong><?=h(tts_admin_class_name($usage))?></strong><br><small class="muted"><?=h((string)$usage['school_year'] . ' · ' . (string)$usage['period_label'])?></small></div>
              <?php endforeach; ?>
            </td>
            <td><span class="badge"><?=h(strtoupper((string)$row['language']))?></span><br><small><?=h((string)$row['voice_id'])?></small></td>
            <td style="min-width:280px;"><?=h((string)$row['text_content'])?><br><small class="muted"><?=h(implode(', ', array_values($row['source_types'])))?></small></td>
            <td><audio controls preload="none" style="max-width:260px;" src="<?=h(url('admin/ajax/tts_audio_stream.php?id=' . (int)$row['id']))?>"></audio></td>
            <td><?=h(tts_admin_bytes((int)$row['file_size']))?><br><small class="muted"><?=h((string)$row['created_at'])?></small></td>
            <td>
              <form method="post" onsubmit="return confirm(<?=h(json_encode(t('tts_audio.admin.delete_file_confirm', 'Diese Audiodatei aus allen Klassen löschen?')))?>);">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="audio_id" value="<?=(int)$row['id']?>">
                <button class="btn danger" type="submit"><?=h(t('tts_audio.admin.delete_file', 'Löschen'))?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php if ($generationClasses): ?>
  <script type="importmap">
    {"imports":{"onnxruntime-web":<?=json_encode(url('assets/vits-web/dist/onnxruntime-web.js'))?>}}
  </script>
  <script type="module">
  const apiUrl = <?=json_encode(url('admin/ajax/tts_audio_cache_api.php'))?>;
  const moduleUrl = <?=json_encode(url('assets/vits-web/dist/vits-web.js'))?>;
  let csrf = <?=json_encode(csrf_token())?>;
  const classBoxes = Array.from(document.querySelectorAll('.tts-bulk-class'));
  const selectAll = document.getElementById('ttsSelectAll');
  const startButton = document.getElementById('ttsBulkStart');
  const refreshButton = document.getElementById('ttsBulkRefresh');
  const progress = document.getElementById('ttsBulkProgress');
  const statusEl = document.getElementById('ttsBulkStatus');
  const detailEl = document.getElementById('ttsBulkDetail');
  const barEl = document.getElementById('ttsBulkBar');
  const text = {
    choose: <?=json_encode(t('tts_audio.admin.bulk_choose', 'Bitte wähle mindestens eine Klasse aus.'))?>,
    checking: <?=json_encode(t('tts_audio.admin.bulk_checking', 'Klasse {current} von {classes} · {name}: benötigte Audios werden geprüft …'))?>,
    generating: <?=json_encode(t('tts_audio.admin.bulk_generating', 'Klasse {current} von {classes} · {name}: Datei {done} von {total} wird erzeugt …'))?>,
    done: <?=json_encode(t('tts_audio.admin.bulk_done', 'Audioerzeugung für die ausgewählten Klassen abgeschlossen.'))?>,
    failed: <?=json_encode(t('tts_audio.admin.bulk_failed', 'Die Audioerzeugung wurde mit Fehlern abgeschlossen.'))?>,
    detail: <?=json_encode(t('tts_audio.admin.bulk_detail', '{cached} bereits vorhanden · {created} neu erzeugt · {failed} fehlgeschlagen'))?>,
  };
  const fmt = (value, replacements) => Object.entries(replacements).reduce((out, [key, val]) => out.replaceAll('{' + key + '}', String(val)), value);
  const updateSelectAll = () => {
    const checked = classBoxes.filter(box => box.checked).length;
    selectAll.checked = checked === classBoxes.length;
    selectAll.indeterminate = checked > 0 && checked < classBoxes.length;
  };
  classBoxes.forEach(box => box.addEventListener('change', updateSelectAll));
  selectAll.addEventListener('change', () => {
    classBoxes.forEach(box => { box.checked = selectAll.checked; });
    updateSelectAll();
  });
  refreshButton.addEventListener('click', () => window.location.reload());

  async function postForm(form) {
    const res = await fetch(apiUrl, { method:'POST', body:form, credentials:'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) throw new Error(data?.message || 'Audio-API error');
    if (data.csrf_token) csrf = data.csrf_token;
    return data;
  }

  startButton.addEventListener('click', async () => {
    const selected = classBoxes.filter(box => box.checked).map(box => ({ id:box.value, name:box.dataset.classLabel || box.value }));
    progress.hidden = false;
    refreshButton.hidden = true;
    barEl.style.width = '0%';
    if (!selected.length) {
      statusEl.textContent = text.choose;
      detailEl.textContent = '';
      return;
    }

    startButton.disabled = true;
    selectAll.disabled = true;
    classBoxes.forEach(box => { box.disabled = true; });
    let cached = 0, created = 0, failed = 0, classErrors = 0;
    let ttsModule = null;
    const sessions = new Map();
    const showDetail = () => {
      detailEl.textContent = fmt(text.detail, { cached, created, failed });
    };
    try {
      for (let classIndex = 0; classIndex < selected.length; classIndex++) {
        const current = selected[classIndex];
        statusEl.textContent = fmt(text.checking, { current:classIndex + 1, classes:selected.length, name:current.name });
        let plan;
        try {
          const planForm = new FormData();
          planForm.set('action', 'plan');
          planForm.set('class_id', String(current.id));
          planForm.set('csrf_token', csrf);
          plan = await postForm(planForm);
        } catch (error) {
          console.warn('TTS plan failed', current.id, error);
          classErrors++;
          failed++;
          barEl.style.width = Math.round((classIndex + 1) / selected.length * 100) + '%';
          showDetail();
          continue;
        }
        cached += Number(plan.cached || 0);
        const pending = (plan.items || []).filter(item => !item.cached);
        if (pending.length && !ttsModule) {
          const modRaw = await import(moduleUrl);
          ttsModule = modRaw?.TtsSession ? modRaw : modRaw?.default;
          if (!ttsModule?.TtsSession) throw new Error('vits-web could not be loaded');
        }
        for (let itemIndex = 0; itemIndex < pending.length; itemIndex++) {
          const item = pending[itemIndex];
          statusEl.textContent = fmt(text.generating, {
            current:classIndex + 1, classes:selected.length, name:current.name,
            done:itemIndex + 1, total:pending.length,
          });
          try {
            let session = sessions.get(item.voice_id);
            if (!session) {
              session = await ttsModule.TtsSession.create({ voiceId:item.voice_id });
              sessions.set(item.voice_id, session);
            }
            const blob = await session.predict(item.text);
            const upload = new FormData();
            upload.set('action', 'upload'); upload.set('class_id', String(current.id)); upload.set('csrf_token', csrf);
            upload.set('cache_key', item.cache_key); upload.set('text', item.text);
            upload.set('language', item.language); upload.set('voice_id', item.voice_id);
            upload.set('source_type', item.source_type); upload.set('source_key', item.source_key);
            upload.set('source_label', item.source_label); upload.set('audio', blob, item.cache_key + '.wav');
            await postForm(upload);
            created++;
          } catch (error) {
            console.warn('TTS bulk generation failed', item.cache_key, error);
            failed++;
          }
          const classProgress = pending.length ? ((itemIndex + 1) / pending.length) : 1;
          barEl.style.width = Math.round((classIndex + classProgress) / selected.length * 100) + '%';
          showDetail();
        }
        barEl.style.width = Math.round((classIndex + 1) / selected.length * 100) + '%';
        showDetail();
      }
      statusEl.textContent = (failed || classErrors) ? text.failed : text.done;
    } catch (error) {
      console.error(error);
      statusEl.textContent = text.failed;
      detailEl.textContent = (error?.message || String(error)) + ' · ' + fmt(text.detail, { cached, created, failed });
    } finally {
      startButton.disabled = false;
      selectAll.disabled = false;
      classBoxes.forEach(box => { box.disabled = false; });
      refreshButton.hidden = false;
    }
  });
  </script>
<?php endif; ?>
<?php render_admin_footer(); ?>
