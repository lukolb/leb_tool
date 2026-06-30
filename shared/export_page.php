<?php
// shared/export_page.php
declare(strict_types=1);

/**
 * Shared PDF export page (UI + JS).
 *
 * Expected variables (set by wrapper):
 *   - $exportApiUrl (string)
 *   - $backUrl (string)
 *   - $pageTitle (string)
 *   - $classId (int)
 *   - $classes (array)
 *   - $csrf (string)
 *   - $debugPdf (bool)
 */

if (!isset($exportApiUrl, $backUrl, $pageTitle, $classId, $classes, $csrf, $debugPdf)) {
  throw new RuntimeException('shared/export_page.php missing required variables.');
}

$tx = [
  'no_classes' => t('teacher.export.no_classes', 'Keine Klassen gefunden.'),
  'no_classes_hint' => t('teacher.export.no_classes_hint', 'Für Lehrkräfte heißt das meistens: Es sind noch keine Klassen zugeordnet (<code>user_class_assignments</code>).'),
  'class_label' => t('teacher.export.class_label', 'Klasse'),
  'class_hint' => t('teacher.export.class_hint', 'Exportiert die der Klasse zugeordnete Vorlage.'),
  'mode_label' => t('teacher.export.mode_label', 'Export-Variante'),
  'mode.zip' => t('teacher.export.mode.zip', 'ZIP-Export'),
  'mode.zip_sub' => t('teacher.export.mode.zip_sub', 'eine PDF pro Schüler'),
  'mode.merged' => t('teacher.export.mode.merged', 'Gesamt-PDF'),
  'mode.merged_sub' => t('teacher.export.mode.merged_sub', 'alle Schüler in einer Datei'),
  'mode.single' => t('teacher.export.mode.single', 'Einzel-Export'),
  'mode.single_sub' => t('teacher.export.mode.single_sub', 'nur eine ausgewählte Person'),
  'mode.booklet_single' => t('teacher.export.mode.booklet_single', 'Broschürendruck (einzeln)'),
  'mode.booklet_single_sub' => t('teacher.export.mode.booklet_single_sub', 'eine ausgewählte Person als Broschüre'),
  'mode.booklet_merged' => t('teacher.export.mode.booklet_merged', 'Broschürendruck (Klasse)'),
  'mode.booklet_merged_sub' => t('teacher.export.mode.booklet_merged_sub', 'alle Schüler als Broschüren-PDF'),
  'filter_label' => t('teacher.export.filter_label', 'Filter'),
  'filter_only_submitted' => t('teacher.export.filter_only_submitted', 'Nur abgegebene (submitted)'),
  'student_label' => t('teacher.export.student_label', 'Schüler'),
  'check' => t('teacher.export.check', 'Prüfen'),
  'start' => t('teacher.export.start', 'Export starten'),
  'warn_note' => t('teacher.export.warn_note', 'Warnungen blockieren den Export nicht.'),
  'status' => t('teacher.export.status', 'Status'),
  'ready' => t('teacher.export.ready', 'Bereit.'),
  'info_label' => t('teacher.export.info_label', 'Hinweis:'),
  'warn_label' => t('teacher.export.warn_label', 'Achtung:'),
  'warn_hint' => t('teacher.export.warn_hint', 'Beim Export kannst du die Warnung ignorieren oder abbrechen.'),
  'details' => t('teacher.export.details', 'Details'),
  'speed_hint' => t('teacher.export.speed_hint', 'Bei großen Klassen kann „Eine PDF (alle)“ etwas dauern'),
  'debug_active' => t('teacher.export.debug_active', 'Debug aktiv (debug_pdf=1) – siehe Browser-Konsole'),
  'missing_title' => t('teacher.export.missing_title', 'Fehlende Einträge gefunden'),
  'missing_search' => t('teacher.export.missing_search', 'Suchen (Schüler oder Feld) …'),
  'expand_all' => t('teacher.export.expand_all', 'Alle ausklappen'),
  'collapse_all' => t('teacher.export.collapse_all', 'Alle einklappen'),
  'cancel' => t('teacher.export.cancel', 'Abbrechen'),
  'ignore' => t('teacher.export.ignore', 'Ignorieren & exportieren'),
];
$txJs = [
  'pdf_lib_load_error' => t('teacher.export.js.pdf_lib_load_error', 'pdf-lib konnte nicht geladen werden.'),
  'jszip_load_error' => t('teacher.export.js.jszip_load_error', 'JSZip konnte nicht geladen werden.'),
  'api_url_missing' => t('teacher.export.js.api_url_missing', 'EXPORT_API_URL ist leer (Wrapper setzt $exportApiUrl nicht).'),
  'api_network_error' => t('teacher.export.js.api_network_error', 'Netzwerkfehler beim API-Request: {message}'),
  'invalid_api_response' => t('teacher.export.js.invalid_api_response', 'Ungültige API-Antwort.'),
  'status_checking' => t('teacher.export.js.status_checking', 'Prüfe Daten …'),
  'status_check_done' => t('teacher.export.js.status_check_done', 'Prüfen fertig'),
  'status_ok_students' => t('teacher.export.js.status_ok_students', 'OK. {count} Schüler gefunden.'),
  'status_info' => t('teacher.export.js.status_info', 'Hinweis.'),
  'status_error' => t('teacher.export.js.status_error', 'Fehler: {message}'),
  'status_load_export' => t('teacher.export.js.status_load_export', 'Lade Exportdaten …'),
  'status_load_libs' => t('teacher.export.js.status_load_libs', 'Lade Bibliotheken …'),
  'status_libs_loaded' => t('teacher.export.js.status_libs_loaded', 'Bibliotheken geladen'),
  'status_load_template' => t('teacher.export.js.status_load_template', 'Lade PDF-Vorlage …'),
  'status_template_loaded' => t('teacher.export.js.status_template_loaded', 'PDF-Vorlage geladen'),
  'error_template_load' => t('teacher.export.js.error_template_load', 'PDF-Vorlage konnte nicht geladen werden.'),
  'error_no_students' => t('teacher.export.js.error_no_students', 'Keine Schüler gefunden (Filter?).'),
  'status_create_pdfs' => t('teacher.export.js.status_create_pdfs', 'Erzeuge PDFs …'),
  'status_zip_packing' => t('teacher.export.js.status_zip_packing', 'ZIP packen …'),
  'status_zip_done' => t('teacher.export.js.status_zip_done', 'Fertig. ZIP wurde heruntergeladen.'),
  'status_done' => t('teacher.export.js.status_done', 'Fertig'),
  'status_merge_pdf' => t('teacher.export.js.status_merge_pdf', 'Erzeuge eine zusammengeführte PDF …'),
  'status_merging' => t('teacher.export.js.status_merging', 'Zusammenführen …'),
  'status_pdf_done' => t('teacher.export.js.status_pdf_done', 'Fertig. PDF wurde heruntergeladen.'),
  'status_create_pdf' => t('teacher.export.js.status_create_pdf', 'Erzeuge PDF …'),
  'status_create_booklet' => t('teacher.export.js.status_create_booklet', 'Erzeuge Broschüren-PDF …'),
  'booklet_embed_failed' => t('teacher.export.js.booklet_embed_failed', 'Broschürenexport fehlgeschlagen: Seite {page} konnte nicht eingebettet werden ({operation}).'),
  'status_export_cancelled' => t('teacher.export.js.status_export_cancelled', 'Export abgebrochen.'),
  'progress_error' => t('teacher.export.js.progress_error', 'Fehler'),
  'admin_template_hint' => t('teacher.export.js.admin_template_hint', ' (Admin: bitte der Klasse eine Vorlage zuweisen.)'),
  'missing_summary' => t('teacher.export.js.missing_summary', 'Insgesamt {total} fehlende Einträge bei {students} Schüler(n).'),
  'required_suffix' => t('teacher.export.js.required_suffix', ' (Pflicht)'),
  'more_entries' => t('teacher.export.js.more_entries', '… und {count} weitere'),
  'missing_count_suffix' => t('teacher.export.js.missing_count_suffix', ' – {count} fehlend'),
  'missing_none' => t('teacher.export.js.missing_none', 'Keine passenden Treffer.'),
  'student_id_label' => t('teacher.export.js.student_id_label', 'ID {id}'),
  'class_label_fallback' => t('teacher.export.js.class_label_fallback', 'Klasse'),
  'only_submitted_suffix' => t('teacher.export.js.only_submitted_suffix', ' - nur abgegebene'),
  'booklet_suffix' => t('teacher.export.booklet_suffix', ' Broschuere'),
  'student_filename_fallback' => t('teacher.export.js.student_filename_fallback', 'Schueler-{id}'),
  'non_fatal_no_template' => t('export.api.error.no_template', 'Für diese Klasse wurde keine Vorlage zugeordnet.'),
  'non_fatal_no_students' => t('teacher.export.js.non_fatal_no_students', 'Keine Schüler gefunden'),
];

$cfg = app_config();
$exportCfg = $cfg['export'] ?? [];
$allowEditablePdf = (bool)($exportCfg['allow_editable_pdf'] ?? false);
$radioCrossColorMode = (string)($exportCfg['radio_cross_color_mode'] ?? 'pdf_text');
if (!in_array($radioCrossColorMode, ['pdf_text', 'admin'], true)) $radioCrossColorMode = 'pdf_text';
$radioCrossColorHex = trim((string)($exportCfg['radio_cross_color'] ?? '#0b57d0'));
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $radioCrossColorHex)) $radioCrossColorHex = '#0b57d0';

function export_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

