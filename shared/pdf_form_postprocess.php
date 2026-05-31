<?php
declare(strict_types=1);

function pdf_form_cli_tool_path(string $tool): string {
    $tool = preg_replace('/[^A-Za-z0-9_.-]/', '', $tool) ?: '';
    if ($tool === '') return '';
    $cmd = 'command -v ' . escapeshellarg($tool) . ' 2>/dev/null';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code !== 0 || empty($out[0])) return '';
    $path = trim((string)$out[0]);
    return is_executable($path) ? $path : '';
}

function pdf_form_extract_field_names(string $pdfBytes): array {
    preg_match_all('/\/T\s*\(([^()]*)\)/s', $pdfBytes, $matches);
    $names = [];
    foreach (($matches[1] ?? []) as $raw) {
        $name = stripcslashes((string)$raw);
        if ($name !== '') $names[] = $name;
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);
    return $names;
}

function pdf_form_decode_literal_string(string $raw): string {
    $out = '';
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $ch = $raw[$i];
        if ($ch !== '\\') { $out .= $ch; continue; }
        $i++;
        if ($i >= $len) break;
        $esc = $raw[$i];
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($map[$esc])) { $out .= $map[$esc]; continue; }
        if ($esc >= '0' && $esc <= '7') {
            $oct = $esc;
            for ($j = 0; $j < 2 && $i + 1 < $len && $raw[$i + 1] >= '0' && $raw[$i + 1] <= '7'; $j++) {
                $oct .= $raw[++$i];
            }
            $out .= chr(octdec($oct));
            continue;
        }
        $out .= $esc;
    }
    if (strncmp($out, "\xFE\xFF", 2) === 0 && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding(substr($out, 2), 'UTF-8', 'UTF-16BE');
        if (is_string($converted) && $converted !== '') return $converted;
    }
    return $out;
}

function pdf_form_extract_pdf_string_value(string $dict, string $key): string {
    $keyPattern = preg_quote($key, '/');
    if (preg_match('/\/' . $keyPattern . '\s*\((([^\\\\()]|\\\\.)*)\)/s', $dict, $m)) {
        return pdf_form_decode_literal_string((string)$m[1]);
    }
    if (preg_match('/\/' . $keyPattern . '\s*<([0-9A-Fa-f\s]+)>/s', $dict, $m)) {
        $hex = preg_replace('/\s+/', '', (string)$m[1]) ?? '';
        if ($hex !== '') {
            $bin = @hex2bin(strlen($hex) % 2 === 0 ? $hex : ($hex . '0'));
            if (is_string($bin)) return pdf_form_decode_literal_string($bin);
        }
    }
    if (preg_match('/\/' . $keyPattern . '\s*\/([^\s\[\]<>()]+)/s', $dict, $m)) {
        return (string)$m[1];
    }
    return '';
}

function pdf_form_collect_indirect_objects(string $pdfBytes): array {
    preg_match_all('/\b(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj\b/s', $pdfBytes, $matches, PREG_SET_ORDER);
    $objects = [];
    foreach ($matches as $m) {
        $obj = (int)$m[1];
        $gen = (int)$m[2];
        $objects[$obj . ' ' . $gen] = ['obj' => $obj, 'gen' => $gen, 'body' => (string)$m[3]];
    }
    return $objects;
}

function pdf_form_count_fields_array_entries(string $dict, array $objects): int {
    if (preg_match('/\/Fields\s*\[(.*?)\]/s', $dict, $m)) {
        preg_match_all('/\b\d+\s+\d+\s+R\b/', (string)$m[1], $refs);
        return count($refs[0] ?? []);
    }
    if (preg_match('/\/Fields\s+(\d+)\s+(\d+)\s+R\b/s', $dict, $m)) {
        $ref = (int)$m[1] . ' ' . (int)$m[2];
        $body = (string)($objects[$ref]['body'] ?? '');
        if ($body !== '' && preg_match('/\[(.*?)\]/s', $body, $am)) {
            preg_match_all('/\b\d+\s+\d+\s+R\b/', (string)$am[1], $refs);
            return count($refs[0] ?? []);
        }
        return 1;
    }
    return 0;
}

