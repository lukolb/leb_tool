<?php
declare(strict_types=1);

require_once __DIR__ . '/tcpdf/tcpdf.php';
require_once __DIR__ . '/fpdi/src/autoload.php';

if (!class_exists('TCPDF')) {
  throw new RuntimeException('TCPDF ist nicht verfügbar. Bitte /lib/tcpdf installieren.');
}
if (!class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi')) {
  throw new RuntimeException('FPDI ist nicht verfügbar. Bitte /lib/fpdi installieren.');
}
