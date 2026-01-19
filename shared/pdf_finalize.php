<?php
declare(strict_types=1);
// shared/pdf_finalize.php
// Finalize pipeline for filled PDFs: optional encryption and optional signing.

function finalize_pdf(string $pdfBytes, array $options): string {
  $encrypt = (bool)($options['encrypt'] ?? false);
  $sign = (bool)($options['sign'] ?? false);

  if (!$encrypt && !$sign) {
    return $pdfBytes;
  }

  if (!extension_loaded('openssl')) {
    throw new RuntimeException('OpenSSL fehlt.');
  }

  if (!class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi')) {
    throw new RuntimeException('PDF-Finalisierung erfordert TCPDF + FPDI (PHP-Library).');
  }

  $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);

  $stream = \setasign\Fpdi\PdfParser\StreamReader::createByString($pdfBytes);
  $pageCount = $pdf->setSourceFile($stream);
  for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
    $tplId = $pdf->importPage($pageNo);
    $size = $pdf->getTemplateSize($tplId);
    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    $pdf->useTemplate($tplId);
  }

  if ($encrypt) {
    $permissions = $options['permissions'] ?? [];
    $permList = [];
    if (!empty($permissions['modify'])) $permList[] = 'modify';
    if (!empty($permissions['copy'])) $permList[] = 'copy';
    if (!empty($permissions['annotate'])) $permList[] = 'annot-forms';
    if (!empty($permissions['fill'])) $permList[] = 'fill-forms';

    $print = (string)($permissions['print'] ?? 'high');
    if ($print === 'low') $permList[] = 'print';
    if ($print === 'high') $permList[] = 'print-high';

    $userPass = (string)($options['user_password'] ?? '');
    $ownerPass = (string)($options['owner_password'] ?? '');

    $pdf->SetProtection($permList, $userPass, $ownerPass, 0, null);
  }

  if ($sign) {
    $p12Path = getenv('PDF_SIGN_P12_PATH') ?: '';
    $p12Pass = getenv('PDF_SIGN_P12_PASS') ?: '';
    if ($p12Path === '' || !is_file($p12Path)) {
      throw new RuntimeException('Zertifikat nicht gefunden.');
    }

    $p12Data = file_get_contents($p12Path);
    if ($p12Data === false) {
      throw new RuntimeException('Zertifikat nicht lesbar.');
    }

    $certs = [];
    if (!openssl_pkcs12_read($p12Data, $certs, $p12Pass)) {
      throw new RuntimeException('Falsche p12 Passphrase.');
    }

    $signerName = (string)($options['signer_name'] ?? '');
    $signReason = (string)($options['sign_reason'] ?? '');
    $signLocation = (string)($options['sign_location'] ?? '');

    $pdf->setSignature($certs['cert'], $certs['pkey'], $p12Pass, '', 2, [
      'Name' => $signerName !== '' ? $signerName : null,
      'Reason' => $signReason !== '' ? $signReason : null,
      'Location' => $signLocation !== '' ? $signLocation : null,
    ]);

    if (!empty($options['sign_visible'])) {
      $margin = (int)($options['sign_margin'] ?? 12);
      $position = (string)($options['sign_position'] ?? 'bottom-right');
      $pageWidth = $pdf->getPageWidth();
      $pageHeight = $pdf->getPageHeight();
      $boxWidth = 60;
      $boxHeight = 20;

      $x = $margin;
      $y = $pageHeight - $boxHeight - $margin;
      if ($position === 'bottom-left') {
        $x = $margin;
        $y = $pageHeight - $boxHeight - $margin;
      } elseif ($position === 'top-left') {
        $x = $margin;
        $y = $margin;
      } elseif ($position === 'top-right') {
        $x = $pageWidth - $boxWidth - $margin;
        $y = $margin;
      } else {
        $x = $pageWidth - $boxWidth - $margin;
        $y = $pageHeight - $boxHeight - $margin;
      }

      $pdf->setSignatureAppearance($x, $y, $boxWidth, $boxHeight);
    }
  }

  return $pdf->Output('', 'S');
}
