<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$ok = null; $err = null;
$teacherId = (int)(current_user()['id'] ?? 0);

$cats = $pdo->query("SELECT id,name_de,name_en FROM competency_categories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$subs = $pdo->query("SELECT id,category_id,name_de,name_en FROM competency_subcategories ORDER BY category_id,sort_order,id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$subsByCat = [];
foreach ($subs as $s) { $subsByCat[(int)$s['category_id']][] = $s; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $cat = (int)($_POST['category_id'] ?? 0);
    $sub = (int)($_POST['subcategory_id'] ?? 0);
    $textDe = trim((string)($_POST['proposal_text_de'] ?? ''));
    $textEn = trim((string)($_POST['proposal_text_en'] ?? ''));
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $grades = array_values(array_unique(array_map('intval', (array)($_POST['grade_levels'] ?? []))));
    $grades = array_values(array_filter($grades, static fn(int $g): bool => $g >= 1 && $g <= 4));

    if ($cat <= 0) throw new RuntimeException('Bitte eine Kategorie wählen.');
    if ($textDe === '' || $textEn === '') throw new RuntimeException('Kompetenztext (DE/EN) fehlt.');

    $stCat = $pdo->prepare("SELECT id,name_de FROM competency_categories WHERE id=? LIMIT 1");
    $stCat->execute([$cat]);
    $catRow = $stCat->fetch(PDO::FETCH_ASSOC);
    if (!$catRow) throw new RuntimeException('Kategorie nicht gefunden.');

    $subId = null; $subRow = null;
    if ($sub > 0) {
      $stSub = $pdo->prepare("SELECT id,name_de FROM competency_subcategories WHERE id=? AND category_id=? LIMIT 1");
      $stSub->execute([$sub, $cat]);
      $subRow = $stSub->fetch(PDO::FETCH_ASSOC);
      if (!$subRow) throw new RuntimeException('Unterkategorie passt nicht zur gewählten Kategorie.');
      $subId = (int)$subRow['id'];
    }

    $meta = json_encode(['grade_levels'=>$grades,'is_required'=>$isRequired], JSON_UNESCAPED_UNICODE);
    $stIns = $pdo->prepare("INSERT INTO competency_requests(teacher_user_id,category_id,subcategory_id,proposal_text_de,proposal_text_en,status,admin_note,reviewed_by_user_id,reviewed_at,approved_competency_id) VALUES (?,?,?,?,?,'pending',?,?,?,?)");
    $stIns->execute([$teacherId, $cat, $subId, $textDe, $textEn, $meta, null, null, null]);

    $ok = 'Antrag wurde gesendet. Die Administration prüft den Vorschlag, bevor die Kompetenz übernommen wird.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$myRequests = $pdo->prepare("SELECT cr.*, cat.name_de AS cat_de, sub.name_de AS sub_de FROM competency_requests cr LEFT JOIN competency_categories cat ON cat.id=cr.category_id LEFT JOIN competency_subcategories sub ON sub.id=cr.subcategory_id WHERE cr.teacher_user_id=? ORDER BY cr.created_at DESC, cr.id DESC");
$myRequests->execute([$teacherId]);
$requests = $myRequests->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_teacher_header('Kompetenz-Anträge');
?>
<div class="card">
  <h1>Neue Kompetenz beantragen</h1>
  <p class="muted">Der Antrag wird von der Administration geprüft, bevor die Kompetenz übernommen wird.</p>
  <?php if($ok): ?><div class="alert success" style="margin-bottom:10px;"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert danger" style="margin-bottom:10px;"><?=h($err)?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">

    <div class="form-group">
      <label class="form-label">Kategorie</label>
      <select class="input" name="category_id" id="category_id" required>
        <option value="">Bitte wählen…</option>
        <?php foreach($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name_de']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Unterkategorie</label>
      <select class="input" name="subcategory_id" id="subcategory_id"><option value="">Ohne Unterkategorie</option></select>
      <small id="sub_hint" class="muted">Bitte zuerst eine Kategorie wählen.</small>
    </div>
    <div class="form-group">
      <label class="form-label">Kompetenz (Deutsch)</label>
      <input class="input" type="text" name="proposal_text_de" required>
    </div>
    <div class="form-group">
      <label class="form-label">Kompetenz (English)</label>
      <input class="input" type="text" name="proposal_text_en" required>
    </div>
    <div class="form-group">
      <label class="choice-chip required-choice"><input type="checkbox" name="is_required" value="1"><span>🔒 Pflichtkompetenz</span></label>
    </div>
    <div class="form-group">
      <div class="form-label">Klassenstufen</div>
      <div class="choice-row"><?php foreach([1,2,3,4] as $g): ?><label class="choice-chip grade-choice grade-<?= $g ?>"><input type="checkbox" name="grade_levels[]" value="<?= $g ?>"><span><?= $g ?></span></label><?php endforeach; ?></div>
    </div>
    <div style="display:flex;justify-content:flex-end;"><button class="btn" type="submit">Antrag senden</button></div>
  </form>
</div>

<div class="card" style="margin-top:14px;">
  <h2>Meine Kompetenzanträge</h2>
  <?php if(!$requests): ?><p class="muted">Noch keine Anträge vorhanden.</p><?php endif; ?>
  <?php foreach($requests as $r):
    $meta = json_decode((string)($r['admin_note'] ?? ''), true); if(!is_array($meta)) $meta=[];
    $status=(string)($r['status']??'pending');
    $stLabel=$status==='approved'?'Genehmigt':($status==='rejected'?'Abgelehnt':'Offen');
    $stColor=$status==='approved'?'#067647':($status==='rejected'?'#b42318':'#0b57d0');
    $grades=(array)($meta['grade_levels']??[]);
    $comment=trim((string)($meta['comment']??''));
  ?>
    <div class="card" style="margin:10px 0;border-left:4px solid <?=h($stColor)?>;">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
        <strong><?=h($stLabel)?></strong>
        <span class="chip" style="background:<?=h($stColor)?>;color:#fff;"><?=h($status)?></span>
      </div>
      <div class="muted" style="margin-top:6px;">Kategorie: <?=h((string)($r['cat_de']??'—'))?> · Unterkategorie: <?=h((string)($r['sub_de']?:'Ohne Unterkategorie'))?></div>
      <div style="margin-top:8px;"><strong>DE:</strong> <?=h((string)$r['proposal_text_de'])?></div>
      <div><strong>EN:</strong> <?=h((string)($r['proposal_text_en']?:'—'))?></div>
      <div style="margin-top:6px;">Klassenstufen:
        <?php if($grades): foreach($grades as $g): ?><span class="chip grade-chip grade-<?= (int)$g ?>"><?= (int)$g ?></span><?php endforeach; else: ?> <span class="muted">—</span><?php endif; ?>
        <span class="chip"><?= ((int)($meta['is_required']??0)===1?'Pflicht':'Optional') ?></span>
      </div>
      <div class="muted" style="margin-top:6px;">Erstellt: <?=h((string)$r['created_at'])?><?php if(!empty($r['reviewed_at'])): ?> · Bearbeitet: <?=h((string)$r['reviewed_at'])?><?php endif; ?></div>
      <?php if($comment!==''): ?><div style="margin-top:8px;padding:8px 10px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;"><strong>Admin-Kommentar:</strong><br><?=nl2br(h($comment))?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<style>
.form-grid{display:flex;flex-direction:column;gap:14px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:13px;font-weight:700;color:#334155}
.choice-row{display:flex;flex-wrap:wrap;gap:8px}
.choice-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:999px;padding:7px 11px;cursor:pointer;font-size:13px;font-weight:600;color:#334155;user-select:none}
.choice-chip input{width:auto;margin:0}
.choice-chip:has(input:checked){border-color:#0b57d0;background:#eef5ff;color:#0b57d0}
.required-choice:has(input:checked){border-color:#1f355e;background:#e7edf9;color:#1f355e}
.grade-choice.grade-1:has(input:checked){border-color:#1f4f99;background:#eaf3ff;color:#1f4f99}
.grade-choice.grade-2:has(input:checked){border-color:#1f7a45;background:#ecfff4;color:#1f7a45}
.grade-choice.grade-3:has(input:checked){border-color:#9a5a12;background:#fff5e8;color:#9a5a12}
.grade-choice.grade-4:has(input:checked){border-color:#5a33a2;background:#f3edff;color:#5a33a2}
.input{border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px}
.grade-chip{font-weight:600}
.grade-1{background:#eaf3ff;color:#1f4f99}.grade-2{background:#ecfff4;color:#1f7a45}.grade-3{background:#fff5e8;color:#9a5a12}.grade-4{background:#f3edff;color:#5a33a2}
</style>
<script>
const SUBS_BY_CAT = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;
const catEl = document.getElementById('category_id');
const subEl = document.getElementById('subcategory_id');
const hint = document.getElementById('sub_hint');
function updateSubOptions(){
  const catId = String(catEl.value || '');
  const subs = SUBS_BY_CAT[catId] || [];
  subEl.innerHTML = '';
  const optNone = document.createElement('option'); optNone.value=''; optNone.textContent='Ohne Unterkategorie'; subEl.appendChild(optNone);
  subs.forEach(s=>{const o=document.createElement('option'); o.value=String(s.id); o.textContent=String(s.name_de||''); subEl.appendChild(o);});
  hint.textContent = catId === '' ? 'Bitte zuerst eine Kategorie wählen.' : (subs.length ? 'Nur Unterkategorien der gewählten Kategorie sind auswählbar.' : 'Für diese Kategorie sind keine Unterkategorien vorhanden.');
}
catEl.addEventListener('change', updateSubOptions); updateSubOptions();
</script>
<?php render_teacher_footer();
