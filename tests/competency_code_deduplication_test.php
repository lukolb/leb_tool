<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/competency_codes.php';

function competency_dedup_assert(bool $condition, string $message): void {
    if ($condition) return;
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE competency_categories (id INTEGER PRIMARY KEY, name_de TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE competency_subcategories (id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE competencies (id INTEGER PRIMARY KEY, category_id INTEGER, subcategory_id INTEGER, code TEXT, sort_order INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO competency_categories(id,name_de,sort_order) VALUES (1,'Deutsch',1),(2,'Mathematik',2)");
$pdo->exec('INSERT INTO competency_subcategories(id,category_id,sort_order) VALUES (10,1,1),(20,2,1)');
$pdo->exec("INSERT INTO competencies(id,category_id,subcategory_id,code,sort_order) VALUES
    (1,NULL,10,'SCHR-017',1),
    (2,NULL,10,'SCHR-017',2),
    (3,NULL,10,'SCHR-017',3),
    (4,NULL,10,'SCHR-018',4),
    (5,NULL,10,'SCHR-019',5),
    (6,NULL,20,'GEOM-035',1),
    (7,NULL,20,'GEOM-035',2)");

$changes = deduplicate_competency_codes($pdo);
$codes = $pdo->query('SELECT id, code FROM competencies ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);

competency_dedup_assert(count($changes) === 3, 'every duplicate after the first catalog entry must be renamed');
competency_dedup_assert($codes[1] === 'SCHR-017', 'the first catalog entry must keep its existing code');
competency_dedup_assert($codes[2] === 'SCHR-020' && $codes[3] === 'SCHR-021', 'duplicates must continue at the next free prefix number');
competency_dedup_assert($codes[6] === 'GEOM-035' && $codes[7] === 'GEOM-036', 'each code prefix must be advanced independently');
competency_dedup_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM (SELECT LOWER(code) normalized FROM competencies GROUP BY LOWER(code) HAVING COUNT(*)>1) duplicates")->fetchColumn() === 0,
    'no duplicate competency codes may remain'
);

fwrite(STDOUT, "competency_code_deduplication_test: OK\n");
