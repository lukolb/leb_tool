<?php
declare(strict_types=1);

function pdf_decode_literal(string $raw): string {
  $out = '';
  $len = strlen($raw);
  for ($i = 0; $i < $len; $i++) {
    $ch = $raw[$i];
    if ($ch === '\\') {
      $i++;
      if ($i >= $len) break;
      $esc = $raw[$i];
      if ($esc === 'n') { $out .= "\n"; continue; }
      if ($esc === 'r') { $out .= "\r"; continue; }
      if ($esc === 't') { $out .= "\t"; continue; }
      if ($esc === 'b') { $out .= "\b"; continue; }
      if ($esc === 'f') { $out .= "\f"; continue; }
      if (ctype_digit($esc)) {
        $oct = $esc;
        for ($j = 0; $j < 2 && $i + 1 < $len && ctype_digit($raw[$i + 1]); $j++) {
          $i++;
          $oct .= $raw[$i];
        }
        $out .= chr(octdec($oct));
        continue;
      }
      $out .= $esc;
      continue;
    }
    $out .= $ch;
  }
  return $out;
}

function pdf_parse_string(?string $raw): string {
  if ($raw === null) return '';
  $raw = trim($raw);
  if ($raw === '') return '';
  if ($raw[0] === '(') {
    $raw = substr($raw, 1, -1);
    return pdf_decode_literal($raw);
  }
  if ($raw[0] === '<') {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
    $bin = '';
    for ($i = 0; $i < strlen($hex); $i += 2) {
      $bin .= chr(hexdec(substr($hex, $i, 2)));
    }
    return $bin;
  }
  return $raw;
}

