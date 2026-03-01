<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$ok = null;
$err = null;

function ag_period_label_options(): array {
  return [
    'Standard' => t('admin.classes.period.h1', '1. Halbjahr'),
    'H2' => t('admin.classes.period.h2', '2. Halbjahr'),
  ];
}

try { ensure_ag_tables($pdo); } catch (Throwable $e) {}

if (!db_has_table($pdo, 'ag_catalog')) {
  render_admin_header('AG-Verwaltung');
  echo '<div class="card"><h1>AG-Verwaltung</h1><div class="alert danger">AG-Tabellen fehlen in der Datenbank.</div></div>';
  render_admin_footer();
  exit;
}

function class_scopes(PDO $pdo): array {
  $rows = $pdo->query("SELECT DISTINCT school_year, period_label FROM classes ORDER BY school_year DESC, period_label ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $r) {
    $y = trim((string)($r['school_year'] ?? ''));
    $p = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
    if ($y === '') continue;
    if (!isset($out[$y])) $out[$y] = [];
    if (!in_array($p, $out[$y], true)) $out[$y][] = $p;
  }
  return $out;
}

function ag_scopes(PDO $pdo): array {
  $rows = $pdo->query("SELECT DISTINCT school_year, period_label FROM ag_catalog ORDER BY school_year DESC, period_label ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $r) {
    $y = trim((string)($r['school_year'] ?? ''));
    $p = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
    if ($y === '') continue;
    if (!isset($out[$y])) $out[$y] = [];
    if (!in_array($p, $out[$y], true)) $out[$y][] = $p;
  }
  return $out;
}

$classScopes = class_scopes($pdo);
$agScopes = ag_scopes($pdo);
$scopeYears = array_values(array_unique(array_merge(array_keys($classScopes), array_keys($agScopes))));
rsort($scopeYears);

if (!$scopeYears) {
  $scopeYears = [date('Y') . '/' . substr((string)((int)date('Y') + 1), -2)];
}

$selectedYear = trim((string)($_GET['school_year'] ?? $_POST['school_year'] ?? $scopeYears[0]));
if (!in_array($selectedYear, $scopeYears, true)) $selectedYear = $scopeYears[0];

$periodChoices = array_keys(ag_period_label_options());
$selectedPeriod = normalize_class_period_label((string)($_GET['period_label'] ?? $_POST['period_label'] ?? 'Standard'));
if (!in_array($selectedPeriod, $periodChoices, true)) $selectedPeriod = 'Standard';

