<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$ok = null; $err = null;
$teacherId = (int)(current_user()['id'] ?? 0);
$lang = ui_lang();

function competency_request_label_for_lang(?string $de, ?string $en, string $lang): string {
  $primary = $lang === 'en' ? trim((string)$en) : trim((string)$de);
  if ($primary !== '') return $primary;
  $fallback = $lang === 'en' ? trim((string)$de) : trim((string)$en);
  return $fallback !== '' ? $fallback : '—';
}

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

    if ($cat <= 0) throw new RuntimeException(t('competency_requests.error.category_required'));
    if ($textDe === '' || $textEn === '') throw new RuntimeException(t('competency_requests.error.text_required'));

    $stCat = $pdo->prepare("SELECT id,name_de,name_en FROM competency_categories WHERE id=? LIMIT 1");
    $stCat->execute([$cat]);
    $catRow = $stCat->fetch(PDO::FETCH_ASSOC);
    if (!$catRow) throw new RuntimeException(t('competency_requests.error.category_not_found'));

    $subId = null; $subRow = null;
    if ($sub > 0) {
      $stSub = $pdo->prepare("SELECT id,name_de,name_en FROM competency_subcategories WHERE id=? AND category_id=? LIMIT 1");
      $stSub->execute([$sub, $cat]);
      $subRow = $stSub->fetch(PDO::FETCH_ASSOC);
      if (!$subRow) throw new RuntimeException(t('competency_requests.error.subcategory_mismatch'));
      $subId = (int)$subRow['id'];
    }

    $meta = json_encode(['grade_levels'=>$grades,'is_required'=>$isRequired], JSON_UNESCAPED_UNICODE);
    $stIns = $pdo->prepare("INSERT INTO competency_requests(teacher_user_id,category_id,subcategory_id,proposal_text_de,proposal_text_en,status,admin_note,reviewed_by_user_id,reviewed_at,approved_competency_id) VALUES (?,?,?,?,?,'pending',?,?,?,?)");
    $stIns->execute([$teacherId, $cat, $subId, $textDe, $textEn, $meta, null, null, null]);
    $requestId = (int)$pdo->lastInsertId();

    // Admin notification after successful insert (must not block request submission)
    try {
      $adminLink = absolute_url('admin/competencies.php?request_id=' . $requestId . '#competency-request-' . $requestId);
      $teacherName = trim((string)(current_user()['display_name'] ?? current_user()['email'] ?? t('competency_requests.email.teacher_fallback')));
      $subLabel = $subId ? competency_request_label_for_lang($subRow['name_de'] ?? '', $subRow['name_en'] ?? '', $lang) : t('competency_requests.no_subcategory');
      $gradesTxt = $grades ? implode(', ', $grades) : '—';
      $reqTxt = $isRequired ? t('competency_requests.required') : t('competency_requests.optional');
      $createdTxt = (new DateTimeImmutable('now', user_timezone()))->format('d.m.Y H:i');

      $subject = t('competency_requests.email.subject');
      $html = '<div style="font-family:Arial,sans-serif;line-height:1.45">'
        . '<h2 style="margin:0 0 12px">' . h(t('competency_requests.email.subject')) . '</h2>'
        . '<p>' . h(t('competency_requests.email.intro')) . '</p>'
        . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0">'
        . '<tr><td><strong>' . h(t('competency_requests.email.teacher')) . '</strong></td><td>' . h($teacherName) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.category')) . '</strong></td><td>' . h(competency_request_label_for_lang($catRow['name_de'] ?? '', $catRow['name_en'] ?? '', $lang)) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.subcategory')) . '</strong></td><td>' . h($subLabel) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.competency_de')) . '</strong></td><td>' . h($textDe) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.competency_en')) . '</strong></td><td>' . h($textEn) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.grade_levels')) . '</strong></td><td>' . h($gradesTxt) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.type')) . '</strong></td><td>' . h($reqTxt) . '</td></tr>'
        . '<tr><td><strong>' . h(t('competency_requests.submitted_at')) . '</strong></td><td>' . h($createdTxt) . '</td></tr>'
        . '</table>'
        . '<p style="margin-top:14px"><a href="' . h($adminLink) . '" style="display:inline-block;background:#0b57d0;color:#ffffff !important;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700;">' . h(t('competency_requests.email.open_admin')) . '</a></p>'
        . '<p style="font-size:12px;color:#64748b">' . h(t('competency_requests.email.direct_link')) . ': <a href="' . h($adminLink) . '">' . h($adminLink) . '</a></p>'
        . '</div>';

      $admins = $pdo->query("SELECT email FROM users WHERE role='admin' AND is_active=1 AND deleted_at IS NULL AND email IS NOT NULL AND email<>''")->fetchAll(PDO::FETCH_COLUMN) ?: [];
      if (!$admins) {
        error_log('[competency request mail] no admin recipients found');
      } else {
        foreach ($admins as $m) {
          $mail = (string)$m;
          if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            error_log('[competency request mail] invalid admin email skipped: ' . $mail);
            continue;
          }
          $sent = @send_email($mail, $subject, $html);
          if (!$sent) {
            error_log('[competency request mail] failed for recipient: ' . $mail);
          }
        }
      }
    } catch (Throwable $mailError) {
      error_log('[competency request mail] exception: ' . $mailError->getMessage());
    }

    $ok = t('competency_requests.success');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$myRequests = $pdo->prepare("SELECT cr.*, cat.name_de AS cat_de, cat.name_en AS cat_en, sub.name_de AS sub_de, sub.name_en AS sub_en FROM competency_requests cr LEFT JOIN competency_categories cat ON cat.id=cr.category_id LEFT JOIN competency_subcategories sub ON sub.id=cr.subcategory_id WHERE cr.teacher_user_id=? ORDER BY cr.created_at DESC, cr.id DESC");
