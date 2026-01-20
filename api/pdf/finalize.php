<?php
declare(strict_types=1);

use setasign\Fpdi\Tcpdf\Fpdi;

require __DIR__ . '/../../bootstrap.php';

function pdf_read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function pdf_error(string $message, int $status = 400): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pdf_meta_read(?string $json): array {
  if (!$json) return [];
  $data = json_decode($json, true);
  return is_array($data) ? $data : [];
}

function pdf_normalize_rect($rect): ?array {
  if (is_string($rect)) {
    $decoded = json_decode($rect, true);
    if (is_array($decoded)) $rect = $decoded;
    if (is_string($rect)) {
      $parts = array_map('trim', explode(',', $rect));
      if (count($parts) >= 4) $rect = array_slice($parts, 0, 4);
    }
  }
  if (!is_array($rect) || count($rect) < 4) return null;
  $vals = array_map('floatval', array_slice($rect, 0, 4));
  if (count($vals) < 4) return null;
  return $vals;
}

function pdf_rect_to_tcpdf(array $rect, float $pageHeight): array {
  [$x1, $y1, $x2, $y2] = $rect;
  $left = min($x1, $x2);
  $right = max($x1, $x2);
  $bottom = min($y1, $y2);
  $top = max($y1, $y2);
  $width = $right - $left;
  $height = $top - $bottom;
  $x = $left;
  $y = $pageHeight - $top;
  return [$x, $y, $width, $height];
}

