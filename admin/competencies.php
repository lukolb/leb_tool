<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();
$pdo = db();
$ok = null; $err = null;
$gradeOptions = [1,2,3,4];

if (isset($_GET['download_csv_template'])) {
  $csv = "category_de;category_en;subcategory_de;subcategory_en;code;text_de;text_en;required;grades\n";
  $csv .= "Sozialkompetenz;Social skills;Kommunikation;Communication;SOC-001;Hört anderen aufmerksam zu.;Listens attentively to others.;1;1,2\n";
  $csv .= "Lernkompetenz;Learning skills;Selbstorganisation;Self-organization;LRN-010;Plant Aufgaben eigenständig.;Plans tasks independently.;0;3,4\n";
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="kompetenzen_vorlage.csv"');
  echo $csv;
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $a = (string)($_POST['action'] ?? '');

    if ($a === 'add_category') {
      $pdo->prepare("INSERT INTO competency_categories(name_de,name_en,sort_order) VALUES (?,?,?)")
        ->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en']), (int)($_POST['sort_order'] ?? 0)]);
    }

    if ($a === 'add_subcategory') {
      $pdo->prepare("INSERT INTO competency_subcategories(category_id,name_de,name_en,sort_order) VALUES (?,?,?,?)")
        ->execute([(int)$_POST['category_id'], trim((string)$_POST['name_de']), trim((string)$_POST['name_en']), (int)($_POST['sort_order'] ?? 0)]);
    }

    if ($a === 'add_competency') {
      $pdo->prepare("INSERT INTO competencies(subcategory_id,code,text_de,text_en,is_required,sort_order) VALUES (?,?,?,?,?,?)")
        ->execute([(int)$_POST['subcategory_id'], trim((string)$_POST['code']), trim((string)$_POST['text_de']), trim((string)$_POST['text_en']), isset($_POST['is_required'])?1:0, (int)($_POST['sort_order'] ?? 0)]);
      $cid = (int)$pdo->lastInsertId();
      foreach ((array)($_POST['grades'] ?? []) as $g) {
        $gi = (int)$g; if ($gi < 1 || $gi > 4) continue;
        $pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$cid,$gi]);
      }
    }

    if ($a === 'csv_import') {
      if (!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) throw new RuntimeException('CSV fehlt');
      $fh = fopen($_FILES['csv']['tmp_name'], 'r'); if (!$fh) throw new RuntimeException('CSV konnte nicht geöffnet werden');
      $line = 0;
      while (($r = fgetcsv($fh, 0, ';')) !== false) {
        $line++; if ($line === 1) continue; if (count($r) < 9) continue;
        [$catDe,$catEn,$subDe,$subEn,$code,$de,$en,$required,$grades] = $r;
        $catDe=trim($catDe); $catEn=trim($catEn); $subDe=trim($subDe); $subEn=trim($subEn); $de=trim($de); $en=trim($en);
        if ($catDe===''||$catEn===''||$subDe===''||$subEn===''||$de===''||$en==='') continue;
        $pdo->prepare("INSERT INTO competency_categories(name_de,name_en,sort_order) VALUES (?,?,0)")->execute([$catDe,$catEn]);
        $catId = (int)$pdo->lastInsertId(); if ($catId===0) { $catId = (int)$pdo->query("SELECT id FROM competency_categories WHERE name_de=".$pdo->quote($catDe)." AND name_en=".$pdo->quote($catEn)." ORDER BY id DESC LIMIT 1")->fetchColumn(); }
        $pdo->prepare("INSERT INTO competency_subcategories(category_id,name_de,name_en,sort_order) VALUES (?,?,?,0)")->execute([$catId,$subDe,$subEn]);
        $subId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO competencies(subcategory_id,code,text_de,text_en,is_required,sort_order) VALUES (?,?,?,?,?,0)")->execute([$subId,trim((string)$code),$de,$en,(trim($required)==='1'||strtolower(trim($required))==='ja')?1:0]);
        $cid = (int)$pdo->lastInsertId();
        foreach (explode(',', (string)$grades) as $g) { $gi=(int)trim($g); if ($gi>=1 && $gi<=4) $pdo->prepare("INSERT IGNORE INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$cid,$gi]); }
      }
      fclose($fh);
    }

    if ($a === 'delete_competency') {
      $id=(int)$_POST['id'];
      $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competencies WHERE id=?")->execute([$id]);
    }

    if ($a === 'move_competency') {
      $id=(int)$_POST['id']; $to=(int)$_POST['to_sort'];
      $pdo->prepare("UPDATE competencies SET sort_order=? WHERE id=?")->execute([$to,$id]);
    }

    $ok = 'Gespeichert.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cats = $pdo->query("SELECT * FROM competency_categories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
$subs = $pdo->query("SELECT s.*,c.name_de AS cat_de FROM competency_subcategories s JOIN competency_categories c ON c.id=s.category_id ORDER BY c.sort_order,s.sort_order,s.id")->fetchAll(PDO::FETCH_ASSOC);
$comps = $pdo->query("SELECT c.*,s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en FROM competencies c JOIN competency_subcategories s ON s.id=c.subcategory_id JOIN competency_categories cat ON cat.id=s.category_id ORDER BY cat.sort_order,s.sort_order,c.sort_order,c.id")->fetchAll(PDO::FETCH_ASSOC);
$gradesByComp=[]; $gr=$pdo->query("SELECT competency_id, grade_level FROM competency_grade_levels ORDER BY grade_level")->fetchAll(PDO::FETCH_ASSOC);
foreach($gr as $r){$gradesByComp[(int)$r['competency_id']][]=(int)$r['grade_level'];}

render_admin_header('Kompetenzen verwalten'); ?>
<div class="card"><h1>Kompetenzen verwalten</h1><?php if($ok):?><div class="alert success"><?=h($ok)?></div><?php endif; ?><?php if($err):?><div class="alert danger"><?=h($err)?></div><?php endif; ?>
  <a class="btn" href="<?=h(url('admin/competencies.php?download_csv_template=1'))?>">CSV-Vorlage herunterladen</a>
</div>

<div class="card">
  <h3>Übersicht (bestehend)</h3>
  <table class="table" id="compTable"><tr><th>Kategorie</th><th>Unterkategorie</th><th>Code</th><th>DE</th><th>EN</th><th>Typ</th><th>Stufen</th><th>Aktion</th></tr>
  <?php foreach($comps as $c): $id=(int)$c['id']; ?>
    <tr draggable="true" data-id="<?=$id?>" data-sort="<?= (int)$c['sort_order'] ?>">
      <td><?=h((string)$c['cat_de'])?><br><small><?=h((string)$c['cat_en'])?></small></td>
      <td><?=h((string)$c['sub_de'])?><br><small><?=h((string)$c['sub_en'])?></small></td>
      <td><?=h((string)$c['code'])?></td><td><?=h((string)$c['text_de'])?></td><td><?=h((string)$c['text_en'])?></td>
      <td><?= ((int)$c['is_required']===1)?'verpflichtend':'optional' ?></td>
      <td><?=h(implode(',', $gradesByComp[$id] ?? []))?></td>
      <td><form method="post" onsubmit="return confirm('Löschen?')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_competency"><input type="hidden" name="id" value="<?=$id?>"><button class="btn">Löschen</button></form></td>
    </tr>
  <?php endforeach; ?>
  </table>
</div>

<details class="card"><summary><strong>Kategorie hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_category"><input class="input" name="name_de" placeholder="Deutsch" required><input class="input" name="name_en" placeholder="Englisch" required><input class="input" name="sort_order" type="number" value="0"><button class="btn">Speichern</button></form></details>
<details class="card"><summary><strong>Unterkategorie hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_subcategory"><select class="input" name="category_id"><?php foreach($cats as $c):?><option value="<?=$c['id']?>"><?=h($c['name_de'])?> / <?=h($c['name_en'])?></option><?php endforeach;?></select><input class="input" name="name_de" required><input class="input" name="name_en" required><input class="input" name="sort_order" type="number" value="0"><button class="btn">Speichern</button></form></details>
<details class="card"><summary><strong>Kompetenz hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_competency"><select class="input" name="subcategory_id"><?php foreach($subs as $s):?><option value="<?=$s['id']?>"><?=h($s['cat_de'])?> → <?=h($s['name_de'])?></option><?php endforeach;?></select><input class="input" name="code" placeholder="Code"><textarea class="input" name="text_de" required></textarea><textarea class="input" name="text_en" required></textarea><label><input type="checkbox" name="is_required" value="1"> verpflichtend</label><input class="input" name="sort_order" type="number" value="0"><div><?php foreach($gradeOptions as $g):?><label style="margin-right:8px;"><input type="checkbox" name="grades[]" value="<?=$g?>"> <?=$g?></label><?php endforeach;?></div><button class="btn">Speichern</button></form></details>

<script>
const table = document.getElementById('compTable');
let dragRow = null;
table.querySelectorAll('tr[draggable="true"]').forEach((row) => {
  row.addEventListener('dragstart', () => { dragRow = row; });
  row.addEventListener('dragover', (e) => { e.preventDefault(); });
  row.addEventListener('drop', async (e) => {
    e.preventDefault();
    if (!dragRow || dragRow === row) return;
    const id = dragRow.dataset.id;
    const toSort = row.dataset.sort || '0';
    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fd.append('action', 'move_competency');
    fd.append('id', id);
    fd.append('to_sort', toSort);
    await fetch('', { method:'POST', body: fd });
    window.location.reload();
  });
});
</script>
<?php render_admin_footer();
