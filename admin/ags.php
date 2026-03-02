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
  $rows = $pdo->query("SELECT DISTINCT school_year, period_label FROM classes ORDER BY school_year ASC, period_label ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
  $rows = $pdo->query("SELECT DISTINCT school_year, period_label FROM ag_catalog ORDER BY school_year ASC, period_label ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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


function ag_scope_label(string $schoolYear, string $periodLabel): string {
  return $schoolYear . ' · ' . (ag_period_label_options()[$periodLabel] ?? $periodLabel);
}

function ag_scope_sort_rank(string $periodLabel): int {
  $p = normalize_class_period_label($periodLabel);
  if ($p === 'Standard') return 0;
  if ($p === 'H2') return 1;
  return 2;
}

function ag_scope_options(PDO $pdo): array {
  $rows = $pdo->query(
    "SELECT DISTINCT school_year, period_label
" .
    "FROM classes
" .
    "ORDER BY school_year ASC, CASE WHEN period_label='Standard' THEN 0 WHEN period_label='H2' THEN 1 ELSE 2 END, period_label ASC"
  )->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $seen = [];
  $out = [];
  foreach ($rows as $r) {
    $sy = trim((string)($r['school_year'] ?? ''));
    $pl = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
    if ($sy === '') continue;
    $key = $sy . '|' . $pl;
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = ['key' => $key, 'school_year' => $sy, 'period_label' => $pl, 'label' => ag_scope_label($sy, $pl)];
  }

  $rowsAg = $pdo->query(
    "SELECT DISTINCT school_year, period_label
" .
    "FROM ag_catalog
" .
    "ORDER BY school_year ASC, CASE WHEN period_label='Standard' THEN 0 WHEN period_label='H2' THEN 1 ELSE 2 END, period_label ASC"
  )->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rowsAg as $r) {
    $sy = trim((string)($r['school_year'] ?? ''));
    $pl = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
    if ($sy === '') continue;
    $key = $sy . '|' . $pl;
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = ['key' => $key, 'school_year' => $sy, 'period_label' => $pl, 'label' => ag_scope_label($sy, $pl)];
  }

  usort($out, static function(array $a, array $b): int {
    $syCmp = strcmp((string)$a['school_year'], (string)$b['school_year']);
    if ($syCmp !== 0) return $syCmp;
    $ra = ag_scope_sort_rank((string)$a['period_label']);
    $rb = ag_scope_sort_rank((string)$b['period_label']);
    if ($ra !== $rb) return $ra <=> $rb;
    return strcmp((string)$a['period_label'], (string)$b['period_label']);
  });

  return $out;
}


$scopeOptions = ag_scope_options($pdo);
if (!$scopeOptions) {
  $fallbackYear = date('Y') . '/' . substr((string)((int)date('Y') + 1), -2);
  $scopeOptions[] = [
    'key' => $fallbackYear . '|Standard',
    'school_year' => $fallbackYear,
    'period_label' => 'Standard',
    'label' => ag_scope_label($fallbackYear, 'Standard'),
  ];
}

$activeClassScope = null;
try {
  $activeClassScope = $pdo->query(
    "SELECT school_year, period_label
" .
    "FROM classes
" .
    "WHERE is_active=1
" .
    "ORDER BY school_year ASC, CASE WHEN period_label='Standard' THEN 0 WHEN period_label='H2' THEN 1 ELSE 2 END
" .
    "LIMIT 1"
  )->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $activeClassScope = null;
}

$defaultActiveKey = (string)($scopeOptions[0]['key'] ?? '');
if ($activeClassScope) {
  $sy = trim((string)($activeClassScope['school_year'] ?? ''));
  $pl = normalize_class_period_label((string)($activeClassScope['period_label'] ?? 'Standard'));
  if ($sy !== '') {
    $candidate = $sy . '|' . $pl;
    foreach ($scopeOptions as $opt) {
      if ((string)$opt['key'] === $candidate) { $defaultActiveKey = $candidate; break; }
    }
  }
}

$activeKey = trim((string)($_GET['active_key'] ?? $_POST['active_key'] ?? $defaultActiveKey));
$selectedScope = $scopeOptions[0];
foreach ($scopeOptions as $opt) {
  if ((string)$opt['key'] === $activeKey) { $selectedScope = $opt; break; }
}
$selectedYear = (string)$selectedScope['school_year'];
$selectedPeriod = (string)$selectedScope['period_label'];
$currentActiveKey = (string)$selectedScope['key'];

$sourceScopeOptions = [];
try {
  $rowsSrc = $pdo->query(
    "SELECT DISTINCT school_year, period_label
" .
    "FROM ag_catalog
" .
    "ORDER BY school_year ASC, CASE WHEN period_label='Standard' THEN 0 WHEN period_label='H2' THEN 1 ELSE 2 END, period_label ASC"
  )->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rowsSrc as $r) {
    $sy = trim((string)($r['school_year'] ?? ''));
    $pl = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
    if ($sy === '') continue;
    $k = $sy . '|' . $pl;
    $sourceScopeOptions[] = ['key'=>$k,'school_year'=>$sy,'period_label'=>$pl,'label'=>ag_scope_label($sy,$pl)];
  }
} catch (Throwable $e) {
  $sourceScopeOptions = [];
}

$defaultSourceKey = '';
foreach ($sourceScopeOptions as $opt) {
  if ((string)$opt['key'] !== $currentActiveKey) { $defaultSourceKey = (string)$opt['key']; break; }
}
if ($defaultSourceKey === '' && $sourceScopeOptions) $defaultSourceKey = (string)$sourceScopeOptions[0]['key'];
$sourceActiveKey = trim((string)($_POST['source_active_key'] ?? $_GET['source_active_key'] ?? $defaultSourceKey));
$sourceYear = '';
$sourcePeriod = 'Standard';
foreach ($sourceScopeOptions as $opt) {
  if ((string)$opt['key'] === $sourceActiveKey) {
    $sourceYear = (string)$opt['school_year'];
    $sourcePeriod = (string)$opt['period_label'];
    break;
  }
}
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
  "ORDER BY school_year ASC, CASE WHEN period_label='Standard' THEN 0 WHEN period_label='H2' THEN 1 ELSE 2 END, period_label ASC"
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
  "ORDER BY ag_name ASC, school_year ASC"
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

  <form method="get" class="row" style="gap:10px; align-items:flex-end;">
    <div>
      <label>Schuljahr/Halbjahr</label>
      <select class="input" name="active_key" onchange="this.form.submit()">
        <?php foreach ($scopeOptions as $opt): ?>
          <option value="<?=h((string)$opt['key'])?>" <?=((string)$opt['key']===$currentActiveKey)?'selected':''?>><?=h((string)$opt['label'])?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="school_year" value="<?=h($selectedYear)?>">
      <input type="hidden" name="period_label" value="<?=h($selectedPeriod)?>">
    </div>
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
    <div style="grid-column: span 2;">
      <label>Quelle Schuljahr/Halbjahr</label>
      <select class="input" name="source_active_key">
        <?php foreach ($sourceScopeOptions as $opt): ?>
          <option value="<?=h((string)$opt['key'])?>" <?=((string)$opt['key']===$sourceActiveKey)?'selected':''?>><?=h((string)$opt['label'])?></option>
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
