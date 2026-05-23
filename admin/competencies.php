<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();
$pdo = db();
$gradeOptions = [1,2,3,4];

function json_out(array $d, int $code=200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function bad(string $m, int $c=400): never { json_out(['ok'=>false,'error'=>$m],$c); }
function next_comp_code(PDO $pdo, int $categoryId, string $catDe): string { $prefix=strtoupper(preg_replace('/[^A-Za-z0-9]/','',substr($catDe,0,4))?:('CAT'.$categoryId)); $st=$pdo->prepare("SELECT code FROM competencies WHERE category_id=? ORDER BY sort_order DESC,id DESC LIMIT 1"); $st->execute([$categoryId]); $last=(string)($st->fetchColumn()?:''); $n=1; if(preg_match('/-(\d+)$/',$last,$m)) $n=((int)$m[1])+1; return $prefix.'-'.str_pad((string)$n,3,'0',STR_PAD_LEFT);} 

function max_sort(PDO $pdo, string $table, string $where='1=1', array $params=[]): int { $st=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM {$table} WHERE {$where}"); $st->execute($params); return (int)$st->fetchColumn(); }
function normalize_order(PDO $pdo, string $table, string $where='1=1', array $params=[]): void { $st=$pdo->prepare("SELECT id FROM {$table} WHERE {$where} ORDER BY sort_order,id"); $st->execute($params); $ids=$st->fetchAll(PDO::FETCH_COLUMN)?:[]; $u=$pdo->prepare("UPDATE {$table} SET sort_order=? WHERE id=?"); foreach($ids as $i=>$id){$u->execute([$i+1,(int)$id]);}}

function fetch_tree(PDO $pdo): array {
  $cats=$pdo->query("SELECT id,name_de,name_en,sort_order FROM competency_categories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC)?:[];
  $subs=$pdo->query("SELECT id,category_id,name_de,name_en,sort_order FROM competency_subcategories ORDER BY category_id,sort_order,id")->fetchAll(PDO::FETCH_ASSOC)?:[];
  $comps=$pdo->query("SELECT id,category_id,subcategory_id,code,text_de,text_en,is_required,sort_order FROM competencies ORDER BY category_id, COALESCE(subcategory_id,0), sort_order,id")->fetchAll(PDO::FETCH_ASSOC)?:[];
  $grades=[]; foreach(($pdo->query("SELECT competency_id,grade_level FROM competency_grade_levels ORDER BY grade_level")->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){$grades[(int)$r['competency_id']][]=(int)$r['grade_level'];}
  $subsByCat=[]; foreach($subs as $s){$subsByCat[(int)$s['category_id']][]=$s;}
  $compsBySub=[]; $compsNoSubByCat=[];
  foreach($comps as $c){
    $subId=(int)($c['subcategory_id'] ?? 0);
    if($subId>0){ $compsBySub[$subId][]=$c; }
    else { $compsNoSubByCat[(int)($c['category_id']??0)][]=$c; }
  }
  $tree=[];
  foreach($cats as $c){
    $catId=(int)$c['id'];
    $cn=[];
    if(!empty($compsNoSubByCat[$catId])){
      $items=[];
      foreach(($compsNoSubByCat[$catId]??[]) as $it){
        $cid=(int)$it['id'];
        $items[]=['id'=>$cid,'type'=>'competency','title'=>(string)$it['code'].' — '.(string)$it['text_de'],'code'=>(string)$it['code'],'text_de'=>(string)$it['text_de'],'text_en'=>(string)$it['text_en'],'is_required'=>(int)$it['is_required'],'grades'=>$grades[$cid]??[],'category_id'=>(int)$it['category_id'],'sort_order'=>(int)$it['sort_order']];
      }
      $cn[]=['id'=>'virtual-no-subcategory-'.$catId,'is_virtual'=>true,'category_id'=>$catId,'type'=>'subcategory','title'=>'Ohne Unterkategorie','name_de'=>'Ohne Unterkategorie','name_en'=>'Without subcategory','sort_order'=>0,'children'=>$items];
    }
    foreach(($subsByCat[$catId]??[]) as $s){
      $subId=(int)$s['id'];
      $items=[];
      foreach(($compsBySub[$subId]??[]) as $it){
        $cid=(int)$it['id'];
        $items[]=['id'=>$cid,'type'=>'competency','title'=>(string)$it['code'].' — '.(string)$it['text_de'],'code'=>(string)$it['code'],'text_de'=>(string)$it['text_de'],'text_en'=>(string)$it['text_en'],'is_required'=>(int)$it['is_required'],'grades'=>$grades[$cid]??[],'category_id'=>(int)$it['category_id'],'sort_order'=>(int)$it['sort_order']];
      }
      $cn[]=['id'=>$subId,'type'=>'subcategory','title'=>(string)$s['name_de'],'name_de'=>(string)$s['name_de'],'name_en'=>(string)$s['name_en'],'sort_order'=>(int)$s['sort_order'],'children'=>$items];
    }
    $tree[]=['id'=>$catId,'type'=>'category','title'=>(string)$c['name_de'],'name_de'=>(string)$c['name_de'],'name_en'=>(string)$c['name_en'],'sort_order'=>(int)$c['sort_order'],'children'=>$cn];
  }
  return $tree;
}

function delete_preview(PDO $pdo, string $type, int $id): array {
  if($type==='category'){
    $st=$pdo->prepare("SELECT COUNT(*) FROM competency_subcategories WHERE category_id=?");$st->execute([$id]);$subs=(int)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COUNT(*) FROM competencies c INNER JOIN competency_subcategories s ON s.id=c.subcategory_id WHERE s.category_id=?");$st->execute([$id]);$comps=(int)$st->fetchColumn();
    return ['subcategories'=>$subs,'competencies'=>$comps];
  }
  if($type==='subcategory'){
    $st=$pdo->prepare("SELECT COUNT(*) FROM competencies WHERE subcategory_id=?");$st->execute([$id]);
    return ['subcategories'=>0,'competencies'=>(int)$st->fetchColumn()];
  }
  return ['subcategories'=>0,'competencies'=>1];
}

if(isset($_GET['download_csv_template'])){ $csv="category_de;category_en;subcategory_de;subcategory_en;code;text_de;text_en;required;grades\n"; $csv.="Sozialkompetenz;Social skills;Kommunikation;Communication;SOZI-001;Hört anderen aufmerksam zu.;Listens attentively to others.;1;1,2\n"; header('Content-Type:text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="kompetenzen_vorlage.csv"'); echo $csv; exit; }


if($_SERVER['REQUEST_METHOD']==='POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH']) && (string)($_POST['action']??'')==='import_csv'){
  try{ csrf_verify();
    if(!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) throw new RuntimeException('CSV-Datei fehlt.');
    $fh=fopen($_FILES['csv']['tmp_name'],'r'); if(!$fh) throw new RuntimeException('CSV konnte nicht gelesen werden.');
    $line=0; while(($row=fgetcsv($fh,0,';'))!==false){ $line++; if($line===1) continue; if(count($row)<9) continue;
      [$catDe,$catEn,$subDe,$subEn,$code,$textDe,$textEn,$required,$grades]=$row;
      $catDe=trim((string)$catDe); $catEn=trim((string)$catEn); $subDe=trim((string)$subDe); $subEn=trim((string)$subEn); $code=trim((string)$code); $textDe=trim((string)$textDe); $textEn=trim((string)$textEn);
      if($catDe===''||$catEn===''||$textDe==='') continue;
      $st=$pdo->prepare("SELECT id FROM competency_categories WHERE LOWER(name_de)=LOWER(?) AND LOWER(name_en)=LOWER(?) LIMIT 1"); $st->execute([$catDe,$catEn]); $catId=(int)($st->fetchColumn()?:0);
      if($catId<=0){ $pdo->prepare("INSERT INTO competency_categories(name_de,name_en,sort_order) VALUES (?,?,?)")->execute([$catDe,$catEn,max_sort($pdo,'competency_categories')+1]); $catId=(int)$pdo->lastInsertId(); }
      $subId=0; if($subDe!==''||$subEn!==''){ $st=$pdo->prepare("SELECT id FROM competency_subcategories WHERE category_id=? AND LOWER(name_de)=LOWER(?) AND LOWER(name_en)=LOWER(?) LIMIT 1"); $st->execute([$catId,$subDe,$subEn]); $subId=(int)($st->fetchColumn()?:0); if($subId<=0){ $pdo->prepare("INSERT INTO competency_subcategories(category_id,name_de,name_en,sort_order) VALUES (?,?,?,?)")->execute([$catId,$subDe,$subEn,max_sort($pdo,'competency_subcategories','category_id=?',[$catId])+1]); $subId=(int)$pdo->lastInsertId(); } }
      if($code==='') $code=next_comp_code($pdo,$catId,$catDe);
      $st=$pdo->prepare("SELECT id FROM competencies WHERE code=? LIMIT 1"); $st->execute([$code]); $compId=(int)($st->fetchColumn()?:0);
      $isReq=(trim(strtolower((string)$required))==='1'||trim(strtolower((string)$required))==='ja')?1:0;
      if($compId>0){ $pdo->prepare("UPDATE competencies SET category_id=?,subcategory_id=?,text_de=?,text_en=?,is_required=? WHERE id=?")->execute([$catId,$subId>0?$subId:null,$textDe,$textEn,$isReq,$compId]); }
      else { $so=$subId>0?max_sort($pdo,'competencies','subcategory_id=?',[$subId])+1:max_sort($pdo,'competencies','category_id=? AND subcategory_id IS NULL',[$catId])+1; $pdo->prepare("INSERT INTO competencies(category_id,subcategory_id,code,text_de,text_en,is_required,sort_order) VALUES (?,?,?,?,?,?,?)")->execute([$catId,$subId>0?$subId:null,$code,$textDe,$textEn,$isReq,$so]); $compId=(int)$pdo->lastInsertId(); }
      $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$compId]);
      foreach(explode(',',(string)$grades) as $g){ $gi=(int)trim($g); if($gi>=1&&$gi<=4){ $pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$compId,$gi]); } }
    }
    fclose($fh);
    header('Location: '.url('admin/competencies.php')); exit;
  } catch(Throwable $e){ header('Location: '.url('admin/competencies.php')); exit; }
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
  try{
    csrf_verify();
    $a=(string)($_POST['action']??'');
    if($a==='list_tree') json_out(['ok'=>true,'tree'=>fetch_tree($pdo)]);

    if($a==='create'){
      $type=(string)($_POST['type']??'');
      if($type==='category'){
        $de=trim((string)($_POST['name_de']??'')); $en=trim((string)($_POST['name_en']??'')); if($de===''||$en==='') bad('Name DE/EN erforderlich');
        $so=max_sort($pdo,'competency_categories')+1; $pdo->prepare("INSERT INTO competency_categories(name_de,name_en,sort_order) VALUES (?,?,?)")->execute([$de,$en,$so]);
      } elseif($type==='subcategory'){
        $cat=(int)($_POST['parent_id']??0); if($cat<=0) bad('Kategorie erforderlich');
        $de=trim((string)($_POST['name_de']??'')); $en=trim((string)($_POST['name_en']??'')); if($de===''||$en==='') bad('Name DE/EN erforderlich');
        $so=max_sort($pdo,'competency_subcategories','category_id=?',[$cat])+1; $pdo->prepare("INSERT INTO competency_subcategories(category_id,name_de,name_en,sort_order) VALUES (?,?,?,?)")->execute([$cat,$de,$en,$so]);
      } elseif($type==='competency'){
        $subRaw=(string)($_POST['parent_id']??''); $sub=(int)$subRaw; $cat=(int)($_POST['target_category_id']??0); $catName='';
        if($sub>0){ $st=$pdo->prepare("SELECT category_id,name_de FROM competency_subcategories WHERE id=?");$st->execute([$sub]);$sr=$st->fetch(PDO::FETCH_ASSOC); if(!$sr) bad('Unterkategorie nicht gefunden'); $cat=(int)$sr['category_id']; $catName=(string)$sr['name_de']; }
        else { if($cat<=0) bad('Kategorie erforderlich'); $st=$pdo->prepare("SELECT name_de FROM competency_categories WHERE id=?");$st->execute([$cat]); $catName=(string)($st->fetchColumn()?:'CAT'); }
        $de=trim((string)($_POST['text_de']??'')); $en=trim((string)($_POST['text_en']??'')); if($de===''||$en==='') bad('Kompetenztext DE/EN erforderlich');
        $code=trim((string)($_POST['code']??'')); if($code==='') $code=next_comp_code($pdo,$cat,(string)$sr['name_de']);
        $so=max_sort($pdo,'competencies','subcategory_id=?',[$sub])+1;
        $pdo->prepare("INSERT INTO competencies(category_id,subcategory_id,code,text_de,text_en,is_required,sort_order) VALUES (?,?,?,?,?,?,?)")->execute([$cat,$sub,$code,$de,$en,isset($_POST['is_required'])?1:0,$so]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]);
        foreach((array)($_POST['grades']??[]) as $g){$gi=(int)$g;if($gi>=1&&$gi<=4)$pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$id,$gi]);}
      } else bad('Ungültiger Typ');
      json_out(['ok'=>true,'tree'=>fetch_tree($pdo)]);
    }

    if($a==='update'){
      $type=(string)($_POST['type']??''); $id=(int)($_POST['id']??0); if($id<=0) bad('Ungültige ID');
      if($type==='category'){ $de=trim((string)($_POST['name_de']??'')); $en=trim((string)($_POST['name_en']??'')); if($de===''||$en==='') bad('Name DE/EN erforderlich'); $pdo->prepare("UPDATE competency_categories SET name_de=?,name_en=? WHERE id=?")->execute([$de,$en,$id]); }
      elseif($type==='subcategory'){ $de=trim((string)($_POST['name_de']??'')); $en=trim((string)($_POST['name_en']??'')); if($de===''||$en==='') bad('Name DE/EN erforderlich'); $pdo->prepare("UPDATE competency_subcategories SET name_de=?,name_en=? WHERE id=?")->execute([$de,$en,$id]); }
      elseif($type==='competency'){ $code=trim((string)($_POST['code']??'')); $de=trim((string)($_POST['text_de']??'')); $en=trim((string)($_POST['text_en']??'')); if($code===''||$de===''||$en==='') bad('Code und Texte erforderlich'); $pdo->prepare("UPDATE competencies SET code=?,text_de=?,text_en=?,is_required=? WHERE id=?")->execute([$code,$de,$en,isset($_POST['is_required'])?1:0,$id]); $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]); foreach((array)($_POST['grades']??[]) as $g){$gi=(int)$g;if($gi>=1&&$gi<=4)$pdo->prepare("INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (?,?)")->execute([$id,$gi]);} }
      else bad('Ungültiger Typ');
      json_out(['ok'=>true,'tree'=>fetch_tree($pdo)]);
    }

    if($a==='delete_preview'){
      $type=(string)($_POST['type']??''); $id=(int)($_POST['id']??0); if($id<=0) bad('Ungültige ID');
      json_out(['ok'=>true,'counts'=>delete_preview($pdo,$type,$id)]);
    }

    if($a==='delete'){
      $type=(string)($_POST['type']??''); $id=(int)($_POST['id']??0); if($id<=0) bad('Ungültige ID');
      $pdo->beginTransaction();
      if($type==='category'){
        $subIds=[]; $st=$pdo->prepare("SELECT id FROM competency_subcategories WHERE category_id=?");$st->execute([$id]);$subIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if($subIds){ $in=implode(',',array_fill(0,count($subIds),'?')); $st=$pdo->prepare("SELECT id FROM competencies WHERE subcategory_id IN ($in)"); $st->execute($subIds); $compIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]); if($compIds){$in2=implode(',',array_fill(0,count($compIds),'?')); $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id IN ($in2)")->execute($compIds); $pdo->prepare("DELETE FROM competencies WHERE id IN ($in2)")->execute($compIds);} $pdo->prepare("DELETE FROM competency_subcategories WHERE id IN ($in)")->execute($subIds);} 
        $pdo->prepare("DELETE FROM competency_categories WHERE id=?")->execute([$id]);
      } elseif($type==='subcategory'){
        $st=$pdo->prepare("SELECT id FROM competencies WHERE subcategory_id=?");$st->execute([$id]);$compIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if($compIds){$in=implode(',',array_fill(0,count($compIds),'?')); $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id IN ($in)")->execute($compIds); $pdo->prepare("DELETE FROM competencies WHERE id IN ($in)")->execute($compIds);} 
        $pdo->prepare("DELETE FROM competency_subcategories WHERE id=?")->execute([$id]);
      } elseif($type==='competency'){
        $pdo->prepare("DELETE FROM competency_grade_levels WHERE competency_id=?")->execute([$id]); $pdo->prepare("DELETE FROM competencies WHERE id=?")->execute([$id]);
      } else bad('Ungültiger Typ');
      $pdo->commit();
      json_out(['ok'=>true,'tree'=>fetch_tree($pdo)]);
    }

    if($a==='reorder'){
      $type=(string)($_POST['type']??''); $id=(int)($_POST['id']??0); $newParent=(int)($_POST['new_parent_id']??0);
      $ordered=json_decode((string)($_POST['ordered_ids']??'[]'), true); if(!is_array($ordered)) bad('ordered_ids ungültig'); $ordered=array_values(array_map('intval',$ordered));
      if(!in_array($id,$ordered,true)) bad('ID nicht in ordered_ids');
      if($type==='category'){
        $st=$pdo->query("SELECT id FROM competency_categories"); $valid=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
        foreach($ordered as $x){ if(!in_array((int)$x,$valid,true)) bad('Ungültige Kategorie-ID in ordered_ids'); }
        foreach($ordered as $pos=>$x){$pdo->prepare("UPDATE competency_categories SET sort_order=? WHERE id=?")->execute([$pos+1,$x]);}
      } elseif($type==='subcategory'){
        if($newParent<=0) bad('Kategorie als Parent erforderlich');
        $st=$pdo->prepare("SELECT category_id FROM competency_subcategories WHERE id=?"); $st->execute([$id]); $oldParent=(int)($st->fetchColumn()?:0);
        $st=$pdo->query("SELECT id FROM competency_subcategories"); $validSub=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
        foreach($ordered as $x){ if(!in_array((int)$x,$validSub,true)) bad('Ungültige Unterkategorie-ID in ordered_ids'); }
        $pdo->prepare("UPDATE competency_subcategories SET category_id=? WHERE id=?")->execute([$newParent,$id]);
        foreach($ordered as $pos=>$x){$pdo->prepare("UPDATE competency_subcategories SET category_id=?, sort_order=? WHERE id=?")->execute([$newParent,$pos+1,$x]);}
        if($oldParent>0 && $oldParent!==$newParent){ normalize_order($pdo,'competency_subcategories','category_id=?',[$oldParent]); }
        normalize_order($pdo,'competency_subcategories','category_id=?',[$newParent]);
      } elseif($type==='competency'){
        if($newParent<=0) bad('Unterkategorie als Parent erforderlich');
        $st=$pdo->prepare("SELECT category_id FROM competency_subcategories WHERE id=?");$st->execute([$newParent]);$cat=(int)($st->fetchColumn()?:0); if($cat<=0) bad('Ziel-Unterkategorie nicht gefunden');
        $pdo->prepare("UPDATE competencies SET subcategory_id=?, category_id=? WHERE id=?")->execute([$newParent,$cat,$id]);
        foreach($ordered as $x){$pdo->prepare("UPDATE competencies SET subcategory_id=?, category_id=?, sort_order=? WHERE id=?")->execute([$newParent,$cat,array_search($x,$ordered,true)+1,$x]);}
      } else bad('Ungültiger Typ');
      normalize_order($pdo,'competency_categories');
      json_out(['ok'=>true,'tree'=>fetch_tree($pdo)]);
    }

    bad('Unbekannte Action');
  } catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); bad($e->getMessage()); }
}

