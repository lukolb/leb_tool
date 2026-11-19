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

$title = t('ai_help.title', 'LEB Tool Hilfe');
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
</head>
<body class="ai-help-embed-page">
  <iframe
    class="ai-help-embed-frame"
    title="<?=h($title)?>"
    src="<?=h($embedUrl)?>"
    referrerpolicy="no-referrer"
    allow="fullscreen; clipboard-write"
  ></iframe>
</body>
</html>
