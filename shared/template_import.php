<?php
declare(strict_types=1);

function template_import_ensure_dir(string $path): void {
    if (!is_dir($path) && !@mkdir($path, 0755, true)) {
        throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $path);
    }
}

function template_import_safe_pdf_base(string $filename): string {
    $base = preg_replace('/[^a-z0-9._-]+/i', '_', pathinfo($filename, PATHINFO_FILENAME)) ?: 'template';
    $base = trim($base, '._-');
    return $base !== '' ? $base : 'template';
}

function template_import_next_version(PDO $pdo, string $name): int {
    $st = $pdo->prepare('SELECT MAX(template_version) FROM templates WHERE name=?');
    $st->execute([$name]);
    return max(1, ((int)($st->fetchColumn() ?: 0)) + 1);
}

function create_template_from_pdf_file(PDO $pdo, string $sourceAbs, string $templateName, string $originalFilename, int $createdByUserId, ?int $version = null): array {
    $templateName = trim($templateName);
    if ($templateName === '') {
        throw new RuntimeException('Template-Name darf nicht leer sein.');
    }
    if (!is_file($sourceAbs) || !is_readable($sourceAbs)) {
        throw new RuntimeException('PDF-Datei nicht gefunden oder nicht lesbar.');
    }
    if (!preg_match('/\.pdf$/i', $sourceAbs) && !preg_match('/\.pdf$/i', $originalFilename)) {
        throw new RuntimeException('Nur PDF-Dateien können als Template übernommen werden.');
    }

    $version = $version !== null && $version > 0 ? $version : template_import_next_version($pdo, $templateName);
    $sha = hash_file('sha256', $sourceAbs) ?: null;

    $cfg = app_config();
    $uploadsRel = trim((string)($cfg['app']['uploads_dir'] ?? 'uploads'), '/\\') ?: 'uploads';
    $rootAbs = realpath(__DIR__ . '/..');
    if (!$rootAbs) {
        throw new RuntimeException('Projektwurzel konnte nicht ermittelt werden.');
    }
    $uploadsAbs = $rootAbs . '/' . $uploadsRel;
    template_import_ensure_dir($uploadsAbs);
    template_import_ensure_dir($uploadsAbs . '/templates');

    $stmt = $pdo->prepare(
        'INSERT INTO templates (name, template_version, pdf_storage_path, pdf_original_filename, pdf_sha256, created_by_user_id, is_active) VALUES (?, ?, \'\', ?, ?, ?, 1)'
    );
    $stmt->execute([$templateName, $version, $originalFilename, $sha, $createdByUserId]);
    $templateId = (int)$pdo->lastInsertId();

    $tplDirAbs = $uploadsAbs . '/templates/' . $templateId;
    template_import_ensure_dir($tplDirAbs);
    $destAbs = $tplDirAbs . '/' . template_import_safe_pdf_base($originalFilename) . '_v' . $version . '.pdf';
    $destRel = $uploadsRel . '/templates/' . $templateId . '/' . basename($destAbs);

    if (!@copy($sourceAbs, $destAbs)) {
        throw new RuntimeException('PDF-Datei konnte nicht in den Template-Speicher kopiert werden.');
    }
    @chmod($destAbs, 0640);

    $pdo->prepare('UPDATE templates SET pdf_storage_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$destRel, $templateId]);

    return [
        'template_id' => $templateId,
        'version' => $version,
        'pdf_storage_path' => $destRel,
        'pdf_abs_path' => $destAbs,
        'pdf_sha256' => $sha,
    ];
}

function template_import_map_field_type(string $type, array $field): string {
    $allowed = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];
    $t = strtolower(trim($type));
    if ($t === '') $t = 'radio';
    $u = strtoupper($t);
    if ($u === 'TX') $t = !empty($field['multiline']) ? 'multiline' : 'text';
    if ($u === 'CH') $t = 'select';
    if ($u === 'SIG') $t = 'signature';
    if ($u === 'BTN') $t = 'checkbox';
    if (!empty($field['multiline']) && $t === 'text') $t = 'multiline';
    return in_array($t, $allowed, true) ? $t : 'radio';
}

