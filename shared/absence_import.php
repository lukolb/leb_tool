<?php
declare(strict_types=1);

function absence_import_clean_string(?string $raw): string {
  $s = html_entity_decode((string)($raw ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = preg_replace('/^\xEF\xBB\xBF/u', '', $s) ?? $s;
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
  $s = str_replace("\xC2\xA0", ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
  return trim($s);
}

function absence_import_normalize_token(string $s): string {
  $s = absence_import_clean_string($s);
  $s = mb_strtolower($s, 'UTF-8');
  $map = ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue'];
  $s = strtr($s, $map);
  if (function_exists('iconv')) {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (is_string($ascii) && $ascii !== '') $s = $ascii;
  }
  $s = preg_replace('/[^a-z0-9]+/i', '', $s) ?? $s;
  return trim($s);
}

function parse_csv_student_name(?string $rawName): array {
  $raw = absence_import_clean_string($rawName);
  if ($raw === '') {
    return ['ok' => false, 'raw' => '', 'last_name' => '', 'first_name' => '', 'normalized_full_name' => '', 'warning' => t('admin.students.import.absence.reason.name_empty', 'Name fehlt.')];
  }
  if (str_contains($raw, ',')) {
    [$last, $first] = array_pad(array_map('absence_import_clean_string', explode(',', $raw, 2)), 2, '');
  } else {
    $parts = preg_split('/\s+/u', $raw) ?: [];
    if (count($parts) < 2) {
      return ['ok' => false, 'raw' => $raw, 'last_name' => $raw, 'first_name' => '', 'normalized_full_name' => absence_import_normalize_token($raw), 'warning' => t('admin.students.import.absence.reason.name_unclear', 'Name konnte nicht sicher in Nachname/Vorname getrennt werden.')];
    }
    $last = (string)array_shift($parts);
    $first = implode(' ', $parts);
  }
  $last = absence_import_clean_string($last);
  $first = absence_import_clean_string($first);
  if ($last === '' || $first === '') {
    return ['ok' => false, 'raw' => $raw, 'last_name' => $last, 'first_name' => $first, 'normalized_full_name' => absence_import_normalize_token($raw), 'warning' => t('admin.students.import.absence.reason.name_unclear', 'Name konnte nicht sicher in Nachname/Vorname getrennt werden.')];
  }
  $lastParts = preg_split('/\s+/u', $last) ?: [];
  $warning = null;
  if (count($lastParts) > 1) {
    $warning = t('admin.students.import.absence.reason.name_multi_last', 'Nachname enthält mehrere Wörter; automatische Zuordnung nur bei eindeutigem Full-Name-Treffer.');
  }
  return [
    'ok' => true,
    'raw' => $raw,
    'last_name' => $last,
    'first_name' => $first,
    'normalized_full_name' => absence_import_normalize_token($last . ' ' . $first),
    'warning' => $warning,
  ];
}

function parse_absence_value(?string $raw, string $fieldLabel): array {
  $v = absence_import_clean_string($raw);
  if ($v === '') return ['ok' => false, 'value' => null, 'is_empty' => true, 'warning' => strtr(t('admin.students.import.absence.reason.empty_value', '{field}: leerer Wert wird nicht importiert.'), ['{field}' => $fieldLabel])];
  $vl = mb_strtolower($v, 'UTF-8');
  if (in_array($vl, ['-', '—', '–', 'n/a', 'na'], true)) {
    return ['ok' => false, 'value' => null, 'is_empty' => false, 'warning' => strtr(t('admin.students.import.absence.reason.invalid_value', '{field}: ungültiger Wert "{value}".'), ['{field}' => $fieldLabel, '{value}' => $v])];
  }
  $num = str_replace(',', '.', $v);
  if (!preg_match('/^\d+(?:\.\d+)?$/', $num)) {
    return ['ok' => false, 'value' => null, 'is_empty' => false, 'warning' => strtr(t('admin.students.import.absence.reason.invalid_value', '{field}: ungültiger Wert "{value}".'), ['{field}' => $fieldLabel, '{value}' => $v])];
  }
  $float = (float)$num;
  if ($float < 0 || floor($float) !== $float) {
    return ['ok' => false, 'value' => null, 'is_empty' => false, 'warning' => strtr(t('admin.students.import.absence.reason.invalid_value', '{field}: ungültiger Wert "{value}".'), ['{field}' => $fieldLabel, '{value}' => $v])];
  }
  return ['ok' => true, 'value' => (int)$float, 'is_empty' => false, 'warning' => null];
}

function absence_import_detect_delimiter(string $sample): string {
  $candidates = ["\t", ';', ','];
  $best = "\t";
  $bestCount = -1;
  foreach ($candidates as $delimiter) {
    $cols = str_getcsv($sample, $delimiter, '"');
    $count = is_array($cols) ? count($cols) : 0;
    if ($count > $bestCount) {
      $bestCount = $count;
      $best = $delimiter;
    }
  }
  return $best;
}

function read_absence_csv_rows(string $path, ?string &$delimiterOut = null): array {
  $content = file_get_contents($path);
  if ($content === false) throw new RuntimeException(t('admin.students.error.csv_open_failed'));
  $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
  $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
  $first = '';
  foreach ($lines as $line) {
    if (trim($line) !== '') { $first = $line; break; }
  }
  $delimiter = absence_import_detect_delimiter($first);
  $delimiterOut = $delimiter;
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException(t('admin.students.error.csv_open_failed'));
  $rows = [];
  while (($row = fgetcsv($fh, 0, $delimiter, '"')) !== false) {
    if (!is_array($row)) continue;
    $row = array_map('absence_import_clean_string', $row);
    if (!array_filter($row, static fn($v) => trim((string)$v) !== '')) continue;
    $rows[] = $row;
  }
  fclose($fh);
  return $rows;
}

function absence_import_student_index(PDO $pdo, string $schoolYear, string $periodLabel): array {
  $st = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.class_id
     FROM students s
     INNER JOIN classes c ON c.id=s.class_id
     WHERE c.school_year=? AND c.period_label=? AND s.is_active=1"
  );
  $st->execute([$schoolYear, $periodLabel]);
  $byClass = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int)($r['id'] ?? 0);
    $cid = (int)($r['class_id'] ?? 0);
    if ($sid <= 0 || $cid <= 0) continue;
    $first = absence_import_clean_string($r['first_name'] ?? '');
    $last = absence_import_clean_string($r['last_name'] ?? '');
    $display = trim($last . ', ' . $first);
    $entry = ['id' => $sid, 'first_name' => $first, 'last_name' => $last, 'name' => $display, 'class_id' => $cid];
    foreach ([
      absence_import_normalize_token($last . ' ' . $first),
      absence_import_normalize_token($first . ' ' . $last),
      absence_import_normalize_token($last . ',' . $first),
    ] as $key) {
      if ($key === '') continue;
      $byClass[$cid]['keys'][$key][$sid] = $entry;
    }
    $byClass[$cid]['students'][$sid] = $entry;
  }
  return $byClass;
}

