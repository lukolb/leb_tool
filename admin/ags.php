<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$ok = null;
$err = null;

function load_periods_for_year(PDO $pdo, string $schoolYear): array {
  $st = $pdo->prepare("SELECT DISTINCT period_label FROM classes WHERE school_year=? ORDER BY period_label ASC");
  $st->execute([$schoolYear]);
  $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $out = [];
  foreach ($rows as $r) {
    $p = normalize_class_period_label((string)$r);
    if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
  }
  if (!in_array('Standard', $out, true)) $out[] = 'Standard';
  return $out;
}

$allYears = $pdo->query("SELECT DISTINCT school_year FROM classes ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$selectedYear = trim((string)($_GET['school_year'] ?? $_POST['school_year'] ?? ($allYears[0] ?? date('Y') . '/' . substr((string)((int)date('Y') + 1), -2))));
$periods = load_periods_for_year($pdo, $selectedYear);
$selectedPeriod = normalize_class_period_label((string)($_GET['period_label'] ?? $_POST['period_label'] ?? ($periods[0] ?? 'Standard')));
if (!in_array($selectedPeriod, $periods, true)) $selectedPeriod = $periods[0] ?? 'Standard';

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
    } elseif ($action === 'copy_previous') {
      $srcYear = trim((string)($_POST['source_school_year'] ?? ''));
      $srcPeriod = normalize_class_period_label((string)($_POST['source_period_label'] ?? ''));
      if ($srcYear === '' || $srcPeriod === '') throw new RuntimeException('Quell-Halbjahr fehlt.');
      $src = $pdo->prepare("SELECT ag_name, sort_order, is_active FROM ag_catalog WHERE school_year=? AND period_label=? ORDER BY sort_order ASC, ag_name ASC");
      $src->execute([$srcYear, $srcPeriod]);
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

render_admin_header('AG-Verwaltung');
?>
<div class="card">
  <h1>AG-Verwaltung</h1>
  <?php if ($ok): ?><div class="alert success"><?=h($ok)?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert danger"><?=h($err)?></div><?php endif; ?>

  <form method="get" class="grid" style="grid-template-columns:1fr 1fr auto; gap:10px; align-items:end;">
    <div><label>Schuljahr</label><input class="input" name="school_year" value="<?=h($selectedYear)?>"></div>
    <div><label>Halbjahr</label><select class="input" name="period_label"><?php foreach ($periods as $p): ?><option value="<?=h($p)?>" <?=$p===$selectedPeriod?'selected':''?>><?=h($p)?></option><?php endforeach; ?></select></div>
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
    <div><label>Quelle Schuljahr</label><input class="input" name="source_school_year" value="<?=h($selectedYear)?>"></div>
    <div><label>Quelle Halbjahr</label><input class="input" name="source_period_label" placeholder="z.B. 1. Halbjahr" required></div>
    <div><button class="btn secondary" type="submit">Übernehmen</button></div>
  </form>

  <h3>Verfügbare AGs</h3>
  <table class="table">
    <tr><th>Name</th><th>Sortierung</th><th>Status</th><th>Aktion</th></tr>
    <?php foreach ($ags as $a): ?>
      <tr>
        <td><?=h((string)$a['ag_name'])?></td>
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
</div>
<?php render_admin_footer();
