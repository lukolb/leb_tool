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
