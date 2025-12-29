<?php
// admin/settings.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$cfgPath = __DIR__ . '/../config.php';
$cfg = app_config();

$err = '';
$ok  = '';

function child_intro_file_abs(): string {
  $cfg = app_config();
  $uploadsRel = (string)($cfg['app']['uploads_dir'] ?? 'uploads');
  $rootAbs = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
  return rtrim($rootAbs, '/\\') . '/' . trim($uploadsRel, '/\\') . '/child_intro.html';
}

function sanitize_intro_html(string $html): string {
  // Keep it simple: remove scripts
  $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
  return trim($html);
}


function parse_group_title_overrides_from_post(array $keys, array $titles): array {
  $out = [];
  $n = max(count($keys), count($titles));
  for ($i = 0; $i < $n; $i++) {
    $k = trim((string)($keys[$i] ?? ''));
    $t = trim((string)($titles[$i] ?? ''));
    if ($k === '' || $t === '') continue;
    // prevent duplicates; last one wins
    $out[$k] = $t;
  }
  return $out;
}

function known_intro_placeholders(): array {
  return [
    '{{org_name}}'      => 'Schule/Organisation',
    '{{student_name}}'  => 'Schüler:in (Vorname Nachname)',
    '{{first_name}}'    => 'Vorname',
    '{{last_name}}'     => 'Nachname',
    '{{class}}'         => 'Klasse (z.B. 4A)',
    '{{school_year}}'   => 'Schuljahr (z.B. 2025/26)',
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';

    // ---- Branding ----
    $brand = $cfg['app']['brand'] ?? [];
    $brand['org_name'] = trim((string)($_POST['org_name'] ?? ($brand['org_name'] ?? 'LEB Tool')));

    $primary = trim((string)($_POST['brand_primary'] ?? ($brand['primary'] ?? '#0b57d0')));
    $secondary = trim((string)($_POST['brand_secondary'] ?? ($brand['secondary'] ?? '#111111')));

    $brand['primary'] = $primary;
    $brand['secondary'] = $secondary;

    $defaultSY = trim((string)($_POST['default_school_year'] ?? ($cfg['app']['default_school_year'] ?? '')));

    if ($brand['org_name'] === '') throw new RuntimeException('Organisation/Schule darf nicht leer sein.');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand['primary'])) throw new RuntimeException('Primary Color ungültig (z.B. #0b57d0).');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand['secondary'])) throw new RuntimeException('Secondary Color ungültig (z.B. #111111).');

    // ---- Mail settings (From) ----
    $fromEmail = trim((string)($_POST['from_email'] ?? ($cfg['mail']['from_email'] ?? '')));
    $fromName  = trim((string)($_POST['from_name'] ?? ($cfg['mail']['from_name'] ?? '')));

    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('From E-Mail ist ungültig.');
    }
    if ($fromName === '') {
      throw new RuntimeException('From Name darf nicht leer sein.');
    }

    if (!isset($cfg['mail']) || !is_array($cfg['mail'])) $cfg['mail'] = [];
    $cfg['mail']['from_email'] = $fromEmail === '' ? 'no-reply@example.org' : $fromEmail;
    $cfg['mail']['from_name']  = $fromName;

    // ---- AI suggestions (key only) ----
    $aiKey = trim((string)($_POST['ai_key'] ?? ($cfg['ai']['api_key'] ?? '')));
    $aiProvider = trim((string)($_POST['ai_provider'] ?? ($cfg['ai']['provider'] ?? 'openai')));
    $aiBaseUrl = trim((string)($_POST['ai_base_url'] ?? ($cfg['ai']['base_url'] ?? 'https://api.openai.com')));
    $aiModel = trim((string)($_POST['ai_model'] ?? ($cfg['ai']['model'] ?? 'gpt-4o-mini')));
    $aiEnabled = (isset($_POST['ai_enabled']) || $_POST['ai_key'])
      ? (int)$_POST['ai_enabled']
      : (int)($cfg['ai']['enabled'] ?? 1);
    if (!isset($cfg['ai']) || !is_array($cfg['ai'])) $cfg['ai'] = [];
    $cfg['ai']['enabled'] = ($aiEnabled === 1);
    $cfg['ai']['api_key'] = $aiKey;
    $cfg['ai']['provider'] = $aiProvider === '' ? 'openai' : $aiProvider;
    $cfg['ai']['base_url'] = rtrim($aiBaseUrl === '' ? 'https://api.openai.com' : $aiBaseUrl, '/');
    $cfg['ai']['model'] = $aiModel === '' ? 'gpt-4o-mini' : $aiModel;

    // ---- Student wizard settings ----
    if (!isset($cfg['student']) || !is_array($cfg['student'])) $cfg['student'] = [];

    $keys = $_POST['group_key'] ?? [];
    $titles = $_POST['group_title'] ?? [];
    if (!is_array($keys)) $keys = [];
    if (!is_array($titles)) $titles = [];
    $cfg['student']['group_titles'] = parse_group_title_overrides_from_post($keys, $titles);

    // ---- Logo actions ----
    if ($action === 'remove_logo') {
      $brand['logo_path'] = '';
    }

    if ($action === 'upload_logo') {
      if (!isset($_FILES['brand_logo']) || ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Kein Logo hochgeladen.');
      }
      $uploadsDirRel = $cfg['app']['uploads_dir'] ?? 'uploads';
      $uploadsDirAbs = realpath(__DIR__ . '/..') . '/' . $uploadsDirRel;
      $brandingAbs = $uploadsDirAbs . '/branding';

      if (!is_dir($brandingAbs)) {
        @mkdir($brandingAbs, 0755, true);
      }

      $tmp = $_FILES['brand_logo']['tmp_name'];
      $mime = mime_content_type($tmp) ?: '';
      $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
      if (!isset($allowed[$mime])) throw new RuntimeException('Logo muss PNG/JPG/WEBP sein.');
      $ext = $allowed[$mime];

      $destAbs = $brandingAbs . '/logo.' . $ext;
      if (!move_uploaded_file($tmp, $destAbs)) throw new RuntimeException('Konnte Logo nicht speichern.');

      $brand['logo_path'] = $uploadsDirRel . '/branding/logo.' . $ext;
    }

    // ---- Student intro (WYSIWYG) ----
    if ($action === 'save_student_intro') {
      $html = (string)($_POST['student_intro_html'] ?? '');
      $html = sanitize_intro_html($html);

      $abs = child_intro_file_abs();
      $dir = dirname($abs);
      if (!is_dir($dir)) @mkdir($dir, 0755, true);

      if (file_put_contents($abs, $html, LOCK_EX) === false) {
        throw new RuntimeException('Konnte Intro-Datei nicht speichern (Rechte?).');
      }
    }

    // ---- Save cfg ----
    $cfg['app']['brand'] = $brand;
    $cfg['app']['default_school_year'] = $defaultSY;

    $export = "<?php\n// config.php (updated by admin/settings.php)\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents($cfgPath, $export, LOCK_EX) === false) {
      throw new RuntimeException('Konnte config.php nicht schreiben (Rechte?).');
    }

    $ok = ($action === 'save_student_intro')
      ? 'Intro gespeichert.'
      : 'Einstellungen gespeichert.';

    audit('settings_update', (int)current_user()['id'], ['action'=>$action]);

    $cfg = app_config(true);

  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

