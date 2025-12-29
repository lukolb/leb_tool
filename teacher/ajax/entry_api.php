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

function option_list_id_from_meta(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function load_option_list_items(PDO $pdo, int $listId): array {
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
  return $out;
}

function resolve_option_value_text(PDO $pdo, array $meta, ?string $valueJsonRaw, ?string $valueTextRaw): array {
  $out = ['text' => $valueTextRaw, 'json' => $valueJsonRaw];
  $listId = option_list_id_from_meta($meta);
  if ($listId <= 0) return $out;

  $vj = null;
  if ($valueJsonRaw) {
    $tmp = json_decode($valueJsonRaw, true);
    if (is_array($tmp)) $vj = $tmp;
  }

  $optId = is_array($vj) && isset($vj['option_item_id']) ? (int)$vj['option_item_id'] : 0;
  if ($optId > 0) {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$optId, $listId]);
    $rowVal = $st->fetchColumn();
    if ($rowVal !== false && $rowVal !== null) {
      $out['text'] = (string)$rowVal;
      return $out;
    }
  }

  $vt = $valueTextRaw !== null ? trim((string)$valueTextRaw) : '';
  if ($vt !== '') {
    $st = $pdo->prepare("SELECT id, value FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $vt]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $out['json'] = json_encode(['option_item_id' => (int)$row['id']], JSON_UNESCAPED_UNICODE);
      $out['text'] = (string)($row['value'] ?? $vt);
      return $out;
    }
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
    'max_tokens' => 320,
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

function can_user_edit_group(PDO $pdo, array $currentUser, int $classId, string $schoolYear, string $periodLabel, string $groupKey): bool {
  if (($currentUser['role'] ?? '') === 'admin') return true;
  $uid = (int)($currentUser['id'] ?? 0);
  if ($uid <= 0) return false;
  $assigned = delegated_user_for_group($pdo, $classId, $schoolYear, $periodLabel, $groupKey);
  if ($assigned <= 0) return true;        // not delegated => anyone with class access may edit
  return $assigned === $uid;              // delegated => only that teacher
}

function base_field_key(string $fieldName): string {
  $s = strtolower(trim($fieldName));
  $s = explode('-', $s, 2)[0];
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  return trim($s);
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
  $st = $pdo->prepare(
    "SELECT id, status
     FROM report_instances
     WHERE template_id=? AND student_id=0 AND school_year=? AND period_label='__class__'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return (int)$row['id'];

  $pdo->prepare(
    "INSERT INTO report_instances (template_id, student_id, period_label, school_year, status, created_by_user_id, created_at, updated_at)
     VALUES (?, 0, '__class__', ?, 'draft', NULL, NOW(), NOW())"
  )->execute([$templateId, $schoolYear]);

  return (int)$pdo->lastInsertId();
}

function find_or_create_report_instance_for_student(PDO $pdo, int $templateId, int $studentId, string $schoolYear): array {
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
    "INSERT INTO report_instances (template_id, student_id, period_label, school_year, status, created_by_user_id, created_at, updated_at)
     VALUES (?, ?, 'Standard', ?, 'draft', NULL, NOW(), NOW())"
  )->execute([$templateId, $studentId, $schoolYear]);

  $rid = (int)$pdo->lastInsertId();
  return ['id' => $rid, 'status' => 'draft'];
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
    "SELECT id, field_name, field_type, options_json, meta_json
     FROM template_fields
     WHERE template_id=? AND can_child_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_values(PDO $pdo, array $reportIds, array $fieldIds, string $source): array {
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
      $r['value_text'] !== null ? (string)$r['value_text'] : null
    );
    $out[$rid][$fid] = $res['text'] !== null ? (string)$res['text'] : '';
  }
  return $out;
}

