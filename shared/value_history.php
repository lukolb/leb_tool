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
    // Collapse rapid, tiny edits into the last history entry to avoid noise (e.g. textarea typing).
    $latestStmt = $pdo->prepare(
      "SELECT id, value_text, value_json, source, created_at
         FROM field_value_history
        WHERE report_instance_id=? AND template_field_id=?
        ORDER BY id DESC
        LIMIT 1"
    );
    $latestStmt->execute([$reportId, $fieldId]);
    $prev = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($prev) {
      $sameValue = ($prev['value_text'] ?? null) === $valueText && ($prev['value_json'] ?? null) === $valueJson;
      if ($sameValue) return; // identical change -> do not duplicate

      $recentSeconds = time() - strtotime((string)($prev['created_at'] ?? 'now'));
      $isRecentSameSource = $recentSeconds < 20 && (string)($prev['source'] ?? '') === $sourceSafe;

      if ($isRecentSameSource) {
        // Small edits within a short window just update the latest entry instead of adding a new row.
        $update = $pdo->prepare(
          "UPDATE field_value_history
              SET value_text=?, value_json=?, updated_by_user_id=?, updated_by_student_id=?, created_at=NOW()
            WHERE id=?"
        );
        $update->execute([$valueText, $valueJson, $userId, $studentId, (int)$prev['id']]);
        return;
      }
    }

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
