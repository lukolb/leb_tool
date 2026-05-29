<?php
declare(strict_types=1);

function generated_template_package_uploads_rel(): string {
    $cfg = app_config();
    $uploadsRel = trim((string)($cfg['app']['uploads_dir'] ?? 'uploads'), '/\\');
    return $uploadsRel !== '' ? $uploadsRel : 'uploads';
}

function generated_template_package_storage_root(): string {
    $base = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    return $base . '/' . generated_template_package_uploads_rel() . '/generated_template_packages';
}

function ensure_generated_template_package_storage_dir(): void {
    $dir = generated_template_package_storage_root();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
}

function ensure_generated_template_packages_table(PDO $pdo): void {
    if (function_exists('db_has_table') && db_has_table($pdo, 'generated_template_packages')) {
        if (function_exists('db_has_column') && !db_has_column($pdo, 'generated_template_packages', 'imported_at')) {
            $pdo->exec("ALTER TABLE generated_template_packages ADD COLUMN imported_at DATETIME DEFAULT NULL AFTER imported_template_id");
        }
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS generated_template_packages (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  token VARCHAR(96) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  created_by_user_id BIGINT UNSIGNED NOT NULL,\n" .
        "  created_by_role ENUM('admin','teacher') COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  status ENUM('draft','submitted','imported','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',\n" .
        "  title VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  pdf_path VARCHAR(1024) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  pdf_filename VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  pdf_sha256 CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n" .
        "  rcff_json LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  rcff_filename VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'report.rcff',\n" .
        "  metadata_json LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  expires_at DATETIME NOT NULL,\n" .
        "  submitted_to_admin_at DATETIME DEFAULT NULL,\n" .
        "  imported_template_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  imported_at DATETIME DEFAULT NULL,\n" .
        "  PRIMARY KEY (id),\n" .
        "  UNIQUE KEY uq_generated_template_packages_token (token),\n" .
        "  KEY idx_generated_template_packages_creator (created_by_user_id, status, created_at),\n" .
        "  KEY idx_generated_template_packages_status_expires (status, expires_at),\n" .
        "  KEY idx_generated_template_packages_imported (imported_template_id)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
}


function generated_template_package_pdf_absolute_path(string $pdfPath): string {
    $pdfPath = ltrim(str_replace('\\', '/', $pdfPath), '/');
    if ($pdfPath === '' || !preg_match('/\.pdf$/i', $pdfPath)) {
        throw new RuntimeException('Ungültiger Paket-PDF-Pfad.');
    }
    $uploadsRel = generated_template_package_uploads_rel();
    $expectedPrefix = $uploadsRel . '/generated_template_packages/';
    if (strncmp($pdfPath, $expectedPrefix, strlen($expectedPrefix)) !== 0 || str_contains($pdfPath, '..')) {
        throw new RuntimeException('Paket-PDF liegt nicht im erlaubten Speicherbereich.');
    }
    $root = realpath(__DIR__ . '/..');
    if (!$root) throw new RuntimeException('Projektwurzel konnte nicht ermittelt werden.');
    $abs = realpath($root . '/' . $pdfPath);
    $storageRoot = realpath(generated_template_package_storage_root());
    if (!$abs || !$storageRoot || !is_file($abs)) {
        throw new RuntimeException('Paket-PDF wurde nicht gefunden.');
    }
    $absNorm = str_replace('\\', '/', $abs);
    $rootNorm = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
    if (strncmp($absNorm, $rootNorm, strlen($rootNorm)) !== 0) {
        throw new RuntimeException('Paket-PDF liegt außerhalb des erlaubten Speicherbereichs.');
    }
    return $abs;
}

function generated_template_package_json_encode(array $data): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Template-Paket-JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
    }
    return $json;
}