function export_period_label_display(?string $raw): string {
  $val = normalize_class_period_label($raw);
  return $val === 'H2'
    ? t('admin.classes.period.h2', '2. Halbjahr')
    : t('admin.classes.period.h1', '1. Halbjahr');
}
?>

<style>
.export-mode { min-width: 320px; }
.export-title { font-weight: 600; display: block; margin-bottom: 6px; }
.export-list { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.export-row { display: flex; align-items: baseline; gap: 10px; padding: 8px 12px; cursor: pointer; transition: background 0.12s ease; }
.export-row + .export-row { border-top: 1px solid #eee; }
.export-row input { display: none; }
.export-row:hover { background: #f7f7f7; }
.export-row:has(input:checked) { background: #eef4ff; }
.export-row:has(input:checked)::before { content: ''; width: 3px; height: 100%; background: #3b82f6; margin-right: 6px; border-radius: 2px; }
.export-main { font-weight: 500; }
.export-sub { font-size: 0.85em; color: #666; }
</style>

<div class="card">
  <h1><?=h($pageTitle)?></h1>
</div>

<?php if (!is_array($classes) || count($classes) === 0): ?>
  <div class="card" style="border:1px solid #ffe08a; background:#fff7db; margin-bottom:14px;">
    <strong><?=h($tx['no_classes'])?></strong>
    <div class="muted" style="margin-top:6px;"><?=$tx['no_classes_hint']?></div>
  </div>
<?php else: ?>

<div class="card" style="margin-bottom:14px;">
  <div class="row" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div style="min-width:260px;">
      <label for="classId" class="export-title"><?=h($tx['class_label'])?></label>
      <select id="classId" class="input" style="width:100%;">
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$classId) ? 'selected' : '' ?>>
            <?=h((string)$c['school_year'])?> · <?=h(export_period_label_display($c['period_label'] ?? 'Standard'))?> · <?=h(export_class_display($c))?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="muted" style="margin-top:4px;"><?=h($tx['class_hint'])?></div>
    </div>

    <div class="export-mode">
      <label class="export-title"><?=h($tx['mode_label'])?></label>

      <div class="export-list">
        <label class="export-row">
          <input type="radio" name="mode" value="zip" checked>
          <span class="export-main"><?=h($tx['mode.zip'])?></span>
          <span class="export-sub"><?=h($tx['mode.zip_sub'])?></span>
        </label>

        <label class="export-row">
          <input type="radio" name="mode" value="merged">
          <span class="export-main"><?=h($tx['mode.merged'])?></span>
          <span class="export-sub"><?=h($tx['mode.merged_sub'])?></span>
        </label>

        <label class="export-row">
          <input type="radio" name="mode" value="single">
          <span class="export-main"><?=h($tx['mode.single'])?></span>
          <span class="export-sub"><?=h($tx['mode.single_sub'])?></span>
        </label>

        <label class="export-row">
          <input type="radio" name="mode" value="booklet_single">
          <span class="export-main"><?=h($tx['mode.booklet_single'])?></span>
          <span class="export-sub"><?=h($tx['mode.booklet_single_sub'])?></span>
        </label>

        <label class="export-row">
          <input type="radio" name="mode" value="booklet_merged">
          <span class="export-main"><?=h($tx['mode.booklet_merged'])?></span>
          <span class="export-sub"><?=h($tx['mode.booklet_merged_sub'])?></span>
        </label>
      </div>
    </div>

    <div style="min-width:220px;">
      <label class="export-title"><?=h($tx['filter_label'])?></label>
      <label class="row" style="gap:8px; margin-top:6px;">
        <input type="checkbox" id="onlySubmitted">
        <?=h($tx['filter_only_submitted'])?>
      </label>
    </div>

    <div id="singleStudentWrap" style="min-width:260px; display:none;">
      <label class="export-title" for="studentId"><?=h($tx['student_label'])?></label>
      <select id="studentId" class="input" style="width:100%;"></select>
    </div>

    <div style="flex:1; min-width:240px;">
      <label><strong>&nbsp;</strong></label>
      <div class="row" style="gap:10px; justify-content:flex-end;">
        <a class="btn secondary" id="btnCheck" type="button"><?=h($tx['check'])?></a>
        <a class="btn primary" id="btnExport" type="button" style="margin-left: 10px;"><?=h($tx['start'])?></a>
      </div>
      <div class="muted" style="margin-top:4px; text-align:right;"><?=h($tx['warn_note'])?></div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:14px;">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <strong><?=h($tx['status'])?></strong>
      <div class="muted" id="statusLine" style="padding-top: 10px"><?=h($tx['ready'])?></div>
    </div>
  </div>

  <div id="exportProgressWrap" class="progress-wrap" style="display:none; margin-top:10px;">
    <div class="progress-meta"><span id="exportProgressText">—</span><span id="exportProgressPct"></span></div>
    <div class="progress"><div id="exportProgressBar" class="progress-bar"></div></div>
  </div>

  <div id="infoBox" style="display:none; margin-top:10px; padding:10px; border-radius:10px; border:1px solid #b9dbff; background:#eaf4ff;">
    <strong><?=h($tx['info_label'])?></strong>
    <span id="infoText"></span>
  </div>

  <div id="warnBox" class="alert info">
    <div class="row" style="justify-content:space-between; align-items:flex-start; gap:12px;">
      <div style="float: left;">
        <strong><?=h($tx['warn_label'])?></strong>
        <span id="warnText"></span>
        <div class="muted" style="margin-top:6px;"><?=h($tx['warn_hint'])?></div>
      </div>
      <div style="white-space:nowrap; text-align: end">
        <button class="btn secondary" id="btnWarnDetails" type="button" style="display:none;"><?=h($tx['details'])?></button>
      </div>
    </div>
  </div>
  <div class="muted" style="max-width:520px; padding-top: 10px">
    <?=h($tx['speed_hint'])?>
  </div>
</div>

<div class="muted" style="font-size:13px;">
  <?php if ($debugPdf): ?>
    <span style="margin-left:10px; padding:2px 8px; border-radius:999px; background:#fff7d6; border:1px solid #ffe59a;">
      <?=h($tx['debug_active'])?>
    </span>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- modal -->
<div id="missingModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div style="max-width:920px; margin:6vh auto; background:#fff; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;">
    <div style="padding:16px 18px; border-bottom:1px solid #eee;">
      <div style="font-size:18px; font-weight:700;"><?=h($tx['missing_title'])?></div>
      <div class="muted" id="missingModalSummary" style="margin-top:4px;"></div>

      <div class="row" style="gap:10px; margin-top:12px; flex-wrap:wrap; align-items:center;">
        <input id="missingSearch" class="input" style="flex:1; min-width:260px; margin-bottom: 10px;" placeholder="<?=h($tx['missing_search'])?>">
        <button class="btn secondary" id="btnExpandAll" type="button"><?=h($tx['expand_all'])?></button>
        <button class="btn secondary" id="btnCollapseAll" type="button"><?=h($tx['collapse_all'])?></button>
      </div>
    </div>

    <div style="padding:14px 18px; max-height:58vh; overflow:auto;">
      <div id="missingModalList"></div>
    </div>

    <div style="padding:14px 18px; border-top:1px solid #eee; display:flex; gap:10px; justify-content:flex-end;">
      <button class="btn secondary" id="btnMissingCancel" type="button"><?=h($tx['cancel'])?></button>
      <button class="btn" id="btnMissingIgnore" type="button"><?=h($tx['ignore'])?></button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const DEBUG_PDF = <?= $debugPdf ? 'true' : 'false' ?>;
const EXPORT_API_URL = <?= json_encode($exportApiUrl) ?>;
const FONT_MANIFEST_URL = <?= json_encode(url('shared/font_manifest.php')) ?>;
const FONTKIT_URL = 'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js';
const EXPORT_LANG = <?= json_encode(ui_lang()) ?>;
const ALLOW_EDITABLE_PDF = <?= $allowEditablePdf ? 'true' : 'false' ?>;
const RADIO_CROSS_COLOR_MODE = <?= json_encode($radioCrossColorMode) ?>;
const RADIO_CROSS_COLOR_HEX = <?= json_encode($radioCrossColorHex) ?>;
const I18N = <?= json_encode($txJs, JSON_UNESCAPED_UNICODE) ?>;

function t(key, fallback){
  return (I18N && Object.prototype.hasOwnProperty.call(I18N, key)) ? I18N[key] : (fallback || key);
}
function tfmt(key, fallback, vars){
  let s = t(key, fallback);
  if (vars && typeof vars === 'object'){
    for (const k of Object.keys(vars)){
      s = s.replaceAll('{' + k + '}', String(vars[k]));
    }
  }
  return s;
}

const elClass = document.getElementById('classId');
const elStudentWrap = document.getElementById('singleStudentWrap');
const elStudent = document.getElementById('studentId');
const elOnlySubmitted = document.getElementById('onlySubmitted');
const elStatus = document.getElementById('statusLine');
const btnCheck = document.getElementById('btnCheck');
const btnExport = document.getElementById('btnExport');

const infoBox = document.getElementById('infoBox');
const infoText = document.getElementById('infoText');

const warnBox = document.getElementById('warnBox');

const progWrap = document.getElementById('exportProgressWrap');
const progBar  = document.getElementById('exportProgressBar');
const progText = document.getElementById('exportProgressText');
const progPct  = document.getElementById('exportProgressPct');
const warnText = document.getElementById('warnText');
const btnWarnDetails = document.getElementById('btnWarnDetails');

const modal = document.getElementById('missingModal');
const modalSummary = document.getElementById('missingModalSummary');
const modalList = document.getElementById('missingModalList');
const btnMissingCancel = document.getElementById('btnMissingCancel');
const btnMissingIgnore = document.getElementById('btnMissingIgnore');
const elMissingSearch = document.getElementById('missingSearch');
const btnExpandAll = document.getElementById('btnExpandAll');
const btnCollapseAll = document.getElementById('btnCollapseAll');

let lastPreview = null;

function resetPreview() {
  lastPreview = null;
  updateWarnBoxFromPreview(null);
}
let __missingRenderSource = null;
let __exportInProgress = false;

// ✅ Cache der kompletten Schülerliste (damit single-export sie nicht überschreibt)
let __fullStudentList = [];

// ✅ NEW: field_meta map from API (field_name => {field_type, date_format?})
let __fieldMetaMap = {};

function setStatus(msg){ if (elStatus) elStatus.textContent = msg; }

function showProgress(label, done, total){
  if (!progWrap || !progBar) return;
  const t = Math.max(0, Number(total||0));
  const d = Math.max(0, Number(done||0));
  const pct = (t>0) ? Math.max(0, Math.min(100, Math.round((d/t)*100))) : 0;
  progWrap.style.display = '';
  if (progText) progText.textContent = label || '';
  if (progPct) progPct.textContent = (t>0) ? (pct + '%') : '';
  progBar.style.width = (t>0) ? (pct + '%') : '0%';
  progBar.classList.toggle('ok', t>0 && d>=t);
}

function hideProgress(){
  if (!progWrap) return;
  progWrap.style.display = 'none';
  if (progBar) { progBar.style.width = '0%'; progBar.classList.remove('ok'); }
  if (progText) progText.textContent = '';
  if (progPct) progPct.textContent = '';
}

function setInfo(msg){
  if (!infoBox || !infoText) return;
  if (msg) { infoText.textContent = msg; infoBox.style.display = ''; }
  else infoBox.style.display = 'none';
}

function currentMode(){
  const r = document.querySelector('input[name="mode"]:checked');
  return r ? r.value : 'zip';
}
function modeNeedsStudent(mode){
  return mode === 'single' || mode === 'booklet_single';
}
function updateModeUI(){
  if (!elStudentWrap) return;
  elStudentWrap.style.display = modeNeedsStudent(currentMode()) ? '' : 'none';
}
document.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', () => {
  updateModeUI();
  resetPreview();
}));
updateModeUI();

if (elClass) {
  elClass.addEventListener('change', () => {
    const id = Number(elClass.value||0);
    const url = new URL(window.location.href);
    url.searchParams.set('class_id', String(id));
    window.location.href = url.toString();
  });
}

async function loadLibsIfNeeded(needZip){
  if (!window.PDFLib){
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
      s.onload = resolve;
      s.onerror = () => reject(new Error(t('pdf_lib_load_error')));
      document.head.appendChild(s);
    });
  }
  if (needZip && !window.JSZip){
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/jszip@3.10.1/dist/jszip.min.js';
      s.onload = resolve;
      s.onerror = () => reject(new Error(t('jszip_load_error')));
      document.head.appendChild(s);
    });
  }
}

