<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$endpoint = 'https://latex.ytotech.com/builds/sync';

$latexDir = __DIR__ . '/../latex';

function readTextFileOrFail(string $path): string {
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Datei nicht gefunden:\n" . $path;
        exit;
    }

    $content = file_get_contents($path);

    if ($content === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Datei konnte nicht gelesen werden:\n" . $path;
        exit;
    }

    return $content;
}

function readBase64FileOrFail(string $path): string {
    return base64_encode(readTextFileOrFail($path));
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

    throw new RuntimeException("Unbalanced braces.");
}

function parse_skill_items(string $body): array {
    $items = [];

    $pattern = '/\\\\SubSkill\{([^{}]*)\}\{([^{}]*)\}|\\\\SkillRow\{([^{}]*)\}\s*\{([^{}]*)\}\s*\{([^{}]*)\}/su';
    preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        if (!empty($m[1])) {
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
            ];
        }
    }

    return $items;
}

function parse_skill_macros(string $content): array {
    $macros = [];

    preg_match_all('/\\\\newcommand\{\\\\([A-Za-z0-9]+Skills)\}\s*\{/', $content, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[1] as $index => $match) {
        $macroName = $match[0];
        $fullMatchStart = $matches[0][$index][1];

        $bracePos = strpos($content, '{', $fullMatchStart + strlen($matches[0][$index][0]) - 1);
        if ($bracePos === false) {
            continue;
        }

        [$body] = extract_balanced_block($content, $bracePos);

        $macros[$macroName] = [
            'body' => $body,
            'items' => parse_skill_items($body),
        ];
    }

    return $macros;
}

