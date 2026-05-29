<?php
declare(strict_types=1);

function latex_template_package_uploads_rel(): string {
    $cfg = app_config();
    $uploadsRel = trim((string)($cfg['app']['uploads_dir'] ?? 'uploads'), '/\\');
    return $uploadsRel !== '' ? $uploadsRel : 'uploads';
}

function latex_template_package_storage_root(): string {
    $base = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    return $base . '/' . latex_template_package_uploads_rel() . '/latex_template_packages';
}

function ensure_latex_template_package_storage_dir(): void {
    $dir = latex_template_package_storage_root();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('LaTeX-Paket-Speicher konnte nicht angelegt werden.');
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
}

function ensure_latex_template_packages_table(PDO $pdo): void {
    if (function_exists('db_has_table') && db_has_table($pdo, 'latex_template_packages')) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS latex_template_packages (\n" .
        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
        "  name VARCHAR(190) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  description TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n" .
        "  status ENUM('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',\n" .
        "  is_default TINYINT(1) NOT NULL DEFAULT 0,\n" .
        "  main_file VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main.tex',\n" .
        "  storage_path VARCHAR(1024) COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  manifest_json LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL,\n" .
        "  package_hash CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n" .
        "  version_label VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n" .
        "  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,\n" .
        "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
        "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
        "  deleted_at DATETIME DEFAULT NULL,\n" .
        "  PRIMARY KEY (id),\n" .
        "  KEY idx_latex_template_packages_status (status, is_default),\n" .
        "  KEY idx_latex_template_packages_deleted (deleted_at)\n" .
        ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
}

function latex_template_package_normalize_path(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        throw new RuntimeException('Ungültiger Pfad im LaTeX-Paket: ' . $path);
    }
    return $path;
}

function latex_template_package_allowed_extension(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['tex','sty','cls','png','jpg','jpeg','pdf','svg','ttf','otf','json','txt'], true);
}

function latex_template_package_abs_dir(string $storagePath): string {
    $storagePath = trim(str_replace('\\', '/', $storagePath), '/');
    $uploadsRel = latex_template_package_uploads_rel();
    $prefix = $uploadsRel . '/latex_template_packages/';
    if ($storagePath === '' || str_contains($storagePath, '..') || strncmp($storagePath, $prefix, strlen($prefix)) !== 0) {
        throw new RuntimeException('Ungültiger LaTeX-Paket-Speicherpfad.');
    }
    $root = realpath(__DIR__ . '/..');
    $storageRoot = realpath(latex_template_package_storage_root());
    if (!$root || !$storageRoot) throw new RuntimeException('LaTeX-Paket-Speicher nicht gefunden.');
    $abs = realpath($root . '/' . $storagePath);
    if (!$abs || !is_dir($abs)) throw new RuntimeException('LaTeX-Paketordner nicht gefunden.');
    $absNorm = rtrim(str_replace('\\', '/', $abs), '/') . '/';
    $rootNorm = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
    if (strncmp($absNorm, $rootNorm, strlen($rootNorm)) !== 0) {
        throw new RuntimeException('LaTeX-Paketordner liegt außerhalb des erlaubten Speicherbereichs.');
    }
    return rtrim($abs, '/\\');
}

function latex_template_package_json(array $data): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Manifest konnte nicht erzeugt werden: ' . json_last_error_msg());
    return $json;
}