function isNonFatalBusinessError(msg){
  const m = (msg||'').toLowerCase();
  const needles = [
    t('non_fatal_no_template').toLowerCase(),
    t('non_fatal_no_students').toLowerCase(),
    'keine schueler',
  ];
  return needles.some((n) => n && m.includes(n));
}

async function apiFetch(payload){
  if (!EXPORT_API_URL) throw new Error(t('api_url_missing'));

  let resp;
  try {
    resp = await fetch(EXPORT_API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });
  } catch (e) {
    throw new Error(tfmt('api_network_error', null, { message: (e?.message || e) }));
  }

  const raw = await resp.text();
  let data = null;
  try { data = JSON.parse(raw); } catch (e) { data = null; }

  if (!resp.ok) {
    const msg = data?.error ? String(data.error) : raw.slice(0, 300);
    const err = new Error(msg || ('HTTP ' + resp.status));
    err._httpStatus = resp.status;
    err._raw = raw;
    err._isJson = !!data;
    throw err;
  }

  if (!data || !data.ok) {
    const msg = data?.error ? String(data.error) : raw.slice(0, 300);
    const err = new Error(msg || t('invalid_api_response'));
    err._httpStatus = resp.status;
    err._raw = raw;
    err._isJson = !!data;
    throw err;
  }

  return data;
}

function fillStudentSelect(students, keepId){
  if (!elStudent) return;
  const keep = (keepId !== undefined && keepId !== null && String(keepId) !== '') ? String(keepId) : '';
  elStudent.innerHTML = '';

  let firstId = '';
  let foundKeep = false;

  (students||[]).forEach((s, idx) => {
    const opt = document.createElement('option');
    const id = String(s.id);
    if (idx === 0) firstId = id;
    opt.value = id;
    opt.textContent = s.name || tfmt('student_id_label', null, { id });
    if (keep && id === keep) foundKeep = true;
    elStudent.appendChild(opt);
  });

  if (!students || !students.length) return;

  if (keep && foundKeep) elStudent.value = keep;
  else elStudent.value = firstId;
}

function onlySubmittedFlag(){ return (elOnlySubmitted && elOnlySubmitted.checked) ? 1 : 0; }

function updateWarnBoxFromPreview(preview){
  const sum = preview?.warnings_summary;
  const total = Number(sum?.total_missing || 0);
  const studentsWith = Number(sum?.students_with_missing || 0);

  if (!warnBox || !warnText) return;

  if (total > 0) {
    warnText.textContent = tfmt('missing_summary', null, { total, students: studentsWith });
    warnBox.style.display = '';
    if (btnWarnDetails) btnWarnDetails.style.display = '';
  } else {
    warnBox.style.display = 'none';
    if (btnWarnDetails) btnWarnDetails.style.display = 'none';
  }
}

let __refiningSinglePreview = false;

async function check(){
  if (!elClass) return null;
  const classId = Number(elClass.value||0);
  const keepStudentId = elStudent?.value;

  setInfo('');
  setStatus(t('status_checking'));
  showProgress(t('status_checking'), 1, 3);

  try {
    const mode = currentMode();

    // 1) FULL PREVIEW (immer ohne student_id) -> füllt Dropdown korrekt
    const full = await apiFetch({ action: 'preview', class_id: classId, only_submitted: onlySubmittedFlag() });
    showProgress(t('status_checking'), 2, 3);

    // ✅ cache complete list
    __fullStudentList = Array.isArray(full.students) ? full.students : [];

    // ✅ cache field meta (date formats etc.)
    __fieldMetaMap = (full.field_meta && typeof full.field_meta === 'object') ? full.field_meta : {};

    fillStudentSelect(__fullStudentList, keepStudentId);

    // 2) Single: warnings_summary für ausgewählten Schüler nachziehen, ohne Liste zu zerstören
    let merged = full;

    if (modeNeedsStudent(mode) && elStudent && elStudent.value && !__refiningSinglePreview) {
      const sid = Number(elStudent.value || 0);
      if (sid > 0) {
        __refiningSinglePreview = true;
        try {
          const single = await apiFetch({ action: 'preview', class_id: classId, student_id: sid, only_submitted: onlySubmittedFlag() });
          merged = Object.assign({}, full, { warnings_summary: single.warnings_summary });
        } finally {
          __refiningSinglePreview = false;
        }
      }
    }

    lastPreview = merged;
    updateWarnBoxFromPreview(merged);
    const cnt = __fullStudentList.length || 0;
    setStatus(tfmt('status_ok_students', null, { count: cnt }));
    showProgress(t('status_check_done'), 3, 3);
    return merged;

  } catch (e) {
    const msg = (e?.message || String(e));

    if (isNonFatalBusinessError(msg)) {
      lastPreview = null;
      __fullStudentList = [];
      __fieldMetaMap = {};
      if (elStudent) elStudent.innerHTML = '';
      if (warnBox) warnBox.style.display = 'none';
      if (btnWarnDetails) btnWarnDetails.style.display = 'none';
      hideProgress();
      setStatus(t('status_info'));
      setInfo(msg + t('admin_template_hint'));
      return null;
    }

    hideProgress();
    setStatus(tfmt('status_error', null, { message: msg }));
    throw e;
  }
}

if (btnCheck) {
  btnCheck.addEventListener('click', async () => {
    try { btnCheck.disabled = true; await check(); }
    finally { btnCheck.disabled = false; }
  });
}
if (elOnlySubmitted) elOnlySubmitted.addEventListener('change', resetPreview);

if (elStudent) {
  elStudent.addEventListener('change', () => {
    if (modeNeedsStudent(currentMode())) resetPreview();
  });
}

