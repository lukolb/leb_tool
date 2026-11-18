<?php
declare(strict_types=1);

/**
 * Read-only helpers for the admin template trial run.
 *
 * These functions intentionally contain no INSERT/UPDATE/DELETE statements.
 * Trial values are accepted for one request, resolved in memory and returned
 * to the browser without creating students, classes or report instances.
 */

function template_trial_meta(?string $json): array {
  if (!$json) return [];
  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

function template_trial_decode_options(?string $json): array {
  if (!$json) return [];
  $decoded = json_decode($json, true);
  if (!is_array($decoded)) return [];
  if (isset($decoded['options']) && is_array($decoded['options'])) return $decoded['options'];
  return array_is_list($decoded) ? $decoded : [];
}

function template_trial_label(?string $de, ?string $en, string $lang): string {
  $de = trim((string)$de);
  $en = trim((string)$en);
  if ($lang === 'en' && $en !== '') return $en;
  return $de !== '' ? $de : $en;
}

function template_trial_rect(mixed $raw): ?array {
  if (is_string($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $raw = $decoded;
    if (is_string($raw)) $raw = array_map('trim', explode(',', $raw));
  }
  if (!is_array($raw) || count($raw) < 4) return null;
  $rect = array_map('floatval', array_slice($raw, 0, 4));
  foreach ($rect as $coordinate) {
    if (!is_finite($coordinate)) return null;
  }
  return $rect;
}

function template_trial_options(PDO $pdo, array $field, array &$cache, string $lang): array {
  $meta = template_trial_meta($field['meta_json'] ?? null);
  $listId = (int)($meta['option_list_template_id'] ?? 0);
  if ($listId > 0) {
    if (!array_key_exists($listId, $cache)) {
      $st = $pdo->prepare(
        "SELECT id, value, label, label_en, meta_json
         FROM option_list_items
         WHERE list_id=?
         ORDER BY sort_order ASC, id ASC"
      );
      $st->execute([$listId]);
      $cache[$listId] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $rows = $cache[$listId];
  } else {
    $rows = template_trial_decode_options($field['options_json'] ?? null);
  }

  $options = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $value = (string)($row['value'] ?? '');
    $label = template_trial_label($row['label'] ?? null, $row['label_en'] ?? null, $lang);
    if ($label === '') $label = $value;
    $options[] = [
      'option_item_id' => (int)($row['id'] ?? $row['option_item_id'] ?? 0),
      'value' => $value,
      'label' => $label,
    ];
  }
  if (!$options && (string)($field['field_type'] ?? '') === 'grade') {
    foreach (range(1, 6) as $grade) {
      $options[] = ['option_item_id' => 0, 'value' => (string)$grade, 'label' => (string)$grade];
    }
  }
  return $options;
}

function template_trial_load(PDO $pdo, int $templateId, string $lang): array {
  $stTemplate = $pdo->prepare(
    "SELECT id, name, template_version, pdf_original_filename, is_active
     FROM templates
     WHERE id=?
     LIMIT 1"
  );
  $stTemplate->execute([$templateId]);
  $template = $stTemplate->fetch(PDO::FETCH_ASSOC);
  if (!$template) throw new RuntimeException(t('admin.template_trial.error.not_found'));

  $stFields = $pdo->prepare(
    "SELECT id, field_name, field_type, label, label_en, help_text,
            is_multiline, is_required, can_child_edit, can_teacher_edit,
            options_json, meta_json, sort_order
     FROM template_fields
     WHERE template_id=?
     ORDER BY sort_order ASC, id ASC"
  );
  $stFields->execute([$templateId]);
  $fieldRows = $stFields->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $optionCache = [];
  $fields = [];
  foreach ($fieldRows as $row) {
    $meta = template_trial_meta($row['meta_json'] ?? null);
    $bindingTemplate = trim((string)($meta['system_binding_tpl'] ?? ''));
    $binding = trim((string)($meta['system_binding'] ?? ''));
    $systemBound = $bindingTemplate !== '' || $binding !== '';
    $canChild = (int)($row['can_child_edit'] ?? 0) === 1;
    $canTeacher = (int)($row['can_teacher_edit'] ?? 0) === 1;
    $role = $systemBound ? 'system' : ($canChild && !$canTeacher ? 'child' : ($canChild ? 'both' : 'teacher'));
    $fieldType = (string)($row['field_type'] ?? 'text');
    $dateMode = (string)($meta['date_format_mode'] ?? 'preset');
    $dateFormat = $dateMode === 'custom'
      ? trim((string)($meta['date_format_custom'] ?? ''))
      : trim((string)($meta['date_format_preset'] ?? ''));
    $label = template_trial_label($row['label'] ?? null, $row['label_en'] ?? null, $lang);
    if ($label === '') $label = (string)($row['field_name'] ?? '');

    $fields[] = [
      'id' => (int)$row['id'],
      'field_name' => (string)($row['field_name'] ?? ''),
      'field_type' => $fieldType,
      'label' => $label,
      'help_text' => (string)($row['help_text'] ?? ''),
      'is_multiline' => (int)($row['is_multiline'] ?? 0),
      'is_required' => (int)($row['is_required'] ?? 0),
      'can_child_edit' => $canChild ? 1 : 0,
      'can_teacher_edit' => $canTeacher ? 1 : 0,
      'role' => $role,
      'system_bound' => $systemBound ? 1 : 0,
      'binding_template' => $bindingTemplate,
      'binding' => $binding,
      'date_format' => $dateFormat,
      'page' => is_numeric($meta['page'] ?? null) ? (int)$meta['page'] : null,
      'rect' => template_trial_rect($meta['rect'] ?? null),
      'options' => template_trial_options($pdo, $row, $optionCache, $lang),
      'meta' => $meta,
    ];
  }

  $customFields = list_student_custom_fields($pdo);
  $ags = [];
  if (db_has_table($pdo, 'ag_catalog')) {
    $agRows = $pdo->query("SELECT id, ag_name FROM ag_catalog ORDER BY sort_order ASC, ag_name ASC, id ASC")
      ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seen = [];
    foreach ($agRows as $ag) {
      $name = trim((string)($ag['ag_name'] ?? ''));
      $key = mb_strtolower($name, 'UTF-8');
      if ($name === '' || isset($seen[$key])) continue;
      $seen[$key] = true;
      $ags[] = ['id' => (int)($ag['id'] ?? 0), 'name' => $name];
    }
  }

  return [
    'template' => $template,
    'fields' => $fields,
    'custom_fields' => $customFields,
    'ags' => $ags,
  ];
}

function template_trial_scalar(mixed $value, int $maxLength = 10000): string {
  if (!is_scalar($value) && $value !== null) return '';
  $text = (string)($value ?? '');
  return mb_substr($text, 0, $maxLength, 'UTF-8');
}

function template_trial_resolve(array $definition, array $payload): array {
  $studentRaw = is_array($payload['student'] ?? null) ? $payload['student'] : [];
  $classRaw = is_array($payload['class'] ?? null) ? $payload['class'] : [];
  $customRaw = is_array($studentRaw['custom_fields'] ?? null) ? $studentRaw['custom_fields'] : [];
  $fieldRaw = is_array($payload['field_values'] ?? null) ? $payload['field_values'] : [];
  $agRaw = is_array($payload['ag_names'] ?? null) ? $payload['ag_names'] : [];

  $allowedCustom = [];
  foreach ($definition['custom_fields'] ?? [] as $customField) {
    $key = trim((string)($customField['field_key'] ?? ''));
    if ($key === '') continue;
    $allowedCustom[$key] = template_trial_scalar($customRaw[$key] ?? ($customField['default_value'] ?? ''), 2000);
  }

  $allowedAgNames = [];
  foreach ($definition['ags'] ?? [] as $ag) {
    $name = trim((string)($ag['name'] ?? ''));
    if ($name !== '') $allowedAgNames[$name] = true;
  }
  $agNames = [];
  foreach ($agRaw as $nameRaw) {
    $name = trim(template_trial_scalar($nameRaw, 255));
    if ($name !== '' && isset($allowedAgNames[$name])) $agNames[] = $name;
  }
  $agNames = array_values(array_unique($agNames));

  $student = [
    'first_name' => trim(template_trial_scalar($studentRaw['first_name'] ?? '', 190)),
    'last_name' => trim(template_trial_scalar($studentRaw['last_name'] ?? '', 190)),
    'date_of_birth' => trim(template_trial_scalar($studentRaw['date_of_birth'] ?? '', 32)),
    'absence_days_total' => max(0, (int)($studentRaw['absence_days_total'] ?? 0)),
    'absence_days_unexcused' => max(0, (int)($studentRaw['absence_days_unexcused'] ?? 0)),
    'custom_fields' => $allowedCustom,
  ];
  $student['ag_sentence'] = ag_sentence_for_names($student['first_name'], $agNames);

  $gradeRaw = $classRaw['grade_level'] ?? null;
  $class = [
    'school_year' => trim(template_trial_scalar($classRaw['school_year'] ?? '', 32)),
    'period_label' => normalize_class_period_label(template_trial_scalar($classRaw['period_label'] ?? 'H1', 32)),
    'grade_level' => ($gradeRaw === '' || $gradeRaw === null) ? null : (int)$gradeRaw,
    'label' => trim(template_trial_scalar($classRaw['label'] ?? '', 32)),
    'name' => trim(template_trial_scalar($classRaw['name'] ?? '', 190)),
  ];

  $fieldMetaByName = [];
  $fieldValuesByName = [];
  foreach ($definition['fields'] ?? [] as $field) {
    $fieldName = trim((string)($field['field_name'] ?? ''));
    if ($fieldName === '') continue;
    $fieldMetaByName[$fieldName] = [
      'field_type' => (string)($field['field_type'] ?? ''),
      'meta' => is_array($field['meta'] ?? null) ? $field['meta'] : [],
    ];
    if ((int)($field['system_bound'] ?? 0) === 1) continue;
    $fieldValuesByName[$fieldName] = template_trial_scalar($fieldRaw[(string)(int)$field['id']] ?? '', 20000);
  }

  $values = [];
  $valuesByName = [];
  $displayValues = [];
  $systemValues = [];
  $fieldMeta = [];
  foreach ($definition['fields'] ?? [] as $field) {
    $fieldId = (int)($field['id'] ?? 0);
    $fieldName = trim((string)($field['field_name'] ?? ''));
    if ($fieldId <= 0 || $fieldName === '') continue;
    $meta = is_array($field['meta'] ?? null) ? $field['meta'] : [];
    $value = $fieldValuesByName[$fieldName] ?? '';

    if ((int)($field['system_bound'] ?? 0) === 1) {
      $tpl = trim((string)($field['binding_template'] ?? ''));
      if ($tpl !== '') {
        $value = resolve_system_binding_template(
          $tpl,
          $student,
          $class,
          $meta,
          (string)($field['field_type'] ?? ''),
          $fieldValuesByName,
          $fieldMetaByName
        );
      } else {
        $resolved = resolve_system_binding_value((string)($field['binding'] ?? ''), $student, $class);
        $value = $resolved === null ? '' : (string)$resolved;
        if ((string)($field['binding'] ?? '') === 'student.date_of_birth' && (string)($field['date_format'] ?? '') !== '') {
          $value = format_date_pattern($value, (string)$field['date_format']);
        }
      }
      $systemValues[(string)$fieldId] = $value;
      $fieldValuesByName[$fieldName] = $value;
    }

    $values[(string)$fieldId] = $value;
    $valuesByName[$fieldName] = $value;
    $display = $value;
    if ((string)($field['field_type'] ?? '') === 'date' && (string)($field['date_format'] ?? '') !== '') {
      $display = format_date_pattern($value, (string)$field['date_format']);
    }
    $displayValues[(string)$fieldId] = $display;
    $fieldMeta[$fieldName] = [
      'field_type' => (string)($field['field_type'] ?? 'text'),
      'date_format' => (string)($field['date_format'] ?? ''),
    ];
  }

  return [
    'values' => $values,
    'values_by_name' => $valuesByName,
    'display_values' => $displayValues,
    'field_meta' => $fieldMeta,
    'system_values' => $systemValues,
    'ag_sentence' => (string)$student['ag_sentence'],
  ];
}