function pdf_form_extract_acroform_dictionary(string $pdfBytes, array $objects): array {
    $out = ['dict' => '', 'obj' => 0, 'gen' => 0, 'body' => ''];
    if (preg_match('/\/AcroForm\s+(\d+)\s+(\d+)\s+R\b/s', $pdfBytes, $m)) {
        $ref = (int)$m[1] . ' ' . (int)$m[2];
        $obj = $objects[$ref] ?? null;
        if ($obj) {
            $body = trim((string)$obj['body']);
            $start = strpos($body, '<<');
            if ($start !== false) {
                $end = pdf_find_outer_dictionary_end($body, $start);
                if ($end !== null) {
                    return ['dict' => substr($body, $start, $end - $start + 2), 'obj' => (int)$obj['obj'], 'gen' => (int)$obj['gen'], 'body' => $body];
                }
            }
            return ['dict' => $body, 'obj' => (int)$obj['obj'], 'gen' => (int)$obj['gen'], 'body' => $body];
        }
    }
    if (preg_match('/\/AcroForm\s*<</s', $pdfBytes, $m, PREG_OFFSET_CAPTURE)) {
        $start = (int)$m[0][1] + strlen('/AcroForm');
        $dictStart = strpos($pdfBytes, '<<', $start);
        if ($dictStart !== false) {
            $end = pdf_find_outer_dictionary_end($pdfBytes, $dictStart);
            if ($end !== null) $out['dict'] = substr($pdfBytes, $dictStart, $end - $dictStart + 2);
        }
    }
    return $out;
}

function pdf_form_widget_objects(string $pdfBytes): array {
    $objects = pdf_form_collect_indirect_objects($pdfBytes);
    $widgets = [];
    foreach ($objects as $obj) {
        $body = (string)$obj['body'];
        if (!preg_match('/\/Subtype\s*\/Widget\b/', $body)) continue;
        $name = pdf_form_extract_pdf_string_value($body, 'T');
        $ft = '';
        if (preg_match('/\/FT\s*\/([A-Za-z0-9]+)/', $body, $m)) $ft = '/' . (string)$m[1];
        $ff = 0;
        if (preg_match('/\/Ff\s+(-?\d+)/', $body, $m)) $ff = (int)$m[1];
        $rect = null;
        if (preg_match('/\/Rect\s*\[([^\]]+)\]/s', $body, $m)) {
            preg_match_all('/[-+]?\d*\.?\d+(?:[Ee][-+]?\d+)?/', (string)$m[1], $nums);
            $vals = array_map('floatval', array_slice($nums[0] ?? [], 0, 4));
            if (count($vals) === 4) $rect = $vals;
        }
        $widgets[] = [
            'obj' => (int)$obj['obj'],
            'gen' => (int)$obj['gen'],
            'ref' => (int)$obj['obj'] . ' ' . (int)$obj['gen'] . ' R',
            'body' => $body,
            'name' => $name,
            'ft' => $ft,
            'ff' => $ff,
            'rect' => $rect,
            'has_T' => $name !== '',
            'has_FT' => $ft !== '',
            'has_parent' => (bool)preg_match('/\/Parent\s+\d+\s+\d+\s+R\b/', $body),
            'has_AP' => (bool)preg_match('/\/AP\b/', $body),
            'has_DA' => (bool)preg_match('/\/DA\b/', $body),
            'has_MK' => (bool)preg_match('/\/MK\b/', $body),
        ];
    }
    return $widgets;
}