function safeFilename(s){
  return (s||'export').toString()
    .replace(/\s+/g,' ')
    .replace(/[\\/:*?"<>|]/g,'-')
    .trim();
}

function downloadBytes(bytes, filename, mime){
  const blob = new Blob([bytes], { type: mime });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

function escapeHtml(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

// ---------- missing modal rendering (grouped by student) ----------
function buildMissingHtml(preview, q){
  const sum = preview?.warnings_summary || {};
  const byStudent = Array.isArray(sum.by_student) ? sum.by_student : [];
  const query = (q||'').toString().trim().toLowerCase();

  const parts = [];
  for (const s of byStudent) {
    const studentName = (s.student_name || tfmt('student_id_label', null, { id: s.student_id })).toString();
    const fields = Array.isArray(s.missing_fields) ? s.missing_fields : [];
    if (!fields.length) continue;

    const filteredFields = !query ? fields : fields.filter(f => {
      const label = ((f.label || f.field_name || '') + '').toLowerCase();
      return studentName.toLowerCase().includes(query) || label.includes(query);
    });
    if (!filteredFields.length) continue;

    const showN = 60;
    const items = filteredFields.slice(0, showN).map(f => {
      const label = (f.label || f.field_name || '').toString();
      const req = Number(f.is_required || 0) === 1 ? t('required_suffix') : '';
      return `<li style="margin:2px 0;">${escapeHtml(label)}${req}</li>`;
    }).join('');

    const moreCount = filteredFields.length - showN;
    const more = moreCount > 0
      ? `<div class="muted" style="margin-top:6px;">${tfmt('more_entries', null, { count: moreCount })}</div>`
      : '';

    parts.push(`
      <details data-student="${escapeHtml(studentName)}" open>
        <summary style="cursor:pointer; padding:8px 10px; border:1px solid #eee; border-radius:10px; margin:8px 0; background:#fafafa;">
          <strong>${escapeHtml(studentName)}</strong>
          <span class="muted">${tfmt('missing_count_suffix', null, { count: filteredFields.length })}</span>
        </summary>
        <div style="padding:4px 10px 10px 10px;">
          <ul style="margin:6px 0 0 18px; padding:0;">${items}</ul>
          ${more}
        </div>
      </details>
    `);
  }
  return parts.join('') || `<div class="muted">${t('missing_none')}</div>`;
}

function openMissingModal(preview){
  return new Promise((resolve) => {
    const sum = preview?.warnings_summary || {};
    const total = Number(sum.total_missing || 0);
    const studentsWith = Number(sum.students_with_missing || 0);

    __missingRenderSource = preview;

    if (modalSummary) modalSummary.textContent = tfmt('missing_summary', null, { total, students: studentsWith });

    if (elMissingSearch) elMissingSearch.value = '';
    if (modalList) modalList.innerHTML = buildMissingHtml(preview, '');

    function cleanup(){
      if (btnMissingCancel) btnMissingCancel.onclick = null;
      if (btnMissingIgnore) btnMissingIgnore.onclick = null;
      if (modal) modal.style.display = 'none';
    }

    if (btnMissingCancel) btnMissingCancel.onclick = () => { cleanup(); resolve(false); };
    if (btnMissingIgnore) btnMissingIgnore.onclick = () => { cleanup(); resolve(true); };

    if (modal) modal.style.display = '';
  });
}

if (btnWarnDetails) {
  btnWarnDetails.addEventListener('click', async () => {
    if (!lastPreview) return;
    await openMissingModal(lastPreview);
  });
}

if (elMissingSearch) {
  elMissingSearch.addEventListener('input', () => {
    if (!__missingRenderSource || !modalList) return;
    modalList.innerHTML = buildMissingHtml(__missingRenderSource, elMissingSearch.value);
  });
}
if (btnExpandAll) {
  btnExpandAll.addEventListener('click', () => {
    document.querySelectorAll('#missingModalList details').forEach(d => d.open = true);
  });
}
if (btnCollapseAll) {
  btnCollapseAll.addEventListener('click', () => {
    document.querySelectorAll('#missingModalList details').forEach(d => d.open = false);
  });
}

// --------- ✅ Date normalization helpers (supports MMM/MMMM like "30. Dezember 2025") ----------

function escapeRegex(s){ return (s||'').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

const MONTHS_DE = [
  'januar','februar','märz','maerz','april','mai','juni','juli','august','september','oktober','november','dezember'
];
const MONTHS_DE_SHORT = [
  'jan','feb','mär','mae','mrz','apr','mai','jun','jul','aug','sep','okt','nov','dez'
];
const MONTHS_EN = [
  'january','february','march','april','may','june','july','august','september','october','november','december'
];
const MONTHS_EN_SHORT = [
  'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'
];

function monthNameToNumber(nameRaw){
  const s0 = (nameRaw ?? '').toString().trim().toLowerCase()
    .replace(/\.+$/,'')
    .replace('ä','ae').replace('ö','oe').replace('ü','ue').replace('ß','ss');

  const deFull = MONTHS_DE.map(x => x.replace('ä','ae'));
  const deShort = MONTHS_DE_SHORT.map(x => x.replace('ä','ae'));
  const enFull = MONTHS_EN;
  const enShort = MONTHS_EN_SHORT;

  let idx = deFull.indexOf(s0);
  if (idx >= 0) return idx+1;
  idx = deShort.indexOf(s0);
  if (idx >= 0) return idx+1;
  idx = enFull.indexOf(s0);
  if (idx >= 0) return idx+1;
  idx = enShort.indexOf(s0);
  if (idx >= 0) return idx+1;

  return 0;
}

function numberToMonthName(m, lang, style){
  const mm = Number(m);
  if (!(mm>=1 && mm<=12)) return '';
  const useDe = (lang || 'de').toLowerCase().startsWith('de');

  const fullDe = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
  const shortDe = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];

  const fullEn = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const shortEn = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  const arr = useDe
    ? (style === 'short' ? shortDe : fullDe)
    : (style === 'short' ? shortEn : fullEn);

  return arr[mm-1];
}

function buildRegexForFormat(fmt){
  let f = (fmt||'').trim();
  if (!f) return null;

  // normalize common lowercase variants
  f = f.replaceAll('yyyy','YYYY').replaceAll('yy','YY').replaceAll('dd','DD').replaceAll('d','D').replaceAll('mm','MM');

  const tokenMap = {
    'YYYY': '(\\d{4})',
    'YY': '(\\d{2})',
    'DD': '(\\d{2})',
    'D': '(\\d{1,2})',
    'MMMM': '([A-Za-zÄÖÜäöüß\\.]+)',
    'MMM': '([A-Za-zÄÖÜäöüß\\.]+)',
    'MM': '(\\d{2})',
    'M': '(\\d{1,2})'
  };

  const tokens = ['YYYY','YY','MMMM','MMM','DD','D','MM','M']; // longest first
  let re = '';
  for (let i=0; i<f.length; ){
    let matched = null;
    for (const t of tokens){
      if (f.slice(i, i+t.length) === t){ matched = t; break; }
    }
    if (matched){
      re += tokenMap[matched];
      i += matched.length;
    } else {
      re += escapeRegex(f[i]);
      i++;
    }
  }
  return new RegExp('^' + re + '$', 'i');
}

function matchesFormat(value, expectedFmt){
  const v = (value ?? '').toString().trim();
  const fmt = (expectedFmt ?? '').toString().trim();
  if (!v || !fmt) return false;
  const re = buildRegexForFormat(fmt);
  if (!re) return false;
  return re.test(v);
}

function parseFlexibleDate(raw){
  const s = (raw ?? '').toString().trim();
  if (!s) return null;

  // ISO date/datetime
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$/);
  if (iso){
    const y = Number(iso[1]), m = Number(iso[2]), d = Number(iso[3]);
    if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
  }

  // German numeric: DD.MM.YYYY
  const de = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/);
  if (de){
    let d = Number(de[1]), m = Number(de[2]), y = Number(de[3]);
    if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
    if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
  }

  // German with month name: "30. Dezember 2025"
  const named = s.match(/^(\d{1,2})\.\s*([A-Za-zÄÖÜäöüß\.]+)\s+(\d{2}|\d{4})$/);
  if (named){
    let d = Number(named[1]);
    const m = monthNameToNumber(named[2]);
    let y = Number(named[3]);
    if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
    if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
  }

  // US: MM/DD/YYYY
  const us = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/);
  if (us){
    let m = Number(us[1]), d = Number(us[2]), y = Number(us[3]);
    if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
    if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
  }

  // Hyphen: DD-MM-YYYY
  const hy = s.match(/^(\d{1,2})-(\d{1,2})-(\d{2}|\d{4})$/);
  if (hy){
    let d = Number(hy[1]), m = Number(hy[2]), y = Number(hy[3]);
    if (y < 100) y = (y >= 70 ? 1900 + y : 2000 + y);
    if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
  }

  // last resort: Date.parse
  const t = Date.parse(s);
  if (!Number.isNaN(t)){
    const dt = new Date(t);
    const y = dt.getFullYear();
    const m = dt.getMonth()+1;
    const d = dt.getDate();
    if (y>=1000 && m>=1 && m<=12 && d>=1 && d<=31) return { y, m, d };
  }

  return null;
}

function pad2(n){ return String(n).padStart(2,'0'); }

function formatDate(parts, expectedFmt){
  const fmt0 = (expectedFmt ?? '').toString().trim();
  if (!fmt0) return null;

  // normalize common lowercase variants
  const fmt = fmt0.replaceAll('yyyy','YYYY').replaceAll('yy','YY').replaceAll('dd','DD').replaceAll('d','D').replaceAll('mm','MM');

  const y = parts.y, m = parts.m, d = parts.d;
  const yy = String(y).slice(-2);

  const lang = EXPORT_LANG || 'de';

  const tokenMap = {
    'MMMM': numberToMonthName(m, lang, 'full'),
    'MMM': numberToMonthName(m, lang, 'short'),
    'YYYY': String(y),
    'YY': yy,
    'DD': pad2(d),
    'D': String(d),
    'MM': pad2(m),
    'M': String(m),
  };

  return fmt.replace(/(?<!\p{L})(MMMM|MMM|YYYY|YY|DD|MM|D|M)(?!\p{L})/gu, (tok) => tokenMap[tok] ?? tok);
}

/**
 * Main rule:
 * - If value already matches expected format => keep
 * - else attempt parse + format
 * - if not parseable => keep original
 */
function normalizeDateIfNeeded(rawValue, expectedFmt){
  const raw = (rawValue ?? '').toString().trim();
  const fmt = (expectedFmt ?? '').toString().trim();
  if (!raw || !fmt) return raw;

  if (matchesFormat(raw, fmt)) return raw;

  const parsed = parseFlexibleDate(raw);
  if (!parsed) return raw;

  const out = formatDate(parsed, fmt);
  return out || raw;
}

function pdfNameToString(name){
  if (!name) return '';
  if (typeof name === 'string') return name.replace(/^\//, '');
  if (typeof name?.decodeText === 'function') return name.decodeText().replace(/^\//, '');
  if (typeof name?.asString === 'function') return name.asString().replace(/^\//, '');
  if (typeof name?.key === 'string') return name.key.replace(/^\//, '');
  return String(name).replace(/^\//, '');
}

let __fontManifest = null;
let __embeddedFonts = new Map();

function normalizeFontName(raw){
  if (!raw) return '';
  let name = String(raw).trim();
  name = name.replace(/^\//, '');
  name = name.replace(/^[A-Z]{6}\+/, '');
  name = name.replace(/\s+/g, ' ');
  name = name.toLowerCase().trim();
  name = name.replace(/[^a-z0-9._-]+/g, '_').replace(/^[_\-.]+|[_\-.]+$/g, '');
  return name;
}

function expandFontKeys(base){
  if (!base) return [];
  const keys = new Set([base]);
  keys.add(base.replace(/-/g, '_'));
  keys.add(base.replace(/_/g, '-'));
  return Array.from(keys);
}

function pdfStringToText(val){
  if (!val) return '';
  if (typeof val.decodeText === 'function') return val.decodeText();
  if (typeof val.asString === 'function') return val.asString();
  if (typeof val.value === 'string') return val.value;
  return String(val);
}

function parseDaFontKey(daText){
  if (!daText) return '';
  const m = /\/([^\s]+)\s+[\d.]+\s+Tf/.exec(daText);
  return m ? m[1] : '';
}

function getFieldDefaultAppearance(field, PDFName){
  try {
    const da = field?.acroField?.dict?.lookup?.(PDFName.of('DA'));
    if (da) return pdfStringToText(da);
  } catch (e) {}
  try {
    const widgets = field?.acroField?.getWidgets?.() || [];
    for (const w of widgets) {
      const da = w?.dict?.lookup?.(PDFName.of('DA'));
      if (da) return pdfStringToText(da);
    }
  } catch (e) {}
  return '';
}

function resolveBaseFontName(field, fontKey, PDFName, form){
  if (!fontKey) return '';
  try {
    const dr = field?.acroField?.dict?.lookup?.(PDFName.of('DR'))
      || form?.acroForm?.dict?.lookup?.(PDFName.of('DR'));
    const fonts = dr?.lookup?.(PDFName.of('Font'));
    const font = fonts?.lookup?.(PDFName.of(fontKey));
    const base = font?.lookup?.(PDFName.of('BaseFont')) || font?.dict?.lookup?.(PDFName.of('BaseFont'));
    if (base) return pdfNameToString(base);
  } catch (e) {}
  return fontKey;
}

async function loadFontManifest(){
  if (__fontManifest) return __fontManifest;
  const resp = await fetch(FONT_MANIFEST_URL, { credentials: 'same-origin' });
  if (!resp.ok) {
    __fontManifest = new Map();
    return __fontManifest;
  }
  const data = await resp.json();
  const map = new Map();
  (data.fonts || []).forEach((f) => {
    const key = normalizeFontName(f.name || f.key || '');
    if (!key) return;
    expandFontKeys(key).forEach((k) => map.set(k, f));
  });
  __fontManifest = map;
  return map;
}

async function ensureFontkit(){
  if (window.fontkit || window.PDFLib?.fontkit) return window.fontkit;
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = FONTKIT_URL;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('fontkit load failed'));
    document.head.appendChild(s);
  });
  if (window.PDFLib?.registerFontkit && window.fontkit) {
    try { window.PDFLib.registerFontkit(window.fontkit); } catch (e) {}
  }
  return window.fontkit;
}

function standardFontNameMap(PDFLib){
  if (!PDFLib?.StandardFonts) return {};
  return {
    'helvetica': PDFLib.StandardFonts.Helvetica,
    'helvetica-bold': PDFLib.StandardFonts.HelveticaBold,
    'helvetica-oblique': PDFLib.StandardFonts.HelveticaOblique,
    'helvetica-boldoblique': PDFLib.StandardFonts.HelveticaBoldOblique,
    'times-roman': PDFLib.StandardFonts.TimesRoman,
    'times-bold': PDFLib.StandardFonts.TimesBold,
    'times-italic': PDFLib.StandardFonts.TimesItalic,
    'times-bolditalic': PDFLib.StandardFonts.TimesBoldItalic,
    'courier': PDFLib.StandardFonts.Courier,
    'courier-bold': PDFLib.StandardFonts.CourierBold,
    'courier-oblique': PDFLib.StandardFonts.CourierOblique,
    'courier-boldoblique': PDFLib.StandardFonts.CourierBoldOblique,
    'symbol': PDFLib.StandardFonts.Symbol,
    'zapfdingbats': PDFLib.StandardFonts.ZapfDingbats,
  };
}

async function getEmbeddedFont(pdfDoc, fontName, manifest){
  const baseKey = normalizeFontName(fontName);
  if (!baseKey) return null;
  const lookupKeys = expandFontKeys(baseKey);
  for (const key of lookupKeys) {
    if (__embeddedFonts.has(key)) return __embeddedFonts.get(key);
  }

  const PDFLib = window.PDFLib;
  const standardMap = standardFontNameMap(PDFLib);
  for (const key of lookupKeys) {
    if (standardMap[key] && typeof pdfDoc.embedFont === 'function') {
      const font = await pdfDoc.embedFont(standardMap[key]);
      __embeddedFonts.set(key, font);
      return font;
    }
  }

  for (const key of lookupKeys) {
    const custom = manifest.get(key);
    if (custom?.url && typeof pdfDoc.embedFont === 'function') {
      await ensureFontkit();
      try {
        if (typeof pdfDoc.registerFontkit === 'function' && window.fontkit) {
          pdfDoc.registerFontkit(window.fontkit);
        }
      } catch (e) {}
      const res = await fetch(custom.url, { credentials: 'same-origin' });
      if (!res.ok) return null;
      const bytes = await res.arrayBuffer();
      const font = await pdfDoc.embedFont(bytes);
      __embeddedFonts.set(key, font);
      return font;
    }
  }
  return null;
}

async function updateFieldAppearancesWithFonts(form, pdfDoc, fallbackFont){
  const PDFLib = window.PDFLib;
  const { PDFName, PDFTextField, PDFDropdown, PDFOptionList } = PDFLib;
  const fontManifest = await loadFontManifest();

  const fields = form.getFields();
  for (const field of fields) {
    const isText = PDFTextField && field instanceof PDFTextField;
    const isDropdown = PDFDropdown && field instanceof PDFDropdown;
    const isOptionList = PDFOptionList && field instanceof PDFOptionList;
    if (!isText && !isDropdown && !isOptionList) continue;

    const da = getFieldDefaultAppearance(field, PDFName);
    const fontKey = parseDaFontKey(da);
    const base = resolveBaseFontName(field, fontKey, PDFName, form);
    let font = null;
    if (base) {
      font = await getEmbeddedFont(pdfDoc, base, fontManifest);
    }
    if (!font && fontKey) {
      font = await getEmbeddedFont(pdfDoc, fontKey, fontManifest);
    }
    if (!font) font = fallbackFont;

    try {
      if (font && typeof field.updateAppearances === 'function') {
        field.updateAppearances(font);
      } else if (typeof field.updateAppearances === 'function') {
        field.updateAppearances();
      }
    } catch (e) {}
  }
}

function pdfArrayToNumbers(arr, PDFArray, PDFNumber){
  if (!arr) return null;
  const isPdfArray = PDFArray && arr instanceof PDFArray;
  const size = isPdfArray && typeof arr.size === 'function' ? arr.size() : null;
  const out = [];
  if (size !== null) {
    for (let i = 0; i < size; i++) {
      const obj = arr.get(i);
      if (PDFNumber && obj instanceof PDFNumber) out.push(obj.asNumber());
      else if (typeof obj?.asNumber === 'function') out.push(obj.asNumber());
      else {
        const n = Number(obj);
        if (Number.isFinite(n)) out.push(n);
      }
    }
    return out.length ? out : null;
  }
  if (Array.isArray(arr)) {
    for (const obj of arr) {
      const n = Number(obj);
      if (Number.isFinite(n)) out.push(n);
    }
    return out.length ? out : null;
  }
  return null;
}

function parseDaColor(da){
  const s = (da ?? '').toString();
  if (!s) return null;
  const rg = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+RG\b/);
  if (rg) return { model: 'rgb', values: rg.slice(1, 4).map(Number) };
  const rgFill = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+rg\b/);
  if (rgFill) return { model: 'rgb', values: rgFill.slice(1, 4).map(Number) };
  const g = s.match(/([\d.]+)\s+G\b/);
  if (g) return { model: 'gray', values: [Number(g[1])] };
  const gFill = s.match(/([\d.]+)\s+g\b/);
  if (gFill) return { model: 'gray', values: [Number(gFill[1])] };
  const k = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+K\b/);
  if (k) return { model: 'cmyk', values: k.slice(1, 5).map(Number) };
  const kFill = s.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+k\b/);
  if (kFill) return { model: 'cmyk', values: kFill.slice(1, 5).map(Number) };
  return null;
}

