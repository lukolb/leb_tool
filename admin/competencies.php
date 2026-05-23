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
  $st = $pdo->prepare("SELECT code FROM competencies WHERE category_id=? ORDER BY sort_order DESC, id DESC LIMIT 1");
  $st->execute([$categoryId]);
  $last = (string)($st->fetchColumn() ?: '');
  $n = 1;
  if (preg_match('/-(\d+)$/', $last, $m)) $n = ((int)$m[1]) + 1;
  return $prefix . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

function resequence_sort(PDO $pdo, string $table, string $whereSql = '1=1', array $params = []): void {
  $sql = "SELECT id FROM {$table} WHERE {$whereSql} ORDER BY sort_order ASC, id ASC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  $upd = $pdo->prepare("UPDATE {$table} SET sort_order=? WHERE id=?");
  foreach ($ids as $i => $id) $upd->execute([$i + 1, $id]);
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

    if ($a === 'move_item') {
      $type=(string)$_POST['item_type'];
      $id=(int)$_POST['id'];
      $beforeId=(int)($_POST['before_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Ungültige ID.');

      if ($type === 'category') {
        resequence_sort($pdo, 'competency_categories');
        $ids = array_map('intval', $pdo->query("SELECT id FROM competency_categories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_COLUMN) ?: []);
      } elseif ($type === 'subcategory') {
        $catId = (int)$pdo->query("SELECT category_id FROM competency_subcategories WHERE id=" . $id)->fetchColumn();
        resequence_sort($pdo, 'competency_subcategories', 'category_id=?', [$catId]);
        $st=$pdo->prepare("SELECT id FROM competency_subcategories WHERE category_id=? ORDER BY sort_order,id"); $st->execute([$catId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
      } else {
        $st = $pdo->prepare("SELECT COALESCE(category_id,0) AS category_id, COALESCE(subcategory_id,0) AS subcategory_id FROM competencies WHERE id=?");
        $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['category_id'=>0,'subcategory_id'=>0];
        $catId=(int)$row['category_id']; $subId=(int)$row['subcategory_id'];
        if ($subId > 0) {
          resequence_sort($pdo, 'competencies', 'subcategory_id=?', [$subId]);
          $st=$pdo->prepare("SELECT id FROM competencies WHERE subcategory_id=? ORDER BY sort_order,id"); $st->execute([$subId]);
        } else {
          resequence_sort($pdo, 'competencies', 'category_id=? AND (subcategory_id IS NULL OR subcategory_id=0)', [$catId]);
          $st=$pdo->prepare("SELECT id FROM competencies WHERE category_id=? AND (subcategory_id IS NULL OR subcategory_id=0) ORDER BY sort_order,id"); $st->execute([$catId]);
        }
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
      }

      $ids = array_values(array_filter($ids, static fn($v) => $v !== $id));
      if ($beforeId > 0) {
        $pos = array_search($beforeId, $ids, true);
        if ($pos === false) $ids[] = $id; else array_splice($ids, $pos, 0, [$id]);
      } else $ids[] = $id;

      if ($type === 'category') $upd = $pdo->prepare("UPDATE competency_categories SET sort_order=? WHERE id=?");
      elseif ($type === 'subcategory') $upd = $pdo->prepare("UPDATE competency_subcategories SET sort_order=? WHERE id=?");
      else $upd = $pdo->prepare("UPDATE competencies SET sort_order=? WHERE id=?");

      foreach ($ids as $i => $itemId) $upd->execute([$i + 1, $itemId]);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>true]); exit;
    }

    if ($a === 'add_category') {
      $pdo->prepare("INSERT INTO competency_categories(name_de,name_en,sort_order) VALUES (?,?,9999)")->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en'])]);
    } elseif ($a === 'update_category') {
      $pdo->prepare("UPDATE competency_categories SET name_de=?, name_en=? WHERE id=?")->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en']), (int)$_POST['id']]);
    } elseif ($a === 'delete_category') {
      $id=(int)$_POST['id'];
      $pdo->prepare("DELETE FROM competencies WHERE category_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competency_subcategories WHERE category_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competency_categories WHERE id=?")->execute([$id]);
    } elseif ($a === 'add_subcategory') {
      $pdo->prepare("INSERT INTO competency_subcategories(category_id,name_de,name_en,sort_order) VALUES (?,?,?,9999)")->execute([(int)$_POST['category_id'], trim((string)$_POST['name_de']), trim((string)$_POST['name_en'])]);
    } elseif ($a === 'update_subcategory') {
      $pdo->prepare("UPDATE competency_subcategories SET name_de=?, name_en=? WHERE id=?")->execute([trim((string)$_POST['name_de']), trim((string)$_POST['name_en']), (int)$_POST['id']]);
    } elseif ($a === 'delete_subcategory') {
      $id=(int)$_POST['id'];
      $pdo->prepare("UPDATE competencies SET subcategory_id=NULL WHERE subcategory_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM competency_subcategories WHERE id=?")->execute([$id]);
    } elseif ($a === 'add_competency') {
      $subcategoryId=(int)($_POST['subcategory_id']??0); $categoryId=(int)($_POST['category_id']??0);
      if ($subcategoryId>0) { $st=$pdo->prepare("SELECT category_id FROM competency_subcategories WHERE id=?"); $st->execute([$subcategoryId]); $categoryId=(int)($st->fetchColumn()?:0); }
      if($categoryId<=0) throw new RuntimeException('Kategorie fehlt.');
      $catName=(string)$pdo->query("SELECT name_de FROM competency_categories WHERE id=".(int)$categoryId)->fetchColumn();
      $code=trim((string)($_POST['code']??'')); if($code==='') $code=next_comp_code($pdo,$categoryId,$catName);
      $pdo->prepare("INSERT INTO competencies(category_id,subcategory_id,code,text_de,text_en,is_required,sort_order) VALUES (?,?,?,?,?,?,9999)")->execute([$categoryId,$subcategoryId>0?$subcategoryId:null,$code,trim((string)$_POST['text_de']),trim((string)$_POST['text_en']),isset($_POST['is_required'])?1:0]);
      $cid=(int)$pdo->lastInsertId(); foreach((array)($_POST['grades']??[]) as $g){$gi=(int)$g; if($gi>=1&&$gi<=4)$pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$cid,$gi]);}
    } elseif ($a === 'update_competency') {
      $id=(int)$_POST['id']; $code=trim((string)$_POST['code']); if($code==='') throw new RuntimeException('Code ist erforderlich.');
      $pdo->prepare("UPDATE competencies SET code=?, text_de=?, text_en=?, is_required=? WHERE id=?")->execute([$code,trim((string)$_POST['text_de']),trim((string)$_POST['text_en']),isset($_POST['is_required'])?1:0,$id]);
      $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]); foreach((array)($_POST['grades']??[]) as $g){$gi=(int)$g; if($gi>=1&&$gi<=4)$pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$id,$gi]);}
    } elseif ($a === 'delete_competency') {
      $id=(int)$_POST['id']; $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]); $pdo->prepare("DELETE FROM competencies WHERE id=?")->execute([$id]);
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
<div class="card"><h3>Übersicht (Drag & Drop + Inline bearbeiten)</h3>
<?php foreach($cats as $cat): $catId=(int)$cat['id']; ?>
  <details style="margin-bottom:10px;"><summary class="drag-item" draggable="true" data-item-type="category" data-id="<?=$catId?>"><strong><?=h($cat['name_de'])?></strong> <small><?=h($cat['name_en'])?></small></summary>
    <form method="post" class="row" style="margin:8px 0; gap:8px;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_category"><input type="hidden" name="id" value="<?=$catId?>"><input class="input" name="name_de" value="<?=h($cat['name_de'])?>"><input class="input" name="name_en" value="<?=h($cat['name_en'])?>"><button class="btn">Speichern</button><button class="btn" type="button" onclick="if(confirm('Kategorie löschen?')) this.form.querySelector('[name=action]').value='delete_category', this.form.submit();">Löschen</button></form>

    <details style="margin-left:18px;"><summary>Ohne Unterkategorie</summary>
    <?php foreach(($compsBySub[0]??[]) as $c): if((int)$c['category_id']!==$catId) continue; $id=(int)$c['id']; ?>
      <div class="drag-item" draggable="true" data-item-type="competency" data-id="<?=$id?>" style="border:1px solid #ddd;padding:8px;margin:8px 0;">
        <form method="post" class="row" style="gap:8px;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_competency"><input type="hidden" name="id" value="<?=$id?>"><input class="input" name="code" value="<?=h((string)$c['code'])?>" placeholder="Code"><input class="input" name="text_de" value="<?=h((string)$c['text_de'])?>" placeholder="Deutsch"><input class="input" name="text_en" value="<?=h((string)$c['text_en'])?>" placeholder="English"><label><input type="checkbox" name="is_required" value="1" <?=((int)$c['is_required']===1)?'checked':''?>> Pflicht</label><?php foreach($gradeOptions as $g):?><label><input type="checkbox" name="grades[]" value="<?=$g?>" <?=in_array($g,$gradesByComp[$id]??[],true)?'checked':''?>><?=$g?></label><?php endforeach;?><button class="btn">Speichern</button><button class="btn" type="button" onclick="if(confirm('Kompetenz löschen?')) this.form.querySelector('[name=action]').value='delete_competency', this.form.submit();">Löschen</button></form>
      </div>
    <?php endforeach; ?>
    </details>

    <?php foreach(($subsByCat[$catId]??[]) as $sub): $subId=(int)$sub['id']; ?>
      <details style="margin-left:18px;"><summary class="drag-item" draggable="true" data-item-type="subcategory" data-id="<?=$subId?>"><?=h($sub['name_de'])?> / <?=h($sub['name_en'])?></summary>
        <form method="post" class="row" style="margin:8px 0; gap:8px;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_subcategory"><input type="hidden" name="id" value="<?=$subId?>"><input class="input" name="name_de" value="<?=h($sub['name_de'])?>"><input class="input" name="name_en" value="<?=h($sub['name_en'])?>"><button class="btn">Speichern</button><button class="btn" type="button" onclick="if(confirm('Unterkategorie löschen?')) this.form.querySelector('[name=action]').value='delete_subcategory', this.form.submit();">Löschen</button></form>
        <?php foreach(($compsBySub[$subId]??[]) as $c): $id=(int)$c['id']; ?>
          <div class="drag-item" draggable="true" data-item-type="competency" data-id="<?=$id?>" style="border:1px solid #ddd;padding:8px;margin:8px 0;">
            <form method="post" class="row" style="gap:8px;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_competency"><input type="hidden" name="id" value="<?=$id?>"><input class="input" name="code" value="<?=h((string)$c['code'])?>" placeholder="Code"><input class="input" name="text_de" value="<?=h((string)$c['text_de'])?>" placeholder="Deutsch"><input class="input" name="text_en" value="<?=h((string)$c['text_en'])?>" placeholder="English"><label><input type="checkbox" name="is_required" value="1" <?=((int)$c['is_required']===1)?'checked':''?>> Pflicht</label><?php foreach($gradeOptions as $g):?><label><input type="checkbox" name="grades[]" value="<?=$g?>" <?=in_array($g,$gradesByComp[$id]??[],true)?'checked':''?>><?=$g?></label><?php endforeach;?><button class="btn">Speichern</button><button class="btn" type="button" onclick="if(confirm('Kompetenz löschen?')) this.form.querySelector('[name=action]').value='delete_competency', this.form.submit();">Löschen</button></form>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </details>
