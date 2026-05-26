<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$endpoint = 'https://latex.ytotech.com/builds/sync';

$latexDir = __DIR__ . '/../latex';
require_once __DIR__ . '/latex_layout_templates.php';

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
    return "\\newcommand{\\" . $name . "}{%\n" . $body . "}\n";
}

function latex_macro_name_for_category(int $index, string $title): string {
    $lettersOnly = preg_replace('/[^A-Za-z]+/', '', $title) ?? '';
    $lettersOnly = trim($lettersOnly);
    if ($lettersOnly === '') {
        $words = [
            1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        ];
        $suffix = $words[$index] ?? ('Idx' . $index);
        $lettersOnly = 'DbCat' . $suffix;
    }
    $lettersOnly = ucfirst($lettersOnly);
    $name = $lettersOnly . 'Skills';
    return preg_replace('/[^A-Za-z]/', '', $name) ?: 'DbCatSkills';
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


function assert_no_blank_lines_in_newcommands(string $tex): void {
    if (!preg_match_all('/\\\\newcommand\{\\\\[A-Za-z0-9]+\}\{%\\n(.*?)\\n\}/s', $tex, $m, PREG_SET_ORDER)) {
        return;
    }
    foreach ($m as $cmd) {
        $body = (string)($cmd[1] ?? '');
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        foreach ($lines as $idx => $line) {
            if (trim($line) === '') {
                throw new RuntimeException('Leere Zeile innerhalb einer \newcommand-Definition gefunden (Body-Zeile ' . ($idx + 1) . ').');
            }
        }
    }
}

function assert_valid_macro_names_no_digits(string $tex): void {
    if (!preg_match_all('/\\\\newcommand\{\\\\([A-Za-z][A-Za-z0-9]*)\}/', $tex, $m, PREG_SET_ORDER)) {
        return;
    }
    foreach ($m as $hit) {
        $macro = (string)($hit[1] ?? '');
        if (preg_match('/\d/', $macro)) {
            throw new RuntimeException('Invalid LaTeX macro name contains digit: ' . $macro);
        }
    }
}

function write_debug_data_tex(string $content): string {
    $path = sys_get_temp_dir() . '/leb_data_debug.tex';
    @file_put_contents($path, $content);
    return $path;
}

function generate_selected_data_tex(string $content, array $selectedSkills, array $pagebreaks, int $gradeLevel = 1, bool $showSel = true, bool $showAg = true): string {
    $selectedMap = array_fill_keys($selectedSkills, true);
    $pagebreakMap = array_fill_keys($pagebreaks, true);

    $macros = parse_skill_macros($content);
    $sections = parse_sections($content);

    $out = "\\newif\\ifShowSEL\n" . ($showSel ? "\\ShowSELtrue\n" : "\\ShowSELfalse\n");
    $out .= "\\newif\\ifShowAG\n" . ($showAg ? "\\ShowAGtrue\n" : "\\ShowAGfalse\n");
    if ($gradeLevel < 1 || $gradeLevel > 4) { $gradeLevel = 1; }
    $out .= "\\newcommand{\\GradeLevel}{" . $gradeLevel . "}\n";

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
                $body .= "  \\SubSkill{" . $pendingSubSkill['de'] . "}{" . $pendingSubSkill['en'] . "}\n";
                $pendingSubSkill = null;
            }

            $body .= "  \\SkillRow{" . $item['id'] . "}%\n";
            $body .= "    {" . $item['de'] . "}%\n";
            $body .= "    {" . $item['en'] . "}%\n";
            $hasAnySkill = true;
        }

        $out .= "\\newcommand{\\" . $macroName . "}{%\n";
        $out .= $hasAnySkill ? $body : "";
        $out .= "}\n";
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
$allCatIds = array_values(array_unique(array_map('intval', (array)($_POST['cat_ids'] ?? []))));
$activeCatIds = array_values(array_unique(array_map('intval', (array)($_POST['cat_active'] ?? []))));
$disabledCatIds = array_values(array_diff($allCatIds, $activeCatIds));

