<?php
declare(strict_types=1);

/**
 * Shared, template-scoped child wording for competency fields.
 *
 * Template field labels remain the immutable teacher wording. Child wording is
 * resolved at runtime so report PDFs and teacher-facing labels are unaffected.
 */

function competency_child_text_meta(array $field): array {
  $meta = json_decode((string)($field['meta_json'] ?? ''), true);
  return is_array($meta) ? $meta : [];
}

function competency_child_text_rcff_meta(array $field): array {
  $meta = competency_child_text_meta($field);
  return is_array($meta['rcff'] ?? null) ? $meta['rcff'] : [];
}

function competency_child_text_base_field_key(string $fieldName): string {
  $value = strtolower(trim($fieldName));
  $value = preg_replace('/\s+/', ' ', $value) ?? $value;
  $value = preg_replace('/[-_](s|t)$/i', '', trim($value)) ?? $value;
  return trim($value);
}

function competency_child_text_field_key(array $field): string {
  $rcff = competency_child_text_rcff_meta($field);
  $competencyId = (int)($rcff['competency_id'] ?? 0);
  if ($competencyId > 0) return 'id:' . $competencyId;

  $code = strtolower(trim((string)($rcff['competency_code'] ?? '')));
  if ($code !== '') return 'code:' . $code;

  $base = competency_child_text_base_field_key((string)($field['field_name'] ?? ''));
  return $base !== '' ? ('field:' . $base) : '';
}

function competency_child_text_is_competency_field(array $field): bool {
  $rcff = competency_child_text_rcff_meta($field);
  if ((int)($rcff['competency_id'] ?? 0) > 0) return true;
  if (trim((string)($rcff['competency_code'] ?? '')) !== '') return true;
  return strtolower(trim((string)($rcff['type'] ?? ''))) === 'rating';
}