function generated_template_package_categories(PDO $pdo, array $categoryIds): array {
    $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $v): bool => $v > 0)));
    if (!$categoryIds) return [];
    $in = implode(',', array_fill(0, count($categoryIds), '?'));
    $st = $pdo->prepare("SELECT id, name_de, name_en FROM competency_categories WHERE id IN ($in) ORDER BY sort_order, id");
    $st->execute($categoryIds);
    return array_map(static function (array $r): array {
        return [
            'id' => (int)($r['id'] ?? 0),
            'name_de' => (string)($r['name_de'] ?? ''),
            'name_en' => (string)($r['name_en'] ?? ''),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function generated_template_package_competencies(PDO $pdo, array $codes): array {
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static fn(string $v): bool => trim($v) !== '')));
    if (!$codes) return [];
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $pdo->prepare("SELECT id, code, text_de, text_en, COALESCE(display_type,'rated') AS display_type FROM competencies WHERE code IN ($in) ORDER BY sort_order, id");
    $st->execute($codes);
    return array_map(static function (array $r): array {
        return [
            'id' => (int)($r['id'] ?? 0),
            'code' => (string)($r['code'] ?? ''),
            'label_de' => (string)($r['text_de'] ?? ''),
            'label_en' => (string)($r['text_en'] ?? ''),
            'display_type' => (string)($r['display_type'] ?? 'rated'),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function normalize_generated_template_package_rcff(array $rcff, bool $studentTeacherRatings): array {
    foreach (($rcff['fields'] ?? []) as $idx => $field) {
        if (!is_array($field)) continue;
        if (($field['type'] ?? '') === 'rating') {
            $field['role'] = (string)($field['role'] ?? ($studentTeacherRatings ? 'teacher' : 'default'));
            $field['rating_values'] = array_values((array)($field['rating_values'] ?? []));
            $field['rating_mode'] = $studentTeacherRatings ? 'student_teacher' : 'standard';
            $rcff['fields'][$idx] = $field;
        }
    }
    return $rcff;
}

function build_generated_template_package(PDO $pdo, string $pdfBytes, array $rcffData, array $metadata, string $pdfFilename = 'vorlage.pdf', string $rcffFilename = 'report.rcff'): array {
    $user = function_exists('current_user') ? current_user() : null;
    if (!$user || (int)($user['id'] ?? 0) <= 0) {
        throw new RuntimeException('Nur angemeldete Nutzer dürfen Template-Pakete erzeugen.');
    }
    $role = function_exists('get_role') ? get_role() : (string)($user['role'] ?? '');
    if (!in_array($role, ['admin', 'teacher'], true)) {
        throw new RuntimeException('Unzulässige Rolle für Template-Paket.');
    }

    ensure_generated_template_packages_table($pdo);
    ensure_generated_template_package_storage_dir();

    $token = bin2hex(random_bytes(32));
    $storageRoot = generated_template_package_storage_root();
    $subdir = gmdate('Y/m') . '/' . $token;
    $absDir = $storageRoot . '/' . $subdir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0750, true)) {
        throw new RuntimeException('Template-Paket-Speicher konnte nicht angelegt werden.');
    }

    $safePdfFilename = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($pdfFilename)) ?: 'vorlage.pdf';
    if (!preg_match('/\.pdf$/i', $safePdfFilename)) {
        $safePdfFilename .= '.pdf';
    }
    $pdfAbs = $absDir . '/' . $safePdfFilename;
    if (@file_put_contents($pdfAbs, $pdfBytes, LOCK_EX) === false) {
        throw new RuntimeException('Template-Paket-PDF konnte nicht gespeichert werden.');
    }
    @chmod($pdfAbs, 0640);

    $uploadsRel = generated_template_package_uploads_rel();
    $pdfRel = $uploadsRel . '/generated_template_packages/' . $subdir . '/' . $safePdfFilename;
    $now = gmdate('c');
    $title = trim((string)($metadata['title'] ?? ''));
    if ($title === '') $title = 'Generierte Vorlage';

    $metadata = array_replace([
        'title' => $title,
        'source' => 'latex_templates',
        'created_by_user_id' => (int)$user['id'],
        'created_by_role' => $role,
        'created_at' => $now,
        'rcff_version' => (int)($rcffData['version'] ?? 1),
    ], $metadata);
    $metadata['created_by_user_id'] = (int)$user['id'];
    $metadata['created_by_role'] = $role;
    $metadata['created_at'] = (string)($metadata['created_at'] ?? $now);
    $metadata['pdf_filename'] = $safePdfFilename;
    $metadata['rcff_filename'] = $rcffFilename;
    $metadata['pdf_sha256'] = hash('sha256', $pdfBytes);

    $rcffJson = generated_template_package_json_encode($rcffData);
    $metadataJson = generated_template_package_json_encode($metadata);

    $st = $pdo->prepare(
        "INSERT INTO generated_template_packages " .
        "(token, created_by_user_id, created_by_role, status, title, pdf_path, pdf_filename, pdf_sha256, rcff_json, rcff_filename, metadata_json, created_at, expires_at) " .
        "VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY))"
    );
    $st->execute([
        $token,
        (int)$user['id'],
        $role,
        $title,
        $pdfRel,
        $safePdfFilename,
        $metadata['pdf_sha256'],
        $rcffJson,
        $rcffFilename,
        $metadataJson,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'token' => $token,
        'pdf_path' => $pdfRel,
        'pdf_filename' => $safePdfFilename,
        'rcff' => $rcffData,
        'rcff_filename' => $rcffFilename,
        'metadata' => $metadata,
        'status' => 'draft',
    ];
}