function inspect_pdf_form_structure_from_bytes(string $pdfBytes): array {
    $objects = pdf_form_collect_indirect_objects($pdfBytes);
    $acro = pdf_form_extract_acroform_dictionary($pdfBytes, $objects);
    $acroDict = (string)($acro['dict'] ?? '');
    $widgets = pdf_form_widget_objects($pdfBytes);
    $fieldsCount = $acroDict !== '' ? pdf_form_count_fields_array_entries($acroDict, $objects) : 0;
    $need = null;
    if ($acroDict !== '' && preg_match('/\/NeedAppearances\s+(true|false)/i', $acroDict, $needMatches)) {
        $need = strtolower((string)$needMatches[1]) === 'true';
    }
    $widgetNames = [];
    foreach ($widgets as $w) {
        if (($w['name'] ?? '') !== '') $widgetNames[] = (string)$w['name'];
    }
    $widgetNames = array_values(array_unique($widgetNames));
    sort($widgetNames, SORT_STRING);
    $warning = '';
    if ($acroDict !== '' && $fieldsCount === 0 && count($widgets) > 0) {
        $warning = 'PDF contains widget annotations, but AcroForm /Fields is empty.';
    }
    return [
        'has_acroform' => strpos($pdfBytes, '/AcroForm') !== false,
        'acroform_has_fields' => $fieldsCount > 0,
        'acroform_fields_count' => $fieldsCount,
        'page_widget_annotations_count' => count($widgets),
        'widgets_with_T_count' => count(array_filter($widgets, static fn(array $w): bool => !empty($w['has_T']))),
        'widgets_with_FT_count' => count(array_filter($widgets, static fn(array $w): bool => !empty($w['has_FT']))),
        'widgets_with_parent_count' => count(array_filter($widgets, static fn(array $w): bool => !empty($w['has_parent']))),
        'widgets_with_AP_count' => count(array_filter($widgets, static fn(array $w): bool => !empty($w['has_AP']))),
        'widgets_with_DA_count' => count(array_filter($widgets, static fn(array $w): bool => !empty($w['has_DA']))),
        'widgets_with_MK_count' => count(array_filter($widgets, static fn(array $w): bool => !empty($w['has_MK']))),
        'need_appearances' => $need,
        'acroform_DA' => $acroDict !== '' && preg_match('/\/DA\b/', $acroDict) === 1,
        'acroform_DR' => $acroDict !== '' && preg_match('/\/DR\b/', $acroDict) === 1,
        'acroform_object' => ((int)($acro['obj'] ?? 0) > 0) ? ((int)$acro['obj'] . ' ' . (int)$acro['gen'] . ' R') : '',
        'widget_field_names_count' => count($widgetNames),
        'widget_field_names_sha1' => sha1(implode("\n", $widgetNames)),
        'warning' => $warning,
    ];
}

function inspect_pdf_form_structure(string $pdfPath): array {
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        return ['error' => 'PDF file is not readable.'];
    }
    return inspect_pdf_form_structure_from_bytes((string)file_get_contents($pdfPath));
}

function pdf_form_type_from_widget(array $widget): string {
    $ft = strtoupper((string)($widget['ft'] ?? ''));
    $ff = (int)($widget['ff'] ?? 0);
    if ($ft === '/TX') return 'text';
    if ($ft === '/CH') return 'select';
    if ($ft === '/SIG') return 'signature';
    if ($ft === '/BTN') return (($ff & 32768) !== 0) ? 'radio' : 'checkbox';
    return 'radio';
}

function pdf_form_page_widget_ref_map(string $pdfBytes): array {
    $objects = pdf_form_collect_indirect_objects($pdfBytes);
    $map = [];
    $pageNo = 0;
    foreach ($objects as $obj) {
        $body = (string)$obj['body'];
        if (!preg_match('/\/Type\s*\/Page\b/', $body) || preg_match('/\/Type\s*\/Pages\b/', $body)) continue;
        $pageNo++;
        if (!preg_match('/\/Annots\s*(\[(.*?)\]|(\d+)\s+(\d+)\s+R)/s', $body, $m)) continue;
        $annots = (string)($m[2] ?? '');
        if ($annots === '' && !empty($m[3])) {
            $ref = (int)$m[3] . ' ' . (int)$m[4];
            $annots = (string)($objects[$ref]['body'] ?? '');
        }
        preg_match_all('/\b(\d+)\s+(\d+)\s+R\b/', $annots, $refs, PREG_SET_ORDER);
        foreach ($refs as $r) {
            $map[(int)$r[1] . ' ' . (int)$r[2] . ' R'] = $pageNo;
        }
    }
    return $map;
}

