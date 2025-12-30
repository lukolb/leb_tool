<?php
// teacher/ajax/student_ai_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_teacher();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function json_out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function meta_read($metaJson): array {
  if ($metaJson === null) return [];
  $s = trim((string)$metaJson);
  if ($s === '') return [];
  $m = json_decode($s, true);
  return is_array($m) ? $m : [];
}
function label_for_lang(?string $de, ?string $en, string $lang): string {
  $de = trim((string)$de);
  $en = trim((string)$en);
  if ($lang === 'en' && $en !== '') return $en;
  return $de !== '' ? $de : $en;
}

// ==== Option list resolving (matches entry_api.php data model) ====
// meta_json uses: option_list_template_id -> option_list_templates.id
function option_list_id_from_meta(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}
function option_label(PDO $pdo, int $listId, ?int $itemId, ?string $value, string $lang): ?string {
  if ($listId <= 0) return null;

  if ($itemId !== null && $itemId > 0) {
    $st = $pdo->prepare("SELECT value,label,label_en FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$itemId, $listId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $lbl = label_for_lang((string)($row['label'] ?? ''), (string)($row['label_en'] ?? ''), $lang);
      $val = trim((string)($row['value'] ?? ''));
      return $lbl !== '' ? $lbl : ($val !== '' ? $val : null);
    }
  }

  $value = trim((string)$value);
  if ($value !== '') {
    $st = $pdo->prepare("SELECT value,label,label_en FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $value]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $lbl = label_for_lang((string)($row['label'] ?? ''), (string)($row['label_en'] ?? ''), $lang);
      $val = trim((string)($row['value'] ?? $value));
      return $lbl !== '' ? $lbl : ($val !== '' ? $val : $value);
    }
  }

  return null;
}

function resolve_value_for_ai(PDO $pdo, array $fieldRow, ?string $valueText, $valueJson, string $lang): string {
  $meta = meta_read($fieldRow['meta_json'] ?? null);
  $listId = option_list_id_from_meta($meta);

  // value_json can store {option_item_id:..} or arrays etc.
  $itemId = null;
  $fallback = '';

  if (is_array($valueJson)) {
    if (isset($valueJson['option_item_id'])) {
      $itemId = (int)$valueJson['option_item_id'];
      $fallback = (string)($valueText ?? '');
    } else {
      // arrays (multi-select): map each element
      $parts = [];
      foreach ($valueJson as $v) {
        if (is_array($v) && isset($v['option_item_id'])) {
          $lbl = option_label($pdo, $listId, (int)$v['option_item_id'], null, $lang);
          $parts[] = $lbl ?? (string)$v['option_item_id'];
        } else {
          $parts[] = trim((string)$v);
        }
      }
      $parts = array_values(array_filter($parts, fn($x)=>trim((string)$x)!=='' ));
      if ($parts) return implode(', ', $parts);
    }
  }

  $txt = trim((string)($valueText ?? ''));
  if ($listId > 0) {
    $lbl = option_label($pdo, $listId, $itemId, $txt, $lang);
    if ($lbl !== null && trim($lbl) !== '') return trim($lbl);
  }
  return $txt !== '' ? $txt : trim((string)$fallback);
}

