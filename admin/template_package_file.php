<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require_admin();
require_once __DIR__ . '/../shared/generated_template_packages.php';

$packageId = (int)($_GET['package_id'] ?? 0);
if ($packageId <= 0) {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

try {
    $pdo = db();
    $st = $pdo->prepare('SELECT id, title, pdf_path, pdf_filename FROM generated_template_packages WHERE id=? LIMIT 1');
    $st->execute([$packageId]);
    $pkg = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pkg) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    $abs = generated_template_package_pdf_absolute_path((string)$pkg['pdf_path']);
    $filename = (string)($pkg['pdf_filename'] ?? 'vorlage.pdf');
    if (!preg_match('/\.pdf$/i', $filename)) $filename = 'vorlage.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $size = filesize($abs);
    if ($size !== false) header('Content-Length: ' . $size);
    readfile($abs);
} catch (Throwable $e) {
    http_response_code(403);
    echo 'Forbidden';
}
exit;