function import_pdf_fields_to_template(PDO $pdo, int $templateId, array $fields): int {
    $st = $pdo->prepare('SELECT id FROM templates WHERE id=? LIMIT 1');
    $st->execute([$templateId]);
    if (!$st->fetch()) {
        throw new RuntimeException('Template nicht gefunden.');
    }

    $ins = $pdo->prepare(
        "INSERT INTO template_fields
" .
        "  (template_id, field_name, field_type, label, help_text, is_multiline, is_required, meta_json, sort_order, can_child_edit, can_teacher_edit)
" .
        "VALUES
" .
        "  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
" .
        "ON DUPLICATE KEY UPDATE
" .
        "  field_type = VALUES(field_type), label = VALUES(label), help_text = VALUES(help_text), is_multiline = VALUES(is_multiline),
" .
        "  meta_json = VALUES(meta_json), sort_order = VALUES(sort_order), can_child_edit = VALUES(can_child_edit),
" .
        "  can_teacher_edit = VALUES(can_teacher_edit), updated_at = CURRENT_TIMESTAMP"
    );

    $count = 0;
    foreach ($fields as $i => $f) {
        if (!is_array($f)) continue;
        $name = trim((string)($f['name'] ?? $f['field_name'] ?? ''));
        if ($name === '') continue;

        $mappedType = template_import_map_field_type((string)($f['type'] ?? 'radio'), $f);
        $label = trim((string)($f['label'] ?? ''));
        if ($label === '') $label = $name;
        $help = trim((string)($f['help_text'] ?? ''));
        $isMultiline = ($mappedType === 'multiline') ? 1 : (!empty($f['multiline']) ? 1 : 0);
        $canChild = array_key_exists('can_child_edit', $f) ? (((int)$f['can_child_edit'] === 1) ? 1 : 0) : 0;
        $canTeacher = array_key_exists('can_teacher_edit', $f) ? (((int)$f['can_teacher_edit'] === 1) ? 1 : 0) : 1;
        $meta = $f['meta'] ?? [];
        if (!is_array($meta)) $meta = [];
        $meta['detectedType'] = $meta['detectedType'] ?? ($meta['type'] ?? null);
        $meta['multiline'] = $meta['multiline'] ?? (bool)$isMultiline;
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) $metaJson = null;
        $sort = isset($f['sort']) ? (int)$f['sort'] : (isset($f['sort_order']) ? (int)$f['sort_order'] : (int)$i);

        $ins->execute([$templateId, $name, $mappedType, $label, $help, $isMultiline, 1, $metaJson, $sort, $canChild, $canTeacher]);
        $count++;
    }
    return $count;
}

function template_import_table_columns(PDO $pdo, string $table): array {
    $cols = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($cols as $c) {
        $out[(string)($c['Field'] ?? '')] = true;
    }
    return $out;
}

