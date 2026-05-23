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
  $comps=$pdo->query("SELECT id,category_id,subcategory_id,code,text_de,text_en,is_required,sort_order FROM competencies WHERE subcategory_id IS NOT NULL ORDER BY subcategory_id,sort_order,id")->fetchAll(PDO::FETCH_ASSOC)?:[];
  $grades=[]; foreach(($pdo->query("SELECT competency_id,grade_level FROM competency_grade_levels ORDER BY grade_level")->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){$grades[(int)$r['competency_id']][]=(int)$r['grade_level'];}
  $subsByCat=[]; foreach($subs as $s){$subsByCat[(int)$s['category_id']][]=$s;}
  $compsBySub=[]; foreach($comps as $c){$compsBySub[(int)$c['subcategory_id']][]=$c;}
  $tree=[];
  foreach($cats as $c){
    $catId=(int)$c['id'];
    $cn=[];
    foreach(($subsByCat[$catId]??[]) as $s){
      $subId=(int)$s['id'];
      $items=[];
      foreach(($compsBySub[$subId]??[]) as $it){
        $cid=(int)$it['id'];
        $items[]=['id'=>$cid,'type'=>'competency','title'=>(string)$it['code'].' — '.(string)$it['text_de'],'code'=>(string)$it['code'],'text_de'=>(string)$it['text_de'],'text_en'=>(string)$it['text_en'],'is_required'=>(int)$it['is_required'],'grades'=>$grades[$cid]??[],'sort_order'=>(int)$it['sort_order']];
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
        $sub=(int)($_POST['parent_id']??0); if($sub<=0) bad('Unterkategorie erforderlich');
        $st=$pdo->prepare("SELECT category_id,name_de FROM competency_subcategories WHERE id=?");$st->execute([$sub]);$sr=$st->fetch(PDO::FETCH_ASSOC); if(!$sr) bad('Unterkategorie nicht gefunden');
        $cat=(int)$sr['category_id'];
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
        foreach($ordered as $x){$pdo->prepare("UPDATE competency_categories SET sort_order=? WHERE id=?")->execute([array_search($x,$ordered,true)+1,$x]);}
      } elseif($type==='subcategory'){
        if($newParent<=0) bad('Kategorie als Parent erforderlich');
        $pdo->prepare("UPDATE competency_subcategories SET category_id=? WHERE id=?")->execute([$newParent,$id]);
        foreach($ordered as $x){$pdo->prepare("UPDATE competency_subcategories SET category_id=?, sort_order=? WHERE id=?")->execute([$newParent,array_search($x,$ordered,true)+1,$x]);}
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
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center"><h3>Baumstruktur</h3><button id="addCategory" class="btn" type="button">+ Kategorie</button></div>
  <div id="tree"></div>
</div>

<dialog id="modal"><form method="dialog" id="modalForm"><h3 id="modalTitle"></h3><div id="modalFields"></div><menu><button class="btn" value="cancel">Abbrechen</button><button id="modalSave" class="btn" value="default">Speichern</button></menu></form></dialog>

<style>
.tree-node{border:1px solid #e7e7e7;border-radius:8px;padding:8px;margin:6px 0;background:#fff}
.node-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
.node-title{font-weight:600}
.node-actions button{background:transparent;border:0;cursor:pointer}
.children{margin-left:18px}
.drop-target{height:12px;border:2px dashed transparent;border-radius:6px;margin:4px 0}
.drop-target.active{border-color:#0b57d0;background:#eef5ff}
.draggable{cursor:move}
</style>
<script>
const csrf = <?=json_encode(csrf_token())?>;
const treeEl=document.getElementById('tree');
const msg=document.getElementById('msg');
const modal=document.getElementById('modal');
const modalTitle=document.getElementById('modalTitle');
const modalFields=document.getElementById('modalFields');
const modalSave=document.getElementById('modalSave');
let stateTree=[]; let busy=false; let modalState=null;
function showMsg(text,err=false){msg.style.display='block';msg.textContent=text;msg.className='card '+(err?'alert danger':'alert success');}
async function api(data){if(busy) throw new Error('Bitte warten…'); busy=true; try{const fd=new FormData(); Object.entries(data).forEach(([k,v])=>fd.append(k, typeof v==='string'?v:JSON.stringify(v))); fd.append('csrf_token',csrf); const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}); const j=await r.json(); if(!r.ok||!j.ok) throw new Error(j.error||'Fehler'); return j;} finally {busy=false;}}
function gradeChecks(sel=[]){return `<div><strong>Klassenstufe</strong> ${[1,2,3,4].map(g=>`<label><input type="checkbox" name="grades[]" value="${g}" ${sel.includes(g)?'checked':''}> ${g}</label>`).join(' ')}</div>`}
function render(){treeEl.innerHTML=''; if(!stateTree.length){treeEl.innerHTML='<div>Keine Kategorien vorhanden.</div>'; return;} stateTree.forEach(c=>treeEl.appendChild(renderCategory(c))); initDnd();}
function renderCategory(c){const el=document.createElement('div'); el.className='tree-node draggable'; el.draggable=true; el.dataset.type='category'; el.dataset.id=c.id; el.innerHTML=`<div class="drop-target" data-type="category" data-parent="0" data-before="${c.id}"></div><div class="node-head"><span class="node-title">${escapeHtml(c.name_de)} <small>${escapeHtml(c.name_en)}</small></span><span class="node-actions"><button data-act="addSub" data-id="${c.id}">＋</button><button data-act="edit" data-type="category" data-id="${c.id}">✏️</button><button data-act="del" data-type="category" data-id="${c.id}">🗑️</button></span></div><div class="children"></div>`;
const ch=el.querySelector('.children');
if(!c.children.length){ch.innerHTML='<div>Keine Unterkategorien.</div>';} else c.children.forEach(s=>ch.appendChild(renderSub(s,c.id)));
const tail=document.createElement('div'); tail.className='drop-target'; tail.dataset.type='category'; tail.dataset.parent='0'; tail.dataset.before='0'; ch.parentNode.appendChild(tail);
return el;}
function renderSub(s,catId){const el=document.createElement('div'); el.className='tree-node draggable'; el.draggable=true; el.dataset.type='subcategory'; el.dataset.id=s.id; el.dataset.parent=catId; el.innerHTML=`<div class="drop-target" data-type="subcategory" data-parent="${catId}" data-before="${s.id}"></div><div class="node-head"><span>${escapeHtml(s.name_de)} <small>${escapeHtml(s.name_en)}</small></span><span class="node-actions"><button data-act="addComp" data-id="${s.id}">＋</button><button data-act="edit" data-type="subcategory" data-id="${s.id}">✏️</button><button data-act="del" data-type="subcategory" data-id="${s.id}">🗑️</button></span></div><div class="children"></div>`;
const ch=el.querySelector('.children'); if(!s.children.length){ch.innerHTML='<div>Keine Kompetenzen.</div>';} else s.children.forEach(k=>ch.appendChild(renderComp(k,s.id)));
const tail=document.createElement('div'); tail.className='drop-target'; tail.dataset.type='subcategory'; tail.dataset.parent=catId; tail.dataset.before='0';
const ct=document.createElement('div'); ct.className='drop-target'; ct.dataset.type='competency'; ct.dataset.parent=s.id; ct.dataset.before='0';
el.appendChild(ct); el.appendChild(tail);
return el;}
function renderComp(k,subId){const el=document.createElement('div'); el.className='tree-node draggable'; el.draggable=true; el.dataset.type='competency'; el.dataset.id=k.id; el.dataset.parent=subId; el.innerHTML=`<div class="drop-target" data-type="competency" data-parent="${subId}" data-before="${k.id}"></div><div class="node-head"><span>${escapeHtml(k.code)} — ${escapeHtml(k.text_de)}</span><span class="node-actions"><button data-act="edit" data-type="competency" data-id="${k.id}">✏️</button><button data-act="del" data-type="competency" data-id="${k.id}">🗑️</button></span></div>`; return el;}
function escapeHtml(s){return (s??'').toString().replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}

function findNode(type,id){for(const c of stateTree){if(type==='category'&&c.id==id) return c; for(const s of c.children||[]){if(type==='subcategory'&&s.id==id)return s; for(const k of s.children||[]){if(type==='competency'&&k.id==id)return k;}}} return null;}
function openModal(cfg){modalState=cfg; modalTitle.textContent=cfg.title; modalFields.innerHTML=cfg.html; modal.showModal();}

document.getElementById('addCategory').addEventListener('click',()=>openModal({mode:'create',type:'category',title:'Kategorie hinzufügen',html:'<label>Deutsch</label><input class="input" name="name_de" required><label>English</label><input class="input" name="name_en" required>'}));
treeEl.addEventListener('click', async (e)=>{const b=e.target.closest('button[data-act]'); if(!b) return; const act=b.dataset.act; const id=Number(b.dataset.id);
if(act==='addSub'){openModal({mode:'create',type:'subcategory',parent:id,title:'Unterkategorie hinzufügen',html:'<label>Deutsch</label><input class="input" name="name_de" required><label>English</label><input class="input" name="name_en" required>'});}
if(act==='addComp'){openModal({mode:'create',type:'competency',parent:id,title:'Kompetenz hinzufügen',html:'<label>Code (optional)</label><input class="input" name="code"><label>Deutsch</label><input class="input" name="text_de" required><label>English</label><input class="input" name="text_en" required><label><input type="checkbox" name="is_required" value="1"> Pflicht</label>'+gradeChecks([])});}
if(act==='edit'){const n=findNode(b.dataset.type,id); if(!n) return; if(b.dataset.type==='category') openModal({mode:'update',type:'category',id,title:'Kategorie bearbeiten',html:`<label>Deutsch</label><input class="input" name="name_de" value="${escapeHtml(n.name_de)}" required><label>English</label><input class="input" name="name_en" value="${escapeHtml(n.name_en)}" required>`});
if(b.dataset.type==='subcategory') openModal({mode:'update',type:'subcategory',id,title:'Unterkategorie bearbeiten',html:`<label>Deutsch</label><input class="input" name="name_de" value="${escapeHtml(n.name_de)}" required><label>English</label><input class="input" name="name_en" value="${escapeHtml(n.name_en)}" required>`});
if(b.dataset.type==='competency') openModal({mode:'update',type:'competency',id,title:'Kompetenz bearbeiten',html:`<label>Code</label><input class="input" name="code" value="${escapeHtml(n.code)}" required><label>Deutsch</label><input class="input" name="text_de" value="${escapeHtml(n.text_de)}" required><label>English</label><input class="input" name="text_en" value="${escapeHtml(n.text_en)}" required><label><input type="checkbox" name="is_required" value="1" ${n.is_required? 'checked':''}> Pflicht</label>${gradeChecks((n.grades||[]).map(Number))}`});}
if(act==='del'){try{const t=b.dataset.type; const prev=await api({action:'delete_preview',type:t,id:String(id)}); let txt=''; if(t==='category') txt=`Diese Kategorie enthält ${prev.counts.subcategories} Unterkategorien und ${prev.counts.competencies} Kompetenzen. Wirklich löschen?`; if(t==='subcategory') txt=`Diese Unterkategorie enthält ${prev.counts.competencies} Kompetenzen. Wirklich löschen?`; if(t==='competency') txt='Diese Kompetenz wirklich löschen?'; if(!confirm(txt)) return; const res=await api({action:'delete',type:t,id:String(id)}); stateTree=res.tree; render(); showMsg('Gelöscht.'); }catch(err){showMsg(err.message,true);} }
});

modalSave.addEventListener('click', async (e)=>{e.preventDefault(); if(!modalState) return; const fd=new FormData(document.getElementById('modalForm')); const data={action:modalState.mode==='create'?'create':'update',type:modalState.type}; if(modalState.id) data.id=String(modalState.id); if(modalState.parent) data.parent_id=String(modalState.parent); for(const [k,v] of fd.entries()){ if(k.endsWith('[]')){ if(!data[k]) data[k]=[]; data[k].push(v);} else data[k]=v; }
  try{ modalSave.disabled=true; const res=await api(data); stateTree=res.tree; render(); modal.close(); showMsg('Gespeichert.'); }catch(err){showMsg(err.message,true);} finally {modalSave.disabled=false;}
});

let dragEl=null;
function initDnd(){
  document.querySelectorAll('.draggable').forEach(el=>{el.addEventListener('dragstart',()=>{dragEl=el;}); el.addEventListener('dragend',()=>{dragEl=null; document.querySelectorAll('.drop-target').forEach(d=>d.classList.remove('active'));});});
  document.querySelectorAll('.drop-target').forEach(d=>{
    d.addEventListener('dragover',(e)=>{if(!dragEl) return; if(d.dataset.type!==dragEl.dataset.type) return; e.preventDefault(); d.classList.add('active');});
    d.addEventListener('dragleave',()=>d.classList.remove('active'));
    d.addEventListener('drop', async (e)=>{e.preventDefault(); d.classList.remove('active'); if(!dragEl) return; const type=dragEl.dataset.type; if(d.dataset.type!==type) return; const parent=Number(d.dataset.parent||0); const before=Number(d.dataset.before||0);
      try{
        let siblings=[];
        if(type==='category') siblings=Array.from(document.querySelectorAll('.draggable[data-type="category"]')).map(x=>Number(x.dataset.id));
        if(type==='subcategory') siblings=Array.from(document.querySelectorAll(`.draggable[data-type="subcategory"][data-parent="${parent}"]`)).map(x=>Number(x.dataset.id));
        if(type==='competency') siblings=Array.from(document.querySelectorAll(`.draggable[data-type="competency"][data-parent="${parent}"]`)).map(x=>Number(x.dataset.id));
        const id=Number(dragEl.dataset.id); siblings=siblings.filter(x=>x!==id); if(before>0){const idx=siblings.indexOf(before); if(idx>=0) siblings.splice(idx,0,id); else siblings.push(id);} else siblings.push(id);
        const res=await api({action:'reorder',type,id:String(id),new_parent_id:String(parent),ordered_ids:siblings});
        stateTree=res.tree; render();
      }catch(err){showMsg(err.message,true); const res=await api({action:'list_tree'}); stateTree=res.tree; render();}
    });
  });
}

(async()=>{const res=await api({action:'list_tree'}); stateTree=res.tree; render();})();
</script>
<?php render_admin_footer();