$brand = $cfg['app']['brand'] ?? [];
$org = $brand['org_name'] ?? 'LEB Tool';
$primary = $brand['primary'] ?? '#0b57d0';
$secondary = $brand['secondary'] ?? '#111111';
$logo = $brand['logo_path'] ?? '';
$defaultSY = $cfg['app']['default_school_year'] ?? '';

$mail = $cfg['mail'] ?? [];
$fromEmail = $mail['from_email'] ?? 'no-reply@example.org';
$fromName  = $mail['from_name'] ?? ($org ?: 'LEB Tool');

$studentCfg = $cfg['student'] ?? [];

$ai = $cfg['ai'] ?? [];
$aiKey = $ai['api_key'] ?? '';
$aiEnabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
$aiProvider = $ai['provider'] ?? 'openai';
$aiBaseUrl = $ai['base_url'] ?? 'https://api.openai.com';
$aiModel = $ai['model'] ?? 'gpt-4o-mini';

$groupTitles = $studentCfg['group_titles'] ?? [];
if (!is_array($groupTitles)) $groupTitles = [];

$introAbs = child_intro_file_abs();
$introHtml = '';
if (is_file($introAbs)) {
  $introHtml = sanitize_intro_html((string)file_get_contents($introAbs));
}

render_admin_header('Admin – Settings');
?>
<div class="card">
    <h1>Einstellungen</h1>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<!-- Live Preview Card -->
