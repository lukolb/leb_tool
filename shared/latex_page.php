<?php
declare(strict_types=1);

function read_latex_data(string $file): string {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException('Could not read data file.');
    }
    return $content;
}

function extract_balanced_block(string $text, int $startPos): array {
    $len = strlen($text);
    $depth = 0;
    $blockStart = null;

    for ($i = $startPos; $i < $len; $i++) {
        $ch = $text[$i];

        if ($ch === '{') {
            if ($depth === 0) {
                $blockStart = $i + 1;
            }
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0 && $blockStart !== null) {
                return [substr($text, $blockStart, $i - $blockStart), $i + 1];
            }
        }
    }

    throw new RuntimeException('Unbalanced braces in LaTeX data.');
}

function parse_skill_items(string $body): array {
    $items = [];
    $currentSub = null;

    $pattern = '/\\\\SubSkill\{([^{}]*)\}\{([^{}]*)\}|\\\\SkillRow\{([^{}]*)\}\s*\{([^{}]*)\}\s*\{([^{}]*)\}/su';
    preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        if (!empty($m[1])) {
            $currentSub = ['de' => $m[1], 'en' => $m[2]];
            $items[] = ['type' => 'subskill', 'de' => $m[1], 'en' => $m[2]];
        } else {
            $items[] = [
                'type' => 'skill',
                'id' => $m[3],
                'de' => $m[4],
                'en' => $m[5],
                'subskill' => $currentSub,
            ];
        }
    }

    return $items;
}

function parse_skill_macros(string $content): array {
    $skills = [];

    if (!preg_match_all('/\\\\newcommand\{\\\\([A-Za-z0-9]+Skills)\}\s*\{/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        return $skills;
    }

    foreach ($matches[1] as $index => $match) {
        $macroName = $match[0];
        $fullMatchStart = $matches[0][$index][1];
        $bracePos = strpos($content, '{', $fullMatchStart + strlen($matches[0][$index][0]) - 1);

        if ($bracePos === false) {
            continue;
        }

        [$body] = extract_balanced_block($content, $bracePos);

        $skills[$macroName] = ['macro' => '\\' . $macroName, 'items' => parse_skill_items($body)];
    }

    return $skills;
}

function parse_sections(string $content): array {
    $sections = [];

    if (!preg_match('/\\\\newcommand\{\\\\AllSkillSections\}\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
        return $sections;
    }

    $bracePos = strpos($content, '{', $m[0][1] + strlen($m[0][0]) - 1);
    if ($bracePos === false) {
        return $sections;
    }

    [$body] = extract_balanced_block($content, $bracePos);

    $pattern = '/\\\\(SkillSectionPageBreak|SkillSection)\{([^{}]*)\}\{([^{}]*)\}\{\\\\([A-Za-z0-9]+Skills)\}/u';
    preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $sections[] = [
            'pagebreak' => $m[1] === 'SkillSectionPageBreak',
            'de' => $m[2],
            'en' => $m[3],
            'macroName' => $m[4],
        ];
    }

    return $sections;
}

$dataFile = __DIR__ . '/../latex/data.tex';
$content = read_latex_data($dataFile);
$macros = parse_skill_macros($content);
$sections = parse_sections($content);
?>

<h1><?= h(t('latex.title', 'Kompetenz-PDF erstellen')) ?></h1>
<p><?= h(t('latex.desc', 'Wähle die Kompetenzen aus, die im PDF erscheinen sollen.')) ?></p>

<form id="pdfForm" method="post" action="<?= h($latexBuildUrl) ?>">
<?php foreach ($sections as $section): ?>
  <?php $macroName = $section['macroName']; $items = $macros[$macroName]['items'] ?? []; ?>
  <div class="card" style="padding:16px; margin:16px 0;">
    <h2><?= h($section['de']) ?> | <span style="font-style:italic;color:#666;"><?= h($section['en']) ?></span></h2>
    <label><input type="checkbox" name="pagebreaks[]" value="<?= h($macroName) ?>" <?= $section['pagebreak'] ? 'checked' : '' ?>> Seitenumbruch vor dieser Section</label>
    <?php foreach ($items as $item): ?>
      <?php if ($item['type'] === 'subskill'): ?>
        <div style="margin-top:12px; font-weight:700;"><?= h($item['de']) ?> | <span style="font-style:italic;color:#666;"><?= h($item['en']) ?></span></div>
      <?php else: ?>
        <label style="display:block; margin:8px 0;">
          <input type="checkbox" name="skills[]" value="<?= h($item['id']) ?>" checked>
          <?= h($item['de']) ?><br>
          <span style="font-style:italic;color:#666;"><?= h($item['en']) ?></span>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
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
  } finally {
    createPdfButton.disabled = false;
    pdfLoadingOverlay.style.display = 'none';
  }
});
</script>
