<?php
// student/ajax/wizard_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../shared/group_keys.php';
require __DIR__ . '/../../shared/value_history.php';
require_student();

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

/**
 * Option-list templates: keep selections stable even if the *value* changes.
 * We store/resolve by option_list_items.id (option_item_id) and derive the current value from that.
 */
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
  // Returns ['text'=>?, 'json'=>?] with text resolved to the CURRENT option_list_items.value (if possible).
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
    $st = $pdo->prepare("SELECT id, value FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$optId, $listId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $out['text'] = (string)($row['value'] ?? '');
      return $out;
    }
  }

  // Backward compatibility: try to map by old value_text.
  $vt = $valueTextRaw !== null ? trim((string)$valueTextRaw) : '';
  if ($vt !== '') {
    $st = $pdo->prepare("SELECT id, value FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $vt]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $new = ['option_item_id' => (int)$row['id']];
      $out['json'] = json_encode($new, JSON_UNESCAPED_UNICODE);
      $out['text'] = (string)($row['value'] ?? $vt);
      return $out;
    }
  }

  return $out;
}

function meta_first_string(array $meta, array $keys): string {
  return app_group_meta_first_string($meta, $keys);
}

function meta_rcff_first_string(array $meta, array $keys): string {
  return app_group_meta_rcff_first_string($meta, $keys);
}

function group_parts_from_meta(array $meta): array {
  return app_group_parts_from_meta($meta);
}

function subgroup_title_en_from_meta(array $meta): string {
  $title = app_group_meta_first_string($meta, ['subgroup_title_en', 'subcategory_en', 'subgroup_label_en']);
  if ($title !== '') return $title;
  return app_group_meta_rcff_first_string($meta, ['subcategory_en']);
}

function group_key_from_meta(array $meta): string {
  return app_group_key_from_meta($meta);
}

function group_key_aliases_from_meta(array $meta): array {
  return app_group_key_aliases_from_meta($meta);
}

function group_key_unlocked(array $unlockMap, array $aliases): bool {
  return app_group_key_matches_map($unlockMap, $aliases);
}

function base_field_key(string $fieldName): string {
  $s = strtolower(trim($fieldName));
  $s = explode('-', $s, 2)[0];
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  return trim($s);
}

function student_wizard_display_mode_from_class(array $classRow): string {
  $mode = (string)($classRow['student_wizard_display'] ?? 'groups');
  $mode = strtolower(trim($mode));
  return in_array($mode, ['groups','items','beginner'], true) ? $mode : 'groups';
}

function ai_provider_enabled(): bool {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];
  $enabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
  $studentEnabled = array_key_exists('student_enabled', $ai) ? (bool)$ai['student_enabled'] : $enabled;
  if (!$enabled || !$studentEnabled) return false;
  $apiKey = (string)($ai['api_key'] ?? getenv('OPENAI_API_KEY') ?: '');
  return trim($apiKey) !== '';
}

function label_for_lang(?string $labelDe, ?string $labelEn, string $lang, string $fallback=''): string {
  $de = trim((string)$labelDe);
  $en = trim((string)$labelEn);
  if ($lang === 'en' && $en !== '') return $en;
  if ($de !== '') return $de;
  return $fallback;
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
    $t = meta_first_string($meta, ['group_title_en', 'category_en']);
    if ($t === '') $t = meta_rcff_first_string($meta, ['category_en']);
    if ($t !== '') return $t;
  }
  return group_title_override_lang($groupKey, $lang);
}