$sourceYears = array_keys($agScopes);
if (!$sourceYears) $sourceYears = $scopeYears;
rsort($sourceYears);
$sourceYear = trim((string)($_POST['source_school_year'] ?? $_GET['source_school_year'] ?? $sourceYears[0] ?? $selectedYear));
if (!in_array($sourceYear, $sourceYears, true)) $sourceYear = $sourceYears[0] ?? $selectedYear;
$sourcePeriodChoices = array_keys(ag_period_label_options());
$sourcePeriod = normalize_class_period_label((string)($_POST['source_period_label'] ?? $_GET['source_period_label'] ?? 'Standard'));
if (!in_array($sourcePeriod, $sourcePeriodChoices, true)) $sourcePeriod = 'Standard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
      $name = trim((string)($_POST['ag_name'] ?? ''));
      if ($name === '') throw new RuntimeException('AG-Name fehlt.');
      $sort = (int)($_POST['sort_order'] ?? 0);
      $st = $pdo->prepare("INSERT INTO ag_catalog (school_year, period_label, ag_name, sort_order, is_active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), is_active=1");
      $st->execute([$selectedYear, $selectedPeriod, $name, $sort]);
      $ok = 'AG gespeichert.';
    } elseif ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
      $st = $pdo->prepare("UPDATE ag_catalog SET is_active=?, updated_at=NOW() WHERE id=?");
      $st->execute([$active, $id]);
      $ok = 'AG aktualisiert.';
    } elseif ($action === 'rename') {
      $id = (int)($_POST['id'] ?? 0);
      $newName = trim((string)($_POST['ag_name'] ?? ''));
      if ($id <= 0) throw new RuntimeException('AG-ID fehlt.');
      if ($newName === '') throw new RuntimeException('Neuer AG-Name fehlt.');
      $st = $pdo->prepare("UPDATE ag_catalog SET ag_name=?, updated_at=NOW() WHERE id=?");
      $st->execute([$newName, $id]);
      $ok = 'AG umbenannt.';
    } elseif ($action === 'copy_previous') {
      if ($sourceYear === '' || $sourcePeriod === '') throw new RuntimeException('Quell-Halbjahr fehlt.');
      $src = $pdo->prepare("SELECT ag_name, sort_order, is_active FROM ag_catalog WHERE school_year=? AND period_label=? ORDER BY sort_order ASC, ag_name ASC");
      $src->execute([$sourceYear, $sourcePeriod]);
      $rows = $src->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $ins = $pdo->prepare("INSERT INTO ag_catalog (school_year, period_label, ag_name, sort_order, is_active) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), is_active=VALUES(is_active)");
      $count = 0;
      foreach ($rows as $r) {
        $ins->execute([$selectedYear, $selectedPeriod, (string)$r['ag_name'], (int)$r['sort_order'], (int)$r['is_active']]);
        $count++;
      }
      $ok = 'AGs übernommen: ' . $count;
    }
    audit('ag_admin_update', (int)current_user()['id'], ['school_year' => $selectedYear, 'period_label' => $selectedPeriod]);
    $agScopes = ag_scopes($pdo);
    $sourceYears = array_keys($agScopes);
    if (!$sourceYears) $sourceYears = $scopeYears;
    rsort($sourceYears);
    if (!in_array($sourceYear, $sourceYears, true)) $sourceYear = $sourceYears[0] ?? $selectedYear;
    $sourcePeriodChoices = array_keys(ag_period_label_options());
    if (!in_array($sourcePeriod, $sourcePeriodChoices, true)) $sourcePeriod = 'Standard';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$stAg = $pdo->prepare("SELECT id, ag_name, sort_order, is_active FROM ag_catalog WHERE school_year=? AND period_label=? ORDER BY sort_order ASC, ag_name ASC");
$stAg->execute([$selectedYear, $selectedPeriod]);
$ags = $stAg->fetchAll(PDO::FETCH_ASSOC) ?: [];