<div class="card" id="previewCard">
  <h2>Live-Preview</h2>
  <p class="muted">Änderungen werden hier sofort sichtbar (ohne Speichern). Gespeichert wird erst mit „Speichern“ / „Logo hochladen“ / „Intro speichern“.</p>

  <div style="border:1px solid var(--border); border-radius:16px; overflow:hidden;">
    <div id="previewTopbar" style="background:#fff; border-bottom:1px solid var(--border);">
      <div style="display:flex; align-items:center; gap:12px; padding:14px 16px;">
        <img id="previewLogo" src="<?= $logo ? h(url($logo)) : '' ?>" alt="Logo"
             style="height:34px; width:auto; display:<?= $logo ? 'block':'none' ?>; background:#fff;">
        <div>
          <div id="previewOrg" style="font-weight:750; letter-spacing:.2px;"><?=h((string)$org)?></div>
          <div style="color:var(--muted); font-size:12px;">Admin – Settings</div>
        </div>
      </div>
    </div>

    <div style="padding:16px; background:var(--bg);">
      <div class="actions">
        <a class="btn primary" href="javascript:void(0)">Primary Button</a>
        <a class="btn secondary" href="javascript:void(0)">Secondary Button</a>
        <a class="btn danger" href="javascript:void(0)">Danger Button</a>
      </div>
      <div style="margin-top:12px;">
        <span class="pill">Pill</span>
        <span class="pill">Badge</span>
      </div>
      <div class="card" style="margin-top:14px;">
        <h3 style="margin:0 0 8px;">Beispiel-Card</h3>
        <p class="muted" style="margin:0;">So sieht der Content-Bereich mit deinen Farben aus.</p>
      </div>

      <div class="card" style="margin-top:14px;">
        <h3 style="margin:0 0 8px;">E-Mail-Absender (Preview)</h3>
        <p class="muted" style="margin:0;">
          Von: <strong id="previewFromName"><?=h($fromName)?></strong>
          &lt;<span id="previewFromEmail"><?=h($fromEmail)?></span>&gt;
        </p>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <h2>Branding</h2>
  <form method="post" autocomplete="off" id="brandingForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <div class="grid">
      <div>
        <label>Organisation / Schule</label>
        <input id="orgName" name="org_name" value="<?=h((string)$org)?>" required>
      </div>

      <div>
        <label>Default Schuljahr (für Bulk-Import)</label>
        <input name="default_school_year" value="<?=h((string)$defaultSY)?>" placeholder="z.B. 2025/26">
      </div>

      <div>
        <label>Primary Color</label>
        <div class="grid" style="grid-template-columns:140px 1fr;">
          <div>
            <input id="primaryPicker" type="color" value="<?=h((string)$primary)?>" aria-label="Primary Color Picker" style="height:42px; padding:0; border-radius:12px;">
          </div>
          <div>
            <input id="primaryHex" name="brand_primary" value="<?=h((string)$primary)?>" required placeholder="#0b57d0">
          </div>
        </div>
        <div class="muted">Live-Preview oben</div>
      </div>

      <div>
        <label>Secondary Color</label>
        <div class="grid" style="grid-template-columns:140px 1fr;">
          <div>
            <input id="secondaryPicker" type="color" value="<?=h((string)$secondary)?>" aria-label="Secondary Color Picker" style="height:42px; padding:0; border-radius:12px;">
          </div>
          <div>
            <input id="secondaryHex" name="brand_secondary" value="<?=h((string)$secondary)?>" required placeholder="#111111">
          </div>
        </div>
        <div class="muted">Live-Preview oben</div>
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit">Speichern</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>E-Mail</h2>
  <p class="muted">Diese Werte werden als Absender in System-Mails verwendet (Account-Anlage, Reset-Link, etc.).</p>

  <form method="post" autocomplete="off" id="mailForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <div class="grid">
      <div>
        <label>From E-Mail</label>
        <input id="fromEmail" name="from_email" type="email" value="<?=h((string)$fromEmail)?>" placeholder="no-reply@deine-domain.org">
      </div>
      <div>
        <label>From Name</label>
        <input id="fromName" name="from_name" value="<?=h((string)$fromName)?>" required placeholder="<?=h((string)$org)?>">
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit">Speichern</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>KI-Vorschläge</h2>
  <p class="muted">Hinterlege hier den API-Key deines KI-Providers (z.B. OpenAI-kompatibel), damit Lehrkräfte Vorschläge für Stärken, Ziele und Schritte abrufen können.</p>

  <form method="post" autocomplete="off" id="aiForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <label class="chk">
      <input type="checkbox" name="ai_enabled" value="1" <?=$aiEnabled ? 'checked' : ''?>> KI-Vorschläge für Lehrkräfte aktivieren
    </label>
    <p class="muted">Wenn deaktiviert, wird der KI-Button ausgeblendet und es werden keine externen Tokens verbraucht.</p>

    <label>Provider</label>
    <select name="ai_provider">
      <option value="openai" <?=$aiProvider==='openai' ? 'selected' : ''?>>OpenAI</option>
      <option value="compatible" <?=$aiProvider==='compatible' ? 'selected' : ''?>>OpenAI-kompatibel</option>
    </select>

    <label>Basis-URL</label>
    <input name="ai_base_url" value="<?=h((string)$aiBaseUrl)?>" placeholder="https://api.openai.com">
    <p class="muted">Nur ändern, wenn eine eigene oder kompatible API genutzt wird.</p>

    <label>API Key</label>
    <input name="ai_key" value="<?=h((string)$aiKey)?>" placeholder="z.B. sk-...">
    <p class="muted">Schlüsselbeschaffung: Im Provider-Dashboard (z.B. <strong>OpenAI &raquo; API Keys</strong>) einen Secret Key erstellen.</p>

    <label>Modell</label>
    <input name="ai_model" value="<?=h((string)$aiModel)?>" placeholder="z.B. gpt-4o-mini">
    <p class="muted">Bezeichnung muss zu deinem Provider passen.</p>

    <div class="actions">
      <button class="btn primary" type="submit">Speichern</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Schüler-Startseite (Intro)</h2>
  <p class="muted">
    Diese Seite sieht jede:r Schüler:in als erstes. Du kannst Platzhalter einfügen (z.B. für persönliche Begrüßung).
  </p>

  <div class="panel" style="padding:10px; margin-bottom:10px;">
    <label>Platzhalter einfügen</label>
    <div class="actions" style="justify-content:flex-start; gap:10px; flex-wrap:wrap; margin-top:6px;">
      <select id="phSelect" class="input" style="min-width:260px;">
        <?php foreach (known_intro_placeholders() as $token => $label): ?>
          <option value="<?=h($token)?>"><?=h($label)?> — <?=h($token)?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn secondary" type="button" id="btnInsertPh">Einfügen</button>
      <span class="muted">Beispiel: „Hallo {{first_name}}!“</span>
    </div>
  </div>

  <!-- Quill (external) -->
  <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
  <style>
    #quillEditor{ background:#fff; border-radius:14px; overflow:hidden; }
    #quillEditor .ql-toolbar{ border-top-left-radius:14px; border-top-right-radius:14px; }
    #quillEditor .ql-container{ border-bottom-left-radius:14px; border-bottom-right-radius:14px; min-height:220px; }
  </style>

  <form method="post" id="studentIntroForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save_student_intro">
    <input type="hidden" name="student_intro_html" id="studentIntroHtml">

    <div id="quillEditor"></div>

    <div class="actions" style="margin-top:12px;">
      <button class="btn primary" type="submit">Intro speichern</button>
    </div>
  </form>

  <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
  <script>
  (function(){
    const initialHtml = <?=json_encode($introHtml)?>;

    const quill = new Quill('#quillEditor', {
      theme: 'snow',
      modules: {
        toolbar: [
          [{ header: [1, 2, false] }],
          ['bold', 'italic', 'underline'],
          [{ 'list': 'ordered'}, { 'list': 'bullet' }],
          ['link'],
          ['clean']
        ]
      }
    });

    quill.root.innerHTML = initialHtml || '<p><strong>Hallo {{first_name}}!</strong></p><p>Bitte fülle den Bericht Schritt für Schritt aus.</p>';

    const hidden = document.getElementById('studentIntroHtml');
    const form = document.getElementById('studentIntroForm');

    form.addEventListener('submit', () => {
      hidden.value = quill.root.innerHTML || '';
    });

    const sel = document.getElementById('phSelect');
    const btn = document.getElementById('btnInsertPh');
    btn.addEventListener('click', () => {
      const token = sel.value || '';
      if (!token) return;
      const range = quill.getSelection(true);
      const pos = range ? range.index : quill.getLength();
      quill.insertText(pos, token, 'user');
      quill.setSelection(pos + token.length, 0, 'user');
      quill.focus();
    });
  })();
  </script>
