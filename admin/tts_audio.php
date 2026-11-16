<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/tts_audio_cache.php';
require_admin();

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
   FROM tts_audio_class_links l
   INNER JOIN classes c ON c.id=l.class_id
   ORDER BY c.school_year DESC, CASE c.period_label WHEN 'H2' THEN 2 ELSE 1 END DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query(
  "SELECT DISTINCT c.id, c.school_year, c.period_label, c.grade_level, c.label, c.name
   FROM tts_audio_class_links l
   INNER JOIN classes c ON c.id=l.class_id
   ORDER BY c.school_year DESC, c.grade_level, c.label, c.name"
)->fetchAll(PDO::FETCH_ASSOC);

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
$rows = $stRows->fetchAll(PDO::FETCH_ASSOC);
$totalBytes = array_sum(array_map(static fn(array $row): int => (int)($row['file_size'] ?? 0), $rows));

function tts_admin_class_name(array $row): string {
  $grade = $row['grade_level'] !== null ? (int)$row['grade_level'] : null;
  $label = trim((string)($row['label'] ?? ''));
  return $grade !== null && $label !== '' ? ($grade . $label) : ((string)($row['name'] ?? '') ?: ('#' . (int)$row['class_id']));
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
  <p class="muted"><?=h(t('tts_audio.admin.intro', 'Hier werden vorab oder beim ersten Abruf erzeugte Audiodateien angezeigt. Dateien können angehört, einzeln oder gesammelt nach Schulhalbjahr gelöscht werden.'))?></p>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

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
  <p><strong><?=count($rows)?></strong> <?=h(t('tts_audio.admin.files', 'Dateizuordnungen'))?> · <strong><?=h(tts_admin_bytes($totalBytes))?></strong></p>
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
          <th><?=h(t('tts_audio.admin.class', 'Klasse'))?></th>
          <th><?=h(t('tts_audio.admin.language', 'Sprache/Stimme'))?></th>
          <th><?=h(t('tts_audio.admin.text', 'Text'))?></th>
          <th><?=h(t('tts_audio.admin.listen', 'Anhören'))?></th>
          <th><?=h(t('tts_audio.admin.size', 'Größe'))?></th>
          <th><?=h(t('tts_audio.admin.actions', 'Aktion'))?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><strong><?=h(tts_admin_class_name($row))?></strong><br><span class="muted"><?=h((string)$row['school_year'] . ' · ' . (string)$row['period_label'])?></span></td>
            <td><span class="badge"><?=h(strtoupper((string)$row['language']))?></span><br><small><?=h((string)$row['voice_id'])?></small></td>
            <td style="min-width:280px;"><?=h((string)$row['text_content'])?><br><small class="muted"><?=h((string)$row['source_type'])?></small></td>
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
<?php render_admin_footer(); ?>