function colorOperators(color){
  if (!color || !Array.isArray(color.values)) return '';
  const vals = color.values.map(v => (Number.isFinite(v) ? String(v) : '0'));
  if (color.model === 'rgb' && vals.length >= 3) return `${vals[0]} ${vals[1]} ${vals[2]} RG`;
  if (color.model === 'gray' && vals.length >= 1) return `${vals[0]} G`;
  if (color.model === 'cmyk' && vals.length >= 4) return `${vals[0]} ${vals[1]} ${vals[2]} ${vals[3]} K`;
  return '';
}

function hexToRgbColor(hex){
  const m = /^#([\da-fA-F]{6})$/.exec((hex || '').trim());
  if (!m) return null;
  const raw = m[1];
  return {
    model: 'rgb',
    values: [
      parseInt(raw.slice(0, 2), 16) / 255,
      parseInt(raw.slice(2, 4), 16) / 255,
      parseInt(raw.slice(4, 6), 16) / 255,
    ],
  };
}

function getWidgetTextColor(widget, radioField, PDFName){
  try {
    const da = widget?.dict?.lookup?.(PDFName.of('DA'));
    if (da) {
      const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
      if (color) return color;
    }
  } catch (e) {}
  try {
    const da = radioField?.acroField?.dict?.lookup?.(PDFName.of('DA'));
    if (da) {
      const color = parseDaColor(da.decodeText ? da.decodeText() : String(da));
      if (color) return color;
    }
  } catch (e) {}
  return null;
}