function pdf_normalize_text(string $raw): string {
  if ($raw === '') return '';
  $prefix = substr($raw, 0, 2);
  if ($prefix === "\xFE\xFF" || $prefix === "\xFF\xFE") {
    $enc = ($prefix === "\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE';
    return (string)@mb_convert_encoding($raw, 'UTF-8', $enc);
  }
  $hasNulls = strpos($raw, "\x00") !== false;
  if ($hasNulls && (strlen($raw) % 2 === 0)) {
    $utf16 = (string)@mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
    if ($utf16 !== '') return $utf16;
  }
  return $raw;
}

function pdf_extract_objects(string $pdf): array {
  $objects = [];
  if (preg_match_all('/(\\d+)\\s+(\\d+)\\s+obj(.*?)endobj/s', $pdf, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $num = (int)$m[1];
      $body = $m[3];
      $stream = null;
      if (preg_match('/stream\\r?\\n(.*)\\r?\\nendstream/s', $body, $sm)) {
        $stream = $sm[1];
      }
      $objects[$num] = [
        'raw' => $body,
        'stream' => $stream,
      ];
    }
  }
  return $objects;
}

function pdf_decode_stream(string $raw, string $dict): string {
  if (preg_match('/\\/Filter\\s*\\[(.*?)\\]/s', $dict, $fm)) {
    $filters = $fm[1];
    if (stripos($filters, '/FlateDecode') !== false) {
      $decoded = @gzuncompress($raw);
      if (is_string($decoded)) return $decoded;
      $inflated = @gzinflate($raw);
      if (is_string($inflated)) return $inflated;
    }
  } elseif (preg_match('/\\/Filter\\s*\\/([A-Za-z0-9]+)/', $dict, $fm)) {
    if (strcasecmp($fm[1], 'FlateDecode') === 0) {
      $decoded = @gzuncompress($raw);
      if (is_string($decoded)) return $decoded;
      $inflated = @gzinflate($raw);
      if (is_string($inflated)) return $inflated;
    }
  }
  return $raw;
}

function pdf_extract_text_items(string $content): array {
  $items = [];
  $tokens = [];
  $len = strlen($content);
  $i = 0;
  while ($i < $len) {
    $ch = $content[$i];
    if ($ch === '%') {
      while ($i < $len && $content[$i] !== "\n") $i++;
      continue;
    }
    if (ctype_space($ch)) { $i++; continue; }
    if ($ch === '(') {
      $depth = 1;
      $start = $i;
      $i++;
      while ($i < $len && $depth > 0) {
        $c = $content[$i];
        if ($c === '\\') { $i += 2; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') $depth--;
        $i++;
      }
      $tokens[] = substr($content, $start, $i - $start);
      continue;
    }
    if ($ch === '[') {
      $depth = 1;
      $start = $i;
      $i++;
      while ($i < $len && $depth > 0) {
        $c = $content[$i];
        if ($c === '\\') { $i += 2; continue; }
        if ($c === '[') $depth++;
        if ($c === ']') $depth--;
        $i++;
      }
      $tokens[] = substr($content, $start, $i - $start);
      continue;
    }
    $start = $i;
    while ($i < $len && !ctype_space($content[$i])) $i++;
    $tokens[] = substr($content, $start, $i - $start);
  }

  $x = 0.0;
  $y = 0.0;
  $stack = [];
  foreach ($tokens as $tok) {
    $isNumber = preg_match('/^-?\\d*\\.?\\d+$/', $tok);
    if ($isNumber || $tok[0] === '(' || $tok[0] === '[' || $tok[0] === '<') {
      $stack[] = $tok;
      continue;
    }
    $op = $tok;
    if ($op === 'Tm' && count($stack) >= 6) {
      $vals = array_splice($stack, -6);
      $x = (float)$vals[4];
      $y = (float)$vals[5];
    } elseif (($op === 'Td' || $op === 'TD') && count($stack) >= 2) {
      $vals = array_splice($stack, -2);
      $x += (float)$vals[0];
      $y += (float)$vals[1];
    } elseif ($op === 'T*') {
      $y -= 12;
    } elseif ($op === 'Tj' && count($stack) >= 1) {
      $raw = array_pop($stack);
      $str = pdf_normalize_text(pdf_parse_string($raw));
      if ($str !== '') $items[] = ['str' => $str, 'x' => $x, 'y' => $y];
    } elseif ($op === 'TJ' && count($stack) >= 1) {
      $raw = array_pop($stack);
      if ($raw[0] === '[') {
        $inner = trim(substr($raw, 1, -1));
        $parts = preg_split('/(?<=\\))\\s+(?=[\\(\\[\\<\\-\\d])|(?<=\\>)\\s+(?=[\\(\\[\\<\\-\\d])/', $inner);
        $text = '';
        foreach ($parts as $part) {
          $part = trim($part);
          if ($part === '' || preg_match('/^-?\\d/', $part)) continue;
          $text .= pdf_normalize_text(pdf_parse_string($part));
        }
        if ($text !== '') $items[] = ['str' => $text, 'x' => $x, 'y' => $y];
      }
    } elseif ($op === "'" && count($stack) >= 1) {
      $raw = array_pop($stack);
      $str = pdf_normalize_text(pdf_parse_string($raw));
      if ($str !== '') $items[] = ['str' => $str, 'x' => $x, 'y' => $y];
    } elseif ($op === '"' && count($stack) >= 3) {
      $raw = array_pop($stack);
      $str = pdf_normalize_text(pdf_parse_string($raw));
      if ($str !== '') $items[] = ['str' => $str, 'x' => $x, 'y' => $y];
    } else {
      $stack = [];
    }
  }
  return $items;
}

function pdf_pick_label(array $textItems, array $rect): ?string {
  $cy = ($rect[1] + $rect[3]) / 2;
  $fh = abs($rect[3] - $rect[1]);
  $yTol = max(6, $fh * 0.6);
  $lineTol = max(3, $fh * 0.3);
  $yMin = min($rect[1], $rect[3]) - $yTol * 1.2;
  $yMax = max($rect[1], $rect[3]) + $yTol * 1.2;
  $xMin = min($rect[0], $rect[2]);
  $xMax = max($rect[0], $rect[2]);
  $xTol = max(6, abs($rect[2] - $rect[0]) * 0.15);

  $buildLines = static function(array $items) use ($lineTol): array {
    usort($items, static fn($a, $b) => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));
    $lines = [];
    foreach ($items as $item) {
      $found = false;
      foreach ($lines as &$line) {
        if (abs($line['y'] - $item['y']) <= $lineTol) {
          $line['items'][] = $item;
          $count = count($line['items']);
          $line['y'] = ($line['y'] * ($count - 1) + $item['y']) / $count;
          $found = true;
          break;
        }
      }
      unset($line);
      if (!$found) $lines[] = ['y' => $item['y'], 'items' => [$item]];
    }
    $out = [];
    foreach ($lines as $line) {
      usort($line['items'], static fn($a, $b) => $a['x'] <=> $b['x']);
      $text = trim(preg_replace('/\\s+/', ' ', implode(' ', array_map(static fn($it) => trim($it['str']), $line['items']))));
      if ($text === '') continue;
      $xs = array_column($line['items'], 'x');
      $out[] = ['y' => $line['y'], 'text' => $text, 'xMax' => max($xs), 'xMin' => min($xs)];
    }
    return $out;
  };

  $rowLeftItems = array_values(array_filter($textItems, static function($it) use ($xMin, $cy, $yTol, $fh) {
    if (!isset($it['str']) || trim($it['str']) === '') return false;
    if ($it['x'] > $xMin - 2) return false;
    return abs($it['y'] - $cy) <= max($yTol, $fh * 0.8);
  }));
  if ($rowLeftItems) {
    usort($rowLeftItems, static fn($a, $b) => $a['x'] <=> $b['x']);
    $segments = [];
    $seg = null;
    $gapTol = max(10, $fh * 0.8, $lineTol * 2);
    foreach ($rowLeftItems as $item) {
      if ($seg === null) {
        $seg = ['items' => [$item], 'xMax' => $item['x'], 'yAvg' => $item['y']];
        continue;
      }
      $gap = $item['x'] - $seg['xMax'];
      if ($gap > $gapTol) {
        $segments[] = $seg;
        $seg = ['items' => [$item], 'xMax' => $item['x'], 'yAvg' => $item['y']];
      } else {
        $seg['items'][] = $item;
        $seg['xMax'] = max($seg['xMax'], $item['x']);
        $count = count($seg['items']);
        $seg['yAvg'] = ($seg['yAvg'] * ($count - 1) + $item['y']) / $count;
      }
    }
    if ($seg) $segments[] = $seg;

    $candidates = array_values(array_filter($segments, static function($seg) use ($cy, $lineTol, $fh, $xMin) {
      return abs($seg['yAvg'] - $cy) <= max($lineTol * 2, $fh) && $seg['xMax'] <= $xMin + 2;
    }));
    if ($candidates) {
      usort($candidates, static fn($a, $b) => ($xMin - $a['xMax']) <=> ($xMin - $b['xMax']));
      $target = $candidates[0];
      $label = trim(preg_replace('/\\s+/', ' ', implode(' ', array_map(static fn($it) => trim($it['str']), $target['items']))));
      if (strlen($label) >= 2) return $label;
    }
  }

  $inColumnItems = array_values(array_filter($textItems, static function($it) use ($xMin, $xMax, $xTol, $yMax, $yTol) {
    if (!isset($it['str']) || trim($it['str']) === '') return false;
    if ($it['x'] < $xMin - $xTol || $it['x'] > $xMax + $xTol) return false;
    return $it['y'] >= $yMax && $it['y'] <= ($yMax + $yTol * 3);
  }));
  $columnLines = $buildLines($inColumnItems);
  if ($columnLines) {
    usort($columnLines, static fn($a, $b) => $b['y'] <=> $a['y']);
    $label = trim(preg_replace('/\\s+/', ' ', implode(' ', array_column($columnLines, 'text'))));
    if (strlen($label) >= 2) return $label;
  }

  $leftItems = array_values(array_filter($textItems, static function($it) use ($xMin, $yMin, $yMax) {
    if (!isset($it['str']) || trim($it['str']) === '') return false;
    if ($it['x'] > $xMin - 2) return false;
    return $it['y'] >= $yMin && $it['y'] <= $yMax;
  }));
  if (!$leftItems) return null;
  $lines = $buildLines($leftItems);
  if (!$lines) return null;
  usort($lines, static fn($a, $b) => ($xMin - $a['xMax']) <=> ($xMin - $b['xMax']));
  $anchor = $lines[0];
  $maxDx = ($xMin - $anchor['xMax']) + max(8, $fh * 0.4);
  $selected = array_values(array_filter($lines, static function($l) use ($xMin, $maxDx) {
    return ($xMin - $l['xMax']) <= $maxDx;
  }));
  usort($selected, static fn($a, $b) => $b['y'] <=> $a['y']);
  $label = trim(preg_replace('/\\s+/', ' ', implode(' ', array_column($selected, 'text'))));
  if (strlen($label) < 2) return null;
  return $label;
}

function extract_pdf_fields(string $absPath): array {
  $pdf = file_get_contents($absPath);
  if ($pdf === false) throw new RuntimeException('PDF konnte nicht gelesen werden.');
  $objects = pdf_extract_objects($pdf);

  $pageAnnots = [];
  $pageContents = [];
  $pageIndex = 0;
  $pageByObject = [];
  foreach ($objects as $num => $obj) {
    if (!preg_match('/\\/Type\\s*\\/Page\\b/', $obj['raw'])) continue;
    $pageIndex++;
    $pageByObject[$num] = $pageIndex;
    if (preg_match('/\\/Annots\\s*\\[(.*?)\\]/s', $obj['raw'], $am)) {
      preg_match_all('/(\\d+)\\s+\\d+\\s+R/', $am[1], $refs);
      foreach ($refs[1] as $ref) {
        $pageAnnots[(int)$ref] = $pageIndex;
      }
    }
    if (preg_match('/\\/Contents\\s*(\\[.*?\\]|\\d+\\s+\\d+\\s+R)/s', $obj['raw'], $cm)) {
      $contentRefs = [];
      if (str_starts_with(trim($cm[1]), '[')) {
        preg_match_all('/(\\d+)\\s+\\d+\\s+R/', $cm[1], $crefs);
        foreach ($crefs[1] as $ref) $contentRefs[] = (int)$ref;
      } else {
        if (preg_match('/(\\d+)\\s+\\d+\\s+R/', $cm[1], $cref)) $contentRefs[] = (int)$cref[1];
      }
      $pageContents[$pageIndex] = $contentRefs;
    }
  }

  $pageText = [];
  foreach ($pageContents as $page => $refs) {
    $textItems = [];
    foreach ($refs as $ref) {
      if (!isset($objects[$ref])) continue;
      $dict = $objects[$ref]['raw'];
      $stream = $objects[$ref]['stream'];
      if ($stream === null) continue;
      $decoded = pdf_decode_stream($stream, $dict);
      $textItems = array_merge($textItems, pdf_extract_text_items($decoded));
    }
    $pageText[$page] = $textItems;
  }

  $fields = [];
  $seen = [];
  $sort = 0;
  foreach ($objects as $num => $obj) {
    if (!preg_match('/\\/Subtype\\s*\\/Widget/', $obj['raw'])) continue;
    $raw = $obj['raw'];
    $name = '';
    $rawType = '';
    $flags = null;
    $hint = '';

    $seenParents = [];
    while (true) {
      if ($name === '' && preg_match('/\\/T\\s*(\\(.*?\\)|<.*?>)/s', $raw, $nm)) {
        $name = pdf_parse_string($nm[1]);
      }
      if ($rawType === '' && preg_match('/\\/FT\\s*\\/([A-Za-z]+)/', $raw, $tm)) {
        $rawType = $tm[1];
      }
      if ($flags === null && preg_match('/\\/Ff\\s+(\\d+)/', $raw, $fm)) {
        $flags = (int)$fm[1];
      }
      if ($hint === '' && preg_match('/\\/TU\\s*(\\(.*?\\)|<.*?>)/s', $raw, $hm)) {
        $hint = pdf_parse_string($hm[1]);
      }
      if ($name !== '' && $rawType !== '' && $flags !== null) break;

      if (!preg_match('/\\/Parent\\s+(\\d+)\\s+\\d+\\s+R/', $raw, $pm)) break;
      $parentId = (int)$pm[1];
      if (isset($seenParents[$parentId]) || !isset($objects[$parentId])) break;
      $seenParents[$parentId] = true;
      $raw = $objects[$parentId]['raw'];
    }

    $name = trim($name);
    if ($name === '') continue;

    $rect = null;
    if (preg_match('/\\/Rect\\s*\\[([^\\]]+)\\]/', $obj['raw'], $rm)) {
      $parts = preg_split('/\\s+/', trim($rm[1]));
      if (count($parts) >= 4) $rect = array_map('floatval', array_slice($parts, 0, 4));
    }

    if ($rawType === '' && preg_match('/\\/FT\\s*\\/([A-Za-z]+)/', $obj['raw'], $tm)) {
      $rawType = $tm[1];
    }
    $flags = $flags ?? 0;
    if ($flags === 0 && preg_match('/\\/Ff\\s+(\\d+)/', $obj['raw'], $fm)) {
      $flags = (int)$fm[1];
    }
    $multiline = ($flags & (1 << 12)) !== 0;
    $type = 'radio';
    if (strcasecmp($rawType, 'Tx') === 0) $type = $multiline ? 'multiline' : 'text';
    if (strcasecmp($rawType, 'Ch') === 0) $type = 'select';
    if (strcasecmp($rawType, 'Btn') === 0) {
      $isRadio = ($flags & (1 << 15)) !== 0;
      $type = $isRadio ? 'radio' : 'checkbox';
    }

    if ($hint === '' && preg_match('/\\/TU\\s*(\\(.*?\\)|<.*?>)/s', $obj['raw'], $hm)) {
      $hint = pdf_parse_string($hm[1]);
    }

    if (!isset($seen[$name])) {
      $fields[$name] = [
        'name' => $name,
        'type' => $type,
        'label' => $name,
        'help_text' => $hint,
        'multiline' => $multiline,
        'sort' => $sort++,
        'meta' => [
          'type' => $rawType,
        ],
      ];
      $seen[$name] = true;
    } else {
      if ($type === 'radio') $fields[$name]['type'] = 'radio';
      if ($fields[$name]['help_text'] === '' && $hint !== '') $fields[$name]['help_text'] = $hint;
    }

    if ($rect !== null) {
      $fields[$name]['meta']['rect'] = $rect;
      $page = null;
      if (preg_match('/\\/P\\s+(\\d+)\\s+\\d+\\s+R/', $obj['raw'], $pm)) {
        $pageRef = (int)$pm[1];
        if (isset($pageByObject[$pageRef])) $page = $pageByObject[$pageRef];
      }
      if ($page === null) $page = $pageAnnots[$num] ?? null;
      if ($page !== null) $fields[$name]['meta']['page'] = $page;
      if ($page !== null && isset($pageText[$page])) {
        $suggested = pdf_pick_label($pageText[$page], $rect);
        if ($suggested) {
          if ($fields[$name]['label'] === $fields[$name]['name']) $fields[$name]['label'] = $suggested;
          if ($fields[$name]['help_text'] === '' && strlen($suggested) > 18) $fields[$name]['help_text'] = $suggested;
        }
      }
    }
  }

  uasort($fields, static fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
  return array_values($fields);
}