function pdf_option_list_id(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function pdf_load_option_list(PDO $pdo, int $listId): array {
  $st = $pdo->prepare("SELECT value, label, label_en FROM option_list_items WHERE list_id=? ORDER BY sort_order ASC, id ASC");
  $st->execute([$listId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pdf_load_field_options(PDO $pdo, array $field, array $meta): array {
  $listId = pdf_option_list_id($meta);
  if ($listId > 0) {
    return pdf_load_option_list($pdo, $listId);
  }
  if (isset($meta['options']) && is_array($meta['options'])) {
    return $meta['options'];
  }
  $optionsRaw = $field['options_json'] ?? null;
  if ($optionsRaw) {
    $decoded = json_decode((string)$optionsRaw, true);
    if (is_array($decoded)) return $decoded;
  }
  if ((string)($field['field_type'] ?? '') === 'grade') {
    return [
      ['value' => '1', 'label' => '1'],
      ['value' => '2', 'label' => '2'],
      ['value' => '3', 'label' => '3'],
      ['value' => '4', 'label' => '4'],
      ['value' => '5', 'label' => '5'],
      ['value' => '6', 'label' => '6'],
    ];
  }
  return [];
}

function pdf_resolve_option_value(PDO $pdo, array $meta, ?string $valueJson, ?string $valueText): string {
  $listId = pdf_option_list_id($meta);
  if ($listId <= 0) return (string)($valueText ?? '');
  $optId = 0;
  if ($valueJson) {
    $j = json_decode($valueJson, true);
    if (is_array($j) && isset($j['option_item_id'])) $optId = (int)$j['option_item_id'];
  }
  if ($optId > 0) {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$optId, $listId]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }
  $vt = trim((string)($valueText ?? ''));
  if ($vt !== '') {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE list_id=? AND value=? LIMIT 1");
    $st->execute([$listId, $vt]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }
  return (string)($valueText ?? '');
}

function pdf_load_values_for_report(PDO $pdo, int $reportInstanceId): array {
  $st = $pdo->prepare(
    "SELECT tf.field_name, tf.meta_json, fv.value_text, fv.value_json, fv.source, fv.updated_at
     FROM field_values fv
     JOIN template_fields tf ON tf.id=fv.template_field_id
     WHERE fv.report_instance_id=?
     ORDER BY fv.updated_at ASC, fv.id ASC"
  );
  $st->execute([$reportInstanceId]);
  $priority = ['child' => 1, 'system' => 2, 'teacher' => 3];
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $field = (string)($r['field_name'] ?? '');
    if ($field === '') continue;
    $src = (string)($r['source'] ?? 'teacher');
    $meta = pdf_meta_read($r['meta_json'] ?? null);
    $valueText = $r['value_text'] !== null ? (string)$r['value_text'] : null;
    $valueJson = $r['value_json'] !== null ? (string)$r['value_json'] : null;
    $resolved = pdf_resolve_option_value($pdo, $meta, $valueJson, $valueText);
    $current = $map[$field] ?? null;
    $currentScore = $current ? ($priority[$current['source']] ?? 0) : -1;
    $newScore = $priority[$src] ?? 0;
    $useNew = false;
    if ($newScore > $currentScore) {
      $useNew = true;
    } elseif ($newScore === $currentScore && $current) {
      $curTs = strtotime((string)($current['updated_at'] ?? '')) ?: 0;
      $newTs = strtotime((string)($r['updated_at'] ?? '')) ?: 0;
      if ($newTs >= $curTs) $useNew = true;
    }
    if ($useNew || !$current) {
      $map[$field] = [
        'value' => $resolved,
        'source' => $src,
        'updated_at' => (string)($r['updated_at'] ?? ''),
      ];
    }
  }
  return array_map(static fn($row) => (string)($row['value'] ?? ''), $map);
}

function pdf_is_class_field(array $meta): bool {
  if (isset($meta['scope']) && is_string($meta['scope']) && strtolower(trim($meta['scope'])) === 'class') return true;
  if (isset($meta['is_class_field']) && (int)$meta['is_class_field'] === 1) return true;
  return false;
}

function pdf_class_report_instance(PDO $pdo, int $templateId, int $classId, string $schoolYear): ?int {
  $periodLabel = class_report_period_label($classId);
  $st = $pdo->prepare(
    "SELECT id
     FROM report_instances
     WHERE template_id=? AND student_id IS NULL AND school_year=? AND period_label=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $schoolYear, $periodLabel]);
  $id = (int)($st->fetchColumn() ?: 0);
  return $id > 0 ? $id : null;
}

function pdf_find_report_instance(PDO $pdo, int $templateId, int $studentId, string $schoolYear): ?array {
  $st = $pdo->prepare(
    "SELECT id, template_id, student_id, school_year, period_label, status
     FROM report_instances
     WHERE template_id=? AND student_id=? AND school_year=? AND period_label='Standard'
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st->execute([$templateId, $studentId, $schoolYear]);
  $ri = $st->fetch(PDO::FETCH_ASSOC);
  if ($ri) return $ri;
  $st2 = $pdo->prepare(
    "SELECT id, template_id, student_id, school_year, period_label, status
     FROM report_instances
     WHERE template_id=? AND student_id=?
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
  );
  $st2->execute([$templateId, $studentId]);
  $ri2 = $st2->fetch(PDO::FETCH_ASSOC);
  return $ri2 ?: null;
}

function pdf_load_template_fields(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, is_required, is_multiline, meta_json, options_json
     FROM template_fields
     WHERE template_id=?
     ORDER BY sort_order ASC, id ASC"
  );
  $st->execute([$templateId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pdf_template_path(PDO $pdo, int $templateId): array {
  $st = $pdo->prepare("SELECT pdf_storage_path, pdf_original_filename FROM templates WHERE id=? LIMIT 1");
  $st->execute([$templateId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Vorlage nicht gefunden.');
  $rel = (string)($row['pdf_storage_path'] ?? '');
  $abs = realpath(__DIR__ . '/../../' . ltrim($rel, '/'));
  if (!$abs || !is_file($abs)) throw new RuntimeException('PDF-Vorlage nicht gefunden.');
  $uploadsDirRel = app_config()['app']['uploads_dir'] ?? 'uploads';
  $uploadsAbs = realpath(__DIR__ . '/../../' . $uploadsDirRel);
  if (!$uploadsAbs || !str_starts_with($abs, $uploadsAbs)) throw new RuntimeException('Zugriff auf PDF verweigert.');
  $filename = (string)($row['pdf_original_filename'] ?? 'template.pdf');
  if ($filename === '') $filename = 'template.pdf';
  return [$abs, $filename];
}

function pdf_bool_checked($val): bool {
  if (is_bool($val)) return $val;
  $v = strtolower(trim((string)$val));
  return in_array($v, ['1', 'ja', 'yes', 'true', 'x'], true);
}

function pdf_draw_box_with_x(Fpdi $pdf, float $x, float $y, float $w, float $h, bool $checked): void {
  $size = min($w, $h);
  $pdf->Rect($x, $y, $size, $size);
  if ($checked) {
    $pad = max(1.5, $size * 0.18);
    $pdf->Line($x + $pad, $y + $pad, $x + $size - $pad, $y + $size - $pad);
    $pdf->Line($x + $pad, $y + $size - $pad, $x + $size - $pad, $y + $pad);
  }
}

function pdf_norm_str(string $value): string {
  return strtolower(trim($value));
}

function pdf_radio_matches(string $selected, array $option): bool {
  $sel = pdf_norm_str($selected);
  if ($sel === '') return false;
  $value = pdf_norm_str((string)($option['value'] ?? ''));
  $label = pdf_norm_str((string)($option['label'] ?? ''));
  $labelEn = pdf_norm_str((string)($option['label_en'] ?? ''));
  if ($value !== '' && $sel === $value) return true;
  if ($label !== '' && $sel === $label) return true;
  if ($labelEn !== '' && $sel === $labelEn) return true;
  return false;
}

function pdf_layout_radio_positions(array $rect, int $count): array {
  if ($count <= 0) return [];
  [$x, $y, $w, $h] = $rect;
  $columns = min(4, max(1, $count));
  $rows = (int)ceil($count / $columns);
  $cellW = $w / $columns;
  $cellH = $h / $rows;
  $positions = [];
  for ($i = 0; $i < $count; $i++) {
    $col = $i % $columns;
    $row = (int)floor($i / $columns);
    $boxSize = min(12.0, max(8.0, min($cellW, $cellH) * 0.6));
    $boxX = $x + ($col * $cellW) + 2;
    $boxY = $y + ($row * $cellH) + max(1.0, ($cellH - $boxSize) / 2);
    $positions[] = [$boxX, $boxY, $boxSize, $boxSize];
  }
  return $positions;
}

function pdf_draw_text(Fpdi $pdf, string $text, array $rect, bool $multiline): void {
  [$x, $y, $w, $h] = $rect;
  $fontSize = max(8.0, min(12.0, $h * 0.6));
  $pdf->SetFont('helvetica', '', $fontSize);
  $pdf->SetTextColor(0, 0, 0);
  if ($multiline) {
    $pdf->MultiCell($w, $h, $text, 0, 'L', false, 1, $x, $y, true, 0, false, true, $h, 'T', true);
  } else {
    $pdf->MultiCell($w, $h, $text, 0, 'L', false, 1, $x, $y, true, 0, false, true, $h, 'M', true);
  }
}

function pdf_apply_encryption(Fpdi $pdf, array $options): void {
  if (empty($options['encrypt']['enabled'])) return;
  $encrypt = $options['encrypt'];
  $userPassword = isset($encrypt['userPassword']) ? (string)$encrypt['userPassword'] : '';
  $ownerPassword = isset($encrypt['ownerPassword']) ? (string)$encrypt['ownerPassword'] : '';
  if ($ownerPassword === '') {
    $ownerPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
  }
  $permissions = [];
  $perms = $encrypt['permissions'] ?? [];
  if (is_array($perms)) {
    foreach ($perms as $perm) {
      $p = strtolower(trim((string)$perm));
      if ($p !== '') $permissions[] = $p;
    }
  }
  if (!$permissions) $permissions = ['print', 'copy'];
  $pdf->SetProtection($permissions, $userPassword, $ownerPassword);
}

function pdf_apply_signature(Fpdi $pdf, array $options, int $pageCount, array $pageSizes): void {
  if (empty($options['sign']['enabled'])) return;
  $sign = $options['sign'];
  $p12Path = getenv('PDF_SIGN_P12_PATH') ?: '';
  $p12Pass = getenv('PDF_SIGN_P12_PASS') ?: '';
  if ($p12Path === '' || !is_file($p12Path)) {
    throw new RuntimeException('Signaturzertifikat nicht gefunden.');
  }
  if (!function_exists('openssl_pkcs12_read')) {
    throw new RuntimeException('OpenSSL-Unterstützung fehlt für Signatur.');
  }
  $certs = [];
  $p12 = file_get_contents($p12Path);
  if ($p12 === false || !openssl_pkcs12_read($p12, $certs, $p12Pass)) {
    throw new RuntimeException('Signaturzertifikat konnte nicht geladen werden.');
  }
  $info = [
    'Name' => (string)($sign['name'] ?? ''),
    'Location' => (string)($sign['location'] ?? ''),
    'Reason' => (string)($sign['reason'] ?? ''),
    'ContactInfo' => (string)($sign['contact'] ?? ''),
  ];
  $pdf->setSignature($certs['cert'] ?? '', $certs['pkey'] ?? '', $p12Pass, '', 2, $info);
  if (!empty($sign['visible']) && method_exists($pdf, 'setSignatureAppearance')) {
    $page = max(1, $pageCount);
    $size = $pageSizes[$page] ?? ['w' => 595.0, 'h' => 842.0];
    $margin = isset($sign['margin']) ? (float)$sign['margin'] : 18.0;
    $boxW = isset($sign['width']) ? (float)$sign['width'] : 160.0;
    $boxH = isset($sign['height']) ? (float)$sign['height'] : 60.0;
    $corner = strtolower((string)($sign['position'] ?? 'bottom-right'));
    $x = $margin;
    $y = $margin;
    if ($corner === 'bottom-right') {
      $x = $size['w'] - $boxW - $margin;
      $y = $size['h'] - $boxH - $margin;
    } elseif ($corner === 'top-right') {
      $x = $size['w'] - $boxW - $margin;
      $y = $margin;
    } elseif ($corner === 'top-left') {
      $x = $margin;
      $y = $margin;
    } elseif ($corner === 'bottom-left') {
      $x = $margin;
      $y = $size['h'] - $boxH - $margin;
    }
    $pdf->setPage($page);
    $pdf->setSignatureAppearance($x, $y, $boxW, $boxH);
  }
}

function pdf_new_document(): Fpdi {
  $pdf = new Fpdi('P', 'pt');
  $pdf->SetCreator('leb_tool');
  $pdf->SetAuthor('leb_tool');
  $pdf->SetAutoPageBreak(false);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);
  return $pdf;
}

function pdf_add_template_pages(Fpdi $pdf, string $templatePath, array $fieldsByPage, array $values): array {
  $pageSizes = [];
  $pageCount = $pdf->setSourceFile($templatePath);
  for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
    $tpl = $pdf->importPage($pageNo);
    $size = $pdf->getTemplateSize($tpl);
    $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
    $pdf->useTemplate($tpl);
    $pageSizes[$pageNo] = ['w' => $size['width'], 'h' => $size['height']];
    $fields = $fieldsByPage[$pageNo] ?? [];
    if ($fields) {
      foreach ($fields as $field) {
        $value = $values[$field['name']] ?? '';
        $type = $field['type'];
        [$x, $y, $w, $h] = pdf_rect_to_tcpdf($field['rect'], $size['height']);
        if ($type === 'checkbox') {
          pdf_draw_box_with_x($pdf, $x, $y, $w, $h, pdf_bool_checked($value));
        } else {
          $options = $field['options'] ?? [];
          $optionCount = is_array($options) ? count($options) : 0;
          $radioLike = ($type === 'radio') || ($type === 'select' && $optionCount > 0 && $optionCount <= 10);
          if ($radioLike) {
            $positions = pdf_layout_radio_positions([$x, $y, $w, $h], $optionCount);
            $selected = (string)$value;
            $didDraw = false;
            foreach ($options as $idx => $opt) {
              $pos = $positions[$idx] ?? null;
              if (!$pos) continue;
              if (pdf_radio_matches($selected, $opt)) {
                pdf_draw_box_with_x($pdf, $pos[0], $pos[1], $pos[2], $pos[3], true);
                $didDraw = true;
              }
            }
            if (!$didDraw && trim($selected) !== '') {
              if ($positions) {
                $pos = $positions[0];
                pdf_draw_box_with_x($pdf, $pos[0], $pos[1], $pos[2], $pos[3], true);
              } else {
                pdf_draw_box_with_x($pdf, $x, $y, $w, $h, true);
              }
            }
          } elseif ($type === 'select' || $type === 'grade') {
          pdf_draw_text($pdf, (string)$value, [$x, $y, $w, $h], false);
          } elseif ($type === 'signature') {
            pdf_draw_text($pdf, (string)$value, [$x, $y, $w, $h], false);
          } else {
            $multiline = (bool)$field['multiline'];
            pdf_draw_text($pdf, (string)$value, [$x, $y, $w, $h], $multiline);
          }
        }
      }
    }
  }
  return $pageSizes;
}

try {
  require __DIR__ . '/../../lib/pdf_vendor.php';
  $data = pdf_read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $mode = (string)($data['mode'] ?? 'single');
  $mode = in_array($mode, ['single', 'merged', 'zip'], true) ? $mode : 'single';
  $options = is_array($data['options'] ?? null) ? (array)$data['options'] : [];

  $token = trim((string)($data['token'] ?? ''));
  $context = $token !== '' ? 'parent' : 'teacher';

  $templateId = 0;
  $schoolYear = '';
  $classId = 0;
  $students = [];
  $fields = [];
  $filenameBase = 'export';
  $templatePath = '';
  $templateFilename = '';

  if ($context === 'parent') {
    $st = $pdo->prepare(
      "SELECT ppl.*, s.first_name, s.last_name, c.id AS class_id, c.school_year, c.grade_level, c.label, c.name AS class_name,
              ri.template_id, ri.period_label, ri.school_year AS report_school_year
       FROM parent_portal_links ppl
       JOIN students s ON s.id=ppl.student_id
       JOIN report_instances ri ON ri.id=ppl.report_instance_id
       JOIN classes c ON c.id=s.class_id
       WHERE ppl.token=?
       LIMIT 1"
    );
    $st->execute([$token]);
    $link = $st->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
      pdf_error('Freigabe nicht gefunden.', 404);
    }
    $expiresAt = $link['expires_at'] ?? null;
    if ($expiresAt && strtotime((string)$expiresAt) < time()) {
      pdf_error('Freigabe ist abgelaufen.', 403);
    }
    if ((string)($link['status'] ?? '') !== 'approved') {
      pdf_error('Freigabe ist nicht aktiv.', 403);
    }
    $templateId = (int)($link['template_id'] ?? 0);
    $classId = (int)($link['class_id'] ?? 0);
    $schoolYear = (string)($link['report_school_year'] ?? '');
    $students[] = [
      'id' => (int)($link['student_id'] ?? 0),
      'name' => trim((string)($link['first_name'] ?? '') . ' ' . (string)($link['last_name'] ?? '')),
      'report_instance_id' => (int)($link['report_instance_id'] ?? 0),
    ];
    $filenameBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($link['first_name'] ?? '')) . '.pdf';
  } else {
    require_teacher();
    $u = current_user();
    $userId = (int)($u['id'] ?? 0);
    $classId = (int)($data['class_id'] ?? 0);
    if ($classId <= 0) throw new RuntimeException('class_id fehlt.');
    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      pdf_error('Keine Berechtigung.', 403);
    }
    $row = $pdo->prepare(
      "SELECT c.id AS class_id, c.school_year, c.grade_level, c.label, c.name AS class_name,
              t.id AS template_id, t.name AS template_name, t.template_version
       FROM classes c
       LEFT JOIN templates t ON t.id=c.template_id
       WHERE c.id=?
       LIMIT 1"
    );
    $row->execute([$classId]);
    $classRow = $row->fetch(PDO::FETCH_ASSOC);
    if (!$classRow || (int)($classRow['template_id'] ?? 0) <= 0) {
      throw new RuntimeException('Für diese Klasse wurde keine Vorlage zugeordnet.');
    }
    $templateId = (int)$classRow['template_id'];
    $schoolYear = (string)($classRow['school_year'] ?? '');
    $onlySubmitted = (int)($data['only_submitted'] ?? 0) === 1;
    $onlyStudentId = isset($data['student_id']) ? (int)$data['student_id'] : null;
    $whereStudent = '';
    if ($onlyStudentId && $onlyStudentId > 0) $whereStudent = " AND s.id=? ";
    $whereSubmitted = $onlySubmitted ? " AND ri.status='submitted' " : "";
    $sql =
      "SELECT s.id, s.first_name, s.last_name, ri.id AS report_instance_id
       FROM students s
       LEFT JOIN report_instances ri
         ON ri.student_id=s.id
        AND ri.template_id=?
        AND ri.school_year=?
        AND ri.period_label='Standard'
       WHERE s.class_id=? AND s.is_active=1
       $whereStudent
       $whereSubmitted
       ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC";
    $stmt = $pdo->prepare($sql);
    $params = [$templateId, $schoolYear, $classId];
    if ($onlyStudentId && $onlyStudentId > 0) $params[] = $onlyStudentId;
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$students) throw new RuntimeException('Keine Schüler gefunden.');
    $classDisplay = (string)($classRow['class_name'] ?? '');
    $filenameBase = trim($classDisplay . ' ' . $schoolYear);
  }

  if ($templateId <= 0) throw new RuntimeException('Template fehlt.');
  [$templatePath, $templateFilename] = pdf_template_path($pdo, $templateId);

  $templateFields = pdf_load_template_fields($pdo, $templateId);
  $fieldsByPage = [];
  $classFieldNames = [];
  foreach ($templateFields as $field) {
    $name = (string)($field['field_name'] ?? '');
    if ($name === '') continue;
    $meta = pdf_meta_read($field['meta_json'] ?? null);
    $page = isset($meta['page']) ? (int)$meta['page'] : null;
    $rect = pdf_normalize_rect($meta['rect'] ?? null);
    if (!$page || !$rect) continue;
    if (pdf_is_class_field($meta)) $classFieldNames[$name] = true;
    $options = pdf_load_field_options($pdo, $field, $meta);
    $fieldsByPage[$page][] = [
      'name' => $name,
      'type' => (string)($field['field_type'] ?? 'text'),
      'rect' => $rect,
      'multiline' => (int)($field['is_multiline'] ?? 0) === 1,
      'options' => $options,
    ];
  }

  $classValues = [];
  if ($classFieldNames) {
    $classRi = pdf_class_report_instance($pdo, $templateId, $classId, $schoolYear);
    if ($classRi) {
      $classValues = pdf_load_values_for_report($pdo, $classRi);
    }
  }

  if ($mode === 'merged') {
    $pdf = pdf_new_document();
    $pageSizes = [];
    foreach ($students as $student) {
      $studentId = (int)($student['id'] ?? 0);
      $reportId = (int)($student['report_instance_id'] ?? 0);
      $values = [];
      if ($reportId > 0) {
        if (function_exists('apply_system_bindings')) {
          apply_system_bindings($pdo, $reportId);
        }
        $values = pdf_load_values_for_report($pdo, $reportId);
      }
      foreach ($classFieldNames as $fname => $_) {
        if (array_key_exists($fname, $classValues)) {
          $values[$fname] = (string)$classValues[$fname];
        }
      }
      $pageSizes = pdf_add_template_pages($pdf, $templatePath, $fieldsByPage, $values);
    }
    pdf_apply_encryption($pdf, $options);
    pdf_apply_signature($pdf, $options, count($pageSizes), $pageSizes);
    $out = $pdf->Output('', 'S');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filenameBase ?: 'export') . '.pdf"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-cache');
    echo $out;
    exit;
  }

  if ($mode === 'zip') {
    $zip = new ZipArchive();
    $tmpZip = tempnam(sys_get_temp_dir(), 'leb_zip_');
    if ($tmpZip === false) throw new RuntimeException('ZIP konnte nicht erstellt werden.');
    if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) throw new RuntimeException('ZIP konnte nicht geöffnet werden.');
    foreach ($students as $student) {
      $studentId = (int)($student['id'] ?? 0);
      $reportId = (int)($student['report_instance_id'] ?? 0);
      $values = [];
      if ($reportId > 0) {
        if (function_exists('apply_system_bindings')) {
          apply_system_bindings($pdo, $reportId);
        }
        $values = pdf_load_values_for_report($pdo, $reportId);
      }
      foreach ($classFieldNames as $fname => $_) {
        if (array_key_exists($fname, $classValues)) {
          $values[$fname] = (string)$classValues[$fname];
        }
      }
      $pdf = pdf_new_document();
      $pageSizes = pdf_add_template_pages($pdf, $templatePath, $fieldsByPage, $values);
      pdf_apply_encryption($pdf, $options);
      pdf_apply_signature($pdf, $options, count($pageSizes), $pageSizes);
      $out = $pdf->Output('', 'S');
      $name = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
      if ($name === '') $name = 'Schueler-' . $studentId;
      $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
      if ($safe === '') $safe = 'Schueler-' . $studentId;
      $zip->addFromString($safe . '.pdf', $out);
    }
    $zip->close();
    $zipData = file_get_contents($tmpZip);
    if ($zipData === false) throw new RuntimeException('ZIP konnte nicht gelesen werden.');
    @unlink($tmpZip);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filenameBase ?: 'export') . '.zip"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-cache');
    echo $zipData;
    exit;
  }

  $student = $students[0] ?? null;
  if (!$student) throw new RuntimeException('Kein Schüler gefunden.');
  $reportId = (int)($student['report_instance_id'] ?? 0);
  $values = [];
  if ($reportId > 0) {
    if (function_exists('apply_system_bindings')) {
      apply_system_bindings($pdo, $reportId);
    }
    $values = pdf_load_values_for_report($pdo, $reportId);
  }
  foreach ($classFieldNames as $fname => $_) {
    if (array_key_exists($fname, $classValues)) {
      $values[$fname] = (string)$classValues[$fname];
    }
  }
  $pdf = pdf_new_document();
  $pageSizes = pdf_add_template_pages($pdf, $templatePath, $fieldsByPage, $values);
  pdf_apply_encryption($pdf, $options);
  pdf_apply_signature($pdf, $options, count($pageSizes), $pageSizes);
  $out = $pdf->Output('', 'S');
  $downloadName = (string)($data['download_name'] ?? '');
  if ($downloadName === '') $downloadName = $templateFilename ?: 'export.pdf';
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, max-age=0, no-cache');
  echo $out;
  exit;
} catch (Throwable $e) {
  pdf_error($e->getMessage(), 400);
}