function resolveRadioCrossColor(widget, radioField, PDFName){
  if (RADIO_CROSS_COLOR_MODE === 'admin') {
    return hexToRgbColor(RADIO_CROSS_COLOR_HEX) || { model: 'rgb', values: [0, 0, 0] };
  }
  return getWidgetTextColor(widget, radioField, PDFName);
}

function buildCrossAppearanceStream(pdfDoc, rect, color){
  const { context } = pdfDoc;
  const w = Math.max(1, rect.width || 1);
  const h = Math.max(1, rect.height || 1);
  const minDim = Math.min(w, h);
  const inset = minDim * 0.18;
  const lw = Math.min(2.5, Math.max(0.8, minDim * 0.1));
  const x1 = inset;
  const y1 = inset;
  const x2 = w - inset;
  const y2 = h - inset;
  const colorOp = colorOperators(color);
  const content = [
    'q',
    colorOp ? `${colorOp}` : '',
    `${lw} w`,
    `${x1} ${y1} m ${x2} ${y2} l`,
    `${x1} ${y2} m ${x2} ${y1} l`,
    'S',
    'Q',
  ].filter(Boolean).join('\n');
  const stream = context.flateStream(content, {
    Type: 'XObject',
    Subtype: 'Form',
    BBox: [0, 0, w, h],
    Resources: {},
  });
  return context.register(stream);
}

function buildOffAppearanceStream(pdfDoc, rect){
  const { context } = pdfDoc;
  const w = Math.max(1, rect.width || 1);
  const h = Math.max(1, rect.height || 1);
  const stream = context.flateStream('', {
    Type: 'XObject',
    Subtype: 'Form',
    BBox: [0, 0, w, h],
    Resources: {},
  });
  return context.register(stream);
}

function getWidgetOnName(widget, PDFName, PDFDict){
  try {
    const ap = widget?.dict?.lookup?.(PDFName.of('AP'));
    if (ap && (!PDFDict || ap instanceof PDFDict) && ap.lookup) {
      const n = ap.lookup(PDFName.of('N'));
      if (n && (!PDFDict || n instanceof PDFDict) && typeof n.keys === 'function') {
        const keys = n.keys();
        for (const k of keys) {
          const name = pdfNameToString(k);
          if (name && name.toLowerCase() !== 'off') return name;
        }
      }
    }
  } catch (e) {}
  return '';
}

function getRadioGroupValue(radioField, PDFName){
  try {
    const selected = radioField?.getSelected?.();
    if (selected) return pdfNameToString(selected);
  } catch (e) {}
  try {
    const v = radioField?.acroField?.dict?.lookup?.(PDFName.of('V'));
    if (v) return pdfNameToString(v);
  } catch (e) {}
  return '';
}

function getRadioGroupOptions(radioField, PDFName){
  try {
    const opts = radioField?.getOptions?.();
    if (Array.isArray(opts) && opts.length) return opts.map(o => pdfNameToString(o));
  } catch (e) {}
  try {
    const opt = radioField?.acroField?.dict?.lookup?.(PDFName.of('Opt'));
    const arr = opt?.asArray?.() || opt;
    if (Array.isArray(arr)) {
      return arr.map(o => pdfNameToString(o)).filter(Boolean);
    }
  } catch (e) {}
  return [];
}

function getWidgetAppearanceState(widget, PDFName){
  try {
    const as = widget?.dict?.lookup?.(PDFName.of('AS'));
    const name = pdfNameToString(as);
    return name && name.toLowerCase() !== 'off' ? name : '';
  } catch (e) {}
  return '';
}

function applyRadioCrossAppearances(pdfDoc, form, { debug } = {}){
  const PDFLib = window.PDFLib;
  const { PDFName, PDFDict } = PDFLib;
  const fields = form.getFields();
  let debugCount = 0;

  for (const field of fields) {
    if (!(field instanceof PDFLib.PDFRadioGroup)) continue;

    let selectedValue = getRadioGroupValue(field, PDFName);
    const widgets = field?.acroField?.getWidgets?.() || [];
    const options = getRadioGroupOptions(field, PDFName);
    if (!selectedValue) {
      for (const widget of widgets) {
        const widgetSelected = getWidgetAppearanceState(widget, PDFName);
        if (widgetSelected) {
          selectedValue = widgetSelected;
          break;
        }
      }
    }
    for (let i = 0; i < widgets.length; i++) {
      const widget = widgets[i];
      const rect = widget.getRectangle();
      let onName = getWidgetOnName(widget, PDFName, PDFDict);
      if (!onName) {
        if (options[i]) onName = options[i];
      }
      if (!onName && selectedValue) onName = selectedValue;
      if (!onName) {
        if (debug && debugCount < 5) {
          console.log('[PDF DEBUG] Radio appearance skipped (no On name)', {
            field: field.getName?.() || '(unknown)',
          });
        }
        continue;
      }
      const normalizedOn = pdfNameToString(onName);
      const isSelected = selectedValue && pdfNameToString(selectedValue).toLowerCase() === normalizedOn.toLowerCase();

      const color = resolveRadioCrossColor(widget, field, PDFName);
      const onRef = buildCrossAppearanceStream(pdfDoc, rect, color);
      const offRef = buildOffAppearanceStream(pdfDoc, rect);

      const apN = pdfDoc.context.obj({
        Off: offRef,
        [normalizedOn]: onRef,
      });
      const apDict = pdfDoc.context.obj({ N: apN });
      widget.dict.set(PDFName.of('AP'), apDict);
      widget.dict.set(PDFName.of('AS'), PDFName.of(isSelected ? normalizedOn : 'Off'));
    }

    if (selectedValue) {
      try {
        field.acroField?.setValue?.(PDFName.of(pdfNameToString(selectedValue)));
        field.acroField?.dict?.set?.(PDFName.of('V'), PDFName.of(pdfNameToString(selectedValue)));
        field.acroField?.dict?.set?.(PDFName.of('DV'), PDFName.of(pdfNameToString(selectedValue)));
      } catch (e) {}
    }

    if (debug && debugCount < 5) {
      debugCount++;
      const onNames = widgets.map(w => getWidgetOnName(w, PDFName, PDFDict)).filter(Boolean);
      console.log('[PDF DEBUG] Radio appearance', {
        field: field.getName?.() || '(unknown)',
        selectedValue,
        onNames,
        widgets: widgets.length,
      });
    }
  }
}

// --------- PDF fill: render appearances for consistent viewers ----------
let __didDump = false;

/**
 * ✅ NEW: pass fieldMetaMap so we can normalize dates before filling
 */