function competency_child_text_normalize(string $value): string {
  $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function competency_child_text_teacher_hash(string $teacherDe, string $teacherEn): string {
  return hash('sha256', competency_child_text_normalize($teacherDe) . "\n" . competency_child_text_normalize($teacherEn));
}

/** @return array<int,int> */
function competency_child_text_grade_levels(PDO $pdo, int $templateId): array {
  if ($templateId <= 0) return [];
  $levels = [];
  try {
    $st = $pdo->prepare(
      "SELECT DISTINCT grade_level
         FROM classes
        WHERE template_id=? AND grade_level IS NOT NULL
        ORDER BY grade_level ASC"
    );
    $st->execute([$templateId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $level) {
      $level = (int)$level;
      if ($level > 0 && $level <= 13) $levels[] = $level;
    }
  } catch (Throwable $e) {
    // Some tests and incomplete installations do not have the classes table yet.
  }
  if ($levels) return array_values(array_unique($levels));

  try {
    $st = $pdo->prepare("SELECT name FROM templates WHERE id=? LIMIT 1");
    $st->execute([$templateId]);
    $name = (string)($st->fetchColumn() ?: '');
    if (preg_match('/(?:Klasse|Grade)\s*([1-9]|1[0-3])/iu', $name, $match)) {
      return [(int)$match[1]];
    }
  } catch (Throwable $e) {
    // A grade cannot always be inferred for a new or detached template.
  }
  return [];
}

function competency_child_text_grade_context(array $levels): string {
  $levels = array_values(array_unique(array_filter(array_map('intval', $levels), static fn(int $level): bool => $level > 0 && $level <= 13)));
  if (!$levels) return 'Grundschule, Klassenstufe nicht eindeutig hinterlegt; verwende besonders einfache Sprache für 6- bis 10-jährige Kinder';
  sort($levels, SORT_NUMERIC);
  $youngest = $levels[0];
  $ageMin = $youngest + 5;
  $ageMax = $youngest + 6;
  $label = count($levels) === 1 ? (string)$youngest : implode(', ', $levels);
  return 'Klassenstufe ' . $label . ' (für die jüngste Zielgruppe etwa ' . $ageMin . ' bis ' . $ageMax . ' Jahre)';
}

function competency_child_text_group_value(array $field, string $key): string {
  $meta = competency_child_text_meta($field);
  $rcff = is_array($meta['rcff'] ?? null) ? $meta['rcff'] : [];
  $candidates = match ($key) {
    'category_de' => [$meta['group'] ?? null, $rcff['category_de'] ?? null],
    'category_en' => [$meta['group_title_en'] ?? null, $rcff['category_en'] ?? null],
    'subcategory_de' => [$meta['subgroup'] ?? null, $rcff['subcategory_de'] ?? null],
    'subcategory_en' => [$meta['subgroup_title_en'] ?? null, $rcff['subcategory_en'] ?? null],
    default => [],
  };
  foreach ($candidates as $candidate) {
    $value = trim((string)$candidate);
    if ($value !== '') return $value;
  }
  return '';
}

/** @return array<int,array<string,mixed>> */
function competency_child_text_catalog(PDO $pdo, int $templateId): array {
  if ($templateId <= 0) return [];
  $st = $pdo->prepare(
    "SELECT id, template_id, field_name, field_type, label, label_en, meta_json,
            can_child_edit, can_teacher_edit, sort_order
       FROM template_fields
      WHERE template_id=? AND (can_child_edit=1 OR can_teacher_edit=1)
      ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $teacherByKey = [];
  $childByKey = [];
  foreach ($rows as $row) {
    if (!competency_child_text_is_competency_field($row)) continue;
    $key = competency_child_text_field_key($row);
    if ($key === '') continue;

    if ((int)($row['can_teacher_edit'] ?? 0) === 1) {
      $isTeacherOnly = (int)($row['can_child_edit'] ?? 0) !== 1;
      if (!isset($teacherByKey[$key]) || $isTeacherOnly) $teacherByKey[$key] = $row;
    }
    if ((int)($row['can_child_edit'] ?? 0) === 1) {
      $isChildOnly = (int)($row['can_teacher_edit'] ?? 0) !== 1;
      if (!isset($childByKey[$key]) || $isChildOnly) $childByKey[$key] = $row;
    }
  }

  $catalog = [];
  foreach ($childByKey as $key => $childField) {
    $teacherField = $teacherByKey[$key] ?? $childField;
    $teacherDe = trim((string)($teacherField['label'] ?? ''));
    $teacherEn = trim((string)($teacherField['label_en'] ?? ''));
    if ($teacherDe === '' && $teacherEn === '') continue;
    $rcff = competency_child_text_rcff_meta($teacherField);
    if (!$rcff) $rcff = competency_child_text_rcff_meta($childField);
    $catalog[] = [
      'competency_key' => $key,
      'field_id' => (int)($childField['id'] ?? 0),
      'field_name' => (string)($childField['field_name'] ?? ''),
      'code' => trim((string)($rcff['competency_code'] ?? '')),
      'teacher_text_de' => $teacherDe,
      'teacher_text_en' => $teacherEn,
      'teacher_text_hash' => competency_child_text_teacher_hash($teacherDe, $teacherEn),
      'category_de' => competency_child_text_group_value($childField, 'category_de'),
      'category_en' => competency_child_text_group_value($childField, 'category_en'),
      'subcategory_de' => competency_child_text_group_value($childField, 'subcategory_de'),
      'subcategory_en' => competency_child_text_group_value($childField, 'subcategory_en'),
      'sort_order' => (int)($childField['sort_order'] ?? 0),
    ];
  }
  return $catalog;
}

/** @return array<string,array<string,mixed>> */
function competency_child_text_overrides(PDO $pdo, int $templateId): array {
  if ($templateId <= 0 || !function_exists('db_has_table') || !db_has_table($pdo, 'template_competency_child_texts')) return [];
  $st = $pdo->prepare(
    "SELECT id, template_id, competency_key, teacher_text_hash, teacher_text_de, teacher_text_en,
            child_text_de, child_text_en, updated_by_user_id, updated_at
       FROM template_competency_child_texts
      WHERE template_id=?"
  );
  $st->execute([$templateId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $key = (string)($row['competency_key'] ?? '');
    if ($key !== '') $out[$key] = $row;
  }
  return $out;
}

/** @param array<int,array<string,mixed>> $fields @return array<int,array<string,mixed>> */
function competency_child_text_apply_overrides(PDO $pdo, int $templateId, array $fields): array {
  if (!$fields) return $fields;
  $overrides = competency_child_text_overrides($pdo, $templateId);
  if (!$overrides) return $fields;

  foreach ($fields as &$field) {
    if (!competency_child_text_is_competency_field($field)) continue;
    $key = competency_child_text_field_key($field);
    if ($key === '' || !isset($overrides[$key])) continue;
    $override = $overrides[$key];
    $childDe = trim((string)($override['child_text_de'] ?? ''));
    $childEn = trim((string)($override['child_text_en'] ?? ''));
    if ($childDe !== '') $field['label'] = $childDe;
    if ($childEn !== '') $field['label_en'] = $childEn;
  }
  unset($field);
  return $fields;
}