function parse_sections(string $content): array {
    if (!preg_match('/\\\\newcommand\{\\\\AllSkillSections\}\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $bracePos = strpos($content, '{', $m[0][1] + strlen($m[0][0]) - 1);
    if ($bracePos === false) {
        return [];
    }

    [$body] = extract_balanced_block($content, $bracePos);

    $sections = [];
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

function ensure_balanced_braces_or_fail(string $tex, string $label = 'data.tex'): void {
    $depth = 0;
    $len = strlen($tex);
    for ($i = 0; $i < $len; $i++) {
        $ch = $tex[$i];
        if ($ch === '{') $depth++;
        if ($ch === '}') $depth--;
        if ($depth < 0) {
            throw new RuntimeException($label . ' hat eine überzählige schließende Klammer an Position ' . ($i + 1));
        }
    }
    if ($depth !== 0) {
        throw new RuntimeException($label . ' hat unausgeglichene Klammern (Diff: ' . $depth . ').');
    }
}

function latex_newcommand(string $name, string $body): string {
    return "\\newcommand{\\" . $name . "}{%\n" . $body . "}\n\n";
}

function with_line_numbers(string $content, int $maxLines = 120): string {
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    $out = [];
    $n = min(count($lines), $maxLines);
    for ($i = 0; $i < $n; $i++) {
        $out[] = str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT) . ': ' . $lines[$i];
    }
    return implode("\n", $out);
}

function write_debug_data_tex(string $content): string {
    $path = sys_get_temp_dir() . '/leb_data_debug.tex';
    @file_put_contents($path, $content);
    return $path;
}

function generate_selected_data_tex(string $content, array $selectedSkills, array $pagebreaks): string {
    $selectedMap = array_fill_keys($selectedSkills, true);
    $pagebreakMap = array_fill_keys($pagebreaks, true);

    $macros = parse_skill_macros($content);
    $sections = parse_sections($content);

    if (preg_match('/\\\\newcommand\{\\\\GradeLevel\}\{[^{}]*\}/', $content, $m)) {
        $out = $m[0] . "\n\n";
    } else {
        $out = "\\newcommand{\\GradeLevel}{1}\n\n";
    }

    foreach ($macros as $macroName => $macro) {
        $items = $macro['items'];

        $body = "";
        $pendingSubSkill = null;
        $hasAnySkill = false;

        foreach ($items as $item) {
            if ($item['type'] === 'subskill') {
                $pendingSubSkill = $item;
                continue;
            }

            if (!isset($selectedMap[$item['id']])) {
                continue;
            }

            if ($pendingSubSkill !== null) {
                $body .= "  \\SubSkill{" . $pendingSubSkill['de'] . "}{" . $pendingSubSkill['en'] . "}\n\n";
                $pendingSubSkill = null;
            }

            $body .= "  \\SkillRow{" . $item['id'] . "}\n";
            $body .= "    {" . $item['de'] . "}\n";
            $body .= "    {" . $item['en'] . "}\n\n";
            $hasAnySkill = true;
        }

        $out .= "\\newcommand{\\" . $macroName . "}{%\n";
        $out .= $hasAnySkill ? $body : "";
        $out .= "}\n\n";
    }

    $out .= "\\newcommand{\\AllSkillSections}{%\n";

    foreach ($sections as $section) {
        $macroName = $section['macroName'];
        $items = $macros[$macroName]['items'] ?? [];

        $hasSelectedSkill = false;

        foreach ($items as $item) {
            if ($item['type'] === 'skill' && isset($selectedMap[$item['id']])) {
                $hasSelectedSkill = true;
                break;
            }
        }

        if (!$hasSelectedSkill) {
            continue;
        }

        $cmd = isset($pagebreakMap[$macroName]) ? 'SkillSectionPageBreak' : 'SkillSection';

        $out .= "  \\" . $cmd
              . "{" . $section['de'] . "}"
              . "{" . $section['en'] . "}"
              . "{\\" . $macroName . "}\n";
    }

    $out .= "}\n";

    return $out;
}



// Diese Datei erzeugst du vorher dynamisch aus der Checkbox-Auswahl:
$originalDataTex = readTextFileOrFail($latexDir . '/data.tex');

$selectedSkills = $_POST['skills'] ?? [];
$pagebreaks = $_POST['pagebreaks'] ?? [];

if (!is_array($selectedSkills)) {
    $selectedSkills = [];
}

if (!is_array($pagebreaks)) {
    $pagebreaks = [];
}

$selectedSkills = array_values(array_unique(array_filter(array_map('strval', $selectedSkills), static fn($v) => trim((string)$v) !== '')));
$pagebreaks = array_values(array_unique(array_map('strval', $pagebreaks)));

if ((string)($_POST['source'] ?? '') === 'db' && function_exists('db')) {
    $pdo = db();
    $reqRows = $pdo->query("SELECT code FROM competencies WHERE is_active=1 AND is_required=1")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($reqRows as $code) {
        $c = trim((string)$code);
        if ($c !== '') {
            $selectedSkills[] = $c;
        }
    }
    $selectedSkills = array_values(array_unique(array_filter($selectedSkills, static fn($v) => trim((string)$v) !== '')));
}


$generatedDataTex = generate_selected_data_tex($originalDataTex, $selectedSkills, $pagebreaks);
if ((string)($_POST['source'] ?? '') === 'db' && function_exists('db')) {
    $pdo = db();
    $generatedDataTex = generate_db_data_tex($pdo, $selectedSkills);
}

try {
    ensure_balanced_braces_or_fail($generatedDataTex, 'data.tex');
    $debugPath = write_debug_data_tex($generatedDataTex);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fehler bei data.tex-Generierung: " . $e->getMessage() . "\n\n";
    echo "Debug-Datei: " . sys_get_temp_dir() . "/leb_data_debug.tex\n\n";
    echo with_line_numbers($generatedDataTex, 120);
    exit;
}

$resources = [
    [
        'main' => true,
        'content' => readTextFileOrFail($latexDir . '/main.tex'),
    ],

    [
        'path' => 'layout.tex',
        'file' => readBase64FileOrFail($latexDir . '/layout.tex'),
    ],
    [
        'path' => 'skills.tex',
        'file' => readBase64FileOrFail($latexDir . '/skills.tex'),
    ],
    [
        'path' => 'sel.tex',
        'file' => readBase64FileOrFail($latexDir . '/sel.tex'),
    ],
    [
        'path' => 'data.tex',
        'content' => $generatedDataTex,
    ],

    [
        'path' => 'eforms.sty',
        'file' => readBase64FileOrFail($latexDir . '/eforms.sty'),
    ],
    [
        'path' => 'epdftex.def',
        'file' => readBase64FileOrFail($latexDir . '/epdftex.def'),
    ],
    [
        'path' => 'insdljs.sty',
        'file' => readBase64FileOrFail($latexDir . '/insdljs.sty'),
    ],
    [
        'path' => 'taborder.sty',
        'file' => readBase64FileOrFail($latexDir . '/taborder.sty'),
    ],

    [
        'path' => 'assets/logo.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/logo.png'),
    ],
    [
        'path' => 'assets/footer.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/footer.png'),
    ],
    [
        'path' => 'assets/beginning.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/beginning.png'),
    ],
    [
        'path' => 'assets/goal.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/goal.png'),
    ],
    [
        'path' => 'assets/mastering.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/mastering.png'),
    ],
    [
        'path' => 'assets/progressing.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/progressing.png'),
    ],
    [
        'path' => 'assets/strength.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/strength.png'),
    ],
    [
        'path' => 'assets/strengthening.png',
        'file' => readBase64FileOrFail($latexDir . '/assets/strengthening.png'),
    ],

    [
        'path' => 'fonts/OpenSans-Regular.ttf',
        'file' => readBase64FileOrFail($latexDir . '/fonts/OpenSans-Regular.ttf'),
    ],
    [
        'path' => 'fonts/OpenSans-Bold.ttf',
        'file' => readBase64FileOrFail($latexDir . '/fonts/OpenSans-Bold.ttf'),
    ],
    [
        'path' => 'fonts/OpenSans-Italic.ttf',
        'file' => readBase64FileOrFail($latexDir . '/fonts/OpenSans-Italic.ttf'),
    ],
    [
        'path' => 'fonts/OpenSans-BoldItalic.ttf',
        'file' => readBase64FileOrFail($latexDir . '/fonts/OpenSans-BoldItalic.ttf'),
    ],
];