async function fillPdfForStudent(templateBytes, student, fieldMetaMap){
  __embeddedFonts = new Map();
  const PDFLib = window.PDFLib;
  const {
    PDFDocument,
    PDFCheckBox,
    PDFRadioGroup,
    PDFDropdown,
    PDFOptionList,
    PDFName,
    PDFBool,
    PDFArray,
    PDFNumber,
    PDFDict,
    StandardFonts,
  } = PDFLib;

  const pdfDoc = await PDFDocument.load(templateBytes);
  const form = pdfDoc.getForm();
  const values = student.values || {};
  const metaMap = (fieldMetaMap && typeof fieldMetaMap === 'object') ? fieldMetaMap : {};

  const norm = (s) => (s ?? '').toString().trim();
  const normLoose = (s) => norm(s).toLowerCase().replace(/\s+/g,'');

  const fieldsByName = new Map();
  const fieldsByLoose = new Map();

  const addToMap = (map, key, field) => {
    if (!key) return;
    const arr = map.get(key);
    if (arr) arr.push(field); else map.set(key, [field]);
  };

  const allFields = form.getFields();
  for (const f of allFields){
    let name = '';
    try { name = f.getName(); } catch (e) { name = ''; }
    name = norm(name);
    if (!name) continue;

    addToMap(fieldsByName, name, f);
    addToMap(fieldsByLoose, normLoose(name), f);
    addToMap(fieldsByLoose, normLoose(name.replace(/\.+$/,'')), f);
    addToMap(fieldsByLoose, normLoose(name.replace(/\[0\]$/,'')), f);
    addToMap(fieldsByLoose, normLoose(name.replace(/\s+/g,'')), f);
  }

  const getFieldList = (key) => {
    const k = norm(key);
    if (!k) return [];
    const exact = fieldsByName.get(k);
    if (exact && exact.length) return exact;
    const loose = fieldsByLoose.get(normLoose(k));
    if (loose && loose.length) return loose;
    return [];
  };

  const isCheckBox = (f) => (PDFCheckBox && f instanceof PDFCheckBox) || (typeof f?.check === 'function' && typeof f?.uncheck === 'function');
  const isRadioGroup = (f) => (PDFRadioGroup && f instanceof PDFRadioGroup) || (typeof f?.select === 'function' && typeof f?.getOptions === 'function' && !isCheckBox(f));
  const isDropdown = (f) => (PDFDropdown && f instanceof PDFDropdown) || (typeof f?.select === 'function' && typeof f?.getOptions === 'function' && !isRadioGroup(f) && !isCheckBox(f));
  const isOptionList = (f) => (PDFOptionList && f instanceof PDFOptionList);

  const getOnValue = (cb) => {
    try {
      const af = cb?.acroField;
      if (af && typeof af.getOnValue === 'function') {
        const on = af.getOnValue();
        if (on && typeof on.key === 'string') return on.key;
        const s = String(on);
        return s.startsWith('/') ? s.slice(1) : s;
      }
    } catch (e) {}
    try {
      const af = cb?.acroField;
      if (af && typeof af.getWidgets === 'function') {
        const ws = af.getWidgets();
        if (ws && ws.length && typeof ws[0].getOnValue === 'function') {
          const on = ws[0].getOnValue();
          if (on && typeof on.key === 'string') return on.key;
          const s = String(on);
          return s.startsWith('/') ? s.slice(1) : s;
        }
      }
    } catch (e) {}
    try {
      const af = cb?.acroField;
      if (af && typeof af.getWidgets === 'function') {
        for (const widget of (af.getWidgets() || [])) {
          const on = getWidgetOnName(widget, PDFName, PDFDict);
          if (on) return on;
        }
      }
    } catch (e) {}
    return '';
  };

  const pickOption = (field, value) => {
    const v = norm(value);
    if (!v || typeof field?.getOptions !== 'function') return v;
    try {
      const opts = field.getOptions() || [];
      const vL = normLoose(v);
      const found = opts.find(o => normLoose(o) === vL);
      return found || v;
    } catch (e) {
      return v;
    }
  };

  const setText = (f, v) => { try { if (typeof f?.setText === 'function') f.setText(norm(v)); } catch(e) {} };
  const setSelect = (f, v) => { try { if (v !== '') f.select(pickOption(f, v)); } catch(e) {} };

  const isPdfCheckboxChecked = (storedValue) => {
    if (storedValue === true) return true;
    if (storedValue === false || storedValue === null || storedValue === undefined) return false;
    const v = normLoose(storedValue);
    if (!v) return false;
    return ['1', 'true', 'on', 'yes', 'ja', 'checked', 'check', 'x'].includes(v);
  };

  const isPdfCheckboxUnchecked = (storedValue) => {
    if (storedValue === false || storedValue === null || storedValue === undefined) return true;
    const v = normLoose(storedValue);
    return v === '' || ['0', 'false', 'off', 'no', 'nein', 'unchecked'].includes(v);
  };

  const setCheckboxAppearanceState = (cb, checked) => {
    const onName = getOnValue(cb) || 'Yes';
    const stateName = checked ? onName : 'Off';
    try {
      if (checked && typeof cb?.check === 'function') cb.check();
      if (!checked && typeof cb?.uncheck === 'function') cb.uncheck();
    } catch (e) {}
    try {
      cb?.acroField?.dict?.set?.(PDFName.of('V'), PDFName.of(stateName));
      cb?.acroField?.dict?.set?.(PDFName.of('DV'), PDFName.of(stateName));
    } catch (e) {}
    try {
      const widgets = cb?.acroField?.getWidgets?.() || [];
      for (const widget of widgets) {
        const widgetOn = getWidgetOnName(widget, PDFName, PDFDict) || onName;
        widget.dict?.set?.(PDFName.of('AS'), PDFName.of(checked ? widgetOn : 'Off'));
      }
    } catch (e) {}
    return onName;
  };

  const setCheckGroupByOnValue = (checkboxes, desired, fieldName) => {
    const d = norm(desired);
    const dL = normLoose(d);
    const explicitOff = isPdfCheckboxUnchecked(desired);
    const booleanChecked = isPdfCheckboxChecked(desired);
    const onValues = checkboxes.map(cb => getOnValue(cb)).filter(Boolean);
    const hasOnValueMatch = !!dL && onValues.some(on => normLoose(on) === dL);
    for (const cb of checkboxes){
      const on = getOnValue(cb);
      const onL = normLoose(on);
      const checked = hasOnValueMatch ? (on && onL === dL) : (!explicitOff && booleanChecked);
      try {
        const writtenOn = setCheckboxAppearanceState(cb, checked);
        if (DEBUG_PDF) {
          console.log('[PDF DEBUG] Checkbox export state', {
            field_name: fieldName || '',
            stored_value: d,
            interpreted_checked: checked,
            pdf_on_state: writtenOn || on || '',
            written_V: checked ? (writtenOn || on || 'Yes') : 'Off',
            written_AS: checked ? (writtenOn || on || 'Yes') : 'Off',
          });
        }
      } catch(e) {}
    }
  };

  if (DEBUG_PDF && !__didDump) {
    __didDump = true;
    try {
      console.log('[PDF DEBUG] Field inventory:', allFields.map(f => {
        let n = ''; try { n = f.getName(); } catch(e) {}
        return {
          name: n,
          isCheckBox: isCheckBox(f),
          isRadioGroup: isRadioGroup(f),
          isDropdown: isDropdown(f),
          ctor: (f?.constructor?.name || ''),
          onValue: isCheckBox(f) ? getOnValue(f) : null,
          options: (typeof f?.getOptions === 'function') ? (f.getOptions?.() || null) : null
        };
      }));
      console.log('[PDF DEBUG] field_meta keys:', Object.keys(metaMap || {}).slice(0, 20));
    } catch(e) {}
  }

  for (const [key, raw] of Object.entries(values)){
    const list = getFieldList(key);
    if (!list.length) continue;

    const checkboxes = list.filter(isCheckBox);
    if (checkboxes.length) {
      const v = norm(raw);
      setCheckGroupByOnValue(checkboxes, v, key);
      continue;
    }

    const f = list[0];

    // ✅ NEW: normalize dates only if necessary
    const meta = metaMap[key] || null;
    const fieldType = (meta?.field_type || '').toString().toLowerCase();
    const expectedFmt = (meta?.date_format || '').toString().trim();

    let v = norm(raw);
    if (v && (fieldType === 'date' || expectedFmt)) {
      const normed = normalizeDateIfNeeded(v, expectedFmt);
      v = norm(normed);
    }

    if (isRadioGroup(f)) setSelect(f, v);
    else if (isDropdown(f) || isOptionList(f)) setSelect(f, v);
    else if (typeof f.setText === 'function') setText(f, v);
  }

  let appearanceFont = null;
  try {
    if (StandardFonts) {
      appearanceFont = await pdfDoc.embedFont(StandardFonts.Helvetica);
    }
  } catch (e) {}
  try {
    await updateFieldAppearancesWithFonts(form, pdfDoc, appearanceFont || undefined);
  } catch (e) {}
  try {
    applyRadioCrossAppearances(pdfDoc, form, { debug: DEBUG_PDF });
  } catch (e) {}
  try {
    const acro = form.acroForm;
    if (acro && acro.dict && PDFName) {
      const key = PDFName.of('NeedAppearances');
      try { acro.dict.delete(key); } catch (e) {}
      if (PDFBool) acro.dict.set(key, PDFBool.False);
    }
  } catch (e) {}

  return await pdfDoc.save();
}

function isPdfMissingContentsError(error){
  const msg = (error?.message || String(error || '')).toLowerCase();
  return msg.includes('missing contents') || msg.includes('missing /contents');
}

function pdfPageHasContents(page){
  const PDFLib = window.PDFLib || {};
  const { PDFName, PDFArray } = PDFLib;
  if (!page?.node || !PDFName) return true;

  try {
    const contents = page.node.get(PDFName.of('Contents'));
    if (!contents) return false;
    if (PDFArray && contents instanceof PDFArray && contents.size() === 0) return false;
  } catch (e) {
    return true;
  }

  return true;
}