if ((string)($_POST['source'] ?? '') === 'db' && function_exists('db')) {
    $pdo = db();
    $selectedGrade = (int)($_POST['grade_level'] ?? 1);
    if ($selectedGrade < 1 || $selectedGrade > 4) $selectedGrade = 1;
    $reqSql = "SELECT c.code FROM competencies c INNER JOIN competency_grade_levels cgl ON cgl.competency_id=c.id WHERE c.is_active=1 AND c.is_required=1 AND cgl.grade_level=?";
    $reqParams = [$selectedGrade];
    if (!empty($disabledCatIds)) {
        $in = implode(',', array_fill(0, count($disabledCatIds), '?'));
        $reqSql .= " AND COALESCE(c.category_id, (SELECT cs.category_id FROM competency_subcategories cs WHERE cs.id=c.subcategory_id)) NOT IN ($in)";
        $reqParams = array_merge($reqParams, $disabledCatIds);
    }
    $stReq = $pdo->prepare($reqSql);
    $stReq->execute($reqParams);
    $reqRows = $stReq->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($reqRows as $code) {
        $c = trim((string)$code);
        if ($c !== '') {
            $selectedSkills[] = $c;
        }
    }
    $selectedSkills = array_values(array_unique(array_filter($selectedSkills, static fn($v) => trim((string)$v) !== '')));
}


$selectedGrade = (int)($_POST['grade_level'] ?? 1);
if ($selectedGrade < 1 || $selectedGrade > 4) $selectedGrade = 1;
$showSel = ((string)($_POST['show_sel'] ?? '1') === '1');
$showAg = ((string)($_POST['show_ag'] ?? '1') === '1');
$enableStudentTeacherRatings = ((string)($_POST['enable_student_teacher_ratings'] ?? '0') === '1');
$exportRcff = ((string)($_POST['export_rcff'] ?? '0') === '1');
$gradeFieldCategoryIds = array_values(array_unique(array_map('intval', (array)($_POST['grade_field_cats'] ?? []))));
$generatedDataTex = generate_selected_data_tex($originalDataTex, $selectedSkills, $pagebreaks, $selectedGrade, $showSel, $showAg);
if ((string)($_POST['source'] ?? '') === 'db' && function_exists('db')) {
    $pdo = db();
    if (!empty($disabledCatIds) && !empty($selectedSkills)) {
        $inSkills = implode(',', array_fill(0, count($selectedSkills), '?'));
        $inCats = implode(',', array_fill(0, count($disabledCatIds), '?'));
        $sql = "SELECT code FROM competencies c WHERE c.code IN ($inSkills) AND COALESCE(c.category_id, (SELECT cs.category_id FROM competency_subcategories cs WHERE cs.id=c.subcategory_id)) IN ($inCats)";
        $stDisabled = $pdo->prepare($sql);
        $stDisabled->execute(array_merge($selectedSkills, $disabledCatIds));
        $blocked = array_map('strval', $stDisabled->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!empty($blocked)) {
            $selectedSkills = array_values(array_diff($selectedSkills, $blocked));
        }
    }
    $generatedDataTex = generate_db_data_tex($pdo, $selectedSkills, $pagebreaks, $selectedGrade, $gradeFieldCategoryIds, $enableStudentTeacherRatings);
    $generatedDataTex = "\\newif\\ifShowSEL\n" . ($showSel ? "\\ShowSELtrue\n" : "\\ShowSELfalse\n")
        . "\\newif\\ifShowAG\n" . ($showAg ? "\\ShowAGtrue\n" : "\\ShowAGfalse\n")
        . "\\newif\\ifShowSTRatings\n" . ($enableStudentTeacherRatings ? "\\ShowSTRatingstrue\n" : "\\ShowSTRatingsfalse\n")
        . $generatedDataTex;
}
$rcffData = null;
if ($exportRcff && (string)($_POST['source'] ?? '') === 'db' && function_exists('db')) {
    $rcffData = generate_rcff_data($pdo, $selectedSkills, $selectedGrade, $enableStudentTeacherRatings, $gradeFieldCategoryIds);
}

try {
    ensure_balanced_braces_or_fail($generatedDataTex, 'data.tex');
    assert_no_blank_lines_in_newcommands($generatedDataTex);
    assert_valid_macro_names_no_digits($generatedDataTex);
    $debugPath = write_debug_data_tex($generatedDataTex);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fehler bei data.tex-Generierung: " . $e->getMessage() . "\n";
    echo "Debug-Datei: " . sys_get_temp_dir() . "/leb_data_debug.tex\n";
    echo with_line_numbers($generatedDataTex, 120);
    exit;
}


