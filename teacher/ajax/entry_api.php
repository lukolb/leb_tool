<?php
// teacher/ajax/entry_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/../../shared/text_snippets.php';
require __DIR__ . '/../../shared/value_history.php';
require_teacher();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function meta_read(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function pdf_max_len_from_meta(array $meta): ?int {
  $raw = $meta['pdf_max_len'] ?? null;
  if ($raw === null || $raw === '') return null;
  if (!is_numeric($raw)) return null;
  $n = (int)$raw;
  return $n > 0 ? $n : null;
}

function clamp_text_length(?string $text, ?int $maxLen): ?string {
  if ($text === null) return null;
  if (!$maxLen || $maxLen <= 0) return $text;
  if (function_exists('mb_strlen')) {
    if (mb_strlen($text) <= $maxLen) return $text;
    return mb_substr($text, 0, $maxLen);
  }
  if (function_exists('iconv_strlen') && function_exists('iconv_substr')) {
    if (iconv_strlen($text, 'UTF-8') <= $maxLen) return $text;
    return iconv_substr($text, 0, $maxLen, 'UTF-8');
  }
  if (strlen($text) <= $maxLen) return $text;
  return substr($text, 0, $maxLen);
}

function option_list_id_from_meta(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function resolve_icon_urls(PDO $pdo, array $iconIds, array &$cache = []): array {
  $iconIds = array_values(array_unique(array_filter(array_map('intval', $iconIds), fn($x)=>$x>0)));
  $iconIds = array_values(array_filter($iconIds, fn($id) => !isset($cache[$id])));
  if ($iconIds) {
    $in = implode(',', array_fill(0, count($iconIds), '?'));
    $st = $pdo->prepare("SELECT id, storage_path FROM icon_library WHERE id IN ($in)");
    $st->execute($iconIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $cache[(int)$r['id']] = url((string)$r['storage_path']);
    }
  }
  return $cache;
}

function map_option_icons(PDO $pdo, array $options, array &$iconCache = []): array {
  $iconIds = [];
  foreach ($options as $opt) {
    $iid = isset($opt['icon_id']) ? (int)$opt['icon_id'] : 0;
    if ($iid > 0) $iconIds[] = $iid;
  }

  $map = resolve_icon_urls($pdo, $iconIds, $iconCache);

  foreach ($options as &$opt) {
    $iid = isset($opt['icon_id']) ? (int)$opt['icon_id'] : 0;
    $opt['icon_url'] = ($iid > 0 && isset($map[$iid])) ? $map[$iid] : null;
  }
  unset($opt);

  return $options;
}

function load_option_list_items(PDO $pdo, int $listId, array &$iconCache = []): array {
  if ($listId <= 0) return [];
  $st = $pdo->prepare(
    "SELECT id, value, label, label_en, icon_id
        FROM option_list_items
        WHERE list_id=?
        ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$listId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = [
      'option_item_id' => (int)$r['id'],
      'value' => (string)($r['value'] ?? ''),
      'label' => (string)($r['label'] ?? ''),
      'label_en' => (string)($r['label_en'] ?? ''),
      'icon_id' => $r['icon_id'] !== null ? (int)$r['icon_id'] : null,
    ];
  }
  return map_option_icons($pdo, $out, $iconCache);
}

function option_label_for_lang(PDO $pdo, int $listId, ?int $itemId, ?string $value, string $lang): ?string {
  if ($listId <= 0) return null;

  if ($itemId !== null && $itemId > 0) {
    $st = $pdo->prepare("SELECT value, label, label_en FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
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
    $st = $pdo->prepare("SELECT value, label, label_en FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
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

function resolve_option_value_text(PDO $pdo, array $meta, ?string $valueJsonRaw, ?string $valueTextRaw, string $lang = 'de'): array {
  $out = ['text' => $valueTextRaw, 'json' => $valueJsonRaw];
  $listId = option_list_id_from_meta($meta);
  if ($listId <= 0) return $out;

  $vj = null;
  if ($valueJsonRaw) {
    $tmp = json_decode($valueJsonRaw, true);
    if (is_array($tmp)) $vj = $tmp;
  }

  if (is_array($vj)) {
    if (isset($vj['option_item_id'])) {
      $optId = (int)$vj['option_item_id'];
      if ($optId > 0) {
        $lbl = option_label_for_lang($pdo, $listId, $optId, $valueTextRaw, $lang);
        if ($lbl !== null && trim($lbl) !== '') {
          $out['text'] = trim($lbl);
          return $out;
        }
      }
    } else {
      $parts = [];
      foreach ($vj as $piece) {
        if (is_array($piece) && isset($piece['option_item_id'])) {
          $lbl = option_label_for_lang($pdo, $listId, (int)$piece['option_item_id'], null, $lang);
          if ($lbl !== null && trim($lbl) !== '') $parts[] = trim($lbl);
        } else {
          $s = trim((string)$piece);
          if ($s !== '') $parts[] = $s;
        }
      }
      if ($parts) {
        $out['text'] = implode(', ', $parts);
        return $out;
      }
    }
  }

  $vt = $valueTextRaw !== null ? trim((string)$valueTextRaw) : '';
  if ($vt !== '') {
    $lbl = option_label_for_lang($pdo, $listId, null, $vt, $lang);
    if ($lbl !== null && trim($lbl) !== '') {
      $out['text'] = trim($lbl);
      return $out;
    }
  }

  return $out;
}

function parse_numeric_value(?string $raw): ?float {
  $s = trim((string)$raw);
  if ($s === '') return null;
  $s = str_replace(',', '.', $s);
  if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return null;
  return (float)$m[0];
}

function option_list_names(PDO $pdo, array $listIds): array {
  $listIds = array_values(array_unique(array_filter(array_map('intval', $listIds), fn($x)=>$x>0)));
  if (!$listIds) return [];
  $in = implode(',', array_fill(0, count($listIds), '?'));
  $st = $pdo->prepare("SELECT id, name FROM option_list_templates WHERE id IN ($in)");
  $st->execute($listIds);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $out[(int)$row['id']] = (string)($row['name'] ?? '');
  }
  return $out;
}

function ai_provider_config(): array {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];

  $provider = strtolower(trim((string)($ai['provider'] ?? 'openai')));
  $enabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
  $apiKey = (string)($ai['api_key'] ?? getenv('OPENAI_API_KEY') ?: '');
  $baseUrl = (string)($ai['base_url'] ?? 'https://api.openai.com');
  $model = (string)($ai['model'] ?? 'gpt-4o-mini');
  $timeout = (int)($ai['timeout_seconds'] ?? 20);

  if (!$enabled) {
    throw new RuntimeException('KI-Vorschläge sind deaktiviert.');
  }
  if ($apiKey === '') {
    throw new RuntimeException('AI API Key nicht konfiguriert.');
  }

  return [
    'provider' => $provider ?: 'openai',
    'api_key' => $apiKey,
    'base_url' => rtrim($baseUrl !== '' ? $baseUrl : 'https://api.openai.com', '/'),
    'model' => $model !== '' ? $model : 'gpt-4o-mini',
    'timeout' => $timeout > 0 ? $timeout : 20,
  ];
}

function ai_provider_enabled(): bool {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];
  $enabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
  if (!$enabled) return false;
  $apiKey = (string)($ai['api_key'] ?? getenv('OPENAI_API_KEY') ?: '');
  return trim($apiKey) !== '';
}

function ai_chat_completion(array $messages, array $aiCfg): string {
  $url = $aiCfg['base_url'] . '/v1/chat/completions';
  $payload = [
    'model' => $aiCfg['model'],
    'messages' => $messages,
    'temperature' => 0.7,
    'max_tokens' => 3000,
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $aiCfg['api_key'],
    ],
    CURLOPT_TIMEOUT => $aiCfg['timeout'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ]);

  $resp = curl_exec($ch);
  $httpCode = (int)(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('AI Request fehlgeschlagen: ' . $err);
  }
  curl_close($ch);

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) {
    throw new RuntimeException('AI Antwort unverständlich.');
  }
  if ($httpCode >= 400) {
    $msg = (string)($json['error']['message'] ?? 'Fehler beim AI-Provider.');
    throw new RuntimeException('AI Fehler: ' . $msg);
  }

  $choices = $json['choices'] ?? [];
  $content = '';
  if (is_array($choices) && isset($choices[0]['message']['content'])) {
    $content = (string)$choices[0]['message']['content'];
  }
  $content = trim($content);
  if ($content === '') {
    throw new RuntimeException('AI hat keine Antwort geliefert.');
  }

  return $content;
}

function normalize_ai_suggestions(string $text): array {
  $lines = preg_split('/\r?\n+/', trim($text));
  if (!is_array($lines)) return [];

  $out = [];
  foreach ($lines as $ln) {
    $s = trim((string)$ln);
    $s = preg_replace('/^[-*•\d\.\s]+/', '', $s);
    $s = trim((string)$s);
    if ($s !== '') $out[] = $s;
  }

  return array_values(array_filter(array_unique($out), fn($s)=>$s!==''));
}


/**
 * AI cache helpers (file-based).
 * Stores JSON payloads in a writable directory (default: system temp).
 * TTL configurable via app_config()['ai']['support_plan_cache_ttl_seconds'] (default 86400).
 */
function ai_cache_dir(): string {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];
  $dir = trim((string)($ai['cache_dir'] ?? ''));
  if ($dir === '') {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'leb_tool_ai_cache';
  }
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function ai_cache_ttl_seconds(): int {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];
  $ttl = (int)($ai['support_plan_cache_ttl_seconds'] ?? 86400);
  if ($ttl < 60) $ttl = 60;
  return $ttl;
}

function ai_cache_file(string $key): string {
  $dir = ai_cache_dir();
  $hash = sha1($key);
  return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.json';
}

function ai_cache_get(string $key, int $ttlSeconds): ?array {
  $file = ai_cache_file($key);
  if (!is_file($file)) return null;
  $raw = @file_get_contents($file);
  if (!$raw) return null;
  $j = json_decode($raw, true);
  if (!is_array($j)) return null;
  $ts = (int)($j['created_at'] ?? 0);
  if ($ts <= 0) return null;
  $age = time() - $ts;
  if ($age > $ttlSeconds) return null;
  return $j;
}

function ai_cache_set(string $key, array $payload): void {
  $file = ai_cache_file($key);
  $wrap = [
    'created_at' => time(),
    'payload' => $payload,
  ];
  @file_put_contents($file, json_encode($wrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function is_class_field(array $meta): bool {
  $scope = isset($meta['scope']) ? strtolower(trim((string)$meta['scope'])) : '';
  if ($scope === 'class') return true;
  if (isset($meta['is_class_field']) && (int)$meta['is_class_field'] === 1) return true;
  return false;
}

function resolve_label_placeholders(string $tpl, array $classValueByName): string {
  $s = (string)$tpl;
  if ($s === '' || strpos($s, '{{') === false) return $s;

  $out = preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function($m) use ($classValueByName) {
    $token = trim((string)($m[1] ?? ''));
    if ($token === '') return '';
    $kind = 'field';
    $key = $token;

    $p = strpos($token, ':');
    if ($p !== false) {
      $kind = strtolower(trim(substr($token, 0, $p)));
      $key = trim(substr($token, $p + 1));
    }
    if ($key === '') return '';

    if ($kind === 'field' || $kind === 'value') {
      return isset($classValueByName[$key]) ? (string)$classValueByName[$key] : '';
    }
    return '';
  }, $s);

  return $out === null ? $s : (string)$out;
}

function group_key_from_meta(array $meta): string {
  $g = (string)($meta['group'] ?? '');
  $g = trim($g);
  return $g !== '' ? $g : 'Allgemein';
}

function label_for_lang(?string $labelDe, ?string $labelEn, string $lang): string {
  $de = trim((string)$labelDe);
  $en = trim((string)$labelEn);
  if ($lang === 'en' && $en !== '') return $en;
  return $de !== '' ? $de : $en;
}

function group_title_override_lang(string $groupKey, string $lang): string {
  $cfg = app_config();
  $bucket = ($lang === 'en') ? 'group_titles_en' : 'group_titles';
  $map = $cfg['student'][$bucket] ?? [];
  if (!is_array($map)) return $groupKey;
  $t = $map[$groupKey] ?? null;
  $t = is_string($t) ? trim($t) : '';
  return $t !== '' ? $t : $groupKey;
}

function group_title_from_meta(array $meta, string $groupKey, string $lang): string {
  if ($lang === 'en') {
    $t = (string)($meta['group_title_en'] ?? '');
    $t = trim($t);
    if ($t !== '') return $t;
  }
  return group_title_override_lang($groupKey, $lang);
}

function normalize_period_label(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? $s : 'Standard';
}

function load_teachers_for_delegation(PDO $pdo): array {
  // Regular teachers + admins can be selected as delegates.
  $st = $pdo->query(
    "SELECT id, display_name, role
     FROM users
     WHERE is_active=1 AND deleted_at IS NULL AND role IN ('teacher','admin')
     ORDER BY display_name ASC, id ASC"
  );
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = [
      'id' => (int)$r['id'],
      'name' => trim((string)($r['display_name'] ?? '')),
      'role' => (string)($r['role'] ?? ''),
    ];
  }
  return $out;
}

function load_class_group_delegations(PDO $pdo, int $classId, string $schoolYear, string $periodLabel): array {
  $periodLabel = normalize_period_label($periodLabel);
  $st = $pdo->prepare(
    "SELECT d.group_key, d.user_id, d.status, d.note,
            u.display_name
     FROM class_group_delegations d
     LEFT JOIN users u ON u.id=d.user_id
     WHERE d.class_id=? AND d.school_year=? AND d.period_label=?
     ORDER BY d.group_key ASC"
  );
  $st->execute([$classId, $schoolYear, $periodLabel]);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $g = trim((string)($r['group_key'] ?? ''));
    if ($g === '') continue;
    $out[$g] = [
      'group_key' => $g,
      'user_id' => (int)($r['user_id'] ?? 0),
      'user_name' => trim((string)($r['display_name'] ?? '')),
      'status' => (string)($r['status'] ?? 'open'),
      'note' => (string)($r['note'] ?? ''),
    ];
  }
  return $out;
}

function upsert_class_group_delegation(PDO $pdo, int $classId, string $schoolYear, string $periodLabel, string $groupKey, int $userId, string $status, string $note, int $actorUserId): void {
  $groupKey = trim($groupKey);
  if ($groupKey === '') throw new RuntimeException('group_key fehlt.');
  $periodLabel = normalize_period_label($periodLabel);
  $status = ($status === 'done') ? 'done' : 'open';

  if ($userId <= 0) {
    // clear
    $pdo->prepare(
      "DELETE FROM class_group_delegations
       WHERE class_id=? AND school_year=? AND period_label=? AND group_key=?"
    )->execute([$classId, $schoolYear, $periodLabel, $groupKey]);
    audit('class_group_delegation_clear', $actorUserId, ['class_id'=>$classId,'school_year'=>$schoolYear,'period_label'=>$periodLabel,'group_key'=>$groupKey]);
    return;
  }
  // NOTE: Do NOT auto-assign delegates as class teachers (separation requirement).

  $pdo->prepare(
    "INSERT INTO class_group_delegations (class_id, school_year, period_label, group_key, user_id, status, note, created_by_user_id, updated_by_user_id, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
       user_id=VALUES(user_id),
       status=VALUES(status),
       note=VALUES(note),
       updated_by_user_id=VALUES(updated_by_user_id),
       updated_at=NOW()"
  )->execute([$classId, $schoolYear, $periodLabel, $groupKey, $userId, $status, $note, $actorUserId, $actorUserId]);

  audit('class_group_delegation_upsert', $actorUserId, ['class_id'=>$classId,'school_year'=>$schoolYear,'period_label'=>$periodLabel,'group_key'=>$groupKey,'user_id'=>$userId,'status'=>$status]);
}

