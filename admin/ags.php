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
  $out = [];
  $seen = [];
  $sources = [];
  $sources[] = "SELECT DISTINCT school_year, period_label FROM classes";
  if (db_has_table($pdo, 'ag_catalog_semester')) {
    $sources[] = "SELECT DISTINCT school_year, period_label FROM ag_catalog_semester";
  }

  foreach ($sources as $sql) {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
      $sy = trim((string)($r['school_year'] ?? ''));
      $pl = normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
      if ($sy === '') continue;
      $key = $sy . '|' . $pl;
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $out[] = ['key' => $key, 'school_year' => $sy, 'period_label' => $pl, 'label' => ag_scope_label($sy, $pl)];
    }
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

try { ensure_ag_tables($pdo); } catch (Throwable $e) {}

if (!db_has_table($pdo, 'ag_catalog') || !db_has_table($pdo, 'ag_catalog_semester')) {
  render_admin_header('AG-Verwaltung');
  echo '<div class="card"><h1>AG-Verwaltung</h1><div class="alert danger">AG-Tabellen fehlen in der Datenbank.</div></div>';
  render_admin_footer();
  exit;
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

$defaultActiveKey = (string)($scopeOptions[0]['key'] ?? '');
$activeKey = trim((string)($_GET['active_key'] ?? $_POST['active_key'] ?? $defaultActiveKey));
$selectedScope = $scopeOptions[0];
foreach ($scopeOptions as $opt) {
  if ((string)$opt['key'] === $activeKey) { $selectedScope = $opt; break; }
}
$selectedYear = (string)$selectedScope['school_year'];
$selectedPeriod = (string)$selectedScope['period_label'];
$currentActiveKey = (string)$selectedScope['key'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
      $name = trim((string)($_POST['ag_name'] ?? ''));
      if ($name === '') throw new RuntimeException('AG-Name fehlt.');
      $st = $pdo->prepare("INSERT INTO ag_catalog (ag_name, sort_order) VALUES (?,0) ON DUPLICATE KEY UPDATE ag_name=VALUES(ag_name)");
      $st->execute([$name]);
      $ok = 'AG gespeichert.';
    } elseif ($action === 'rename') {
      $id = (int)($_POST['id'] ?? 0);
      $newName = trim((string)($_POST['ag_name'] ?? ''));
      if ($id <= 0) throw new RuntimeException('AG-ID fehlt.');
      if ($newName === '') throw new RuntimeException('Neuer AG-Name fehlt.');
      $st = $pdo->prepare("UPDATE ag_catalog SET ag_name=?, updated_at=NOW() WHERE id=?");
      $st->execute([$newName, $id]);
      $ok = 'AG umbenannt.';
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('AG-ID fehlt.');
      $pdo->prepare("DELETE FROM student_ag_assignments WHERE ag_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM ag_catalog_semester WHERE ag_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM ag_catalog WHERE id=?")->execute([$id]);
      $ok = 'AG gelöscht.';
    } elseif ($action === 'toggle_scope') {
      $id = (int)($_POST['id'] ?? 0);
      $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
      if ($id <= 0) throw new RuntimeException('AG-ID fehlt.');
      $st = $pdo->prepare(
        "INSERT INTO ag_catalog_semester (ag_id, school_year, period_label, is_active)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), updated_at=NOW()"
      );
      $st->execute([$id, $selectedYear, $selectedPeriod, $active]);
      $ok = 'Verfügbarkeit aktualisiert.';
    } elseif ($action === 'copy_previous') {
      $srcKey = trim((string)($_POST['source_active_key'] ?? ''));
      if ($srcKey === '') throw new RuntimeException('Quell-Halbjahr fehlt.');
      [$srcYear, $srcPeriod] = array_pad(explode('|', $srcKey, 2), 2, '');
      $srcYear = trim($srcYear);
      $srcPeriod = normalize_class_period_label($srcPeriod);
      if ($srcYear === '') throw new RuntimeException('Quell-Halbjahr fehlt.');
      $rows = $pdo->prepare("SELECT ag_id, is_active FROM ag_catalog_semester WHERE school_year=? AND period_label=?");
      $rows->execute([$srcYear, $srcPeriod]);
      $all = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $ins = $pdo->prepare(
        "INSERT INTO ag_catalog_semester (ag_id, school_year, period_label, is_active)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), updated_at=NOW()"
      );
      $count = 0;
      foreach ($all as $r) {
        $ins->execute([(int)$r['ag_id'], $selectedYear, $selectedPeriod, (int)$r['is_active']]);
        $count++;
      }
      $ok = 'Verfügbarkeit übernommen: ' . $count;
    }

    audit('ag_admin_update', (int)current_user()['id'], ['school_year' => $selectedYear, 'period_label' => $selectedPeriod]);
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$ags = $pdo->query("SELECT id, ag_name FROM ag_catalog ORDER BY ag_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$stAvail = $pdo->prepare("SELECT ag_id, is_active FROM ag_catalog_semester WHERE school_year=? AND period_label=?");
$stAvail->execute([$selectedYear, $selectedPeriod]);
$availability = [];
foreach ($stAvail->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
  $availability[(int)$r['ag_id']] = ((int)$r['is_active'] === 1);
}

$sourceScopeOptions = [];
foreach ($scopeOptions as $opt) {
  if ((string)$opt['key'] === $currentActiveKey) continue;
  $sourceScopeOptions[] = $opt;
}
$sourceActiveKey = (string)($sourceScopeOptions[0]['key'] ?? '');

$overviewScopes = $scopeOptions;
$scopeKeys = [];
$scopeLabels = [];
foreach ($overviewScopes as $sc) {
  $k = (string)$sc['key'];
  $scopeKeys[] = $k;
  $scopeLabels[$k] = (string)$sc['label'];
}
$overview = [];
if ($ags) {
  $rows = $pdo->query("SELECT ag_id, school_year, period_label, is_active FROM ag_catalog_semester")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($ags as $a) {
    $overview[(string)$a['ag_name']] = [];
  }
  $nameById = [];
  foreach ($ags as $a) $nameById[(int)$a['id']] = (string)$a['ag_name'];
  foreach ($rows as $r) {
    $aid = (int)($r['ag_id'] ?? 0);
    if (!isset($nameById[$aid])) continue;
    $k = trim((string)($r['school_year'] ?? '')) . '|' . normalize_class_period_label((string)($r['period_label'] ?? 'Standard'));
    if ($k === '|' || !isset($scopeLabels[$k])) continue;
    $overview[$nameById[$aid]][$k] = ((int)($r['is_active'] ?? 0) === 1);
  }
}

render_admin_header('AG-Verwaltung');
?>
<div class="card" id="agAdminRoot">
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
    </div>
  </form>

  <h3>AG anlegen</h3>
  <form method="post" class="grid" style="grid-template-columns:2fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="active_key" value="<?=h($currentActiveKey)?>">
    <div><label>Name</label><input class="input" name="ag_name" required></div>
    <div><button class="btn primary" type="submit">Speichern</button></div>
  </form>

  <h3>Verfügbarkeit aus Vorhalbjahr übernehmen</h3>
  <form method="post" class="grid" style="grid-template-columns:1fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="copy_previous">
    <input type="hidden" name="active_key" value="<?=h($currentActiveKey)?>">
    <div>
      <label>Quelle Schuljahr/Halbjahr</label>
      <select class="input" name="source_active_key" <?= $sourceScopeOptions ? '' : 'disabled' ?>>
        <?php foreach ($sourceScopeOptions as $opt): ?>
          <option value="<?=h((string)$opt['key'])?>" <?=((string)$opt['key']===$sourceActiveKey)?'selected':''?>><?=h((string)$opt['label'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><button class="btn secondary" type="submit" <?= $sourceScopeOptions ? '' : 'disabled' ?>>Übernehmen</button></div>
  </form>

  <h3>AGs und Verfügbarkeit</h3>
  <table class="table">
    <tr><th>Name</th><th>Für <?=h(ag_scope_label($selectedYear, $selectedPeriod))?></th><th>Aktion</th></tr>
    <?php foreach ($ags as $a): $aid=(int)$a['id']; $isActive = $availability[$aid] ?? false; ?>
      <tr>
        <td>
          <form method="post" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="active_key" value="<?=h($currentActiveKey)?>">
            <input type="hidden" name="id" value="<?=h((string)$aid)?>">
            <input class="input" name="ag_name" value="<?=h((string)$a['ag_name'])?>" style="min-width:220px;">
            <button class="btn secondary" type="submit">Umbenennen</button>
          </form>
        </td>
        <td>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="toggle_scope">
            <input type="hidden" name="active_key" value="<?=h($currentActiveKey)?>">
            <input type="hidden" name="id" value="<?=h((string)$aid)?>">
            <input type="hidden" name="is_active" value="<?=$isActive?'0':'1'?>">
            <button class="btn secondary" type="submit"><?=$isActive?'Deaktivieren':'Aktivieren'?></button>
          </form>
        </td>
        <td>
          <form method="post" style="display:inline;" onsubmit="return confirm('AG wirklich löschen?');">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="active_key" value="<?=h($currentActiveKey)?>">
            <input type="hidden" name="id" value="<?=h((string)$aid)?>">
            <button class="btn danger" type="submit">Löschen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h3>Übersicht AG-Verfügbarkeit (alle Halbjahre)</h3>
  <?php if (!$scopeKeys): ?>
    <div class="alert">Noch keine Halbjahre vorhanden.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table">
        <tr>
          <th>AG</th>
          <?php foreach ($scopeKeys as $k): ?>
            <th><?=h((string)($scopeLabels[$k] ?? $k))?></th>
          <?php endforeach; ?>
        </tr>
        <?php foreach ($overview as $agName => $availabilityByScope): ?>
          <tr>
            <td><strong><?=h((string)$agName)?></strong></td>
            <?php foreach ($scopeKeys as $k): ?>
              <?php $state = $availabilityByScope[$k] ?? null; ?>
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

<script>
(function(){
  async function submitViaAjax(form){
    const submitBtn = form.querySelector('button[type="submit"]');
    const oldLabel = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Speichere…'; }
    try {
      const resp = await fetch(form.action || window.location.href, {
        method: (form.method || 'POST').toUpperCase(),
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const html = await resp.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const nextRoot = doc.querySelector('#agAdminRoot');
      const currentRoot = document.querySelector('#agAdminRoot');
      if (nextRoot && currentRoot) {
        currentRoot.replaceWith(nextRoot);
      }
    } catch (e) {
      alert('Speichern fehlgeschlagen: ' + String(e && e.message ? e.message : e));
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = oldLabel; }
    }
  }

  document.addEventListener('submit', function(ev){
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.method || '').toLowerCase() !== 'post') return;
    if (!form.closest('#agAdminRoot')) return;
    ev.preventDefault();
    submitViaAjax(form);
  });
})();
</script>
<?php render_admin_footer();