function extract_pdf_widget_fields_from_bytes(string $pdfBytes): array {
    $pageMap = pdf_form_page_widget_ref_map($pdfBytes);
    $fields = [];
    $seen = [];
    $sort = 0;
    foreach (pdf_form_widget_objects($pdfBytes) as $widget) {
        $name = trim((string)($widget['name'] ?? ''));
        if ($name === '' || empty($widget['has_FT'])) continue;
        $type = pdf_form_type_from_widget($widget);
        if (!isset($seen[$name])) {
            $meta = [
                'source' => 'page_widget_annotation',
                'pdf_object_ref' => (string)$widget['ref'],
                'pdf_ft' => (string)$widget['ft'],
                'pdf_ff' => (int)$widget['ff'],
                'has_parent' => !empty($widget['has_parent']),
                'has_ap' => !empty($widget['has_AP']),
                'has_da' => !empty($widget['has_DA']),
                'has_mk' => !empty($widget['has_MK']),
            ];
            if (isset($pageMap[(string)$widget['ref']])) $meta['page'] = $pageMap[(string)$widget['ref']];
            if (is_array($widget['rect'] ?? null)) $meta['rect'] = $widget['rect'];
            $fields[] = [
                'name' => $name,
                'type' => $type,
                'label' => $name,
                'help_text' => '',
                'multiline' => false,
                'sort' => $sort++,
                'can_child_edit' => 0,
                'can_teacher_edit' => 1,
                'meta' => $meta,
            ];
            $seen[$name] = count($fields) - 1;
            continue;
        }
        $idx = $seen[$name];
        if ($type === 'radio') $fields[$idx]['type'] = 'radio';
        $fields[$idx]['meta']['widget_count'] = (int)($fields[$idx]['meta']['widget_count'] ?? 1) + 1;
    }
    return $fields;
}

function extract_pdf_widget_fields_from_file(string $pdfPath): array {
    if (!is_file($pdfPath) || !is_readable($pdfPath)) return [];
    return extract_pdf_widget_fields_from_bytes((string)file_get_contents($pdfPath));
}