// ==== OpenAI call (same pattern as entry_api.php) ====
function ai_provider_config(): array {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];
  $enabled = (int)($ai['enabled'] ?? 0) === 1;
  $apiKey = trim((string)($ai['api_key'] ?? ''));
  $baseUrl = rtrim(trim((string)($ai['base_url'] ?? 'https://api.openai.com/v1')), '/');
  $model = trim((string)($ai['model'] ?? 'gpt-4o-mini'));
  $timeout = (int)($ai['timeout_seconds'] ?? 40);
  if ($timeout <= 0) $timeout = 40;

  if (!$enabled) throw new RuntimeException('KI-Vorschläge sind deaktiviert.');
  if ($apiKey === '') throw new RuntimeException('Kein API-Key konfiguriert.');

  return [
    'api_key' => $apiKey,
    'base_url' => $baseUrl,
    'model' => $model,
    'timeout_seconds' => $timeout,
  ];
}
function ai_chat_completion(array $messages, array $aiCfg): string {
  $payload = [
    'model' => (string)$aiCfg['model'],
    'messages' => $messages,
    'temperature' => 0.4,
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => (string)$aiCfg['base_url'] . '/v1/chat/completions',
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . (string)$aiCfg['api_key'],
    ],
    CURLOPT_TIMEOUT => (int)$aiCfg['timeout_seconds'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ]);

  $resp = curl_exec($ch);
  $httpCode = (int)(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('AI request failed: ' . $err);
  }
  curl_close($ch);

  $json = json_decode($resp, true);
  if ($httpCode < 200 || $httpCode >= 300) {
    $msg = is_array($json) ? (string)($json['error']['message'] ?? '') : '';
    if ($msg === '') $msg = 'HTTP ' . $httpCode;
    throw new RuntimeException('AI error: ' . $msg);
  }

  $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
  if ($content === '') throw new RuntimeException('AI: leere Antwort.');
  return $content;
}