$payload = [
    'compiler' => 'lualatex',
    'resources' => $resources,
    'options' => [
        'compiler' => [
            'halt_on_error' => true,
            'silent' => false,
        ],
        'response' => [
            'log_files_on_failure' => true,
        ],
    ],
];

if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PHP-cURL ist auf diesem Server nicht verfügbar.';
    exit;
}

$ch = curl_init($endpoint);
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);

if ($json === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'JSON konnte nicht erzeugt werden: ' . json_last_error_msg();
    exit;
}
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(500);
    echo 'Fehler beim Aufruf des LaTeX-Dienstes: ' . curl_error($ch);
    exit;
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

$body = substr($response, $headerSize);

curl_close($ch);

$isPdf = strncmp($body, '%PDF-', 5) === 0;

if (!$isPdf) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "LaTeX konnte nicht kompiliert werden.\n\n";
    echo "HTTP Status: " . $statusCode . "\n";
    echo "Content-Type: " . $contentType . "\n\n";
    echo "Debug-Datei: " . ($debugPath ?? (sys_get_temp_dir() . "/leb_data_debug.tex")) . "\n\n";
    echo "data.tex (erste 120 Zeilen):\n";
    echo with_line_numbers($generatedDataTex, 120) . "\n\n";
    echo $body;
    exit;
}

$pdfFilename = 'vorlage.pdf';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header_remove();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $pdfFilename . '"; filename*=UTF-8\'\'' . rawurlencode($pdfFilename));
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($body));

echo $body;
exit;
function latex_escape(string $t): string {
    $map = [
        '\\' => '\\textbackslash{}',
        '{' => '\\{',
        '}' => '\\}',
        '%' => '\\%',
        '&' => '\\&',
        '#' => '\\#',
        '_' => '\\_',
        '$' => '\\$',
        '^' => '\\textasciicircum{}',
        '~' => '\\textasciitilde{}',
    ];
    return strtr($t, $map);
}

function generate_db_data_tex(PDO $pdo, array $selectedCodes): string {
    if (!$selectedCodes) {
        return "\\newcommand{\\GradeLevel}{1}\n\\newcommand{\\AllSkillSections}{}\n";
    }

    $in = implode(',', array_fill(0, count($selectedCodes), '?'));
    $sql = "SELECT c.code, c.text_de, c.text_en, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en "
         . "FROM competencies c "
         . "LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id "
         . "LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id,s.category_id) "
         . "WHERE c.code IN ($in) AND c.is_active=1 AND c.code IS NOT NULL AND c.code <> '' "
         . "ORDER BY cat.sort_order, s.sort_order, c.sort_order, c.id";

    $st = $pdo->prepare($sql);
    $st->execute($selectedCodes);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sections = [];
    foreach ($rows as $r) {
        $cat = trim((string)($r['cat_de'] ?? 'Sonstiges'));
        if ($cat === '') $cat = 'Sonstiges';
        $sub = trim((string)($r['sub_de'] ?? ''));
        $sections[$cat]['en'] = (string)($r['cat_en'] ?? '');
        $sections[$cat]['subs'][$sub]['en'] = (string)($r['sub_en'] ?? '');
        $sections[$cat]['subs'][$sub]['items'][] = $r;
    }

    $out = "% AUTO-GENERATED FROM DB\n";
    $out .= "\\newcommand{\\GradeLevel}{1}\n\n";
    $i = 1;
    foreach ($sections as $catDe => $catData) {
        $macro = 'DbCat' . $i . 'Skills';
        $out .= "% SECTION: " . latex_escape((string)$catDe) . "\n";
        $macroBody = "";
        foreach (($catData['subs'] ?? []) as $subDe => $subData) {
            if ($subDe !== '') {
                $macroBody .= "  \\SubSkill{" . latex_escape($subDe) . "}{" . latex_escape((string)($subData['en'] ?? '')) . "}\n\n";
            }
            foreach (($subData['items'] ?? []) as $it) {
                $code = trim((string)($it['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $macroBody .= "  \\SkillRow{" . latex_escape($code) . "}\n";
                $macroBody .= "    {" . latex_escape((string)$it['text_de']) . "}\n";
                $macroBody .= "    {" . latex_escape((string)($it['text_en'] ?? '')) . "}\n\n";
            }
        }
        $out .= latex_newcommand($macro, $macroBody);
        $sections[$catDe]['macro'] = $macro;
        $i++;
    }

    $out .= "\\newcommand{\\AllSkillSections}{%\n";
    foreach ($sections as $catDe => $catData) {
        $out .= "  \\SkillSection{" . latex_escape($catDe) . "}{" . latex_escape((string)($catData['en'] ?? '')) . "}{\\" . $catData['macro'] . "}\n";
    }
    $out .= "}\n";

    return $out;
}