function load_child_group_unlocks(PDO $pdo, int $classId, string $schoolYear, string $periodLabel): array {
  if ($classId <= 0 || $schoolYear === '') return ['active' => false, 'map' => []];
  $st = $pdo->prepare(
    "SELECT group_key, is_unlocked
     FROM class_child_group_unlocks
     WHERE class_id=? AND school_year=? AND period_label=?"
  );
  $st->execute([$classId, $schoolYear, $periodLabel]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return ['active' => false, 'map' => []];
  $map = [];
  foreach ($rows as $r) {
    $gk = trim((string)($r['group_key'] ?? ''));
    if ($gk === '') continue;
    $map[$gk] = ((int)($r['is_unlocked'] ?? 0) === 1);
  }
  return ['active' => true, 'map' => $map];
}

function get_student_and_class(PDO $pdo, int $studentId): array {
  $st = $pdo->prepare(
    "SELECT s.id, s.first_name, s.last_name, s.class_id,
            c.school_year, c.grade_level, c.label, c.name AS class_name,
            c.template_id AS class_template_id,
            c.student_wizard_display AS student_wizard_display,
            c.student_intro_html AS student_intro_html
     FROM students s
     LEFT JOIN classes c ON c.id=s.class_id
     WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException(t('student.wizard.error.student_not_found'));
  return $row;
}

function class_display(array $row): string {
  $label = (string)($row['label'] ?? '');
  $grade = isset($row['grade_level']) ? (int)$row['grade_level'] : null;
  if ($grade !== null && $label !== '') return (string)$grade . $label;
  $name = (string)($row['class_name'] ?? '');
  return $name !== '' ? $name : '—';
}

function child_intro_file_abs(): string {
  $cfg = app_config();
  $uploadsRel = (string)($cfg['app']['uploads_dir'] ?? 'uploads');
  $rootAbs = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
  return rtrim($rootAbs, '/\\') . '/' . trim($uploadsRel, '/\\') . '/child_intro.html';
}

function sanitize_intro_html(string $html): string {
  $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
  return trim($html);
}

function render_intro_placeholders(string $html, array $studentRow): string {
  $cfg = app_config();
  $brand = $cfg['app']['brand'] ?? [];
  $orgName = (string)($brand['org_name'] ?? 'LEB Tool');

  $first = (string)($studentRow['first_name'] ?? '');
  $last  = (string)($studentRow['last_name'] ?? '');
  $studentName = trim($first . ' ' . $last);
  $class = class_display($studentRow);
  $schoolYear = (string)($studentRow['school_year'] ?? '');

  $rep = [
    '{{org_name}}'     => htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8'),
    '{{student_name}}' => htmlspecialchars($studentName !== '' ? $studentName : $first, ENT_QUOTES, 'UTF-8'),
    '{{first_name}}'   => htmlspecialchars($first, ENT_QUOTES, 'UTF-8'),
    '{{last_name}}'    => htmlspecialchars($last, ENT_QUOTES, 'UTF-8'),
    '{{class}}'        => htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
    '{{school_year}}'  => htmlspecialchars($schoolYear, ENT_QUOTES, 'UTF-8'),
  ];

  return str_replace(array_keys($rep), array_values($rep), $html);
}

/**
 * IMPORTANT: template is assigned per class (classes.template_id).
 * If no template is assigned -> student cannot proceed.
 */
function template_for_student(PDO $pdo, int $studentId): array {
  $st = $pdo->prepare(
    "SELECT c.id AS class_id, c.template_id, c.period_label, t.id AS tid, t.name, t.template_version, t.is_active
     FROM students s
     INNER JOIN classes c ON c.id=s.class_id
     LEFT JOIN templates t ON t.id=c.template_id
     WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException(t('student.wizard.error.student_not_found'));

  $tid = (int)($row['tid'] ?? 0);
  if ($tid <= 0) {
    throw new RuntimeException(t('student.wizard.error.no_template'));
  }
  if ((int)($row['is_active'] ?? 0) !== 1) {
    throw new RuntimeException(t('student.wizard.error.template_inactive'));
  }

  return [
    'id' => $tid,
    'name' => (string)($row['name'] ?? ''),
    'template_version' => (int)($row['template_version'] ?? 0),
    'period_label' => normalize_class_period_label($row['period_label'] ?? 'Standard'),
  ];
}

function find_or_create_class_report_instance(PDO $pdo, int $templateId, int $classId, string $schoolYear, string $periodLabel): int {
  $periodLabel = normalize_class_period_label($periodLabel);
  $periodLabel = class_report_period_label($classId, $periodLabel);
  $st = $pdo->prepare(
    "SELECT id
     FROM report_instances
     WHERE template_id=? AND student_id IS NULL AND school_year=? AND period_label=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear, $periodLabel]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id > 0) return $id;

  return -1;
}

function load_class_lookup(PDO $pdo, int $templateId, int $classReportId): array {
  // NOTE: include label_en so label_for_lang can work
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.label_en, tf.help_text, tf.field_type,
            fv.value_text
     FROM template_fields tf
     LEFT JOIN field_values fv
       ON fv.template_field_id=tf.id AND fv.report_instance_id=?
     WHERE tf.template_id=?
     ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$classReportId, $templateId]);
  $out = [];
  $lang = ui_lang();
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)($r['field_name'] ?? '');
    if ($name === '') continue;
    $out[$name] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => $name,
      'type' => (string)($r['field_type'] ?? 'text'),
      'label' => label_for_lang($r['label'] ?? null, $r['label_en'] ?? null, $lang, $name),
      'help' => (string)($r['help_text'] ?? ''),
      'value' => (string)($r['value_text'] ?? ''),
    ];
  }
  return $out;
}

