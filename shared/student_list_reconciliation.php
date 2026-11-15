<?php
declare(strict_types=1);

/**
 * Pure helpers for comparing an uploaded class list with the current class.
 * Database writes deliberately stay in the controller so a preview cannot
 * accidentally mutate student data.
 */

function student_list_compare_text(mixed $value, bool $caseInsensitive = false): string {
  $value = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
  return $caseInsensitive ? mb_strtolower($value, 'UTF-8') : $value;
}

function student_list_match_key(array $student, string $mode): string {
  $first = student_list_compare_text($student['first_name'] ?? '', true);
  $last = student_list_compare_text($student['last_name'] ?? '', true);
  $dob = trim((string)($student['date_of_birth'] ?? ''));
  $externalRef = student_list_compare_text($student['external_ref'] ?? '', true);
  $emailStudent = student_list_compare_text($student['email_student'] ?? '', true);

  return match ($mode) {
    'external_ref' => $externalRef,
    'identity' => ($first !== '' && $last !== '' && $dob !== '') ? "$first\n$last\n$dob" : '',
    'student_email' => $emailStudent,
    'name' => ($first !== '' && $last !== '') ? "$first\n$last" : '',
    'first_dob' => ($first !== '' && $dob !== '') ? "$first\n$dob" : '',
    'last_dob' => ($last !== '' && $dob !== '') ? "$last\n$dob" : '',
    default => '',
  };
}

function student_list_values_equal(string $field, mixed $current, mixed $uploaded): bool {
  if (in_array($field, ['email_student', 'email_parent1', 'email_parent2', 'external_ref'], true)) {
    return student_list_compare_text($current, true) === student_list_compare_text($uploaded, true);
  }
  return student_list_compare_text($current) === student_list_compare_text($uploaded);
}

function student_list_period_key(string $schoolYear, string $periodLabel): ?array {
  if (!preg_match('/(?:^|\D)(\d{4})(?:\D|$)/', trim($schoolYear), $match)) return null;
  $startYear = (int)$match[1];
  if ($startYear <= 0) return null;
  $periodRank = strtoupper(trim($periodLabel)) === 'H2' ? 2 : 1;
  return [$startYear, $periodRank];
}

function student_list_period_is_at_or_after(
  string $candidateSchoolYear,
  string $candidatePeriodLabel,
  string $referenceSchoolYear,
  string $referencePeriodLabel
): bool {
  $candidate = student_list_period_key($candidateSchoolYear, $candidatePeriodLabel);
  $reference = student_list_period_key($referenceSchoolYear, $referencePeriodLabel);
  if ($candidate === null || $reference === null) return false;
  return $candidate[0] > $reference[0]
    || ($candidate[0] === $reference[0] && $candidate[1] >= $reference[1]);
}

function student_list_diff(array $existing, array $uploaded, array $fieldLabels): array {
  $present = array_fill_keys(array_map('strval', (array)($uploaded['present_fields'] ?? [])), true);
  $diff = [];
  foreach ($fieldLabels as $field => $label) {
    if (!isset($present[$field])) continue;
    $current = $existing[$field] ?? '';
    $incoming = $uploaded[$field] ?? '';
    if (student_list_values_equal((string)$field, $current, $incoming)) continue;
    $diff[] = [
      'field' => (string)$field,
      'label' => (string)$label,
      'current' => (string)$current,
      'uploaded' => (string)$incoming,
    ];
  }

  $existingCustom = is_array($existing['custom'] ?? null) ? $existing['custom'] : [];
  $uploadedCustom = is_array($uploaded['custom'] ?? null) ? $uploaded['custom'] : [];
  $customLabels = is_array($uploaded['custom_labels'] ?? null) ? $uploaded['custom_labels'] : [];
  foreach ($uploadedCustom as $key => $incoming) {
    $field = 'custom:' . (string)$key;
    if (!isset($present[$field])) continue;
    $current = $existingCustom[$key] ?? '';
    if (student_list_values_equal($field, $current, $incoming)) continue;
    $diff[] = [
      'field' => $field,
      'label' => (string)($customLabels[$key] ?? $key),
      'current' => (string)$current,
      'uploaded' => (string)$incoming,
    ];
  }

  if ((int)($existing['is_active'] ?? 1) !== 1) {
    $diff[] = [
      'field' => 'is_active',
      'label' => (string)($fieldLabels['is_active'] ?? 'Status'),
      'current' => (string)($fieldLabels['status_inactive'] ?? 'inaktiv'),
      'uploaded' => (string)($fieldLabels['status_active'] ?? 'aktiv'),
    ];
  }
  return $diff;
}

/**
 * Returns matched, new and missing students. A weaker matching rule is used
 * only when it identifies exactly one still-unmatched class member.
 */
function build_student_list_reconciliation(array $existingStudents, array $uploadedStudents, array $fieldLabels): array {
  $existingById = [];
  foreach ($existingStudents as $student) {
    $id = (int)($student['id'] ?? 0);
    if ($id > 0) $existingById[$id] = $student;
  }

  $matchedIds = [];
  $matched = [];
  $new = [];
  $modes = ['external_ref', 'identity', 'student_email', 'name', 'first_dob', 'last_dob'];

  foreach ($uploadedStudents as $uploaded) {
    $candidateId = 0;
    $matchedBy = '';
    foreach ($modes as $mode) {
      $key = student_list_match_key($uploaded, $mode);
      if ($key === '') continue;
      $candidates = [];
      foreach ($existingById as $id => $existing) {
        if (isset($matchedIds[$id])) continue;
        if (student_list_match_key($existing, $mode) === $key) $candidates[] = $id;
      }
      if (count($candidates) === 1) {
        $candidateId = (int)$candidates[0];
        $matchedBy = $mode;
        break;
      }
    }

    if ($candidateId <= 0) {
      $new[] = $uploaded;
      continue;
    }

    $matchedIds[$candidateId] = true;
    $existing = $existingById[$candidateId];
    $matched[] = [
      'student_id' => $candidateId,
      'name' => trim((string)($uploaded['first_name'] ?? '') . ' ' . (string)($uploaded['last_name'] ?? '')),
      'existing' => $existing,
      'uploaded' => $uploaded,
      'matched_by' => $matchedBy,
      'diff' => student_list_diff($existing, $uploaded, $fieldLabels),
    ];
  }

  $missing = [];
  foreach ($existingById as $id => $existing) {
    if (isset($matchedIds[$id])) continue;
    $missing[] = $existing;
  }

  return ['matched' => $matched, 'new' => $new, 'missing' => $missing];
}
