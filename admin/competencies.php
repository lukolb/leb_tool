<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();
$pdo = db();
$ok = null; $err = null;
$gradeOptions = [1,2,3,4];

function next_comp_code(PDO $pdo, int $categoryId, string $catDe): string {
  $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($catDe, 0, 4)) ?: ('CAT' . $categoryId));
  $st = $pdo->prepare("SELECT code FROM competencies WHERE category_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$categoryId]);
  $last = (string)($st->fetchColumn() ?: '');
  $n = 1;
  if (preg_match('/-(\d+)$/', $last, $m)) $n = ((int)$m[1]) + 1;
  return $prefix . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

if (isset($_GET['download_csv_template'])) {
  $csv = "category_de;category_en;subcategory_de;subcategory_en;code;text_de;text_en;required;grades\n";
  $csv .= "Sozialkompetenz;Social skills;Kommunikation;Communication;SOZI-001;Hört anderen aufmerksam zu.;Listens attentively to others.;1;1,2\n";
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="kompetenzen_vorlage.csv"');
  echo $csv; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $a = (string)($_POST['action'] ?? '');

    if ($a === 'add_category') {
      $pdo->prepare("INSERT INTO competency_categories(name_de,name_en,sort_order) VALUES (?,?,0)")->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en'])]);
    } elseif ($a === 'update_category') {
      $pdo->prepare("UPDATE competency_categories SET name_de=?, name_en=? WHERE id=?")->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en']), (int)$_POST['id']]);
    } elseif ($a === 'delete_category') {
      $id=(int)$_POST['id'];
      $pdo->prepare("DELETE FROM competencies WHERE category_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competency_subcategories WHERE category_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competency_categories WHERE id=?")->execute([$id]);
    } elseif ($a === 'add_subcategory') {
      $pdo->prepare("INSERT INTO competency_subcategories(category_id,name_de,name_en,sort_order) VALUES (?,?,?,0)")->execute([(int)$_POST['category_id'], trim((string)$_POST['name_de']), trim((string)$_POST['name_en'])]);
    } elseif ($a === 'update_subcategory') {
      $pdo->prepare("UPDATE competency_subcategories SET name_de=?, name_en=? WHERE id=?")->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en']), (int)$_POST['id']]);
    } elseif ($a === 'delete_subcategory') {
      $id=(int)$_POST['id'];
      $pdo->prepare("UPDATE competencies SET subcategory_id=NULL WHERE subcategory_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competency_subcategories WHERE id=?")->execute([$id]);
    } elseif ($a === 'add_competency') {
      $subcategoryId=(int)($_POST['subcategory_id']??0);
      $categoryId=(int)($_POST['category_id']??0);
      if ($subcategoryId>0) { $st=$pdo->prepare("SELECT category_id FROM competency_subcategories WHERE id=?"); $st->execute([$subcategoryId]); $categoryId=(int)($st->fetchColumn()?:0); }
      if($categoryId<=0) throw new RuntimeException('Kategorie fehlt.');
      $catName=(string)$pdo->query("SELECT name_de FROM competency_categories WHERE id=".(int)$categoryId)->fetchColumn();
      $code=trim((string)($_POST['code']??'')); if($code==='') $code=next_comp_code($pdo,$categoryId,$catName);
      $pdo->prepare("INSERT INTO competencies(category_id,subcategory_id,code,text_de,text_en,is_required,sort_order) VALUES (?,?,?,?,?,?,0)")->execute([$categoryId,$subcategoryId>0?$subcategoryId:null,$code,trim((string)$_POST['text_de']),trim((string)$_POST['text_en']),isset($_POST['is_required'])?1:0]);
      $cid=(int)$pdo->lastInsertId(); foreach((array)($_POST['grades']??[]) as $g){$gi=(int)$g; if($gi>=1&&$gi<=4)$pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$cid,$gi]);}
    } elseif ($a === 'update_competency') {
      $id=(int)$_POST['id']; $code=trim((string)$_POST['code']); if($code==='') throw new RuntimeException('Code ist erforderlich.');
      $pdo->prepare("UPDATE competencies SET code=?, text_de=?, text_en=?, is_required=? WHERE id=?")->execute([$code,trim((string)$_POST['text_de']),trim((string)$_POST['text_en']),isset($_POST['is_required'])?1:0,$id]);
      $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]); foreach((array)($_POST['grades']??[]) as $g){$gi=(int)$g; if($gi>=1&&$gi<=4)$pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$id,$gi]);}
    } elseif ($a === 'delete_competency') {
      $id=(int)$_POST['id']; $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]); $pdo->prepare("DELETE FROM competencies WHERE id=?")->execute([$id]);
    } elseif ($a === 'move_item') {
      $type=(string)$_POST['item_type']; $id=(int)$_POST['id']; $to=(int)$_POST['to_sort'];
      if ($type==='category') $pdo->prepare("UPDATE competency_categories SET sort_order=? WHERE id=?")->execute([$to,$id]);
      if ($type==='subcategory') $pdo->prepare("UPDATE competency_subcategories SET sort_order=? WHERE id=?")->execute([$to,$id]);
      if ($type==='competency') $pdo->prepare("UPDATE competencies SET sort_order=? WHERE id=?")->execute([$to,$id]);
      echo 'ok'; exit;
    }
    $ok='Gespeichert.';
  } catch(Throwable $e){$err=$e->getMessage();}
}

