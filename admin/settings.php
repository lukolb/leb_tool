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
    '{{org_name}}'      => t('admin.settings.placeholder.org_name', 'Schule/Organisation'),
    '{{student_name}}'  => t('admin.settings.placeholder.student_name', 'Schüler (Vorname Nachname)'),
    '{{first_name}}'    => t('admin.settings.placeholder.first_name', 'Vorname'),
    '{{last_name}}'     => t('admin.settings.placeholder.last_name', 'Nachname'),
    '{{class}}'         => t('admin.settings.placeholder.class', 'Klasse (z.B. 4A)'),
    '{{school_year}}'   => t('admin.settings.placeholder.school_year', 'Schuljahr (z.B. 2025/26)'),
  ];
}

function load_vits_voice_ids(): array {
  $path = __DIR__ . '/../assets/vits-web/src/fixtures.ts';
  if (!is_file($path)) return [];
  $contents = file_get_contents($path);
  if ($contents === false) return [];
  if (!preg_match('~PATH_MAP\\s*:[^{]*\\{(.*?)\\}\\s*$~s', $contents, $match)) {
    return [];
  }
  $block = $match[1];
  preg_match_all("/'([a-z]{2}_[A-Z]{2}[^']*)'\\s*:/", $block, $ids);
  $out = array_values(array_unique($ids[1] ?? []));
  sort($out);
  return $out;
}

function filter_vits_voice_ids(array $ids, array $prefixes): array {
  $out = [];
  foreach ($ids as $id) {
    foreach ($prefixes as $prefix) {
      if (str_starts_with($id, $prefix)) {
        $out[] = $id;
        break;
      }
    }
  }
  return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';

    // ---- Branding ----
    $brand = $cfg['app']['brand'] ?? [];
    $brand['org_name'] = trim((string)($_POST['org_name'] ?? ($brand['org_name'] ?? t('admin.settings.default_org', 'LEB Tool'))));

    $primary = trim((string)($_POST['brand_primary'] ?? ($brand['primary'] ?? '#0b57d0')));
    $secondary = trim((string)($_POST['brand_secondary'] ?? ($brand['secondary'] ?? '#111111')));

    $brand['primary'] = $primary;
    $brand['secondary'] = $secondary;

    $defaultSY = trim((string)($_POST['default_school_year'] ?? ($cfg['app']['default_school_year'] ?? '')));
    $schoolTimezone = trim((string)($_POST['school_timezone'] ?? ($cfg['app']['timezone'] ?? 'America/New_York')));

    if ($brand['org_name'] === '') throw new RuntimeException(t('admin.settings.error.org_name_required', 'Organisation/Schule darf nicht leer sein.'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand['primary'])) throw new RuntimeException(t('admin.settings.error.primary_color_invalid', 'Primary Color ungültig (z.B. #0b57d0).'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand['secondary'])) throw new RuntimeException(t('admin.settings.error.secondary_color_invalid', 'Secondary Color ungültig (z.B. #111111).'));
    if (!in_array($schoolTimezone, timezone_identifiers_list(), true)) {
      throw new RuntimeException(t('admin.settings.error.timezone_invalid', 'Schul-Zeitzone muss eine gültige IANA-Zeitzone sein (z.B. Europe/Berlin).'));
    }

    // ---- Mail settings (From) ----
    $fromEmail = trim((string)($_POST['from_email'] ?? ($cfg['mail']['from_email'] ?? '')));
    $fromName  = trim((string)($_POST['from_name'] ?? ($cfg['mail']['from_name'] ?? '')));

    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException(t('admin.settings.error.from_email_invalid', 'From E-Mail ist ungültig.'));
    }
    if ($fromName === '') {
      throw new RuntimeException(t('admin.settings.error.from_name_required', 'From Name darf nicht leer sein.'));
    }

    if (!isset($cfg['mail']) || !is_array($cfg['mail'])) $cfg['mail'] = [];
    $cfg['mail']['from_email'] = $fromEmail === '' ? t('admin.settings.mail.fallback_email', 'no-reply@example.org') : $fromEmail;
    $cfg['mail']['from_name']  = $fromName;

    // ---- AI suggestions (key only) ----
    $aiKey = trim((string)($_POST['ai_key'] ?? ($cfg['ai']['api_key'] ?? '')));
    $aiProvider = trim((string)($_POST['ai_provider'] ?? ($cfg['ai']['provider'] ?? 'openai')));
    $aiBaseUrl = trim((string)($_POST['ai_base_url'] ?? ($cfg['ai']['base_url'] ?? 'https://api.openai.com')));
    $aiModel = trim((string)($_POST['ai_model'] ?? ($cfg['ai']['model'] ?? 'gpt-4o-mini')));
    $aiEnabled = (isset($_POST['ai_enabled']) || $_POST['ai_key'])
      ? (int)$_POST['ai_enabled']
      : (int)($cfg['ai']['enabled'] ?? 1);
    $aiStudentEnabled = isset($_POST['ai_student_enabled'])
      ? 1
      : 0;
    if (!isset($cfg['ai']) || !is_array($cfg['ai'])) $cfg['ai'] = [];
    $cfg['ai']['enabled'] = ($aiEnabled === 1);
    $cfg['ai']['student_enabled'] = ($aiStudentEnabled === 1);
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

    $ttsVoiceDe = trim((string)($_POST['tts_voice_de'] ?? ($cfg['student']['tts_voice_de'] ?? ($cfg['student']['tts_voice'] ?? ''))));
    $ttsVoiceEn = trim((string)($_POST['tts_voice_en'] ?? ($cfg['student']['tts_voice_en'] ?? '')));
    $ttsRate = (float)($_POST['tts_rate'] ?? ($cfg['student']['tts_rate'] ?? 1.0));
    if ($ttsRate <= 0) $ttsRate = 1.0;
    $cfg['student']['tts_voice_de'] = $ttsVoiceDe;
    $cfg['student']['tts_voice_en'] = $ttsVoiceEn;
    $cfg['student']['tts_voice'] = $ttsVoiceDe;
    $cfg['student']['tts_rate'] = max(0.5, min(1.5, $ttsRate));

    // ---- Parent portal settings ----
    if ($action === 'save' && isset($_POST['parent_download_enabled_present'])) {
      if (!isset($cfg['parent']) || !is_array($cfg['parent'])) $cfg['parent'] = [];
      $cfg['parent']['download_enabled'] = isset($_POST['parent_download_enabled']);
      $cfg['parent']['auto_approve_requests'] = isset($_POST['parent_auto_approve_requests']);
      $cfg['parent']['signature_enabled'] = isset($_POST['parent_signature_enabled']);
      $cfg['parent']['meeting_feedback_enabled'] = isset($_POST['parent_meeting_feedback_enabled']);
      $cfg['parent']['meeting_feedback_required'] = isset($_POST['parent_meeting_feedback_required']) && $cfg['parent']['meeting_feedback_enabled'];
      $cfg['parent']['meeting_feedback_anonymous'] = isset($_POST['parent_meeting_feedback_anonymous']);

      if (!isset($cfg['signature']) || !is_array($cfg['signature'])) $cfg['signature'] = [];
      $sigKeyInput = trim((string)($_POST['parent_signature_master_key'] ?? ''));
      $sigKeyClear = isset($_POST['parent_signature_master_key_clear']);
      if ($sigKeyClear) {
        $cfg['signature']['master_key'] = '';
      } elseif ($sigKeyInput !== '') {
        $cfg['signature']['master_key'] = $sigKeyInput;
      }
    }

    // ---- Logo actions ----
    if ($action === 'remove_logo') {
      $brand['logo_path'] = '';
    }

    if ($action === 'upload_logo') {
      if (!isset($_FILES['brand_logo']) || ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('admin.settings.error.logo_missing', 'Kein Logo hochgeladen.'));
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
      if (!isset($allowed[$mime])) throw new RuntimeException(t('admin.settings.error.logo_type_invalid', 'Logo muss PNG/JPG/WEBP sein.'));
      $ext = $allowed[$mime];

      $destAbs = $brandingAbs . '/logo.' . $ext;
      if (!move_uploaded_file($tmp, $destAbs)) throw new RuntimeException(t('admin.settings.error.logo_save_failed', 'Konnte Logo nicht speichern.'));

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
        throw new RuntimeException(t('admin.settings.error.intro_save_failed', 'Konnte Intro-Datei nicht speichern (Rechte?).'));
      }
    }

    // ---- Save cfg ----
    $cfg['app']['brand'] = $brand;
    $cfg['app']['default_school_year'] = $defaultSY;
    $cfg['app']['timezone'] = $schoolTimezone;

    $export = "<?php\n// config.php (updated by admin/settings.php)\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents($cfgPath, $export, LOCK_EX) === false) {
      throw new RuntimeException(t('admin.settings.error.config_write_failed', 'Konnte config.php nicht schreiben (Rechte?).'));
    }

    $ok = ($action === 'save_student_intro')
      ? t('admin.settings.ok.intro_saved', 'Intro gespeichert.')
      : t('admin.settings.ok.settings_saved', 'Einstellungen gespeichert.');

    audit('settings_update', (int)current_user()['id'], ['action'=>$action]);

    $cfg = app_config(true);

  } catch (Throwable $e) {
    $err = t('admin.settings.error.prefix', 'Fehler: ') . $e->getMessage();
  }
}