async function createBookletPdf(srcBytes){
  const { PDFDocument } = window.PDFLib;
  const src = await PDFDocument.load(srcBytes);
  const pageCount = src.getPageCount();
  if (!pageCount) return srcBytes;

  const firstSize = src.getPage(0).getSize();
  const width = firstSize.width;
  const height = firstSize.height;

  // Keep booklet pagination unchanged, but do not create artificial source PDF
  // pages that later have to be embedded. pdf-lib blank pages may not have a
  // /Contents entry, so padded pages are represented as null placeholders and
  // drawn as empty halves on the imposed sheet.
  const pad = (4 - (pageCount % 4)) % 4;
  const total = pageCount + pad;
  const sheets = total / 4;
  const out = await PDFDocument.create();

  const logBlank = (index, reason) => {
    if (DEBUG_PDF) {
      console.warn('[PDF DEBUG] Booklet page has no /Contents and was inserted as a blank page.', {
        page: index + 1,
        reason,
      });
    }
  };

  const embed = async (index, operation) => {
    if (index >= pageCount) {
      logBlank(index, 'booklet-padding');
      return null;
    }

    const page = src.getPage(index);
    if (!pdfPageHasContents(page)) {
      logBlank(index, 'missing-contents');
      return null;
    }

    try {
      return await out.embedPage(page);
    } catch (e) {
      if (isPdfMissingContentsError(e)) {
        logBlank(index, 'embed-missing-contents');
        return null;
      }

      const msg = tfmt('booklet_embed_failed', null, {
        page: index + 1,
        operation,
      });
      const wrapped = new Error(msg);
      wrapped.cause = e;
      throw wrapped;
    }
  };

  const drawEmbeddedPage = (target, embeddedPage, x) => {
    if (!embeddedPage) return;
    target.drawPage(embeddedPage, { x, y: 0, width, height });
  };

  for (let s = 0; s < sheets; s += 1) {
    const rightIndex = s * 2;
    const leftIndex = total - 1 - (s * 2);
    const backLeftIndex = s * 2 + 1;
    const backRightIndex = total - 2 - (s * 2);

    const front = out.addPage([width * 2, height]);
    const leftPage = await embed(leftIndex, 'booklet-impose/front-left');
    const rightPage = await embed(rightIndex, 'booklet-impose/front-right');
    drawEmbeddedPage(front, leftPage, 0);
    drawEmbeddedPage(front, rightPage, width);

    const back = out.addPage([width * 2, height]);
    const backLeft = await embed(backLeftIndex, 'booklet-impose/back-left');
    const backRight = await embed(backRightIndex, 'booklet-impose/back-right');
    drawEmbeddedPage(back, backLeft, 0);
    drawEmbeddedPage(back, backRight, width);
  }

  return await out.save();
}

async function flattenPdfBytes(srcBytes){
  const { PDFDocument } = window.PDFLib;
  const doc = await PDFDocument.load(srcBytes);
  try {
    const form = doc.getForm();
    try { form.updateFieldAppearances(); } catch (e) {}
    try { form.flatten(); } catch (e) {}
  } catch (e) {}
  return await doc.save();
}

async function maybeFlattenPdfBytes(srcBytes){
  if (ALLOW_EDITABLE_PDF) return srcBytes;
  return await flattenPdfBytes(srcBytes);
}

async function exportNow(){
  hideProgress();
  const mode = currentMode();
  const classId = Number(elClass.value||0);
  const selectedStudentId = elStudent?.value;

  setInfo('');
  setStatus(t('status_load_export'));
  showProgress(t('status_load_export'), 0, 1);

  let data;
  try {
    const payload = { action: 'data', class_id: classId, only_submitted: onlySubmittedFlag() };
    if (modeNeedsStudent(mode) && selectedStudentId) payload.student_id = Number(selectedStudentId);
    data = await apiFetch(payload);
  } catch (e) {
    const msg = (e?.message || String(e));
    if (isNonFatalBusinessError(msg)) {
      hideProgress();
      setStatus(t('status_info'));
      setInfo(msg + t('admin_template_hint'));
      return;
    }
    throw e;
  }

  // ✅ keep field meta current
  __fieldMetaMap = (data.field_meta && typeof data.field_meta === 'object') ? data.field_meta : (__fieldMetaMap || {});

  // ✅ FIX: Dropdown nicht mit single-response überschreiben
  if (modeNeedsStudent(mode)) {
    fillStudentSelect(__fullStudentList, selectedStudentId);
  } else {
    fillStudentSelect(data.students || [], selectedStudentId);
  }

  const students = data.students || [];
  if (!students.length) throw new Error(t('error_no_students'));

  const needZip = (mode === 'zip');
  const isBookletSingle = (mode === 'booklet_single');
  const isBookletMerged = (mode === 'booklet_merged');
  const isBooklet = isBookletSingle || isBookletMerged;
  setStatus(t('status_load_libs'));
  showProgress(t('status_load_libs'), 0, 1);
  await loadLibsIfNeeded(needZip);
  showProgress(t('status_libs_loaded'), 1, 1);

  setStatus(t('status_load_template'));
  showProgress(t('status_load_template'), 0, 1);
  const tplResp = await fetch(data.pdf_url, { credentials: 'same-origin' });
  if (!tplResp.ok) throw new Error(t('error_template_load'));
  const templateBytes = new Uint8Array(await tplResp.arrayBuffer());
  showProgress(t('status_template_loaded'), 1, 1);

  const baseName = safeFilename((data.class?.display || t('class_label_fallback')) + ' ' + (data.class?.school_year || ''));
  const suffix = onlySubmittedFlag() ? t('only_submitted_suffix') : '';
  const bookletSuffix = isBooklet ? (t('booklet_suffix') || '') : '';

  if (mode === 'zip') {
    setStatus(t('status_create_pdfs'));
    showProgress(t('status_create_pdfs'), 0, students.length);
    const zip = new window.JSZip();
    let done = 0;
    for (const s of students){
      const bytes = await fillPdfForStudent(templateBytes, s, __fieldMetaMap);
      const finalBytes = await maybeFlattenPdfBytes(bytes);
      const fn = safeFilename(s.name) || tfmt('student_filename_fallback', null, { id: s.id });
      zip.file(fn + '.pdf', finalBytes);
      done++;
      setStatus(`${t('status_create_pdfs')} ${done}/${students.length}`);
      showProgress(t('status_create_pdfs'), done, students.length);
    }
    setStatus(t('status_zip_packing'));
    showProgress(t('status_zip_packing'), students.length, students.length);
    const out = await zip.generateAsync({ type: 'uint8array' });
    downloadBytes(out, baseName + suffix + '.zip', 'application/zip');
    setStatus(t('status_zip_done'));
    showProgress(t('status_done'), students.length, students.length);
    return;
  }

  if (mode === 'merged' || isBookletMerged) {
    setStatus(t('status_merge_pdf'));
    showProgress(t('status_merging'), 0, students.length);
    const { PDFDocument } = window.PDFLib;
    const merged = await PDFDocument.create();
    let done = 0;
    for (const s of students){
      const filledBytes = await fillPdfForStudent(templateBytes, s, __fieldMetaMap);
      // Always flatten merged PDFs to avoid duplicate field names across students.
      const finalBytes = await flattenPdfBytes(filledBytes);
      let sourceBytes = finalBytes;
      if (isBookletMerged) {
        sourceBytes = await createBookletPdf(finalBytes);
      }
      const src = await PDFDocument.load(sourceBytes);
      const pages = await merged.copyPages(src, src.getPageIndices());
      pages.forEach(p => merged.addPage(p));
      done++;
      setStatus(`${t('status_merging')} ${done}/${students.length}`);
      showProgress(t('status_merging'), done, students.length);
    }
    const out = await merged.save();
    downloadBytes(out, baseName + suffix + (isBookletMerged ? bookletSuffix : '') + '.pdf', 'application/pdf');
    setStatus(t('status_pdf_done'));
    showProgress(t('status_done'), students.length, students.length);
    return;
  }

  setStatus(t('status_create_pdf'));
  showProgress(t('status_create_pdf'), 0, 1);
  const chosenId = elStudent?.value;
  let s = students[0];
  if (chosenId) {
    const found = students.find(x => String(x.id) === String(chosenId));
    if (found) s = found;
  }
  const out = await fillPdfForStudent(templateBytes, s, __fieldMetaMap);
  const finalBytes = await maybeFlattenPdfBytes(out);
  const fn = safeFilename(s.name) || tfmt('student_filename_fallback', null, { id: s.id });
  if (isBookletSingle) {
    setStatus(t('status_create_booklet'));
    showProgress(t('status_create_booklet'), 1, 2);
    const bookletBytes = await createBookletPdf(finalBytes);
    downloadBytes(bookletBytes, fn + suffix + bookletSuffix + '.pdf', 'application/pdf');
  } else {
    downloadBytes(finalBytes, fn + suffix + '.pdf', 'application/pdf');
  }
  setStatus(t('status_pdf_done'));
  showProgress(t('status_done'), 1, 1);
}

if (btnExport) {
  btnExport.addEventListener('click', async () => {
    try {
      btnExport.disabled = true;
      if (btnCheck) btnCheck.disabled = true;
      __exportInProgress = true;

      const preview = await check();

      const sum = preview?.warnings_summary || {};
      const totalMissing = Number(sum.total_missing || 0);

      if (preview && totalMissing > 0) {
        const proceed = await openMissingModal(preview);
        if (!proceed) {
          setStatus(t('status_export_cancelled'));
          return;
        }
      }

      await exportNow();

    } catch (e) {
      showProgress(t('progress_error'), 0, 1);
      setStatus(tfmt('status_error', null, { message: (e?.message || e) }));
    } finally {
      __exportInProgress = false;
      btnExport.disabled = false;
      if (btnCheck) btnCheck.disabled = false;
    }
  });
}

// init
(function initFromQuery(){
  const q = new URLSearchParams(window.location.search);
  const mode = (q.get('mode') || '').toLowerCase();
  const studentId = q.get('student_id') ? String(q.get('student_id')) : '';
  const onlySub = (q.get('only_submitted') === '1');

  if (elOnlySubmitted && onlySub) elOnlySubmitted.checked = true;

  if (mode === 'merged' || mode === 'zip' || mode === 'single' || mode === 'booklet_single' || mode === 'booklet_merged') {
    const r = document.querySelector('input[name="mode"][value="' + mode + '"]');
    if (r) r.checked = true;
  }
  updateModeUI();

  if (!elClass) return;

  if (studentId && elStudent) {
    const opt = Array.from(elStudent.options).find(o => String(o.value) === studentId);
    if (opt) elStudent.value = studentId;
  }
  resetPreview();
})();

if (elClass) {
  check().catch(() => {
    // ignore initial load errors; user can retry via "Prüfen"
  });
}
</script>
