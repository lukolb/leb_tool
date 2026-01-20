<?php
declare(strict_types=1);

require_once __DIR__ . '/../assets/tcpdf/tcpdf.php';
require_once __DIR__ . '/../assets/fpdi/autoload.php';

if (!class_exists('TCPDF')) {
  throw new RuntimeException('TCPDF ist nicht verfügbar. Bitte /assets/tcpdf installieren.');
}
if (!class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi')) {
  throw new RuntimeException('FPDI ist nicht verfügbar. Bitte /assets/fpdi installieren.');
}