$myRequests->execute([$teacherId]);
$requests = $myRequests->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_teacher_header(t('competency_requests.title'));
?>
<div class="card">
  <h1><?=h(t('competency_requests.new_title'))?></h1>
  <p class="muted"><?=h(t('competency_requests.intro'))?></p>
  <?php if($ok): ?><div class="alert success" style="margin-bottom:10px;"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert danger" style="margin-bottom:10px;"><?=h($err)?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">

    <div class="form-group">
      <label class="form-label"><?=h(t('competency_requests.category'))?></label>
      <select class="input" name="category_id" id="category_id" required>
        <option value=""><?=h(t('competency_requests.choose'))?></option>
        <?php foreach($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(competency_request_label_for_lang($c['name_de'] ?? '', $c['name_en'] ?? '', $lang)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label"><?=h(t('competency_requests.subcategory'))?></label>
      <select class="input" name="subcategory_id" id="subcategory_id"><option value=""><?=h(t('competency_requests.no_subcategory'))?></option></select>
      <small id="sub_hint" class="muted"><?=h(t('competency_requests.hint.choose_category_first'))?></small>
    </div>
    <div class="form-group">
      <label class="form-label"><?=h(t('competency_requests.competency_de'))?></label>
      <input class="input" type="text" name="proposal_text_de" required>
    </div>
    <div class="form-group">
      <label class="form-label"><?=h(t('competency_requests.competency_en'))?></label>
      <input class="input" type="text" name="proposal_text_en" required>
    </div>
    <div class="form-group">
      <label class="choice-chip required-choice"><input type="checkbox" name="is_required" value="1"><span>🔒 <?=h(t('competency_requests.required_competency'))?></span></label>
    </div>
    <div class="form-group">
      <div class="form-label"><?=h(t('competency_requests.grade_levels'))?></div>
      <div class="choice-row"><?php foreach([1,2,3,4] as $g): ?><label class="choice-chip grade-choice grade-<?= $g ?>"><input type="checkbox" name="grade_levels[]" value="<?= $g ?>"><span><?= $g ?></span></label><?php endforeach; ?></div>
    </div>
    <div style="display:flex;justify-content:flex-end;"><button class="btn" type="submit"><?=h(t('competency_requests.submit'))?></button></div>
  </form>
</div>

<div class="card" style="margin-top:14px;">
  <h2><?=h(t('competency_requests.my_requests'))?></h2>
  <?php if(!$requests): ?><p class="muted"><?=h(t('competency_requests.empty'))?></p><?php endif; ?>
  <?php foreach($requests as $r):
    $meta = json_decode((string)($r['admin_note'] ?? ''), true); if(!is_array($meta)) $meta=[];
    $status=(string)($r['status']??'pending');
    $stLabel=$status==='approved'?t('competency_requests.status.approved'):($status==='rejected'?t('competency_requests.status.rejected'):t('competency_requests.status.pending'));
    $stColor=$status==='approved'?'#067647':($status==='rejected'?'#b42318':'#0b57d0');
    $grades=(array)($meta['grade_levels']??[]);
    $comment=trim((string)($meta['comment']??''));
    $catDisplay = competency_request_label_for_lang($r['cat_de'] ?? '', $r['cat_en'] ?? '', $lang);
    $subDisplay = ((trim((string)($r['sub_de'] ?? '')) !== '') || (trim((string)($r['sub_en'] ?? '')) !== ''))
      ? competency_request_label_for_lang($r['sub_de'] ?? '', $r['sub_en'] ?? '', $lang)
      : t('competency_requests.no_subcategory');
  ?>
    <div class="card" style="margin:10px 0;border-left:4px solid <?=h($stColor)?>;">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
        <strong><?=h($stLabel)?></strong>
        <span class="chip" style="background:<?=h($stColor)?>;color:#fff;"><?=h($stLabel)?></span>
      </div>
      <div class="muted" style="margin-top:6px;"><?=h(t('competency_requests.category'))?>: <?=h($catDisplay)?> · <?=h(t('competency_requests.subcategory'))?>: <?=h($subDisplay)?></div>
      <div style="margin-top:8px;"><strong>DE:</strong> <?=h((string)$r['proposal_text_de'])?></div>
      <div><strong>EN:</strong> <?=h((string)($r['proposal_text_en']?:'—'))?></div>
      <div style="margin-top:6px;"><?=h(t('competency_requests.grade_levels'))?>:
        <?php if($grades): foreach($grades as $g): ?><span class="chip grade-chip grade-<?= (int)$g ?>"><?= (int)$g ?></span><?php endforeach; else: ?> <span class="muted">—</span><?php endif; ?>
        <span class="chip"><?= ((int)($meta['is_required']??0)===1?h(t('competency_requests.required')):h(t('competency_requests.optional'))) ?></span>
      </div>
      <div class="muted" style="margin-top:6px;"><?=h(t('competency_requests.created'))?>: <?=h((string)$r['created_at'])?><?php if(!empty($r['reviewed_at'])): ?> · <?=h(t('competency_requests.reviewed'))?>: <?=h((string)$r['reviewed_at'])?><?php endif; ?></div>
      <?php if($comment!==''): ?><div style="margin-top:8px;padding:8px 10px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;"><strong><?=h(t('competency_requests.admin_comment'))?>:</strong><br><?=nl2br(h($comment))?></div><?php endif; ?>
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
const I18N = <?= json_encode([
  'lang' => $lang,
  'noSubcategory' => t('competency_requests.no_subcategory'),
  'chooseCategoryFirst' => t('competency_requests.hint.choose_category_first'),
  'onlyCategorySubcategories' => t('competency_requests.hint.only_category_subcategories'),
  'noSubcategoriesForCategory' => t('competency_requests.hint.no_subcategories_for_category'),
], JSON_UNESCAPED_UNICODE) ?>;
const SUBS_BY_CAT = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;
const catEl = document.getElementById('category_id');
const subEl = document.getElementById('subcategory_id');
const hint = document.getElementById('sub_hint');
function updateSubOptions(){
  const catId = String(catEl.value || '');
  const subs = SUBS_BY_CAT[catId] || [];
  subEl.innerHTML = '';
  const optNone = document.createElement('option'); optNone.value=''; optNone.textContent=I18N.noSubcategory; subEl.appendChild(optNone);
  subs.forEach(s=>{const o=document.createElement('option'); o.value=String(s.id); o.textContent=String((I18N.lang === 'en' ? (s.name_en || s.name_de) : (s.name_de || s.name_en)) || ''); subEl.appendChild(o);});
  hint.textContent = catId === '' ? I18N.chooseCategoryFirst : (subs.length ? I18N.onlyCategorySubcategories : I18N.noSubcategoriesForCategory);
}
catEl.addEventListener('change', updateSubOptions); updateSubOptions();
</script>
<?php render_teacher_footer();
