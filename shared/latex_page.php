<?php
declare(strict_types=1);

function db_competency_sections(PDO $pdo, int $gradeLevel): array {
    $sql = "SELECT c.id, c.text_de, c.text_en, COALESCE(c.display_type,'rated') AS display_type, c.is_required, c.code, c.subcategory_id, COALESCE(c.category_id, s.category_id) AS category_id, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en FROM competencies c LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id, s.category_id) INNER JOIN competency_grade_levels cgl ON cgl.competency_id=c.id WHERE c.is_active=1 AND cgl.grade_level=? ORDER BY cat.sort_order, cat.id, CASE WHEN c.subcategory_id IS NULL OR c.subcategory_id=0 THEN 0 ELSE 1 END, s.sort_order, s.id, c.sort_order, c.id";
    $st = $pdo->prepare($sql);
    $st->execute([$gradeLevel]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sections = [];
    foreach ($rows as $r) {
        $catId = (int)($r['category_id'] ?? 0);
        if ($catId <= 0) { continue; }
        if (!isset($sections[$catId])) {
            $sections[$catId] = [
                'de' => (string)($r['cat_de'] ?? ''),
                'en' => (string)($r['cat_en'] ?? ''),
                'direct' => [],
                'subs' => [],
            ];
        }

        $subId = (int)($r['subcategory_id'] ?? 0);
        if ($subId <= 0) {
            $sections[$catId]['direct'][] = $r;
            continue;
        }

        if (!isset($sections[$catId]['subs'][$subId])) {
            $sections[$catId]['subs'][$subId] = [
                'id' => $subId,
                'de' => (string)($r['sub_de'] ?? ''),
                'en' => (string)($r['sub_en'] ?? ''),
                'items' => [],
            ];
        }
        $sections[$catId]['subs'][$subId]['items'][] = $r;
    }
    return $sections;
}

function render_competency_placeholder_html(string $text): string {
    $escaped = h($text);
    $withCheckboxes = preg_replace(
        '/\[checkbox(?:\:[^\]]*)?\]/i',
        '<span class="inline-checkbox-placeholder" title="[checkbox]" aria-label="' . h(t('latex.inline.checkbox_placeholder')) . '"></span>',
        $escaped
    ) ?? $escaped;
    return preg_replace(
        '/\[vline\]/i',
        '<span class="inline-vline-placeholder" title="[vline]" aria-label="' . h(t('latex.inline.vertical_line')) . '"></span>',
        $withCheckboxes
    ) ?? $withCheckboxes;
}

function render_competency_placeholder_html_with_space(string $text): string {
    $base = render_competency_placeholder_html($text);
    return preg_replace(
        '/\[space\s*=\s*[^\]]+\]/i',
        '<span class="inline-space-placeholder" title="[space]" aria-label="' . h(t('latex.inline.space')) . '"></span>',
        $base
    ) ?? $base;
}

require_once __DIR__ . '/latex_layout_templates.php';
$pdo = db();
ensure_default_latex_layout_template($pdo);
$layoutTemplates = get_latex_layout_templates($pdo, true);
$defaultLayout = get_default_latex_layout_template($pdo);
$defaultLayoutId = (int)($defaultLayout['id'] ?? ($layoutTemplates[0]['id'] ?? 0));
$selectedGrade = (int)($_GET['grade_level'] ?? $_POST['grade_level'] ?? 1);
if ($selectedGrade < 1 || $selectedGrade > 4) $selectedGrade = 1;
$sectionsDb = db_competency_sections($pdo, $selectedGrade);
?>

<h1><?= h(t('latex.title')) ?></h1>
<p><?= h(t('latex.desc')) ?></p>

<form id="pdfForm" method="post" action="<?= h($latexBuildUrl) ?>">
<input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="source" value="db">
<input type="hidden" name="grade_level" value="<?= h((string)$selectedGrade) ?>">
<div class="card" style="padding:12px;margin:12px 0;">
  <label><strong><?= h(t('latex.grade_level')) ?></strong></label>
  <select class="input" id="gradeLevelSelect">
    <?php for($g=1;$g<=4;$g++): ?><option value="<?= $g ?>" <?= $selectedGrade===$g?'selected':'' ?>><?= $g ?></option><?php endfor; ?>
  </select>
</div>
<div class="card" style="padding:12px;margin:12px 0;">
  <strong><?= h(t('latex.special_sections')) ?></strong>
  <label style="display:block;margin-top:8px;">
    <input type="hidden" name="show_sel" value="0">
    <input type="checkbox" name="show_sel" value="1" checked > <?= h(t('latex.option.show_sel')) ?>
  </label>
  <label style="display:block;margin-top:6px;">
    <input type="hidden" name="show_ag" value="0">
    <input type="checkbox" name="show_ag" value="1" checked > <?= h(t('latex.option.show_ag')) ?>
  </label>
  <label style="display:block;margin-top:6px;">
    <input type="hidden" name="enable_student_teacher_ratings" value="0">
    <input type="checkbox" name="enable_student_teacher_ratings" value="1" > <?= h(t('latex.option.student_teacher_ratings')) ?>
  </label>
  <label style="display:block;margin-top:6px;">
    <input type="hidden" name="export_rcff" value="0">
    <input type="checkbox" name="export_rcff" value="1" > <?= h(t('latex.option.export_rcff')) ?>
  </label>
  <?php if (!empty($allowTemplatePackage)): ?>
  <label style="display:block;margin-top:10px;padding-top:8px;border-top:1px solid var(--border);">
    <input type="hidden" name="create_template_package" value="0">
    <input type="checkbox" name="create_template_package" value="1" > <?= h(t('latex.option.prepare_template_package')) ?>
  </label>
  <p class="muted" style="margin:6px 0 0;"><?= h(t('latex.option.prepare_template_package_help')) ?></p>
  <?php endif; ?>
  <?php if (!empty($allowTeacherTemplateSubmission)): ?>
  <label style="display:block;margin-top:10px;padding-top:8px;border-top:1px solid var(--border);">
    <input type="hidden" name="submit_template_package_to_admin" value="0">
    <input type="checkbox" name="submit_template_package_to_admin" value="1" > <?= h(t('latex.option.submit_template_package')) ?>
  </label>
  <p class="muted" style="margin:6px 0 0;"><?= h(t('latex.option.submit_template_package_help')) ?></p>
  <?php endif; ?>
</div>

<?php
  $ltpList = !empty($allowLatexTemplatePackageSelection) && is_array($latexTemplatePackages ?? null) ? $latexTemplatePackages : [];
  $defaultPackageId = 0;
  foreach ($ltpList as $pkg) {
      if ((int)($pkg['is_default'] ?? 0) === 1) { $defaultPackageId = (int)($pkg['id'] ?? 0); break; }
  }
  $selectedTemplateSource = $defaultPackageId > 0 ? ('package:' . $defaultPackageId) : ($defaultLayoutId > 0 ? ('layout:' . $defaultLayoutId) : 'system');
?>
<div class="card" style="padding:12px;margin:12px 0;">
  <label for="template_source"><strong><?=h(t('latex_templates.template_source.label'))?></strong></label>
  <select class="input" name="template_source" id="template_source">
    <optgroup label="<?=h(t('latex_templates.template_source.system_group'))?>">
      <option value="system" <?= $selectedTemplateSource === 'system' ? 'selected' : '' ?>><?=h(t('latex_templates.template_source.system'))?></option>
      <?php foreach ($layoutTemplates as $tpl): ?>
        <?php $sourceValue = 'layout:' . (int)$tpl['id']; ?>
        <option value="<?= h($sourceValue) ?>" <?= $selectedTemplateSource === $sourceValue ? 'selected' : '' ?>><?= h(sprintf(t('latex_templates.template_source.layout'), (string)$tpl['display_name'])) ?> (<?= h((string)$tpl['key_name']) ?>)</option>
      <?php endforeach; ?>
    </optgroup>
    <?php if ($ltpList): ?>
      <optgroup label="<?=h(t('latex_templates.template_source.package_group'))?>">
        <?php foreach ($ltpList as $pkg): ?>
          <?php $sourceValue = 'package:' . (int)$pkg['id']; ?>
          <option value="<?= h($sourceValue) ?>" <?= $selectedTemplateSource === $sourceValue ? 'selected' : '' ?>><?= h(sprintf(t('latex_templates.template_source.package'), (string)$pkg['name'])) ?><?= ((int)($pkg['is_default'] ?? 0) === 1) ? ' (' . h(t('latex_templates.table.default')) . ')' : '' ?></option>
        <?php endforeach; ?>
      </optgroup>
    <?php endif; ?>
  </select>
  <p class="muted" style="margin:6px 0 0;"><?=h(t('latex_templates.template_source.help'))?></p>
  <?php if (!$ltpList && !empty($allowLatexTemplatePackageSelection)): ?><p class="muted" style="margin:6px 0 0;"><?= h(t('latex_templates.packages.empty')) ?></p><?php endif; ?>
</div>
<div style="display:flex;gap:8px;margin:8px 0 12px;">
  <button type="button" class="btn" id="collapseAllBtn"><?= h(t('latex.action.collapse_all')) ?></button>
  <button type="button" class="btn" id="expandAllBtn"><?= h(t('latex.action.expand_all')) ?></button>
</div>
<?php foreach ($sectionsDb as $sectionId => $section): ?>
  <?php
    $hasRequired = false;
    foreach (($section['direct'] ?? []) as $it) { if ((int)($it['is_required'] ?? 0) === 1) { $hasRequired = true; break; } }
    if (!$hasRequired) { foreach (($section['subs'] ?? []) as $sub) { foreach (($sub['items'] ?? []) as $it) { if ((int)($it['is_required'] ?? 0) === 1) { $hasRequired = true; break 2; } } } }
  ?>
  <details class="card cat-details" data-cat-id="<?= h((string)$sectionId) ?>" data-has-required="<?= $hasRequired ? '1' : '0' ?>" style="padding:10px 14px; margin:12px 0;" open>
    <input type="hidden" name="cat_ids[]" value="<?= h((string)$sectionId) ?>">
    <summary><h2 class="cat-title"><?= h($section['de']) ?> | <span style="font-style:italic;color:#666;"><?= h((string)($section['en'] ?? '')) ?></span></h2></summary>
    <label style="display:block; margin:8px 0 8px 0; font-weight:600;">
      <input type="checkbox" class="category-toggle" name="cat_active[]" value="<?= h((string)$sectionId) ?>" data-cat-id="<?= h((string)$sectionId) ?>" checked>
      <?= h(t('latex.category_active')) ?>
    </label>
    <div class="category-warning" data-cat-warning="<?= h((string)$sectionId) ?>" style="display:none; margin:0 0 10px; padding:8px 10px; border-radius:8px; background:#fdecec; color:#a61b1b; border:1px solid #f4b4b4;">
      <?= h(t('latex.category_required_warning')) ?>
    </div>
    <label style="display:block; margin:8px 0 12px 0;">
      <input type="checkbox" name="pagebreaks[]" value="<?= h((string)$sectionId) ?>">
      <?= h(t('latex.pagebreak_before_category')) ?>
    </label>
    <label style="display:block; margin:8px 0 12px 0;">
      <input type="checkbox" name="grade_field_cats[]" value="<?= h((string)$sectionId) ?>">
      <?= h(t('latex.grade_field_in_header')) ?>
    </label>
    <div class="category-body" data-cat-body="<?= h((string)$sectionId) ?>">
    <?php if (!empty($section['direct'])): ?>
      <details class="sub-details" style="margin-top:12px; margin-left:12px;" open>
        <summary><strong><?= h(t('latex.without_subcategory')) ?></strong></summary>
        <?php foreach ($section['direct'] as $item): ?>
          <label class="<?= ((int)($item['is_required'] ?? 0) === 1) ? 'required-skill-row' : '' ?>" style="display:block; margin:8px 0 8px 18px;" title="<?= ((int)($item['is_required'] ?? 0) === 1) ? h(t('latex.required_skill_title')) : '' ?>">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled data-required-skill="1"' : 'checked' ?> <?= ((int)($item['is_required'] ?? 0) === 1) ? 'aria-disabled="true"' : '' ?>>
            <?php if ((int)($item['is_required'] ?? 0) === 1): ?><input type="hidden" name="skills[]" value="<?= h((string)$item['code']) ?>" data-required-hidden="1"><span class="required-badge"><?= h(t('latex.required_badge')) ?></span><?php endif; ?>
            <?= render_competency_placeholder_html_with_space((string)$item['text_de']) ?><?php if(((string)($item['display_type'] ?? 'rated'))==='info'): ?> <span class="info-row-badge"><?= h(t('latex.info_row_badge')) ?></span><?php endif; ?>
            <br><span style="font-style:italic;color:#666;"><?= render_competency_placeholder_html_with_space((string)($item['text_en'] ?? '')) ?></span>
          </label>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>

    <?php foreach (($section['subs'] ?? []) as $sub): ?>
      <?php
        $subId = (int)($sub['id'] ?? 0);
        $subHasRequired = false;
        foreach (($sub['items'] ?? []) as $it) { if ((int)($it['is_required'] ?? 0) === 1) { $subHasRequired = true; break; } }
      ?>
      <details class="sub-details" data-sub-id="<?= h((string)$subId) ?>" data-parent-cat-id="<?= h((string)$sectionId) ?>" data-has-required="<?= $subHasRequired ? '1' : '0' ?>" style="margin-top:12px; margin-left:12px;" open>
        <input type="hidden" name="sub_ids[]" value="<?= h((string)$subId) ?>">
        <summary><strong><?= h((string)$sub['de']) ?></strong> | <span style="font-style:italic;color:#666;"><?= h((string)($sub['en'] ?? '')) ?></span></summary>
        <label style="display:block; margin:8px 0 8px 18px; font-weight:600;">
          <input type="checkbox" class="subcategory-toggle" name="sub_active[]" value="<?= h((string)$subId) ?>" data-sub-id="<?= h((string)$subId) ?>" data-parent-cat-id="<?= h((string)$sectionId) ?>" checked>
          <?= h(t('latex_templates.subcategory_active')) ?>
        </label>
        <div class="subcategory-warning" data-sub-warning="<?= h((string)$subId) ?>" style="display:none; margin:0 0 10px 18px; padding:8px 10px; border-radius:8px; background:#fdecec; color:#a61b1b; border:1px solid #f4b4b4;">
          <?= h(t('latex_templates.subcategory_required_warning')) ?>
        </div>
        <div class="subcategory-body" data-sub-body="<?= h((string)$subId) ?>">
        <?php foreach (($sub['items'] ?? []) as $item): ?>
          <label class="<?= ((int)($item['is_required'] ?? 0) === 1) ? 'required-skill-row' : '' ?>" style="display:block; margin:8px 0 8px 18px;" title="<?= ((int)($item['is_required'] ?? 0) === 1) ? h(t('latex.required_skill_title')) : '' ?>">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled data-required-skill="1"' : 'checked' ?> <?= ((int)($item['is_required'] ?? 0) === 1) ? 'aria-disabled="true"' : '' ?>>
            <?php if ((int)($item['is_required'] ?? 0) === 1): ?><input type="hidden" name="skills[]" value="<?= h((string)$item['code']) ?>" data-required-hidden="1"><span class="required-badge"><?= h(t('latex.required_badge')) ?></span><?php endif; ?>
            <?= render_competency_placeholder_html_with_space((string)$item['text_de']) ?><?php if(((string)($item['display_type'] ?? 'rated'))==='info'): ?> <span class="info-row-badge"><?= h(t('latex.info_row_badge')) ?></span><?php endif; ?>
            <br><span style="font-style:italic;color:#666;"><?= render_competency_placeholder_html_with_space((string)($item['text_en'] ?? '')) ?></span>
          </label>
        <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>

<button class="btn" type="submit" id="createPdfButton"><?= h(t('latex.action.create_pdf')) ?></button>
</form>

<style>
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  .cat-title{display:inline;margin:0;font-size:1.1rem;font-weight:700;line-height:1.3}
  .sub-details.is-disabled{opacity:.62}
  .required-skill-row{opacity:.95}
  .required-skill-row input[type="checkbox"]{cursor:not-allowed}
  .required-badge{display:inline-block;margin:0 8px 0 6px;padding:1px 8px;border-radius:999px;background:#eef3ff;color:#2f4d8f;font-size:11px;font-weight:700;vertical-align:middle}
  .info-row-badge{display:inline-block;margin:0 8px 0 6px;padding:1px 8px;border-radius:999px;background:#f0f4ff;color:#334155;font-size:11px;font-weight:700;vertical-align:middle}
  .inline-checkbox-placeholder{display:inline-block;width:.8em;height:.8em;border:1.4px solid currentColor;border-radius:2px;vertical-align:-.08em;margin:0 .18em;box-sizing:border-box;opacity:.85}
  .inline-vline-placeholder{display:inline-block;height:1.1em;border-left:1px solid currentColor;margin:0 .45em;vertical-align:-.15em;opacity:.75}
  .inline-space-placeholder{display:inline-block;width:1.5em;border-bottom:1px dotted currentColor;opacity:.35;margin:0 .15em;vertical-align:.15em}
</style>
<div id="pdfLoadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center;">
  <div class="card" style="padding:18px 22px; font-weight:600; display:flex; gap:12px; align-items:center;">
    <span style="width:18px; height:18px; border:3px solid #d0d7de; border-top-color:#0b57d0; border-radius:50%; display:inline-block; animation:spin .8s linear infinite;"></span>
    <span><?= h(t('latex.loading')) ?></span>
  </div>
</div>

<div id="templatePackageStatus" class="card" style="display:none; margin-top:16px;"></div>

<div id="pdfPreviewWrap" style="display:none; margin-top:24px;">
  <iframe id="pdfPreview" style="width:100%; height:90vh;"></iframe>
</div>

<div id="pdfDebug" class="card" style="display:none; margin-top:16px; border-left:4px solid #b42318;">
  <h3 style="margin-top:0;"><?= h(t('latex.error_details')) ?></h3>
  <pre id="pdfDebugText" style="white-space:pre-wrap; overflow:auto; max-height:280px;"></pre>
</div>

<script>
const form = document.getElementById('pdfForm');
const pdfPreview = document.getElementById('pdfPreview');
const pdfPreviewWrap = document.getElementById('pdfPreviewWrap');
const createPdfButton = document.getElementById('createPdfButton');
const pdfLoadingOverlay = document.getElementById('pdfLoadingOverlay');
const pdfDebug = document.getElementById('pdfDebug');
const pdfDebugText = document.getElementById('pdfDebugText');
const templatePackageStatus = document.getElementById('templatePackageStatus');
let currentPdfUrl = null;

function setSkillInputsState(container, active){
  container.querySelectorAll('input[name="skills[]"]').forEach(el=>{
    const isRequiredCheckbox = el.dataset.requiredSkill === '1';
    const isRequiredHidden = el.dataset.requiredHidden === '1';
    if (isRequiredCheckbox) {
      el.checked = true;
      el.disabled = true;
      return;
    }
    if (isRequiredHidden) {
      el.disabled = !active;
      return;
    }
    el.disabled = !active;
  });
}

function applySubcategoryState(subId){
  const sub = document.querySelector(`.sub-details[data-sub-id="${subId}"]`);
  if(!sub) return;
  const cat = sub.closest('.cat-details');
  const catActive = !!cat?.querySelector('.category-toggle')?.checked;
  const toggle = sub.querySelector('.subcategory-toggle');
  const subActive = !!toggle?.checked;
  const effectiveActive = catActive && subActive;
  const body = sub.querySelector(`[data-sub-body="${subId}"]`);
  const warn = sub.querySelector(`[data-sub-warning="${subId}"]`);
  const hasReq = sub.dataset.hasRequired === '1';
  sub.classList.toggle('is-disabled', !effectiveActive);
  if(toggle) toggle.disabled = !catActive;
  if(body) body.style.display = effectiveActive ? '' : 'none';
  if(warn) warn.style.display = (catActive && !subActive && hasReq) ? 'block' : 'none';
  setSkillInputsState(sub, effectiveActive);
}

function applyCategoryState(catId){
  const root = document.querySelector(`.cat-details[data-cat-id="${catId}"]`);
  if(!root) return;
  const toggle = root.querySelector('.category-toggle');
  const active = !!toggle?.checked;
  const body = root.querySelector(`[data-cat-body="${catId}"]`);
  const warn = root.querySelector(`[data-cat-warning="${catId}"]`);
  const hasReq = root.dataset.hasRequired === '1';
  root.style.opacity = active ? '1' : '.62';
  if(body) body.style.display = active ? '' : 'none';
  if(warn) warn.style.display = (!active && hasReq) ? 'block' : 'none';
  root.querySelectorAll(':scope > input[name="pagebreaks[]"], :scope > label input[name="pagebreaks[]"]').forEach(el=>{ el.disabled = !active; });
  root.querySelectorAll(':scope > .category-body > details:not([data-sub-id])').forEach(direct=>{
    setSkillInputsState(direct, active);
  });
  root.querySelectorAll('.subcategory-toggle').forEach(subToggle=>{
    applySubcategoryState(subToggle.dataset.subId);
  });
}

document.getElementById('gradeLevelSelect').addEventListener('change', (e) => {
  const u = new URL(window.location.href);
  u.searchParams.set('grade_level', e.target.value);
  window.location.href = u.toString();
});

document.querySelectorAll('.subcategory-toggle').forEach(cb=>{
  cb.addEventListener('change',()=>applySubcategoryState(cb.dataset.subId));
});
document.querySelectorAll('.category-toggle').forEach(cb=>{
  cb.addEventListener('change',()=>applyCategoryState(cb.dataset.catId));
  applyCategoryState(cb.dataset.catId);
});
document.getElementById('collapseAllBtn')?.addEventListener('click', ()=>{
  document.querySelectorAll('.cat-details, .sub-details').forEach(el=>{ el.open = false; });
});
document.getElementById('expandAllBtn')?.addEventListener('click', ()=>{
  document.querySelectorAll('.cat-details, .sub-details').forEach(el=>{ el.open = true; });
});

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  createPdfButton.disabled = true;
  pdfLoadingOverlay.style.display = 'flex';

  try {
    pdfDebug.style.display = 'none';
    pdfDebugText.textContent = '';
    if (templatePackageStatus) {
      templatePackageStatus.style.display = 'none';
      templatePackageStatus.textContent = '';
      templatePackageStatus.style.borderLeft = '';
    }

    const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
    const contentType = (response.headers.get('content-type') || '').toLowerCase();

    if (!response.ok) {
      const text = await response.text();
      const msg = text || <?= json_encode(t('latex.error.create_failed')) ?>;
      pdfDebugText.textContent = msg;
      pdfDebug.style.display = 'block';
      console.error('PDF build failed', { status: response.status, contentType, body: msg });
      return;
    }

    const packageCreated = response.headers.get('X-Template-Package-Created') === '1';
    const packageSubmitted = response.headers.get('X-Template-Package-Submitted') === '1';
    const packageId = response.headers.get('X-Template-Package-Id') || '';
    const packageError = response.headers.get('X-Template-Package-Error') || '';
    if (templatePackageStatus && packageSubmitted) {
      templatePackageStatus.textContent = <?= json_encode(t('latex.template_package.submitted')) ?> + (packageId ? (' (' + <?= json_encode(t('latex.template_package.package_number')) ?> + ' #' + packageId + ')') : '');
      templatePackageStatus.style.borderLeft = '4px solid #067647';
      templatePackageStatus.style.display = 'block';
    } else if (templatePackageStatus && packageCreated) {
      templatePackageStatus.textContent = <?= json_encode(t('latex.template_package.prepared')) ?> + (packageId ? (' (' + <?= json_encode(t('latex.template_package.package_number')) ?> + ' #' + packageId + ')') : '');
      templatePackageStatus.style.borderLeft = '4px solid #067647';
      templatePackageStatus.style.display = 'block';
    } else if (templatePackageStatus && packageError) {
      templatePackageStatus.textContent = <?= json_encode(t('latex.template_package.save_failed_prefix')) ?> + ' ' + decodeURIComponent(packageError);
      templatePackageStatus.style.borderLeft = '4px solid #b42318';
      templatePackageStatus.style.display = 'block';
    }

    if (contentType.includes('application/zip')) {
      const blob = await response.blob();
      const dl = document.createElement('a');
      dl.href = URL.createObjectURL(blob);
      dl.download = 'report_export.zip';
      document.body.appendChild(dl);
      dl.click();
      dl.remove();
      return;
    }

    if (!contentType.includes('application/pdf')) {
      const text = await response.text();
      const msg = text || <?= json_encode(t('latex.error.non_pdf_response')) ?>;
      pdfDebugText.textContent = msg;
      pdfDebug.style.display = 'block';
      console.error('PDF build returned non-PDF response', { status: response.status, contentType, body: msg });
      return;
    }

    const blob = await response.blob();

    if (currentPdfUrl) {
      URL.revokeObjectURL(currentPdfUrl);
    }

    currentPdfUrl = URL.createObjectURL(blob);
    pdfPreview.src = currentPdfUrl;
    pdfPreviewWrap.style.display = 'block';
    pdfPreviewWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (error) {
    const msg = (error && error.message) ? error.message : <?= json_encode(t('latex.error.unknown_create')) ?>;
    pdfDebugText.textContent = msg;
    pdfDebug.style.display = 'block';
    console.error('PDF build exception', error);
  } finally {
    createPdfButton.disabled = false;
    pdfLoadingOverlay.style.display = 'none';
  }
});
</script>