function delegated_user_for_group(PDO $pdo, int $classId, string $schoolYear, string $periodLabel, string $groupKey): int {
  $groupKey = trim($groupKey);
  if ($groupKey === '') return 0;
  $periodLabel = normalize_period_label($periodLabel);
  $st = $pdo->prepare(
    "SELECT user_id
     FROM class_group_delegations
     WHERE class_id=? AND school_year=? AND period_label=? AND group_key=?
     LIMIT 1"
  );
  $st->execute([$classId, $schoolYear, $periodLabel, $groupKey]);
  return (int)($st->fetchColumn() ?: 0);
}

function user_is_class_teacher(PDO $pdo, int $userId, int $classId): bool {
  if ($userId <= 0 || $classId <= 0) return false;
  $st = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
  $st->execute([$userId, $classId]);
  return (bool)$st->fetch();
}

function can_user_edit_group(PDO $pdo, array $currentUser, int $classId, string $schoolYear, string $periodLabel, string $groupKey): bool {
  if (($currentUser['role'] ?? '') === 'admin') return true;
  $uid = (int)($currentUser['id'] ?? 0);
  if ($uid <= 0) return false;
  $assigned = delegated_user_for_group($pdo, $classId, $schoolYear, $periodLabel, $groupKey);
  if ($assigned <= 0) return true;        // not delegated => anyone with class access may edit
  return $assigned === $uid;              // delegated => only that teacher
}

function can_user_edit_field(PDO $pdo, array $currentUser, int $classId, string $schoolYear, string $periodLabel, array $meta, string $fieldType, int $isMultiline): bool {
  if (($currentUser['role'] ?? '') === 'admin') return true;
  $uid = (int)($currentUser['id'] ?? 0);
  if ($uid <= 0) return false;

  $groupKey = group_key_from_meta($meta);
  $assigned = delegated_user_for_group($pdo, $classId, $schoolYear, $periodLabel, $groupKey);
  if ($assigned <= 0) return true;

  if (is_free_text_field($fieldType, $isMultiline)) {
    return ($assigned === $uid) || user_is_class_teacher($pdo, $uid, $classId);
  }

  return $assigned === $uid;
}

function is_free_text_field(string $fieldType, int $isMultiline): bool {
  $t = strtolower(trim($fieldType));
  if ($t === 'multiline' || $t === 'text') return true;
  return $isMultiline === 1;
}

function free_text_parts_from_json(?string $valueJsonRaw): array {
  if (!$valueJsonRaw) {
    return ['has_free_text' => false, 'class_text' => '', 'delegate_text' => '', 'delegate_user_id' => 0];
  }
  $j = json_decode($valueJsonRaw, true);
  if (!is_array($j) || !isset($j['free_text']) || !is_array($j['free_text'])) {
    return ['has_free_text' => false, 'class_text' => '', 'delegate_text' => '', 'delegate_user_id' => 0];
  }
  $ft = $j['free_text'];
  return [
    'has_free_text' => true,
    'class_text' => (string)($ft['class_text'] ?? ''),
    'delegate_text' => (string)($ft['delegate_text'] ?? ''),
    'delegate_user_id' => (int)($ft['delegate_user_id'] ?? 0),
  ];
}

function build_free_text_json(string $classText, string $delegateText, int $delegateUserId): string {
  return json_encode([
    'free_text' => [
      'class_text' => $classText,
      'delegate_text' => $delegateText,
      'delegate_user_id' => $delegateUserId,
    ],
  ], JSON_UNESCAPED_UNICODE);
}

function combine_free_text(string $classText, string $delegateText): string {
  $parts = [];
  if (trim($classText) !== '') $parts[] = rtrim($classText);
  if (trim($delegateText) !== '') $parts[] = rtrim($delegateText);
  return implode("\n\n", $parts);
}

function load_existing_teacher_value(PDO $pdo, int $reportInstanceId, int $fieldId): array {
  $st = $pdo->prepare(
    "SELECT value_text, value_json
     FROM field_values
     WHERE report_instance_id=? AND template_field_id=? AND source='teacher'
     LIMIT 1"
  );
  $st->execute([$reportInstanceId, $fieldId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return [
    'value_text' => $row && $row['value_text'] !== null ? (string)$row['value_text'] : null,
    'value_json' => $row && $row['value_json'] !== null ? (string)$row['value_json'] : null,
  ];
}

function save_free_text_value(
  PDO $pdo,
  int $reportId,
  int $fieldId,
  string $classText,
  string $delegateText,
  int $delegateUserId,
  bool $isDelegate,
  int $userId
): array {
  $classText = trim($classText);
  $delegateText = trim($delegateText);

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare(
      "SELECT value_text, value_json
       FROM field_values
       WHERE report_instance_id=? AND template_field_id=? AND source='teacher'
       LIMIT 1
       FOR UPDATE"
    );
    $st->execute([$reportId, $fieldId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $existingClass = '';
    $existingDelegate = '';
    if ($row) {
      $free = free_text_parts_from_json($row['value_json'] !== null ? (string)$row['value_json'] : null);
      if ($free['has_free_text']) {
        $existingClass = (string)($free['class_text'] ?? '');
        $existingDelegate = (string)($free['delegate_text'] ?? '');
      } else {
        $existingClass = (string)($row['value_text'] ?? '');
      }
    }

    if ($isDelegate) $existingDelegate = $delegateText;
    else $existingClass = $classText;

    $valueJson = build_free_text_json($existingClass, $existingDelegate, $delegateUserId);
    $valueText = combine_free_text($existingClass, $existingDelegate);
    if (trim($valueText) === '') $valueText = null;

    if ($row) {
      $up = $pdo->prepare(
        "UPDATE field_values
         SET value_text=?, value_json=?, source='teacher', updated_by_user_id=?, updated_at=NOW()
         WHERE report_instance_id=? AND template_field_id=? AND source='teacher'
         LIMIT 1"
      );
      $up->execute([$valueText, $valueJson, $userId, $reportId, $fieldId]);
    } else {
      $ins = $pdo->prepare(
        "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_at)
         VALUES (?, ?, ?, ?, 'teacher', ?, NOW())"
      );
      $ins->execute([$reportId, $fieldId, $valueText, $valueJson, $userId]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  return load_existing_teacher_value($pdo, $reportId, $fieldId);
}

function base_field_key(string $fieldName): string {
  $s = strtolower(trim($fieldName));
  $s = explode('-', $s, 2)[0];
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  return trim($s);
}

function load_teacher_values_raw(PDO $pdo, array $reportIds, array $fieldIds): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds);

  $st = $pdo->prepare(
    "SELECT report_instance_id, template_field_id, value_text, value_json
     FROM field_values
     WHERE report_instance_id IN ($inR)
       AND template_field_id IN ($inF)
       AND source='teacher'"
  );
  $st->execute($params);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (int)$r['template_field_id'];
    if (!isset($out[$rid])) $out[$rid] = [];
    $out[$rid][$fid] = [
      'text' => $r['value_text'] !== null ? (string)$r['value_text'] : null,
      'json' => $r['value_json'] !== null ? (string)$r['value_json'] : null,
    ];
  }
  return $out;
}

function option_list_lock_map(PDO $pdo, int $listId, array &$cache): array {
  if ($listId <= 0) return ['by_id' => [], 'by_value' => []];
  if (isset($cache[$listId])) return $cache[$listId];
  $st = $pdo->prepare(
    "SELECT id, value, meta_json
     FROM option_list_items
     WHERE list_id=?"
  );
  $st->execute([$listId]);
  $byId = [];
  $byValue = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)($r['id'] ?? 0);
    $value = trim((string)($r['value'] ?? ''));
    $meta = meta_read($r['meta_json'] ?? null);
    $lock = !empty($meta['lock_child']);
    $byId[$id] = [
      'value' => $value,
      'lock_child' => $lock,
    ];
    if ($value !== '' && !isset($byValue[$value])) $byValue[$value] = $id;
  }
  $cache[$listId] = ['by_id' => $byId, 'by_value' => $byValue];
  return $cache[$listId];
}

function teacher_value_locks_child(PDO $pdo, array $teacherField, ?array $teacherValue, array &$cache): bool {
  if (!$teacherValue) return false;
  $type = (string)($teacherField['field_type'] ?? '');
  if (!in_array($type, ['radio','select','grade'], true)) return false;
  $meta = meta_read($teacherField['meta_json'] ?? null);
  $listId = option_list_id_from_meta($meta);
  if ($listId <= 0) return false;
  $map = option_list_lock_map($pdo, $listId, $cache);
  $optId = 0;
  if (!empty($teacherValue['json'])) {
    $decoded = json_decode((string)$teacherValue['json'], true);
    if (is_array($decoded) && isset($decoded['option_item_id'])) {
      $optId = (int)$decoded['option_item_id'];
    }
  }
  if ($optId <= 0) {
    $txt = trim((string)($teacherValue['text'] ?? ''));
    if ($txt !== '' && isset($map['by_value'][$txt])) {
      $optId = (int)$map['by_value'][$txt];
    }
  }
  if ($optId <= 0) return false;
  return !empty($map['by_id'][$optId]['lock_child']);
}

function locked_child_field_ids_for_reports(PDO $pdo, array $teacherFields, array $childFields, array $reportIds): array {
  if (!$reportIds) return [];
  $teacherByBase = [];
  $teacherFieldIds = [];
  foreach ($teacherFields as $f) {
    $fid = (int)($f['id'] ?? 0);
    if ($fid <= 0) continue;
    $teacherFieldIds[] = $fid;
    $base = base_field_key((string)($f['field_name'] ?? ''));
    if ($base !== '' && !isset($teacherByBase[$base])) $teacherByBase[$base] = $f;
  }
  if (!$teacherFieldIds || !$teacherByBase) return [];
  $teacherValues = load_teacher_values_raw($pdo, $reportIds, $teacherFieldIds);
  if (!$teacherValues) return [];

  $lockCache = [];
  $out = [];
  foreach ($reportIds as $rid) {
    $ridKey = (string)(int)$rid;
    $reportTeacherValues = $teacherValues[$ridKey] ?? [];
    if (!$reportTeacherValues) continue;
    foreach ($childFields as $cf) {
      $cfId = (int)($cf['id'] ?? 0);
      if ($cfId <= 0) continue;
      $base = base_field_key((string)($cf['field_name'] ?? ''));
      if ($base === '') continue;
      $teacherField = $teacherByBase[$base] ?? null;
      if (!$teacherField) continue;
      $teacherValue = $reportTeacherValues[(int)($teacherField['id'] ?? 0)] ?? null;
      if (!teacher_value_locks_child($pdo, $teacherField, $teacherValue, $lockCache)) continue;
      if (!isset($out[$ridKey])) $out[$ridKey] = [];
      $out[$ridKey][$cfId] = true;
    }
  }
  return $out;
}

function is_system_bound(array $meta): bool {
  $tpl = $meta['system_binding_tpl'] ?? null;
  if (is_string($tpl) && trim($tpl) !== '') return true;
  $one = $meta['system_binding'] ?? null;
  if (is_string($one) && trim($one) !== '') return true;
  return false;
}

function decode_options(?string $json): array {
  if (!$json) return [];
  $j = json_decode($json, true);
  if (!is_array($j)) return [];
  if (isset($j['options']) && is_array($j['options'])) return $j['options'];
  if (array_is_list($j)) return $j;
  return [];
}

function normalize_pdf_rect($rect): ?array {
  if (is_string($rect)) {
    $decoded = json_decode($rect, true);
    if (is_array($decoded)) $rect = $decoded;
    if (is_string($rect)) {
      $parts = array_map('trim', explode(',', $rect));
      if (count($parts) >= 4) $rect = array_slice($parts, 0, 4);
    }
  }
  if (!is_array($rect) || count($rect) < 4) return null;
  $vals = array_map('floatval', array_slice($rect, 0, 4));
  if (count(array_filter($vals, fn($v)=>is_finite($v))) < 4) return null;
  return $vals;
}

function date_format_pattern_from_meta(array $meta, ?string $fieldType = null): string {
  $mode = (string)($meta['date_format_mode'] ?? '');
  $preset = trim((string)($meta['date_format_preset'] ?? ''));
  $custom = trim((string)($meta['date_format_custom'] ?? ''));
  if ($mode === 'custom') return $custom;
  if ($preset !== '') return $preset;
  if (($fieldType ?? '') === 'date') {
    if ($custom !== '') return $custom;
    return $preset;
  }
  return '';
}

function format_date_value_for_field(?string $value, array $meta, ?string $fieldType = null): ?string {
  if ($value === null) return null;
  $raw = (string)$value;
  if (trim($raw) === '') return $value;
  $pattern = date_format_pattern_from_meta($meta, $fieldType);
  if ($pattern === '') return $value;
  $formatted = format_date_pattern($raw, $pattern);
  if ($formatted === '' || $formatted === $raw) return $value;
  return $formatted;
}

function format_date_value_to_iso(?string $value): ?string {
  if ($value === null) return null;
  $raw = trim((string)$value);
  if ($raw === '') return $value;
  try {
    $dt = new DateTimeImmutable($raw);
  } catch (Throwable $e) {
    $datePart = substr($raw, 0, 10);
    try {
      $dt = new DateTimeImmutable($datePart);
    } catch (Throwable $e2) {
      return $value;
    }
  }
  return $dt->format('Y-m-d');
}

function apply_date_iso_formatting(array $values, array $fieldMap): array {
  foreach ($values as $fid => $val) {
    $fieldId = (int)$fid;
    if (!isset($fieldMap[$fieldId])) continue;
    $meta = $fieldMap[$fieldId]['meta'] ?? [];
    $type = $fieldMap[$fieldId]['field_type'] ?? null;
    if (($type ?? '') !== 'date' && date_format_pattern_from_meta($meta, is_string($type) ? $type : null) === '') continue;
    $values[$fid] = format_date_value_to_iso($val) ?? $val;
  }
  return $values;
}

function apply_date_formatting(array $values, array $fieldMap): array {
  foreach ($values as $fid => $val) {
    $fieldId = (int)$fid;
    if (!isset($fieldMap[$fieldId])) continue;
    $meta = $fieldMap[$fieldId]['meta'] ?? [];
    $type = $fieldMap[$fieldId]['field_type'] ?? null;
    $values[$fid] = format_date_value_for_field($val, $meta, is_string($type) ? $type : null) ?? $val;
  }
  return $values;
}

function should_format_date_field(array $meta, ?string $fieldType = null): bool {
  if (($fieldType ?? '') === 'date') return true;
  return date_format_pattern_from_meta($meta, $fieldType) !== '';
}

function template_for_class(PDO $pdo, int $classId): array {
  $st = $pdo->prepare(
    "SELECT t.id, t.name, t.template_version
     FROM classes c
     LEFT JOIN templates t ON t.id=c.template_id
     WHERE c.id=?
     LIMIT 1"
  );
  $st->execute([$classId]);
  $tpl = $st->fetch(PDO::FETCH_ASSOC);

  if (!$tpl || (int)($tpl['id'] ?? 0) <= 0) {
    throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');
  }

  $st2 = $pdo->prepare("SELECT is_active FROM templates WHERE id=? LIMIT 1");
  $st2->execute([(int)$tpl['id']]);
  if ((int)$st2->fetchColumn() !== 1) {
    throw new RuntimeException('Die zugeordnete Vorlage ist inaktiv.');
  }

  return $tpl;
}

function class_school_year(PDO $pdo, int $classId): string {
  $st = $pdo->prepare("SELECT school_year FROM classes WHERE id=? LIMIT 1");
  $st->execute([$classId]);
  return (string)($st->fetchColumn() ?: '');
}

function find_or_create_class_report_instance(PDO $pdo, int $templateId, int $classId, string $schoolYear): int {
  $periodLabel = class_report_period_label($classId);
  $st = $pdo->prepare(
    "SELECT id, status
     FROM report_instances
     WHERE template_id=? AND student_id IS NULL AND school_year=? AND period_label=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear, $periodLabel]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return (int)$row['id'];

  $pdo->prepare(
    "INSERT INTO report_instances (template_id, student_id, period_label, school_year, status, created_by_user_id, created_at, updated_at)
     VALUES (?, NULL, ?, ?, 'draft', NULL, NOW(), NOW())"
  )->execute([$templateId, $periodLabel, $schoolYear]);

  return (int)$pdo->lastInsertId();
}

function find_or_create_report_instance_for_student(PDO $pdo, int $templateId, int $studentId, string $schoolYear, int $userId): array {
  $st = $pdo->prepare(
    "SELECT id, status
     FROM report_instances
     WHERE template_id=? AND student_id=? AND school_year=? AND period_label='Standard'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $studentId, $schoolYear]);
  $ri = $st->fetch(PDO::FETCH_ASSOC);

  if ($ri) {
    return ['id' => (int)$ri['id'], 'status' => (string)$ri['status']];
  }

  $pdo->prepare(
    "INSERT INTO report_instances (template_id, student_id, period_label, school_year, status, created_by_user_id, locked_by_user_id, locked_at, created_at, updated_at)
     VALUES (?, ?, 'Standard', ?, 'locked', NULL, ?, NOW(), NOW(), NOW())"
  )->execute([$templateId, $studentId, $schoolYear, $userId]);

  $rid = (int)$pdo->lastInsertId();
  return ['id' => $rid, 'status' => 'locked'];
}