function load_value_history(PDO $pdo, array $reportIds, array $fieldIds, array $fieldMetaById, int $limit = 5): array {
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
      $r['value_text'] !== null ? (string)$r['value_text'] : null
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
      $ri = find_or_create_report_instance_for_student($pdo, $templateId, $sid, $schoolYear);
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
        if (!isset($optCache[$listId])) $optCache[$listId] = load_option_list_items($pdo, $listId);
        $optsChild = $optCache[$listId];
      } else {
        $optsChild = decode_options($cf['options_json'] ?? null);
      }
      $childByBase[$base] = [
        'id' => (int)$cf['id'],
        'field_type' => (string)($cf['field_type'] ?? ''),
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

    // load values for editable class fields
    $classValuesById = [];
    if ($classReportInstanceId > 0 && $classFieldIdsEditable) {
      $classValuesById = load_values($pdo, [$classReportInstanceId], $classFieldIdsEditable, 'teacher');
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
        if (!isset($optCache[$listIdT])) $optCache[$listIdT] = load_option_list_items($pdo, $listIdT);
        $optsTeacher = $optCache[$listIdT];
      } else {
        $optsTeacher = decode_options($f0['options_json'] ?? null);
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
        if (!isset($optCache[$listIdF])) $optCache[$listIdF] = load_option_list_items($pdo, $listIdF);
        $optsTeacher = $optCache[$listIdF];
      } else {
        $optsTeacher = decode_options($f['options_json'] ?? null);
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

      $base = base_field_key((string)$f['field_name']);
      $child = ($base !== '' && isset($childByBase[$base])) ? $childByBase[$base] : null;

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
        'child' => $child ? [
          'id' => (int)$child['id'],
          'field_type' => (string)$child['field_type'],
          'options' => $child['options'],
        ] : null,
      ];
    }

    $groupsList = array_values($groups);

    // --- delegations (per class/school_year/period_label + group) ---
    $periodLabel = 'Standard';
    $delegations = load_class_group_delegations($pdo, $classId, $schoolYear, $periodLabel);
    $delegationUsers = load_teachers_for_delegation($pdo);

    // annotate groups with permissions + delegation meta for UI
    $groupsList2 = [];
    foreach ($groupsList as $g0) {
      $gk = (string)($g0['key'] ?? '');
      $del = $gk !== '' && isset($delegations[$gk]) ? $delegations[$gk] : null;
      $canEditGroup = $gk !== '' ? can_user_edit_group($pdo, $u, $classId, $schoolYear, $periodLabel, $gk) : true;
      $g0['delegation'] = $del;
      $g0['can_edit'] = $canEditGroup ? 1 : 0;
      $groupsList2[] = $g0;
    }
    $groupsList = $groupsList2;

    // values
    $teacherFieldIds = array_map(fn($x)=>(int)$x['id'], $teacherFields);
    $childFieldIds = array_values(array_unique(array_filter(array_map(
      fn($x)=> (int)($x['id'] ?? 0),
      array_values($childByBase)
    ), fn($x)=>$x>0)));

    $valuesTeacher = load_values($pdo, $reportIds, $teacherFieldIds, 'teacher');
    $valuesChild = load_values($pdo, $reportIds, $childFieldIds, 'child');

    // --- progress (teacher / child / overall) ---
    $teacherProgressIds = [];
    foreach ($teacherFields as $f0) {
      $m0 = meta_read($f0['meta_json'] ?? null);
      if (is_system_bound($m0)) continue;
      if (is_class_field($m0)) continue;
      $teacherProgressIds[] = (int)$f0['id'];
    }

    $childProgressIds = [];
    // load ALL child-editable fields for progress counting (not only paired)
    $childFieldsAll = load_child_fields_for_pairing($pdo, $templateId);
    foreach ($childFieldsAll as $cf0) {
      $m0 = meta_read($cf0['meta_json'] ?? null);
      if (is_system_bound($m0)) continue;
      if (is_class_field($m0)) continue;
      $childProgressIds[] = (int)$cf0['id'];
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
    $valueHistory = load_value_history($pdo, $reportIds, $historyFieldIds, $fieldMetaById, 5);

    $valuesChildAllForProgress = load_values($pdo, $reportIds, $childProgressIds, 'child');

    $teacherTotal = count($teacherProgressIds);
    $childTotal = count($childProgressIds);
    $overallTotal = $teacherTotal + $childTotal;

    $completeForms = 0;
    foreach ($students as &$srow) {
      $rid = (int)($srow['report_instance_id'] ?? 0);
      $ridKey = (string)$rid;

      $tDone = 0;
      if ($teacherTotal > 0) {
        foreach ($teacherProgressIds as $fid) {
          $v = $valuesTeacher[$ridKey][(string)$fid] ?? '';
          if (trim((string)$v) !== '') $tDone++;
        }
      }

      $cDone = 0;
      if ($childTotal > 0) {
        foreach ($childProgressIds as $fid) {
          $v = $valuesChildAllForProgress[$ridKey][(string)$fid] ?? '';
          if (trim((string)$v) !== '') $cDone++;
        }
      }

      $oDone = $tDone + $cDone;
      $oMissing = max(0, $overallTotal - $oDone);
      $isComplete = ($overallTotal > 0 && $oMissing === 0);
      if ($isComplete) $completeForms++;

      $srow['progress_teacher_total'] = $teacherTotal;
      $srow['progress_teacher_done'] = $tDone;
      $srow['progress_teacher_missing'] = max(0, $teacherTotal - $tDone);

      $srow['progress_child_total'] = $childTotal;
      $srow['progress_child_done'] = $cDone;
      $srow['progress_child_missing'] = max(0, $childTotal - $cDone);

      $srow['progress_overall_total'] = $overallTotal;
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
      'values_child' => $valuesChild,
      'value_history' => $valueHistory,
      'progress_summary' => $progressSummary,
      'class_report_instance_id' => $classReportInstanceId,
      'class_fields' => [
        // ✅ IMPORTANT: only editable class fields
        'field_ids' => $classFieldIdsEditable,
        'fields' => $classFieldsDefs,
        'values' => $classValuesById,
        'value_by_name' => $classValueByName,
      ],
      'ai_enabled' => ai_provider_enabled(),
      'class_grade_level' => $classGradeLevel !== false ? $classGradeLevel : null,
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
      "SELECT ri.template_id, ri.student_id, ri.school_year, s.first_name, s.last_name, s.class_id, c.grade_level
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
    $studentName = trim((string)$info['first_name'] . ' ' . (string)$info['last_name']);
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

    $optionFacts = [];
    $gradeFacts = [];
    $childInputs = [];
    $comparisons = [];

    $missingOptionFields = [];
    foreach ($ctxFields as $cf) {
      $meta = meta_read($cf['meta_json'] ?? null);
      $resolvedTeacher = resolve_option_value_text($pdo, $meta, $cf['teacher_value_json'] ?? null, $cf['teacher_value'] ?? '');
      $resolvedChild = resolve_option_value_text($pdo, $meta, $cf['child_value_json'] ?? null, $cf['child_value'] ?? '');

      $val = trim((string)($resolvedTeacher['text'] ?? ($cf['teacher_value'] ?? '')));
      $childVal = trim((string)($resolvedChild['text'] ?? ($cf['child_value'] ?? '')));
      $label = label_for_lang($cf['label'] ?? null, $cf['label_en'] ?? null, $lang);

      $type = (string)($cf['field_type'] ?? '');
      $hasOptionList = option_list_id_from_meta($meta) > 0;
      if (in_array($type, ['radio','select','grade'], true) && $hasOptionList && $val === '') {
        $missingOptionFields[] = $label !== '' ? $label : (string)($cf['field_name'] ?? '');
      }
      if (in_array($type, ['radio','select'], true) && $hasOptionList && $val !== '') {
        $optionFacts[] = ($label ? ($label . ': ') : '') . $val;
      } elseif ($type === 'grade' && $val !== '') {
        $gradeFacts[] = ($label ? ($label . ': ') : '') . $val;
      }

      if ($childVal !== '' && ($type === 'multiline' || $type === 'text' || (int)($meta['is_child_text'] ?? 0) === 1)) {
        $childInputs[] = ($label ? ($label . ': ') : '') . $childVal;
      }

      if ($val !== '' && $childVal !== '') {
        if (strcasecmp($val, $childVal) === 0) {
          $comparisons[] = ($label ? ($label . ': ') : '') . 'Übereinstimmung (Lehrer & Schüler): ' . $val;
        } else {
          $comparisons[] = ($label ? ($label . ': ') : '') . 'Lehrer: ' . $val . ' · Schüler: ' . $childVal;
        }
      }
    }

    if ($missingOptionFields) {
      $msg = 'Bitte zuerst alle Options-Felder ausfüllen. Offen: ' . implode(', ', array_slice($missingOptionFields, 0, 5));
      if (count($missingOptionFields) > 5) $msg .= ' …';
      throw new RuntimeException($msg);
    }

    $aiCfg = ai_provider_config();

    $ctxParts = [];
    $ctxParts[] = 'Schüler: ' . $studentName;
    if ($gradeLevel !== null) $ctxParts[] = 'Klassenstufe: ' . $gradeLevel;
    if ($optionFacts) $ctxParts[] = 'Wichtige Beobachtungen (Optionen): ' . implode('; ', array_slice($optionFacts, 0, 6));
    if ($gradeFacts) $ctxParts[] = 'Noten (zweitrangig): ' . implode('; ', array_slice($gradeFacts, 0, 3));
    if ($childInputs) $ctxParts[] = 'Rückmeldungen der Schüler: ' . implode(' | ', array_slice($childInputs, 0, 2));
    if ($comparisons) $ctxParts[] = 'Abgleich Lehrer/Schüler: ' . implode(' | ', array_slice($comparisons, 0, 4));

    $userPrompt = trim(implode("\n", array_filter($ctxParts)));

    $messages = [
      [
        'role' => 'system',
        'content' => 'Du bist eine Lehrhilfe und erstellst kurze deutsche Vorschläge mit Fokus auf Stärken, konkrete Ziele und praktikable Schritte. Schreibe altersgerechte Formulierungen, orientiert an der Klassenstufe. Nutze vor allem die übergebenen Options-Bewertungen als Grundlage und beziehe Übereinstimmungen bzw. Unterschiede zwischen Lehrer- und Schülerangaben mit ein. Kein Intro, keine Nummerierung.',
      ],
      [
        'role' => 'user',
        'content' => $userPrompt . "\n\nGib JSON im Format {\"strengths\":[],\"goals\":[],\"steps\":[]} zurück, jeweils mit 3 kurzen Einträgen (max. 2 Sätze). Stärken sind wertschätzende Beobachtungen, Ziele beschreiben den nächsten Lernschritt und Schritte zeigen konkrete Möglichkeiten, das Ziel zu erreichen.",
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
    if ((int)($ri['student_id'] ?? 0) !== 0) throw new RuntimeException('Kein Klassen-Report.');
    if ((string)($ri['school_year'] ?? '') !== $schoolYear) throw new RuntimeException('Schuljahr-Konflikt.');
    if ((string)($ri['period_label'] ?? '') !== '__class__') throw new RuntimeException('Perioden-Konflikt.');

    $status = (string)($ri['status'] ?? 'draft');
    if ($status === 'locked') throw new RuntimeException('Report ist gesperrt.');

    $st = $pdo->prepare(
      "SELECT id, field_name, field_type, meta_json
       FROM template_fields
       WHERE id=? AND template_id=? AND can_teacher_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $meta = meta_read($frow['meta_json'] ?? null);
    if (!is_class_field($meta)) throw new RuntimeException('Dieses Feld ist kein Klassenfeld.');
    if (is_system_bound($meta)) throw new RuntimeException('Dieses Feld wird automatisch befüllt und kann nicht bearbeitet werden.');

    // delegation: if a group is delegated, only that colleague (or admin) may edit it
    $schoolYear = (string)($ri['school_year'] ?? '');
    $periodLabelDeleg = 'Standard';

    $gKey = group_key_from_meta($meta);
    if (!can_user_edit_group($pdo, $u, $classId, $schoolYear, $periodLabelDeleg, $gKey)) {
        throw new RuntimeException('Dieses Feld ist an eine Kollegin/einen Kollegen delegiert und kann von dir nicht bearbeitet werden.');
    }

    $type = (string)$frow['field_type'];
    $valueText = isset($data['value_text']) ? (string)$data['value_text'] : null;

    $valueJson = null;

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
    }

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
      "SELECT id, field_type, meta_json
       FROM template_fields
       WHERE id=? AND template_id=? AND can_teacher_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $meta = meta_read($frow['meta_json'] ?? null);
    if (is_system_bound($meta)) throw new RuntimeException('Dieses Feld wird automatisch befüllt und kann nicht bearbeitet werden.');

    // ✅ Delegation serverseitig erzwingen
    $schoolYear = (string)($ri['school_year'] ?? '');
    $periodLabel = (string)($ri['period_label'] ?? 'Standard');
    $gKey = group_key_from_meta($meta);
    if (!can_user_edit_group($pdo, $u, $classId, $schoolYear, $periodLabel, $gKey)) {
      throw new RuntimeException('Dieses Feld ist an eine Kollegin/einen Kollegen delegiert und kann von dir nicht bearbeitet werden.');
    }

    $type = (string)$frow['field_type'];
    $valueText = isset($data['value_text']) ? (string)$data['value_text'] : null;

    // ✅ immer initialisieren (sonst Undefined variable)
    $valueJson = null;

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
    }

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

    record_field_value_history($pdo, $reportId, $fieldId, $valueText, $valueJson, 'teacher', $userId, null);

    audit('teacher_value_save', $userId, ['report_instance_id'=>$reportId,'template_field_id'=>$fieldId]);
    json_out(['ok' => true]);
  }

  throw new RuntimeException('Unbekannte action.');

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