function find_or_create_report_instance(PDO $pdo, int $studentId, int $templateId, string $schoolYear, string $periodLabel): array {
  $periodLabel = normalize_class_period_label($periodLabel);
  $st = $pdo->prepare(
    "SELECT id, status
     FROM report_instances
     WHERE template_id=? AND student_id=? AND school_year=? AND period_label=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $studentId, $schoolYear, $periodLabel]);
  $ri = $st->fetch(PDO::FETCH_ASSOC);

  if ($ri) {
    return [
      'report_instance_id' => (int)$ri['id'],
      'status' => (string)$ri['status'],
    ];
  }

  return [
      'report_instance_id' => -1,
      'status' => 'locked',
    ];
}

function ensure_editable_or_throw(PDO $pdo, int $reportId): void {
  $st = $pdo->prepare("SELECT status FROM report_instances WHERE id=? LIMIT 1");
  $st->execute([$reportId]);
  $status = (string)($st->fetchColumn() ?: '');
  if ($status !== 'draft') throw new RuntimeException(t('student.wizard.error.already_submitted'));
}

function get_report_status(PDO $pdo, int $reportId): string {
  $st = $pdo->prepare("SELECT status FROM report_instances WHERE id=? LIMIT 1");
  $st->execute([$reportId]);
  $s = (string)($st->fetchColumn() ?: '');
  return $s !== '' ? $s : 'draft';
}

function load_all_fields_lookup(PDO $pdo, int $templateId, int $reportId): array {
  // Lookup for ALL template fields (child + teacher), keyed by field_name
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.label_en, tf.help_text, tf.field_type,
            fv.value_text
     FROM template_fields tf
     LEFT JOIN field_values fv
       ON fv.template_field_id=tf.id AND fv.report_instance_id=?
     WHERE tf.template_id=?
     ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$reportId, $templateId]);
  $out = [];
  $lang = ui_lang();
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)($r['field_name'] ?? '');
    if ($name === '') continue;
    $out[$name] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => $name,
      'type' => (string)($r['field_type'] ?? 'text'),
      'label' => label_for_lang($r['label'] ?? null, $r['label_en'] ?? null, $lang, $name),
      'help' => (string)($r['help_text'] ?? ''),
      'value' => (string)($r['value_text'] ?? ''),
    ];
  }
  return $out;
}

function load_child_fields(PDO $pdo, int $templateId): array {
  $select = 'id, field_name, field_type, label, label_en, help_text, is_multiline, options_json, meta_json, sort_order';
  foreach (['group_label', 'group_label_en', 'subgroup_label', 'subgroup_label_en'] as $column) {
    if (db_has_column($pdo, 'template_fields', $column)) $select .= ', ' . $column;
  }
  $st = $pdo->prepare(
    "SELECT $select
     FROM template_fields
     WHERE template_id=? AND can_child_edit=1
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_teacher_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, meta_json
     FROM template_fields
     WHERE template_id=? AND can_teacher_edit=1"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_child_values(PDO $pdo, int $reportInstanceId): array {
  $st = $pdo->prepare(
    "SELECT template_field_id, value_text, value_json
     FROM field_values
     WHERE report_instance_id=? AND source='child'"
  );
  $st->execute([$reportInstanceId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $fid = (int)$r['template_field_id'];
    $out[$fid] = [
      'text' => $r['value_text'] !== null ? (string)$r['value_text'] : null,
      'json' => $r['value_json'] !== null ? $r['value_json'] : null,
    ];
  }
  return $out;
}

function load_teacher_values(PDO $pdo, int $reportInstanceId, array $fieldIds): array {
  $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), fn($x)=>$x>0)));
  if (!$fieldIds) return [];
  $in = implode(',', array_fill(0, count($fieldIds), '?'));
  $params = array_merge([$reportInstanceId], $fieldIds);
  $st = $pdo->prepare(
    "SELECT template_field_id, value_text, value_json
     FROM field_values
     WHERE report_instance_id=? AND source='teacher' AND template_field_id IN ($in)"
  );
  $st->execute($params);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $fid = (int)$r['template_field_id'];
    $out[$fid] = [
      'text' => $r['value_text'] !== null ? (string)$r['value_text'] : null,
      'json' => $r['value_json'] !== null ? $r['value_json'] : null,
    ];
  }
  return $out;
}