function load_teacher_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, label_en, help_text, is_multiline, options_json, meta_json, sort_order
     FROM template_fields
     WHERE template_id=? AND can_teacher_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_child_fields_for_pairing(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, label_en, help_text, is_multiline, options_json, meta_json
     FROM template_fields
     WHERE template_id=? AND can_child_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_values(PDO $pdo, array $reportIds, array $fieldIds, string $source, string $lang): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds, [$source]);

  $st = $pdo->prepare(
    "SELECT fv.report_instance_id, fv.template_field_id, fv.value_text, fv.value_json, tf.meta_json
     FROM field_values fv
     JOIN template_fields tf ON tf.id=fv.template_field_id
     WHERE fv.report_instance_id IN ($inR)
       AND fv.template_field_id IN ($inF)
       AND fv.source=?"
  );
  $st->execute($params);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (string)(int)$r['template_field_id'];
    if (!isset($out[$rid])) $out[$rid] = [];
    $meta = meta_read($r['meta_json'] ?? null);
    $res = resolve_option_value_text(
      $pdo,
      $meta,
      $r['value_json'] !== null ? (string)$r['value_json'] : null,
      $r['value_text'] !== null ? (string)$r['value_text'] : null,
      $lang
    );
    $out[$rid][$fid] = $res['text'] !== null ? (string)$res['text'] : '';
  }
  return $out;
}

function load_raw_values(PDO $pdo, array $reportIds, array $fieldIds, string $source): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds, [$source]);

  $st = $pdo->prepare(
    "SELECT fv.report_instance_id, fv.template_field_id, fv.value_text
     FROM field_values fv
     WHERE fv.report_instance_id IN ($inR)
       AND fv.template_field_id IN ($inF)
       AND fv.source=?"
  );
  $st->execute($params);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (string)(int)$r['template_field_id'];
    if (!isset($out[$rid])) $out[$rid] = [];
    $out[$rid][$fid] = $r['value_text'] !== null ? (string)$r['value_text'] : '';
  }
  return $out;
}

function load_input_values(PDO $pdo, array $reportIds, array $fieldMap, string $source): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', array_keys($fieldMap)), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds, [$source]);

  $st = $pdo->prepare(
    "SELECT fv.report_instance_id, fv.template_field_id, fv.value_text, fv.value_json
     FROM field_values fv
     WHERE fv.report_instance_id IN ($inR)
       AND fv.template_field_id IN ($inF)
       AND fv.source=?"
  );
  $st->execute($params);

  $listValueCache = [];
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (int)$r['template_field_id'];
    if (!isset($fieldMap[$fid])) continue;
    if (!isset($out[$rid])) $out[$rid] = [];

    $valueText = $r['value_text'] !== null ? (string)$r['value_text'] : '';
    $valueJsonRaw = $r['value_json'] !== null ? (string)$r['value_json'] : null;

    $meta = $fieldMap[$fid]['meta'] ?? [];
    $type = (string)($fieldMap[$fid]['field_type'] ?? '');
    $listId = option_list_id_from_meta($meta);

    if (in_array($type, ['radio','select','grade'], true) && $listId > 0 && $valueJsonRaw) {
      $decoded = json_decode($valueJsonRaw, true);
      $optId = is_array($decoded) ? (int)($decoded['option_item_id'] ?? 0) : 0;
      if ($optId > 0) {
        if (!isset($listValueCache[$listId])) {
          $listValueCache[$listId] = [];
          $stOpt = $pdo->prepare("SELECT id, value FROM option_list_items WHERE list_id=?");
          $stOpt->execute([$listId]);
          foreach ($stOpt->fetchAll(PDO::FETCH_ASSOC) as $optRow) {
            $listValueCache[$listId][(int)$optRow['id']] = (string)($optRow['value'] ?? '');
          }
        }
        $valueText = $listValueCache[$listId][$optId] ?? $valueText;
      }
    }

    $out[$rid][(string)$fid] = $valueText;
  }

  return $out;
}

function load_teacher_values_for_user(
  PDO $pdo,
  array $reportIds,
  array $fieldMap,
  array $delegations,
  array $currentUser,
  int $classId,
  string $schoolYear,
  string $periodLabel,
  string $lang
): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', array_keys($fieldMap)), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $inR = implode(',', array_fill(0, count($reportIds), '?'));
  $inF = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge($reportIds, $fieldIds);

  $st = $pdo->prepare(
    "SELECT report_instance_id, template_field_id, value_text, value_json
     FROM field_values
     WHERE report_instance_id IN ($inR)
       AND template_field_id IN ($inF)
       AND source='teacher'"
  );
  $st->execute($params);

  $uid = (int)($currentUser['id'] ?? 0);
  $isClassTeacher = (($currentUser['role'] ?? '') === 'admin') || user_is_class_teacher($pdo, $uid, $classId);

  $combined = [];
  $own = [];
  $parts = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (int)$r['template_field_id'];
    if (!isset($fieldMap[$fid])) continue;
    if (!isset($combined[$rid])) $combined[$rid] = [];
    if (!isset($own[$rid])) $own[$rid] = [];

    $meta = $fieldMap[$fid]['meta'] ?? [];
    $fieldType = (string)($fieldMap[$fid]['field_type'] ?? '');
    $isMultiline = (int)($fieldMap[$fid]['is_multiline'] ?? 0);

    $groupKey = group_key_from_meta($meta);
    $del = $groupKey !== '' ? ($delegations[$groupKey] ?? null) : null;
    $assigned = $del ? (int)($del['user_id'] ?? 0) : 0;

    $valueTextRaw = $r['value_text'] !== null ? (string)$r['value_text'] : '';
    $valueJsonRaw = $r['value_json'] !== null ? (string)$r['value_json'] : null;

    if ($assigned > 0 && is_free_text_field($fieldType, $isMultiline)) {
      $free = free_text_parts_from_json($valueJsonRaw);
      if ($free['has_free_text']) {
        $delegateMatches = ((int)$free['delegate_user_id'] === $assigned);
        $classText = (string)$free['class_text'];
        $delegateText = $delegateMatches ? (string)$free['delegate_text'] : '';
        $textCombined = combine_free_text($classText, $delegateText);
        $isDelegate = ($assigned === $uid);
        $textOwn = ($isDelegate && !$isClassTeacher) ? $delegateText : $classText;
        $combined[$rid][(string)$fid] = $textCombined;
        $own[$rid][(string)$fid] = $textOwn;
        if (!isset($parts[$rid])) $parts[$rid] = [];
        $parts[$rid][(string)$fid] = [
          'class_text' => $classText,
          'delegate_text' => $delegateText,
          'delegate_user_id' => $assigned,
        ];
        continue;
      }
      $combined[$rid][(string)$fid] = $valueTextRaw;
      $own[$rid][(string)$fid] = $valueTextRaw;
      continue;
    }

    $resolved = resolve_option_value_text($pdo, $meta, $valueJsonRaw, $valueTextRaw, $lang);
    $text = $resolved['text'] !== null ? (string)$resolved['text'] : '';
    $combined[$rid][(string)$fid] = $text;
    $own[$rid][(string)$fid] = $text;
  }

  return ['combined' => $combined, 'own' => $own, 'parts' => $parts];
}

function load_value_history(PDO $pdo, array $reportIds, array $fieldIds, array $fieldMetaById, string $lang, int $limit = 5): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($x)=>$x>0)));
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$reportIds || !$fieldIds) return [];

  $phR = implode(',', array_fill(0, count($reportIds), '?'));
  $phF = implode(',', array_fill(0, count($fieldIds), '?'));

  try {
    $st = $pdo->prepare(
      "SELECT report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_by_student_id, created_at
       FROM field_value_history
       WHERE report_instance_id IN ($phR) AND template_field_id IN ($phF)
       ORDER BY created_at DESC, id DESC"
    );
    $st->execute(array_merge($reportIds, $fieldIds));
  } catch (Throwable $e) {
    return [];
  }

  $out = [];

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $rid = (string)(int)$r['report_instance_id'];
    $fid = (string)(int)$r['template_field_id'];

    if (!isset($out[$rid])) $out[$rid] = [];
    if (!isset($out[$rid][$fid])) $out[$rid][$fid] = [];

    if (count($out[$rid][$fid]) >= $limit) continue;

    $meta = $fieldMetaById[(int)$fid]['meta'] ?? [];
    $resolved = resolve_option_value_text(
      $pdo,
      $meta,
      $r['value_json'] !== null ? (string)$r['value_json'] : null,
      $r['value_text'] !== null ? (string)$r['value_text'] : null,
      $lang
    );

    $out[$rid][$fid][] = [
      'text' => $resolved['text'] !== null ? (string)$resolved['text'] : '',
      'value_text' => $r['value_text'] !== null ? (string)$r['value_text'] : null,
      'value_json' => $r['value_json'] !== null ? (string)$r['value_json'] : null,
      'source' => (string)($r['source'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
      'updated_by_user_id' => $r['updated_by_user_id'] !== null ? (int)$r['updated_by_user_id'] : null,
      'updated_by_student_id' => $r['updated_by_student_id'] !== null ? (int)$r['updated_by_student_id'] : null,
    ];
  }

  return $out;
}