$brand = $cfg['app']['brand'] ?? [];
$org = $brand['org_name'] ?? t('admin.settings.default_org', 'LEB Tool');
$primary = $brand['primary'] ?? '#0b57d0';
$secondary = $brand['secondary'] ?? '#111111';
$logo = $brand['logo_path'] ?? '';
$defaultSY = $cfg['app']['default_school_year'] ?? '';
$schoolTimezone = $cfg['app']['timezone'] ?? 'America/New_York';

$mail = $cfg['mail'] ?? [];
$fromEmail = $mail['from_email'] ?? t('admin.settings.mail.fallback_email', 'no-reply@example.org');
$fromName  = $mail['from_name'] ?? ($org ?: t('admin.settings.default_org', 'LEB Tool'));

$studentCfg = $cfg['student'] ?? [];

$ai = $cfg['ai'] ?? [];
$aiKey = $ai['api_key'] ?? '';
$aiEnabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
$aiStudentEnabled = array_key_exists('student_enabled', $ai) ? (bool)$ai['student_enabled'] : $aiEnabled;
$aiProvider = $ai['provider'] ?? 'openai';
$aiBaseUrl = $ai['base_url'] ?? 'https://api.openai.com';
$aiModel = $ai['model'] ?? 'gpt-4o-mini';

$parentCfg = $cfg['parent'] ?? [];
$parentDownloadEnabled = (bool)($parentCfg['download_enabled'] ?? false);
$parentAutoApprove = (bool)($parentCfg['auto_approve_requests'] ?? false);
$parentSignatureEnabled = (bool)($parentCfg['signature_enabled'] ?? false);
$parentMeetingFeedbackEnabled = (bool)($parentCfg['meeting_feedback_enabled'] ?? false);
$parentMeetingFeedbackRequired = (bool)($parentCfg['meeting_feedback_required'] ?? false);
$parentMeetingFeedbackAnonymous = (bool)($parentCfg['meeting_feedback_anonymous'] ?? false);
$signatureCfg = $cfg['signature'] ?? [];
$signatureMasterKeySet = trim((string)($signatureCfg['master_key'] ?? '')) !== '';

