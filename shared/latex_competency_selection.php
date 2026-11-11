<?php
declare(strict_types=1);

function latex_normalize_competency_codes(array $codes): array {
    $normalized = [];
    foreach ($codes as $code) {
        if (!is_scalar($code) && $code !== null) {
            continue;
        }
        $value = trim((string)$code);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }
    return array_keys($normalized);
}

function latex_normalize_competency_ids(array $ids): array {
    $normalized = [];
    foreach ($ids as $id) {
        if (!is_int($id) && !is_string($id)) {
            continue;
        }
        $value = trim((string)$id);
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            continue;
        }
        $normalized[(int)$value] = true;
    }
    return array_keys($normalized);
}

function latex_visible_selected_competency_ids(
    PDO $pdo,
    array $submittedIds,
    int $gradeLevel,
    array $activeCategoryIds,
    array $activeSubcategoryIds
): array {
    if ($gradeLevel < 1 || $gradeLevel > 4) {
        $gradeLevel = 1;
    }

    $selectedMap = array_fill_keys(latex_normalize_competency_ids($submittedIds), true);
    if (!$selectedMap) {
        return [];
    }

    $activeCategoryMap = array_fill_keys(
        array_values(array_unique(array_filter(array_map('intval', $activeCategoryIds), static fn(int $id): bool => $id > 0))),
        true
    );
    $activeSubcategoryMap = array_fill_keys(
        array_values(array_unique(array_filter(array_map('intval', $activeSubcategoryIds), static fn(int $id): bool => $id > 0))),
        true
    );
    if (!$activeCategoryMap) {
        return [];
    }

    $sql = "SELECT c.id, c.subcategory_id, COALESCE(c.category_id, sub.category_id) AS category_id "
        . "FROM competencies c "
        . "LEFT JOIN competency_subcategories sub ON sub.id=c.subcategory_id "
        . "LEFT JOIN competency_categories cat ON cat.id=COALESCE(c.category_id, sub.category_id) "
        . "INNER JOIN competency_grade_levels cgl ON cgl.competency_id=c.id "
        . "WHERE c.is_active=1 AND cgl.grade_level=? "
        . "ORDER BY cat.sort_order, cat.id, CASE WHEN c.subcategory_id IS NULL OR c.subcategory_id=0 THEN 0 ELSE 1 END, sub.sort_order, sub.id, c.sort_order, c.id";
    $statement = $pdo->prepare($sql);
    $statement->execute([$gradeLevel]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $categoryId = (int)($row['category_id'] ?? 0);
        $subcategoryId = (int)($row['subcategory_id'] ?? 0);
        if ($id <= 0 || !isset($selectedMap[$id]) || !isset($activeCategoryMap[$categoryId])) {
            continue;
        }
        if ($subcategoryId > 0 && !isset($activeSubcategoryMap[$subcategoryId])) {
            continue;
        }
        $result[] = $id;
    }

    return $result;
}