function absence_import_match_student(array $studentIndex, int $classId, string $rawName): array {
  $parsed = parse_csv_student_name($rawName);
  $classStudents = $studentIndex[$classId] ?? ['keys' => [], 'students' => []];
  $rawKey = absence_import_normalize_token($rawName);
  $keys = [$rawKey];
  if (($parsed['last_name'] ?? '') !== '' && ($parsed['first_name'] ?? '') !== '') {
    $keys[] = absence_import_normalize_token((string)$parsed['last_name'] . ' ' . (string)$parsed['first_name']);
    $keys[] = absence_import_normalize_token((string)$parsed['first_name'] . ' ' . (string)$parsed['last_name']);
    $keys[] = absence_import_normalize_token((string)$parsed['last_name'] . ',' . (string)$parsed['first_name']);
  }
  $matches = [];
  foreach (array_values(array_unique(array_filter($keys))) as $key) {
    foreach (($classStudents['keys'][$key] ?? []) as $sid => $entry) $matches[$sid] = $entry;
  }
  $suggestions = [];
  foreach (($classStudents['students'] ?? []) as $sid => $entry) {
    $candidate = absence_import_normalize_token((string)$entry['last_name'] . ' ' . (string)$entry['first_name']);
    if ($rawKey !== '' && (str_contains($candidate, $rawKey) || str_contains($rawKey, $candidate) || levenshtein(substr($rawKey, 0, 64), substr($candidate, 0, 64)) <= 4)) {
      $suggestions[$sid] = $entry;
    }
  }
  if (count($matches) === 1) {
    $student = array_values($matches)[0];
    return ['status' => 'matched', 'student_id' => (int)$student['id'], 'student' => $student, 'parsed' => $parsed, 'suggestions' => array_values($suggestions), 'warning' => $parsed['warning'] ?? null];
  }
  if (count($matches) > 1) {
    return ['status' => 'ambiguous', 'student_id' => 0, 'student' => null, 'parsed' => $parsed, 'suggestions' => array_values($matches), 'warning' => t('admin.students.import.absence.reason.student_ambiguous', 'Mehrere mögliche Schüler gefunden.')];
  }
  return ['status' => 'not_found', 'student_id' => 0, 'student' => null, 'parsed' => $parsed, 'suggestions' => array_values(array_slice($suggestions, 0, 5, true)), 'warning' => $parsed['warning'] ?: t('admin.students.import.absence.reason.student_not_found', 'Schüler nicht gefunden.')];
}