$overviewScopes = $pdo->query(
  "SELECT DISTINCT school_year, period_label
" .
  "FROM ag_catalog
" .
  "ORDER BY school_year DESC, CASE WHEN period_label='Standard' THEN 0 WHEN period_label='H2' THEN 1 ELSE 2 END, period_label ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$scopeKeys = [];
$scopeLabels = [];
foreach ($overviewScopes as $sc) {
  $sy = trim((string)($sc['school_year'] ?? ''));
  $pl = normalize_class_period_label((string)($sc['period_label'] ?? 'Standard'));
  if ($sy === '') continue;
  $k = $sy . '|' . $pl;
  $scopeKeys[] = $k;
  $scopeLabels[$k] = $sy . ' · ' . (ag_period_label_options()[$pl] ?? $pl);
}
$overview = [];
$rowsAll = $pdo->query(
  "SELECT school_year, period_label, ag_name, is_active
" .
  "FROM ag_catalog
" .
  "ORDER BY ag_name ASC, school_year DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rowsAll as $r) {
  $name = trim((string)($r['ag_name'] ?? ''));
  $sy = trim((string)($r['school_year'] ?? ''));
  $pl = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
  if ($name === '' || $sy === '') continue;
  $k = $sy . '|' . $pl;
  if (!isset($overview[$name])) $overview[$name] = [];
  $overview[$name][$k] = ((int)($r['is_active'] ?? 0) === 1);
}

render_admin_header('AG-Verwaltung');
?>
<div class="card">
  <h1>AG-Verwaltung</h1>
  <?php if ($ok): ?><div class="alert success"><?=h($ok)?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert danger"><?=h($err)?></div><?php endif; ?>

  <form method="get" class="grid" style="grid-template-columns:1fr 1fr auto; gap:10px; align-items:end;">
    <div>
      <label>Schuljahr</label>
      <select class="input" name="school_year">
        <?php foreach ($scopeYears as $y): ?>
          <option value="<?=h($y)?>" <?=$y===$selectedYear?'selected':''?>><?=h($y)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Halbjahr</label>
      <select class="input" name="period_label">
        <?php foreach ($periodChoices as $p): ?>
          <option value="<?=h($p)?>" <?=$p===$selectedPeriod?'selected':''?>><?=h(ag_period_label_options()[$p] ?? $p)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><button class="btn secondary" type="submit">Anzeigen</button></div>
  </form>

  <h3>AG hinzufügen</h3>
  <form method="post" class="grid" style="grid-template-columns:2fr 1fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="school_year" value="<?=h($selectedYear)?>">
    <input type="hidden" name="period_label" value="<?=h($selectedPeriod)?>">
    <div><label>Name</label><input class="input" name="ag_name" required></div>
    <div><label>Sortierung</label><input class="input" type="number" name="sort_order" value="0"></div>
    <div><button class="btn primary" type="submit">Speichern</button></div>
  </form>

  <h3>Verfügbarkeit aus Vorhalbjahr übernehmen</h3>
  <form method="post" class="grid" style="grid-template-columns:1fr 1fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="copy_previous">
    <input type="hidden" name="school_year" value="<?=h($selectedYear)?>">
    <input type="hidden" name="period_label" value="<?=h($selectedPeriod)?>">
    <div>
      <label>Quelle Schuljahr</label>
      <select class="input" name="source_school_year">
        <?php foreach ($sourceYears as $y): ?>
          <option value="<?=h($y)?>" <?=$y===$sourceYear?'selected':''?>><?=h($y)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Quelle Halbjahr</label>
      <select class="input" name="source_period_label">
        <?php foreach ($sourcePeriodChoices as $p): ?>
          <option value="<?=h($p)?>" <?=$p===$sourcePeriod?'selected':''?>><?=h(ag_period_label_options()[$p] ?? $p)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><button class="btn secondary" type="submit">Übernehmen</button></div>
  </form>

  <h3>Verfügbare AGs</h3>
  <table class="table">
    <tr><th>Name</th><th>Sortierung</th><th>Status</th><th>Aktion</th></tr>
    <?php foreach ($ags as $a): ?>
      <tr>
        <td>
          <form method="post" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="school_year" value="<?=h($selectedYear)?>">
            <input type="hidden" name="period_label" value="<?=h($selectedPeriod)?>">
            <input type="hidden" name="id" value="<?=h((string)$a['id'])?>">
            <input class="input" name="ag_name" value="<?=h((string)$a['ag_name'])?>" style="min-width:220px;">
            <button class="btn secondary" type="submit">Umbenennen</button>
          </form>
        </td>
        <td><?=h((string)$a['sort_order'])?></td>
        <td><?=((int)$a['is_active']===1)?'aktiv':'inaktiv'?></td>
        <td>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="school_year" value="<?=h($selectedYear)?>">
            <input type="hidden" name="period_label" value="<?=h($selectedPeriod)?>">
            <input type="hidden" name="id" value="<?=h((string)$a['id'])?>">
            <input type="hidden" name="is_active" value="<?=((int)$a['is_active']===1)?'0':'1'?>">
            <button class="btn secondary" type="submit"><?=((int)$a['is_active']===1)?'Deaktivieren':'Aktivieren'?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h3>Übersicht AG-Verfügbarkeit (alle Halbjahre)</h3>
  <?php if (!$scopeKeys): ?>
    <div class="alert">Noch keine AGs angelegt.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table">
        <tr>
          <th>AG</th>
          <?php foreach ($scopeKeys as $k): ?>
            <th><?=h((string)($scopeLabels[$k] ?? $k))?></th>
          <?php endforeach; ?>
        </tr>
        <?php foreach ($overview as $agName => $availability): ?>
          <tr>
            <td><strong><?=h((string)$agName)?></strong></td>
            <?php foreach ($scopeKeys as $k): ?>
              <?php $state = $availability[$k] ?? null; ?>
              <td>
                <?php if ($state === true): ?>
                  <span class="badge" style="background:rgba(0,150,0,.08);">verfügbar</span>
                <?php elseif ($state === false): ?>
                  <span class="badge" style="background:rgba(150,0,0,.08);">inaktiv</span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php render_admin_footer();