try {
  $data = read_json_body();

  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $u = current_user();
  $lang = ui_lang();
  $userId = (int)($u['id'] ?? 0);

  $action = (string)($data['action'] ?? '');
  if ($action === '') throw new RuntimeException('action fehlt.');

  if ($action === 'load') {
    $classId = (int)($data['class_id'] ?? 0);
    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $tpl = template_for_class($pdo, $classId);
    $templateId = (int)$tpl['id'];
    $schoolYear = class_school_year($pdo, $classId);
    if ($schoolYear === '') $schoolYear = date('Y');

    $classReportInstanceId = find_or_create_class_report_instance($pdo, $templateId, $classId, $schoolYear);

    $optCache = []; // listId => option definitions
    $iconCache = []; // iconId => url

    // students in class
    $st = $pdo->prepare(
      "SELECT id, first_name, last_name
       FROM students
       WHERE class_id=? AND is_active=1
       ORDER BY last_name ASC, first_name ASC, id ASC"
    );
    $st->execute([$classId]);
    $studentsRaw = $st->fetchAll(PDO::FETCH_ASSOC);

    $students = [];
    $reportIds = [];
    foreach ($studentsRaw as $s) {
      $sid = (int)$s['id'];
      $name = trim((string)$s['last_name'] . ', ' . (string)$s['first_name']);
      $ri = find_or_create_report_instance_for_student($pdo, $templateId, $sid, $schoolYear, $userId);
      $students[] = [
        'id' => $sid,
        'name' => $name,
        'report_instance_id' => (int)$ri['id'],
        'status' => (string)$ri['status'],
      ];
      $reportIds[] = (int)$ri['id'];
    }

    // include class report id so class values are returned within values_teacher
    if ($classReportInstanceId > 0) $reportIds[] = (int)$classReportInstanceId;

    // child pairing map by base key
    $childFields = load_child_fields_for_pairing($pdo, $templateId);
    $childByBase = [];
    foreach ($childFields as $cf) {
      $base = base_field_key((string)$cf['field_name']);
      if ($base === '') continue;
      if (isset($childByBase[$base])) continue;
      $mcf = meta_read($cf['meta_json'] ?? null);
      $listId = option_list_id_from_meta($mcf);
      $optsChild = [];
      if ($listId > 0) {
        if (!isset($optCache[$listId])) $optCache[$listId] = load_option_list_items($pdo, $listId, $iconCache);
        $optsChild = $optCache[$listId];
      } else {
        $optsChild = map_option_icons($pdo, decode_options($cf['options_json'] ?? null), $iconCache);
      }
      $labelChild = label_for_lang($cf['label'] ?? null, $cf['label_en'] ?? null, $lang);
      $childByBase[$base] = [
        'id' => (int)$cf['id'],
        'field_name' => (string)($cf['field_name'] ?? ''),
        'field_type' => (string)($cf['field_type'] ?? ''),
        'label' => $labelChild,
        'help_text' => (string)($cf['help_text'] ?? ''),
        'is_multiline' => (int)($cf['is_multiline'] ?? 0),
        'options' => $optsChild,
      ];
    }

    $teacherFields = load_teacher_fields($pdo, $templateId);

    // ✅ determine EDITABLE class fields (scope=class AND not system-bound)
    $classFieldIdsEditable = [];
    foreach ($teacherFields as $f0) {
      $m0 = meta_read($f0['meta_json'] ?? null);
      if (!is_class_field($m0)) continue;
      if (is_system_bound($m0)) continue;
      $classFieldIdsEditable[] = (int)$f0['id'];
    }

    $periodLabel = 'Standard';
    $delegations = load_class_group_delegations($pdo, $classId, $schoolYear, $periodLabel);
    $delegationUsers = load_teachers_for_delegation($pdo);

    $teacherFieldMap = [];
    foreach ($teacherFields as $f0) {
      $fid = (int)($f0['id'] ?? 0);
      if ($fid <= 0) continue;
      $teacherFieldMap[$fid] = [
        'field_type' => (string)($f0['field_type'] ?? ''),
        'is_multiline' => (int)($f0['is_multiline'] ?? 0),
        'meta' => meta_read($f0['meta_json'] ?? null),
      ];
    }

    // load values for editable class fields
    $classValuesById = [];
    $classValuesOwnById = [];
    if ($classReportInstanceId > 0 && $classFieldIdsEditable) {
      $classFieldMap = array_intersect_key($teacherFieldMap, array_flip($classFieldIdsEditable));
      $classValues = load_teacher_values_for_user(
        $pdo,
        [$classReportInstanceId],
        $classFieldMap,
        $delegations,
        $u,
        $classId,
        $schoolYear,
        $periodLabel,
        $lang
      );
      $classValuesById = $classValues['combined'] ?? [];
      $classValuesOwnById = $classValues['own'] ?? [];
    }

    // name => value for placeholder resolution
    $classValueByName = [];
    foreach ($teacherFields as $f0) {
      $m0 = meta_read($f0['meta_json'] ?? null);
      if (!is_class_field($m0) || is_system_bound($m0)) continue;
      $fid0 = (string)(int)$f0['id'];
      $val0 = '';
      $ridKey = (string)(int)$classReportInstanceId;
      if (isset($classValuesById[$ridKey]) && isset($classValuesById[$ridKey][$fid0])) {
        $val0 = (string)$classValuesById[$ridKey][$fid0];
      }
      $classValueByName[(string)$f0['field_name']] = $val0;
    }

    // class fields definitions for UI
    $classFieldsDefs = [];
    foreach ($teacherFields as $f0) {
      $m0 = meta_read($f0['meta_json'] ?? null);
      if (!is_class_field($m0) || is_system_bound($m0)) continue;

      $optsTeacher = [];
      $listIdT = option_list_id_from_meta($m0);
      if ($listIdT > 0) {
        if (!isset($optCache[$listIdT])) $optCache[$listIdT] = load_option_list_items($pdo, $listIdT, $iconCache);
        $optsTeacher = $optCache[$listIdT];
      } else {
        $optsTeacher = map_option_icons($pdo, decode_options($f0['options_json'] ?? null), $iconCache);
      }
      if (!$optsTeacher && (string)$f0['field_type'] === 'grade') {
        $optsTeacher = [
          ['value'=>'1','label'=>'1'],
          ['value'=>'2','label'=>'2'],
          ['value'=>'3','label'=>'3'],
          ['value'=>'4','label'=>'4'],
          ['value'=>'5','label'=>'5'],
          ['value'=>'6','label'=>'6'],
        ];
      }

      $canEditClassField = can_user_edit_field(
        $pdo,
        $u,
        $classId,
        $schoolYear,
        $periodLabel,
        $m0,
        (string)($f0['field_type'] ?? ''),
        (int)($f0['is_multiline'] ?? 0)
      );

      $classFieldsDefs[] = [
        'id' => (int)$f0['id'],
        'field_name' => (string)$f0['field_name'],
        'field_type' => (string)$f0['field_type'],
        'label' => label_for_lang($f0['label'] ?? null, $f0['label_en'] ?? null, $lang),
        'help_text' => (string)($f0['help_text'] ?? ''),
        'label_resolved' => resolve_label_placeholders(label_for_lang($f0['label'] ?? null, $f0['label_en'] ?? null, $lang), $classValueByName),
        'help_text_resolved' => resolve_label_placeholders((string)($f0['help_text'] ?? ''), $classValueByName),
        'is_multiline' => (int)($f0['is_multiline'] ?? 0),
        'options' => $optsTeacher,
        'can_edit' => $canEditClassField ? 1 : 0,
      ];
    }

    // teacher fields -> groups (excluding class fields + system bound)
    $groups = [];
    foreach ($teacherFields as $f) {
      $meta = meta_read($f['meta_json'] ?? null);

      if (is_system_bound($meta)) continue;
      if (is_class_field($meta)) continue;

      $gKey = group_key_from_meta($meta);
      if (!isset($groups[$gKey])) {
        $groups[$gKey] = [
          'key' => $gKey,
          'title' => group_title_from_meta($meta, $gKey, $lang),
          'fields' => [],
        ];
      }

      $optsTeacher = [];
      $listIdF = option_list_id_from_meta($meta);
      if ($listIdF > 0) {
        if (!isset($optCache[$listIdF])) $optCache[$listIdF] = load_option_list_items($pdo, $listIdF, $iconCache);
        $optsTeacher = $optCache[$listIdF];
      } else {
        $optsTeacher = map_option_icons($pdo, decode_options($f['options_json'] ?? null), $iconCache);
      }
      if (!$optsTeacher && (string)$f['field_type'] === 'grade') {
        $optsTeacher = [
          ['value'=>'1','label'=>'1'],
          ['value'=>'2','label'=>'2'],
          ['value'=>'3','label'=>'3'],
          ['value'=>'4','label'=>'4'],
          ['value'=>'5','label'=>'5'],
          ['value'=>'6','label'=>'6'],
        ];
      }
      if (!$optsTeacher && isset($meta['options']) && is_array($meta['options'])) {
        $optsTeacher = map_option_icons($pdo, $meta['options'], $iconCache);
      }
      if ($optsTeacher) {
        $optsTeacher = array_map(function(array $opt) use ($lang) {
          $label = label_for_lang($opt['label'] ?? null, $opt['label_en'] ?? null, $lang);
          if ($label === '') $label = trim((string)($opt['value'] ?? ''));
          $opt['label_resolved'] = $label;
          return $opt;
        }, $optsTeacher);
      }

      $base = base_field_key((string)$f['field_name']);
      $child = ($base !== '' && isset($childByBase[$base])) ? $childByBase[$base] : null;

      $canEditField = can_user_edit_field(
        $pdo,
        $u,
        $classId,
        $schoolYear,
        $periodLabel,
        $meta,
        (string)($f['field_type'] ?? ''),
        (int)($f['is_multiline'] ?? 0)
      );

      $groups[$gKey]['fields'][] = [
        'id' => (int)$f['id'],
        'field_name' => (string)$f['field_name'],
        'field_type' => (string)$f['field_type'],
        'label' => label_for_lang($f['label'] ?? null, $f['label_en'] ?? null, $lang),
        'label_resolved' => resolve_label_placeholders(label_for_lang($f['label'] ?? null, $f['label_en'] ?? null, $lang), $classValueByName),
        'help_text' => (string)($f['help_text'] ?? ''),
        'help_text_resolved' => resolve_label_placeholders((string)($f['help_text'] ?? ''), $classValueByName),
        'is_multiline' => (int)($f['is_multiline'] ?? 0),
        'options' => $optsTeacher,
        'can_edit' => $canEditField ? 1 : 0,
        'child' => $child ? [
          'id' => (int)$child['id'],
          'field_name' => (string)($child['field_name'] ?? ''),
          'field_type' => (string)$child['field_type'],
          'label' => (string)($child['label'] ?? ''),
          'help_text' => (string)($child['help_text'] ?? ''),
          'is_multiline' => (int)($child['is_multiline'] ?? 0),
          'options' => $child['options'],
        ] : null,
      ];
    }

    $groupsList = array_values($groups);

    // annotate groups with permissions + delegation meta for UI
    $groupsList2 = [];
    foreach ($groupsList as $g0) {
      $gk = (string)($g0['key'] ?? '');
      $del = $gk !== '' && isset($delegations[$gk]) ? $delegations[$gk] : null;
      $anyEditable = false;
      if (isset($g0['fields']) && is_array($g0['fields'])) {
        foreach ($g0['fields'] as $f0) {
          if (!empty($f0['can_edit'])) {
            $anyEditable = true;
            break;
          }
        }
      }
      $g0['delegation'] = $del;
      $g0['can_edit'] = $anyEditable ? 1 : 0;
      $groupsList2[] = $g0;
    }
    $groupsList = $groupsList2;

    // values
    $teacherFieldIds = array_map(fn($x)=>(int)$x['id'], $teacherFields);
    $childFieldIds = array_values(array_unique(array_filter(array_map(
      fn($x)=> (int)($x['id'] ?? 0),
      array_values($childByBase)
    ), fn($x)=>$x>0)));

    $teacherValues = load_teacher_values_for_user(
      $pdo,
      $reportIds,
      $teacherFieldMap,
      $delegations,
      $u,
      $classId,
      $schoolYear,
      $periodLabel,
      $lang
    );
    $valuesTeacher = $teacherValues['combined'] ?? [];
    $valuesTeacherOwn = $teacherValues['own'] ?? [];
    $valuesChild = load_values($pdo, $reportIds, $childFieldIds, 'child', $lang);

    // --- progress (teacher / child / overall) ---
    $teacherProgressIds = [];
    foreach ($teacherFields as $f0) {
      $m0 = meta_read($f0['meta_json'] ?? null);
      if (is_system_bound($m0)) continue;
      if (is_class_field($m0)) continue;
      $teacherProgressIds[] = (int)$f0['id'];
    }

    $childProgressIds = [];
    $childProgressLabels = [];
    // load ALL child-editable fields for progress counting (not only paired)
    $childFieldsAll = load_child_fields_for_pairing($pdo, $templateId);
    foreach ($childFieldsAll as $cf0) {
      $m0 = meta_read($cf0['meta_json'] ?? null);
      if (is_system_bound($m0)) continue;
      if (is_class_field($m0)) continue;
      $childProgressIds[] = (int)$cf0['id'];
      $labelC = label_for_lang($cf0['label'] ?? null, $cf0['label_en'] ?? null, $lang);
      $fnameC = (string)($cf0['field_name'] ?? '');
      $childProgressLabels[(int)$cf0['id']] = $labelC !== '' ? $labelC : $fnameC;
    }

    $fieldMetaById = [];
    foreach ($teacherFields as $f0) {
      $fid = (int)($f0['id'] ?? 0);
      if ($fid <= 0) continue;
      $fieldMetaById[$fid] = ['meta' => meta_read($f0['meta_json'] ?? null)];
    }
    foreach ($childFieldsAll as $cf0) {
      $fid = (int)($cf0['id'] ?? 0);
      if ($fid <= 0 || isset($fieldMetaById[$fid])) continue;
      $fieldMetaById[$fid] = ['meta' => meta_read($cf0['meta_json'] ?? null)];
    }

    $historyFieldIds = array_values(array_unique(array_merge($teacherFieldIds, $childProgressIds, $classFieldIdsEditable)));
    $valueHistory = load_value_history($pdo, $reportIds, $historyFieldIds, $fieldMetaById, $lang, 5);

    $valuesChildAllForProgress = load_values($pdo, $reportIds, $childProgressIds, 'child', $lang);

    $teacherTotal = count($teacherProgressIds);
    $childTotal = count($childProgressIds);
    $overallTotal = $teacherTotal + $childTotal;

    $completeForms = 0;
    $lockedChildIdsByReport = locked_child_field_ids_for_reports($pdo, $teacherFields, $childFieldsAll, $reportIds);
    foreach ($students as &$srow) {
      $rid = (int)($srow['report_instance_id'] ?? 0);
      $ridKey = (string)$rid;
      $lockedChildIds = $lockedChildIdsByReport[$ridKey] ?? [];
      $childTotalForStudent = max(0, $childTotal - count($lockedChildIds));

      $tDone = 0;
      if ($teacherTotal > 0) {
        foreach ($teacherProgressIds as $fid) {
          $v = $valuesTeacher[$ridKey][(string)$fid] ?? '';
          if (trim((string)$v) !== '') $tDone++;
        }
      }

      $cDone = 0;
      $missingChildLabels = [];
      if ($childTotalForStudent > 0) {
        foreach ($childProgressIds as $fid) {
          if (!empty($lockedChildIds[$fid])) continue;
          $v = $valuesChildAllForProgress[$ridKey][(string)$fid] ?? '';
          $trimmed = trim((string)$v);
          if ($trimmed !== '') {
            $cDone++;
          } else {
            $lbl = $childProgressLabels[$fid] ?? '';
            if ($lbl !== '') $missingChildLabels[] = $lbl;
          }
        }
      }

      $overallTotalForStudent = $teacherTotal + $childTotalForStudent;
      $oDone = $tDone + $cDone;
      $oMissing = max(0, $overallTotalForStudent - $oDone);
      $isComplete = ($overallTotalForStudent > 0 && $oMissing === 0);
      if ($isComplete) $completeForms++;

      $srow['progress_teacher_total'] = $teacherTotal;
      $srow['progress_teacher_done'] = $tDone;
      $srow['progress_teacher_missing'] = max(0, $teacherTotal - $tDone);

      $srow['progress_child_total'] = $childTotalForStudent;
      $srow['progress_child_done'] = $cDone;
      $srow['progress_child_missing'] = max(0, $childTotalForStudent - $cDone);
      $srow['child_missing_fields'] = $missingChildLabels;

      $srow['progress_overall_total'] = $overallTotalForStudent;
      $srow['progress_overall_done'] = $oDone;
      $srow['progress_overall_missing'] = $oMissing;
      $srow['progress_is_complete'] = $isComplete;
    }
    unset($srow);

    // class fields progress (counts only class-scope editable fields)
    $classTotal = count($classFieldIdsEditable);
    $classDone = 0;
    if ($classTotal > 0 && $classReportInstanceId > 0) {
      $ridKey = (string)(int)$classReportInstanceId;
      foreach ($classFieldIdsEditable as $fid) {
        $v = $classValuesById[$ridKey][(string)$fid] ?? '';
        if (trim((string)$v) !== '') $classDone++;
      }
    }

    $progressSummary = [
      'students_total' => count($students),
      'forms_complete' => $completeForms,
      'forms_incomplete' => max(0, count($students) - $completeForms),
      'teacher_fields_total' => $teacherTotal,
      'child_fields_total' => $childTotal,
      'overall_fields_total' => $overallTotal,
      'class_fields_total' => $classTotal,
      'class_fields_done' => $classDone,
      'class_fields_missing' => max(0, $classTotal - $classDone),
    ];

    $classGradeLevel = null;
    $stClass = $pdo->prepare("SELECT grade_level FROM classes WHERE id=? LIMIT 1");
    $stClass->execute([$classId]);
    $classGradeLevel = $stClass->fetchColumn();

    $isClassTeacher = (($u['role'] ?? '') === 'admin') || user_is_class_teacher($pdo, $userId, $classId);

    json_out([
      'ok' => true,
      'template' => [
        'id' => $templateId,
        'name' => (string)$tpl['name'],
        'version' => (int)$tpl['template_version'],
      ],
      'students' => $students,
      'groups' => $groupsList,
      'delegation_users' => $delegationUsers,
      'delegations' => array_values($delegations),
      'period_label' => $periodLabel,
      'text_snippets' => text_snippets_list($pdo),
      'values_teacher' => $valuesTeacher,
      'values_teacher_own' => $valuesTeacherOwn,
      'values_teacher_parts' => $teacherValues['parts'] ?? [],
      'values_child' => $valuesChild,
      'value_history' => $valueHistory,
      'progress_summary' => $progressSummary,
      'class_report_instance_id' => $classReportInstanceId,
      'class_fields' => [
        // ✅ IMPORTANT: only editable class fields
        'field_ids' => $classFieldIdsEditable,
        'fields' => $classFieldsDefs,
        'values' => $classValuesById,
        'values_own' => $classValuesOwnById,
        'values_parts' => $classValues['parts'] ?? [],
        'value_by_name' => $classValueByName,
      ],
      'ai_enabled' => ai_provider_enabled(),
      'class_grade_level' => $classGradeLevel !== false ? $classGradeLevel : null,
      'is_class_teacher' => $isClassTeacher,
    ]);
  }

  if ($action === 'load_pdf') {
    $classId = (int)($data['class_id'] ?? 0);
    $studentId = (int)($data['student_id'] ?? 0);
    if ($classId <= 0 || $studentId <= 0) throw new RuntimeException('class_id/student_id fehlt.');

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $stStu = $pdo->prepare("SELECT id, first_name, last_name, class_id FROM students WHERE id=? LIMIT 1");
    $stStu->execute([$studentId]);
    $stu = $stStu->fetch(PDO::FETCH_ASSOC);
    if (!$stu || (int)($stu['class_id'] ?? 0) !== $classId) {
      throw new RuntimeException('Schüler gehört nicht zur Klasse.');
    }
    $studentNav = [
      'prev_id' => null,
      'next_id' => null,
      'prev_name' => '',
      'next_name' => '',
      'position' => null,
      'total' => null,
    ];
    $stNav = $pdo->prepare(
      "SELECT id, first_name, last_name
       FROM students
       WHERE class_id=? AND is_active=1
       ORDER BY last_name ASC, first_name ASC"
    );
    $stNav->execute([$classId]);
    $navRows = $stNav->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $navIds = [];
    $navNames = [];
    foreach ($navRows as $row) {
      $sid = (int)($row['id'] ?? 0);
      if ($sid <= 0) continue;
      $navIds[] = $sid;
      $navNames[$sid] = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }
    $navCount = count($navIds);
    if ($navCount > 0) {
      $pos = array_search($studentId, $navIds, true);
      if ($pos !== false) {
        $studentNav['position'] = $pos + 1;
        $studentNav['total'] = $navCount;
        if ($pos > 0) {
          $prevId = $navIds[$pos - 1];
          $studentNav['prev_id'] = $prevId;
          $studentNav['prev_name'] = (string)($navNames[$prevId] ?? '');
        }
        if ($pos < $navCount - 1) {
          $nextId = $navIds[$pos + 1];
          $studentNav['next_id'] = $nextId;
          $studentNav['next_name'] = (string)($navNames[$nextId] ?? '');
        }
      }
    }

    $tpl = template_for_class($pdo, $classId);
    $templateId = (int)$tpl['id'];
    $schoolYear = class_school_year($pdo, $classId);
    if ($schoolYear === '') $schoolYear = date('Y');
    $periodLabel = 'Standard';
    $delegations = load_class_group_delegations($pdo, $classId, $schoolYear, $periodLabel);

    $ri = find_or_create_report_instance_for_student($pdo, $templateId, $studentId, $schoolYear, $userId);
    $reportId = (int)($ri['id'] ?? 0);
    $classReportInstanceId = find_or_create_class_report_instance($pdo, $templateId, $classId, $schoolYear);
    if ($reportId > 0) {
      apply_system_bindings($pdo, $reportId);
    }

    $teacherFields = load_teacher_fields($pdo, $templateId);
    $childFields = load_child_fields_for_pairing($pdo, $templateId);
    $optCache = [];
    $iconCache = [];
    $childFieldByBase = [];
    foreach ($childFields as $childField) {
      $base = base_field_key((string)($childField['field_name'] ?? ''));
      if ($base === '' || isset($childFieldByBase[$base])) continue;
      $childFieldByBase[$base] = (int)($childField['id'] ?? 0);
    }

    $fields = [];
    $fieldsById = [];
    $studentFieldIds = [];
    $classFieldIds = [];
    $fieldMapInput = [];

    $appendField = function(array $f, bool $canEditOverride, bool $forceChildOnly) use (
      $pdo,
      $u,
      $classId,
      $schoolYear,
      $lang,
      $childFieldByBase,
      &$optCache,
      &$iconCache,
      &$fields,
      &$fieldsById,
      &$studentFieldIds,
      &$classFieldIds,
      &$fieldMapInput
    ): void {
      $meta = meta_read($f['meta_json'] ?? null);
      $isSystemBound = is_system_bound($meta);
      $childFieldId = 0;
      if (!$forceChildOnly) {
        $base = base_field_key((string)($f['field_name'] ?? ''));
        if ($base !== '') $childFieldId = (int)($childFieldByBase[$base] ?? 0);
      }

      $pageRaw = $meta['page'] ?? null;
      $page = is_numeric($pageRaw) ? (int)$pageRaw : null;
      $rect = normalize_pdf_rect($meta['rect'] ?? null);

      $listIdF = option_list_id_from_meta($meta);
      if ($listIdF > 0) {
        if (!isset($optCache[$listIdF])) $optCache[$listIdF] = load_option_list_items($pdo, $listIdF, $iconCache);
        $optsTeacher = $optCache[$listIdF];
      } else {
        $optsTeacher = map_option_icons($pdo, decode_options($f['options_json'] ?? null), $iconCache);
      }
      if (!$optsTeacher && (string)$f['field_type'] === 'grade') {
        $optsTeacher = [
          ['value'=>'1','label'=>'1'],
          ['value'=>'2','label'=>'2'],
          ['value'=>'3','label'=>'3'],
          ['value'=>'4','label'=>'4'],
          ['value'=>'5','label'=>'5'],
          ['value'=>'6','label'=>'6'],
        ];
      }
      if (!$optsTeacher && isset($meta['options']) && is_array($meta['options'])) {
        $optsTeacher = map_option_icons($pdo, $meta['options'], $iconCache);
      }
      if ($optsTeacher) {
        $optsTeacher = array_map(function(array $opt) use ($lang) {
          $label = label_for_lang($opt['label'] ?? null, $opt['label_en'] ?? null, $lang);
          if ($label === '') $label = trim((string)($opt['value'] ?? ''));
          $opt['label_resolved'] = $label;
          return $opt;
        }, $optsTeacher);
      }

      $periodLabel = 'Standard';
      $type = (string)($f['field_type'] ?? '');
      $isMultiline = (int)($f['is_multiline'] ?? 0);
      $canEditField = false;
      if ($canEditOverride && !$isSystemBound) {
        $canEditField = can_user_edit_field(
          $pdo,
          $u,
          $classId,
          $schoolYear,
          $periodLabel,
          $meta,
          $type,
          $isMultiline
        );
      }

      $isClassField = is_class_field($meta);
      $fid = (int)($f['id'] ?? 0);
      $fieldMapInput[$fid] = [
        'field_type' => $type,
        'meta' => $meta,
        'is_multiline' => $isMultiline,
      ];
      if ($isClassField) {
        $classFieldIds[] = $fid;
      } else {
        $studentFieldIds[] = $fid;
      }

      $fields[] = [
        'id' => $fid,
        'field_name' => (string)($f['field_name'] ?? ''),
        'field_type' => $type,
        'label' => label_for_lang($f['label'] ?? null, $f['label_en'] ?? null, $lang),
        'help_text' => (string)($f['help_text'] ?? ''),
        'is_multiline' => $isMultiline,
        'options' => $optsTeacher,
        'can_edit' => $canEditField ? 1 : 0,
        'date_format' => date_format_pattern_from_meta($meta, $type),
        'page' => $page,
        'rect' => $rect,
        'scope' => $isClassField ? 'class' : 'student',
        'child_only' => $forceChildOnly ? 1 : 0,
        'system_bound' => $isSystemBound ? 1 : 0,
        'child_field_id' => $childFieldId,
      ];
      $fieldsById[$fid] = true;
    };

    foreach ($teacherFields as $f) {
      $fid = (int)($f['id'] ?? 0);
      if ($fid <= 0 || isset($fieldsById[$fid])) continue;
      $appendField($f, true, false);
    }

    foreach ($childFields as $f) {
      $fid = (int)($f['id'] ?? 0);
      if ($fid <= 0 || isset($fieldsById[$fid])) continue;
      $appendField($f, false, true);
    }

    $values = [];
    $valuesChild = [];
    $valuesDelegateOther = [];
    if ($reportId > 0 && $studentFieldIds) {
      $studentFieldMap = array_intersect_key($fieldMapInput, array_flip($studentFieldIds));
      $teacherValues = load_teacher_values_for_user(
        $pdo,
        [$reportId],
        $studentFieldMap,
        $delegations,
        $u,
        $classId,
        $schoolYear,
        $periodLabel,
        $lang
      );
      $values = $teacherValues['own'][(string)$reportId] ?? [];
      $partsForReport = $teacherValues['parts'][(string)$reportId] ?? [];
      $isClassTeacher = (($u['role'] ?? '') === 'admin') || user_is_class_teacher($pdo, $userId, $classId);
      foreach ($partsForReport as $fid => $parts) {
        $fieldId = (int)$fid;
        if (!isset($studentFieldMap[$fieldId])) continue;
        $meta = $studentFieldMap[$fieldId]['meta'] ?? [];
        $type = (string)($studentFieldMap[$fieldId]['field_type'] ?? '');
        $isMultiline = (int)($studentFieldMap[$fieldId]['is_multiline'] ?? 0);
        if (!is_free_text_field($type, $isMultiline)) continue;
        $assigned = (int)($parts['delegate_user_id'] ?? 0);
        if ($assigned <= 0) continue;
        $isDelegate = ($assigned === $userId) && !$isClassTeacher;
        $otherText = $isDelegate
          ? (string)($parts['class_text'] ?? '')
          : (string)($parts['delegate_text'] ?? '');
        if (trim($otherText) !== '') {
          $valuesDelegateOther[(string)$fieldId] = $otherText;
        }
      }
      $valsSystem = load_input_values($pdo, [$reportId], $studentFieldMap, 'system');
      $valuesSystem = $valsSystem[(string)$reportId] ?? [];
      if ($valuesSystem) {
        $values = array_replace($values, $valuesSystem);
      }
      $valsChild = load_input_values($pdo, [$reportId], $studentFieldMap, 'child');
      $valuesChild = $valsChild[(string)$reportId] ?? [];
    }
    if ($classReportInstanceId > 0 && $classFieldIds) {
      $classFieldMap = array_intersect_key($fieldMapInput, array_flip($classFieldIds));
      $vals = load_input_values($pdo, [$classReportInstanceId], $classFieldMap, 'teacher');
      $classVals = $vals[(string)$classReportInstanceId] ?? [];
      $values = array_replace($values, $classVals);
    }
    if ($values) $values = apply_date_iso_formatting($values, $fieldMapInput);
    if ($valuesChild) $valuesChild = apply_date_iso_formatting($valuesChild, $fieldMapInput);
    if ($valuesDelegateOther) $valuesDelegateOther = apply_date_iso_formatting($valuesDelegateOther, $fieldMapInput);

    $valuesDisplay = $values ? apply_date_formatting($values, $fieldMapInput) : [];
    $valuesChildDisplay = $valuesChild ? apply_date_formatting($valuesChild, $fieldMapInput) : [];
    $valuesDelegateOtherDisplay = $valuesDelegateOther ? apply_date_formatting($valuesDelegateOther, $fieldMapInput) : [];

    json_out([
      'ok' => true,
      'template' => [
        'id' => $templateId,
        'name' => (string)$tpl['name'],
        'version' => (int)$tpl['template_version'],
        'pdf_url' => url('template_file.php?class_id=' . (int)$classId),
      ],
      'student' => [
        'id' => (int)$stu['id'],
        'name' => trim((string)($stu['first_name'] ?? '') . ' ' . (string)($stu['last_name'] ?? '')),
        'report_instance_id' => $reportId,
      ],
      'student_nav' => $studentNav,
      'class_report_instance_id' => $classReportInstanceId,
      'fields' => $fields,
      'values' => $values,
      'values_child' => $valuesChild,
      'values_delegate_other' => $valuesDelegateOther,
      'values_display' => $valuesDisplay,
      'values_child_display' => $valuesChildDisplay,
      'values_delegate_other_display' => $valuesDelegateOtherDisplay,
    ]);
  }

  if ($action === 'snippets_list') {
    json_out(['ok' => true, 'snippets' => text_snippets_list($pdo)]);
  }

  if ($action === 'snippet_save') {
    $title = (string)($data['title'] ?? '');
    $category = (string)($data['category'] ?? '');
    $content = (string)($data['content'] ?? '');
    $row = text_snippet_save($pdo, $userId, $title, $category, $content);
    json_out(['ok' => true, 'snippet' => $row]);
  }

  if ($action === 'ai_suggestions') {
    $classId = (int)($data['class_id'] ?? 0);
    $reportId = (int)($data['report_instance_id'] ?? 0);

    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
    if ($reportId <= 0) throw new RuntimeException('report_instance_id fehlt.');

    if (!ai_provider_enabled()) {
      throw new RuntimeException('KI-Vorschläge sind deaktiviert oder nicht konfiguriert.');
    }

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $stInfo = $pdo->prepare(
      "SELECT ri.template_id, ri.student_id, ri.school_year, s.first_name, s.date_of_birth, s.class_id, c.grade_level
       FROM report_instances ri
       JOIN students s ON s.id=ri.student_id
       JOIN classes c ON c.id=s.class_id
       WHERE ri.id=?
       LIMIT 1"
    );
    $stInfo->execute([$reportId]);
    $info = $stInfo->fetch(PDO::FETCH_ASSOC);
    if (!$info) throw new RuntimeException('Bericht nicht gefunden.');
    if ((int)$info['class_id'] !== $classId) throw new RuntimeException('Bericht gehört nicht zur Klasse.');

    $templateId = (int)$info['template_id'];
    $studentName = trim((string)$info['first_name']);
    $birthYear = '';
    if (!empty($info['date_of_birth'])) {
      $ts = strtotime((string)$info['date_of_birth']);
      if ($ts !== false) $birthYear = date('Y', $ts);
    }
    $gradeLevel = $info['grade_level'] !== null ? (int)$info['grade_level'] : null;

    $stCtx = $pdo->prepare(
      "SELECT tf.id, tf.field_name, tf.field_type, tf.label, tf.label_en, tf.meta_json, tf.options_json,
              t.value_text AS teacher_value, c.value_text AS child_value,
              t.value_json AS teacher_value_json, c.value_json AS child_value_json
       FROM template_fields tf
       LEFT JOIN field_values t ON t.template_field_id=tf.id AND t.report_instance_id=? AND t.source='teacher'
       LEFT JOIN field_values c ON c.template_field_id=tf.id AND c.report_instance_id=? AND c.source='child'
       WHERE tf.template_id=? AND tf.can_teacher_edit=1"
    );
    $stCtx->execute([$reportId, $reportId, $templateId]);
    $ctxFields = $stCtx->fetchAll(PDO::FETCH_ASSOC);

    $teacherEntries = [];
    $selfAssessments = [];
    $comparisons = [];

    $missingOptionFields = [];
    foreach ($ctxFields as $cf) {
      $meta = meta_read($cf['meta_json'] ?? null);
      $resolvedTeacher = resolve_option_value_text($pdo, $meta, $cf['teacher_value_json'] ?? null, $cf['teacher_value'] ?? '', $lang);
      $resolvedChild = resolve_option_value_text($pdo, $meta, $cf['child_value_json'] ?? null, $cf['child_value'] ?? '', $lang);

      $val = trim((string)($resolvedTeacher['text'] ?? ($cf['teacher_value'] ?? '')));
      $childVal = trim((string)($resolvedChild['text'] ?? ($cf['child_value'] ?? '')));
      $label = label_for_lang($cf['label'] ?? null, $cf['label_en'] ?? null, $lang);

      $type = (string)($cf['field_type'] ?? '');
      $hasOptionList = option_list_id_from_meta($meta) > 0;
      if (in_array($type, ['radio','select','grade'], true) && $hasOptionList && $val === '') {
        $missingOptionFields[] = $label !== '' ? $label : (string)($cf['field_name'] ?? '');
      }
      if ($val !== '') {
        $teacherEntries[] = ($label ? ($label . ': ') : '') . $val;
      }

      if ($childVal !== '') {
        $selfAssessments[] = ($label ? ($label . ': ') : '') . $childVal;
      }

      if ($val !== '' && $childVal !== '' && strcasecmp($val, $childVal) !== 0) {
        $comparisons[] = ($label ? ($label . ': ') : '') . 'Lehrer=' . $val . ' | Schüler=' . $childVal;
      }
    }

    if ($missingOptionFields) {
      $msg = 'Bitte zuerst alle Options-Felder ausfüllen. Offen: ' . implode(', ', array_slice($missingOptionFields, 0, 5));
      if (count($missingOptionFields) > 5) $msg .= ' …';
      throw new RuntimeException($msg);
    }

    $aiCfg = ai_provider_config();

    $ctxParts = [];
    $ctxParts[] = 'Schüler (anonymisiert): ' . $studentName . ($birthYear !== '' ? (' (Geburtsjahr: ' . $birthYear . ')') : '');
    if ($gradeLevel !== null) $ctxParts[] = 'Klassenstufe: ' . $gradeLevel;
    if ($teacherEntries) {
      $ctxParts[] = "Lehrkraft-Einträge (maßgeblich):\n" . implode("\n", array_slice($teacherEntries, 0, 20));
    }
    if ($selfAssessments) {
      $ctxParts[] = "Selbsteinschätzung des Schülers (nur zur Einordnung):\n" . implode("\n", array_slice($selfAssessments, 0, 10));
    }
    if ($comparisons) $ctxParts[] = 'Abweichungen Lehrer/Schüler: ' . implode(' | ', array_slice($comparisons, 0, 6));

    $userPrompt = trim(implode("\n", array_filter($ctxParts)));

    $messages = [
      [
        'role' => 'system',
        'content' => 'Du bist eine Lehrhilfe und erstellst kurze deutsche Vorschläge mit Fokus auf Stärken, konkrete Ziele und praktikable Schritte. Schreibe altersgerechte Formulierungen, orientiert an der Klassenstufe. Für Kompetenzbewertung und Zielvorschläge nutzt du ausschließlich die Einträge der Lehrkraft. Schülerangaben nutzt du nur, um das Selbsteinschätzungsvermögen zu bewerten und Abweichungen ggf. zu benennen. Kein Intro, keine Nummerierung.',
      ],
      [
        'role' => 'user',
        'content' => $userPrompt . "\n\nGib JSON im Format {\"strengths\":[],\"goals\":[],\"steps\":[]} zurück, jeweils mit 4 kurzen Einträgen (max. 2 Sätze). Stärken sind wertschätzende Beobachtungen auf Basis der Lehrkraft-Einträge. Ziele beschreiben den nächsten Lernschritt für das kommende Halbjahr und beziehen sich auf konkrete Fähigkeiten oder Sozial-/Lernverhalten; Schritte zeigen konkrete Möglichkeiten, diese Ziele zu erreichen. Alle drei sollen in der ich-Perspektive formuliert sein. Wenn Schülerangaben stark von den Lehrkraft-Einträgen abweichen, erwähne kurz die Abweichung zur Selbsteinschätzung.",
      ],
    ];

    $aiText = ai_chat_completion($messages, $aiCfg);

    $parsed = ['strengths' => [], 'goals' => [], 'steps' => []];
    $json = json_decode($aiText, true);
    if (is_array($json)) {
      foreach (['strengths','goals','steps'] as $k) {
        if (isset($json[$k]) && is_array($json[$k])) {
          $parsed[$k] = array_values(array_filter(array_map(fn($s)=>trim((string)$s), $json[$k]), fn($s)=>$s!==''));
        }
      }
    }

    if (!$parsed['strengths'] && !$parsed['goals'] && !$parsed['steps']) {
      $flat = normalize_ai_suggestions($aiText);
      $parsed['goals'] = $flat;
    }

    json_out(['ok' => true, 'suggestions' => $parsed]);
  }

  
  if ($action === 'ai_support_plan') {
    $classId = (int)($data['class_id'] ?? 0);
    $reportId = (int)($data['report_instance_id'] ?? 0);
    $force = (int)($data['force'] ?? 0) === 1;

    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
    if ($reportId <= 0) throw new RuntimeException('report_instance_id fehlt.');

    if (!ai_provider_enabled()) {
      throw new RuntimeException('KI-Vorschläge sind deaktiviert oder nicht konfiguriert.');
    }

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    // Cache (per report + language)
    $ttl = ai_cache_ttl_seconds();
    $cacheKey = 'support_plan:v1:' . $reportId . ':' . ui_lang();
    if (!$force) {
      $cached = ai_cache_get($cacheKey, $ttl);
      if (is_array($cached) && isset($cached['payload']) && is_array($cached['payload'])) {
        $age = time() - (int)($cached['created_at'] ?? time());
        json_out([
          'ok' => true,
          'support_plan' => $cached['payload'],
          'meta' => [
            'cached' => true,
            'cache_age_seconds' => max(0, (int)$age),
          ],
        ]);
      }
    }

    // Load report instance & student meta (ensure report belongs to class)
    $stInfo = $pdo->prepare(
      "SELECT ri.template_id, ri.student_id, ri.school_year, s.first_name, s.date_of_birth, s.class_id, c.grade_level
       FROM report_instances ri
       JOIN students s ON s.id=ri.student_id
       JOIN classes c ON c.id=s.class_id
       WHERE ri.id=?
       LIMIT 1"
    );
    $stInfo->execute([$reportId]);
    $info = $stInfo->fetch(PDO::FETCH_ASSOC);
    if (!$info) throw new RuntimeException('Bericht nicht gefunden.');
    if ((int)$info['class_id'] !== $classId) throw new RuntimeException('Bericht gehört nicht zur Klasse.');

    $templateId = (int)$info['template_id'];
    $schoolYear = (string)($info['school_year'] ?? '');
    $gradeLevel = (int)($info['grade_level'] ?? 0);
    $studentName = trim((string)($info['first_name'] ?? ''));
    $birthYear = '';
    if (!empty($info['date_of_birth'])) {
      $ts = strtotime((string)$info['date_of_birth']);
      if ($ts !== false) $birthYear = date('Y', $ts);
    }

    // Load all template fields with values for this report instance (teacher + child)
    $stFields = $pdo->prepare(
      "SELECT tf.id AS template_field_id, tf.field_type, tf.label, tf.label_en, tf.group_label, tf.group_label_en,
              tf.meta_json,
              rf.teacher_value, rf.teacher_value_json,
              rf.child_value, rf.child_value_json
       FROM template_fields tf
       LEFT JOIN report_fields rf
         ON rf.report_instance_id=? AND rf.template_field_id=tf.id
       WHERE tf.template_id=?
       ORDER BY tf.sort_order ASC, tf.id ASC"
    );
    $stFields->execute([$reportId, $templateId]);
    $ctxFields = $stFields->fetchAll(PDO::FETCH_ASSOC);

    // Build compact context from filled fields, resolving option lists to readable text
    $ctxParts = [];
    $comparisons = [];
    $filledCount = 0;
    $lang = ui_lang();

    foreach ($ctxFields as $cf) {
      $meta = meta_read($cf['meta_json'] ?? null);

      $resolvedTeacher = resolve_option_value_text($pdo, $meta, $cf['teacher_value_json'] ?? null, $cf['teacher_value'] ?? '', $lang);
      $resolvedChild   = resolve_option_value_text($pdo, $meta, $cf['child_value_json'] ?? null, $cf['child_value'] ?? '', $lang);

      $teacherText = trim((string)($resolvedTeacher['text'] ?? ($cf['teacher_value'] ?? '')));
      $childText   = trim((string)($resolvedChild['text'] ?? ($cf['child_value'] ?? '')));

      if ($teacherText === '' && $childText === '') continue;

      $filledCount++;

      $label = label_for_lang($cf['label'] ?? null, $cf['label_en'] ?? null, $lang);
      $group = label_for_lang($cf['group_label'] ?? null, $cf['group_label_en'] ?? null, $lang);
      $prefix = $group !== '' ? ($group . ' – ') : '';

      if ($teacherText !== '' && $childText !== '' && $teacherText !== $childText) {
        $comparisons[] = $prefix . $label . ': Lehrer=' . $teacherText . ' | Schüler=' . $childText;
      } else {
        $one = $teacherText !== '' ? $teacherText : $childText;
        $ctxParts[] = $prefix . $label . ': ' . $one;
      }
    }

    if ($comparisons) {
      $ctxParts[] = 'Abweichungen Lehrer/Schüler: ' . implode(' | ', array_slice($comparisons, 0, 6));
    }

    $context = trim(implode("\n", array_filter($ctxParts)));
    if ($context === '') {
      $context = '(Noch keine inhaltlichen Eingaben vorhanden. Bitte arbeite mit allgemeinen, aber praxisnahen Förderideen.)';
    }

    $aiCfg = ai_provider_config();
    $messages = [
      [
        'role' => 'system',
        'content' =>
          'Du bist eine erfahrene Lehrkraft (Grundschule) und erstellst sehr konkrete, umsetzbare Fördermöglichkeiten. ' .
          'Nutze nur die gelieferten Angaben und formuliere keine Diagnosen. ' .
          'Wenn Informationen fehlen, formuliere Optionen („Wenn … dann …“) und kurze Beobachtungspunkte. ' .
          'Keine Einleitung, keine Floskeln.'
      ],
      [
        'role' => 'user',
        'content' =>
          "Schüler (anonymisiert): {$studentName}" . ($birthYear !== '' ? " (Geburtsjahr: {$birthYear})" : '') . "\n" .
          "Klassenstufe: {$gradeLevel}\nSchuljahr: {$schoolYear}\n\n" .
          "Eingaben (Lehrer + Schüler):\n{$context}\n\n" .
          "Erstelle umfangreiche, fächerübergreifende Fördermöglichkeiten. Gib ausschließlich JSON zurück im Format:\n" .
          "{\"kurzprofil\":\"...\",\"foerder_uebergreifend\":[...],\"deutsch\":[...],\"mathe\":[...],\"sachkunde\":[...],\"lernorganisation\":[...],\"sozial_emotional\":[...],\"zu_hause\":[...],\"diagnostik_naechste_schritte\":[...] }\n" .
          "Regeln: Alle Listen-Elemente als kurze, konkrete Maßnahmen (max. 1–2 Sätze), möglichst mit Material/Beispiel. " .
          "Mindestens 6 Punkte bei foerder_uebergreifend, jeweils mindestens 4 bei deutsch/mathe. Keine Nummerierung, kein Markdown."
      ],
    ];

    $aiText = ai_chat_completion($messages, $aiCfg);

    $parsed = [
      'kurzprofil' => '',
      'foerder_uebergreifend' => [],
      'deutsch' => [],
      'mathe' => [],
      'sachkunde' => [],
      'lernorganisation' => [],
      'sozial_emotional' => [],
      'zu_hause' => [],
      'diagnostik_naechste_schritte' => [],
    ];

    $json = json_decode((string)$aiText, true);
    if (is_array($json)) {
      if (isset($json['kurzprofil'])) $parsed['kurzprofil'] = trim((string)$json['kurzprofil']);
      foreach (['foerder_uebergreifend','deutsch','mathe','sachkunde','lernorganisation','sozial_emotional','zu_hause','diagnostik_naechste_schritte'] as $k) {
        if (isset($json[$k]) && is_array($json[$k])) {
          $parsed[$k] = array_values(array_filter(array_map(fn($s)=>trim((string)$s), $json[$k]), fn($s)=>$s!==''));
        }
      }
    } else {
      // Fallback: treat as bullet list
      $parsed['foerder_uebergreifend'] = array_slice(normalize_ai_suggestions((string)$aiText), 0, 12);
    }

    // Save cache
    ai_cache_set($cacheKey, $parsed);

    json_out([
      'ok' => true,
      'support_plan' => $parsed,
      'meta' => [
        'cached' => false,
        'filled_fields' => $filledCount,
        'cache_ttl_seconds' => $ttl,
      ],
    ]);
  }

  if ($action === 'ai_class_feedback') {
    $classId = (int)($data['class_id'] ?? 0);
    $force = (int)($data['force'] ?? 0) === 1;

    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
    if (!ai_provider_enabled()) {
      throw new RuntimeException('KI-Vorschläge sind deaktiviert oder nicht konfiguriert.');
    }
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $ttl = ai_cache_ttl_seconds();
    $cacheKey = 'class_feedback:v1:' . $classId . ':' . ui_lang();
    if (!$force) {
      $cached = ai_cache_get($cacheKey, $ttl);
      if (is_array($cached) && isset($cached['payload']) && is_array($cached['payload'])) {
        $age = time() - (int)($cached['created_at'] ?? time());
        json_out([
          'ok' => true,
          'feedback' => $cached['payload'],
          'meta' => [
            'cached' => true,
            'cache_age_seconds' => max(0, (int)$age),
          ],
        ]);
      }
    }

    $tpl = template_for_class($pdo, $classId);
    $templateId = (int)$tpl['id'];
    $schoolYear = class_school_year($pdo, $classId);
    if ($schoolYear === '') $schoolYear = date('Y');

    $stClass = $pdo->prepare("SELECT grade_level FROM classes WHERE id=? LIMIT 1");
    $stClass->execute([$classId]);
    $gradeLevel = $stClass->fetchColumn();
    $gradeLevel = $gradeLevel !== false ? (int)$gradeLevel : null;

    $stStudents = $pdo->prepare(
      "SELECT id FROM students WHERE class_id=? AND is_active=1 ORDER BY id ASC"
    );
    $stStudents->execute([$classId]);
    $studentIds = array_map(fn($r) => (int)$r['id'], $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: []);
    $studentCount = count($studentIds);

    $reportIds = [];
    foreach ($studentIds as $sid) {
      $ri = find_or_create_report_instance_for_student($pdo, $templateId, $sid, $schoolYear, $userId);
      if (is_array($ri) && isset($ri['id'])) $reportIds[] = (int)$ri['id'];
    }
    $reportToStudent = [];
    if ($reportIds) {
      $chunks = array_chunk($reportIds, 200);
      foreach ($chunks as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $stMap = $pdo->prepare(
          "SELECT id, student_id FROM report_instances WHERE id IN ($in)"
        );
        $stMap->execute($chunk);
        foreach ($stMap->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
          $reportToStudent[(int)$row['id']] = (int)$row['student_id'];
        }
      }
    }

    $teacherFields = load_teacher_fields($pdo, $templateId);
    $fieldsById = [];
    $gradeFieldIds = [];
    $competencyLists = [];
    $optCache = [];

    foreach ($teacherFields as $f) {
      $meta = meta_read($f['meta_json'] ?? null);
      if (is_system_bound($meta) || is_class_field($meta)) continue;
      $fid = (int)($f['id'] ?? 0);
      if ($fid <= 0) continue;

      $groupKey = group_key_from_meta($meta);
      $groupTitle = group_title_from_meta($meta, $groupKey, $lang);
      $label = label_for_lang($f['label'] ?? null, $f['label_en'] ?? null, $lang);
      $listId = option_list_id_from_meta($meta);
      $fieldType = (string)($f['field_type'] ?? '');

      $options = [];
      if ($listId > 0) {
        if (!isset($optCache[$listId])) $optCache[$listId] = load_option_list_items($pdo, $listId);
        $options = $optCache[$listId];
      } else {
        $options = decode_options($f['options_json'] ?? null);
      }

      $fieldsById[$fid] = [
        'label' => $label !== '' ? $label : ('Feld#' . $fid),
        'group' => $groupTitle !== '' ? $groupTitle : $groupKey,
        'field_type' => $fieldType,
        'meta' => $meta,
        'list_id' => $listId,
        'options' => $options,
      ];

      if ($fieldType === 'grade') {
        $gradeFieldIds[] = $fid;
      }

      if (in_array($fieldType, ['select','radio'], true)) {
        if ($listId > 0) {
          $competencyLists[$listId] = [
            'name' => '',
            'items' => $options,
          ];
        } elseif ($options) {
          $competencyLists['field_' . $fid] = [
            'name' => $label !== '' ? $label : ('Feld#' . $fid),
            'items' => $options,
          ];
        }
      }
    }

    $gradeFieldIds = array_values(array_unique($gradeFieldIds));
    $fieldIds = array_keys($fieldsById);

    $values = [];
    if ($reportIds && $fieldIds) {
      $reportChunks = array_chunk($reportIds, 200);
      $fieldChunks = array_chunk($fieldIds, 200);
      foreach ($reportChunks as $rChunk) {
        foreach ($fieldChunks as $fChunk) {
          $rIn = implode(',', array_fill(0, count($rChunk), '?'));
          $fIn = implode(',', array_fill(0, count($fChunk), '?'));
          $stVals = $pdo->prepare(
            "SELECT report_instance_id, template_field_id, value_text, value_json
             FROM field_values
             WHERE source='teacher' AND report_instance_id IN ($rIn) AND template_field_id IN ($fIn)"
          );
          $stVals->execute([...$rChunk, ...$fChunk]);
          $values = array_merge($values, $stVals->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
      }
    }

    $gradeValues = [];
    $gradeDistribution = [];
    $groupGrades = [];
    $performanceValues = [];
    $groupStats = [];
    $studentSummaries = [];
    $studentIndex = [];
    foreach ($studentIds as $idx => $sid) {
      $studentIndex[$sid] = $idx + 1;
      $studentSummaries[$sid] = [
        'groups' => [],
      ];
    }

    foreach ($values as $row) {
      $fid = (int)($row['template_field_id'] ?? 0);
      if (!isset($fieldsById[$fid])) continue;
      $field = $fieldsById[$fid];
      $meta = $field['meta'];

      $resolved = resolve_option_value_text(
        $pdo,
        $meta,
        $row['value_json'] !== null ? (string)$row['value_json'] : null,
        $row['value_text'] !== null ? (string)$row['value_text'] : null,
        $lang
      );
      $text = trim((string)($resolved['text'] ?? ''));
      if ($text === '') continue;

      $group = $field['group'] ?: '—';
      if (!isset($groupStats[$group])) {
        $groupStats[$group] = [
          'grade' => [],
          'performance' => [],
          'competency' => [],
        ];
      }

      $rid = (int)($row['report_instance_id'] ?? 0);
      $sid = $rid && isset($reportToStudent[$rid]) ? $reportToStudent[$rid] : null;
      if ($sid !== null && isset($studentSummaries[$sid])) {
        $label = $field['label'] ?? ('Feld#' . $fid);
        $entry = $label . ': ' . $text;
        if (!isset($studentSummaries[$sid]['groups'][$group])) {
          $studentSummaries[$sid]['groups'][$group] = [];
        }
        $studentSummaries[$sid]['groups'][$group][] = $entry;
      }

      $numeric = parse_numeric_value($text);
      if ($numeric === null && $row['value_text'] !== null) {
        $numeric = parse_numeric_value((string)$row['value_text']);
      }

      if ($field['field_type'] === 'grade') {
        $gradeDistribution[$text] = ($gradeDistribution[$text] ?? 0) + 1;
        if ($numeric !== null) {
          $gradeValues[] = $numeric;
          if (!isset($groupGrades[$group])) $groupGrades[$group] = [];
          $groupGrades[$group][] = $numeric;
          $groupStats[$group]['grade'][] = $numeric;
        }
      } elseif ($field['field_type'] === 'select' || $field['field_type'] === 'radio') {
        $groupStats[$group]['competency'][$text] = ($groupStats[$group]['competency'][$text] ?? 0) + 1;
      } elseif ($numeric !== null) {
        $performanceValues[] = $numeric;
        $groupStats[$group]['performance'][] = $numeric;
      }
    }

    $gradeAvg = $gradeValues ? (array_sum($gradeValues) / count($gradeValues)) : null;
    $performanceAvg = $performanceValues ? (array_sum($performanceValues) / count($performanceValues)) : null;

    $groupAverages = [];
    foreach ($groupGrades as $group => $vals) {
      if (!$vals) continue;
      $groupAverages[] = [
        'group' => $group,
        'avg' => array_sum($vals) / count($vals),
        'count' => count($vals),
      ];
    }

    $competencyListIds = array_filter(array_keys($competencyLists), 'is_int');
    $listNames = option_list_names($pdo, $competencyListIds);
    foreach ($competencyLists as $key => &$list) {
      if (is_int($key)) $list['name'] = $listNames[$key] ?? ('Liste #' . $key);
      $items = [];
      foreach ($list['items'] as $opt) {
        if (is_array($opt)) {
          $label = (string)($opt['label'] ?? $opt['label_en'] ?? $opt['value'] ?? '');
          $label = trim($label);
          if ($label !== '') $items[] = $label;
        } else {
          $label = trim((string)$opt);
          if ($label !== '') $items[] = $label;
        }
      }
      $list['items'] = $items;
    }
    unset($list);

    $competencyLines = [];
    foreach ($competencyLists as $list) {
      if (!$list['items']) continue;
      $competencyLines[] = ($list['name'] !== '' ? $list['name'] : 'Kompetenzstufen') . ': ' . implode(' > ', $list['items']);
    }
    if (!$competencyLines) $competencyLines[] = 'Keine Kompetenzstufenlisten gefunden.';

    $gradeDistributionTxt = '';
    if ($gradeDistribution) {
      ksort($gradeDistribution, SORT_NATURAL);
      $parts = [];
      foreach ($gradeDistribution as $label => $cnt) {
        $parts[] = $label . ': ' . $cnt;
      }
      $gradeDistributionTxt = implode(', ', $parts);
    }

    $groupLines = [];
    foreach ($groupAverages as $g) {
      $groupLines[] = $g['group'] . ': Ø ' . number_format($g['avg'], 2, ',', '') . ' (n=' . $g['count'] . ')';
    }
    if (!$groupLines) $groupLines[] = 'Keine numerischen Notenwerte für Fachgruppen.';

    $groupContextLines = [];
    foreach ($groupStats as $group => $stats) {
      $parts = [];
      if (!empty($stats['grade'])) {
        $parts[] = 'Noten-Ø ' . number_format(array_sum($stats['grade']) / count($stats['grade']), 2, ',', '') . ' (n=' . count($stats['grade']) . ')';
        $parts[] = 'Notenbereich ' . number_format(min($stats['grade']), 2, ',', '') . '–' . number_format(max($stats['grade']), 2, ',', '');
      }
      if (!empty($stats['performance'])) {
        $parts[] = 'Leistungsschnitt Ø ' . number_format(array_sum($stats['performance']) / count($stats['performance']), 2, ',', '') . ' (n=' . count($stats['performance']) . ')';
        $parts[] = 'Leistungsbereich ' . number_format(min($stats['performance']), 2, ',', '') . '–' . number_format(max($stats['performance']), 2, ',', '');
      }
      if (!empty($stats['competency'])) {
        $labels = [];
        foreach ($stats['competency'] as $label => $cnt) {
          $labels[] = $label . ': ' . $cnt;
        }
        arsort($stats['competency']);
        $topLabel = array_key_first($stats['competency']);
        $topCount = $topLabel !== null ? $stats['competency'][$topLabel] : 0;
        $parts[] = 'Kompetenzverteilung: ' . implode(', ', $labels);
        if ($topLabel !== null) {
          $parts[] = 'Häufigste Kompetenzstufe: ' . $topLabel . ' (n=' . $topCount . ')';
        }
      }
      if ($parts) {
        $groupContextLines[] = 'Bereich ' . $group . ': ' . implode(' | ', $parts);
      }
    }
    if (!$groupContextLines) $groupContextLines[] = 'Keine bereichsspezifischen Werte verfügbar.';

    $studentContextLines = [];
    foreach ($studentSummaries as $sid => $summary) {
      if (empty($summary['groups'])) continue;
      $lines = [];
      foreach ($summary['groups'] as $group => $items) {
        $items = array_slice($items, 0, 20);
        $lines[] = $group . ': ' . implode('; ', $items);
      }
      if ($lines) {
        $studentContextLines[] = 'Schüler #' . ($studentIndex[$sid] ?? $sid) . ":\n- " . implode("\n- ", $lines);
      }
    }

    $contextParts = [];
    $contextParts[] = 'Klassenstufe: ' . ($gradeLevel !== null ? (string)$gradeLevel : '—');
    $contextParts[] = 'Schuljahr: ' . ($schoolYear !== '' ? $schoolYear : '—');
    $contextParts[] = 'Aktive Schüler: ' . $studentCount;
    $contextParts[] = 'Schülerdaten (anonymisiert, pro Schüler gruppiert):' . ($studentContextLines ? "\n- " . implode("\n- ", $studentContextLines) : ' Keine Schülerdaten verfügbar.');
    $contextParts[] = 'Notenfelder (numerisch, Lehrkraft): ' . ($gradeAvg !== null ? ('Notenschnitt Ø ' . number_format($gradeAvg, 2, ',', '') . ' aus ' . count($gradeValues) . ' Werten') : 'Keine numerischen Notenwerte verfügbar.');
    if ($gradeDistributionTxt !== '') $contextParts[] = 'Notenverteilung (alle Notenfelder): ' . $gradeDistributionTxt;
    $contextParts[] = 'Fachgruppen (Noten-Ø): ' . implode(' | ', $groupLines);
    $contextParts[] = 'Leistungsschnitt (sonstige numerische Felder): ' . ($performanceAvg !== null ? ('Ø ' . number_format($performanceAvg, 2, ',', '') . ' aus ' . count($performanceValues) . ' Werten') : 'Keine numerischen Leistungswerte verfügbar.');
    $contextParts[] = "Kompetenzstufen (geordnet niedrig → hoch):\n- " . implode("\n- ", $competencyLines);
    $contextParts[] = "Bereichsspezifische Zusammenfassung:\n- " . implode("\n- ", $groupContextLines);

    $system = "Du bist eine erfahrene Lehrkraft und erstellst eine Klassen-Rückmeldung. Antworte ausschließlich als JSON mit genau diesen Keys:\n"
      . "rueckmeldung_gesamt (string), noten_leistungsschnitt (string), foerdermoeglichkeiten (array), schwerpunkte_faecher (array), bereiche (array).\n"
      . "Keine weiteren Keys. Keine Markdown-Umrahmung.";

    $userPrompt = "Erstelle eine KI-Rückmeldung zur Klasse insgesamt. Nutze ausschließlich die folgenden aggregierten Informationen und erfinde keine Details. "
      . "Keine personenbezogenen Daten oder Hinweise auf einzelne Schüler. "
      . "Gib Fördermöglichkeiten und fachliche Schwerpunkte an (je Fach als kurzer Stichpunkt „Fach: …“). "
      . "Fördermöglichkeiten müssen konkret, beobachtungsnah und umsetzbar sein (Material/Übung, Sozialform, Häufigkeit/Dauer) und immer eine kurze Begründung enthalten, die sich auf die aggregierten Daten bezieht. "
      . "Fachliche Schwerpunkte müssen ebenfalls immer begründet sein (warum dieser Schwerpunkt aus den Daten hervorgeht). "
      . "Erstelle zusätzlich pro Bereich eine ausführliche Rückmeldung und konkrete, begründete Empfehlungen zur weiteren Förderung; nutze dafür die bereichsspezifische Zusammenfassung und die nach Schülern gruppierten Daten. "
      . "Bei jedem Bereich nenne mindestens drei konkrete Förderideen mit kurzer Begründung (z. B. Übungsformate, Methoden, Differenzierung). "
      . "Alle Einträge in foerdermoeglichkeiten und schwerpunkte_faecher müssen reine Strings sein (keine Objekte). "
      . "Wenn Daten fehlen, erwähne das knapp in der Ausgabe.\n\nKONTEXT:\n" . implode("\n", $contextParts);

    $aiCfg = ai_provider_config();
    $messages = [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user', 'content' => $userPrompt],
    ];

    $aiText = ai_chat_completion($messages, $aiCfg);

    $decoded = json_decode((string)$aiText, true);
    if (!is_array($decoded)) {
      if (preg_match('/\{[\s\S]*\}/', (string)$aiText, $m)) {
        $decoded = json_decode($m[0], true);
      }
    }

    $parsed = [
      'rueckmeldung_gesamt' => '',
      'noten_leistungsschnitt' => '',
      'foerdermoeglichkeiten' => [],
      'schwerpunkte_faecher' => [],
      'bereiche' => [],
    ];
    $normalizeList = function($items) {
      if (!is_array($items)) return [];
      $result = [];
      foreach ($items as $item) {
        if (is_string($item)) {
          $text = trim($item);
          if ($text !== '') $result[] = $text;
          continue;
        }
        if (is_array($item)) {
          $parts = [];
          foreach (['fach','titel','name','massnahme','foerderung','idee','begruendung','text'] as $key) {
            if (!empty($item[$key]) && is_string($item[$key])) {
              $parts[] = trim($item[$key]);
            }
          }
          $text = trim(implode(' – ', array_filter($parts, fn($p) => $p !== '')));
          if ($text !== '') $result[] = $text;
        }
      }
      return $result;
    };

    if (is_array($decoded)) {
      $parsed['rueckmeldung_gesamt'] = trim((string)($decoded['rueckmeldung_gesamt'] ?? ''));
      $parsed['noten_leistungsschnitt'] = trim((string)($decoded['noten_leistungsschnitt'] ?? ''));
      foreach (['foerdermoeglichkeiten','schwerpunkte_faecher'] as $k) {
        if (isset($decoded[$k]) && is_array($decoded[$k])) {
          $parsed[$k] = $normalizeList($decoded[$k]);
        }
      }
      if (isset($decoded['bereiche']) && is_array($decoded['bereiche'])) {
        $areas = [];
        foreach ($decoded['bereiche'] as $key => $item) {
          if (is_string($item)) {
            $text = trim($item);
            if ($text === '') continue;
            $areas[] = [
              'bereich' => is_string($key) ? trim($key) : '',
              'rueckmeldung' => $text,
              'foerderung' => '',
            ];
            continue;
          }

          if (!is_array($item)) continue;
          $bereich = trim((string)($item['bereich'] ?? (is_string($key) ? $key : '')));
          $rueckmeldung = trim((string)($item['rueckmeldung'] ?? ''));
          $foerderung = trim((string)($item['foerderung'] ?? ''));
          if ($bereich === '' && $rueckmeldung === '' && $foerderung === '') continue;
          $areas[] = [
            'bereich' => $bereich,
            'rueckmeldung' => $rueckmeldung,
            'foerderung' => $foerderung,
          ];
        }
        $parsed['bereiche'] = $areas;
      }
    } else {
      $parsed['rueckmeldung_gesamt'] = trim((string)$aiText);
    }

    ai_cache_set($cacheKey, $parsed);

    json_out([
      'ok' => true,
      'feedback' => $parsed,
      'meta' => [
        'cached' => false,
        'students' => $studentCount,
        'grade_values' => count($gradeValues),
        'performance_values' => count($performanceValues),
      ],
    ]);
  }

if ($action === 'delegations_save') {
    $classId = (int)($data['class_id'] ?? 0);
    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $schoolYear = class_school_year($pdo, $classId);
    if ($schoolYear === '') $schoolYear = date('Y');
    $periodLabel = normalize_period_label((string)($data['period_label'] ?? 'Standard'));

    $items = $data['delegations'] ?? null;
    if (!is_array($items)) throw new RuntimeException('delegations fehlt.');

    $pdo->beginTransaction();
    try {
      foreach ($items as $it) {
        if (!is_array($it)) continue;
        $gk = trim((string)($it['group_key'] ?? ''));
        if ($gk === '') continue;

        $uid = (int)($it['user_id'] ?? 0);
        $status = (string)($it['status'] ?? 'open');
        $note = (string)($it['note'] ?? '');

        upsert_class_group_delegation($pdo, $classId, $schoolYear, $periodLabel, $gk, $uid, $status, $note, $userId);
      }

      $pdo->commit();
    } catch (Throwable $e2) {
      $pdo->rollBack();
      throw $e2;
    }

    $delegations = load_class_group_delegations($pdo, $classId, $schoolYear, $periodLabel);
    json_out(['ok'=>true, 'delegations'=>array_values($delegations)]);
  }
  
    // Delegated teachers: only update status/note for delegations assigned to them.
  // No user reassignment and no clearing.
  if ($action === 'delegations_mark') {
    $classId = (int)($data['class_id'] ?? 0);
    $periodLabel = trim((string)($data['period_label'] ?? ''));
    $groupKey = trim((string)($data['group_key'] ?? ''));
    $status = trim((string)($data['status'] ?? 'open'));
    $note = (string)($data['note'] ?? '');

    if ($classId <= 0 || $groupKey === '') throw new RuntimeException('Ungültige Parameter.');

    // must have access to class (delegations inbox grants class access)
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Kein Zugriff.');
    }

    // resolve school year
    $stc = $pdo->prepare("SELECT school_year FROM classes WHERE id=? LIMIT 1");
    $stc->execute([$classId]);
    $cRow = $stc->fetch(PDO::FETCH_ASSOC);
    if (!$cRow) throw new RuntimeException('Klasse nicht gefunden.');
    $schoolYear = (string)($cRow['school_year'] ?? '');

    // current delegations
    $delegations = load_class_group_delegations($pdo, $classId, $schoolYear, $periodLabel);
    $cur = $delegations[$groupKey] ?? null;
    if (!$cur || (int)($cur['user_id'] ?? 0) <= 0) {
      throw new RuntimeException('Keine Delegation für diese Gruppe vorhanden.');
    }

    // only delegate themselves (admins can do anything)
    if (($u['role'] ?? '') !== 'admin') {
      if ((int)$cur['user_id'] !== $userId) {
        throw new RuntimeException('Nicht deine Delegation.');
      }
    }

    if ($status !== 'open' && $status !== 'done') $status = 'open';
    $note = trim($note);

    // upsert with same user_id (NO reassignment)
    upsert_class_group_delegation(
      $pdo,
      $classId,
      $schoolYear,
      $periodLabel,
      $groupKey,
      (int)$cur['user_id'],
      $status,
      $note,
      $userId
    );

    $delegations = load_class_group_delegations($pdo, $classId, $schoolYear, $periodLabel);
    json_out(['ok'=>true, 'delegations'=>array_values($delegations)]);
  }

  if ($action === 'save_class') {
    $classId = (int)($data['class_id'] ?? 0);
    $reportId = (int)($data['report_instance_id'] ?? 0);
    $fieldId = (int)($data['template_field_id'] ?? 0);
    if ($classId <= 0 || $reportId <= 0 || $fieldId <= 0) throw new RuntimeException('class_id/report_instance_id/template_field_id fehlt.');

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $tpl = template_for_class($pdo, $classId);
    $templateId = (int)$tpl['id'];
    $schoolYear = class_school_year($pdo, $classId);
    if ($schoolYear === '') $schoolYear = date('Y');

    $st = $pdo->prepare(
      "SELECT id, status, template_id, student_id, school_year, period_label
       FROM report_instances
       WHERE id=? LIMIT 1"
    );
    $st->execute([$reportId]);
    $ri = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ri) throw new RuntimeException('Report nicht gefunden.');

    if ((int)($ri['template_id'] ?? 0) !== $templateId) throw new RuntimeException('Vorlagenkonflikt.');
    if ($ri['student_id'] !== null) throw new RuntimeException('Kein Klassen-Report.');
    if ((string)($ri['school_year'] ?? '') !== $schoolYear) throw new RuntimeException('Schuljahr-Konflikt.');
    $expectedLabel = class_report_period_label($classId);
    if ((string)($ri['period_label'] ?? '') !== $expectedLabel) throw new RuntimeException('Perioden-Konflikt.');

    $status = (string)($ri['status'] ?? 'draft');
    if ($status === 'locked') throw new RuntimeException('Report ist gesperrt.');

    $st = $pdo->prepare(
      "SELECT id, field_name, field_type, is_multiline, meta_json
       FROM template_fields
       WHERE id=? AND template_id=? AND can_teacher_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $meta = meta_read($frow['meta_json'] ?? null);
    $maxLen = pdf_max_len_from_meta($meta);
    if (!is_class_field($meta)) throw new RuntimeException('Dieses Feld ist kein Klassenfeld.');
    if (is_system_bound($meta)) throw new RuntimeException('Dieses Feld wird automatisch befüllt und kann nicht bearbeitet werden.');

    // delegation: if a group is delegated, only that colleague (or admin) may edit it
    $schoolYear = (string)($ri['school_year'] ?? '');
    $periodLabelDeleg = 'Standard';

    $gKey = group_key_from_meta($meta);
    $type = (string)($frow['field_type'] ?? '');
    $isMultiline = (int)($frow['is_multiline'] ?? 0);
    if (!can_user_edit_field($pdo, $u, $classId, $schoolYear, $periodLabelDeleg, $meta, $type, $isMultiline)) {
      throw new RuntimeException('Dieses Feld ist an eine Kollegin/einen Kollegen delegiert und kann von dir nicht bearbeitet werden.');
    }
    $valueTextInput = isset($data['value_text']) ? (string)$data['value_text'] : null;

    $valueJson = null;
    $assigned = delegated_user_for_group($pdo, $classId, $schoolYear, $periodLabelDeleg, $gKey);

    if ($assigned > 0 && is_free_text_field($type, $isMultiline)) {
      $inputText = $valueTextInput !== null ? trim($valueTextInput) : '';
      $inputText = clamp_text_length($inputText, $maxLen) ?? '';
      $isDelegate = ($assigned === $userId) && !user_is_class_teacher($pdo, $userId, $classId);
      $classText = $isDelegate ? '' : $inputText;
      $delegateText = $isDelegate ? $inputText : '';

      $saved = save_free_text_value(
        $pdo,
        $reportId,
        $fieldId,
        $classText,
        $delegateText,
        $assigned,
        $isDelegate,
        $userId
      );
      $valueText = $saved['value_text'];
      $valueJson = $saved['value_json'];
    } else {
      $valueText = $valueTextInput;
      if (in_array($type, ['radio','select','grade'], true)) {
        $valueText = $valueText !== null ? trim($valueText) : '';
        if ($valueText === '') $valueText = null;

        $listId = option_list_id_from_meta($meta);
        if ($listId > 0 && $valueText !== null) {
          $st2 = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
          $st2->execute([$listId, $valueText]);
          $optId = (int)($st2->fetchColumn() ?: 0);
          if ($optId > 0) {
            $valueJson = json_encode(['option_item_id' => $optId], JSON_UNESCAPED_UNICODE);
          }
        }
      } elseif ($type === 'checkbox') {
        $valueText = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
      } else {
        $valueText = $valueText !== null ? trim($valueText) : null;
        if ($valueText === '') $valueText = null;
        if (should_format_date_field($meta, $type)) {
          $valueText = format_date_value_to_iso($valueText);
        }
      }
    }

    if (!($assigned > 0 && is_free_text_field($type, $isMultiline))) {
      $up = $pdo->prepare(
        "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_at)
         VALUES (?, ?, ?, ?, 'teacher', ?, NOW())
         ON DUPLICATE KEY UPDATE
           value_text=VALUES(value_text),
           value_json=VALUES(value_json),
           source='teacher',
           updated_by_user_id=VALUES(updated_by_user_id),
           updated_at=NOW()"
      );
      $up->execute([$reportId, $fieldId, $valueText, $valueJson, $userId]);
    }

    record_field_value_history($pdo, $reportId, $fieldId, $valueText, $valueJson, 'teacher', $userId, null);

    audit('teacher_class_value_save', $userId, ['class_id'=>$classId,'report_instance_id'=>$reportId,'template_field_id'=>$fieldId]);
    json_out(['ok' => true]);
  }

  if ($action === 'save') {
    $reportId = (int)($data['report_instance_id'] ?? 0);
    $fieldId = (int)($data['template_field_id'] ?? 0);
    if ($reportId <= 0 || $fieldId <= 0) throw new RuntimeException('report_instance_id/template_field_id fehlt.');

    $st = $pdo->prepare(
      "SELECT ri.id, ri.status, ri.template_id, ri.school_year, ri.period_label, s.class_id, c.template_id AS class_template_id
       FROM report_instances ri
       INNER JOIN students s ON s.id=ri.student_id
       INNER JOIN classes c ON c.id=s.class_id
       WHERE ri.id=? LIMIT 1"
    );
    $st->execute([$reportId]);
    $ri = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ri) throw new RuntimeException('Report nicht gefunden.');

    $classId = (int)($ri['class_id'] ?? 0);
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $status = (string)($ri['status'] ?? 'draft');
    if ($status === 'locked') throw new RuntimeException('Report ist gesperrt.');

    $riTemplateId = (int)($ri['template_id'] ?? 0);
    $classTemplateId = (int)($ri['class_template_id'] ?? 0);
    if ($classTemplateId <= 0) throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');
    if ($riTemplateId !== $classTemplateId) throw new RuntimeException('Vorlagenkonflikt: Der Bericht gehört zu einer anderen Vorlage als der Klasse zugeordnet ist.');

    $templateId = $riTemplateId;

    $st = $pdo->prepare(
      "SELECT id, field_type, is_multiline, meta_json
       FROM template_fields
       WHERE id=? AND template_id=? AND can_teacher_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $meta = meta_read($frow['meta_json'] ?? null);
    $maxLen = pdf_max_len_from_meta($meta);
    if (is_system_bound($meta)) throw new RuntimeException('Dieses Feld wird automatisch befüllt und kann nicht bearbeitet werden.');

    // ✅ Delegation serverseitig erzwingen
    $schoolYear = (string)($ri['school_year'] ?? '');
    $periodLabel = (string)($ri['period_label'] ?? 'Standard');
    $gKey = group_key_from_meta($meta);
    $type = (string)($frow['field_type'] ?? '');
    $isMultiline = (int)($frow['is_multiline'] ?? 0);
    if (!can_user_edit_field($pdo, $u, $classId, $schoolYear, $periodLabel, $meta, $type, $isMultiline)) {
      throw new RuntimeException('Dieses Feld ist an eine Kollegin/einen Kollegen delegiert und kann von dir nicht bearbeitet werden.');
    }
    $valueTextInput = isset($data['value_text']) ? (string)$data['value_text'] : null;

    // ✅ immer initialisieren (sonst Undefined variable)
    $valueJson = null;
    $assigned = delegated_user_for_group($pdo, $classId, $schoolYear, $periodLabel, $gKey);

    if ($assigned > 0 && is_free_text_field($type, $isMultiline)) {
      $inputText = $valueTextInput !== null ? trim($valueTextInput) : '';
      $inputText = clamp_text_length($inputText, $maxLen) ?? '';
      $isDelegate = ($assigned === $userId) && !user_is_class_teacher($pdo, $userId, $classId);
      $classText = $isDelegate ? '' : $inputText;
      $delegateText = $isDelegate ? $inputText : '';

      $saved = save_free_text_value(
        $pdo,
        $reportId,
        $fieldId,
        $classText,
        $delegateText,
        $assigned,
        $isDelegate,
        $userId
      );
      $valueText = $saved['value_text'];
      $valueJson = $saved['value_json'];
    } else {
      $valueText = $valueTextInput;
      if (in_array($type, ['radio','select','grade'], true)) {
        $valueText = $valueText !== null ? trim($valueText) : '';
        if ($valueText === '') $valueText = null;

        $listId = option_list_id_from_meta($meta);
        if ($listId > 0 && $valueText !== null) {
          $st2 = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
          $st2->execute([$listId, $valueText]);
          $optId = (int)($st2->fetchColumn() ?: 0);
          if ($optId > 0) {
            $valueJson = json_encode(['option_item_id' => $optId], JSON_UNESCAPED_UNICODE);
          }
        }
      } elseif ($type === 'checkbox') {
        $valueText = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
      } else {
        $valueText = $valueText !== null ? trim($valueText) : null;
        if ($valueText === '') $valueText = null;
        if (should_format_date_field($meta, $type)) {
          $valueText = format_date_value_to_iso($valueText);
        }
      }
    }

    if (!($assigned > 0 && is_free_text_field($type, $isMultiline))) {
      $up = $pdo->prepare(
        "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_at)
         VALUES (?, ?, ?, ?, 'teacher', ?, NOW())
         ON DUPLICATE KEY UPDATE
           value_text=VALUES(value_text),
           value_json=VALUES(value_json),
           source='teacher',
           updated_by_user_id=VALUES(updated_by_user_id),
           updated_at=NOW()"
      );
      $up->execute([$reportId, $fieldId, $valueText, $valueJson, $userId]);
    }

    record_field_value_history($pdo, $reportId, $fieldId, $valueText, $valueJson, 'teacher', $userId, null);

    audit('teacher_value_save', $userId, ['report_instance_id'=>$reportId,'template_field_id'=>$fieldId]);
    json_out(['ok' => true]);
  }

  if ($action === 'child_value_update') {
    $reportId = (int)($data['report_instance_id'] ?? 0);
    $fieldId = (int)($data['child_field_id'] ?? 0);
    $deleteValue = array_key_exists('value_text', $data) ? ($data['value_text'] === null) : false;
    $valueText = array_key_exists('value_text', $data) ? (string)$data['value_text'] : null;

    if ($reportId <= 0) throw new RuntimeException('report_instance_id fehlt.');
    if ($fieldId <= 0) throw new RuntimeException('child_field_id fehlt.');

    $st = $pdo->prepare(
      "SELECT ri.template_id AS report_template_id, ri.school_year, ri.period_label, s.class_id, c.template_id AS class_template_id,
              tf.field_type, tf.meta_json
       FROM report_instances ri
       JOIN students s ON s.id=ri.student_id
       JOIN classes c ON c.id=s.class_id
       JOIN template_fields tf ON tf.id=? AND tf.template_id=ri.template_id AND tf.can_child_edit=1
       WHERE ri.id=?
       LIMIT 1"
    );
    $st->execute([$fieldId, $reportId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Schülerfeld nicht gefunden oder nicht freigegeben.');

    $classId = (int)($row['class_id'] ?? 0);
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $reportTplId = (int)($row['report_template_id'] ?? 0);
    $classTplId = (int)($row['class_template_id'] ?? 0);
    if ($reportTplId <= 0 || $classTplId <= 0) throw new RuntimeException('Vorlage nicht gefunden.');
    if ($reportTplId !== $classTplId) throw new RuntimeException('Vorlagenkonflikt: Der Bericht gehört zu einer anderen Vorlage als der Klasse zugeordnet ist.');

    $meta = meta_read($row['meta_json'] ?? null);
    $maxLen = pdf_max_len_from_meta($meta);
    if (is_system_bound($meta)) throw new RuntimeException('Dieses Feld wird automatisch befüllt und kann nicht bearbeitet werden.');

    $type = (string)($row['field_type'] ?? '');
    $valueJson = null;

    if ($deleteValue) {
      $valueText = null;
    } elseif (in_array($type, ['radio','select','grade'], true)) {
      $valueText = $valueText !== null ? trim($valueText) : '';
      if ($valueText === '') $valueText = null;

      $listId = option_list_id_from_meta($meta);
      if ($listId > 0 && $valueText !== null) {
        $st2 = $pdo->prepare("SELECT id FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
        $st2->execute([$listId, $valueText]);
        $optId = (int)($st2->fetchColumn() ?: 0);
        if ($optId > 0) {
          $valueJson = json_encode(['option_item_id' => $optId], JSON_UNESCAPED_UNICODE);
        }
      }
    } elseif ($type === 'checkbox') {
      $valueText = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
    } else {
      $valueText = $valueText !== null ? trim($valueText) : null;
      if ($valueText === '') {
        $valueText = null;
      } else {
        $valueText = clamp_text_length($valueText, $maxLen);
      }
    }

    $up = $pdo->prepare(
      "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_at)
       VALUES (?, ?, ?, ?, 'child', ?, NOW())
       ON DUPLICATE KEY UPDATE
         value_text=VALUES(value_text),
         value_json=VALUES(value_json),
         source='child',
         updated_by_user_id=VALUES(updated_by_user_id),
         updated_by_student_id=NULL,
         updated_at=NOW()"
    );
    $up->execute([$reportId, $fieldId, $valueText, $valueJson, $userId]);

    record_field_value_history($pdo, $reportId, $fieldId, $valueText, $valueJson, 'child', $userId, null);

    $resolved = resolve_option_value_text($pdo, $meta, $valueJson, $valueText, $lang);
    audit('teacher_child_value_update', $userId, ['report_instance_id'=>$reportId,'template_field_id'=>$fieldId]);

    json_out([
      'ok' => true,
      'value_text' => $resolved['text'] !== null ? (string)$resolved['text'] : '',
      'value_json' => $resolved['json'] ?? $valueJson,
      'raw_value_text' => $valueText,
    ]);
  }

  if ($action === 'unlock_child_entry') {
    $reportId = (int)($data['report_instance_id'] ?? 0);
    if ($reportId <= 0) throw new RuntimeException('report_instance_id fehlt.');

    $st = $pdo->prepare(
      "SELECT ri.id, ri.status, ri.template_id, ri.school_year, ri.period_label, s.class_id, c.template_id AS class_template_id
       FROM report_instances ri
       INNER JOIN students s ON s.id=ri.student_id
       INNER JOIN classes c ON c.id=s.class_id
       WHERE ri.id=?
       LIMIT 1"
    );
    $st->execute([$reportId]);
    $ri = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ri) throw new RuntimeException('Report nicht gefunden.');

    $classId = (int)($ri['class_id'] ?? 0);
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $status = (string)($ri['status'] ?? 'draft');
    if ($status === 'draft') {
      json_out(['ok' => true, 'status' => 'draft', 'changed' => false]);
    }

    $riTemplateId = (int)($ri['template_id'] ?? 0);
    $classTemplateId = (int)($ri['class_template_id'] ?? 0);
    if ($classTemplateId <= 0) throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');
    if ($riTemplateId !== $classTemplateId) throw new RuntimeException('Vorlagenkonflikt: Der Bericht gehört zu einer anderen Vorlage als der Klasse zugeordnet ist.');

    $pdo->prepare(
      "UPDATE report_instances
       SET status='draft', locked_by_user_id=NULL, locked_at=NULL
       WHERE id=?"
    )->execute([$reportId]);

    audit('teacher_child_unlock', $userId, ['report_instance_id'=>$reportId, 'class_id'=>$classId]);
    json_out(['ok' => true, 'status' => 'draft', 'changed' => true]);
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
