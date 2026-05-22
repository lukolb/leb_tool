<?php
declare(strict_types=1);

function read_latex_data(string $file): string {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("Could not read data file.");
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

    throw new RuntimeException("Unbalanced braces in LaTeX data.");
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

        $skills[$macroName] = [
            'macro' => '\\' . $macroName,
            'items' => parse_skill_items($body),
        ];
    }

    return $skills;
}

function parse_skill_items(string $body): array {
    $items = [];
    $currentSub = null;

    $pattern = '/\\\\SubSkill\{([^{}]*)\}\{([^{}]*)\}|\\\\SkillRow\{([^{}]*)\}\s*\{([^{}]*)\}\s*\{([^{}]*)\}/su';

    preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        if (!empty($m[1])) {
            $currentSub = [
                'de' => $m[1],
                'en' => $m[2],
            ];
            $items[] = [
                'type' => 'subskill',
                'de' => $m[1],
                'en' => $m[2],
            ];
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

$dataFile = __DIR__ . '/latex/data.tex';
$content = read_latex_data($dataFile);
$macros = parse_skill_macros($content);
$sections = parse_sections($content);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Kompetenz-PDF erstellen</title>
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
      margin: 32px;
      background: #f7f7f7;
      color: #222;
    }
    h1 {
      margin-bottom: 8px;
    }
    .section {
      background: white;
      border-radius: 12px;
      padding: 18px 22px;
      margin: 18px 0;
      box-shadow: 0 2px 8px rgba(0,0,0,.08);
    }
    .subskill {
      margin-top: 16px;
      font-weight: 700;
      color: #444;
    }
    label {
      display: block;
      margin: 8px 0;
      line-height: 1.35;
    }
    .en {
      color: #666;
      font-style: italic;
      font-size: 0.92em;
    }
    button {
      margin-top: 24px;
      padding: 12px 18px;
      border: 0;
      border-radius: 8px;
      font-weight: 700;
      background: #1f5f99;
      color: white;
      cursor: pointer;
    }
  </style>
</head>
<body>

<h1>Kompetenz-PDF erstellen</h1>
<p>Wähle die Kompetenzen aus, die im PDF erscheinen sollen.</p>

<form id="pdfForm" method="post" action="build.php">

<?php foreach ($sections as $section): ?>
  <?php
    $macroName = $section['macroName'];
    $items = $macros[$macroName]['items'] ?? [];
  ?>
  <div class="section">
    <h2><?= htmlspecialchars($section['de']) ?> | <span class="en"><?= htmlspecialchars($section['en']) ?></span></h2>

    <label>
      <input type="checkbox" name="pagebreaks[]" value="<?= htmlspecialchars($macroName) ?>" <?= $section['pagebreak'] ? 'checked' : '' ?>>
      Seitenumbruch vor dieser Section
    </label>

    <?php foreach ($items as $item): ?>
      <?php if ($item['type'] === 'subskill'): ?>
        <div class="subskill">
          <?= htmlspecialchars($item['de']) ?> |
          <span class="en"><?= htmlspecialchars($item['en']) ?></span>
        </div>
      <?php else: ?>
        <label>
          <input type="checkbox" name="skills[]" value="<?= htmlspecialchars($item['id']) ?>" checked>
          <?= htmlspecialchars($item['de']) ?>
          <br>
          <span class="en"><?= htmlspecialchars($item['en']) ?></span>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<button type="submit">PDF erstellen</button>

</form>

<div id="pdfActions" style="display:none; margin-top: 24px;">
  <button type="button" id="downloadPdfButton">PDF herunterladen</button>
</div>

<iframe
  id="pdfPreview"
  style="display:none; width:100%; height:90vh; margin-top:24px; border:1px solid #ccc; border-radius:8px;"
></iframe>

<script>
let currentPdfUrl = null;
let currentPdfFilename = 'Vorlage.pdf';

const form = document.getElementById('pdfForm');
const pdfPreview = document.getElementById('pdfPreview');
const pdfActions = document.getElementById('pdfActions');
const downloadPdfButton = document.getElementById('downloadPdfButton');

form.addEventListener('submit', async function (event) {
  event.preventDefault();

  const submitButton = form.querySelector('button[type="submit"]');
  const oldText = submitButton ? submitButton.textContent : '';

  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'PDF wird erstellt...';
  }

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form)
    });

    const contentType = response.headers.get('Content-Type') || '';

    if (!response.ok) {
      const text = await response.text();
      throw new Error(text || 'PDF konnte nicht erstellt werden.');
    }

    const blob = await response.blob();

    if (!blob || blob.size === 0) {
      throw new Error('Der Server hat keine PDF-Datei zurückgegeben.');
    }

    if (currentPdfUrl) {
      URL.revokeObjectURL(currentPdfUrl);
    }

    currentPdfUrl = URL.createObjectURL(blob);

    pdfPreview.src = currentPdfUrl;
    pdfPreview.style.display = 'block';
    pdfActions.style.display = 'block';

    pdfPreview.scrollIntoView({ behavior: 'smooth', block: 'start' });

  } catch (error) {
    alert(error.message || 'Beim Erstellen des PDFs ist ein Fehler aufgetreten.');
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = oldText;
    }
  }
});

downloadPdfButton.addEventListener('click', function () {
  if (!currentPdfUrl) {
    return;
  }

  const a = document.createElement('a');
  a.href = currentPdfUrl;
  a.download = currentPdfFilename;
  document.body.appendChild(a);
  a.click();
  a.remove();
});
</script>

</body>
</html>
