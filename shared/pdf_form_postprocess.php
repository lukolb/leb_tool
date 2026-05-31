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

function pdf_form_diagnostics(string $pdfBytes): array {
    preg_match_all('/\/NeedAppearances\s+(true|false)/i', $pdfBytes, $needMatches);
    $need = null;
    if (!empty($needMatches[1])) {
        $last = strtolower((string)end($needMatches[1]));
        $need = ($last === 'true');
    }
    $fieldNames = pdf_form_extract_field_names($pdfBytes);
    return [
        'has_acroform' => strpos($pdfBytes, '/AcroForm') !== false,
        'need_appearances' => $need,
        'fields_count' => count($fieldNames),
        'field_names_sha1' => sha1(implode("\n", $fieldNames)),
        'widget_annotations_count' => preg_match_all('/\/Subtype\s*\/Widget\b/', $pdfBytes),
        'appearance_streams_count' => preg_match_all('/\/AP\b/', $pdfBytes),
        'default_appearance_count' => preg_match_all('/\/DA\b/', $pdfBytes),
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
    if (!$before['has_acroform']) {
        $result['success'] = true;
        $result['method'] = 'none';
        $result['message'] = 'PDF enthält keine Formularfelder/AcroForm; Postprocessing nicht erforderlich.';
        return $result;
    }

    foreach (['pdftk', 'php_incremental_need_appearances'] as $method) {
        $attempt = $method === 'pdftk' ? pdf_run_pdftk_need_appearances($pdfBytes) : pdf_set_need_appearances_incremental($pdfBytes);
        if (empty($attempt['success'])) {
            $result['message'] .= ($result['message'] !== '' ? ' ' : '') . '[' . $method . '] ' . (string)($attempt['message'] ?? 'fehlgeschlagen');
            continue;
        }
        $newBytes = (string)$attempt['bytes'];
        $after = pdf_form_diagnostics($newBytes);
        $unchanged = (($before['fields_count'] === $after['fields_count']) && ($before['field_names_sha1'] === $after['field_names_sha1']));
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