function pdf_rebuild_acroform_fields_incremental(string $pdfBytes): array {
    $objects = pdf_form_collect_indirect_objects($pdfBytes);
    $acro = pdf_form_extract_acroform_dictionary($pdfBytes, $objects);
    $acroObj = (int)($acro['obj'] ?? 0);
    $acroGen = (int)($acro['gen'] ?? 0);
    $dict = (string)($acro['dict'] ?? '');
    if ($acroObj <= 0 || $dict === '') return ['success' => false, 'message' => 'AcroForm is not an indirect dictionary.'];
    $widgetRefs = [];
    foreach (pdf_form_widget_objects($pdfBytes) as $widget) {
        if (!empty($widget['has_T']) && !empty($widget['has_FT'])) $widgetRefs[] = (string)$widget['ref'];
    }
    $widgetRefs = array_values(array_unique($widgetRefs));
    if (!$widgetRefs) return ['success' => false, 'message' => 'No widget annotations with /T and /FT found.'];

    $newDict = $dict;
    $fieldsValue = '/Fields [' . implode(' ', $widgetRefs) . ']';
    if (preg_match('/\/Fields\s*\[(.*?)\]/s', $newDict)) {
        $newDict = preg_replace('/\/Fields\s*\[(.*?)\]/s', $fieldsValue, $newDict, 1) ?? $newDict;
    } elseif (preg_match('/\/Fields\s+\d+\s+\d+\s+R\b/s', $newDict)) {
        $newDict = preg_replace('/\/Fields\s+\d+\s+\d+\s+R\b/s', $fieldsValue, $newDict, 1) ?? $newDict;
    } else {
        $newDict = substr($newDict, 0, -2) . "\n" . $fieldsValue . "\n>>";
    }
    if (!preg_match('/\/DA\b/', $newDict)) {
        $newDict = substr($newDict, 0, -2) . "\n/DA (/Helv 10 Tf 0 g)\n>>";
    }
    if (!preg_match('/\/DR\b/', $newDict)) {
        $newDict = substr($newDict, 0, -2) . "\n/DR << /Font << /Helv << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >>\n>>";
    }
    if (preg_match('/\/NeedAppearances\s+(true|false)/i', $newDict)) {
        $newDict = preg_replace('/\/NeedAppearances\s+(true|false)/i', '/NeedAppearances true', $newDict, 1) ?? $newDict;
    } else {
        $newDict = substr($newDict, 0, -2) . "\n/NeedAppearances true\n>>";
    }
    $updatedBody = str_replace($dict, $newDict, (string)$acro['body']);

    if (!preg_match_all('/\b(\d+)\s+\d+\s+obj\b/', $pdfBytes, $objNums)) return ['success' => false, 'message' => 'Object count could not be determined.'];
    $maxObj = max(array_map('intval', $objNums[1]));
    $size = $maxObj + 1;
    if (preg_match_all('/\/Size\s+(\d+)/', $pdfBytes, $sizeMatches) && !empty($sizeMatches[1])) $size = max($size, (int)end($sizeMatches[1]));
    if (!preg_match_all('/startxref\s+(\d+)\s+%%EOF/s', $pdfBytes, $xrefMatches) || empty($xrefMatches[1])) return ['success' => false, 'message' => 'Previous xref offset could not be determined.'];
    $prevXref = (int)end($xrefMatches[1]);
    if (!preg_match_all('/\/Root\s+(\d+)\s+(\d+)\s+R/', $pdfBytes, $rootMatches, PREG_SET_ORDER) || empty($rootMatches)) return ['success' => false, 'message' => 'Root object could not be determined.'];
    $root = end($rootMatches);
    $rootRef = (int)$root[1] . ' ' . (int)$root[2] . ' R';

    $prefix = (str_ends_with($pdfBytes, "\n") ? '' : "\n");
    $objectOffset = strlen($pdfBytes . $prefix);
    $increment = $prefix . $acroObj . ' ' . $acroGen . " obj\n" . $updatedBody . "\nendobj\n";
    $xrefOffset = strlen($pdfBytes . $increment);
    $increment .= "xref\n" . $acroObj . " 1\n" . sprintf('%010d %05d n ', $objectOffset, $acroGen) . "\n";
    $increment .= "trailer\n<< /Size " . $size . " /Root " . $rootRef . " /Prev " . $prevXref . " >>\n";
    $increment .= "startxref\n" . $xrefOffset . "\n%%EOF\n";
    return ['success' => true, 'bytes' => $pdfBytes . $increment, 'message' => 'AcroForm /Fields was rebuilt from page widget annotations.', 'widgets_used' => count($widgetRefs)];
}

function pdf_form_diagnostics(string $pdfBytes): array {
    $structure = inspect_pdf_form_structure_from_bytes($pdfBytes);
    $fieldNames = pdf_form_extract_field_names($pdfBytes);
    return $structure + [
        'fields_count' => count($fieldNames),
        'field_names_sha1' => sha1(implode("\n", $fieldNames)),
        'widget_annotations_count' => (int)($structure['page_widget_annotations_count'] ?? 0),
        'appearance_streams_count' => (int)($structure['widgets_with_AP_count'] ?? 0),
        'default_appearance_count' => (int)($structure['widgets_with_DA_count'] ?? 0),
    ];
}

function pdf_find_outer_dictionary_end(string $text, int $start): ?int {
    $len = strlen($text);
    $depth = 0;
    $inString = false;
    $escape = false;
    for ($i = $start; $i < $len - 1; $i++) {
        $ch = $text[$i];
        if ($inString) {
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\') { $escape = true; continue; }
            if ($ch === ')') $inString = false;
            continue;
        }
        if ($ch === '(') { $inString = true; continue; }
        $pair = substr($text, $i, 2);
        if ($pair === '<<') { $depth++; $i++; continue; }
        if ($pair === '>>') {
            $depth--;
            if ($depth === 0) return $i;
            $i++;
        }
    }
    return null;
}

