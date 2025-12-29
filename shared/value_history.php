<?php
// shared/value_history.php
// Helpers to store value history for audit/undo purposes.

declare(strict_types=1);

function record_field_value_history(
  PDO $pdo,
  int $reportId,
  int $fieldId,
  ?string $valueText,
  ?string $valueJson,
  string $source,
  ?int $userId = null,
  ?int $studentId = null
): void {
  $sourceSafe = in_array($source, ['teacher', 'child', 'system'], true) ? $source : 'teacher';

  try {
    $stmt = $pdo->prepare(
      "INSERT INTO field_value_history (report_instance_id, template_field_id, value_text, value_json, source, updated_by_user_id, updated_by_student_id, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
      $reportId,
      $fieldId,
      $valueText,
      $valueJson,
      $sourceSafe,
      $userId,
      $studentId,
    ]);
  } catch (Throwable $e) {
    // History is best-effort and must not block the main save path.
  }
}