function resolve_icon_urls(PDO $pdo, array $iconIds): array {
  $iconIds = array_values(array_unique(array_filter(array_map('intval', $iconIds), fn($x)=>$x>0)));
  if (!$iconIds) return [];
  $in = implode(',', array_fill(0, count($iconIds), '?'));
  $st = $pdo->prepare("SELECT id, storage_path FROM icon_library WHERE id IN ($in)");
  $st->execute($iconIds);
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $map[(int)$r['id']] = url((string)$r['storage_path']);
  }
  return $map;
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

function child_field_locked_by_teacher(PDO $pdo, array $childField, array &$context): bool {
  $base = base_field_key((string)($childField['field_name'] ?? ''));
  if ($base === '') return false;
  $teacherField = $context['teacher_by_base'][$base] ?? null;
  if (!$teacherField) return false;
  $teacherValue = $context['teacher_values'][(int)($teacherField['id'] ?? 0)] ?? null;
  return teacher_value_locks_child($pdo, $teacherField, $teacherValue, $context['option_cache']);
}

function child_lock_context(PDO $pdo, int $templateId, int $reportId): array {
  $teacherFields = load_teacher_fields($pdo, $templateId);
  $teacherByBase = [];
  $teacherFieldIds = [];
  foreach ($teacherFields as $f) {
    $fid = (int)($f['id'] ?? 0);
    if ($fid <= 0) continue;
    $teacherFieldIds[] = $fid;
    $base = base_field_key((string)($f['field_name'] ?? ''));
    if ($base !== '' && !isset($teacherByBase[$base])) {
      $teacherByBase[$base] = $f;
    }
  }
  $teacherValues = $teacherFieldIds ? load_teacher_values($pdo, $reportId, $teacherFieldIds) : [];
  return [
    'teacher_by_base' => $teacherByBase,
    'teacher_values' => $teacherValues,
    'option_cache' => [],
  ];
}

function all_child_fields_filled(PDO $pdo, int $templateId, int $reportId, array $lockedFieldIds = []): bool {
  $fields = load_child_fields($pdo, $templateId);
  if (!$fields) return true;

  $vals = load_child_values($pdo, $reportId);
  foreach ($fields as $f) {
    $fid = (int)$f['id'];
    if (!empty($lockedFieldIds[$fid])) continue;
    $v = $vals[$fid]['text'] ?? null;
    if (trim((string)$v) === '') return false;
  }
  return true;
}