function pdf_set_need_appearances_incremental(string $pdfBytes): array {
    if (strpos($pdfBytes, '/AcroForm') === false) {
        return ['success' => false, 'message' => 'PDF enthält kein /AcroForm.'];
    }
    if (!preg_match('/\/AcroForm\s+(\d+)\s+(\d+)\s+R\b/', $pdfBytes, $acroRef)) {
        return ['success' => false, 'message' => 'AcroForm ist nicht als direkt auffindbares indirektes Objekt gespeichert.'];
    }
    $objNum = (int)$acroRef[1];
    $genNum = (int)$acroRef[2];
    $objPattern = '/\b' . preg_quote((string)$objNum, '/') . '\s+' . preg_quote((string)$genNum, '/') . '\s+obj\b(.*?)\bendobj\b/s';
    if (!preg_match($objPattern, $pdfBytes, $objMatch)) {
        return ['success' => false, 'message' => 'AcroForm-Objekt konnte nicht im PDF-Body gefunden werden.'];
    }
    $objectBody = trim((string)$objMatch[1]);
    $dictStart = strpos($objectBody, '<<');
    if ($dictStart === false) {
        return ['success' => false, 'message' => 'AcroForm-Objekt enthält kein Dictionary.'];
    }
    $dictEnd = pdf_find_outer_dictionary_end($objectBody, $dictStart);
    if ($dictEnd === null) {
        return ['success' => false, 'message' => 'AcroForm-Dictionary konnte nicht sicher gelesen werden.'];
    }
    $dict = substr($objectBody, $dictStart, $dictEnd - $dictStart + 2);
    if (preg_match('/\/NeedAppearances\s+(true|false)/i', $dict)) {
        $dict = preg_replace('/\/NeedAppearances\s+(true|false)/i', '/NeedAppearances true', $dict, 1) ?? $dict;
    } else {
        $dict = substr($dict, 0, -2) . "\n/NeedAppearances true\n>>";
    }
    $updatedBody = substr($objectBody, 0, $dictStart) . $dict . substr($objectBody, $dictEnd + 2);

    if (!preg_match_all('/\b(\d+)\s+\d+\s+obj\b/', $pdfBytes, $objNums)) {
        return ['success' => false, 'message' => 'Objektanzahl konnte nicht bestimmt werden.'];
    }
    $maxObj = max(array_map('intval', $objNums[1]));
    $size = $maxObj + 1;
    if (preg_match_all('/\/Size\s+(\d+)/', $pdfBytes, $sizeMatches) && !empty($sizeMatches[1])) {
        $size = max($size, (int)end($sizeMatches[1]));
    }
    if (!preg_match_all('/startxref\s+(\d+)\s+%%EOF/s', $pdfBytes, $xrefMatches) || empty($xrefMatches[1])) {
        return ['success' => false, 'message' => 'Vorheriger xref-Offset konnte nicht bestimmt werden.'];
    }
    $prevXref = (int)end($xrefMatches[1]);
    if (!preg_match_all('/\/Root\s+(\d+)\s+(\d+)\s+R/', $pdfBytes, $rootMatches, PREG_SET_ORDER) || empty($rootMatches)) {
        return ['success' => false, 'message' => 'Root-Objekt konnte nicht bestimmt werden.'];
    }
    $root = end($rootMatches);
    $rootRef = (int)$root[1] . ' ' . (int)$root[2] . ' R';

    $prefix = (str_ends_with($pdfBytes, "\n") ? '' : "\n");
    $objectOffset = strlen($pdfBytes . $prefix);
    $increment = $prefix . $objNum . ' ' . $genNum . " obj\n" . $updatedBody . "\nendobj\n";
    $xrefOffset = strlen($pdfBytes . $increment);
    $increment .= "xref\n" . $objNum . " 1\n" . sprintf('%010d %05d n ', $objectOffset, $genNum) . "\n";
    $increment .= "trailer\n<< /Size " . $size . " /Root " . $rootRef . " /Prev " . $prevXref . " >>\n";
    $increment .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

    return ['success' => true, 'bytes' => $pdfBytes . $increment, 'message' => 'NeedAppearances wurde per PDF-Incremental-Update gesetzt.'];
}

