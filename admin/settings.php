<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$cfgPath = __DIR__ . '/../config.php';
$cfg = app_config();

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';

    // ---- Branding ----
    $brand = $cfg['app']['brand'] ?? [];
    $brand['org_name'] = trim((string)($_POST['org_name'] ?? ($brand['org_name'] ?? 'LEG Tool')));

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

    // Ensure mail keys exist
    if (!isset($cfg['mail']) || !is_array($cfg['mail'])) $cfg['mail'] = [];
    $cfg['mail']['from_email'] = $fromEmail === '' ? 'no-reply@example.org' : $fromEmail;
    $cfg['mail']['from_name']  = $fromName;

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

    // ---- Save cfg ----
    $cfg['app']['brand'] = $brand;
    $cfg['app']['default_school_year'] = $defaultSY;

    $export = "<?php\n// config.php (updated by admin/settings.php)\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents($cfgPath, $export, LOCK_EX) === false) {
      throw new RuntimeException('Konnte config.php nicht schreiben (Rechte?).');
    }

    $ok = 'Einstellungen gespeichert.';
    audit('settings_update', (int)current_user()['id'], ['action'=>$action]);

    // reload cfg after save
    $cfg = app_config();

  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

$brand = $cfg['app']['brand'] ?? [];
$org = $brand['org_name'] ?? 'LEG Tool';
$primary = $brand['primary'] ?? '#0b57d0';
$secondary = $brand['secondary'] ?? '#111111';
$logo = $brand['logo_path'] ?? '';
$defaultSY = $cfg['app']['default_school_year'] ?? '';

$mail = $cfg['mail'] ?? [];
$fromEmail = $mail['from_email'] ?? 'no-reply@example.org';
$fromName  = $mail['from_name'] ?? ($org ?: 'LEG Tool');

render_admin_header('Admin – Settings');
?>
<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Admin</a>
    <a class="btn secondary" href="<?=h(url('admin/users.php'))?>">Nutzer</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<!-- Live Preview Card -->
<div class="card" id="previewCard">
  <h2>Live-Preview</h2>
  <p class="muted">Änderungen werden hier sofort sichtbar (ohne Speichern). Gespeichert wird erst mit „Speichern“ / „Logo hochladen“.</p>

  <div style="border:1px solid var(--border); border-radius:16px; overflow:hidden;">
    <div id="previewTopbar" style="background:#fff; border-bottom:1px solid var(--border);">
      <div style="display:flex; align-items:center; gap:12px; padding:14px 16px;">
        <img id="previewLogo" src="<?= $logo ? h(url($logo)) : '' ?>" alt="Logo"
             style="height:34px; width:auto; display:<?= $logo ? 'block':'none' ?>; background:#fff;">
        <div>
          <div id="previewOrg" style="font-weight:750; letter-spacing:.2px;"><?=h($org)?></div>
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
        <input id="orgName" name="org_name" value="<?=h($org)?>" required>
      </div>

      <div>
        <label>Default Schuljahr (für Bulk-Import)</label>
        <input name="default_school_year" value="<?=h((string)$defaultSY)?>" placeholder="z.B. 2025/26">
      </div>

      <div>
        <label>Primary Color</label>
        <div class="grid" style="grid-template-columns:140px 1fr;">
          <div>
            <input id="primaryPicker" type="color" value="<?=h($primary)?>" aria-label="Primary Color Picker" style="height:42px; padding:0; border-radius:12px;">
          </div>
          <div>
            <input id="primaryHex" name="brand_primary" value="<?=h($primary)?>" required placeholder="#0b57d0">
          </div>
        </div>
        <div class="muted">Picker oder Hex. (Live-Preview oben)</div>
      </div>

      <div>
        <label>Secondary Color</label>
        <div class="grid" style="grid-template-columns:140px 1fr;">
          <div>
            <input id="secondaryPicker" type="color" value="<?=h($secondary)?>" aria-label="Secondary Color Picker" style="height:42px; padding:0; border-radius:12px;">
          </div>
          <div>
            <input id="secondaryHex" name="brand_secondary" value="<?=h($secondary)?>" required placeholder="#111111">
          </div>
        </div>
        <div class="muted">Picker oder Hex. (Live-Preview oben)</div>
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit">Speichern</button>
    </div>

    <p class="muted">Gespeichert wird in <code>config.php</code>. Live-Preview ändert nur die Ansicht im Browser.</p>
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
        <input id="fromEmail" name="from_email" type="email" value="<?=h($fromEmail)?>" placeholder="no-reply@deine-domain.org">
      </div>
      <div>
        <label>From Name</label>
        <input id="fromName" name="from_name" value="<?=h($fromName)?>" required placeholder="<?=h($org)?>">
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit">Speichern</button>
    </div>

    <p class="muted">
      Hinweis: Damit Mails zuverlässig ankommen, sollte deine Domain korrekt SPF/DKIM/DMARC setzen (später).
    </p>
  </form>
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
      <button class="btn secondary" type="button" id="logoPreviewReset">Vorschau zurücksetzen</button>
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
  const logoResetBtn = document.getElementById('logoPreviewReset');

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

  // Org live preview
  orgInput.addEventListener('input', () => {
    previewOrg.textContent = orgInput.value.trim() || 'LEG Tool';
    // optional: default From-Name preview to org if From-Name empty
  });

  // Picker -> Hex + apply
  pPick.addEventListener('input', () => { pHex.value = pPick.value; applyColors(); });
  sPick.addEventListener('input', () => { sHex.value = sPick.value; applyColors(); });

  // Hex -> Picker (only when valid) + apply
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

  // Initial apply
  applyColors();
  previewOrg.textContent = orgInput.value.trim() || 'LEG Tool';

  // Mail live preview
  function applyMailPreview(){
    const fe = (fromEmailInput.value || '').trim() || 'no-reply@example.org';
    const fn = (fromNameInput.value || '').trim() || 'LEG Tool';
    previewFromEmail.textContent = fe;
    previewFromName.textContent = fn;
  }
  fromEmailInput.addEventListener('input', applyMailPreview);
  fromNameInput.addEventListener('input', applyMailPreview);
  applyMailPreview();

  // Logo live preview
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

  // Reset preview to saved logo
  if (logoResetBtn) {
    logoResetBtn.addEventListener('click', () => {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
      }
      previewLogo.src = initialLogoSrc;
      previewLogo.style.display = initialLogoSrc ? 'block' : initialLogoDisplay;
      if (logoInput) logoInput.value = '';
    });
  }

  window.addEventListener('beforeunload', () => {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
  });
})();
</script>

<?php render_admin_footer(); ?>
