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

function help_workspace_default_page(): string {
  return url(get_role() === 'admin' ? 'admin/index.php' : 'teacher/index.php');
}

function help_workspace_page(string $raw): string {
  return ai_help_normalize_workspace_page($raw, help_workspace_default_page(), APP_BASE_URL);
}

$pageUrl = help_workspace_page((string)($_GET['page'] ?? ''));
$publicUrl = ai_help_public_url($embedUrl);
$title = t('ai_help.title', 'LEB Tool Hilfe');
$privacyHint = t('ai_help.privacy_hint', 'Bitte keine personenbezogenen Daten eingeben.');
$externalLabel = t('ai_help.open_external', 'In neuem Fenster öffnen');
$minimizeLabel = t('ai_help.minimize', 'KI-Hilfe minimieren');
$restoreLabel = t('ai_help.restore', 'KI-Hilfe wieder öffnen');
$closeLabel = t('ai_help.close', 'KI-Hilfe schließen');
$resizeLabel = t('ai_help.resize', 'Breite der KI-Hilfe anpassen');
$appFrameTitle = t('ai_help.app_frame', 'LEB Tool Arbeitsbereich');
$brand = brand();
$primary = (string)($brand['primary'] ?? '#0b57d0');
$secondary = (string)($brand['secondary'] ?? '#111111');
?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>:root{--primary:<?=h($primary)?>;--secondary:<?=h($secondary)?>;}</style>
</head>
<body class="help-workspace-page">
  <div class="help-workspace" id="helpWorkspace">
    <main class="help-workspace-app">
      <iframe id="helpWorkspaceAppFrame" src="<?=h($pageUrl)?>" title="<?=h($appFrameTitle)?>"></iframe>
    </main>

    <aside class="help-workspace-chat" id="helpWorkspaceChat" aria-label="<?=h($title)?>">
      <div
        class="help-workspace-resizer"
        id="helpWorkspaceResizer"
        role="separator"
        tabindex="0"
        aria-orientation="vertical"
        aria-label="<?=h($resizeLabel)?>"
        aria-valuemin="300"
        aria-valuemax="760"
        aria-valuenow="430"
      ></div>
      <header class="help-workspace-chat-header">
        <div class="ai-help-heading">
          <strong><?=h($title)?></strong>
          <span><?=h($privacyHint)?></span>
        </div>
        <div class="help-workspace-actions">
          <?php if ($publicUrl !== ''): ?>
            <a href="<?=h($publicUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="<?=h($externalLabel)?>" title="<?=h($externalLabel)?>">↗</a>
          <?php endif; ?>
          <button type="button" id="helpWorkspaceMinimize" aria-label="<?=h($minimizeLabel)?>" title="<?=h($minimizeLabel)?>">−</button>
          <button type="button" id="helpWorkspaceClose" aria-label="<?=h($closeLabel)?>" title="<?=h($closeLabel)?>">×</button>
        </div>
      </header>
      <iframe
        class="help-workspace-chat-frame"
        src="<?=h(url('help_assistant.php'))?>"
        title="<?=h($title)?>"
        allow="fullscreen; clipboard-write"
      ></iframe>
      <button class="help-workspace-restore" type="button" id="helpWorkspaceRestore" aria-label="<?=h($restoreLabel)?>" title="<?=h($restoreLabel)?>">?</button>
    </aside>
  </div>

  <script>
    (() => {
      const workspace = document.getElementById('helpWorkspace');
      const appFrame = document.getElementById('helpWorkspaceAppFrame');
      const chat = document.getElementById('helpWorkspaceChat');
      const resizer = document.getElementById('helpWorkspaceResizer');
      const minimize = document.getElementById('helpWorkspaceMinimize');
      const restore = document.getElementById('helpWorkspaceRestore');
      const close = document.getElementById('helpWorkspaceClose');
      if (!workspace || !appFrame || !chat || !resizer || !minimize || !restore || !close) return;

      const workspaceUrl = <?=json_encode(url('help_workspace.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
      const widthKey = 'lebAiHelpDockWidth';
      let currentAppUrl = <?=json_encode($pageUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;

      const widthLimits = () => {
        const min = Math.min(300, window.innerWidth);
        const max = window.innerWidth <= 760
          ? window.innerWidth
          : Math.max(min, Math.min(760, window.innerWidth - 320));
        return { min, max };
      };
      const setWidth = (requested, persist = true) => {
        const limits = widthLimits();
        const width = Math.round(Math.max(limits.min, Math.min(limits.max, Number(requested) || 430)));
        workspace.style.setProperty('--help-dock-width', `${width}px`);
        resizer.setAttribute('aria-valuemin', String(limits.min));
        resizer.setAttribute('aria-valuemax', String(limits.max));
        resizer.setAttribute('aria-valuenow', String(width));
        if (persist) {
          try { localStorage.setItem(widthKey, String(width)); } catch (error) {}
        }
        return width;
      };

      let savedWidth = 430;
      try { savedWidth = Number(localStorage.getItem(widthKey)) || 430; } catch (error) {}
      setWidth(savedWidth, false);

      const setMinimized = (value) => {
        workspace.classList.toggle('is-help-minimized', value);
        minimize.setAttribute('aria-expanded', value ? 'false' : 'true');
        restore.hidden = !value;
        if (value) restore.focus();
      };
      minimize.addEventListener('click', () => setMinimized(true));
      restore.addEventListener('click', () => {
        setMinimized(false);
        minimize.focus();
      });

      const readAppUrl = () => {
        try {
          const location = appFrame.contentWindow.location;
          if (location.origin !== window.location.origin) return currentAppUrl;
          return location.pathname + location.search + location.hash;
        } catch (error) {
          return currentAppUrl;
        }
      };
      appFrame.addEventListener('load', () => {
        currentAppUrl = readAppUrl();
        try {
          const current = new URL(currentAppUrl, window.location.origin);
          if (current.pathname.endsWith('/login.php') || current.pathname.endsWith('/student/login.php')) {
            window.location.replace(currentAppUrl);
            return;
          }
          const nextWorkspaceUrl = `${workspaceUrl}?page=${encodeURIComponent(currentAppUrl)}`;
          window.history.replaceState(null, '', nextWorkspaceUrl);
          const innerTitle = appFrame.contentDocument && appFrame.contentDocument.title;
          if (innerTitle) document.title = `${innerTitle} · ${<?=json_encode($title, JSON_UNESCAPED_UNICODE)?>}`;
        } catch (error) {}
      });

      close.addEventListener('click', () => window.location.assign(readAppUrl()));

      let drag = null;
      resizer.addEventListener('pointerdown', (event) => {
        if (workspace.classList.contains('is-help-minimized')) return;
        drag = { x: event.clientX, width: chat.getBoundingClientRect().width };
        resizer.setPointerCapture(event.pointerId);
        document.body.classList.add('is-help-resizing');
        event.preventDefault();
      });
      resizer.addEventListener('pointermove', (event) => {
        if (!drag) return;
        setWidth(drag.width + drag.x - event.clientX, false);
      });
      const stopResize = () => {
        if (!drag) return;
        drag = null;
        document.body.classList.remove('is-help-resizing');
        setWidth(parseFloat(getComputedStyle(workspace).getPropertyValue('--help-dock-width')) || 430, true);
      };
      resizer.addEventListener('pointerup', stopResize);
      resizer.addEventListener('pointercancel', stopResize);
      resizer.addEventListener('dblclick', () => setWidth(430, true));
      resizer.addEventListener('keydown', (event) => {
        const current = parseFloat(getComputedStyle(workspace).getPropertyValue('--help-dock-width')) || 430;
        if (event.key === 'ArrowLeft') setWidth(current + 24, true);
        else if (event.key === 'ArrowRight') setWidth(current - 24, true);
        else if (event.key === 'Home') setWidth(widthLimits().max, true);
        else if (event.key === 'End') setWidth(widthLimits().min, true);
        else return;
        event.preventDefault();
      });
      window.addEventListener('resize', () => setWidth(parseFloat(getComputedStyle(workspace).getPropertyValue('--help-dock-width')) || 430, false));
    })();
  </script>
</body>
</html>