function pdf_run_pdftk_need_appearances(string $pdfBytes): array {
    $pdftk = pdf_form_cli_tool_path('pdftk');
    if ($pdftk === '') return ['success' => false, 'message' => 'pdftk nicht verfügbar.'];
    $in = tempnam(sys_get_temp_dir(), 'leb_pdf_in_');
    $out = tempnam(sys_get_temp_dir(), 'leb_pdf_out_');
    if ($in === false || $out === false) return ['success' => false, 'message' => 'Temporäre PDF-Datei konnte nicht angelegt werden.'];
    file_put_contents($in, $pdfBytes);
    $cmd = escapeshellarg($pdftk) . ' ' . escapeshellarg($in) . ' output ' . escapeshellarg($out) . ' need_appearances 2>&1';
    $lines = [];
    $code = 1;
    @exec($cmd, $lines, $code);
    $newBytes = ($code === 0 && is_file($out)) ? (string)file_get_contents($out) : '';
    @unlink($in); @unlink($out);
    if ($code !== 0 || strncmp($newBytes, '%PDF-', 5) !== 0) {
        return ['success' => false, 'message' => 'pdftk fehlgeschlagen: ' . trim(implode("\n", $lines))];
    }
    return ['success' => true, 'bytes' => $newBytes, 'message' => 'PDF-Formularfelder wurden mit pdftk/need_appearances normalisiert.', 'tool' => $pdftk];
}

function normalize_generated_pdf_form_fields(string $pdfBytes, array $options = []): array {
    $before = pdf_form_diagnostics($pdfBytes);
    $result = [
        'enabled' => true,
        'success' => false,
        'method' => 'none',
        'message' => '',
        'tool' => '',
        'acroform_before' => $before,
        'acroform_after' => $before,
        'field_names_unchanged' => true,
        'bytes' => $pdfBytes,
    ];
    if (!$before['has_acroform'] && (int)($before['page_widget_annotations_count'] ?? 0) === 0) {
        $result['success'] = true;
        $result['method'] = 'none';
        $result['message'] = 'PDF enthält keine Formularfelder/AcroForm; Postprocessing nicht erforderlich.';
        return $result;
    }

    $methods = ['pdftk'];
    if (!empty($before['has_acroform']) && empty($before['acroform_has_fields']) && (int)($before['page_widget_annotations_count'] ?? 0) > 0) {
        $methods[] = 'php_rebuild_acroform_fields_from_widgets';
    }
    $methods[] = 'php_incremental_need_appearances';

    foreach ($methods as $method) {
        $attempt = $method === 'pdftk'
            ? pdf_run_pdftk_need_appearances($pdfBytes)
            : ($method === 'php_rebuild_acroform_fields_from_widgets' ? pdf_rebuild_acroform_fields_incremental($pdfBytes) : pdf_set_need_appearances_incremental($pdfBytes));
        if (empty($attempt['success'])) {
            $result['message'] .= ($result['message'] !== '' ? ' ' : '') . '[' . $method . '] ' . (string)($attempt['message'] ?? 'fehlgeschlagen');
            continue;
        }
        $newBytes = (string)$attempt['bytes'];
        $after = pdf_form_diagnostics($newBytes);
        $beforeNamesHash = (string)($before['widget_field_names_sha1'] ?? $before['field_names_sha1'] ?? '');
        $afterNamesHash = (string)($after['widget_field_names_sha1'] ?? $after['field_names_sha1'] ?? '');
        $unchanged = ($beforeNamesHash === $afterNamesHash)
            && ((int)($before['widget_field_names_count'] ?? $before['fields_count'] ?? 0) === (int)($after['widget_field_names_count'] ?? $after['fields_count'] ?? 0));
        if (!$unchanged) {
            $result['message'] .= ($result['message'] !== '' ? ' ' : '') . '[' . $method . '] Feldnamen/-anzahl würden sich ändern; Ergebnis verworfen.';
            continue;
        }
        $result['success'] = true;
        $result['method'] = $method;
        $result['message'] = (string)($attempt['message'] ?? 'PDF-Formularfelder wurden normalisiert.');
        $result['tool'] = (string)($attempt['tool'] ?? 'PHP');
        $result['acroform_after'] = $after;
        $result['field_names_unchanged'] = true;
        $result['bytes'] = $newBytes;
        return $result;
    }

    $result['message'] = $result['message'] !== '' ? $result['message'] : 'Kein unterstütztes PDF-Postprocessing verfügbar.';
    return $result;
}
