<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$endpoint = 'https://latex.ytotech.com/builds/sync';

$latexDir = __DIR__ . '/latex';

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

$selectedSkills = array_values(array_unique(array_map('strval', $selectedSkills)));
$pagebreaks = array_values(array_unique(array_map('strval', $pagebreaks)));

$generatedDataTex = generate_selected_data_tex($originalDataTex, $selectedSkills, $pagebreaks);

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
    echo $body;
    exit;
}

$pdfFilename = 'Vorlage.pdf';

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
