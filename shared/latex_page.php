<?php
declare(strict_types=1);

function db_competency_sections(PDO $pdo, int $gradeLevel): array {
    $sql = "SELECT c.id, c.text_de, c.text_en, c.is_required, c.code, c.subcategory_id, COALESCE(c.category_id, s.category_id) AS category_id, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en FROM competencies c LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id, s.category_id) INNER JOIN competency_grade_levels cgl ON cgl.competency_id=c.id WHERE c.is_active=1 AND cgl.grade_level=? ORDER BY cat.sort_order, s.sort_order, c.sort_order, c.id";
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

$pdo = db();
$selectedGrade = (int)($_GET['grade_level'] ?? $_POST['grade_level'] ?? 1);
if ($selectedGrade < 1 || $selectedGrade > 4) $selectedGrade = 1;
$sectionsDb = db_competency_sections($pdo, $selectedGrade);
?>

<h1><?= h(t('latex.title', 'Kompetenz-PDF erstellen')) ?></h1>
<p><?= h(t('latex.desc', 'Wähle die Kompetenzen aus, die im PDF erscheinen sollen.')) ?></p>

<form id="pdfForm" method="post" action="<?= h($latexBuildUrl) ?>">
<input type="hidden" name="source" value="db">
<input type="hidden" name="grade_level" value="<?= h((string)$selectedGrade) ?>">
<div class="card" style="padding:12px;margin:12px 0;">
  <label><strong>Klassenstufe</strong></label>
  <select class="input" id="gradeLevelSelect">
    <?php for($g=1;$g<=4;$g++): ?><option value="<?= $g ?>" <?= $selectedGrade===$g?'selected':'' ?>><?= $g ?></option><?php endfor; ?>
  </select>
</div>
<?php foreach ($sectionsDb as $sectionId => $section): ?>
  <details class="card" style="padding:12px 16px; margin:16px 0;" open>
    <summary><h2 style="display:inline;"><?= h($section['de']) ?> | <span style="font-style:italic;color:#666;"><?= h((string)($section['en'] ?? '')) ?></span></h2></summary>
    <label style="display:block; margin:8px 0 12px 0;">
      <input type="checkbox" name="pagebreaks[]" value="<?= h((string)$sectionId) ?>">
      Seitenumbruch vor dieser Kategorie
    </label>

    <?php if (!empty($section['direct'])): ?>
      <details style="margin-top:12px; margin-left:12px;" open>
        <summary><strong>Ohne Unterkategorie</strong></summary>
        <?php foreach ($section['direct'] as $item): ?>
          <label style="display:block; margin:8px 0 8px 18px;">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled' : 'checked' ?>>
            <?php if ((int)($item['is_required'] ?? 0) === 1): ?><input type="hidden" name="skills[]" value="<?= h((string)$item['code']) ?>"><?php endif; ?>
            <?= h((string)$item['text_de']) ?>
            <br><span style="font-style:italic;color:#666;"><?= h((string)($item['text_en'] ?? '')) ?></span>
          </label>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>

    <?php foreach (($section['subs'] ?? []) as $sub): ?>
      <details style="margin-top:12px; margin-left:12px;" open>
        <summary><strong><?= h((string)$sub['de']) ?></strong> | <span style="font-style:italic;color:#666;"><?= h((string)($sub['en'] ?? '')) ?></span></summary>
        <?php foreach (($sub['items'] ?? []) as $item): ?>
          <label style="display:block; margin:8px 0 8px 18px;">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled' : 'checked' ?>>
            <?php if ((int)($item['is_required'] ?? 0) === 1): ?><input type="hidden" name="skills[]" value="<?= h((string)$item['code']) ?>"><?php endif; ?>
            <?= h((string)$item['text_de']) ?>
            <br><span style="font-style:italic;color:#666;"><?= h((string)($item['text_en'] ?? '')) ?></span>
          </label>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </details>
<?php endforeach; ?>

<button class="btn" type="submit" id="createPdfButton">PDF erstellen</button>
</form>

<style>
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
<div id="pdfLoadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center;">
  <div class="card" style="padding:18px 22px; font-weight:600; display:flex; gap:12px; align-items:center;">
    <span style="width:18px; height:18px; border:3px solid #d0d7de; border-top-color:#0b57d0; border-radius:50%; display:inline-block; animation:spin .8s linear infinite;"></span>
    <span>PDF wird erstellt … bitte warten.</span>
  </div>
</div>

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
let currentPdfUrl = null;

document.getElementById('gradeLevelSelect').addEventListener('change', (e) => {
  const u = new URL(window.location.href);
  u.searchParams.set('grade_level', e.target.value);
  window.location.href = u.toString();
});

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  createPdfButton.disabled = true;
  pdfLoadingOverlay.style.display = 'flex';

  try {
    pdfDebug.style.display = 'none';
    pdfDebugText.textContent = '';

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