</div>

<div class="card">
  <h2>Logo</h2>

  <?php if ($logo): ?>
    <p class="muted">Aktuelles Logo:</p>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <img src="<?=h(url($logo))?>" alt="Logo" style="height:54px; width:auto; background:#fff; padding:8px; border:1px solid #e5e7eb; border-radius:12px;">
      <form method="post" onsubmit="return confirm('Logo wirklich entfernen?');">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="remove_logo">
        <button class="btn danger" type="submit">Logo entfernen</button>
      </form>
    </div>
  <?php else: ?>
    <p class="muted">Kein Logo gesetzt.</p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" style="margin-top:14px;" id="logoForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="upload_logo">

    <label>Neues Logo hochladen (PNG/JPG/WEBP)</label>
    <input id="brandLogoInput" type="file" name="brand_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required>

    <div class="actions">
      <button class="btn primary" type="submit">Logo hochladen</button>
    </div>

    <p class="muted">Die Vorschau zeigt dein gewähltes Bild sofort. Hochgeladen wird erst beim Klick auf „Logo hochladen“.</p>
  </form>
</div>

<script>
(function(){
  const root = document.documentElement;

  const orgInput = document.getElementById('orgName');
  const previewOrg = document.getElementById('previewOrg');

  const pPick = document.getElementById('primaryPicker');
  const pHex  = document.getElementById('primaryHex');
  const sPick = document.getElementById('secondaryPicker');
  const sHex  = document.getElementById('secondaryHex');

  const previewLogo = document.getElementById('previewLogo');
  const logoInput = document.getElementById('brandLogoInput');

  const fromEmailInput = document.getElementById('fromEmail');
  const fromNameInput  = document.getElementById('fromName');
  const previewFromEmail = document.getElementById('previewFromEmail');
  const previewFromName  = document.getElementById('previewFromName');

  const initialLogoSrc = previewLogo.getAttribute('src') || '';
  const initialLogoDisplay = previewLogo.style.display || 'none';

  let objectUrl = null;

  function hexLooksValid(v){ return /^#[0-9a-fA-F]{6}$/.test((v||'').trim()); }
  function setCssVar(name, value){ root.style.setProperty(name, value); }

  function applyColors(){
    const p = pHex.value.trim();
    const s = sHex.value.trim();
    if (hexLooksValid(p)) setCssVar('--primary', p);
    if (hexLooksValid(s)) setCssVar('--secondary', s);
  }

  orgInput.addEventListener('input', () => {
    previewOrg.textContent = orgInput.value.trim() || 'LEB Tool';
  });

  pPick.addEventListener('input', () => { pHex.value = pPick.value; applyColors(); });
  sPick.addEventListener('input', () => { sHex.value = sPick.value; applyColors(); });

  pHex.addEventListener('input', () => {
    const v = pHex.value.trim();
    if (hexLooksValid(v)) pPick.value = v;
    applyColors();
  });
  sHex.addEventListener('input', () => {
    const v = sHex.value.trim();
    if (hexLooksValid(v)) sPick.value = v;
    applyColors();
  });

  applyColors();
  previewOrg.textContent = orgInput.value.trim() || 'LEB Tool';

  function applyMailPreview(){
    const fe = (fromEmailInput.value || '').trim() || 'no-reply@example.org';
    const fn = (fromNameInput.value || '').trim() || 'LEB Tool';
    previewFromEmail.textContent = fe;
    previewFromName.textContent = fn;
  }
  if (fromEmailInput) fromEmailInput.addEventListener('input', applyMailPreview);
  if (fromNameInput) fromNameInput.addEventListener('input', applyMailPreview);
  applyMailPreview();

  if (logoInput) {
    logoInput.addEventListener('change', () => {
      const file = logoInput.files && logoInput.files[0];
      if (!file) return;
      if (!file.type || !file.type.startsWith('image/')) return;

      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = URL.createObjectURL(file);

      previewLogo.src = objectUrl;
      previewLogo.style.display = 'block';
    });
  }

  window.addEventListener('beforeunload', () => {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
  });

  // group title overrides UI
  const rowsEl = document.getElementById('groupTitleRows');
  const btnAdd = document.getElementById('btnAddGroupTitle');
  const initial = <?=json_encode($groupTitles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;

  function rowTpl(k='', t=''){
    const div = document.createElement('div');
    div.style.display = 'grid';
    div.style.gridTemplateColumns = '1fr 1fr auto';
    div.style.gap = '8px';
    div.innerHTML = `
      <input class="input" name="group_key[]" placeholder="Original (Template) – z.B. Deutsch" value="${String(k).replace(/"/g,'&quot;')}">
      <input class="input" name="group_title[]" placeholder="Anzeige – z.B. Deutsch – Schreiben" value="${String(t).replace(/"/g,'&quot;')}">
      <button class="btn danger" type="button" title="Entfernen">×</button>
    `;
    div.querySelector('button').addEventListener('click', ()=>div.remove());
    return div;
  }

  if (rowsEl && btnAdd) {
    const entries = initial && typeof initial === 'object' ? Object.entries(initial) : [];
    if (!entries.length) rowsEl.appendChild(rowTpl());
    else entries.forEach(([k,t]) => rowsEl.appendChild(rowTpl(k,t)));

    btnAdd.addEventListener('click', () => rowsEl.appendChild(rowTpl()));
  }
})();
</script>

<?php render_admin_footer(); ?>
