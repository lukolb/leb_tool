<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/competency_codes.php';

function competency_code_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

competency_code_assert(
  next_comp_code_from_existing(['SOZI-009', 'SOZI-002', 'SOZI-017'], 1, 'Sozialkompetenz') === 'SOZI-018',
  'the highest suffix must win regardless of display order'
);
competency_code_assert(
  next_comp_code_from_existing(['MATH-041', 'SOZI-003'], 2, 'Sozialkompetenz') === 'SOZI-004',
  'codes with another prefix must not affect the counter'
);
competency_code_assert(
  next_comp_code_from_existing(['UEBE-001'], 3, 'Übergänge') === 'UEBE-002',
  'German umlauts must produce a stable readable prefix'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE competencies (id INTEGER PRIMARY KEY, category_id INTEGER, code TEXT, sort_order INTEGER)');
$pdo->exec("INSERT INTO competencies(id,category_id,code,sort_order) VALUES (1,1,'SOZI-012',1),(2,1,'SOZI-004',99),(3,2,'SOZI-020',1)");

competency_code_assert(
  next_comp_code($pdo, 1, 'Sozialkompetenz') === 'SOZI-021',
  'automatic codes must be globally unique even when category prefixes collide'
);
competency_code_assert(competency_code_exists($pdo, 'sozi-012'), 'duplicate detection must be case-insensitive');
competency_code_assert(!competency_code_exists($pdo, 'SOZI-012', 1), 'the current competency must be excluded while editing');
competency_code_assert(str_contains(competency_code_duplicate_message(), 'eindeutigen Code'), 'duplicate codes need a clear validation message');
competency_code_assert(
    competency_code_unique_constraint_failed(new PDOException('Duplicate entry for key uq_competencies_code', 23000)),
    'database uniqueness violations must be converted into the validation message'
);

fwrite(STDOUT, "competency_code_generation_test: OK\n");