try {
  $pdo = db();
  $studentId = (int)($_SESSION['student']['id'] ?? 0);
  $lang = ui_lang();
  if ($studentId <= 0) throw new RuntimeException(t('student.wizard.error.not_logged_in'));

  $data = read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $action = (string)($data['action'] ?? '');
  if (!in_array($action, ['bootstrap','save_value','submit'], true)) {
    throw new RuntimeException(t('student.wizard.error.invalid_action'));
  }

  $tpl = template_for_student($pdo, $studentId);
  $templateId = (int)$tpl['id'];
  $periodLabel = normalize_class_period_label($tpl['period_label'] ?? 'Standard');

  $studentRow = get_student_and_class($pdo, $studentId);
  $schoolYear = (string)($studentRow['school_year'] ?? '');
  if ($schoolYear === '') {
    $cfg = app_config();
    $schoolYear = (string)($cfg['app']['default_school_year'] ?? '');
  }
  if ($schoolYear === '') throw new RuntimeException(t('student.wizard.error.school_year_missing'));
  $classId = (int)($studentRow['class_id'] ?? 0);

  $ctx = find_or_create_report_instance($pdo, $studentId, $templateId, $schoolYear, $periodLabel);
  
  if ((int)$ctx['report_instance_id'] < 0) throw new RuntimeException(t('student.wizard.error.report_unavailable'));
  
  $reportId = (int)$ctx['report_instance_id'];

  $status = get_report_status($pdo, $reportId);
  $childCanEdit = ($status === 'draft');
  $groupUnlocks = load_child_group_unlocks($pdo, $classId, $schoolYear, 'Standard');

  if ($action === 'bootstrap') {
    $introHtml = '';
    $classIntro = trim((string)($studentRow['student_intro_html'] ?? ''));
    if ($classIntro !== '') {
      $introHtml = render_intro_placeholders(sanitize_intro_html($classIntro), $studentRow);
    } else {
      $introAbs = child_intro_file_abs();
      if (is_file($introAbs)) {
        $introHtml = sanitize_intro_html((string)file_get_contents($introAbs));
        $introHtml = render_intro_placeholders($introHtml, $studentRow);
      }
    }

    $fieldsRaw = load_child_fields($pdo, $templateId);
    $values = load_child_values($pdo, $reportId);

    $fieldLookup = load_all_fields_lookup($pdo, $templateId, $reportId);

    // Merge class-wide values into field_lookup so placeholders can resolve even if the student has no value.
    if ($classId > 0 && $schoolYear !== '') {
      $classReportId = find_or_create_class_report_instance($pdo, $templateId, $classId, $schoolYear, $periodLabel);
      $classLookup = load_class_lookup($pdo, $templateId, $classReportId);

      foreach ($classLookup as $k => $v) {
        if (!isset($fieldLookup[$k])) {
          $fieldLookup[$k] = $v;
          continue;
        }
        $sv = (string)($fieldLookup[$k]['value'] ?? '');
        $cv = (string)($v['value'] ?? '');
        if (trim($sv) === '' && trim($cv) !== '') {
          $fieldLookup[$k]['value'] = $cv;
        }
      }
    }

    $groups = [];
    $iconIds = [];
    $optCache = []; // listId => options array
    $lockedFieldIds = [];
    $lockContext = child_lock_context($pdo, $templateId, $reportId);

    foreach ($fieldsRaw as $r) {
      $fid = (int)$r['id'];
      if (child_field_locked_by_teacher($pdo, $r, $lockContext)) {
        $lockedFieldIds[$fid] = true;
        continue;
      }
      $meta = meta_read($r['meta_json'] ?? null);
      foreach ([
        'group_label' => 'group',
        'group_label_en' => 'group_title_en',
        'subgroup_label' => 'subgroup',
        'subgroup_label_en' => 'subgroup_title_en',
      ] as $column => $metaKey) {
        $v = trim((string)($r[$column] ?? ''));
        if ($v !== '' && trim((string)($meta[$metaKey] ?? '')) === '') $meta[$metaKey] = $v;
      }

      $gParts = group_parts_from_meta($meta);
      $gKey = $gParts['group'];
      $gTitle = group_title_from_meta($meta, $gKey, $lang);

      $gAliases = group_key_aliases_from_meta($meta);
      if (!isset($groups[$gKey])) {
        $groups[$gKey] = ['key' => $gKey, 'title' => $gTitle, 'aliases' => $gAliases, 'fields' => []];
      } else {
        $groups[$gKey]['title'] = $gTitle;
        $groups[$gKey]['aliases'] = array_values(array_unique(array_merge($groups[$gKey]['aliases'] ?? [], $gAliases)));
      }

      $opts = [];
      $listId = option_list_id_from_meta($meta);

      if ($listId > 0) {
        if (!isset($optCache[$listId])) $optCache[$listId] = load_option_list_items($pdo, $listId);
        $opts = $optCache[$listId];
      } elseif (!empty($r['options_json'])) {
        // legacy / manual options_json
        $oj = json_decode((string)$r['options_json'], true);
        if (is_array($oj) && isset($oj['options']) && is_array($oj['options'])) {
          // allow optional label_en in JSON; pass through as-is
          $opts = $oj['options'];
        }
      }

      foreach ($opts as $o) {
        $iid = (int)($o['icon_id'] ?? 0);
        if ($iid > 0) $iconIds[] = $iid;
      }

      $raw = $values[$fid] ?? ['text' => null, 'json' => null];
      $val = resolve_option_value_text($pdo, $meta, $raw['json'] ?? null, $raw['text'] ?? null);

      $groups[$gKey]['fields'][] = [
        'id' => $fid,
        'name' => (string)$r['field_name'],
        'type' => (string)$r['field_type'],
        'label_raw' => (string)($r['label'] ?? $r['field_name']),
        'label_en_raw' => (string)($r['label_en'] ?? ''),
        'help_raw' => (string)($r['help_text'] ?? ''),
        'label' => label_for_lang($r['label'] ?? null, $r['label_en'] ?? null, $lang, (string)$r['field_name']),
        'help' => (string)($r['help_text'] ?? ''),
        'required' => true,
        'multiline' => (int)($r['is_multiline'] ?? 0) === 1,
        'group' => $gKey,
        'subgroup' => $gParts['subgroup'],
        'subgroup_title_en' => subgroup_title_en_from_meta($meta),
        'options' => $opts,     // includes label_en now (for option-list templates)
        'value' => $val,
        'max_length' => pdf_max_len_from_meta($meta),
      ];
    }

    $iconMap = resolve_icon_urls($pdo, $iconIds);

    foreach ($groups as $gKey => $gData) {
      foreach ($gData['fields'] as $i => $f) {
        if (!empty($f['options']) && is_array($f['options'])) {
          foreach ($f['options'] as $k => $o) {
            $iid = (int)($o['icon_id'] ?? 0);
            $groups[$gKey]['fields'][$i]['options'][$k]['icon_url'] = ($iid > 0 && isset($iconMap[$iid])) ? $iconMap[$iid] : null;
          }
        }
      }
    }

    $steps = [];
    $steps[] = [
      'key' => 'intro',
      'title' => 'Start',
      'is_intro' => true,
      'intro_html' => $introHtml,
      'fields' => [],
    ];

    if ($groupUnlocks['active']) {
      foreach ($groups as $gKey => $gData) {
        $aliases = is_array($gData['aliases'] ?? null) ? $gData['aliases'] : [$gKey];
        if (!group_key_unlocked($groupUnlocks['map'], $aliases)) unset($groups[$gKey]);
      }
      if (!$groups) $childCanEdit = false;
    }

    foreach ($groups as $gKey => $gData) {
      $steps[] = [
        'key' => $gKey,
        'title' => (string)$gData['title'],
        'fields' => $gData['fields'],
      ];
    }

    json_out([
      'ok' => true,
      'template' => [
        'id' => $templateId,
        'name' => (string)$tpl['name'],
        'version' => (int)$tpl['template_version'],
      ],
      'report_instance_id' => $reportId,
      'report_status' => $status,
      'child_can_edit' => $childCanEdit,
      'steps' => $steps,
      'field_lookup' => $fieldLookup,
      'ui' => [
        'display_mode' => student_wizard_display_mode_from_class($studentRow),
        'ai_enabled' => ai_provider_enabled(),
      ],
      'ui_lang' => $lang,
      'translations' => ui_translations(),
    ]);
  }

  if ($action === 'save_value') {
    ensure_editable_or_throw($pdo, $reportId);

    $fieldId = (int)($data['template_field_id'] ?? 0);
    if ($fieldId <= 0) throw new RuntimeException('template_field_id fehlt.');

    $fieldSelect = 'id, field_name, field_type, meta_json';
    foreach (['group_label', 'group_label_en', 'subgroup_label', 'subgroup_label_en'] as $column) {
      if (db_has_column($pdo, 'template_fields', $column)) $fieldSelect .= ', ' . $column;
    }
    $st = $pdo->prepare(
      "SELECT $fieldSelect
       FROM template_fields
       WHERE id=? AND template_id=? AND can_child_edit=1
       LIMIT 1"
    );
    $st->execute([$fieldId, $templateId]);
    $frow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$frow) throw new RuntimeException('Feld nicht erlaubt.');

    $lockContext = child_lock_context($pdo, $templateId, $reportId);
    if (child_field_locked_by_teacher($pdo, $frow, $lockContext)) {
      throw new RuntimeException('Feld ist gesperrt.');
    }

    $type = (string)$frow['field_type'];
    $meta = meta_read($frow['meta_json'] ?? null);
    foreach ([
      'group_label' => 'group',
      'group_label_en' => 'group_title_en',
      'subgroup_label' => 'subgroup',
      'subgroup_label_en' => 'subgroup_title_en',
    ] as $column => $metaKey) {
      $v = trim((string)($frow[$column] ?? ''));
      if ($v !== '' && trim((string)($meta[$metaKey] ?? '')) === '') $meta[$metaKey] = $v;
    }
    $maxLen = pdf_max_len_from_meta($meta);
    $valueText = isset($data['value_text']) ? (string)$data['value_text'] : null;

    if ($groupUnlocks['active']) {
      if (!group_key_unlocked($groupUnlocks['map'], group_key_aliases_from_meta($meta))) {
        throw new RuntimeException('Kategorie noch nicht freigegeben.');
      }
    }

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
      $v = ($valueText === '1' || $valueText === 'true' || $valueText === 'on') ? '1' : '0';
      $valueText = $v;
    } else {
      $valueText = $valueText !== null ? trim($valueText) : null;
      if ($valueText === '') {
        $valueText = null;
      } else {
        $valueText = clamp_text_length($valueText, $maxLen);
      }
    }

    try {
      $pdo->beginTransaction();

      $up = $pdo->prepare(
        "INSERT INTO field_values (report_instance_id, template_field_id, value_text, value_json, source, updated_by_student_id, updated_at)
         VALUES (?, ?, ?, ?, 'child', ?, NOW())
         ON DUPLICATE KEY UPDATE
           value_text=VALUES(value_text),
           value_json=VALUES(value_json),
           source='child',
           updated_by_student_id=VALUES(updated_by_student_id),
           updated_at=NOW()"
      );
      $up->execute([$reportId, $fieldId, $valueText, $valueJson, $studentId]);

      record_field_value_history($pdo, $reportId, $fieldId, $valueText, $valueJson, 'child', null, $studentId);

      if (db_has_column($pdo, 'report_instances', 'updated_at')) {
        $touch = $pdo->prepare("UPDATE report_instances SET updated_at=NOW() WHERE id=?");
        $touch->execute([$reportId]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    json_out([
      'ok' => true,
      'template_field_id' => $fieldId,
      'updated_at' => date(DATE_ATOM),
    ]);
  }

  // submit
  ensure_editable_or_throw($pdo, $reportId);
  $lockContext = child_lock_context($pdo, $templateId, $reportId);
  $lockedFieldIds = [];
  foreach (load_child_fields($pdo, $templateId) as $f) {
    $fid = (int)($f['id'] ?? 0);
    if ($fid > 0 && child_field_locked_by_teacher($pdo, $f, $lockContext)) {
      $lockedFieldIds[$fid] = true;
    }
  }
  if (!all_child_fields_filled($pdo, $templateId, $reportId, $lockedFieldIds)) {
    throw new RuntimeException(t('student.wizard.error.fields_required'));
  }

  $pdo->prepare(
    "UPDATE report_instances
     SET status='submitted', locked_by_user_id=NULL, locked_at=NULL
     WHERE id=?"
  )->execute([$reportId]);

  audit('student_submit', null, ['student_id'=>$studentId,'report_instance_id'=>$reportId]);

  json_out(['ok' => true]);

} catch (Throwable $e) {
  $message = $e->getMessage();
  $lowerMessage = strtolower($message);
  $status = 400;
  $code = 'request_failed';
  if (str_contains($lowerMessage, 'csrf') || str_contains($lowerMessage, 'token')) {
    $status = 403;
    $code = 'csrf_failed';
  } elseif (str_contains($lowerMessage, 'nicht angemeldet') || str_contains($lowerMessage, 'not_logged_in')) {
    $status = 401;
    $code = 'session_expired';
  } elseif ($e instanceof PDOException || !($e instanceof RuntimeException)) {
    $status = 500;
    $code = 'internal_error';
    $message = t('student.js.save_error_generic');
  }
  json_out([
    'ok' => false,
    'error' => $code,
    'message' => $message,
    'retryable' => in_array($status, [500, 502, 503, 504], true),
    'requires_login' => in_array($code, ['session_expired', 'csrf_failed'], true),
  ], $status);
}