$groupTitles = $studentCfg['group_titles'] ?? [];
if (!is_array($groupTitles)) $groupTitles = [];
$ttsVoicePrefDe = trim((string)($studentCfg['tts_voice_de'] ?? ($studentCfg['tts_voice'] ?? '')));
$ttsVoicePrefEn = trim((string)($studentCfg['tts_voice_en'] ?? ''));
$ttsRate = (float)($studentCfg['tts_rate'] ?? 1.0);
if ($ttsRate <= 0) $ttsRate = 1.0;
$ttsRate = max(0.5, min(1.5, $ttsRate));

$vitsVoiceIds = load_vits_voice_ids();
$vitsVoiceIdsDe = filter_vits_voice_ids($vitsVoiceIds, ['de_DE-']);
$vitsVoiceIdsEn = filter_vits_voice_ids($vitsVoiceIds, ['en_GB-', 'en_US-']);

$introAbs = child_intro_file_abs();
$introHtml = '';
if (is_file($introAbs)) {
  $introHtml = sanitize_intro_html((string)file_get_contents($introAbs));
}

render_admin_header(t('admin.settings.page_title', 'Admin – Settings'));
?>
<div class="card">
    <h1><?=h(t('admin.settings.heading', 'Einstellungen'))?></h1>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<!-- Live Preview Card -->
