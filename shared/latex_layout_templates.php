<?php
declare(strict_types=1);

function latex_layout_storage_dir(): string {
    $cfg = app_config();
    $uploadsRel = (string)($cfg['app']['uploads_dir'] ?? 'uploads');
    $base = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    return $base . '/' . trim($uploadsRel, '/\\') . '/latex_layouts';
}

function ensure_latex_layout_storage_dir(): void {
    $dir = latex_layout_storage_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Options -ExecCGI\nAddType text/plain .php .phtml .php3 .php4 .php5 .phar\n");
    }
}

function ensure_default_latex_layout_template(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM latex_layout_templates")->fetchColumn();
    if ($count > 0) return;
    $defaultPath = 'latex/layout.tex';
    $st = $pdo->prepare("INSERT INTO latex_layout_templates (key_name, display_name, file_path, is_default, is_active, created_at, updated_at) VALUES ('annual_report','Jahreszeugnis',?,1,1,NOW(),NOW())");
    $st->execute([$defaultPath]);
}


function ensure_default_layout_template_after_delete(PDO $pdo): ?int {
    $pdo->exec("UPDATE latex_layout_templates SET is_default=0 WHERE is_active<>1");
    $st = $pdo->query("SELECT id FROM latex_layout_templates WHERE is_active=1 ORDER BY id ASC LIMIT 1");
    $newDefaultId = (int)($st->fetchColumn() ?: 0);
    $pdo->exec("UPDATE latex_layout_templates SET is_default=0");
    if ($newDefaultId > 0) {
        $upd = $pdo->prepare("UPDATE latex_layout_templates SET is_default=1, updated_at=NOW() WHERE id=?");
        $upd->execute([$newDefaultId]);
        return $newDefaultId;
    }
    return null;
}

function get_latex_layout_templates(PDO $pdo, bool $onlyActive = false): array {
    $sql = "SELECT * FROM latex_layout_templates" . ($onlyActive ? " WHERE is_active=1" : "") . " ORDER BY is_default DESC, display_name ASC, id ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function get_default_latex_layout_template(PDO $pdo): ?array {
    $st = $pdo->query("SELECT * FROM latex_layout_templates WHERE is_default=1 AND is_active=1 ORDER BY id ASC LIMIT 1");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_active_latex_layout_template(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM latex_layout_templates WHERE id=? AND is_active=1 LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function latex_layout_absolute_path(string $filePath): string {
    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    return $root . '/' . ltrim(str_replace('..', '', $filePath), '/\\');
}