$cats=$pdo->query("SELECT * FROM competency_categories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
$subs=$pdo->query("SELECT * FROM competency_subcategories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
$comps=$pdo->query("SELECT c.*, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en FROM competencies c LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id LEFT JOIN competency_categories cat ON cat.id=c.category_id ORDER BY cat.sort_order,s.sort_order,c.sort_order,c.id")->fetchAll(PDO::FETCH_ASSOC);
$gradesByComp=[]; foreach(($pdo->query("SELECT competency_id,grade_level FROM competency_grade_levels")->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){$gradesByComp[(int)$r['competency_id']][]=(int)$r['grade_level'];}
$subsByCat=[]; foreach($subs as $s){$subsByCat[(int)$s['category_id']][]=$s;}
$compsBySub=[]; foreach($comps as $c){$k=(int)($c['subcategory_id']??0); $compsBySub[$k][]=$c;}

render_admin_header('Kompetenzen verwalten'); ?>
<div class="card"><h1>Kompetenzen verwalten</h1><?php if($ok):?><div class="alert success"><?=h($ok)?></div><?php endif;?><?php if($err):?><div class="alert danger"><?=h($err)?></div><?php endif;?>
  <a class="btn" href="<?=h(url('admin/competencies.php?download_csv_template=1'))?>">CSV-Vorlage herunterladen</a>
</div>
<div class="card"><h3>Übersicht (Baumstruktur)</h3>
<?php foreach($cats as $cat): $catId=(int)$cat['id']; ?>
  <details style="margin-bottom:10px;"><summary><strong draggable="true" data-item-type="category" data-id="<?=$catId?>" data-sort="<?= (int)$cat['sort_order'] ?>"><?=h($cat['name_de'])?></strong> <small><?=h($cat['name_en'])?></small></summary>
    <form method="post" class="row" style="margin:8px 0;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_category"><input type="hidden" name="id" value="<?=$catId?>"><input class="input" name="name_de" value="<?=h($cat['name_de'])?>" placeholder="Kategoriename (Deutsch)"><input class="input" name="name_en" value="<?=h($cat['name_en'])?>" placeholder="Category name (English)"><button class="btn">Speichern</button></form>
    <form method="post" onsubmit="return confirm('Kategorie löschen?')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?=$catId?>"><button class="btn">Kategorie löschen</button></form>
    <details style="margin-left:18px;"><summary><em>Ohne Unterkategorie</em></summary><?php foreach(($compsBySub[0]??[]) as $c): if((int)$c['category_id']!==$catId) continue; $id=(int)$c['id']; ?><div draggable="true" data-item-type="competency" data-id="<?=$id?>" data-sort="<?= (int)$c['sort_order'] ?>" style="border:1px solid #ddd;padding:8px;margin:8px 0;"><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_competency"><input type="hidden" name="id" value="<?=$id?>"><input class="input" name="code" value="<?=h((string)$c['code'])?>" placeholder="Eindeutiger Code"><input class="input" name="text_de" value="<?=h((string)$c['text_de'])?>" placeholder="Kompetenztext (Deutsch)"><input class="input" name="text_en" value="<?=h((string)$c['text_en'])?>" placeholder="Competency text (English)"><label><input type="checkbox" name="is_required" value="1" <?=((int)$c['is_required']===1)?'checked':''?>> verpflichtend</label><div><?php foreach($gradeOptions as $g):?><label><input type="checkbox" name="grades[]" value="<?=$g?>" <?=in_array($g,$gradesByComp[$id]??[],true)?'checked':''?>><?=$g?></label><?php endforeach;?></div><button class="btn">Speichern</button></form><form method="post" onsubmit="return confirm('Kompetenz löschen?')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_competency"><input type="hidden" name="id" value="<?=$id?>"><button class="btn">Löschen</button></form></div><?php endforeach; ?></details>
    <?php foreach(($subsByCat[$catId]??[]) as $sub): $subId=(int)$sub['id']; ?>
      <details style="margin-left:18px;"><summary><span draggable="true" data-item-type="subcategory" data-id="<?=$subId?>" data-sort="<?= (int)$sub['sort_order'] ?>"><?=h($sub['name_de'])?> / <?=h($sub['name_en'])?></span></summary>
        <form method="post" class="row" style="margin:8px 0;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_subcategory"><input type="hidden" name="id" value="<?=$subId?>"><input class="input" name="name_de" value="<?=h($sub['name_de'])?>" placeholder="Unterkategorie (Deutsch)"><input class="input" name="name_en" value="<?=h($sub['name_en'])?>" placeholder="Subcategory (English)"><button class="btn">Speichern</button></form>
        <form method="post" onsubmit="return confirm('Unterkategorie löschen?')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_subcategory"><input type="hidden" name="id" value="<?=$subId?>"><button class="btn">Unterkategorie löschen</button></form>
        <?php foreach(($compsBySub[$subId]??[]) as $c): $id=(int)$c['id']; ?><div draggable="true" data-item-type="competency" data-id="<?=$id?>" data-sort="<?= (int)$c['sort_order'] ?>" style="border:1px solid #ddd;padding:8px;margin:8px 0;"><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_competency"><input type="hidden" name="id" value="<?=$id?>"><input class="input" name="code" value="<?=h((string)$c['code'])?>" placeholder="Eindeutiger Code"><input class="input" name="text_de" value="<?=h((string)$c['text_de'])?>" placeholder="Kompetenztext (Deutsch)"><input class="input" name="text_en" value="<?=h((string)$c['text_en'])?>" placeholder="Competency text (English)"><label><input type="checkbox" name="is_required" value="1" <?=((int)$c['is_required']===1)?'checked':''?>> verpflichtend</label><div><?php foreach($gradeOptions as $g):?><label><input type="checkbox" name="grades[]" value="<?=$g?>" <?=in_array($g,$gradesByComp[$id]??[],true)?'checked':''?>><?=$g?></label><?php endforeach;?></div><button class="btn">Speichern</button></form><form method="post" onsubmit="return confirm('Kompetenz löschen?')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_competency"><input type="hidden" name="id" value="<?=$id?>"><button class="btn">Löschen</button></form></div><?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </details>
<?php endforeach; ?>
</div>

<details class="card"><summary><strong>Kategorie hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_category"><label>Kategoriename (Deutsch)</label><input class="input" name="name_de" required><label>Category name (English)</label><input class="input" name="name_en" required><button class="btn">Speichern</button></form></details>
<details class="card"><summary><strong>Unterkategorie hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_subcategory"><label>Kategorie</label><select class="input" name="category_id"><?php foreach($cats as $c):?><option value="<?=$c['id']?>"><?=h($c['name_de'])?> / <?=h($c['name_en'])?></option><?php endforeach;?></select><label>Unterkategorie (Deutsch)</label><input class="input" name="name_de" required><label>Subcategory (English)</label><input class="input" name="name_en" required><button class="btn">Speichern</button></form></details>
<details class="card"><summary><strong>Kompetenz hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_competency"><label>Kategorie</label><select class="input" name="category_id"><?php foreach($cats as $c):?><option value="<?=$c['id']?>"><?=h($c['name_de'])?> / <?=h($c['name_en'])?></option><?php endforeach;?></select><label>Unterkategorie (optional)</label><select class="input" name="subcategory_id"><option value="0">Ohne Unterkategorie</option><?php foreach($subs as $s):?><option value="<?=$s['id']?>"><?=h($s['name_de'])?> / <?=h($s['name_en'])?></option><?php endforeach;?></select><label>Eindeutiger Code (optional, wird sonst automatisch erzeugt)</label><input class="input" name="code"><label>Kompetenztext (Deutsch)</label><textarea class="input" name="text_de" required></textarea><label>Competency text (English)</label><textarea class="input" name="text_en" required></textarea><label><input type="checkbox" name="is_required" value="1"> verpflichtend</label><div><?php foreach($gradeOptions as $g):?><label style="margin-right:8px;"><input type="checkbox" name="grades[]" value="<?=$g?>"> <?=$g?></label><?php endforeach;?></div><button class="btn">Speichern</button></form></details>

<script>
let dragNode = null;
document.querySelectorAll('[draggable="true"]').forEach((n) => {
  n.addEventListener('dragstart', () => { dragNode = n; });
  n.addEventListener('dragover', (e) => e.preventDefault());
  n.addEventListener('drop', async (e) => {
    e.preventDefault();
    if (!dragNode || dragNode === n) return;
    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fd.append('action', 'move_item');
    fd.append('item_type', dragNode.dataset.itemType || 'competency');
    fd.append('id', dragNode.dataset.id || '0');
    fd.append('to_sort', n.dataset.sort || '0');
    await fetch('', { method:'POST', body: fd });
    window.location.reload();
  });
});
</script>
<?php render_admin_footer();