function latex_template_package_import_zip(PDO $pdo, array $file, array $options): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP-Unterstützung (ZipArchive) ist nicht verfügbar.');
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('ZIP-Upload fehlgeschlagen.');
    $orig = (string)($file['name'] ?? '');
    if (!preg_match('/\.zip$/i', $orig)) throw new RuntimeException('Nur .zip-Dateien sind erlaubt.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 20 * 1024 * 1024) throw new RuntimeException('ZIP-Datei ist leer oder größer als 20 MB.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Ungültige Upload-Datei.');

    $name = trim((string)($options['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Name darf nicht leer sein.');
    $description = trim((string)($options['description'] ?? ''));
    $mainFile = latex_template_package_normalize_path((string)($options['main_file'] ?? 'main.tex'));
    if (strtolower(basename($mainFile)) === 'data.tex') throw new RuntimeException('data.tex kann nicht Hauptdatei sein.');
    if (!preg_match('/\.tex$/i', $mainFile)) throw new RuntimeException('Die Hauptdatei muss eine .tex-Datei sein.');
    $status = !empty($options['is_active']) ? 'active' : 'inactive';
    $setDefault = !empty($options['is_default']);

    ensure_latex_template_packages_table($pdo);
    ensure_latex_template_package_storage_dir();

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) throw new RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');

    $token = bin2hex(random_bytes(24));
    $subdir = gmdate('Y/m') . '/' . $token;
    $absDir = latex_template_package_storage_root() . '/' . $subdir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0750, true)) {
        $zip->close();
        throw new RuntimeException('LaTeX-Paketordner konnte nicht angelegt werden.');
    }

    $files = [];
    $warnings = [];
    $containsDataTex = false;
    $mainContent = null;
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $rawName = (string)($stat['name'] ?? '');
            if ($rawName === '' || str_ends_with($rawName, '/')) continue;
            $path = latex_template_package_normalize_path($rawName);
            if (basename($path) === '.htaccess') {
                $warnings[] = '.htaccess wurde ignoriert.';
                continue;
            }
            if (!latex_template_package_allowed_extension($path)) throw new RuntimeException('Nicht erlaubte Datei im ZIP: ' . $path);
            if ((int)($stat['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('Einzeldatei ist zu groß: ' . $path);
            $content = $zip->getFromIndex($i);
            if ($content === false) throw new RuntimeException('Datei konnte nicht aus ZIP gelesen werden: ' . $path);
            if (strtolower($path) === 'data.tex') {
                $containsDataTex = true;
                $warnings[] = 'data.tex im Paket wird beim Generieren vom System ersetzt.';
            }
            $dest = $absDir . '/' . $path;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0750, true)) throw new RuntimeException('Zielordner konnte nicht angelegt werden.');
            if (@file_put_contents($dest, $content, LOCK_EX) === false) throw new RuntimeException('Datei konnte nicht gespeichert werden: ' . $path);
            @chmod($dest, 0640);
            $files[] = ['path' => $path, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
            if ($path === $mainFile) $mainContent = $content;
        }
    } catch (Throwable $e) {
        $zip->close();
        latex_template_package_remove_dir($absDir);
        throw $e;
    }
    $zip->close();

    if ($mainContent === null) {
        latex_template_package_remove_dir($absDir);
        throw new RuntimeException('Hauptdatei wurde im ZIP nicht gefunden: ' . $mainFile);
    }
    $hasDataInput = (bool)preg_match('/\\(?:input|include)\s*\{\s*data(?:\.tex)?\s*\}/i', $mainContent);
    if (!$hasDataInput) $warnings[] = 'Hauptdatei enthält keinen erkennbaren \\input{data.tex}- oder \\include{data.tex}-Verweis.';

    $manifest = [
        'files' => $files,
        'main_file' => $mainFile,
        'contains_data_tex' => $containsDataTex,
        'has_data_input' => $hasDataInput,
        'warnings' => array_values(array_unique($warnings)),
    ];
    $storageRel = latex_template_package_uploads_rel() . '/latex_template_packages/' . $subdir;
    $hash = hash_file('sha256', $tmp) ?: null;

    $pdo->beginTransaction();
    if ($setDefault && $status === 'active') {
        $pdo->exec('UPDATE latex_template_packages SET is_default=0 WHERE deleted_at IS NULL');
    }
    $st = $pdo->prepare('INSERT INTO latex_template_packages (name, description, status, is_default, main_file, storage_path, manifest_json, package_hash, created_by_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $st->execute([$name, $description !== '' ? $description : null, $status, ($setDefault && $status === 'active') ? 1 : 0, $mainFile, $storageRel, latex_template_package_json($manifest), $hash, (int)((current_user() ?: [])['id'] ?? 0)]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    return ['id' => $id, 'manifest' => $manifest, 'warnings' => $manifest['warnings']];
}