// ==== Cache ====
function cache_dir(): string {
  $dir = __DIR__ . '/../../cache/ai';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function cache_get(string $key, int $ttl, ?int &$ageSeconds = null): ?array {
  $file = cache_dir() . '/' . sha1($key) . '.json';
  if (!is_file($file)) return null;
  $raw = @file_get_contents($file);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['_ts'])) return null;
  $age = time() - (int)$data['_ts'];
  $ageSeconds = $age;
  if ($age < 0 || $age > $ttl) return null;
  return $data;
}
function cache_set(string $key, array $payload): void {
  $payload['_ts'] = time();
  $file = cache_dir() . '/' . sha1($key) . '.json';
  @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

try {
  $data = read_json_body();

  // like entry_api.php: accept CSRF in header
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  }
  csrf_verify();

  $pdo = db();
  $u = current_user();
  $lang = ui_lang();
  $userId = (int)($u['id'] ?? 0);

  $action = (string)($data['action'] ?? 'ai_student_support_plan');
  if ($action !== 'ai_student_support_plan') throw new RuntimeException('Unbekannte Aktion.');

  $classId = (int)($data['class_id'] ?? 0);
  $studentId = (int)($data['student_id'] ?? 0);
  $force = (int)($data['force'] ?? 0) === 1;

  if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
  if ($studentId <= 0) throw new RuntimeException('student_id fehlt.');

  if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
    throw new RuntimeException('Keine Berechtigung.');
  }

  $stStu = $pdo->prepare("SELECT id, class_id, first_name, last_name, date_of_birth FROM students WHERE id=? LIMIT 1");
  $stStu->execute([$studentId]);
  $stu = $stStu->fetch(PDO::FETCH_ASSOC);
  if (!$stu) throw new RuntimeException('Schüler nicht gefunden.');
  if ((int)$stu['class_id'] !== $classId && ($u['role'] ?? '') !== 'admin') {
    throw new RuntimeException('Schüler gehört nicht zu dieser Klasse.');
  }

  $cfg = app_config();
  $aiCfg = ai_provider_config();
  $ttl = (int)($cfg['ai']['support_plan_cache_ttl_seconds'] ?? 86400);
  if ($ttl <= 0) $ttl = 86400;

  $cacheKey = 'support_plan_student:v3:' . $studentId . ':' . $lang;
  $age = null;

  if (!$force) {
    $cached = cache_get($cacheKey, $ttl, $age);
    if (is_array($cached) && isset($cached['support_plan'])) {
      json_out([
        'ok' => true,
        'support_plan' => $cached['support_plan'],
        'meta' => [
          'cached' => true,
          'cache_age_seconds' => $age,
          'filled_fields' => (int)($cached['meta']['filled_fields'] ?? 0),
          'reports' => (int)($cached['meta']['reports'] ?? 0),
        ],
      ]);
    }
  }

  // Student extra fields (student_fields + student_field_values)
  $extraLines = [];
  $stExtra = $pdo->prepare(
    "SELECT f.field_key, f.label, f.label_en, v.value_text
     FROM student_field_values v
     JOIN student_fields f ON f.id=v.field_id
     WHERE v.student_id=?
     ORDER BY f.sort_order ASC, f.id ASC"
  );
  $stExtra->execute([$studentId]);
  $rowsExtra = $stExtra->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rowsExtra as $r) {
    $val = trim((string)($r['value_text'] ?? ''));
    if ($val === '') continue;
    $lbl = label_for_lang((string)($r['label'] ?? ''), (string)($r['label_en'] ?? ''), $lang);
    if ($lbl === '') $lbl = (string)($r['field_key'] ?? '');
    if ($lbl === '') continue;
    $extraLines[] = $lbl . ': ' . $val;
  }

  // All report instances for this student (across years/templates)
  $stRI = $pdo->prepare(
    "SELECT ri.id, ri.template_id, ri.school_year, ri.period_label, ri.status,
            t.name AS template_name, t.template_version
     FROM report_instances ri
     JOIN templates t ON t.id=ri.template_id
     WHERE ri.student_id=?
     ORDER BY ri.school_year DESC, ri.id DESC"
  );
  $stRI->execute([$studentId]);
  $instances = $stRI->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $contextParts = [];
  $stuName = trim((string)($stu['first_name'] ?? '') . ' ' . (string)($stu['last_name'] ?? ''));
  $dob = trim((string)($stu['date_of_birth'] ?? ''));
  $contextParts[] = 'Schüler: ' . ($stuName !== '' ? $stuName : ('#'.$studentId)) . ($dob !== '' ? (' (DOB: '.$dob.')') : '');

  if ($extraLines) {
    $contextParts[] = "Zusatzinfos:\n" . implode("\n", array_slice($extraLines, 0, 120));
  }

  $filledCount = 0;

  foreach (array_slice($instances, 0, 12) as $inst) {
    $rid = (int)($inst['id'] ?? 0);
    $templateId = (int)($inst['template_id'] ?? 0);
    if ($rid <= 0 || $templateId <= 0) continue;

    $header = 'Bericht [' . trim((string)($inst['school_year'] ?? '')) . ' / ' . trim((string)($inst['period_label'] ?? 'Standard')) . '] '
            . trim((string)($inst['template_name'] ?? ('Template#'.$templateId))) . ' (Status: ' . trim((string)($inst['status'] ?? '')) . ')';
    $contextParts[] = $header;

    // Load fields
    $stFields = $pdo->prepare(
      "SELECT id, field_type, label, label_en, field_name, meta_json
       FROM template_fields
       WHERE template_id=?
       ORDER BY sort_order ASC, id ASC"
    );
    $stFields->execute([$templateId]);
    $fields = $stFields->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$fields) continue;

    // Load values (current)
    $stVals = $pdo->prepare(
      "SELECT template_field_id, source, value_text, value_json
       FROM field_values
       WHERE report_instance_id=?"
    );
    $stVals->execute([$rid]);
    $vals = $stVals->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $valMap = []; // [fid][source] => (row)
    foreach ($vals as $v) {
      $fid = (int)($v['template_field_id'] ?? 0);
      if ($fid <= 0) continue;
      $src = trim((string)($v['source'] ?? 'teacher'));
      if ($src === '') $src = 'teacher';
      if (!isset($valMap[$fid])) $valMap[$fid] = [];
      $valMap[$fid][$src] = $v;
    }

    $lines = [];
    $diffs = [];

    foreach ($fields as $f) {
      $fid = (int)($f['id'] ?? 0);
      if ($fid <= 0) continue;

      $label = label_for_lang((string)($f['label'] ?? ''), (string)($f['label_en'] ?? ''), $lang);
      if ($label === '') $label = (string)($f['field_name'] ?? '');
      if ($label === '') $label = 'Feld#' . $fid;

      $teacherRow = $valMap[$fid]['teacher'] ?? null;
      $childRow = $valMap[$fid]['child'] ?? null;

      $teacherText = '';
      $childText = '';

      if ($teacherRow) {
        $vj = $teacherRow['value_json'] !== null ? json_decode((string)$teacherRow['value_json'], true) : null;
        $teacherText = resolve_value_for_ai($pdo, $f, (string)($teacherRow['value_text'] ?? ''), $vj, $lang);
      }
      if ($childRow) {
        $vj = $childRow['value_json'] !== null ? json_decode((string)$childRow['value_json'], true) : null;
        $childText = resolve_value_for_ai($pdo, $f, (string)($childRow['value_text'] ?? ''), $vj, $lang);
      }

      $teacherText = trim($teacherText);
      $childText = trim($childText);

      if ($teacherText === '' && $childText === '') continue;
      $filledCount++;

      if ($teacherText !== '' && $childText !== '' && $teacherText !== $childText) {
        $diffs[] = $label . ': Lehrer=' . $teacherText . ' | Schüler=' . $childText;
      } else {
        $one = $teacherText !== '' ? $teacherText : $childText;
        $lines[] = $label . ': ' . $one;
      }
    }

    if ($diffs) $lines[] = 'Abweichungen Lehrer/Schüler: ' . implode(' | ', array_slice($diffs, 0, 10));
    if ($lines) $contextParts[] = implode("\n", array_slice($lines, 0, 220));
  }

  $context = trim(implode("\n\n", $contextParts));
  if ($context === '') $context = '(Keine Einträge vorhanden.)';

  $system = "Du bist eine erfahrene Grundschul-Lehrkraft und erstellst sehr konkrete, umsetzbare Förderideen. Antworte IMMER als JSON-Objekt mit genau diesen Keys:\n"
          . "kurzprofil (string), foerder_uebergreifend (array), deutsch (array), mathe (array), sachkunde (array), lernorganisation (array), sozial_emotional (array), zu_hause (array), diagnostik_naechste_schritte (array).\n"
          . "Keine weiteren Keys. Keine Markdown-Umrahmung.";

  $userPrompt = "Erstelle umfangreiche, spezifische Fördermöglichkeiten fächerübergreifend auf Basis aller vorhandenen Daten. Du erhältst zu verschiedenen Punkten die Selbsteinschätzung des Schülers und die Beurteilung der Lehrkraft. Ausschlaggebend ist insbesondere die Einschätzung der Lehrkraft aber auch der Vergleich zwischen beiden. Gib dazu auch immer eine Erklärung und Begründung für deine Empfehlung in der du dich auch auf die entsprechenden Eingaben beziehst. Wenn dir nicht ausreichend Daten für eine begründete Empfehlung vorliegen, schreibe die in den entsprechenden Array. Achte sehr genau darauf nichts zu erfinden und mache lieber keine Aussage, wenn dir nicht ausreichend Daten zur Verfügung stehen.\n\nKONTEXT:\n" . $context;

  $messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $userPrompt],
  ];

  $raw = ai_chat_completion($messages, $aiCfg);

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    $decoded = [
      'kurzprofil' => trim($raw),
      'foerder_uebergreifend' => [],
      'deutsch' => [],
      'mathe' => [],
      'sachkunde' => [],
      'lernorganisation' => [],
      'sozial_emotional' => [],
      'zu_hause' => [],
      'diagnostik_naechste_schritte' => [],
    ];
  }

  $payload = [
    'support_plan' => $decoded,
    'meta' => [
      'filled_fields' => $filledCount,
      'reports' => count($instances),
    ],
  ];
  cache_set($cacheKey, $payload);

  json_out([
    'ok' => true,
    'support_plan' => $decoded,
    'meta' => [
      'cached' => false,
      'filled_fields' => $filledCount,
      'reports' => count($instances),
    ],
  ]);

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