render_admin_header('Kompetenzen verwalten'); ?>
<div class="card"><h1>Kompetenzen verwalten</h1><a class="btn" href="<?=h(url('admin/competencies.php?download_csv_template=1'))?>">CSV-Vorlage herunterladen</a></div>
<div id="msg" class="card" style="display:none;"></div>
<div class="card"><details><summary><strong>CSV importieren</strong></summary><form method="post" enctype="multipart/form-data" style="margin-top:8px;"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="import_csv"><input type="file" name="csv" accept=".csv,text/csv" required><button class="btn" type="submit">Import starten</button></form></details></div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center"><h3>Baumstruktur</h3><button id="addCategory" class="btn" type="button">+ Kategorie</button></div>
  <div id="tree"></div>
</div>

<dialog id="modal"><form id="modalForm"><h3 id="modalTitle"></h3><div id="modalFields"></div><menu><button type="button" id="modalCancel" class="btn">Abbrechen</button><button id="modalSave" type="submit" class="btn">Speichern</button></menu></form></dialog>

<style>
.tree-node{border:1px solid #e7e7e7;border-radius:8px;padding:8px;margin:6px 0;background:#fff}
.node-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
.node-title{font-weight:600}
.node-actions button{background:transparent;border:0;cursor:pointer}
.children{margin-left:18px}
.drop-target{min-height:10px;border:2px dashed transparent;border-radius:8px;margin:4px 0;display:flex;align-items:center;padding:0 10px;font-size:12px;color:#7a7a7a}
.drop-target.active{border-color:#0b57d0;background:#eef5ff}
.dnd-placeholder-subcategory{min-height:10px;border-width:1px;margin:3px 0 3px 12px}
.dnd-placeholder-subcategory.active{border-color:#7a3cff;background:#f5f0ff}
body.is-dragging-category .dnd-placeholder-category{min-height:40px}
body.is-dragging-subcategory .dnd-placeholder-subcategory{min-height:38px}
body.is-dragging-category .dnd-placeholder-subcategory{display:none}
body.is-dragging-subcategory .dnd-placeholder-category{display:none}
.draggable{cursor:move}
.comp-main{font-weight:600}
.comp-sub{font-size:12px;color:#666}
.chip{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;background:#eef5ff;margin-right:4px}
#modal{width:min(900px,95vw)}
#modal textarea{width:100%;min-height:80px}
</style>
<script>
const csrf = <?=json_encode(csrf_token())?>;
const treeEl=document.getElementById('tree');
const msg=document.getElementById('msg');
const modal=document.getElementById('modal');
const modalTitle=document.getElementById('modalTitle');
const modalFields=document.getElementById('modalFields');
const modalSave=document.getElementById('modalSave');
let stateTree=[]; let busy=false; let modalState=null; const collapsed = new Set();
function showMsg(text,err=false){msg.style.display='block';msg.textContent=text;msg.className='card '+(err?'alert danger':'alert success');}
async function api(data){if(busy) throw new Error('Bitte warten…'); busy=true; try{const fd=new FormData(); Object.entries(data).forEach(([k,v])=>{ if(Array.isArray(v)){ v.forEach(x=>fd.append(k+'[]',String(x))); } else fd.append(k,String(v)); }); fd.append('csrf_token',csrf); const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}); const j=await r.json(); if(!r.ok||!j.ok) throw new Error(j.error||'Fehler'); return j;} finally {busy=false;}}
function gradeChecks(sel=[]){return `<div><strong>Klassenstufe</strong> ${[1,2,3,4].map(g=>`<label><input type="checkbox" name="grades[]" value="${g}" ${sel.includes(g)?'checked':''}> ${g}</label>`).join(' ')}</div>`}
function render(){
  treeEl.innerHTML='';
  if(!stateTree.length){treeEl.innerHTML='<div>Keine Kategorien vorhanden.</div>'; return;}
  const catList=document.createElement('div'); catList.dataset.dndList='categories';
  if(stateTree.length){
    catList.appendChild(mkDrop('category', String(stateTree[0].id), {dropType:'category', beforeId:String(stateTree[0].id)}));
  }
  stateTree.forEach((c,idx)=>{
    catList.appendChild(renderCategory(c));
    const nextId = stateTree[idx+1] ? String(stateTree[idx+1].id) : '';
    catList.appendChild(mkDrop('category', nextId || '0', {dropType:'category', beforeId:nextId}));
  });
  console.debug('[competencies dnd category] render category dropzones', stateTree.map(c=>c.id));
  treeEl.appendChild(catList);
  initDnd();
}
function mkDrop(type,before='0',extra={}){ const d=document.createElement('div'); d.className=`drop-target dnd-placeholder-${type}`; d.dataset.type=type; d.dataset.before=String(before); Object.entries(extra).forEach(([k,v])=>d.dataset[k]=String(v)); d.textContent=type==='category'?'Kategorie hier ablegen':'Unterkategorie hier ablegen'; return d; }
function renderCategory(c){
  const nodeKey=`category-${c.id}`; const isCollapsed=collapsed.has(nodeKey); const hasChildren=(c.children||[]).length>0;
  const wrap=document.createElement('div'); wrap.dataset.itemType='category'; wrap.dataset.itemId=String(c.id); wrap.className='tree-node category-node draggable'; wrap.draggable=true; wrap.dataset.type='category'; wrap.dataset.id=String(c.id);
  wrap.innerHTML=`<div class="node-head"><span class="node-title">${hasChildren?`<button data-act="toggle" data-node="${nodeKey}" style="border:0;background:none;cursor:pointer">${isCollapsed?'▸':'▾'}</button>`:''}${escapeHtml(c.name_de)} <small>${escapeHtml(c.name_en)}</small></span><span class="node-actions"><button data-act="addSub" data-id="${c.id}">＋</button><button data-act="edit" data-type="category" data-id="${c.id}">✏️</button><button data-act="del" data-type="category" data-id="${c.id}">🗑️</button></span></div>`;
  const subList=document.createElement('div'); subList.className='children'; subList.dataset.dndList='subcategories'; subList.dataset.categoryId=String(c.id); subList.style.display=isCollapsed?'none':'';
  const realSubs=(c.children||[]).filter(x=>!x.is_virtual);
  const virtualSubs=(c.children||[]).filter(x=>!!x.is_virtual);
  const firstReal=realSubs[0] ? String(realSubs[0].id) : '';
  subList.appendChild(mkDrop('subcategory', firstReal || '0', {dropType:'subcategory', parent:c.id, beforeId:firstReal}));
  realSubs.forEach((s,idx)=>{ 
    subList.appendChild(renderSub(s,c.id)); 
    const nextId = realSubs[idx+1] ? String(realSubs[idx+1].id) : '';
    subList.appendChild(mkDrop('subcategory', nextId || '0', {dropType:'subcategory', parent:c.id, beforeId:nextId}));
  });
  console.debug('[competencies dnd subcategory] render subcategory dropzones', c.id, realSubs.map(s=>s.id));
  virtualSubs.forEach(s=>subList.appendChild(renderSub(s,c.id)));
  wrap.appendChild(subList);
  return wrap;
}
function renderSub(s,catId){
  const virtual=!!s.is_virtual; const sid=String(s.id); const nodeKey=`subcategory-${sid}`; const isCollapsed=collapsed.has(nodeKey); const hasChildren=(s.children||[]).length>0;
  const wrap=document.createElement('div'); wrap.className='tree-node'+(virtual?'':' draggable');
  if(!virtual){ wrap.draggable=true; wrap.dataset.type='subcategory'; wrap.dataset.id=sid; wrap.dataset.parent=String(catId); wrap.dataset.itemType='subcategory'; wrap.dataset.itemId=sid; }
  wrap.innerHTML=`<div class="node-head"><span>${virtual?'':''}${hasChildren?`<button data-act="toggle" data-node="${nodeKey}" style="border:0;background:none;cursor:pointer">${isCollapsed?'▸':'▾'}</button>`:''}${escapeHtml(s.name_de)} <small>${escapeHtml(s.name_en||'')}</small></span><span class="node-actions"><button data-act="addComp" data-id="${sid}" data-virtual="${virtual?1:0}" data-category="${catId}">＋</button>${virtual?'':'<button data-act="edit" data-type="subcategory" data-id="'+sid+'">✏️</button><button data-act="del" data-type="subcategory" data-id="'+sid+'">🗑️</button>'}</span></div>`;
  const compList=document.createElement('div'); compList.className='children'; compList.dataset.dndList='competencies'; compList.dataset.categoryId=String(catId); compList.dataset.subcategoryId=virtual?'':sid; if(virtual) compList.dataset.virtualNoSubcategory='1'; compList.style.display=isCollapsed?'none':'';
  compList.appendChild(mkDrop('competency','0',{parent:virtual?0:sid,targetCategory:catId}));
  (s.children||[]).forEach(k=>{ compList.appendChild(renderComp(k,sid)); compList.appendChild(mkDrop('competency','0',{parent:virtual?0:sid,targetCategory:catId})); });
  wrap.appendChild(compList);
  return wrap;
}
function renderComp(k,subId){const el=document.createElement('div'); el.className='tree-node draggable'; el.draggable=true; el.dataset.type='competency'; el.dataset.itemType='competency'; el.dataset.itemId=String(k.id); el.dataset.id=String(k.id); el.dataset.parent=String(subId);const grade=(k.grades||[]).map(g=>`<span class="chip">${g}</span>`).join('');const req=k.is_required?'<span class="chip">Pflicht</span>':'<span class="chip">Optional</span>'; el.innerHTML=`<div class="node-head"><span><div class="comp-main">${escapeHtml(k.code)} — ${escapeHtml(k.text_de)}</div>${k.text_en?`<div class="comp-sub">${escapeHtml(k.text_en)}</div>`:''}<div>${req}${grade}</div></span><span class="node-actions"><button data-act="edit" data-type="competency" data-id="${k.id}">✏️</button><button data-act="del" data-type="competency" data-id="${k.id}">🗑️</button></span></div>`; return el;}
function escapeHtml(s){return (s??'').toString().replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}

function findNode(type,id){for(const c of stateTree){if(type==='category'&&c.id==id) return c; for(const s of c.children||[]){if(type==='subcategory'&&s.id==id)return s; for(const k of s.children||[]){if(type==='competency'&&k.id==id)return k;}}} return null;}
function openModal(cfg){modalState=cfg; modalTitle.textContent=cfg.title; modalFields.innerHTML=cfg.html; modal.showModal();}

document.getElementById('addCategory').addEventListener('click',()=>openModal({mode:'create',type:'category',title:'Kategorie hinzufügen',html:'<label>Deutsch</label><input class="input" name="name_de" required><label>English</label><input class="input" name="name_en" required>'}));
treeEl.addEventListener('click', async (e)=>{const b=e.target.closest('button[data-act]'); if(!b) return; const act=b.dataset.act; const id=Number(b.dataset.id);
if(act==='addSub'){openModal({mode:'create',type:'subcategory',parent:id,title:'Unterkategorie hinzufügen',html:'<label>Deutsch</label><input class="input" name="name_de" required><label>English</label><input class="input" name="name_en" required>'});}
if(act==='toggle'){const key=b.dataset.node; if(collapsed.has(key)) collapsed.delete(key); else collapsed.add(key); render(); return;}
if(act==='addComp'){const virt=b.dataset.virtual==='1';openModal({mode:'create',type:'competency',parent:virt?0:id,targetCategory:virt?Number(b.dataset.category):0,title:'Kompetenz hinzufügen',html:'<label>Code (optional)</label><input class="input" name="code"><label>Deutsch</label><textarea name="text_de" required></textarea><label>English</label><textarea name="text_en"></textarea><label><input type="checkbox" name="is_required" value="1"> Pflicht</label>'+gradeChecks([])});}
if(act==='edit'){const n=findNode(b.dataset.type,id); if(!n) return; if(b.dataset.type==='category') openModal({mode:'update',type:'category',id,title:'Kategorie bearbeiten',html:`<label>Deutsch</label><input class="input" name="name_de" value="${escapeHtml(n.name_de)}" required><label>English</label><input class="input" name="name_en" value="${escapeHtml(n.name_en)}" required>`});
if(b.dataset.type==='subcategory') openModal({mode:'update',type:'subcategory',id,title:'Unterkategorie bearbeiten',html:`<label>Deutsch</label><input class="input" name="name_de" value="${escapeHtml(n.name_de)}" required><label>English</label><input class="input" name="name_en" value="${escapeHtml(n.name_en)}" required>`});
if(b.dataset.type==='competency') openModal({mode:'update',type:'competency',id,title:'Kompetenz bearbeiten',html:`<label>Code</label><input class="input" name="code" value="${escapeHtml(n.code)}" required><label>Deutsch</label><textarea name="text_de" required>${escapeHtml(n.text_de)}</textarea><label>English</label><textarea name="text_en">${escapeHtml(n.text_en||'')}</textarea><label><input type="checkbox" name="is_required" value="1" ${n.is_required? 'checked':''}> Pflicht</label>${gradeChecks((n.grades||[]).map(Number))}`});}
if(act==='del'){try{const t=b.dataset.type; const prev=await api({action:'delete_preview',type:t,id:String(id)}); let txt=''; if(t==='category') txt=`Diese Kategorie enthält ${prev.counts.subcategories} Unterkategorien und ${prev.counts.competencies} Kompetenzen. Wirklich löschen?`; if(t==='subcategory') txt=`Diese Unterkategorie enthält ${prev.counts.competencies} Kompetenzen. Wirklich löschen?`; if(t==='competency') txt='Diese Kompetenz wirklich löschen?'; if(!confirm(txt)) return; const res=await api({action:'delete',type:t,id:String(id)}); stateTree=res.tree; render(); showMsg('Gelöscht.'); }catch(err){showMsg(err.message,true);} }
});

document.getElementById('modalForm').addEventListener('submit', async (e)=>{e.preventDefault(); if(!modalState) return; const fd=new FormData(document.getElementById('modalForm')); const data={action:modalState.mode==='create'?'create':'update',type:modalState.type}; if(modalState.id) data.id=String(modalState.id); if(modalState.parent!==undefined) data.parent_id=String(modalState.parent); if(modalState.targetCategory) data.target_category_id=String(modalState.targetCategory); for(const [k,v] of fd.entries()){ if(k.endsWith('[]')){ const key=k.slice(0,-2); if(!data[key]) data[key]=[]; data[key].push(v);} else data[k]=v; }
  try{ modalSave.disabled=true; const res=await api(data); stateTree=res.tree; render(); modal.close(); showMsg('Gespeichert.'); }catch(err){showMsg(err.message,true);} finally {modalSave.disabled=false;}
});

document.getElementById('modalCancel').addEventListener('click',()=>{document.getElementById('modalForm').reset(); modal.close();});
modal.addEventListener('cancel',(e)=>{e.preventDefault(); document.getElementById('modalForm').reset(); modal.close();});

let dragEl=null;
function initDnd(){
  document.querySelectorAll('.draggable').forEach(el=>{el.ondragstart=(e)=>{ 
    e.stopPropagation();
    const nested = e.target.closest('.tree-node.draggable');
    if(nested && nested!==el) return;
    if(el.dataset.type!=='category' && el.dataset.type!=='subcategory') return;
    dragEl=el; 
    document.body.classList.remove('is-dragging-category','is-dragging-subcategory');
    document.body.classList.add(el.dataset.type==='category'?'is-dragging-category':'is-dragging-subcategory');
    if(el.dataset.type==='category') console.debug('[competencies dnd category] start', Number(el.dataset.id));
    if(el.dataset.type==='subcategory') console.debug('[competencies dnd subcategory] start', Number(el.dataset.id), Number(el.dataset.parent||0));
  }; el.ondragend=(e)=>{e.stopPropagation(); dragEl=null; document.body.classList.remove('is-dragging-category','is-dragging-subcategory'); document.querySelectorAll('.dnd-placeholder-category,.dnd-placeholder-subcategory').forEach(d=>d.classList.remove('active'));};});
  document.querySelectorAll('.dnd-placeholder-category').forEach(d=>{
    d.ondragover=(e)=>{ if(!dragEl || dragEl.dataset.type!=='category'){ console.debug('[competencies dnd category] dragover ignored for', dragEl?.dataset?.type); return; } if(d.dataset.dropType!=='category') return; e.preventDefault(); d.classList.add('active'); };
    d.ondragleave=()=>d.classList.remove('active');
    d.ondrop=async (e)=>{
      e.preventDefault(); d.classList.remove('active');
      if(!dragEl || dragEl.dataset.type!=='category') return;
      const itemId=Number(dragEl.dataset.id);
      const beforeIdRaw=(d.dataset.beforeId||'').trim();
      const beforeId=beforeIdRaw===''?0:Number(beforeIdRaw);
      let orderedIds=stateTree.map(c=>Number(c.id)).filter(x=>x!==itemId);
      if(beforeId>0){ const i=orderedIds.indexOf(beforeId); if(i>=0) orderedIds.splice(i,0,itemId); else orderedIds.push(itemId);} else { orderedIds.push(itemId); }
      const currentIds=stateTree.map(c=>Number(c.id));
      if(itemId===beforeId || orderedIds.join(',')===currentIds.join(',')) return;
      console.debug('[competencies dnd category] drop', {itemId,beforeId:beforeIdRaw,orderedIds});
      try{ const res=await api({action:'reorder',type:'category',id:String(itemId),ordered_ids:JSON.stringify(orderedIds)}); stateTree=res.tree; render(); }
      catch(err){ showMsg(err.message,true); const res=await api({action:'list_tree'}); stateTree=res.tree; render(); }
    };
  });
  document.querySelectorAll('.dnd-placeholder-subcategory').forEach(d=>{
    d.ondragover=(e)=>{ if(!dragEl || dragEl.dataset.type!=='subcategory') return; if(d.dataset.dropType!=='subcategory') return; e.preventDefault(); d.classList.add('active'); console.debug('[competencies dnd subcategory] dragover allowed', Number(d.dataset.parent||0)); };
    d.ondragleave=()=>d.classList.remove('active');
    d.ondrop=async (e)=>{
      e.preventDefault(); d.classList.remove('active');
      if(!dragEl || dragEl.dataset.type!=='subcategory') return;
      const itemId=Number(dragEl.dataset.id);
      const sourceCategoryId=Number(dragEl.dataset.parent||0);
      const targetCategoryId=Number(d.dataset.parent||0);
      const beforeIdRaw=(d.dataset.beforeId||'').trim();
      const beforeId=beforeIdRaw===''?0:Number(beforeIdRaw);
      const targetCat = stateTree.find(c=>Number(c.id)===targetCategoryId);
      const targetRealSubs = ((targetCat?.children)||[]).filter(s=>!s.is_virtual).map(s=>Number(s.id));
      let orderedIds=targetRealSubs.filter(x=>x!==itemId);
      if(beforeId>0){ const i=orderedIds.indexOf(beforeId); if(i>=0) orderedIds.splice(i,0,itemId); else orderedIds.push(itemId);} else { orderedIds.push(itemId); }
      const currentIds = sourceCategoryId===targetCategoryId
        ? ((stateTree.find(c=>Number(c.id)===sourceCategoryId)?.children)||[]).filter(s=>!s.is_virtual).map(s=>Number(s.id))
        : targetRealSubs;
      if(itemId===beforeId || orderedIds.join(',')===currentIds.join(',')) return;
      console.debug('[competencies dnd subcategory] drop', {itemId,sourceCategoryId,targetCategoryId,beforeId:beforeIdRaw,orderedIds});
      try{ const res=await api({action:'reorder',type:'subcategory',id:String(itemId),new_parent_id:String(targetCategoryId),ordered_ids:JSON.stringify(orderedIds)}); stateTree=res.tree; render(); }
      catch(err){ showMsg(err.message,true); const res=await api({action:'list_tree'}); stateTree=res.tree; render(); }
    };
  });
}

(async()=>{const res=await api({action:'list_tree'}); stateTree=res.tree; render();})();
</script>
<?php render_admin_footer();