$pdoForLayout = function_exists('db') ? db() : null;
$selectedLayoutPath = 'latex/layout.tex';
if ($pdoForLayout instanceof PDO) {
    ensure_default_latex_layout_template($pdoForLayout);
    $layoutTemplateId = (int)($_POST['layout_template_id'] ?? 0);
    $layout = $layoutTemplateId > 0 ? find_active_latex_layout_template($pdoForLayout, $layoutTemplateId) : null;
    if (!$layout) {
        $layout = get_default_latex_layout_template($pdoForLayout);
    }
    if ($layout && !empty($layout['file_path'])) {
        $selectedLayoutPath = (string)$layout['file_path'];
    }
}
$layoutAbsolute = latex_layout_absolute_path($selectedLayoutPath);
if (!is_file($layoutAbsolute)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Layoutvorlage nicht gefunden.';
    exit;
}

$resources = [
    [
        'main' => true,
        'content' => readTextFileOrFail($latexDir . '/main.tex'),
    ],

    [
        'path' => 'layout.tex',
        'file' => readBase64FileOrFail($layoutAbsolute),
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
        'path' => 'assets/logo.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/logo.pdf'),
    ],
    [
        'path' => 'assets/footer.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/footer.pdf'),
    ],
    [
        'path' => 'assets/beginning.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/beginning.pdf'),
    ],
    [
        'path' => 'assets/goal.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/goal.pdf'),
    ],
    [
        'path' => 'assets/mastering.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/mastering.pdf'),
    ],
    [
        'path' => 'assets/progressing.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/progressing.pdf'),
    ],
    [
        'path' => 'assets/strength.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/strength.pdf'),
    ],
    [
        'path' => 'assets/strengthening.pdf',
        'file' => readBase64FileOrFail($latexDir . '/assets/strengthening.pdf'),
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
    echo "LaTeX konnte nicht kompiliert werden.\n";
    echo "HTTP Status: " . $statusCode . "\n";
    echo "Content-Type: " . $contentType . "\n";
    echo "Debug-Datei: " . ($debugPath ?? (sys_get_temp_dir() . "/leb_data_debug.tex")) . "\n";
    echo "data.tex (erste 120 Zeilen):\n";
    echo with_line_numbers($generatedDataTex, 120) . "\n";
    echo $body;
    exit;
}

$pdfFilename = 'vorlage.pdf';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header_remove();
if ($exportRcff && $rcffData !== null && class_exists('ZipArchive')) {
    $zipPath = tempnam(sys_get_temp_dir(), 'leb_rcff_');
    $zip = new ZipArchive();
    if ($zipPath !== false && $zip->open($zipPath, ZipArchive::OVERWRITE) === true) {
        $zip->addFromString('report.pdf', $body);
        $rcffJson = json_encode($rcffData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rcffJson === false) $rcffJson = '{"format":"rcff","version":1,"fields":[]}';
        $zip->addFromString('report.rcff', $rcffJson);
        $zip->close();
        $zipBytes = (string)file_get_contents($zipPath);
        @unlink($zipPath);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="report_export.zip"');
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($zipBytes));
        echo $zipBytes;
        exit;
    }
}
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

function parse_checkbox_placeholder_options(string $token): array {
    $opts = ['size' => 2.7, 'border' => false, 'same' => false, 'type' => 'checkbox', 'group' => '', 'value' => ''];
    if (!preg_match('/^\[checkbox(?::([^\]]*))?\]$/i', $token, $m)) {
        return $opts;
    }
    $raw = trim((string)($m[1] ?? ''));
    if ($raw === '') return $opts;
    $parts = array_filter(array_map('trim', explode(';', $raw)), static fn($v) => $v !== '');
    foreach ($parts as $part) {
        $kv = array_map('trim', explode('=', $part, 2));
        if (count($kv) !== 2) continue;
        [$k, $v] = $kv;
        $k = strtolower($k);
        $v = strtolower(str_replace(',', '.', $v));
        if ($k === 'size') {
            if (is_numeric($v)) {
                $size = (float)$v;
                if ($size >= 1.5 && $size <= 8.0) $opts['size'] = $size;
            }
            continue;
        }
        if ($k === 'border') { $opts['border'] = in_array($v, ['1','true','yes','ja'], true); continue; }
        if ($k === 'same') { $opts['same'] = in_array($v, ['1','true','yes','ja'], true); continue; }
        if ($k === 'type') {
            $opts['type'] = ($v === 'radio') ? 'radio' : 'checkbox';
            continue;
        }
        if ($k === 'group') {
            $opts['group'] = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$v) ?? '';
            $opts['group'] = trim((string)$opts['group'], '-');
            continue;
        }
        if ($k === 'value') {
            $opts['value'] = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$v) ?? '';
            $opts['value'] = trim((string)$opts['value'], '-');
            continue;
        }
    }
    return $opts;
}