function apply_rcff_to_template_fields(PDO $pdo, int $templateId, array $rcff): array {
    if (($rcff['format'] ?? '') !== 'rcff') {
        throw new RuntimeException('Ungültiges RCFF-Format.');
    }
    if ((int)($rcff['version'] ?? 0) !== 1) {
        throw new RuntimeException('Nicht unterstützte RCFF-Version.');
    }
    if (!is_array($rcff['fields'] ?? null)) {
        throw new RuntimeException('RCFF fields fehlen.');
    }

    $columns = template_import_table_columns($pdo, 'template_fields');
    $hasLabelEn = isset($columns['label_en']);
    $hasGroupLabel = isset($columns['group_label']);
    $hasGroupLabelEn = isset($columns['group_label_en']);
    $hasSubgroupLabel = isset($columns['subgroup_label']);
    $hasSubgroupLabelEn = isset($columns['subgroup_label_en']);

    $select = 'id, field_name, label, meta_json';
    if ($hasLabelEn) $select .= ', label_en';
    if ($hasGroupLabel) $select .= ', group_label';
    if ($hasGroupLabelEn) $select .= ', group_label_en';
    if ($hasSubgroupLabel) $select .= ', subgroup_label';
    if ($hasSubgroupLabelEn) $select .= ', subgroup_label_en';

    $st = $pdo->prepare("SELECT $select FROM template_fields WHERE template_id=?");
    $st->execute([$templateId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byName = [];
    foreach ($rows as $row) {
        $byName[(string)$row['field_name']] = $row;
    }

    $templateFieldNames = array_keys($byName);
    $stats = [
        'rcff_fields_total' => 0,
        'template_fields_total' => count($templateFieldNames),
        'matched_fields' => 0,
        'updated_fields' => 0,
        'labels_updated' => 0,
        'groups_updated' => 0,
        'subgroups_updated' => 0,
        'rating_meta_updated' => 0,
        'ignored_missing_pdf_field' => 0,
        'errors' => [],
        'unmatched_rcff_field_names' => [],
        'sample_template_field_names' => array_slice($templateFieldNames, 0, 5),
        // Backwards-compatible keys used by the existing manual RCFF upload UI.
        'read' => 0,
        'matched' => 0,
        'updated' => 0,
        'ignored' => 0,
        'skipped' => 0,
        'meta_updated' => 0,
    ];
    $rcffValuesByName = [];
    foreach ($rcff['fields'] as $candidate) {
        if (!is_array($candidate)) continue;
        $candidateName = trim((string)($candidate['field_name'] ?? ''));
        if ($candidateName === '' || !array_key_exists('value', $candidate)) continue;
        $rcffValuesByName[$candidateName][] = (string)$candidate['value'];
    }

    foreach ($rcff['fields'] as $field) {
        $stats['read']++;
        $stats['rcff_fields_total']++;
        if (!is_array($field)) { $stats['skipped']++; $stats['errors'][] = 'Ungültiger RCFF-Feldeintrag bei Index ' . ($stats['read'] - 1); continue; }
        $fieldName = trim((string)($field['field_name'] ?? ''));
        if ($fieldName === '') { $stats['skipped']++; $stats['errors'][] = 'RCFF-Feld ohne field_name bei Index ' . ($stats['read'] - 1); continue; }
        if (!isset($byName[$fieldName])) {
            $stats['ignored']++;
            $stats['ignored_missing_pdf_field']++;
            if (count($stats['unmatched_rcff_field_names']) < 5) $stats['unmatched_rcff_field_names'][] = $fieldName;
            continue;
        }
        $stats['matched']++;
        $stats['matched_fields']++;
        $row = $byName[$fieldName];

        $labelDe = trim((string)($field['label_de'] ?? ''));
        $labelEn = trim((string)($field['label_en'] ?? ''));
        $categoryDe = trim((string)($field['category_de'] ?? ''));
        $categoryEn = trim((string)($field['category_en'] ?? ''));
        $subcategoryDe = trim((string)($field['subcategory_de'] ?? ''));
        $subcategoryEn = trim((string)($field['subcategory_en'] ?? ''));

        if ($hasLabelEn) {
            $newLabel = $labelDe !== '' ? $labelDe : (string)($row['label'] ?? '');
            $newLabelEn = $labelEn !== '' ? $labelEn : (string)($row['label_en'] ?? '');
        } else {
            $combined = ($labelDe !== '' && $labelEn !== '') ? ($labelDe . ' | ' . $labelEn) : ($labelDe !== '' ? $labelDe : $labelEn);
            $newLabel = $combined !== '' ? $combined : (string)($row['label'] ?? '');
            $newLabelEn = '';
        }
        $labelChanged = ($newLabel !== (string)($row['label'] ?? '')) || ($hasLabelEn && $newLabelEn !== (string)($row['label_en'] ?? ''));

        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        if (!is_array($meta)) $meta = [];
        $beforeMeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $meta['rcff'] = [
            'field_name' => $fieldName,
            'type' => (string)($field['type'] ?? 'unknown'),
            'label_de' => $labelDe,
            'label_en' => $labelEn,
            'category_id' => isset($field['category_id']) ? (int)$field['category_id'] : 0,
            'category_de' => $categoryDe,
            'category_en' => $categoryEn,
            'subcategory_id' => isset($field['subcategory_id']) ? (int)$field['subcategory_id'] : 0,
            'subcategory_de' => $subcategoryDe,
            'subcategory_en' => $subcategoryEn,
            'competency_code' => (string)($field['competency_code'] ?? ''),
            'competency_id' => isset($field['competency_id']) ? (int)$field['competency_id'] : 0,
            'role' => (string)($field['role'] ?? ''),
        ];
        if (array_key_exists('rating_values', $field)) {
            $meta['rcff']['rating_values'] = array_values((array)$field['rating_values']);
        }
        if (array_key_exists('rating_mode', $field)) {
            $meta['rcff']['rating_mode'] = (string)$field['rating_mode'];
        }
        if (array_key_exists('value', $field)) {
            $meta['rcff']['value'] = (string)$field['value'];
        }
        if (isset($rcffValuesByName[$fieldName])) {
            $meta['rcff']['values'] = array_values(array_unique($rcffValuesByName[$fieldName]));
        }

        $groupPath = '';
        if ($categoryDe !== '' && $subcategoryDe !== '') $groupPath = $categoryDe . '/' . $subcategoryDe;
        elseif ($categoryDe !== '') $groupPath = $categoryDe;
        if ($groupPath !== '') $meta['group'] = $groupPath;
        if ($subcategoryDe !== '') $meta['subgroup'] = $subcategoryDe;
        if ($categoryEn !== '') $meta['group_title_en'] = $categoryEn;
        if ($subcategoryEn !== '') $meta['subgroup_title_en'] = $subcategoryEn;

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) $metaJson = '{}';
        $metaChanged = ($metaJson !== $beforeMeta);

        $sql = 'UPDATE template_fields SET label=?, meta_json=?';
        $params = [$newLabel, $metaJson];
        if ($hasLabelEn) { $sql .= ', label_en=?'; $params[] = ($newLabelEn !== '' ? $newLabelEn : null); }
        if ($hasGroupLabel) { $sql .= ', group_label=?'; $params[] = ($categoryDe !== '' ? $categoryDe : ($row['group_label'] ?? null)); }
        if ($hasGroupLabelEn) { $sql .= ', group_label_en=?'; $params[] = ($categoryEn !== '' ? $categoryEn : ($row['group_label_en'] ?? null)); }
        if ($hasSubgroupLabel) { $sql .= ', subgroup_label=?'; $params[] = ($subcategoryDe !== '' ? $subcategoryDe : ($row['subgroup_label'] ?? null)); }
        if ($hasSubgroupLabelEn) { $sql .= ', subgroup_label_en=?'; $params[] = ($subcategoryEn !== '' ? $subcategoryEn : ($row['subgroup_label_en'] ?? null)); }
        $sql .= ', updated_at=CURRENT_TIMESTAMP WHERE id=? AND template_id=?';
        $params[] = (int)$row['id'];
        $params[] = $templateId;
        $pdo->prepare($sql)->execute($params);

        $stats['updated']++;
        $stats['updated_fields']++;
        if ($labelChanged) $stats['labels_updated']++;
        if ($categoryDe !== '' || $categoryEn !== '') $stats['groups_updated']++;
        if ($subcategoryDe !== '' || $subcategoryEn !== '' || isset($field['subcategory_id'])) $stats['subgroups_updated']++;
        if ((string)($field['type'] ?? '') === 'rating' && (array_key_exists('rating_values', $field) || array_key_exists('rating_mode', $field) || array_key_exists('role', $field))) $stats['rating_meta_updated']++;
        if ($metaChanged) $stats['meta_updated']++;
    }

    return $stats;
}
