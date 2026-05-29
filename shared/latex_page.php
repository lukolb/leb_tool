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
        '<span class="inline-checkbox-placeholder" title="[checkbox]" aria-label="Checkbox-Platzhalter"></span>',
        $escaped
    ) ?? $escaped;
    return preg_replace(
        '/\[vline\]/i',
        '<span class="inline-vline-placeholder" title="[vline]" aria-label="Vertikale Trennlinie"></span>',
        $withCheckboxes
    ) ?? $withCheckboxes;
}

function render_competency_placeholder_html_with_space(string $text): string {
    $base = render_competency_placeholder_html($text);
    return preg_replace(
        '/\[space\s*=\s*[^\]]+\]/i',
        '<span class="inline-space-placeholder" title="[space]" aria-label="Abstand"></span>',
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

<h1><?= h(t('latex.title', 'Kompetenz-PDF erstellen')) ?></h1>
<p><?= h(t('latex.desc', 'Wähle die Kompetenzen aus, die im PDF erscheinen sollen.')) ?></p>

<form id="pdfForm" method="post" action="<?= h($latexBuildUrl) ?>">
<input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="source" value="db">
<input type="hidden" name="grade_level" value="<?= h((string)$selectedGrade) ?>">
<div class="card" style="padding:12px;margin:12px 0;">
  <label><strong>Klassenstufe</strong></label>
  <select class="input" id="gradeLevelSelect">
    <?php for($g=1;$g<=4;$g++): ?><option value="<?= $g ?>" <?= $selectedGrade===$g?'selected':'' ?>><?= $g ?></option><?php endfor; ?>
  </select>
</div>
<div class="card" style="padding:12px;margin:12px 0;">
  <strong>Sonderbereiche</strong>
  <label style="display:block;margin-top:8px;">
    <input type="hidden" name="show_sel" value="0">
    <input type="checkbox" name="show_sel" value="1" checked> SEL-Bereich anzeigen
  </label>
  <label style="display:block;margin-top:6px;">
    <input type="hidden" name="show_ag" value="0">
    <input type="checkbox" name="show_ag" value="1" checked> AG-Bereich anzeigen
  </label>
  <label style="display:block;margin-top:6px;">
    <input type="hidden" name="enable_student_teacher_ratings" value="0">
    <input type="checkbox" name="enable_student_teacher_ratings" value="1"> Schüler-/Lehrer-Bewertungsspalten anzeigen
  </label>
  <label style="display:block;margin-top:6px;">
    <input type="hidden" name="export_rcff" value="0">
    <input type="checkbox" name="export_rcff" value="1"> Zusätzlich RCFF-Felddatei exportieren
  </label>
  <?php if (!empty($allowTemplatePackage)): ?>
  <label style="display:block;margin-top:10px;padding-top:8px;border-top:1px solid var(--border);">
    <input type="hidden" name="create_template_package" value="0">
    <input type="checkbox" name="create_template_package" value="1"> Als Template-Paket vorbereiten (PDF + Felddaten)
  </label>
  <p class="muted" style="margin:6px 0 0;">Speichert intern PDF, RCFF-Felddaten und Generierungsmetadaten für eine spätere Vorlagenübernahme.</p>
  <?php endif; ?>
  <?php if (!empty($allowTeacherTemplateSubmission)): ?>
  <label style="display:block;margin-top:10px;padding-top:8px;border-top:1px solid var(--border);">
    <input type="hidden" name="submit_template_package_to_admin" value="0">
    <input type="checkbox" name="submit_template_package_to_admin" value="1"> Als Vorlage an Admin senden
  </label>
  <p class="muted" style="margin:6px 0 0;">Das PDF und die Felddaten werden intern gespeichert. Der Admin kann daraus eine Vorlage anlegen.</p>
  <?php endif; ?>
</div>

<div class="card" style="padding:12px;margin:12px 0;">
  <label><strong>Titelseitenvorlage</strong></label>
  <select class="input" name="layout_template_id">
    <?php foreach ($layoutTemplates as $tpl): ?>
      <option value="<?= h((string)$tpl['id']) ?>" <?= ((int)$tpl['id'] === $defaultLayoutId) ? 'selected' : '' ?>><?= h((string)$tpl['display_name']) ?> (<?= h((string)$tpl['key_name']) ?>)</option>
    <?php endforeach; ?>
  </select>
</div>
<div style="display:flex;gap:8px;margin:8px 0 12px;">
  <button type="button" class="btn" id="collapseAllBtn">Alle einklappen</button>
  <button type="button" class="btn" id="expandAllBtn">Alle ausklappen</button>
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
      Kategorie aktiv
    </label>
    <div class="category-warning" data-cat-warning="<?= h((string)$sectionId) ?>" style="display:none; margin:0 0 10px; padding:8px 10px; border-radius:8px; background:#fdecec; color:#a61b1b; border:1px solid #f4b4b4;">
      Achtung: Durch das Deaktivieren dieser Kategorie werden auch verpflichtende Kompetenzen ausgeblendet.
    </div>
    <label style="display:block; margin:8px 0 12px 0;">
      <input type="checkbox" name="pagebreaks[]" value="<?= h((string)$sectionId) ?>">
      Seitenumbruch vor dieser Kategorie
    </label>
    <label style="display:block; margin:8px 0 12px 0;">
      <input type="checkbox" name="grade_field_cats[]" value="<?= h((string)$sectionId) ?>">
      Notenfeld im Kategorie-Header anzeigen
    </label>
    <div class="category-body" data-cat-body="<?= h((string)$sectionId) ?>">
    <?php if (!empty($section['direct'])): ?>
      <details class="sub-details" style="margin-top:12px; margin-left:12px;" open>
        <summary><strong>Ohne Unterkategorie</strong></summary>
        <?php foreach ($section['direct'] as $item): ?>
          <label class="<?= ((int)($item['is_required'] ?? 0) === 1) ? 'required-skill-row' : '' ?>" style="display:block; margin:8px 0 8px 18px;" title="<?= ((int)($item['is_required'] ?? 0) === 1) ? 'Verpflichtende Kompetenz kann nicht einzeln deaktiviert werden' : '' ?>">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled data-required-skill="1"' : 'checked' ?> <?= ((int)($item['is_required'] ?? 0) === 1) ? 'aria-disabled="true"' : '' ?>>
            <?php if ((int)($item['is_required'] ?? 0) === 1): ?><input type="hidden" name="skills[]" value="<?= h((string)$item['code']) ?>" data-required-hidden="1"><span class="required-badge">Pflicht</span><?php endif; ?>
            <?= render_competency_placeholder_html_with_space((string)$item['text_de']) ?><?php if(((string)($item['display_type'] ?? 'rated'))==='info'): ?> <span class="info-row-badge">Infozeile</span><?php endif; ?>
            <br><span style="font-style:italic;color:#666;"><?= render_competency_placeholder_html_with_space((string)($item['text_en'] ?? '')) ?></span>
          </label>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>

    <?php foreach (($section['subs'] ?? []) as $sub): ?>
      <details class="sub-details" style="margin-top:12px; margin-left:12px;" open>
        <summary><strong><?= h((string)$sub['de']) ?></strong> | <span style="font-style:italic;color:#666;"><?= h((string)($sub['en'] ?? '')) ?></span></summary>
        <?php foreach (($sub['items'] ?? []) as $item): ?>
          <label class="<?= ((int)($item['is_required'] ?? 0) === 1) ? 'required-skill-row' : '' ?>" style="display:block; margin:8px 0 8px 18px;" title="<?= ((int)($item['is_required'] ?? 0) === 1) ? 'Verpflichtende Kompetenz kann nicht einzeln deaktiviert werden' : '' ?>">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled data-required-skill="1"' : 'checked' ?> <?= ((int)($item['is_required'] ?? 0) === 1) ? 'aria-disabled="true"' : '' ?>>
            <?php if ((int)($item['is_required'] ?? 0) === 1): ?><input type="hidden" name="skills[]" value="<?= h((string)$item['code']) ?>" data-required-hidden="1"><span class="required-badge">Pflicht</span><?php endif; ?>
            <?= render_competency_placeholder_html_with_space((string)$item['text_de']) ?><?php if(((string)($item['display_type'] ?? 'rated'))==='info'): ?> <span class="info-row-badge">Infozeile</span><?php endif; ?>
            <br><span style="font-style:italic;color:#666;"><?= render_competency_placeholder_html_with_space((string)($item['text_en'] ?? '')) ?></span>
          </label>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>

<button class="btn" type="submit" id="createPdfButton">PDF erstellen</button>
</form>

<style>
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  .cat-title{display:inline;margin:0;font-size:1.1rem;font-weight:700;line-height:1.3}
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
    <span>PDF wird erstellt … bitte warten.</span>
  </div>
</div>

<div id="templatePackageStatus" class="card" style="display:none; margin-top:16px;"></div>

<div id="pdfPreviewWrap" style="display:none; margin-top:24px;">
  <iframe id="pdfPreview" style="width:100%; height:90vh;"></iframe>
</div>

<div id="pdfDebug" class="card" style="display:none; margin-top:16px; border-left:4px solid #b42318;">
  <h3 style="margin-top:0;">Fehlerdetails</h3>
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
  root.querySelectorAll('input[name="skills[]"]').forEach(el=>{
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
  root.querySelectorAll('input[name="pagebreaks[]"]').forEach(el=>{ el.disabled = !active; });
}

document.getElementById('gradeLevelSelect').addEventListener('change', (e) => {
  const u = new URL(window.location.href);
  u.searchParams.set('grade_level', e.target.value);
  window.location.href = u.toString();
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
      const msg = text || 'PDF konnte nicht erstellt werden.';
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
      templatePackageStatus.textContent = 'Die Vorlage wurde an den Admin gesendet.' + (packageId ? (' (Paket #' + packageId + ')') : '');
      templatePackageStatus.style.borderLeft = '4px solid #067647';
      templatePackageStatus.style.display = 'block';
    } else if (templatePackageStatus && packageCreated) {
      templatePackageStatus.textContent = 'Template-Paket wurde vorbereitet und kann im nächsten Schritt als Vorlage übernommen werden.' + (packageId ? (' (Paket #' + packageId + ')') : '');
      templatePackageStatus.style.borderLeft = '4px solid #067647';
      templatePackageStatus.style.display = 'block';
    } else if (templatePackageStatus && packageError) {
      templatePackageStatus.textContent = 'PDF wurde erstellt, aber das Template-Paket konnte nicht gespeichert werden: ' + decodeURIComponent(packageError);
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
      const msg = text || 'Unerwartete Serverantwort (kein PDF).';
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
    const msg = (error && error.message) ? error.message : 'Unbekannter Fehler bei der PDF-Erstellung.';
    pdfDebugText.textContent = msg;
    pdfDebug.style.display = 'block';
    console.error('PDF build exception', error);
  } finally {
    createPdfButton.disabled = false;
    pdfLoadingOverlay.style.display = 'none';
  }
});
</script>