<?php endforeach; ?>
</div>

<details class="card"><summary><strong>Kategorie hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_category"><label>Kategoriename (Deutsch)</label><input class="input" name="name_de" required><label>Category name (English)</label><input class="input" name="name_en" required><button class="btn">Speichern</button></form></details>
<details class="card"><summary><strong>Unterkategorie hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_subcategory"><label>Kategorie</label><select class="input" name="category_id"><?php foreach($cats as $c):?><option value="<?=$c['id']?>"><?=h($c['name_de'])?> / <?=h($c['name_en'])?></option><?php endforeach;?></select><label>Unterkategorie (Deutsch)</label><input class="input" name="name_de" required><label>Subcategory (English)</label><input class="input" name="name_en" required><button class="btn">Speichern</button></form></details>
<details class="card"><summary><strong>Kompetenz hinzufügen</strong></summary><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_competency"><label>Kategorie</label><select class="input" name="category_id"><?php foreach($cats as $c):?><option value="<?=$c['id']?>"><?=h($c['name_de'])?> / <?=h($c['name_en'])?></option><?php endforeach;?></select><label>Unterkategorie (optional)</label><select class="input" name="subcategory_id"><option value="0">Ohne Unterkategorie</option><?php foreach($subs as $s):?><option value="<?=$s['id']?>"><?=h($s['name_de'])?> / <?=h($s['name_en'])?></option><?php endforeach;?></select><label>Eindeutiger Code (optional, Auto bei leer)</label><input class="input" name="code"><label>Kompetenztext (Deutsch)</label><input class="input" name="text_de" required><label>Competency text (English)</label><input class="input" name="text_en" required><label><input type="checkbox" name="is_required" value="1"> verpflichtend</label><div><?php foreach($gradeOptions as $g):?><label style="margin-right:8px;"><input type="checkbox" name="grades[]" value="<?=$g?>"> <?=$g?></label><?php endforeach;?></div><button class="btn">Speichern</button></form></details>

<script>
let dragNode = null;
for (const n of document.querySelectorAll('.drag-item[draggable="true"]')) {
  n.addEventListener('dragstart', () => { dragNode = n; n.style.opacity = '0.5'; });
  n.addEventListener('dragend', () => { n.style.opacity = '1'; });
  n.addEventListener('dragover', (e) => { e.preventDefault(); });
  n.addEventListener('drop', async (e) => {
    e.preventDefault();
    if (!dragNode || dragNode === n) return;
    if ((dragNode.dataset.itemType || '') !== (n.dataset.itemType || '')) return;
    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fd.append('action', 'move_item');
    fd.append('item_type', dragNode.dataset.itemType || 'competency');
    fd.append('id', dragNode.dataset.id || '0');
    fd.append('before_id', n.dataset.id || '0');
    const res = await fetch('', { method:'POST', body: fd });
    if (res.ok) window.location.reload();
  });
}
</script>
<?php render_admin_footer();
