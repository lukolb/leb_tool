<?php
declare(strict_types=1);

/**
 * Build the stable, human-readable prefix used for automatically assigned
 * competency codes.
 */
function competency_code_prefix(int $categoryId, string $categoryName): string {
  $asciiName = strtr(trim($categoryName), [
    'Ä'=>'AE', 'Ö'=>'OE', 'Ü'=>'UE', 'ß'=>'SS',
    'ä'=>'ae', 'ö'=>'oe', 'ü'=>'ue',
  ]);
  $cleanName = preg_replace('/[^A-Za-z0-9]/', '', $asciiName) ?: '';
  $prefix = strtoupper(substr($cleanName, 0, 4));
  return $prefix !== '' ? $prefix : 'CAT' . $categoryId;
}

/**
 * Determine the next code from all existing codes, independent of their
 * current display order. Codes are checked globally because two categories
 * can legitimately have the same four-character prefix.
 *
 * @param array<int, mixed> $existingCodes
 */
function next_comp_code_from_existing(array $existingCodes, int $categoryId, string $categoryName): string {
  $prefix = competency_code_prefix($categoryId, $categoryName);
  $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)$/iD';
  $highest = 0;

  foreach ($existingCodes as $existingCode) {
    if (!is_string($existingCode) && !is_numeric($existingCode)) continue;
    if (preg_match($pattern, trim((string)$existingCode), $match)) {
      $highest = max($highest, (int)$match[1]);
    }
  }

  $next = $highest + 1;
  return $prefix . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function next_comp_code(PDO $pdo, int $categoryId, string $categoryName): string {
  $statement = $pdo->query("SELECT code FROM competencies WHERE code IS NOT NULL AND code <> ''");
  $existingCodes = $statement ? ($statement->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
  return next_comp_code_from_existing($existingCodes, $categoryId, $categoryName);
}

function competency_code_exists(PDO $pdo, string $code, ?int $excludedCompetencyId = null): bool {
  $sql = 'SELECT id FROM competencies WHERE LOWER(code)=LOWER(?)';
  $params = [$code];
  if ($excludedCompetencyId !== null) {
    $sql .= ' AND id<>?';
    $params[] = $excludedCompetencyId;
  }
  $sql .= ' LIMIT 1';

  $statement = $pdo->prepare($sql);
  $statement->execute($params);
  return $statement->fetchColumn() !== false;
}

function competency_code_duplicate_message(): string {
  return 'Dieser Kompetenz-Code wird bereits verwendet. Bitte einen eindeutigen Code eingeben.';
}

function competency_code_unique_constraint_failed(Throwable $error): bool {
  $sqlState = (string)$error->getCode();
  $message = strtolower($error->getMessage());
  return in_array($sqlState, ['23000', '23505', '19'], true)
    && (str_contains($message, 'uq_competencies_code') || (str_contains($message, 'competencies') && str_contains($message, 'code')));
}

/**
 * Assign the next free number within the duplicate code's own prefix. This
 * keeps existing catalog conventions such as SCHR-017 -> SCHR-020 intact.
 *
 * @param array<int, mixed> $existingCodes
 */
function next_comp_code_for_duplicate(array $existingCodes, string $duplicateCode, int $categoryId, string $categoryName): string {
  $duplicateCode = trim($duplicateCode);
  if (!preg_match('/^([A-Za-z0-9]+)-(\d+)$/D', $duplicateCode, $duplicateMatch)) {
    return next_comp_code_from_existing($existingCodes, $categoryId, $categoryName);
  }

  $prefix = strtoupper((string)$duplicateMatch[1]);
  $width = max(3, strlen((string)$duplicateMatch[2]));
  $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)$/iD';
  $highest = 0;
  foreach ($existingCodes as $existingCode) {
    if (!is_string($existingCode) && !is_numeric($existingCode)) continue;
    if (preg_match($pattern, trim((string)$existingCode), $match)) {
      $highest = max($highest, (int)$match[1]);
    }
  }
  return $prefix . '-' . str_pad((string)($highest + 1), $width, '0', STR_PAD_LEFT);
}

/**
 * Keep the first competency in catalog order for each code and rename every
 * later collision. Returns the performed changes for tests and diagnostics.
 *
 * @return array<int, array{id:int,old_code:string,new_code:string}>
 */
function deduplicate_competency_codes(PDO $pdo): array {
  $statement = $pdo->query(
    "SELECT c.id, c.code, COALESCE(c.category_id, sub.category_id) AS category_id, " .
    "COALESCE(cat.name_de, '') AS category_name " .
    "FROM competencies c " .
    "LEFT JOIN competency_subcategories sub ON sub.id=c.subcategory_id " .
    "LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id, sub.category_id) " .
    "WHERE c.code IS NOT NULL AND TRIM(c.code)<>'' " .
    "ORDER BY cat.sort_order, cat.id, CASE WHEN c.subcategory_id IS NULL OR c.subcategory_id=0 THEN 0 ELSE 1 END, " .
    "sub.sort_order, sub.id, c.sort_order, c.id"
  );
  $rows = $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  if (!$rows) return [];

  $existingCodes = array_map(static fn(array $row): string => trim((string)($row['code'] ?? '')), $rows);
  $seen = [];
  $changes = [];
  $update = $pdo->prepare('UPDATE competencies SET code=? WHERE id=?');
  $startedTransaction = !$pdo->inTransaction();

  if ($startedTransaction) $pdo->beginTransaction();
  try {
    foreach ($rows as $row) {
      $id = (int)($row['id'] ?? 0);
      $code = trim((string)($row['code'] ?? ''));
      $normalizedCode = strtolower($code);
      if ($id <= 0 || $code === '') continue;
      if (!isset($seen[$normalizedCode])) {
        $seen[$normalizedCode] = true;
        continue;
      }

      $newCode = next_comp_code_for_duplicate(
        $existingCodes,
        $code,
        (int)($row['category_id'] ?? 0),
        (string)($row['category_name'] ?? '')
      );
      $update->execute([$newCode, $id]);
      $existingCodes[] = $newCode;
      $seen[strtolower($newCode)] = true;
      $changes[] = ['id' => $id, 'old_code' => $code, 'new_code' => $newCode];
    }
    if ($startedTransaction) $pdo->commit();
  } catch (Throwable $e) {
    if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  return $changes;
}
