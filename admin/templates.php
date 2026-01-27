<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$err = '';
$ok  = '';

function ensure_dir(string $p): void {
  if (!is_dir($p)) @mkdir($p, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_active') {
      $templateId = (int)($_POST['template_id'] ?? 0);
      if ($templateId <= 0) throw new RuntimeException('Ungültiges Template.');

      $st = $pdo->prepare("SELECT is_active FROM templates WHERE id=? LIMIT 1");
      $st->execute([$templateId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Template nicht gefunden.');

      $cur = (int)($row['is_active'] ?? 0);
      $next = $cur === 1 ? 0 : 1;

      $pdo->prepare("UPDATE templates SET is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
          ->execute([$next, $templateId]);

      audit('template_toggle_active', (int)current_user()['id'], ['template_id' => $templateId, 'is_active' => $next]);
      $ok = $next === 1 ? "Template #{$templateId} ist jetzt aktiv." : "Template #{$templateId} ist jetzt inaktiv.";
    }

    if ($action === 'upload') {
      $name = trim((string)($_POST['name'] ?? ''));
      $version = (int)($_POST['version'] ?? 1);

      if ($name === '') throw new RuntimeException('Template-Name fehlt.');
      if ($version < 1) throw new RuntimeException('Ungültige Version.');

      if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bitte eine PDF auswählen.');
      }

      $tmp = $_FILES['pdf']['tmp_name'];
      $origName = (string)($_FILES['pdf']['name'] ?? 'template.pdf');
      if (!preg_match('/\.pdf$/i', $origName)) throw new RuntimeException('Datei ist keine PDF (.pdf).');

      $sha = hash_file('sha256', $tmp) ?: null;

      $cfg = app_config();
      $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';
      $rootAbs = realpath(__DIR__ . '/..');
      if (!$rootAbs) throw new RuntimeException('Root-Pfad konnte nicht ermittelt werden.');
      $uploadsAbs = $rootAbs . '/' . $uploadsRel;

      ensure_dir($uploadsAbs);
      ensure_dir($uploadsAbs . '/templates');

      $stmt = $pdo->prepare("
        INSERT INTO templates (name, template_version, pdf_storage_path, pdf_original_filename, pdf_sha256, created_by_user_id, is_active)
        VALUES (?, ?, '', ?, ?, ?, 1)
      ");
      $stmt->execute([$name, $version, $origName, $sha, (int)current_user()['id']]);
      $tplId = (int)$pdo->lastInsertId();

      $tplDirAbs = $uploadsAbs . '/templates/' . $tplId;
      ensure_dir($tplDirAbs);

      $safeBase = preg_replace('/[^a-z0-9._-]+/i', '_', pathinfo($origName, PATHINFO_FILENAME));
      if ($safeBase === '' || $safeBase === '_') $safeBase = 'template';

      $destAbs = $tplDirAbs . '/' . $safeBase . '_v' . $version . '.pdf';
      $destRel = $uploadsRel . '/templates/' . $tplId . '/' . basename($destAbs);

      if (!move_uploaded_file($tmp, $destAbs)) throw new RuntimeException('PDF konnte nicht gespeichert werden.');

      $pdo->prepare("UPDATE templates SET pdf_storage_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
          ->execute([$destRel, $tplId]);

      audit('template_upload', (int)current_user()['id'], ['template_id'=>$tplId]);
      $ok = "Template hochgeladen (#{$tplId}). Jetzt „Felder auslesen“ klicken.";
    }

    if ($action === 'upload_pdf_font') {
      if (!isset($_FILES['pdf_font_file']) || ($_FILES['pdf_font_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Keine PDF-Schriftart hochgeladen.');
      }
      $fontName = trim((string)($_POST['pdf_font_name'] ?? ''));
      if ($fontName === '') throw new RuntimeException('Bitte eine Schriftart aus der Liste wählen.');

      $cfg = app_config();
      if (!isset($cfg['pdf']) || !is_array($cfg['pdf'])) $cfg['pdf'] = [];
      if (!isset($cfg['pdf']['fonts']) || !is_array($cfg['pdf']['fonts'])) $cfg['pdf']['fonts'] = [];

      $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';
      $rootAbs = realpath(__DIR__ . '/..');
      if (!$rootAbs) throw new RuntimeException('Root-Pfad konnte nicht ermittelt werden.');
      $fontsAbs = $rootAbs . '/' . $uploadsRel . '/pdf_fonts';
      if (!is_dir($fontsAbs)) {
        @mkdir($fontsAbs, 0755, true);
      }

      $tmp = $_FILES['pdf_font_file']['tmp_name'];
      $mime = mime_content_type($tmp) ?: '';
      $allowed = [
        'font/ttf' => 'ttf',
        'font/otf' => 'otf',
        'application/x-font-ttf' => 'ttf',
        'application/x-font-otf' => 'otf',
        'application/font-sfnt' => 'ttf',
      ];
      $ext = $allowed[$mime] ?? '';
      if ($ext === '') {
        $original = (string)($_FILES['pdf_font_file']['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
      }
      if (!in_array($ext, ['ttf', 'otf'], true)) {
        throw new RuntimeException('Schriftart muss TTF oder OTF sein.');
      }

      $original = (string)($_FILES['pdf_font_file']['name'] ?? 'font.' . $ext);
      $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($original, PATHINFO_FILENAME)) ?: 'font';
      $dest = $base . '.' . $ext;
      $i = 1;
      while (is_file($fontsAbs . '/' . $dest)) {
        $dest = $base . '-' . $i . '.' . $ext;
        $i++;
      }

      $destAbs = $fontsAbs . '/' . $dest;
      if (!move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException('Konnte Schriftart nicht speichern.');
      }

      $cfg['pdf']['fonts'][] = [
        'name' => $fontName,
        'file' => $uploadsRel . '/pdf_fonts/' . $dest,
      ];

      $cfgPath = __DIR__ . '/../config.php';
      $export = "<?php\n// config.php (updated by admin/templates.php)\nreturn " . var_export($cfg, true) . ";\n";
      if (file_put_contents($cfgPath, $export, LOCK_EX) === false) {
        throw new RuntimeException('Konnte config.php nicht schreiben (Rechte?).');
      }

      audit('pdf_font_upload', (int)current_user()['id'], ['file' => $dest, 'name' => $fontName]);
      $ok = 'PDF-Schriftart hochgeladen.';
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$templates = $pdo->query("
  SELECT id, name, template_version, pdf_storage_path, pdf_original_filename, is_active, created_at
  FROM templates
  ORDER BY created_at DESC
")->fetchAll();

$cfg = app_config();
$pdfFontsCfg = $cfg['pdf']['fonts'] ?? [];
if (!is_array($pdfFontsCfg)) $pdfFontsCfg = [];

render_admin_header('Admin – Templates');
?>

<!-- FILE GENERATED BY CHATGPT: templates.php (standalone-aligned parser) -->

<style>
/* (styles unchanged; see earlier version) */
.wiz-preview { position: sticky; top: 18px; align-self: start; }
.small { font-size: 0.92rem; }

.table-scroll{
  max-height: 62vh;
  overflow: auto;
  border: 1px solid var(--border);
  border-radius: 12px;
}

#fieldsTbl{
  width: 100%;
  min-width: 1400px;
  border-collapse: separate;
  border-spacing: 0;
}
#fieldsTbl th, #fieldsTbl td{
  vertical-align: top;
  border-bottom: 1px solid var(--border);
  padding: 10px;
}
#fieldsTbl thead th{
  position: sticky;
  top: 0;
  z-index: 5;
  background: var(--card, #fff);
}

#fieldsTbl th.col-child, #fieldsTbl td.col-child { min-width: 70px; width: 70px; }
#fieldsTbl th.col-teach, #fieldsTbl td.col-teach { min-width: 80px; width: 80px; }
#fieldsTbl th.col-name,  #fieldsTbl td.col-name  { min-width: 240px; }
#fieldsTbl th.col-type,  #fieldsTbl td.col-type  { min-width: 180px; }
#fieldsTbl th.col-label, #fieldsTbl td.col-label { min-width: 280px; }
#fieldsTbl th.col-help,  #fieldsTbl td.col-help  { min-width: 560px; }

#fieldsTbl input[type="text"], #fieldsTbl select{
  width: 100%;
  box-sizing: border-box;
}

.actions-row { display:flex; align-items:center; gap:10px; }
.actions-row .file-input { max-width:260px; }

.copybar{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  align-items:flex-end;
  padding:12px;
  border:1px dashed var(--border);
  border-radius:12px;
  margin-top:12px;
}
.copybar .block{ min-width: 280px; }
.copyopts{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  padding:10px 12px;
  border:1px solid var(--border);
  border-radius:12px;
  background: var(--card, #fff);
}
.copyopts label{ display:flex; align-items:center; gap:8px; margin:0; }
.copybar .actions{ justify-content:flex-start; }

.expert-settings summary{ cursor:pointer; font-weight:600; }
.expert-settings .grid{ margin-top:10px; gap:10px; }
.expert-settings .checklist{ display:flex; align-items:center; gap:8px; margin-top:6px; }
.expert-settings .hint{ margin-top:8px; }

#wizGrid.is-preview-hidden{ grid-template-columns: 1fr !important; }
#wizPreviewCol.is-hidden{ display:none !important; }

tr.flash { animation: flashRow 0.7s ease; }
@keyframes flashRow { 0% { background: rgba(176,0,32,0.18); } 100% { background: transparent; } }

tr.tpl-inactive { opacity: 0.65; }
</style>

<div class="card"><h1>Templates</h1></div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2>PDF Template hochladen</h2>
  <form id="uploadTemplateForm" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="upload">
    <div class="grid">
      <div>
        <label>Name</label>
        <input name="name" required placeholder="z.B. LEG Halbjahr">
      </div>
      <div>
        <label>Version</label>
        <input name="version" type="number" min="1" value="1" required>
      </div>
    </div>
    <label>PDF Datei</label>
    <div class="actions actions-row">
      <input class="file-input" type="file" name="pdf" accept=".pdf,application/pdf" required>
      <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;">Hochladen</a>
    </div>
  </form>
</div>

<div class="card">
  <h2>Vorhandene Templates</h2>
  <?php if (!$templates): ?>
    <p class="muted">Noch keine Templates vorhanden.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>ID</th><th>Name</th><th>Version</th><th>Status</th><th>PDF</th><th>Aktion</th></tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $t): $isActive = (int)($t['is_active'] ?? 0) === 1; ?>
          <tr class="<?=($isActive ? '' : 'tpl-inactive')?>">
            <td><?=h((string)$t['id'])?></td>
            <td><?=h($t['name'])?></td>
            <td><?=h((string)$t['template_version'])?></td>
            <td style="white-space:nowrap;">
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="template_id" value="<?=h((string)$t['id'])?>">
                <button class="btn <?=($isActive ? 'secondary' : 'primary')?>" type="submit"
                        title="<?=($isActive ? 'Template deaktivieren' : 'Template aktivieren')?>"><?=($isActive ? 'aktiv' : 'inaktiv')?></button>
              </form>
            </td>
            <td>
              <a href="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>" target="_blank">
                <?=h($t['pdf_original_filename'] ?: 'PDF')?>
              </a>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn secondary js-extract" type="button"
                 data-template-id="<?=h((string)$t['id'])?>"
                 data-pdf-url="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>">Felder auslesen</a>
              <a class="btn secondary js-font-scan" type="button"
                 data-template-id="<?=h((string)$t['id'])?>"
                 data-template-name="<?=h((string)($t['name'] ?? ''))?>"
                 data-pdf-url="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>">Schriftarten prüfen</a>
              <a class="btn secondary" href="<?=h(url('admin/template_fields.php?template_id='.(int)$t['id']))?>">Bearbeiten</a>
              <a class="btn secondary" href="<?=h(url('admin/template_mappings.php?template_id='.(int)$t['id']))?>">Mapping</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" id="fontAuditCard">
  <h2>PDF-Schriftarten prüfen</h2>
  <p class="muted">Liest aus der PDF-Vorlage die in Formularfeldern verwendeten Schriftarten aus und zeigt fehlende Fonts an.</p>

  <div class="row" style="gap:12px; flex-wrap:wrap; align-items:flex-start;">
    <div style="min-width:240px;">
      <label>Ausgewähltes Template</label>
      <div id="fontAuditTemplate" class="muted">—</div>
    </div>
    <div style="flex:1;">
      <label>Fehlende Schriftarten</label>
      <ul id="missingFontsList" class="muted" style="margin:6px 0 0 18px;"></ul>
      <div id="missingFontsEmpty" class="muted" style="margin-top:6px;">Noch keine Prüfung durchgeführt.</div>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" style="margin-top:14px;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="upload_pdf_font">
    <input type="hidden" name="pdf_font_name" id="pdfFontNameInput">

    <div class="grid" style="grid-template-columns: 1fr 2fr; align-items:end; gap:12px;">
      <div>
        <label>Fehlende Schriftart auswählen</label>
        <select id="missingFontSelect" required>
          <option value="">Bitte zuerst prüfen…</option>
        </select>
        <div class="muted" style="margin-top:6px;">Die Schriftart wird automatisch mit dem Font-Namen aus der PDF benannt.</div>
      </div>
      <div>
        <label>Datei (TTF/OTF)</label>
        <input type="file" name="pdf_font_file" accept=".ttf,.otf,font/ttf,font/otf,application/x-font-ttf,application/x-font-otf" required>
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit" id="btnUploadMissingFont" disabled>Schriftart hochladen</button>
    </div>
  </form>
</div>

<div class="card" id="wizard" style="display:none;">
  <h2>Import-Wizard: Rechte & Basisdaten</h2>
  <p class="muted" id="wizMeta"></p>

  <div class="actions" style="margin-top:12px; flex-wrap:wrap;">
    <button class="btn secondary" id="btnChildNone" type="button">Kind: nichts (sichtbar)</button>
    <button class="btn secondary" id="btnChildAll" type="button">Kind: alle (sichtbar)</button>
    <button class="btn secondary" id="btnTeachNone" type="button">Lehrer: nichts (sichtbar)</button>
    <button class="btn secondary" id="btnTeachAll" type="button">Lehrer: alle (sichtbar)</button>

    <button class="btn secondary" id="btnTogglePreview" type="button">Vorschau ausblenden</button>

    <button class="btn primary" id="btnImport" type="button">Importieren</button>
    <button class="btn secondary" id="btnCancel" type="button">Abbrechen</button>
  </div>

  <div class="copybar">
    <div class="block">
      <label>Eigenschaften übernehmen von Template</label>
      <select id="copyFromTemplate">
        <option value="">— kein —</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?=h((string)$t['id'])?>">
            #<?=h((string)$t['id'])?> · <?=h($t['name'])?> v<?=h((string)$t['template_version'])?>
            <?=((int)($t['is_active'] ?? 0) === 1 ? '' : ' (inaktiv)')?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="muted small">Match über exakten Feldnamen.</div>
    </div>

    <div class="block" style="min-width:520px;">
      <div class="muted small" style="margin-bottom:6px;">Welche Eigenschaften übernehmen?</div>
      <div class="copyopts">
        <label><input type="checkbox" id="cpType" checked> Typ</label>
        <label><input type="checkbox" id="cpLabel" checked> Label</label>
        <label><input type="checkbox" id="cpHelp" checked> Beschreibung</label>
        <label><input type="checkbox" id="cpRights" checked> Rechte (Kind/Lehrer)</label>
        <label><input type="checkbox" id="cpMeta" checked> Meta/Optionen</label>
      </div>
      <div class="muted small" style="margin-top:6px;">
        Meta enthält z.B. Radio-Optionen/Skalen/Datumsformat usw. (falls im Editor gespeichert).
      </div>
    </div>

    <div class="actions">
      <button class="btn secondary" id="btnCopyVisible" type="button">Auf sichtbare anwenden</button>
      <button class="btn secondary" id="btnCopyAll" type="button">Auf alle anwenden</button>
    </div>

    <div class="muted small" id="copyResult" style="min-width:220px;">&nbsp;</div>
  </div>

  <details class="card expert-settings" id="expertSettings" style="margin-top:12px;">
    <summary>Experteneinstellungen (Parsing)</summary>
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
      <div>
        <label for="parsePadLeft">padLeft</label>
        <input id="parsePadLeft" type="number" min="0" step="1" value="18">
        <div class="muted small">Padding links vor Feld (px)</div>
      </div>
      <div>
        <label for="parseYtol">yTol</label>
        <input id="parseYtol" type="number" min="0" step="1" value="14">
        <div class="muted small">Y-Toleranz (px)</div>
      </div>
      <div>
        <label for="parseLineCluster">yLineCluster</label>
        <input id="parseLineCluster" type="number" min="0" step="1" value="7">
        <div class="muted small">Zeilen-Cluster (px)</div>
      </div>
      <div>
        <label for="parseGapWord">gapWord</label>
        <input id="parseGapWord" type="number" min="0" step="1" value="4">
        <div class="muted small">Wortabstand (px)</div>
      </div>
      <label class="checklist">
        <input id="parseKeepLineBreaks" type="checkbox">
        keepLineBreaks
      </label>
      <label class="checklist">
        <input id="parseFillHelpFromLabel" type="checkbox">
        fillHelpFromLabel
      </label>
      <label class="checklist">
        <input id="parseDebugLabelCandidates" type="checkbox">
        debugLabelCandidates
      </label>
    </div>
    <div class="muted small hint">
      Tipp: Wenn 2-zeilige Labels fehlen → yTol erhöhen (typisch 24–36). Wenn Überschriften reinrutschen → yTol senken oder padLeft erhöhen.
    </div>
  </details>

  <div class="grid" id="wizGrid" style="grid-template-columns: 1.2fr 0.8fr; gap:14px; margin-top:12px;">
    <div style="overflow:hidden;">
      <div class="grid" style="grid-template-columns: 1fr 200px; gap:12px; align-items:end;">
        <div>
          <label>Filter Feldname</label>
          <input id="fieldFilter" placeholder="z.B. Soc, Work, Eng, Math …">
          <div class="muted small">Filter wirkt auch auf die Bulk-Buttons.</div>
        </div>
        <div class="actions" style="justify-content:flex-start;">
          <button class="btn secondary" type="button" id="btnClearFilter">Filter löschen</button>
        </div>
      </div>

      <div class="table-scroll" style="margin-top:10px;">
        <table id="fieldsTbl">
          <thead>
            <tr>
              <th class="col-child">Kind</th>
              <th class="col-teach">Lehrer</th>
              <th class="col-name">Feldname</th>
              <th class="col-type">Typ *</th>
              <th class="col-label">Label</th>
              <th class="col-help">Beschreibung (Help)</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <p class="muted small" style="margin-top:8px;">
        * Typ ist Pflicht. Wenn nicht sicher, bleibt Standard <code>radio</code>.
      </p>
    </div>

    <div id="wizPreviewCol">
      <div class="card wiz-preview" style="margin:0;">
        <h3 style="margin-top:0;">PDF Vorschau</h3>
        <div class="muted" id="pdfHint">Klicke links ein Feld, um es im PDF zu markieren.</div>

        <div style="display:flex; gap:8px; align-items:center; margin:10px 0; flex-wrap:wrap;">
          <button class="btn secondary" id="btnPrevPage" type="button">←</button>
          <div class="muted" id="pageInfo">Seite –</div>
          <button class="btn secondary" id="btnNextPage" type="button">→</button>
          <button class="btn secondary" id="btnToggleHighlights" type="button">Felder hervorheben: an</button>
        </div>

        <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden;">
          <canvas id="pdfCanvas" style="display:block; width:100%; height:auto;"></canvas>
        </div>

        <div class="muted small" style="margin-top:10px;">
          Tipp: Du kannst auch direkt im PDF auf ein Feld klicken → dann springt die Tabelle zur passenden Zeile.
        </div>
      </div>
    </div>
  </div>
</div>

<script type="module">
import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

const csrf = "<?=h(csrf_token())?>";

const wizard = document.getElementById('wizard');
const wizMeta = document.getElementById('wizMeta');
const tbody = document.querySelector('#fieldsTbl tbody');

const btnChildNone = document.getElementById('btnChildNone');
const btnChildAll  = document.getElementById('btnChildAll');
const btnTeachNone = document.getElementById('btnTeachNone');
const btnTeachAll  = document.getElementById('btnTeachAll');
const btnImport = document.getElementById('btnImport');
const btnCancel = document.getElementById('btnCancel');

const btnTogglePreview = document.getElementById('btnTogglePreview');
const wizGrid = document.getElementById('wizGrid');
const wizPreviewCol = document.getElementById('wizPreviewCol');

const pdfCanvas = document.getElementById('pdfCanvas');
const pdfHint = document.getElementById('pdfHint');
const pageInfo = document.getElementById('pageInfo');
const btnPrevPage = document.getElementById('btnPrevPage');
const btnNextPage = document.getElementById('btnNextPage');

const btnToggleHighlights = document.getElementById('btnToggleHighlights');

const fieldFilter = document.getElementById('fieldFilter');
const btnClearFilter = document.getElementById('btnClearFilter');
const fontAuditTemplate = document.getElementById('fontAuditTemplate');
const missingFontsList = document.getElementById('missingFontsList');
const missingFontsEmpty = document.getElementById('missingFontsEmpty');
const missingFontSelect = document.getElementById('missingFontSelect');
const pdfFontNameInput = document.getElementById('pdfFontNameInput');
const btnUploadMissingFont = document.getElementById('btnUploadMissingFont');

const uploadedPdfFonts = <?= json_encode(array_values($pdfFontsCfg)) ?>;
const uploadedFontKeys = new Set(
  uploadedPdfFonts
    .map(f => (f && f.name ? normalizeFontKey(f.name) : ''))
    .filter(Boolean)
);

const STANDARD_FONT_KEYS = new Set([
  'helvetica',
  'helvetica-bold',
  'helvetica-oblique',
  'helvetica-boldoblique',
  'times-roman',
  'times-bold',
  'times-italic',
  'times-bolditalic',
  'courier',
  'courier-bold',
  'courier-oblique',
  'courier-boldoblique',
  'symbol',
  'zapfdingbats',
]);

const FONT_KEY_ALIASES = new Map([
  ['helv', 'helvetica'],
  ['helv-bold', 'helvetica-bold'],
  ['helv-oblique', 'helvetica-oblique'],
  ['helv-boldoblique', 'helvetica-boldoblique'],
  ['tiro', 'times-roman'],
  ['tiro-bold', 'times-bold'],
  ['tiro-italic', 'times-italic'],
  ['tiro-bolditalic', 'times-bolditalic'],
  ['cour', 'courier'],
  ['cour-bold', 'courier-bold'],
  ['cour-oblique', 'courier-oblique'],
  ['cour-boldoblique', 'courier-boldoblique'],
]);

// Copy UI
const copyFromTemplate = document.getElementById('copyFromTemplate');
const btnCopyVisible = document.getElementById('btnCopyVisible');
const btnCopyAll = document.getElementById('btnCopyAll');
const copyResult = document.getElementById('copyResult');

const cpType  = document.getElementById('cpType');
const cpLabel = document.getElementById('cpLabel');
const cpHelp  = document.getElementById('cpHelp');
const cpRights= document.getElementById('cpRights');
const cpMeta  = document.getElementById('cpMeta');

// Parser UI
const parsePadLeft = document.getElementById('parsePadLeft');
const parseYtol = document.getElementById('parseYtol');
const parseLineCluster = document.getElementById('parseLineCluster');
const parseGapWord = document.getElementById('parseGapWord');
const parseKeepLineBreaks = document.getElementById('parseKeepLineBreaks');
const parseFillHelpFromLabel = document.getElementById('parseFillHelpFromLabel');
const parseDebugLabelCandidates = document.getElementById('parseDebugLabelCandidates');

const PARSE_STORAGE_KEY = 'wizard_parse_cfg_v2_standalone';
const PARSE_DEFAULTS = { padLeft:18, yTol:14, yLineCluster:7, gapWord:4, keepLineBreaks:false, fillHelpFromLabel:false, debugLabelCandidates:false };

let currentTemplateId = null;
let currentPdfUrl = null;

let fields = [];
let filterText = '';

let pdfDoc = null;
let currentPage = 1;
let currentHighlight = null;

const FIELD_TYPES = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];

let pageWidgets = new Map();
let rowByFieldName = new Map();
let showAllWidgetHighlights = true;

function normalizeType(rawType, multilineFlag) {
  const t = String(rawType || '').trim();
  const u = t.toUpperCase();
  if (u === 'TX' || u === 'TEXT') return multilineFlag ? 'multiline' : 'text';
  if (u === 'CH' || u === 'SELECT') return 'select';
  if (u === 'SIG' || u === 'SIGNATURE') return 'signature';
  if (u === 'BTN') return 'checkbox';
  if (u === 'CHECKBOX') return 'checkbox';
  if (u === 'RADIO') return 'radio';
  return 'radio';
}

function normalizeFontKey(name){
  let key = (name ?? '').toString().trim().replace(/^\//, '').toLowerCase();
  key = key.replace(/^[a-z]{6}\+/, '');
  key = key.replace(/[\s_]+/g, '-');
  return FONT_KEY_ALIASES.get(key) || key;
}

function extractFontNamesFromDa(da){
  const s = (da ?? '').toString();
  if (!s) return [];
  const out = [];
  const re = /\/([^\s]+)\s+[\d.]+\s+Tf/g;
  let m;
  while ((m = re.exec(s)) !== null) {
    if (m[1]) out.push(m[1]);
  }
  return out;
}

function collectFontName(out, raw){
  const name = (raw ?? '').toString().trim();
  if (!name) return;
  out.add(name.replace(/^\//, ''));
}

function renderMissingFonts(missing){
  missingFontsList.innerHTML = '';
  missingFontSelect.innerHTML = '';

  if (!missing.length) {
    missingFontsEmpty.textContent = 'Keine fehlenden Schriftarten gefunden.';
    missingFontsEmpty.style.display = '';
    missingFontSelect.innerHTML = '<option value="">Keine fehlenden Schriftarten</option>';
    pdfFontNameInput.value = '';
    btnUploadMissingFont.disabled = true;
    return;
  }

  missingFontsEmpty.style.display = 'none';
  for (const font of missing) {
    const li = document.createElement('li');
    li.textContent = font;
    missingFontsList.appendChild(li);

    const opt = document.createElement('option');
    opt.value = font;
    opt.textContent = font;
    missingFontSelect.appendChild(opt);
  }

  missingFontSelect.selectedIndex = 0;
  pdfFontNameInput.value = missingFontSelect.value;
  btnUploadMissingFont.disabled = !missingFontSelect.value;
}

async function scanPdfFonts(pdfUrl){
  const pdf = await pdfjsLib.getDocument({ url: pdfUrl, withCredentials:true }).promise;
  const found = new Set();

  if (pdf.getFieldObjects) {
    const fo = await pdf.getFieldObjects();
    if (fo && typeof fo === 'object') {
      for (const arr of Object.values(fo)) {
        if (!Array.isArray(arr)) continue;
        for (const field of arr) {
          const da = field?.defaultAppearance || field?.defaultStyle || '';
          for (const name of extractFontNamesFromDa(da)) {
            collectFontName(found, name);
          }
        }
      }
    }
  }

  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const annots = await page.getAnnotations({ intent:"display" });
    for (const a of annots) {
      const da = a?.defaultAppearance || a?.defaultStyle || '';
      for (const name of extractFontNamesFromDa(da)) {
        collectFontName(found, name);
      }
    }
  }

  const missing = [];
  for (const name of found) {
    const key = normalizeFontKey(name);
    if (!key) continue;
    if (STANDARD_FONT_KEYS.has(key)) continue;
    if (uploadedFontKeys.has(key)) continue;
    missing.push(name);
  }
  missing.sort((a, b) => a.localeCompare(b));
  return missing;
}

function clampNumber(value, fallback, min = null, max = null) {
  const n = Number(value);
  if (Number.isNaN(n) || !Number.isFinite(n)) return fallback;
  if (min !== null && n < min) return min;
  if (max !== null && n > max) return max;
  return n;
}

function loadParsingConfig() {
  let cfg = { ...PARSE_DEFAULTS };
  try {
    const stored = localStorage.getItem(PARSE_STORAGE_KEY);
    if (stored) {
      const parsed = JSON.parse(stored);
      if (parsed && typeof parsed === 'object') cfg = { ...cfg, ...parsed };
    }
  } catch (e) {}

  parsePadLeft.value = String(clampNumber(cfg.padLeft, PARSE_DEFAULTS.padLeft, 0));
  parseYtol.value = String(clampNumber(cfg.yTol, PARSE_DEFAULTS.yTol, 0));
  parseLineCluster.value = String(clampNumber(cfg.yLineCluster, PARSE_DEFAULTS.yLineCluster, 0));
  parseGapWord.value = String(clampNumber(cfg.gapWord, PARSE_DEFAULTS.gapWord, 0));
  parseKeepLineBreaks.checked = !!cfg.keepLineBreaks;
  parseFillHelpFromLabel.checked = !!cfg.fillHelpFromLabel;
  parseDebugLabelCandidates.checked = !!cfg.debugLabelCandidates;
}

function getParsingConfigFromUI() {
  const cfg = {
    padLeft: clampNumber(parsePadLeft.value, PARSE_DEFAULTS.padLeft, 0),
    yTol: clampNumber(parseYtol.value, PARSE_DEFAULTS.yTol, 0),
    yLineCluster: clampNumber(parseLineCluster.value, PARSE_DEFAULTS.yLineCluster, 0),
    gapWord: clampNumber(parseGapWord.value, PARSE_DEFAULTS.gapWord, 0),
    keepLineBreaks: !!parseKeepLineBreaks.checked,
    fillHelpFromLabel: !!parseFillHelpFromLabel.checked,
    debugLabelCandidates: !!parseDebugLabelCandidates.checked
  };
  try { localStorage.setItem(PARSE_STORAGE_KEY, JSON.stringify(cfg)); } catch(e){}
  return cfg;
}

[parsePadLeft, parseYtol, parseLineCluster, parseGapWord, parseKeepLineBreaks, parseFillHelpFromLabel, parseDebugLabelCandidates].forEach(input => {
  if (!input) return;
  const ev = (input.type === 'checkbox') ? 'change' : 'input';
  input.addEventListener(ev, () => { getParsingConfigFromUI(); });
});

function isVisibleByFilter(f) {
  const ft = (filterText || '').toLowerCase();
  if (!ft) return true;
  return String(f.name || '').toLowerCase().includes(ft);
}

function updateMeta() {
  const n = fields.length;
  const visible = fields.filter(isVisibleByFilter).length;
  const cChild = fields.filter(f => f.can_child_edit === 1).length;
  const cTeach = fields.filter(f => f.can_teacher_edit === 1).length;
  wizMeta.textContent = `Template #${currentTemplateId} – ${n} Felder (sichtbar: ${visible}) – Kind: ${cChild} – Lehrer: ${cTeach}`;
}

function flashRow(tr){
  if (!tr) return;
  tr.classList.remove('flash');
  void tr.offsetWidth;
  tr.classList.add('flash');
}

function renderTable() {
  tbody.innerHTML = '';
  rowByFieldName.clear();

  fields.forEach((f, idx) => {
    if (!isVisibleByFilter(f)) return;

    const tr = document.createElement('tr');
    tr.style.cursor = 'pointer';

    rowByFieldName.set(String(f.name || ''), tr);

    tr.addEventListener('click', () => {
      if (f.meta && f.meta.page && f.meta.rect) {
        currentHighlight = { page: f.meta.page, rect: f.meta.rect, name: f.name };
        currentPage = f.meta.page;
        renderPage();
      } else {
        currentHighlight = null;
        pdfHint.textContent = `Kein Positions-Rect für „${f.name}“ gefunden.`;
        renderPage();
      }
    });

    const tdK = document.createElement('td');
    tdK.className = 'col-child';
    const cbK = document.createElement('input');
    cbK.type = 'checkbox';
    cbK.checked = f.can_child_edit === 1;
    cbK.addEventListener('click', (e) => e.stopPropagation());
    cbK.addEventListener('change', (e) => { e.stopPropagation(); fields[idx].can_child_edit = cbK.checked ? 1 : 0; updateMeta(); });
    tdK.appendChild(cbK);

    const tdT = document.createElement('td');
    tdT.className = 'col-teach';
    const cbT = document.createElement('input');
    cbT.type = 'checkbox';
    cbT.checked = f.can_teacher_edit === 1;
    cbT.addEventListener('click', (e) => e.stopPropagation());
    cbT.addEventListener('change', (e) => { e.stopPropagation(); fields[idx].can_teacher_edit = cbT.checked ? 1 : 0; updateMeta(); });
    tdT.appendChild(cbT);

    const tdN = document.createElement('td');
    tdN.className = 'col-name';
    tdN.textContent = f.name;

    const tdTy = document.createElement('td');
    tdTy.className = 'col-type';
    const sel = document.createElement('select');
    FIELD_TYPES.forEach(t => {
      const o = document.createElement('option');
      o.value = t;
      o.textContent = t;
      if (t === f.type) o.selected = true;
      sel.appendChild(o);
    });
    if (!FIELD_TYPES.includes(f.type)) { fields[idx].type = 'radio'; sel.value = 'radio'; }
    sel.addEventListener('click', (e) => e.stopPropagation());
    sel.addEventListener('change', (e) => { e.stopPropagation(); fields[idx].type = sel.value; });
    tdTy.appendChild(sel);

    const tdL = document.createElement('td');
    tdL.className = 'col-label';
    const inpL = document.createElement('input');
    inpL.type = 'text';
    inpL.value = f.label || f.name;
    inpL.addEventListener('click', (e) => e.stopPropagation());
    inpL.addEventListener('input', (e) => { e.stopPropagation(); fields[idx].label = inpL.value; });
    tdL.appendChild(inpL);

    const tdH = document.createElement('td');
    tdH.className = 'col-help';
    const inpH = document.createElement('input');
    inpH.type = 'text';
    inpH.value = f.help_text || '';
    inpH.placeholder = 'Hint…';
    inpH.addEventListener('click', (e) => e.stopPropagation());
    inpH.addEventListener('input', (e) => { e.stopPropagation(); fields[idx].help_text = inpH.value; });
    tdH.appendChild(inpH);

    tr.appendChild(tdK);
    tr.appendChild(tdT);
    tr.appendChild(tdN);
    tr.appendChild(tdTy);
    tr.appendChild(tdL);
    tr.appendChild(tdH);

    tbody.appendChild(tr);
  });

  updateMeta();
}

function setChildVisible(val){
  fields = fields.map(f => (isVisibleByFilter(f) ? { ...f, can_child_edit: val } : f));
  renderTable();
}
function setTeachVisible(val){
  fields = fields.map(f => (isVisibleByFilter(f) ? { ...f, can_teacher_edit: val } : f));
  renderTable();
}

/* -------- Standalone-like label extraction (viewport coords, scale 1.6) -------- */
function avg(arr){ return arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : 0; }

function assembleMultilineLabel(items, yClusterTol, gapWordPx, keepLineBreaks) {
  if (!items || !items.length) return "";
  const sorted = [...items].sort((a,b)=>a.y-b.y || a.x-b.x);
  const lines = [];
  for (const t of sorted) {
    let placed = false;
    for (const line of lines) {
      if (Math.abs(line.y - t.y) <= yClusterTol) {
        line.items.push(t);
        line.y = (line.y*(line.items.length-1)+t.y)/line.items.length;
        placed = true; break;
      }
    }
    if (!placed) lines.push({ y:t.y, items:[t] });
  }
  lines.sort((a,b)=>a.y-b.y);
  const lineStrings = lines.map(line => {
    const parts = line.items.sort((a,b)=>a.x-b.x);
    let s=""; let last=null;
    for (const p of parts) {
      if (!last) s = p.s;
      else {
        const gap = p.x - (last.x + (last.w||0));
        s += (gap > gapWordPx ? " " : " ") + p.s;
      }
      last = p;
    }
    return s.replace(/\s+/g," ").trim();
  }).filter(Boolean);
  const joined = keepLineBreaks ? lineStrings.join("") : lineStrings.join(" ");
  return joined.replace(/\s+([,.;:!?])/g,"$1").replace(/\s+/g, keepLineBreaks ? (m)=>m : " ").trim();
}

async function buildLabelMapForPage(page, viewport, cfg){
  const annots = await page.getAnnotations({ intent:"display" });
  const widgets = annots.filter(a=>a.subtype==="Widget" && a.fieldName && a.rect).map(a=>{
    const r = pdfjsLib.Util.normalizeRect(a.rect);
    const vr = viewport.convertToViewportRectangle(r);
    const x1 = Math.min(vr[0],vr[2]), x2 = Math.max(vr[0],vr[2]);
    const y1 = Math.min(vr[1],vr[3]), y2 = Math.max(vr[1],vr[3]);
    const fieldName = String(a.fieldName);
    return { fieldName, baseKey: fieldName.replace(/-T$/i,""), rect:{x1,y1,x2,y2,yMid:(y1+y2)/2,xMid:(x1+x2)/2} };
  });

  const textContent = await page.getTextContent({ disableCombineTextItems:false });
  const textItems = (textContent.items||[]).map(it=>{
    const s = (it.str||"").replace(/\s+/g," ").trim();
    if (!s) return null;
    const tx = pdfjsLib.Util.transform(viewport.transform, it.transform);
    return { s, x:tx[4], y:tx[5], w:(typeof it.width==="number"? it.width*viewport.scale:0), h:(typeof it.height==="number"? it.height*viewport.scale:0) };
  }).filter(Boolean);

  const groups = new Map();
  for (const w of widgets){ if(!groups.has(w.baseKey)) groups.set(w.baseKey,[]); groups.get(w.baseKey).push(w); }

  const labelByBase = new Map();
  for (const [baseKey, arr] of groups.entries()){
    const yMid = avg(arr.map(w=>w.rect.yMid));
    const minX = Math.min(...arr.map(w=>w.rect.x1));
    const bandTop = yMid - cfg.yTol;
    const bandBot = yMid + cfg.yTol;
    const candidates = textItems.filter(t => t.x < (minX - cfg.padLeft) && t.y >= bandTop && t.y <= bandBot);
    const label = assembleMultilineLabel(candidates, cfg.yLineCluster, cfg.gapWord, cfg.keepLineBreaks);
    if (label) labelByBase.set(baseKey, label);
  }

  return labelByBase;
}
/* --------------------------------------------------------------------------- */

async function loadPdf(){
  pdfDoc = await pdfjsLib.getDocument({ url: currentPdfUrl, withCredentials:true }).promise;
  currentPage = 1;
  currentHighlight = null;

  pageWidgets = new Map();
  for (let p=1; p<=pdfDoc.numPages; p++){
    const page = await pdfDoc.getPage(p);
    const annots = await page.getAnnotations({ intent:"display" });
    const widgets=[];
    for (const a of annots){
      if (a.subtype!=="Widget") continue;
      const name = (a.fieldName||"").toString().trim();
      const rect = Array.isArray(a.rect)&&a.rect.length===4 ? a.rect : null;
      if (!name || !rect) continue;
      widgets.push({ name, rect });
    }
    pageWidgets.set(p, widgets);
  }
  renderPage();
}

async function renderPage(){
  if (!pdfDoc) return;
  const page = await pdfDoc.getPage(currentPage);
  const viewport = page.getViewport({ scale:1.2 });
  const ctx = pdfCanvas.getContext("2d");
  pdfCanvas.width = Math.floor(viewport.width);
  pdfCanvas.height = Math.floor(viewport.height);
  await page.render({ canvasContext:ctx, viewport }).promise;

  if (showAllWidgetHighlights){
    const widgets = pageWidgets.get(currentPage) || [];
    if (widgets.length){
      ctx.save();
      ctx.lineWidth = 1;
      ctx.strokeStyle = 'rgba(0, 120, 255, 0.35)';
      ctx.fillStyle   = 'rgba(0, 120, 255, 0.10)';
      for (const w of widgets){
        const [x1,y1,x2,y2] = w.rect;
        const p1 = viewport.convertToViewportPoint(x1,y1);
        const p2 = viewport.convertToViewportPoint(x2,y2);
        const rx = Math.min(p1[0],p2[0]);
        const ry = Math.min(p1[1],p2[1]);
        const rw = Math.abs(p2[0]-p1[0]);
        const rh = Math.abs(p2[1]-p1[1]);
        ctx.fillRect(rx,ry,Math.max(rw,6),Math.max(rh,6));
        ctx.strokeRect(rx,ry,Math.max(rw,6),Math.max(rh,6));
      }
      ctx.restore();
    }
  }

  if (currentHighlight && currentHighlight.page===currentPage && currentHighlight.rect){
    const [x1,y1,x2,y2] = currentHighlight.rect;
    const p1 = viewport.convertToViewportPoint(x1,y1);
    const p2 = viewport.convertToViewportPoint(x2,y2);
    const rx = Math.min(p1[0],p2[0]);
    const ry = Math.min(p1[1],p2[1]);
    const rw = Math.abs(p2[0]-p1[0]);
    const rh = Math.abs(p2[1]-p1[1]);
    ctx.save();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#b00020';
    ctx.fillStyle = 'rgba(176,0,32,0.12)';
    ctx.fillRect(rx,ry,rw,rh);
    ctx.strokeRect(rx,ry,rw,rh);
    ctx.restore();
    pdfHint.textContent = `Markiert: ${currentHighlight.name}`;
  } else {
    pdfHint.textContent = 'Klicke links ein Feld, um es im PDF zu markieren. Oder klicke im PDF → Tabelle springt.';
  }

  pageInfo.textContent = `Seite ${currentPage} / ${pdfDoc.numPages}`;
  btnPrevPage.disabled = currentPage <= 1;
  btnNextPage.disabled = currentPage >= pdfDoc.numPages;
  btnToggleHighlights.textContent = showAllWidgetHighlights ? 'Felder hervorheben: an' : 'Felder hervorheben: aus';
}

btnPrevPage.addEventListener('click', ()=>{ if(currentPage>1){ currentPage--; renderPage(); }});
btnNextPage.addEventListener('click', ()=>{ if(pdfDoc && currentPage<pdfDoc.numPages){ currentPage++; renderPage(); }});
btnToggleHighlights.addEventListener('click', ()=>{ showAllWidgetHighlights = !showAllWidgetHighlights; if(pdfDoc) renderPage(); });

pdfCanvas.addEventListener('click', (ev)=>{
  if (!pdfDoc) return;
  const rect = pdfCanvas.getBoundingClientRect();
  const sx = pdfCanvas.width / rect.width;
  const sy = pdfCanvas.height / rect.height;
  const cx = (ev.clientX-rect.left)*sx;
  const cy = (ev.clientY-rect.top)*sy;

  pdfDoc.getPage(currentPage).then(page=>{
    const viewport = page.getViewport({ scale:1.2 });
    const [pdfX,pdfY] = viewport.convertToPdfPoint(cx,cy);

    const widgets = pageWidgets.get(currentPage) || [];
    const hit = widgets.find(w=>{
      const [x1,y1,x2,y2]=w.rect;
      const minX=Math.min(x1,x2), maxX=Math.max(x1,x2);
      const minY=Math.min(y1,y2), maxY=Math.max(y1,y2);
      return (pdfX>=minX && pdfX<=maxX && pdfY>=minY && pdfY<=maxY);
    });
    if (!hit) return;

    currentHighlight = { page:currentPage, rect:hit.rect, name:hit.name };
    renderPage();

    let tr = rowByFieldName.get(hit.name);
    if (!tr){
      fieldFilter.value='';
      filterText='';
      renderTable();
      tr = rowByFieldName.get(hit.name);
    }
    if (tr){
      tr.scrollIntoView({ behavior:'smooth', block:'center' });
      flashRow(tr);
    }
  });
});

async function extractFieldsFromPdf(){
  const pdf = await pdfjsLib.getDocument({ url: currentPdfUrl, withCredentials:true }).promise;
  const cfg = getParsingConfigFromUI();

  const out = new Map();
  let sort = 0;

  if (pdf.getFieldObjects){
    const fo = await pdf.getFieldObjects();
    if (fo && typeof fo === 'object'){
      for (const [name, arr] of Object.entries(fo)){
        const first = (Array.isArray(arr) && arr[0]) ? arr[0] : {};
        const rawType = first.type || first.fieldType || '';
        const multilineFlag = !!(first.multiline || first.multiLine);
        const type = normalizeType(rawType, multilineFlag);
        out.set(name, { name, type, label:name, help_text:'', multiline:multilineFlag, sort:sort++, meta:{ type:rawType, multiline:multilineFlag } });
      }
    }
  }

  for (let p=1; p<=pdf.numPages; p++){
    const page = await pdf.getPage(p);
    const viewport = page.getViewport({ scale: 1.6 });
    const labelByBase = await buildLabelMapForPage(page, viewport, cfg);

    const annots = await page.getAnnotations({ intent:"display" });
    for (const a of annots){
      if (a.subtype !== 'Widget') continue;
      const name = (a.fieldName||'').toString().trim();
      if (!name) continue;

      const rect = Array.isArray(a.rect)&&a.rect.length===4 ? a.rect : null;
      const rawType = a.fieldType || a.type || '';
      let type = normalizeType(rawType, false);
      if (a.radioButton===true) type='radio';
      if (a.checkBox===true) type='checkbox';

      const hint = (a.alternativeText || a.altText || a.tooltip || a.title || a.fieldLabel || '')?.toString?.() || '';

      if (!out.has(name)){
        out.set(name, { name, type: FIELD_TYPES.includes(type)?type:'radio', label:name, help_text:hint||'', multiline:false, sort:sort++, meta:{ type:rawType } });
      } else {
        const it = out.get(name);
        if (it && type==='radio') it.type='radio';
        if (it && !it.help_text && hint) it.help_text = hint;
      }

      const item = out.get(name);
      if (item && rect){
        item.meta = item.meta || {};
        if (!item.meta.page) item.meta.page = p;
        if (!item.meta.rect) item.meta.rect = rect;

        const baseKey = name.replace(/-T$/i,'');
        const label = labelByBase.get(baseKey) || '';
        if (label){
          if (!item.label || item.label===item.name) item.label = label;
          if (cfg.fillHelpFromLabel && (!item.help_text || String(item.help_text).trim()==='')) item.help_text = label;
        }
        if (cfg.debugLabelCandidates){
          item.meta._label_debug = { page:p, baseKey, label, cfg };
        }
      }
    }
  }

  return Array.from(out.values()).sort((a,b)=>(a.sort??0)-(b.sort??0));
}

async function fetchTemplateFieldsMap(templateId){
  const url = "<?=h(url('admin/ajax/template_fields_export.php'))?>?template_id=" + encodeURIComponent(templateId);
  const resp = await fetch(url, { method:"GET" });
  const data = await resp.json().catch(()=>({}));
  if (!resp.ok || !data.ok) throw new Error(data.error || ("HTTP "+resp.status));
  const map = new Map();
  (data.fields||[]).forEach(f=>{ if (f && f.name) map.set(String(f.name), f); });
  return map;
}

function getCopyOptions(){
  return { type:!!cpType.checked, label:!!cpLabel.checked, help:!!cpHelp.checked, rights:!!cpRights.checked, meta:!!cpMeta.checked };
}

function applyFromSourceMap(sourceMap, onlyVisible){
  const opt = getCopyOptions();
  let applied=0;

  fields = fields.map(f=>{
    if (onlyVisible && !isVisibleByFilter(f)) return f;
    const src = sourceMap.get(String(f.name));
    if (!src) return f;
    applied++;
    const next = { ...f };

    if (opt.type){
      const t = (src.type && FIELD_TYPES.includes(src.type)) ? src.type : next.type;
      next.type = t;
      next.multiline = !!src.multiline;
    }
    if (opt.label && src.label && String(src.label).trim()!=='') next.label = String(src.label);
    if (opt.help && src.help_text && String(src.help_text).trim()!=='') next.help_text = String(src.help_text);
    if (opt.rights){
      next.can_child_edit = src.can_child_edit ? 1 : 0;
      next.can_teacher_edit = (src.can_teacher_edit ?? 1) ? 1 : 0;
    }
    if (opt.meta && src.meta && typeof src.meta === 'object') next.meta = src.meta;

    return next;
  });

  renderTable();
  return applied;
}

btnCopyVisible.addEventListener('click', async ()=>{
  try{
    const fromId = parseInt(copyFromTemplate.value||'0',10);
    if (!fromId){ copyResult.textContent='Bitte Quelle auswählen.'; return; }
    copyResult.textContent='Lade…';
    const map = await fetchTemplateFieldsMap(fromId);
    const n = applyFromSourceMap(map, true);
    copyResult.textContent = `Übernommen: ${n} (sichtbar)`;
  } catch(e){
    copyResult.textContent = 'Fehler: ' + (e && e.message ? e.message : e);
  }
});

btnCopyAll.addEventListener('click', async ()=>{
  try{
    const fromId = parseInt(copyFromTemplate.value||'0',10);
    if (!fromId){ copyResult.textContent='Bitte Quelle auswählen.'; return; }
    copyResult.textContent='Lade…';
    const map = await fetchTemplateFieldsMap(fromId);
    const n = applyFromSourceMap(map, false);
    copyResult.textContent = `Übernommen: ${n} (alle)`;
  } catch(e){
    copyResult.textContent = 'Fehler: ' + (e && e.message ? e.message : e);
  }
});

let previewVisible = true;
btnTogglePreview.addEventListener('click', ()=>{
  previewVisible = !previewVisible;
  if (previewVisible){
    wizGrid.classList.remove('is-preview-hidden');
    wizPreviewCol.classList.remove('is-hidden');
    btnTogglePreview.textContent = 'Vorschau ausblenden';
    if (pdfDoc) setTimeout(()=>renderPage(),20);
  } else {
    wizGrid.classList.add('is-preview-hidden');
    wizPreviewCol.classList.add('is-hidden');
    btnTogglePreview.textContent = 'Vorschau einblenden';
  }
});

document.querySelectorAll('.js-extract').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    btn.disabled=true;
    try{
      currentTemplateId = parseInt(btn.dataset.templateId,10);
      currentPdfUrl = btn.dataset.pdfUrl;

      if (!currentTemplateId || Number.isNaN(currentTemplateId)) throw new Error('template_id konnte nicht gelesen werden.');

      fields = await extractFieldsFromPdf();
      fields = fields.map(f=>({ ...f, can_child_edit:0, can_teacher_edit:1, label:f.label||f.name, help_text:f.help_text||'', type: FIELD_TYPES.includes(f.type)?f.type:'radio' }));

      filterText=''; fieldFilter.value='';
      copyFromTemplate.value=''; copyResult.textContent='';

      cpType.checked=true; cpLabel.checked=true; cpHelp.checked=true; cpRights.checked=true; cpMeta.checked=true;

      previewVisible=true;
      wizGrid.classList.remove('is-preview-hidden');
      wizPreviewCol.classList.remove('is-hidden');
      btnTogglePreview.textContent='Vorschau ausblenden';

      showAllWidgetHighlights=true;
      btnToggleHighlights.textContent='Felder hervorheben: an';

      wizard.style.display='block';
      renderTable();
      await loadPdf();
    } catch(e){
      alert('Fehler beim Auslesen: ' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled=false;
    }
  });
});

document.querySelectorAll('.js-font-scan').forEach(btn => {
  btn.addEventListener('click', async () => {
    const pdfUrl = btn.getAttribute('data-pdf-url') || '';
    const tplName = btn.getAttribute('data-template-name') || '';
    if (!pdfUrl) return;

    fontAuditTemplate.textContent = tplName || '—';
    missingFontsEmpty.textContent = 'Suche fehlende Schriftarten …';
    missingFontsEmpty.style.display = '';
    missingFontsList.innerHTML = '';
    missingFontSelect.innerHTML = '<option value="">Lade …</option>';
    pdfFontNameInput.value = '';
    btnUploadMissingFont.disabled = true;

    try {
      const missing = await scanPdfFonts(pdfUrl);
      renderMissingFonts(missing);
    } catch (e) {
      missingFontsEmpty.textContent = 'Fehler beim Lesen der Schriftarten: ' + (e?.message || e);
      missingFontsEmpty.style.display = '';
      missingFontsList.innerHTML = '';
      missingFontSelect.innerHTML = '<option value="">Fehler</option>';
      pdfFontNameInput.value = '';
      btnUploadMissingFont.disabled = true;
    }
  });
});

if (missingFontSelect) {
  missingFontSelect.addEventListener('change', () => {
    pdfFontNameInput.value = missingFontSelect.value || '';
    btnUploadMissingFont.disabled = !pdfFontNameInput.value;
  });
}

btnChildNone.addEventListener('click', ()=>setChildVisible(0));
btnChildAll.addEventListener('click', ()=>setChildVisible(1));
btnTeachNone.addEventListener('click', ()=>setTeachVisible(0));
btnTeachAll.addEventListener('click', ()=>setTeachVisible(1));

btnImport.addEventListener('click', async ()=>{
  btnImport.disabled=true;
  try{
    if (!currentTemplateId || Number.isNaN(currentTemplateId)) throw new Error('template_id ist leer – bitte Wizard neu öffnen.');

    const payloadFields = fields.map((f,i)=>({
      name:f.name,
      type:f.type,
      label:(f.label && f.label.trim()!=='') ? f.label.trim() : f.name,
      help_text:(f.help_text && String(f.help_text).trim()!=='') ? String(f.help_text).trim() : '',
      multiline:(f.type==='multiline') ? true : !!f.multiline,
      sort:i,
      meta:f.meta || {},
      can_child_edit:f.can_child_edit ? 1 : 0,
      can_teacher_edit:f.can_teacher_edit ? 1 : 0
    }));

    const params = new URLSearchParams();
    params.set('csrf_token', csrf);
    params.set('template_id', String(currentTemplateId));
    params.set('fields', JSON.stringify(payloadFields));

    const resp = await fetch("<?=h(url('admin/ajax/import_fields.php'))?>", {
      method:"POST",
      headers:{ "Content-Type":"application/x-www-form-urlencoded; charset=UTF-8", "X-CSRF-Token":csrf },
      body: params.toString()
    });

    const data = await resp.json().catch(()=>({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || ("Import fehlgeschlagen (HTTP "+resp.status+")"));

    alert("Import OK: " + data.imported + " Felder.");
    window.location.href = "<?=h(url('admin/template_fields.php'))?>?template_id=" + encodeURIComponent(currentTemplateId);
  } catch(e){
    alert('Import-Fehler: ' + (e && e.message ? e.message : e));
  } finally {
    btnImport.disabled=false;
  }
});

btnCancel.addEventListener('click', ()=>{
  wizard.style.display='none';
  currentTemplateId=null;
  currentPdfUrl=null;
  fields=[];
  tbody.innerHTML='';
  pdfDoc=null;
  currentHighlight=null;
  pageWidgets=new Map();
  rowByFieldName=new Map();
  fieldFilter.value='';
  filterText='';
});

fieldFilter.addEventListener('input', ()=>{ filterText = String(fieldFilter.value||'').trim(); renderTable(); });
btnClearFilter.addEventListener('click', ()=>{ fieldFilter.value=''; filterText=''; renderTable(); });

loadParsingConfig();
</script>

<?php render_admin_footer(); ?>
