<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_teacher();

$embedUrl = ai_help_embed_url();
if ($embedUrl === '') {
  http_response_code(404);
  exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');

$publicUrl = ai_help_public_url($embedUrl);
$title = t('ai_help.title', 'LEB Tool Hilfe');
$privacyHint = t('ai_help.privacy_hint', 'Bitte keine personenbezogenen Daten eingeben.');
$externalLabel = t('ai_help.open_external', 'In neuem Fenster öffnen');
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
</head>
<body class="ai-help-window">
  <header class="ai-help-window-header">
    <div class="ai-help-heading">
      <strong><?=h($title)?></strong>
      <span><?=h($privacyHint)?></span>
    </div>
    <?php if ($publicUrl !== ''): ?>
      <a class="ai-help-window-external" href="<?=h($publicUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="<?=h($externalLabel)?>" title="<?=h($externalLabel)?>">↗</a>
    <?php endif; ?>
  </header>
  <iframe
    class="ai-help-window-frame"
    title="<?=h($title)?>"
    src="<?=h($embedUrl)?>"
    referrerpolicy="no-referrer"
    allow="fullscreen; clipboard-write"
  ></iframe>
  <script>window.name = 'lebToolHelp';</script>
</body>
</html>