<div class="card" id="previewCard">
  <h2><?=h(t('admin.settings.preview.title', 'Live-Preview'))?></h2>
  <p class="muted"><?=h(t('admin.settings.preview.desc', 'Änderungen werden hier sofort sichtbar (ohne Speichern). Gespeichert wird erst mit „Speichern“ / „Logo hochladen“ / „Intro speichern“.'))?></p>

  <div style="border:1px solid var(--border); border-radius:16px; overflow:hidden;">
    <div id="previewTopbar" style="background:#fff; border-bottom:1px solid var(--border);">
      <div style="display:flex; align-items:center; gap:12px; padding:14px 16px;">
        <img id="previewLogo" src="<?= $logo ? h(url($logo)) : '' ?>" alt="<?=h(t('admin.settings.logo.alt', 'Logo'))?>"
             style="height:34px; width:auto; display:<?= $logo ? 'block':'none' ?>; background:#fff;">
        <div>
          <div id="previewOrg" style="font-weight:750; letter-spacing:.2px;"><?=h((string)$org)?></div>
          <div style="color:var(--muted); font-size:12px;"><?=h(t('admin.settings.preview.header_subtitle', 'Admin – Settings'))?></div>
        </div>
      </div>
    </div>

    <div style="padding:16px; background:var(--bg);">
      <div class="actions">
        <a class="btn primary" href="javascript:void(0)"><?=h(t('admin.settings.preview.primary_button', 'Primary Button'))?></a>
        <a class="btn secondary" href="javascript:void(0)"><?=h(t('admin.settings.preview.secondary_button', 'Secondary Button'))?></a>
        <a class="btn danger" href="javascript:void(0)"><?=h(t('admin.settings.preview.danger_button', 'Danger Button'))?></a>
      </div>
      <div style="margin-top:12px;">
        <span class="pill"><?=h(t('admin.settings.preview.pill', 'Pill'))?></span>
        <span class="pill"><?=h(t('admin.settings.preview.badge', 'Badge'))?></span>
      </div>
      <div class="card" style="margin-top:14px;">
        <h3 style="margin:0 0 8px;"><?=h(t('admin.settings.preview.card_title', 'Beispiel-Card'))?></h3>
        <p class="muted" style="margin:0;"><?=h(t('admin.settings.preview.card_desc', 'So sieht der Content-Bereich mit deinen Farben aus.'))?></p>
      </div>

      <div class="card" style="margin-top:14px;">
        <h3 style="margin:0 0 8px;"><?=h(t('admin.settings.preview.mail_title', 'E-Mail-Absender (Preview)'))?></h3>
        <p class="muted" style="margin:0;">
          <?=h(t('admin.settings.preview.mail_from', 'Von:'))?> <strong id="previewFromName"><?=h($fromName)?></strong>
          &lt;<span id="previewFromEmail"><?=h($fromEmail)?></span>&gt;
        </p>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <h2><?=h(t('admin.settings.branding.title', 'Branding'))?></h2>
  <form method="post" autocomplete="off" id="brandingForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <div class="grid">
      <div>
        <label><?=h(t('admin.settings.branding.org_label', 'Organisation / Schule'))?></label>
        <input id="orgName" name="org_name" value="<?=h((string)$org)?>" required>
      </div>

      <div>
        <label><?=h(t('admin.settings.branding.default_school_year_label', 'Default Schuljahr (für Bulk-Import)'))?></label>
        <input name="default_school_year" value="<?=h((string)$defaultSY)?>" placeholder="<?=h(t('admin.settings.branding.default_school_year_placeholder', 'z.B. 2025/26'))?>">
      </div>
      <div>
        <label><?=h(t('admin.settings.branding.timezone_label', 'Schul-Zeitzone (IANA)'))?></label>
        <input name="school_timezone" list="timezoneOptions" value="<?=h((string)$schoolTimezone)?>" placeholder="<?=h(t('admin.settings.branding.timezone_placeholder', 'z.B. Europe/Berlin'))?>" required>
        <datalist id="timezoneOptions">
          <?php foreach (timezone_identifiers_list() as $tz): ?>
            <option value="<?=h($tz)?>"></option>
          <?php endforeach; ?>
        </datalist>
        <div class="muted"><?=h(t('admin.settings.branding.timezone_hint', 'Gilt für alle Zeitstempel im Tool (inkl. Sommer-/Winterzeit).'))?></div>
      </div>

      <div>
        <label><?=h(t('admin.settings.branding.primary_color_label', 'Primary Color'))?></label>
        <div class="grid" style="grid-template-columns:140px 1fr;">
          <div>
            <input id="primaryPicker" type="color" value="<?=h((string)$primary)?>" aria-label="<?=h(t('admin.settings.primary_color_picker'))?>" style="height:42px; padding:0; border-radius:12px;">
          </div>
          <div>
            <input id="primaryHex" name="brand_primary" value="<?=h((string)$primary)?>" required placeholder="<?=h(t('admin.settings.branding.primary_color_placeholder', '#0b57d0'))?>">
          </div>
        </div>
        <div class="muted"><?=h(t('admin.settings.branding.preview_hint', 'Live-Preview oben'))?></div>
      </div>

      <div>
        <label><?=h(t('admin.settings.branding.secondary_color_label', 'Secondary Color'))?></label>
        <div class="grid" style="grid-template-columns:140px 1fr;">
          <div>
            <input id="secondaryPicker" type="color" value="<?=h((string)$secondary)?>" aria-label="<?=h(t('admin.settings.secondary_color_picker'))?>" style="height:42px; padding:0; border-radius:12px;">
          </div>
          <div>
            <input id="secondaryHex" name="brand_secondary" value="<?=h((string)$secondary)?>" required placeholder="<?=h(t('admin.settings.branding.secondary_color_placeholder', '#111111'))?>">
          </div>
        </div>
        <div class="muted"><?=h(t('admin.settings.branding.preview_hint', 'Live-Preview oben'))?></div>
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit"><?=h(t('admin.settings.save_button', 'Speichern'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.settings.mail.title', 'E-Mail'))?></h2>
  <p class="muted"><?=h(t('admin.settings.mail.desc', 'Diese Werte werden als Absender in System-Mails verwendet (Account-Anlage, Reset-Link, etc.).'))?></p>

  <form method="post" autocomplete="off" id="mailForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <div class="grid">
      <div>
        <label><?=h(t('admin.settings.mail.from_email_label', 'From E-Mail'))?></label>
        <input id="fromEmail" name="from_email" type="email" value="<?=h((string)$fromEmail)?>" placeholder="<?=h(t('admin.settings.mail.from_email_placeholder', 'no-reply@deine-domain.org'))?>">
      </div>
      <div>
        <label><?=h(t('admin.settings.mail.from_name_label', 'From Name'))?></label>
        <input id="fromName" name="from_name" value="<?=h((string)$fromName)?>" required placeholder="<?=h((string)$org)?>">
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit"><?=h(t('admin.settings.save_button', 'Speichern'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.settings.ai.title', 'KI-Vorschläge'))?></h2>
  <p class="muted"><?=h(t('admin.settings.ai.desc', 'Hinterlege hier den API-Key deines KI-Providers (z.B. OpenAI-kompatibel), damit Lehrkräfte Vorschläge für Stärken, Ziele und Schritte abrufen können.'))?></p>

  <form method="post" autocomplete="off" id="aiForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <label class="chk">
      <input type="checkbox" name="ai_enabled" value="1" <?=$aiEnabled ? 'checked' : ''?>> <?=h(t('admin.settings.ai.enable_teacher', 'KI-Vorschläge für Lehrkräfte aktivieren'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.ai.enable_teacher_hint', 'Wenn deaktiviert, wird der KI-Button ausgeblendet und es werden keine externen Tokens verbraucht.'))?></p>

    <label class="chk" style="margin-top:8px;">
      <input type="checkbox" name="ai_student_enabled" value="1" <?=$aiStudentEnabled ? 'checked' : ''?>> <?=h(t('admin.settings.ai.enable_student', 'KI-Erklärungen für Schüler aktivieren'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.ai.enable_student_hint', 'Steuert die KI-Kurzerklärung im Schülerbereich (separat vom Lehrkräfte-Feature).'))?></p>

    <label><?=h(t('admin.settings.ai.provider_label', 'Provider'))?></label>
    <select name="ai_provider">
      <option value="openai" <?=$aiProvider==='openai' ? 'selected' : ''?>><?=h(t('admin.settings.ai.provider_openai', 'OpenAI'))?></option>
      <option value="compatible" <?=$aiProvider==='compatible' ? 'selected' : ''?>><?=h(t('admin.settings.ai.provider_compatible', 'OpenAI-kompatibel'))?></option>
    </select>

    <label><?=h(t('admin.settings.ai.base_url_label', 'Basis-URL'))?></label>
    <input name="ai_base_url" value="<?=h((string)$aiBaseUrl)?>" placeholder="<?=h(t('admin.settings.ai.base_url_placeholder', 'https://api.openai.com'))?>">
    <p class="muted"><?=h(t('admin.settings.ai.base_url_hint', 'Nur ändern, wenn eine eigene oder kompatible API genutzt wird.'))?></p>

    <label><?=h(t('admin.settings.ai.api_key_label', 'API Key'))?></label>
    <input name="ai_key" value="<?=h((string)$aiKey)?>" placeholder="<?=h(t('admin.settings.ai.api_key_placeholder', 'z.B. sk-...'))?>">
    <p class="muted"><?=h(t('admin.settings.ai.api_key_hint', 'Schlüsselbeschaffung: Im Provider-Dashboard (z.B. OpenAI » API Keys) einen Secret Key erstellen.'))?></p>

    <label><?=h(t('admin.settings.ai.model_label', 'Modell'))?></label>
    <input name="ai_model" value="<?=h((string)$aiModel)?>" placeholder="<?=h(t('admin.settings.ai.model_placeholder', 'z.B. gpt-4o-mini'))?>">
    <p class="muted"><?=h(t('admin.settings.ai.model_hint', 'Bezeichnung muss zu deinem Provider passen.'))?></p>

    <div class="actions">
      <button class="btn primary" type="submit"><?=h(t('admin.settings.save_button', 'Speichern'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.settings.parent.title', 'Elternmodus'))?></h2>
  <p class="muted"><?=h(t('admin.settings.parent.desc', 'Steuere hier, ob Eltern den Bericht zusätzlich als schreibgeschützte PDF herunterladen dürfen, ob Anfragen automatisch freigegeben werden und ob eine grafische Lehrkraft-Signatur genutzt wird.'))?></p>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="parent_download_enabled_present" value="1">

    <label class="chk">
      <input type="checkbox" name="parent_download_enabled" value="1" <?=$parentDownloadEnabled ? 'checked' : ''?>> <?=h(t('admin.settings.parent.download_enable', 'Download-Button in der Elternansicht anzeigen'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.download_hint', 'Der Download erzeugt eine signierte, nicht bearbeitbare PDF-Version.'))?></p>
    <label class="chk" style="margin-top:10px;">
      <input type="checkbox" name="parent_auto_approve_requests" value="1" <?=$parentAutoApprove ? 'checked' : ''?>> <?=h(t('admin.settings.parent.auto_approve', 'Anfragen automatisch freigeben (keine Admin-Bestätigung erforderlich)'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.auto_approve_hint', 'Wenn aktiviert, werden neue Elternzugänge direkt freigeschaltet.'))?></p>
    <label class="chk" style="margin-top:10px;">
      <input type="checkbox" name="parent_signature_enabled" value="1" <?=$parentSignatureEnabled ? 'checked' : ''?>> <?=h(t('admin.settings.parent.signature_enable', 'Grafische Lehrkraft-Unterschrift aktivieren'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.signature_hint', 'Lehrkräfte können dann eine handschriftliche Signatur erfassen, die im Eltern-PDF über dem Unterschriftenfeld platziert wird.'))?></p>

    <label class="chk" style="margin-top:10px;">
      <input type="checkbox" name="parent_meeting_feedback_enabled" value="1" <?=$parentMeetingFeedbackEnabled ? 'checked' : ''?>> <?=h(t('admin.settings.parent.meeting_feedback_enable', 'Feedbackbogen nach dem Lernentwicklungsgespräch aktivieren'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.meeting_feedback_hint', 'Eltern können dann einen kurzen Feedbackbogen ausfüllen. Ergebnisse werden für Lehrkräfte und Admins ausgewertet.'))?></p>
    <label class="chk" style="margin-top:6px;">
      <input type="checkbox" name="parent_meeting_feedback_required" value="1" <?=$parentMeetingFeedbackRequired ? 'checked' : ''?> <?= $parentMeetingFeedbackEnabled ? '' : 'disabled' ?>> <?=h(t('admin.settings.parent.meeting_feedback_required', 'Feedbackbogen verpflichtend vor Berichtszugriff'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.meeting_feedback_required_hint', 'Wenn aktiviert, müssen Eltern den Feedbackbogen ausfüllen, bevor sie den Bericht sehen können.'))?></p>
    <label class="chk" style="margin-top:6px;">
      <input type="checkbox" name="parent_meeting_feedback_anonymous" value="1" <?=$parentMeetingFeedbackAnonymous ? 'checked' : ''?>> <?=h(t('admin.settings.parent.meeting_feedback_anonymous', 'Feedback anonym erfassen'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.meeting_feedback_anonymous_hint', 'Hinweistext in der Elternansicht: Feedback wird anonym ausgewertet.'))?></p>

    <label style="margin-top:10px;"><?=h(t('admin.settings.parent.signature_key_label', 'SIGNATURE_MASTER_KEY (32 Byte, Hex/Base64 möglich)'))?></label>
    <div class="row" style="gap:8px; align-items:center; flex-wrap:wrap;">
      <input id="signatureMasterKeyInput" name="parent_signature_master_key" type="password" value="" placeholder="<?= $signatureMasterKeySet ? h(t('admin.settings.parent.signature_key_placeholder_existing', 'Vorhanden (leer lassen zum Beibehalten)')) : h(t('admin.settings.parent.signature_key_placeholder_new', 'z.B. 64-stelliges Hex oder Base64')) ?>" style="min-width:260px;">
      <button class="btn secondary" type="button" id="signatureMasterKeyGenerate"><?=h(t('admin.settings.parent.signature_key_generate', 'Neu generieren'))?></button>
    </div>
    <label class="chk" style="margin-top:6px;">
      <input type="checkbox" name="parent_signature_master_key_clear" value="1" id="signatureMasterKeyClear"> <?=h(t('admin.settings.parent.signature_key_clear', 'Master-Key löschen'))?>
    </label>
    <p class="muted"><?=h(t('admin.settings.parent.signature_key_hint', 'Der Schlüssel wird in der config.php gespeichert. Beim Löschen oder Neu-Generieren können gespeicherte Signaturen nicht mehr entschlüsselt werden.'))?></p>

    <script>
      (function(){
        const input = document.getElementById('signatureMasterKeyInput');
        const btn = document.getElementById('signatureMasterKeyGenerate');
        const clear = document.getElementById('signatureMasterKeyClear');
        const tConfirmGenerate = <?=json_encode(t('admin.settings.parent.signature_key_confirm_generate', 'Neuen Master-Key erzeugen? Vorhandene Signaturen können danach nicht mehr entschlüsselt werden.'))?>;
        const tSecureRandomMissing = <?=json_encode(t('admin.settings.parent.signature_key_random_missing', 'Sicherer Zufall ist im Browser nicht verfügbar.'))?>;
        const tConfirmClear = <?=json_encode(t('admin.settings.parent.signature_key_confirm_clear', 'Master-Key wirklich löschen? Gespeicherte Signaturen können danach nicht mehr entschlüsselt werden.'))?>;
        if (btn) {
          btn.addEventListener('click', () => {
            if (!confirm(tConfirmGenerate)) return;
            if (!window.crypto || !window.crypto.getRandomValues) {
              alert(tSecureRandomMissing);
              return;
            }
            const bytes = new Uint8Array(32);
            window.crypto.getRandomValues(bytes);
            const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
            if (input) input.value = hex;
            if (clear) clear.checked = false;
          });
        }
        if (clear) {
          clear.addEventListener('change', () => {
            if (clear.checked) {
              const ok = confirm(tConfirmClear);
              if (!ok) clear.checked = false;
            }
          });
        }
      })();
    </script>

    <div class="actions">
      <button class="btn primary" type="submit"><?=h(t('admin.settings.save_button', 'Speichern'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.settings.tts.title', 'Vorlesen (Text-to-Speech)'))?></h2>
  <p class="muted"><?=h(t('admin.settings.tts.desc', 'Wähle die Standard-Stimme und Lesegeschwindigkeit für Schüler. Die Liste basiert auf den in vits-web verfügbaren Stimmen.'))?></p>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">

    <label><?=h(t('admin.settings.tts.voice_de_label', 'Standard-Stimme (Deutsch)'))?></label>
    <select id="ttsVoiceDe" name="tts_voice_de">
      <option value=""><?=h(t('admin.settings.tts.voice_auto', 'Automatisch (Standard)'))?></option>
      <?php foreach ($vitsVoiceIdsDe as $voiceId): ?>
        <option value="<?=h($voiceId)?>" <?= $voiceId === $ttsVoicePrefDe ? 'selected' : ''?>><?=h($voiceId)?></option>
      <?php endforeach; ?>
    </select>
    <label style="margin-top:10px;"><?=h(t('admin.settings.tts.voice_en_label', 'Standard-Stimme (Englisch)'))?></label>
    <select id="ttsVoiceEn" name="tts_voice_en">
      <option value=""><?=h(t('admin.settings.tts.voice_auto', 'Automatisch (Standard)'))?></option>
      <?php foreach ($vitsVoiceIdsEn as $voiceId): ?>
        <option value="<?=h($voiceId)?>" <?= $voiceId === $ttsVoicePrefEn ? 'selected' : ''?>><?=h($voiceId)?></option>
      <?php endforeach; ?>
    </select>
    <p class="muted"><?=h(t('admin.settings.tts.voice_hint', 'Die Auswahl verwendet die Voice-IDs von vits-web (z.B. de_DE-thorsten-medium).'))?></p>

    <label><?=h(t('admin.settings.tts.rate_label', 'Lesegeschwindigkeit'))?></label>
    <div class="grid" style="grid-template-columns: 1fr auto; align-items:center; gap:10px;">
      <input id="ttsRateInput" type="range" name="tts_rate" min="0.5" max="1.5" step="0.05" value="<?=h((string)$ttsRate)?>">
      <span id="ttsRateLabel" class="pill" style="min-width:70px; text-align:center;">×<?=h(number_format($ttsRate, 2))?></span>
    </div>
    <p class="muted"><?=h(t('admin.settings.tts.rate_hint', '0,5 = langsam, 1,0 = normal, 1,5 = schnell.'))?></p>

    <label style="margin-top:10px;"><?=h(t('admin.settings.tts.sample_label', 'Vorleseprobe'))?></label>
    <div class="grid" style="grid-template-columns: 1fr auto; gap:10px; align-items:center;">
      <textarea id="ttsSampleText" rows="3" style="resize:vertical;"><?=h(t('admin.settings.tts.sample_text_default', 'Dies ist eine Vorleseprobe für die gewählte Stimme.'))?></textarea>
      <select id="ttsSampleLang" class="input" style="min-width:180px;">
        <option value="de"><?=h(t('admin.settings.tts.sample_lang_de', 'Deutsch'))?></option>
        <option value="en"><?=h(t('admin.settings.tts.sample_lang_en', 'Englisch'))?></option>
      </select>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:10px; margin-top:10px; flex-wrap:wrap;">
      <button class="btn secondary" type="button" id="ttsSamplePlay"><?=h(t('admin.settings.tts.sample_play', 'Vorlesen'))?></button>
      <button class="btn secondary" type="button" id="ttsSampleStop"><?=h(t('admin.settings.tts.sample_stop', 'Stopp'))?></button>
      <span id="ttsSampleStatus" class="muted"><?=h(t('admin.settings.tts.sample_status_ready', 'Bereit.'))?></span>
    </div>
    <p class="muted"><?=h(t('admin.settings.tts.sample_hint', 'Die Vorleseprobe nutzt vits-web (falls verfügbar), sonst die Browser-Stimme.'))?></p>

    <div class="actions">
      <button class="btn primary" type="submit"><?=h(t('admin.settings.save_button', 'Speichern'))?></button>
    </div>
  </form>

  <script>
    (function(){
      const input = document.getElementById('ttsRateInput');
      const label = document.getElementById('ttsRateLabel');
      if (input && label) {
        const render = () => { const v = Number(input.value || 1); label.textContent = '×' + v.toFixed(2); };
        input.addEventListener('input', render);
        render();
      }
    })();
  </script>

  <script>
    (function(){
      const playBtn = document.getElementById('ttsSamplePlay');
      const stopBtn = document.getElementById('ttsSampleStop');
      const status = document.getElementById('ttsSampleStatus');
      const sampleText = document.getElementById('ttsSampleText');
      const sampleLang = document.getElementById('ttsSampleLang');
      const rateInput = document.getElementById('ttsRateInput');
      const voiceDe = document.getElementById('ttsVoiceDe');
      const voiceEn = document.getElementById('ttsVoiceEn');
      const vitsModuleUrl = <?=json_encode(url('assets/vits-web/dist/vits-web.js'))?>;
      const tSampleReady = <?=json_encode(t('admin.settings.tts.sample_status_ready', 'Bereit.'))?>;
      const tSampleLoading = <?=json_encode(t('admin.settings.tts.sample_status_loading', 'Vorlesemodell wird geladen …'))?>;
      const tSampleReading = <?=json_encode(t('admin.settings.tts.sample_status_reading', 'Liest gerade …'))?>;
      const tSampleStopped = <?=json_encode(t('admin.settings.tts.sample_status_stopped', 'Vorlesen wurde gestoppt.'))?>;
      const tSampleTextMissing = <?=json_encode(t('admin.settings.tts.sample_status_text_missing', 'Bitte einen Text für die Vorleseprobe eingeben.'))?>;
      const webSpeechSupported = typeof window !== 'undefined'
        && 'speechSynthesis' in window
        && 'SpeechSynthesisUtterance' in window;
      let vitsModule = null;
      let vitsSession = null;
      let vitsAudio = null;
      let vitsLoading = false;

      function setStatus(text){
        if (status) status.textContent = text;
      }

      function stopAll(){
        if (vitsAudio) {
          try { vitsAudio.pause(); vitsAudio.currentTime = 0; } catch (e) {}
          vitsAudio = null;
        }
        if (webSpeechSupported) {
          try { speechSynthesis.cancel(); } catch (e) {}
        }
        setStatus(tSampleReady);
      }

      function pickVoice(lang, preferredName){
        if (!webSpeechSupported) return null;
        const voices = speechSynthesis.getVoices ? speechSynthesis.getVoices() : [];
        if (!voices || voices.length === 0) return null;
        const pref = (preferredName || '').toLowerCase().trim();
        const isMatch = (voice, needle) => voice?.name && voice.name.toLowerCase().includes(needle);

        if (pref !== '') {
          const prefExact = voices.find(v => v?.name && v.name.toLowerCase() === pref && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()));
          if (prefExact) return prefExact;
          const prefLoose = voices.find(v => isMatch(v, pref));
          if (prefLoose) return prefLoose;
        }

        const premiumVendors = ['google', 'microsoft', 'natural', 'neural'];
        const premiumVoice = voices.find(v => v?.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()) && premiumVendors.some(p => isMatch(v, p)));
        if (premiumVoice) return premiumVoice;

        const exactLocal = voices.find(v => v && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()) && v.localService);
        if (exactLocal) return exactLocal;
        const exact = voices.find(v => v && v.lang && v.lang.toLowerCase().startsWith(lang.toLowerCase()));
        if (exact) return exact;
        return voices[0] || null;
      }

      function currentLang(){
        return sampleLang && sampleLang.value === 'en' ? 'en-US' : 'de-DE';
      }

      function currentVoiceId(){
        if (!sampleLang) return '';
        if (sampleLang.value === 'en') return voiceEn?.value || '';
        return voiceDe?.value || '';
      }

      function currentRate(){
        const raw = Number(rateInput?.value || 1);
        if (!Number.isFinite(raw) || raw <= 0) return 1;
        return Math.max(0.5, Math.min(1.5, raw));
      }

      function loadVits(){
        if (vitsModule || vitsLoading) return Promise.resolve(vitsModule);
        vitsLoading = true;
        setStatus(tSampleLoading);
        return import(vitsModuleUrl)
          .then((mod) => {
            if (mod && mod.predict) {
              vitsModule = mod;
            } else if (mod && mod.default && mod.default.predict) {
              vitsModule = mod.default;
            }
            return vitsModule;
          })
          .catch((err) => {
            console.warn('vits-web failed to load', err);
            vitsModule = null;
            return null;
          })
          .finally(() => {
            vitsLoading = false;
          });
      }

      function ensureVitsSession(voiceId){
        if (vitsSession && vitsSession.voiceId === voiceId) return Promise.resolve(vitsSession);
        return loadVits().then((mod) => {
          if (!mod || !mod.TtsSession) return null;
          setStatus(tSampleLoading);
          return mod.TtsSession.create({ voiceId }).then((session) => {
            vitsSession = session;
            return session;
          }).catch((err) => {
            console.warn('vits-web session failed', err);
            return null;
          });
        });
      }

      function speakWithVits(text){
        const normalized = typeof text === 'string' ? text.trim() : '';
        if (!normalized) return Promise.resolve(false);
        const voiceId = currentVoiceId() || (sampleLang?.value === 'en' ? 'en_US-lessac-medium' : 'de_DE-thorsten-medium');
        return ensureVitsSession(voiceId).then((session) => {
          if (!session) return false;
          stopAll();
          setStatus(tSampleReading);
          return session.predict(normalized).then((blob) => {
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            audio.playbackRate = currentRate();
            audio.onended = () => {
              URL.revokeObjectURL(url);
              vitsAudio = null;
              setStatus(tSampleReady);
            };
            audio.onerror = () => {
              URL.revokeObjectURL(url);
              vitsAudio = null;
              setStatus(tSampleStopped);
            };
            vitsAudio = audio;
            return audio.play().then(() => true).catch(() => {
              URL.revokeObjectURL(url);
              vitsAudio = null;
              setStatus(tSampleStopped);
              return false;
            });
          }).catch((err) => {
            console.warn('vits-web playback failed', err);
            setStatus(tSampleStopped);
            return false;
          });
        });
      }

      function speakWithWebSpeech(text){
        if (!webSpeechSupported) return false;
        const normalized = typeof text === 'string' ? text.trim() : '';
        if (!normalized) return false;
        stopAll();
        const utter = new SpeechSynthesisUtterance(normalized);
        const lang = currentLang();
        utter.lang = lang;
        utter.rate = currentRate();
        const preferredName = sampleLang?.value === 'en' ? voiceEn?.value : voiceDe?.value;
        const voice = pickVoice(lang, preferredName || '');
        if (voice) utter.voice = voice;
        utter.onstart = () => setStatus(tSampleReading);
        utter.onend = () => setStatus(tSampleReady);
        utter.onerror = () => setStatus(tSampleStopped);
        speechSynthesis.speak(utter);
        return true;
      }

      function speakSample(){
        const text = sampleText?.value || '';
        if (text.trim() === '') {
          setStatus(tSampleTextMissing);
          return;
        }
        setStatus(tSampleReading);
        speakWithVits(text).then((ok) => {
          if (!ok) speakWithWebSpeech(text);
        });
      }

      if (playBtn) playBtn.addEventListener('click', speakSample);
      if (stopBtn) stopBtn.addEventListener('click', stopAll);
      if (webSpeechSupported && typeof speechSynthesis.addEventListener === 'function') {
        speechSynthesis.addEventListener('voiceschanged', () => setStatus(tSampleReady));
      }
    })();
  </script>
</div>

<div class="card">
  <h2><?=h(t('admin.settings.student_intro.title', 'Schüler-Startseite (Intro)'))?></h2>
  <p class="muted">
    <?=h(t('admin.settings.student_intro.desc', 'Diese Seite sieht jeder Schüler als erstes. Du kannst Platzhalter einfügen (z.B. für persönliche Begrüßung).'))?>
  </p>

  <div class="panel" style="padding:10px; margin-bottom:10px;">
    <label><?=h(t('admin.settings.student_intro.placeholder_label', 'Platzhalter einfügen'))?></label>
    <div class="actions" style="justify-content:flex-start; gap:10px; flex-wrap:wrap; margin-top:6px;">
      <select id="phSelect" class="input" style="min-width:260px;">
        <?php foreach (known_intro_placeholders() as $token => $label): ?>
          <option value="<?=h($token)?>"><?=h($label)?> — <?=h($token)?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn secondary" type="button" id="btnInsertPh"><?=h(t('admin.settings.student_intro.insert_placeholder', 'Einfügen'))?></button>
      <span class="muted"><?=h(t('admin.settings.student_intro.example', 'Beispiel: „Hallo {{first_name}}!“'))?></span>
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
      <button class="btn primary" type="submit"><?=h(t('admin.settings.student_intro.save', 'Intro speichern'))?></button>
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

    quill.root.innerHTML = initialHtml || <?=json_encode(t('admin.settings.student_intro.default_html', '<p><strong>Hallo {{first_name}}!</strong></p><p>Bitte fülle den Bericht Schritt für Schritt aus.</p>'))?>;

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
  <h2><?=h(t('admin.settings.logo.title', 'Logo'))?></h2>

  <?php if ($logo): ?>
    <p class="muted"><?=h(t('admin.settings.logo.current', 'Aktuelles Logo:'))?></p>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <img src="<?=h(url($logo))?>" alt="<?=h(t('admin.settings.logo.alt', 'Logo'))?>" style="height:54px; width:auto; background:#fff; padding:8px; border:1px solid #e5e7eb; border-radius:12px;">
      <form method="post" onsubmit="return confirm(<?=json_encode(t('admin.settings.logo.confirm_remove', 'Logo wirklich entfernen?'))?>);">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="remove_logo">
        <button class="btn danger" type="submit"><?=h(t('admin.settings.logo.remove', 'Logo entfernen'))?></button>
      </form>
    </div>
  <?php else: ?>
    <p class="muted"><?=h(t('admin.settings.logo.none', 'Kein Logo gesetzt.'))?></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" style="margin-top:14px;" id="logoForm">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="upload_logo">

    <label><?=h(t('admin.settings.logo.upload_label', 'Neues Logo hochladen (PNG/JPG/WEBP)'))?></label>
    <input id="brandLogoInput" type="file" name="brand_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required>

    <div class="actions">
      <button class="btn primary" type="submit"><?=h(t('admin.settings.logo.upload_button', 'Logo hochladen'))?></button>
    </div>

    <p class="muted"><?=h(t('admin.settings.logo.upload_hint', 'Die Vorschau zeigt dein gewähltes Bild sofort. Hochgeladen wird erst beim Klick auf „Logo hochladen“.'))?></p>
  </form>
</div>

<script>
(function(){
  const root = document.documentElement;
  const tOrgFallback = <?=json_encode(t('admin.settings.preview.org_fallback', 'LEB Tool'))?>;
  const tFromEmailFallback = <?=json_encode(t('admin.settings.mail.fallback_email', 'no-reply@example.org'))?>;
  const tFromNameFallback = <?=json_encode(t('admin.settings.preview.from_name_fallback', 'LEB Tool'))?>;
  const tGroupKeyPlaceholder = <?=json_encode(t('admin.settings.groups.key_placeholder', 'Original (Template) – z.B. Deutsch'))?>;
  const tGroupTitlePlaceholder = <?=json_encode(t('admin.settings.groups.title_placeholder', 'Anzeige – z.B. Deutsch – Schreiben'))?>;
  const tGroupRemoveTitle = <?=json_encode(t('admin.settings.groups.remove', 'Entfernen'))?>;

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
    previewOrg.textContent = orgInput.value.trim() || tOrgFallback;
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
  previewOrg.textContent = orgInput.value.trim() || tOrgFallback;

  function applyMailPreview(){
    const fe = (fromEmailInput.value || '').trim() || tFromEmailFallback;
    const fn = (fromNameInput.value || '').trim() || tFromNameFallback;
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
      <input class="input" name="group_key[]" placeholder="${tGroupKeyPlaceholder.replace(/"/g,'&quot;')}" value="${String(k).replace(/"/g,'&quot;')}">
      <input class="input" name="group_title[]" placeholder="${tGroupTitlePlaceholder.replace(/"/g,'&quot;')}" value="${String(t).replace(/"/g,'&quot;')}">
      <button class="btn danger" type="button" title="${tGroupRemoveTitle.replace(/"/g,'&quot;')}">×</button>
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
