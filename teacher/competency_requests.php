<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$ok = null; $err = null;

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
    if ($textDe === '') throw new RuntimeException('Kompetenztext (DE) fehlt.');

    $stCat = $pdo->prepare("SELECT id,name_de FROM competency_categories WHERE id=? LIMIT 1");
    $stCat->execute([$cat]);
    $catRow = $stCat->fetch(PDO::FETCH_ASSOC);
    if (!$catRow) throw new RuntimeException('Kategorie nicht gefunden.');

    $subId = null;
    if ($sub > 0) {
      $stSub = $pdo->prepare("SELECT id,name_de FROM competency_subcategories WHERE id=? AND category_id=? LIMIT 1");
      $stSub->execute([$sub, $cat]);
      $subRow = $stSub->fetch(PDO::FETCH_ASSOC);
      if (!$subRow) throw new RuntimeException('Unterkategorie passt nicht zur gewählten Kategorie.');
      $subId = (int)$subRow['id'];
    }

    $stIns = $pdo->prepare("INSERT INTO competency_requests(teacher_user_id,category_id,subcategory_id,proposal_text_de,proposal_text_en,status,admin_note,reviewed_by_user_id,reviewed_at,approved_competency_id) VALUES (?,?,?,?,?,'pending',?,?,?,?)");
    $meta = json_encode(['grade_levels'=>$grades,'is_required'=>$isRequired], JSON_UNESCAPED_UNICODE);
    $stIns->execute([(int)current_user()['id'], $cat, $subId, $textDe, $textEn, $meta, null, null, null]);
    $requestId = (int)$pdo->lastInsertId();

    $adminLink = absolute_url('admin/competencies.php?request_id=' . $requestId . '#competency-request-' . $requestId);
    $teacherName = trim((string)(current_user()['display_name'] ?? current_user()['email'] ?? 'Lehrkraft'));
    $subLabel = $subId ? (string)($subRow['name_de'] ?? '') : 'Ohne Unterkategorie';
    $gradesTxt = $grades ? implode(', ', $grades) : '—';
    $reqTxt = $isRequired ? 'Pflicht' : 'Optional';
    $createdTxt = (new DateTimeImmutable('now', user_timezone()))->format('d.m.Y H:i');
    $subject = 'Neuer Kompetenzantrag zur Prüfung';
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.45">'
      . '<h2 style="margin:0 0 12px">Neuer Kompetenzantrag zur Prüfung</h2>'
      . '<p>Eine Lehrkraft hat einen neuen Kompetenzantrag eingereicht.</p>'
      . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0">'
      . '<tr><td><strong>Lehrkraft</strong></td><td>' . h($teacherName) . '</td></tr>'
      . '<tr><td><strong>Kategorie</strong></td><td>' . h((string)$catRow['name_de']) . '</td></tr>'
      . '<tr><td><strong>Unterkategorie</strong></td><td>' . h($subLabel) . '</td></tr>'
      . '<tr><td><strong>Kompetenz (DE)</strong></td><td>' . nl2br(h($textDe)) . '</td></tr>'
      . '<tr><td><strong>Kompetenz (EN)</strong></td><td>' . nl2br(h($textEn !== '' ? $textEn : '—')) . '</td></tr>'
      . '<tr><td><strong>Klassenstufen</strong></td><td>' . h($gradesTxt) . '</td></tr>'
      . '<tr><td><strong>Typ</strong></td><td>' . h($reqTxt) . '</td></tr>'
      . '<tr><td><strong>Eingereicht am</strong></td><td>' . h($createdTxt) . '</td></tr>'
      . '</table>'
      . '<p style="margin-top:14px"><a href="' . h($adminLink) . '" style="display:inline-block;background:#0b57d0;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px;">Antrag in der Administration öffnen</a></p>'
      . '<p style="font-size:12px;color:#64748b">Direktlink: <a href="' . h($adminLink) . '">' . h($adminLink) . '</a></p>'
      . '</div>';

    $admins = $pdo->query("SELECT email FROM users WHERE role='admin' AND is_active=1 AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($admins as $m) { if (filter_var((string)$m, FILTER_VALIDATE_EMAIL)) @send_email((string)$m, $subject, $html); }

    $ok = 'Antrag wurde gesendet. Die Administration prüft den Vorschlag, bevor er übernommen wird.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

render_teacher_header('Kompetenz-Anträge');
?>
<div class="card">
  <h1>Neue Kompetenz beantragen</h1>
  <p class="muted">Der Antrag wird von der Administration geprüft, bevor die Kompetenz in Vorlagen übernommen wird.</p>
  <?php if($ok): ?><div class="alert success" style="margin-bottom:10px;"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert danger" style="margin-bottom:10px;"><?=h($err)?></div><?php endif; ?>

  <form method="post" style="display:flex;flex-direction:column;gap:14px;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">

    <div class="card" style="padding:12px;">
      <h3 style="margin-top:0;">Zuordnung</h3>
      <label>Kategorie</label>
      <select class="input" name="category_id" id="category_id" required>
        <option value="">Bitte wählen…</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name_de']) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="margin-top:8px;">Unterkategorie</label>
      <select class="input" name="subcategory_id" id="subcategory_id">
        <option value="">Ohne Unterkategorie</option>
      </select>
      <small id="sub_hint" class="muted">Bitte zuerst eine Kategorie wählen.</small>
    </div>

    <div class="card" style="padding:12px;">
      <h3 style="margin-top:0;">Kompetenztext</h3>
      <label>Deutsch (Pflicht)</label>
      <textarea class="input" name="proposal_text_de" required rows="4"></textarea>
      <label style="margin-top:8px;">Englisch (optional)</label>
      <textarea class="input" name="proposal_text_en" rows="3"></textarea>
    </div>

    <div class="card" style="padding:12px;">
      <h3 style="margin-top:0;">Optionen</h3>
      <label><input type="checkbox" name="is_required" value="1"> Pflichtkompetenz</label>
      <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;">
        <?php foreach([1,2,3,4] as $g): ?><label><input type="checkbox" name="grade_levels[]" value="<?= $g ?>"> Klasse <?= $g ?></label><?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="btn" type="submit">Antrag senden</button>
    </div>
  </form>
</div>
<script>
const SUBS_BY_CAT = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;
const catEl = document.getElementById('category_id');
const subEl = document.getElementById('subcategory_id');
const hint = document.getElementById('sub_hint');
function updateSubOptions(){
  const catId = String(catEl.value || '');
  const subs = SUBS_BY_CAT[catId] || [];
  subEl.innerHTML = '';
  const optNone = document.createElement('option');
  optNone.value = '';
  optNone.textContent = 'Ohne Unterkategorie';
  subEl.appendChild(optNone);
  subs.forEach(s=>{
    const o=document.createElement('option');
    o.value=String(s.id);
    o.textContent=String(s.name_de || '');
    subEl.appendChild(o);
  });
  hint.textContent = catId === '' ? 'Bitte zuerst eine Kategorie wählen.' : (subs.length ? 'Nur Unterkategorien der gewählten Kategorie sind auswählbar.' : 'Für diese Kategorie sind keine Unterkategorien vorhanden.');
}
catEl.addEventListener('change', updateSubOptions);
updateSubOptions();
</script>
<?php render_teacher_footer();
