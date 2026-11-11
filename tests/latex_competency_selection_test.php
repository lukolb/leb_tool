<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/latex_competency_selection.php';

function latex_selection_assert(bool $condition, string $message): void {
    if ($condition) return;
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE competency_categories (id INTEGER PRIMARY KEY, sort_order INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE competency_subcategories (id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE competencies (id INTEGER PRIMARY KEY, category_id INTEGER, subcategory_id INTEGER, code TEXT, is_active INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE competency_grade_levels (competency_id INTEGER NOT NULL, grade_level INTEGER NOT NULL, PRIMARY KEY (competency_id, grade_level))');

$pdo->exec('INSERT INTO competency_categories(id,sort_order) VALUES (1,1),(2,2)');
$pdo->exec('INSERT INTO competency_subcategories(id,category_id,sort_order) VALUES (10,1,1),(20,1,2)');
$pdo->exec("INSERT INTO competencies(id,category_id,subcategory_id,code,is_active,sort_order) VALUES
    (1,1,NULL,'G1-DIRECT',1,1),
    (2,NULL,10,'G1-SUB-VISIBLE',1,2),
    (3,NULL,10,'G1-NOT-SELECTED',1,3),
    (4,NULL,20,'G1-SUB-HIDDEN',1,4),
    (5,2,NULL,'G1-CAT-HIDDEN',1,5),
    (6,1,NULL,'G2-WRONG-GRADE',1,6),
    (7,1,NULL,'G1-INACTIVE',0,7)");
$pdo->exec('INSERT INTO competency_grade_levels(competency_id,grade_level) VALUES (1,1),(2,1),(3,1),(4,1),(5,1),(6,2),(7,1)');

$submitted = [
    'G1-DIRECT',
    'G1-SUB-VISIBLE',
    'G1-SUB-HIDDEN',
    'G1-CAT-HIDDEN',
    'G2-WRONG-GRADE',
    'G1-INACTIVE',
    'G1-DIRECT',
];
$actual = latex_visible_selected_competency_codes($pdo, $submitted, 1, [1], [10]);

latex_selection_assert(
    $actual === ['G1-DIRECT', 'G1-SUB-VISIBLE'],
    'only selected, active, grade-matching competencies in visible categories and subcategories may remain'
);
latex_selection_assert(
    latex_visible_selected_competency_codes($pdo, [], 1, [1], [10]) === [],
    'an empty selection must remain empty'
);
latex_selection_assert(
    latex_visible_selected_competency_codes($pdo, ['G1-DIRECT'], 1, [], [10]) === [],
    'competencies from inactive categories must not remain visible'
);

fwrite(STDOUT, "latex_competency_selection_test: OK\n");