function parse_space_placeholder_to_latex(string $token): ?string {
    if (!preg_match('/^\[space\s*=\s*([^\]]+)\]$/i', $token, $m)) {
        return null;
    }
    $raw = strtolower(trim((string)($m[1] ?? '')));
    $raw = str_replace(',', '.', $raw);
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(mm|cm)?$/', $raw, $mm)) {
        return null;
    }
    $num = (float)($mm[1] ?? 0);
    $unit = (string)($mm[2] ?? 'mm');
    if ($unit === '') $unit = 'mm';
    if ($unit === 'cm') {
        $num *= 10.0;
        $unit = 'mm';
    }
    if ($num < 0 || $num > 30) {
        return null;
    }
    $val = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
    if ($val === '') $val = '0';
    return '\\hspace{' . $val . $unit . '}';
}

function collect_inline_field_metadata(string $text, string $fieldPrefix, array $baseMeta): array {
    $tokens = preg_split('/(\[checkbox(?::[^\]]*)?\])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '-', $fieldPrefix) ?? 'skillcb';
    $safePrefix = trim($safePrefix, '-');
    if ($safePrefix === '') $safePrefix = 'skillcb';
    $out = [];
    $checkboxIndex = 0;
    $radioValueCounters = [];
    foreach ($tokens as $token) {
        if (!preg_match('/^\[checkbox(?::[^\]]*)?\]$/i', (string)$token)) continue;
        $o = parse_checkbox_placeholder_options((string)$token);
        if ((string)$o['type'] === 'radio') {
            $group = (string)($o['group'] ?? '');
            if ($group === '') $group = 'default';
            $value = (string)($o['value'] ?? '');
            if ($value === '') {
                $radioValueCounters[$group] = (int)($radioValueCounters[$group] ?? 0) + 1;
                $value = 'opt' . $radioValueCounters[$group];
            }
            $out[] = $baseMeta + ['field_name' => $safePrefix . '-radio-' . $group, 'type' => 'inline_radio', 'value' => $value];
        } else {
            $fieldName = $o['same'] ? ($safePrefix . '-same') : ($safePrefix . '-' . (++$checkboxIndex));
            $out[] = $baseMeta + ['field_name' => $fieldName, 'type' => 'inline_checkbox'];
        }
    }
    return $out;
}

function latex_escape_with_inline_placeholders(string $t, string $fieldPrefix = 'skillcb'): string {
    $tokens = preg_split('/(\[checkbox(?::[^\]]*)?\]|\[vline\]|\[space\s*=\s*[^\]]+\])/i', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($tokens)) { return latex_escape($t); }
    if (count($tokens) === 1) { return latex_escape($t); }

    $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '-', $fieldPrefix) ?? 'skillcb';
    $safePrefix = trim($safePrefix, '-');
    if ($safePrefix === '') {
        $safePrefix = 'skillcb';
    }

    $out = '';
    $checkboxIndex = 0;
    $radioValueCounters = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = (string)$tokens[$i];
        if (preg_match('/^\[checkbox(?::[^\]]*)?\]$/i', $token)) {
            $o = parse_checkbox_placeholder_options($token);
            $size = rtrim(rtrim(number_format((float)$o['size'], 2, '.', ''), '0'), '.');
            $border = $o['border'] ? '1' : '0';
            if ((string)$o['type'] === 'radio') {
                $group = (string)($o['group'] ?? '');
                if ($group === '') $group = 'default';
                $fieldName = $safePrefix . '-radio-' . $group;
                $value = (string)($o['value'] ?? '');
                if ($value === '') {
                    $radioValueCounters[$group] = (int)($radioValueCounters[$group] ?? 0) + 1;
                    $value = 'opt' . $radioValueCounters[$group];
                }
                $out .= '\\InlineCrossRadio{' . latex_escape($fieldName) . '}{' . latex_escape($value) . '}{' . $size . '}{' . $border . '}';
            } else {
                $fieldName = $o['same'] ? ($safePrefix . '-same') : ($safePrefix . '-' . (++$checkboxIndex));
                if ($size === '2.7' && $border === '0') $out .= '\\InlineCrossCheckbox{' . latex_escape($fieldName) . '}';
                else $out .= '\\InlineCrossCheckboxSized{' . latex_escape($fieldName) . '}{' . $size . '}{' . $border . '}';
            }
            continue;
        }
        if (preg_match('/^\[vline\]$/i', $token)) {
            $out .= '\\InlineVLine';
            continue;
        }
        $spaceLatex = parse_space_placeholder_to_latex($token);
        if ($spaceLatex !== null) {
            $out .= $spaceLatex;
            continue;
        }

        $part = $token;
        $nextToken = ($i + 1 < $count) ? (string)$tokens[$i + 1] : '';
        $extraSpaceCount = 0;
        // LaTeX collapses normal multiple spaces; for controlled spacing use [space=...].
        if (preg_match('/^\[checkbox(?::[^\]]*)?\]$/i', $nextToken) && preg_match('/( +)$/', $part, $m)) {
            $spaces = (string)($m[1] ?? '');
            $spaceCount = strlen($spaces);
            if ($spaceCount > 1) {
                $extraSpaceCount = $spaceCount - 1;
                $part = substr($part, 0, -$spaceCount) . ' ';
            }
        }
        $out .= latex_escape($part);
        if ($extraSpaceCount > 0) {
            $out .= '\\hspace{' . (1.5 * $extraSpaceCount) . 'mm}';
        }
    }
    return $out;
}