function get_latex_template_packages(PDO $pdo, bool $activeOnly = false): array {
    ensure_latex_template_packages_table($pdo);
    $sql = "SELECT p.*, u.display_name AS created_by_name, u.email AS created_by_email FROM latex_template_packages p LEFT JOIN users u ON u.id=p.created_by_user_id WHERE p.deleted_at IS NULL";
    if ($activeOnly) $sql .= " AND p.status='active'";
    $sql .= " ORDER BY p.is_default DESC, p.created_at DESC, p.id DESC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function find_latex_template_package(PDO $pdo, int $id, bool $activeOnly = false): ?array {
    if ($id <= 0) return null;
    ensure_latex_template_packages_table($pdo);
    $sql = "SELECT * FROM latex_template_packages WHERE id=? AND deleted_at IS NULL" . ($activeOnly ? " AND status='active'" : '') . " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
}

function latex_template_package_files_as_resources(array $pkg): array {
    $dir = latex_template_package_abs_dir((string)($pkg['storage_path'] ?? ''));
    $manifest = json_decode((string)($pkg['manifest_json'] ?? '{}'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) throw new RuntimeException('LaTeX-Paketmanifest ist ungültig.');
    $mainFile = latex_template_package_normalize_path((string)($pkg['main_file'] ?? $manifest['main_file'] ?? 'main.tex'));
    $resources = [];
    $mainFound = false;
    foreach ($manifest['files'] as $file) {
        if (!is_array($file)) continue;
        $path = latex_template_package_normalize_path((string)($file['path'] ?? ''));
        $abs = realpath($dir . '/' . $path);
        if (!$abs || !is_file($abs)) throw new RuntimeException('Paketdatei fehlt: ' . $path);
        $dirNorm = rtrim(str_replace('\\', '/', realpath($dir) ?: $dir), '/') . '/';
        $absNorm = str_replace('\\', '/', $abs);
        if (strncmp($absNorm, $dirNorm, strlen($dirNorm)) !== 0) throw new RuntimeException('Paketdatei liegt außerhalb des Paketordners: ' . $path);
        if (strtolower($path) === 'data.tex') continue;
        $entry = ['path' => $path, 'file' => base64_encode((string)file_get_contents($abs))];
        if ($path === $mainFile) { $entry['main'] = true; $mainFound = true; }
        $resources[] = $entry;
    }
    if (!$mainFound) throw new RuntimeException('Hauptdatei des LaTeX-Pakets fehlt: ' . $mainFile);
    return $resources;
}

function latex_template_package_remove_dir(string $dir): void {
    $storageRoot = realpath(latex_template_package_storage_root());
    $real = realpath($dir);
    if (!$storageRoot || !$real) return;
    $rootNorm = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
    $realNorm = rtrim(str_replace('\\', '/', $real), '/') . '/';
    if (strncmp($realNorm, $rootNorm, strlen($rootNorm)) !== 0) return;
    $items = scandir($real) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $real . '/' . $item;
        if (is_link($path)) { @unlink($path); continue; }
        if (is_dir($path)) latex_template_package_remove_dir($path);
        else @unlink($path);
    }
    @rmdir($real);
}

function delete_latex_template_package(PDO $pdo, int $id): void {
    $pkg = find_latex_template_package($pdo, $id, false);
    if (!$pkg) throw new RuntimeException('LaTeX-Paket nicht gefunden.');
    if ((int)($pkg['is_default'] ?? 0) === 1) throw new RuntimeException('Standardpaket kann nicht gelöscht werden.');
    $dir = latex_template_package_abs_dir((string)$pkg['storage_path']);
    $pdo->prepare('UPDATE latex_template_packages SET deleted_at=NOW(), is_default=0, status=\'inactive\' WHERE id=?')->execute([$id]);
    latex_template_package_remove_dir($dir);
}
