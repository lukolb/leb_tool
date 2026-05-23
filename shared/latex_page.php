<?php
declare(strict_types=1);

function db_competency_sections(PDO $pdo): array {
    $rows = $pdo->query("SELECT c.id, c.text_de, c.text_en, c.is_required, c.code, c.subcategory_id, COALESCE(c.category_id, s.category_id) AS category_id, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en FROM competencies c LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id, s.category_id) WHERE c.is_active=1 ORDER BY cat.sort_order, s.sort_order, c.sort_order, c.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
$sectionsDb = db_competency_sections($pdo);
?>

<h1><?= h(t('latex.title', 'Kompetenz-PDF erstellen')) ?></h1>
<p><?= h(t('latex.desc', 'Wähle die Kompetenzen aus, die im PDF erscheinen sollen.')) ?></p>

<form id="pdfForm" method="post" action="<?= h($latexBuildUrl) ?>">
<input type="hidden" name="source" value="db">
<?php foreach ($sectionsDb as $section): ?>
  <details class="card" style="padding:12px 16px; margin:16px 0;" open>
    <summary><h2 style="display:inline;"><?= h($section['de']) ?> | <span style="font-style:italic;color:#666;"><?= h((string)($section['en'] ?? '')) ?></span></h2></summary>

    <?php if (!empty($section['direct'])): ?>
      <details style="margin-top:12px; margin-left:12px;" open>
        <summary><strong>Ohne Unterkategorie</strong></summary>
        <?php foreach ($section['direct'] as $item): ?>
          <label style="display:block; margin:8px 0 8px 18px;">
            <input type="checkbox" name="skills[]" value="<?= h((string)$item['code']) ?>" <?= ((int)($item['is_required'] ?? 0) === 1) ? 'checked disabled' : 'checked' ?>>
            <?= h((string)$item['text_de']) ?> <?= ((int)($item['is_required'] ?? 0) === 1) ? '<strong>(verpflichtend)</strong>' : '<em>(optional)</em>' ?>
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
            <?= h((string)$item['text_de']) ?> <?= ((int)($item['is_required'] ?? 0) === 1) ? '<strong>(verpflichtend)</strong>' : '<em>(optional)</em>' ?>
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

<script>
const form = document.getElementById('pdfForm');
const pdfPreview = document.getElementById('pdfPreview');
const pdfPreviewWrap = document.getElementById('pdfPreviewWrap');
const createPdfButton = document.getElementById('createPdfButton');
const pdfLoadingOverlay = document.getElementById('pdfLoadingOverlay');
let currentPdfUrl = null;

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  createPdfButton.disabled = true;
  pdfLoadingOverlay.style.display = 'flex';

  try {
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });

    if (!response.ok) {
      const text = await response.text();
      alert(text || 'PDF konnte nicht erstellt werden.');
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
  } finally {
    createPdfButton.disabled = false;
    pdfLoadingOverlay.style.display = 'none';
  }
});
</script>