function generate_db_data_tex(PDO $pdo, array $selectedCodes, array $pagebreakCategoryIds = [], int $gradeLevel = 1, array $gradeFieldCategoryIds = [], bool $enableStudentTeacherRatings = false): string {
    if ($gradeLevel < 1 || $gradeLevel > 4) { $gradeLevel = 1; }
    if (!$selectedCodes) {
        return "\\newcommand{\\GradeLevel}{" . $gradeLevel . "}\n\\newcommand{\\AllSkillSections}{}\n";
    }

    $in = implode(',', array_fill(0, count($selectedCodes), '?'));
    $sql = "SELECT c.id, c.code, c.text_de, c.text_en, COALESCE(c.display_type,'rated') AS display_type, COALESCE(c.category_id, s.category_id) AS category_id, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en "
         . "FROM competencies c "
         . "LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id "
         . "LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id,s.category_id) "
         . "WHERE c.code IN ($in) AND c.is_active=1 AND c.code IS NOT NULL AND c.code <> '' "
         . "ORDER BY cat.sort_order, cat.id, CASE WHEN c.subcategory_id IS NULL OR c.subcategory_id=0 THEN 0 ELSE 1 END, s.sort_order, s.id, c.sort_order, c.id";

    $st = $pdo->prepare($sql);
    $st->execute($selectedCodes);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sections = [];
    foreach ($rows as $r) {
        $catId = (int)($r['category_id'] ?? 0);
        if ($catId <= 0) {
            continue;
        }
        $catDe = trim((string)($r['cat_de'] ?? 'Sonstiges'));
        if ($catDe === '') $catDe = 'Sonstiges';
        $sub = trim((string)($r['sub_de'] ?? ''));
        if (!isset($sections[$catId])) {
            $sections[$catId] = [
                'de' => $catDe,
                'en' => (string)($r['cat_en'] ?? ''),
                'subs' => [],
            ];
        }
        if (!isset($sections[$catId]['subs'][$sub])) {
            $sections[$catId]['subs'][$sub] = [
                'en' => (string)($r['sub_en'] ?? ''),
                'items' => [],
            ];
        }
        $sections[$catId]['subs'][$sub]['items'][] = $r;
    }

    $out = "% AUTO-GENERATED FROM DB\n";
    $out .= "\\newcommand{\\GradeLevel}{" . $gradeLevel . "}\n";
    $i = 1;
    foreach ($sections as $catId => $catData) {
        $catDe = (string)($catData['de'] ?? 'Sonstiges');
        $macro = latex_macro_name_for_category($i, $catDe);
        $out .= "% SECTION: " . latex_escape($catDe) . "\n";
        $macroBody = "";
        $subSkillMacro = $enableStudentTeacherRatings ? 'SubSkillST' : 'SubSkill';
        $infoSkillMacro = $enableStudentTeacherRatings ? 'InfoSkillRowST' : 'InfoSkillRow';
        $skillRowMacro = $enableStudentTeacherRatings ? 'SkillRowST' : 'SkillRow';
        foreach (($catData['subs'] ?? []) as $subDe => $subData) {
            if ($subDe !== '') {
                $macroBody .= "  \\" . $subSkillMacro . "{" . latex_escape($subDe) . "}{" . latex_escape((string)($subData['en'] ?? '')) . "}\n";
            }
            foreach (($subData['items'] ?? []) as $it) {
                $code = trim((string)($it['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $fieldPrefix = 'skillcb-cid-' . (string)($it['id'] ?? '');
                $displayType = (string)($it['display_type'] ?? 'rated');
                if ($displayType === 'info') {
                    $rawDe = (string)($it['text_de'] ?? '');
                    $rawEn = (string)($it['text_en'] ?? '');
                    $de = latex_escape_with_inline_placeholders($rawDe, $fieldPrefix);
                    $en = latex_escape_with_inline_placeholders($rawEn, $fieldPrefix);
                    $hasDe = trim($rawDe) !== '';
                    $hasEn = trim($rawEn) !== '';

                    if ($hasDe && $hasEn) {
                        $infoText = $de . ' \\textbar{} \\textit{' . $en . '}';
                    } elseif ($hasDe) {
                        $infoText = $de;
                    } else {
                        $infoText = '\\textit{' . $en . '}';
                    }

                    $macroBody .= "  \\" . $infoSkillMacro . "{" . $infoText . "}\n";
                } else {
                    $macroBody .= "  \\" . $skillRowMacro . "{" . latex_escape($code) . "}%\n";
                    $macroBody .= "    {" . latex_escape_with_inline_placeholders((string)$it['text_de'], $fieldPrefix) . "}%\n";
                    $macroBody .= "    {" . latex_escape_with_inline_placeholders((string)($it['text_en'] ?? ''), $fieldPrefix) . "}\n";
                }
            }
        }
        $out .= latex_newcommand($macro, $macroBody);
        $sections[$catId]['macro'] = $macro;
        $i++;
    }

    $out .= "\\newcommand{\\AllSkillSections}{%\n";
    $pagebreakMap = [];
    foreach ($pagebreakCategoryIds as $id) {
        $parsed = (int)$id;
        if ($parsed > 0) {
            $pagebreakMap[$parsed] = true;
        }
    }
    $gradeFieldMap = [];
    foreach ($gradeFieldCategoryIds as $id) {
        $parsed = (int)$id;
        if ($parsed > 0) { $gradeFieldMap[$parsed] = true; }
    }
    foreach ($sections as $catId => $catData) {
        $hasPageBreak = isset($pagebreakMap[(int)$catId]);
        $hasGradeField = isset($gradeFieldMap[(int)$catId]);
        if ($enableStudentTeacherRatings) {
            if ($hasPageBreak) {
                $cmd = $hasGradeField ? 'SkillSectionSTWithGradePageBreak' : 'SkillSectionSTPageBreak';
            } else {
                $cmd = $hasGradeField ? 'SkillSectionSTWithGrade' : 'SkillSectionST';
            }
        } else {
            if ($hasPageBreak) {
                $cmd = $hasGradeField ? 'SkillSectionWithGradePageBreak' : 'SkillSectionPageBreak';
            } else {
                $cmd = $hasGradeField ? 'SkillSectionWithGrade' : 'SkillSection';
            }
        }
        $fieldName = 'grade-' . (int)$catId;
        if ($hasGradeField) {
            $out .= "  \\" . $cmd . "{" . latex_escape((string)($catData['de'] ?? 'Sonstiges')) . "}{" . latex_escape((string)($catData['en'] ?? '')) . "}{" . latex_escape($fieldName) . "}{\\" . $catData['macro'] . "}%\n";
        } else {
            $out .= "  \\" . $cmd . "{" . latex_escape((string)($catData['de'] ?? 'Sonstiges')) . "}{" . latex_escape((string)($catData['en'] ?? '')) . "}{\\" . $catData['macro'] . "}%\n";
        }
    }
    $out .= "}\n";

    return $out;
}

function generate_rcff_data(PDO $pdo, array $selectedCodes, int $gradeLevel = 1, bool $enableStudentTeacherRatings = false, array $gradeFieldCategoryIds = []): array {
    $gradeLevel = max(1, min(4, $gradeLevel));
    $rcff = [
        'format' => 'rcff',
        'version' => 1,
        'source' => 'leb_tool',
        'created_at' => gmdate('c'),
        'grade_level' => $gradeLevel,
        'student_teacher_ratings' => $enableStudentTeacherRatings,
        'fields' => [],
    ];
    if (!$selectedCodes) return $rcff;
    $in = implode(',', array_fill(0, count($selectedCodes), '?'));
    $sql = "SELECT c.id, c.code, c.text_de, c.text_en, COALESCE(c.display_type,'rated') AS display_type, COALESCE(c.category_id, s.category_id) AS category_id, s.name_de AS sub_de, s.name_en AS sub_en, cat.name_de AS cat_de, cat.name_en AS cat_en FROM competencies c LEFT JOIN competency_subcategories s ON s.id=c.subcategory_id LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id,s.category_id) WHERE c.code IN ($in) AND c.is_active=1";
    $st = $pdo->prepare($sql);
    $st->execute($selectedCodes);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $gradeMap = array_fill_keys(array_map('intval', $gradeFieldCategoryIds), true);
    foreach ($rows as $it) {
        $code = trim((string)($it['code'] ?? ''));
        if ($code === '') continue;
        $base = [
            'label_de' => (string)($it['text_de'] ?? ''),
            'label_en' => (string)($it['text_en'] ?? ''),
            'category_de' => (string)($it['cat_de'] ?? ''),
            'category_en' => (string)($it['cat_en'] ?? ''),
            'subcategory_de' => (string)($it['sub_de'] ?? ''),
            'subcategory_en' => (string)($it['sub_en'] ?? ''),
            'competency_code' => $code,
            'competency_id' => (int)($it['id'] ?? 0),
        ];
        if ((string)($it['display_type'] ?? 'rated') === 'info') {
            $prefix = 'skillcb-cid-' . (string)($it['id'] ?? '');
            foreach (collect_inline_field_metadata((string)($it['text_de'] ?? ''), $prefix, $base) as $f) $rcff['fields'][] = $f;
            foreach (collect_inline_field_metadata((string)($it['text_en'] ?? ''), $prefix, $base) as $f) $rcff['fields'][] = $f;
            continue;
        }
        if ($enableStudentTeacherRatings) {
            $rcff['fields'][] = $base + ['field_name' => $code . '-T', 'type' => 'rating', 'role' => 'teacher', 'rating_values' => ['na','be','pr','st','ma']];
            $rcff['fields'][] = $base + ['field_name' => $code . '-S', 'type' => 'rating', 'role' => 'student', 'rating_values' => ['be','pr','st','ma']];
        } else {
            $rcff['fields'][] = $base + ['field_name' => $code, 'type' => 'rating', 'role' => 'default', 'rating_values' => ['na','be','pr','st','ma']];
        }
        $catId = (int)($it['category_id'] ?? 0);
        if ($catId > 0 && isset($gradeMap[$catId])) {
            $rcff['fields'][] = [
                'field_name' => 'grade-' . $catId,
                'type' => 'grade',
                'label_de' => 'Note',
                'label_en' => 'Grade',
                'category_de' => (string)($it['cat_de'] ?? ''),
                'category_en' => (string)($it['cat_en'] ?? ''),
                'category_id' => $catId,
            ];
            unset($gradeMap[$catId]);
        }
    }
    return $rcff;
}
