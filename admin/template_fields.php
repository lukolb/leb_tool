<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$templateId = (int)($_GET['template_id'] ?? 0);
if ($templateId <= 0) {
  render_admin_header('Feld-Editor');
  echo '<div class="alert danger"><strong>template_id fehlt.</strong></div>';
  echo '<div class="card"><a class="btn secondary" href="'.h(url('admin/templates.php')).'">← Templates</a></div>';
  render_admin_footer();
  exit;
}

$pdfUrl = url('admin/file.php?template_id=' . $templateId);

render_admin_header('Feld-Editor');
?>

<style>
  .wiz-preview { position: sticky; top: 130px; align-self: start; }

  .layout2{
    display:grid;
    grid-template-columns: 1fr 10px 0.9fr; /* middle = resizer */
    gap:14px;
    align-items:start;
  }
  .layout2.hide-preview{
    grid-template-columns: 1fr !important;
  }
  @media (max-width: 1200px){
    .layout2{ grid-template-columns: 1fr; }
    .wiz-preview{ position: static; }
    .col-resizer{ display:none; }
  }

  .col-resizer{
    width:10px;
    border-radius:999px;
    background: rgba(0,0,0,0.04);
    border: 1px solid var(--border);
    cursor: col-resize;
    user-select:none;
    height: 100%;
    min-height: 200px;
    position: sticky;
    top: 18px;
    align-self: start;
  }
  .col-resizer:hover{ background: rgba(0,0,0,0.08); }
  .col-resizer.dragging{ background: rgba(176,0,32,0.12); border-color: rgba(176,0,32,0.35); }
  
  /* Drag handle only in field name col */
    .fieldname-cell{
      display:flex;
      align-items:flex-start;
      gap:8px;
    }

    .drag-handle{
      width:18px;
      min-width:18px;
      height:18px;
      margin-top:2px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border:1px solid var(--border);
      border-radius:6px;
      background: rgba(0,0,0,0.03);
      cursor: grab;
      user-select: none;
    }

    .drag-handle:active{ cursor: grabbing; }

    /* Optional: damit man Feldname-Text markieren kann */
    .fieldname-text{
      user-select: text;
    }

  .panel{
    border:1px solid var(--border);
    border-radius:14px;
    padding:12px;
  }

  /* Groups bar (top) */
  .groups-bar{
    display:flex;
    gap:10px;
    align-items:flex-start;
    flex-wrap:wrap;
    padding:10px;
    border:1px solid var(--border);
    border-radius:14px;
    background: rgba(0,0,0,0.015);
  }
  .group-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    border:1px solid var(--border);
    border-radius:999px;
    padding:6px 10px;
    cursor:pointer;
    user-select:none;
    background: var(--card, #fff);
  }
  .group-pill:hover{ background: rgba(0,0,0,0.03); }
  .group-pill.is-active{ outline: 2px solid rgba(176,0,32,0.25); }
  .group-pill .count{ color: var(--muted); font-size: 12px; }
  .group-pill .toggle{
    width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center;
    border:1px solid var(--border); border-radius:6px; font-weight:700;
  }

  /* Table */
  .table-scroll{
    max-height: 68vh;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 12px;
  }
  #fieldsTbl{
    width: 100%;
    min-width: 2000px;
    border-collapse: separate;
    border-spacing: 0;
  }
  #fieldsTbl th, #fieldsTbl td{
    border-bottom: 1px solid var(--border);
    padding: 10px;
    background: var(--card, #fff);
  }
  #fieldsTbl thead th{
    position: sticky;
    top: 0;
    z-index: 6;
  }
  #fieldsTbl input[type="text"], #fieldsTbl select, #fieldsTbl textarea{
    width: 100%;
    box-sizing: border-box;
  }

  /* sticky reference columns (checkbox + field name) */
  .sticky-col-0{ position: sticky; left: 0; z-index: 100; background: var(--card, #fff); }
  .sticky-col-1{ position: sticky; left: 45px; z-index: 100; background: var(--card, #fff); }
  #fieldsTbl thead .sticky-col-0, #fieldsTbl thead .sticky-col-1{ z-index: 200; }
  
  td:has(> input[type='checkbox']) {
      text-align: center;
  }

  /* group header rows inside table */
  tr.group-row td{
    background: rgba(0,0,0,0.035);
    font-weight: 700;
  }
  /* nur die linke Gruppen-Zelle ist sticky-left */
    tr.group-row td.group-sticky{
      position: sticky;
      left: 0;
      z-index: 9;                 /* über normalen Zellen; unter thead (10) */
      background: rgba(0,0,0,0.035);
    }
  /* damit die Gruppenzeile auch unter dem sticky THEAD korrekt liegt */
  #fieldsTbl thead th{ z-index: 10; }
  tr.group-row td .gwrap{
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  }
  tr.group-row td .gbtn{
    width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center;
    border:1px solid var(--border); border-radius:8px; user-select:none;
  }
  tr.group-row td .gmeta{ font-weight:400; color: var(--muted); font-size: 12px; }

  .toolbar{
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start;
    padding:12px; border:1px dashed var(--border); border-radius:12px;
  }
  .toolbar .block{ min-width: 220px; }
  .toolbar .actions{ justify-content:flex-start; }
  .pill{
    display:inline-flex; gap:8px; align-items:center;
    padding:6px 10px; border:1px solid var(--border); border-radius:999px;
  }
  .muted2{ color: var(--muted); font-size: 12px; }
  tr.is-selected{ outline: 2px solid rgba(176,0,32,0.25); outline-offset:-2px; }
  tr.is-found{ box-shadow: inset 0 0 0 3px rgba(176,0,32,0.25); }

  .badge{
    display:inline-flex; align-items:center; gap:6px;
    border:1px solid var(--border);
    border-radius:999px;
    padding:4px 8px;
    font-size: 12px;
    color: var(--muted);
    background: rgba(0,0,0,0.02);
    margin: 2px 6px 2px 0;
  }
  .extras{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .extras .btn{ padding: 6px 10px; border-radius: 10px; }

  /* Dialogs */
  dialog{
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 0;
    max-width: 860px;
    width: calc(100% - 24px);
  }
  dialog::backdrop{ background: rgba(0,0,0,0.35); }
  .dlg-head{ padding: 12px 14px; border-bottom:1px solid var(--border); }
  .dlg-body{ padding: 14px; }
  .dlg-foot{ padding: 12px 14px; border-top:1px solid var(--border); display:flex; gap:10px; justify-content:flex-end; }
  .dlg-body textarea{ min-height: 220px; font-family: ui-monospace, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }
  .dlg-title{ margin:0; }

  /* Split banner */
  .split-banner{
    border: 1px solid rgba(0,0,0,0.08);
    background: rgba(0,150,255,0.06);
    border-radius: 14px;
    padding: 10px 12px;
    display:none;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
  }
  .split-banner strong{ font-weight:700; }
</style>

<div class="card">
    <div class="row-actions" style="float: right;">
        <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">← zurück zu den Templates</a>
    </div>

  <h1>Feld-Editor</h1>
</div>

<div id="dirtyWarning" class="alert danger" style="display:none">
  <p style="margin:0; display:flex; align-items:center; gap:12px;">
    <b>Achtung! Ungespeicherte Änderungen!</b>
    <button class="btn primary" type="button" id="btnSaveTop" style="margin-left:auto;">
      Speichern
    </button>
  </p>
</div>

<!-- Hinweis für "DE | EN" Split (ohne Fokus-Verlust / ohne confirm()) -->
<div class="card split-banner" id="splitBanner">
  <div class="muted2" id="splitBannerText">—</div>
  <div class="actions" style="justify-content:flex-start; gap:8px;">
    <button class="btn secondary" type="button" id="btnSplitNow">Jetzt trennen</button>
    <button class="btn secondary" type="button" id="btnSplitDismiss">Ignorieren</button>
    <button class="btn secondary" type="button" id="btnSplitAll">Alle trennen</button>
    <button class="btn secondary" type="button" id="btnSplitResetIgnored">Ignorierte zurücksetzen</button>
  </div>
</div>

<div class="card" id="metaCard">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
    <div>
      <div class="muted" id="metaLine">Lade…</div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <a class="btn secondary" type="button" id="btnTogglePreview">Vorschau ausblenden</a>
    </div>
  </div>
</div>

<datalist id="groupList"></datalist>

<!-- OPTIONS MODAL -->
<dialog id="optionsModal">
  <div class="dlg-head">
    <h3 class="dlg-title">Optionen</h3>
    <div class="muted2" id="optSubtitle"></div>
  </div>
  <div class="dlg-body">
    <div class="grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
      <div>
        <label>Options-Vorlage (option_list_templates)</label>
        <select id="optTpl"><option value="">—</option></select>
        <div class="muted2">Setzt options_json + merkt <code>meta.option_list_template_id</code>.</div>
      </div>
      <div class="actions" style="justify-content:flex-start; gap:8px;">
        <button class="btn secondary" type="button" id="btnGrade16">Noten 1–6</button>
        <button class="btn secondary" type="button" id="btnClearOptions">Leeren</button>
      </div>
    </div>

    <div style="margin-top:12px;">
      <label>options_json (JSON)</label>
      <textarea id="optJson" placeholder='{"options":[{"value":"A","label":"A","icon_id":123}]}'></textarea>
      <div class="muted2">Wir speichern die Items als <code>{"options":[...]}</code> ins Feld.</div>
    </div>
  </div>

  <form method="dialog">
    <div class="dlg-foot">
      <button class="btn secondary" value="cancel" type="submit">Abbrechen</button>
      <button class="btn primary" value="ok" type="submit">Übernehmen</button>
    </div>
  </form>
</dialog>

<!-- DATE MODAL -->
<dialog id="dateModal">
  <div class="dlg-head">
    <h3 class="dlg-title">Datumsformat</h3>
    <div class="muted2" id="dateSubtitle"></div>
  </div>
  <div class="dlg-body">
    <div class="grid" style="grid-template-columns: 180px 1fr; gap:12px;">
      <div>
        <label>Modus</label>
        <select id="dateMode">
          <option value="preset">Preset</option>
          <option value="custom">Custom</option>
        </select>
      </div>
      <div>
        <label>Preset</label>
        <select id="datePreset">
          <option value="MM/DD/YYYY">MM/DD/YYYY (US)</option>
          <option value="DD.MM.YYYY">DD.MM.YYYY (DE)</option>
          <option value="YYYY-MM-DD">YYYY-MM-DD (ISO)</option>
        </select>
      </div>
    </div>
    <div style="margin-top:12px;">
      <label>Custom Format</label>
      <input id="dateCustom" placeholder="z.B. DD. MMMM YYYY">
      <div class="muted2">Wird genutzt, wenn Modus = Custom.</div>
    </div>
  </div>

  <form method="dialog">
  <div class="dlg-foot">
    <button class="btn secondary" value="cancel" type="submit">Abbrechen</button>
    <button class="btn primary" value="ok" type="submit">Übernehmen</button>
  </div>
</form>
</dialog>

<!-- PDF REPLACE -->
<div class="card panel">
  <div style="display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap;">
    <div>
      <h2>PDF-Vorlage ersetzen</h2>
      <div class="muted2">Lädt eine neue PDF-Datei hoch, synchronisiert Feld-Positionen und informiert über fehlende Felder.</div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <a class="btn secondary" type="button" id="btnReplacePdf">PDF austauschen</a>
      <a class="btn secondary" type="button" id="btnDeleteMissing" style="display:none;">Fehlende Felder löschen…</a>
    </div>
  </div>
  <div style="margin-top:12px; display:grid; gap:6px;">
    <div>
      <label>Neue PDF auswählen</label>
      <input type="file" id="replacePdfFile" accept=".pdf,application/pdf">
    </div>
    <div class="muted2">Empfohlen: gleiche Felder behalten oder neu zuordnen, damit bestehende Daten nicht verloren gehen.</div>
  </div>
  <div class="muted2" id="replacePdfStatus" style="margin-top:8px;"></div>
</div>

<dialog id="mapPdfFieldsDialog">
  <div class="dlg-head">
    <h3 class="dlg-title" style="margin:0;">Neue PDF-Felder zuordnen</h3>
    <div class="muted2">Neue PDF-Felder können bestehenden (fehlenden) Feldern zugeordnet werden, damit Daten erhalten bleiben.</div>
  </div>
  <div class="dlg-body">
    <div class="muted2" id="mapPdfSummary"></div>
    <div id="mapPdfFieldsList" class="grid" style="grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;"></div>
    <div class="muted2" id="mapPdfError" style="color:#b00020; display:none;"></div>
  </div>
  <form method="dialog">
    <div class="dlg-foot">
      <button class="btn secondary" value="cancel" type="submit">Abbrechen</button>
      <button class="btn primary" value="ok" type="submit">Zuordnung übernehmen</button>
    </div>
  </form>
</dialog>

<!-- TOP GROUPS OVERVIEW -->
<div class="card panel">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
    <div>
      <h2>Gruppenübersicht</h2>
      <div class="muted2">Klick = filtern, Toggle = Gruppe in Tabelle ein/ausklappen. Alt-Klick = Gruppe ausblenden.</div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <a class="btn secondary" type="button" id="btnClearGroupFilter">Gruppenfilter löschen</a>
      <a class="btn secondary" type="button" id="btnShowAllGroups">Alle Gruppen einblenden</a>
    </div>
  </div>
  <div class="groups-bar" id="groupsBar" style="margin-top:10px;"></div>
</div>

<div class="layout2" id="layout2">
  <!-- TABLE -->
  <div class="card" style="overflow:hidden; margin: 0" id="tableCard">
    <div class="grid" style="grid-template-columns: 1fr 200px; gap:12px; align-items:end;">
      <div>
          <h3 style="margin-top: 0;">Filter (Feldname/Label/Gruppe)</h3>
        <input id="fieldFilter" placeholder="z.B. soc, work, eng, math …">
        <div style="margin-top:8px;">
          <label class="muted2" style="display:block; margin-bottom:4px;">Nicht enthält</label>
          <input id="fieldExclude" placeholder="z.B. -T">
        </div>
        <div class="muted2">Filter wirkt auf Bulk-Aktionen (sichtbare Zeilen) und Gruppenübersicht.</div>
      </div>
      <div class="actions" style="justify-content:flex-start;">
        <a class="btn secondary" type="button" id="btnClearFilter">Filter löschen</a>
      </div>
    </div>

    <!-- BULK TOOLBAR -->
    <div class="pill" style="float: right; margin-top: 19px"><strong>Auswahl:</strong> </div>
    <h3 style="margin-bottom: 0;">Werte setzen</h3>
    <div class="toolbar" style="margin-top:12px;">

      <div class="block">
        <label>Gruppe setzen</label>
        <input id="bulkGroup" list="groupList" placeholder="z.B. Social / Math / German">
        <div class="muted2">Leer lassen → Gruppe entfernen.</div>
      </div>

      <div class="block">
        <label>Typ setzen</label>
        <select id="bulkType">
          <option value="">—</option>
          <option>text</option><option>multiline</option><option>date</option><option>number</option>
          <option>grade</option><option>checkbox</option><option>radio</option><option>select</option><option>signature</option>
        </select>
      </div>

      <div class="block">
        <label>Rechte</label>
        <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap:8px;">
          <div>
            <label class="muted2">Kind</label>
            <select id="bulkChild">
              <option value="">—</option>
              <option value="1">Ja</option>
              <option value="0">Nein</option>
            </select>
          </div>
          <div>
            <label class="muted2">Lehrer</label>
            <select id="bulkTeacher">
              <option value="">—</option>
              <option value="1">Ja</option>
              <option value="0">Nein</option>
            </select>
          </div>
          <div>
            <label class="muted2">Required</label>
            <select id="bulkRequired">
              <option value="">—</option>
              <option value="1">Ja</option>
              <option value="0">Nein</option>
            </select>
          </div>
        </div>
      </div>

      <div class="block" style="min-width:340px;">
        <label>Options-Vorlage (option_list_templates)</label>
        <select id="bulkTpl">
          <option value="">— keine —</option>
        </select>
        <div class="muted2">Setzt options_json + merkt <code>meta.option_list_template_id</code>.</div>
      </div>

      <div class="block" style="min-width:360px;">
        <label>Datumsformat (nur date)</label>
        <div style="display:flex; gap:8px;">
          <select id="bulkDateMode" style="max-width:140px;">
            <option value="">—</option>
            <option value="preset">Preset</option>
            <option value="custom">Custom</option>
          </select>
          <select id="bulkDatePreset">
            <option value="MM/DD/YYYY">MM/DD/YYYY (US)</option>
            <option value="DD.MM.YYYY">DD.MM.YYYY (DE)</option>
            <option value="YYYY-MM-DD">YYYY-MM-DD (ISO)</option>
          </select>
        </div>
        <input id="bulkDateCustom" placeholder="z.B. DD. MMMM YYYY" style="margin-top:6px;">
      </div>

      <div class="actions">
          <a class="btn secondary" type="button" id="btnApplySelected" style="gap: 0;">Auf Auswahl (<span id="selCount">0</span>) anwenden</a>
        <a class="btn secondary" type="button" id="btnApplyVisible">Auf sichtbare anwenden</a>
      </div>

      <div class="block" style="min-width:280px;">
          <h3 style="margin-bottom: 0;">Auto-Group</h3>
        <div class="muted" style="margin-bottom: 10px;">Prefix ignoriert alles nach <code>-</code> (z.B. <code>mu-grade</code> → <code>mu</code>).</div>
        <div style="display:flex; gap:8px;">
          <a class="btn secondary" type="button" id="btnAutoGroupPrefix">Nach Prefix</a>
          <a class="btn secondary" type="button" id="btnAutoGroupPage">Nach PDF-Seite</a>
        </div>
      </div>
        <div class="block" style="min-width:100%; text-align: end;">
            <div class="muted2" id="saveHint" style="min-width:220px;">&nbsp;</div>
            <a class="btn primary" type="button" id="btnSave">Speichern</a>
        </div>
    </div>

    <div class="table-scroll" id="tableScroll" style="margin-top:12px;">
      <table id="fieldsTbl">
        <thead>
          <tr>
            <th class="sticky-col-0" style="width:46px;">✓</th>
            <th class="sticky-col-1">Feldname</th>
            <th style="min-width:220px;">Gruppe</th>
            <th style="min-width:220px;">Gruppentitel (EN)</th>
            <th style="min-width:160px;">Typ</th>
            <th style="min-width:260px;">Label</th>
            <th style="min-width:260px;">Label (EN)</th>
            <th style="min-width:240px;">Stammfeld</th>
            <th style="min-width:420px;">Help</th>
            <th>Kind</th>
            <th>Lehrer</th>
            <th>Klassenfeld</th>
            <th>Erforderlich</th>
            <th style="min-width:420px;">Extras</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <p class="muted2" style="margin-top:10px;">
      • Klick auf Zeile markiert das Feld im PDF.<br>
      • Klick im PDF sucht das Feld in der Tabelle und markiert es (wie in templates.php).<br>
      • Im PDF werden alle Felder der aktuellen Seite hellblau angezeigt.<br>
      • Drag&Drop in der Tabelle ändert die Reihenfolge (<code>sort_order</code>) ohne extra Sort-Spalte.<br>
      • Preview-Breite: ziehe den Balken zwischen Tabelle und Vorschau.
    </p>
  </div>

  <!-- RESIZER -->
  <div class="col-resizer" id="colResizer" title="Ziehen um Vorschau-Breite anzupassen"></div>

  <!-- PDF Preview -->
  <div class="card wiz-preview" id="previewCard" style="margin:0;">
    <h2>PDF Vorschau</h2>
    <div class="muted" id="pdfHint">Klicke links ein Feld, um es im PDF zu markieren.</div>

    <div style="display:flex; gap:8px; align-items:center; margin:10px 0; flex-wrap:wrap;">
      <button class="btn secondary" id="btnPrevPage" type="button">←</button>
      <div class="muted" id="pageInfo">Seite –</div>
      <button class="btn secondary" id="btnNextPage" type="button">→</button>
      <div class="muted2" style="margin-left:auto;">Tipp: Klick ins PDF → Feld finden</div>
    </div>

    <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden;">
      <canvas id="pdfCanvas" style="display:block; width:100%; height:auto; cursor: crosshair;"></canvas>
    </div>
  </div>
</div>

<script type="module">
import * as pdfjsLib from <?= json_encode(url('assets/pdfjs/pdf.min.mjs')) ?>;
pdfjsLib.GlobalWorkerOptions.workerSrc = <?= json_encode(url('assets/pdfjs/pdf.worker.min.mjs')) ?>;

const csrf = "<?=h(csrf_token())?>";
const templateId = <?= (int)$templateId ?>;

const apiUrl = "<?=h(url('admin/ajax/template_fields_api.php'))?>";
const optionListsApiUrl = "<?=h(url('admin/ajax/option_lists_api.php'))?>";
const replacePdfUrl = "<?=h(url('admin/ajax/templates_replace_pdf.php'))?>";
const importFieldsUrl = "<?=h(url('admin/ajax/import_fields.php'))?>";

const metaLine = document.getElementById('metaLine');
const tbody = document.querySelector('#fieldsTbl tbody');
const saveHint = document.getElementById('saveHint');

const splitBanner = document.getElementById('splitBanner');
const splitBannerText = document.getElementById('splitBannerText');
const btnSplitNow = document.getElementById('btnSplitNow');
const btnSplitResetIgnored = document.getElementById('btnSplitResetIgnored');
const btnSplitDismiss = document.getElementById('btnSplitDismiss');
let splitCandidate = null; // { fieldId, idx, de, en, inpDE, inpEN }

const groupList = document.getElementById('groupList');
const groupsBar = document.getElementById('groupsBar');
const btnShowAllGroups = document.getElementById('btnShowAllGroups');
const btnClearGroupFilter = document.getElementById('btnClearGroupFilter');

const fieldFilter = document.getElementById('fieldFilter');
const fieldExclude = document.getElementById('fieldExclude');
const btnClearFilter = document.getElementById('btnClearFilter');

const selCount = document.getElementById('selCount');
const btnSave = document.getElementById('btnSave');
const btnSaveTop = document.getElementById('btnSaveTop');

const bulkGroup = document.getElementById('bulkGroup');
const bulkType = document.getElementById('bulkType');
const bulkChild = document.getElementById('bulkChild');
const bulkTeacher = document.getElementById('bulkTeacher');
const bulkRequired = document.getElementById('bulkRequired');
const bulkTpl = document.getElementById('bulkTpl');

const bulkDateMode = document.getElementById('bulkDateMode');
const bulkDatePreset = document.getElementById('bulkDatePreset');
const bulkDateCustom = document.getElementById('bulkDateCustom');

const btnApplySelected = document.getElementById('btnApplySelected');
const btnApplyVisible = document.getElementById('btnApplyVisible');
const btnAutoGroupPrefix = document.getElementById('btnAutoGroupPrefix');
const btnAutoGroupPage = document.getElementById('btnAutoGroupPage');

const tableScroll = document.getElementById('tableScroll');

const btnTogglePreview = document.getElementById('btnTogglePreview');
const layout2 = document.getElementById('layout2');
const previewCard = document.getElementById('previewCard');

const replacePdfInput = document.getElementById('replacePdfFile');
const btnReplacePdf = document.getElementById('btnReplacePdf');
const replacePdfStatus = document.getElementById('replacePdfStatus');
const btnDeleteMissing = document.getElementById('btnDeleteMissing');

const mapPdfFieldsDialog = document.getElementById('mapPdfFieldsDialog');
const mapPdfFieldsList = document.getElementById('mapPdfFieldsList');
const mapPdfSummary = document.getElementById('mapPdfSummary');
const mapPdfError = document.getElementById('mapPdfError');

const colResizer = document.getElementById('colResizer');

const optionsModal = document.getElementById('optionsModal');
const optSubtitle = document.getElementById('optSubtitle');
const optTpl = document.getElementById('optTpl');
const optJson = document.getElementById('optJson');
const btnGrade16 = document.getElementById('btnGrade16');
const btnClearOptions = document.getElementById('btnClearOptions');

const dateModal = document.getElementById('dateModal');
const dateSubtitle = document.getElementById('dateSubtitle');
const dateMode = document.getElementById('dateMode');
const datePreset = document.getElementById('datePreset');
const dateCustom = document.getElementById('dateCustom');

let template = null;
let fields = [];
let optionTemplates = [];

let filterText = '';
let excludeText = '';
let groupFilter = '';
let hiddenGroups = new Set();
let collapsedGroupHeaders = new Set();

let selected = new Set();
let dirty = new Set();
let lastFoundRowId = null;
let lastMissingNames = [];

let isRowDragging = false;
let dragScrollRaf = 0;
let lastDragClientY = 0;

let modalFieldId = null;

const IGN_SPLIT_LS_KEY = `template_fields_ignored_split_v1:${templateId}`;

function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }

function startDragAutoScroll(){
  if (dragScrollRaf) return;

  const step = () => {
    if (!isRowDragging) { dragScrollRaf = 0; return; }

    const r = tableScroll.getBoundingClientRect();
    const y = lastDragClientY;

    const edge = 48;          // px: wie nah am Rand
    const maxSpeed = 22;      // px pro Frame (feinjustieren)

    let dy = 0;

    // oben
    if (y < r.top + edge) {
      const t = (r.top + edge - y) / edge;        // 0..1
      dy = -Math.ceil(maxSpeed * clamp(t, 0, 1));
    }
    // unten
    else if (y > r.bottom - edge) {
      const t = (y - (r.bottom - edge)) / edge;   // 0..1
      dy = Math.ceil(maxSpeed * clamp(t, 0, 1));
    }

    if (dy !== 0) {
      tableScroll.scrollTop += dy;
    }

    dragScrollRaf = requestAnimationFrame(step);
  };

  dragScrollRaf = requestAnimationFrame(step);
}

// Global: Mausposition während Drag tracken (auch wenn Cursor über PDF/außerhalb Table ist)
window.addEventListener('dragover', (e)=>{
  if (!isRowDragging) return;

  // notwendig, damit "dragover" weiter feuert und Drop möglich bleibt
  // (bei manchen Browsern sonst hakelig)
  e.preventDefault();

  lastDragClientY = e.clientY;
  startDragAutoScroll();
}, { passive: false });

// Wenn Drag irgendwo endet: Loop stoppen (zusätzliche Sicherheit)
window.addEventListener('drop', ()=>{
  isRowDragging = false;
  if (dragScrollRaf) cancelAnimationFrame(dragScrollRaf);
  dragScrollRaf = 0;
});

window.addEventListener('dragend', ()=>{
  isRowDragging = false;
  if (dragScrollRaf) cancelAnimationFrame(dragScrollRaf);
  dragScrollRaf = 0;
});

function loadIgnoredSplit(){
  try{
    const raw = localStorage.getItem(IGN_SPLIT_LS_KEY);
    const arr = raw ? JSON.parse(raw) : [];
    if (Array.isArray(arr)) return new Set(arr.map(x=>Number(x)).filter(n=>Number.isFinite(n) && n>0));
  }catch(e){}
  return new Set();
}
function saveIgnoredSplit(){
  try{
    localStorage.setItem(IGN_SPLIT_LS_KEY, JSON.stringify([...ignoredSplit]));
  }catch(e){}
}

let ignoredSplit = loadIgnoredSplit(); // fieldId -> ignore split-hint

// --- PDF preview state
let pdfUrl = "<?=h($pdfUrl)?>";
const pdfCanvas = document.getElementById('pdfCanvas');
const pdfHint = document.getElementById('pdfHint');
const pageInfo = document.getElementById('pageInfo');
const btnPrevPage = document.getElementById('btnPrevPage');
const btnNextPage = document.getElementById('btnNextPage');

let pdfDoc = null;
let currentPage = 1;
let currentHighlight = null;

// like templates.php
let pageWidgets = new Map();     // pageNo -> [{name, rect}]
let rowByFieldName = new Map();  // field_name -> tr (first visible)

function flashRow(tr){
  if (!tr) return;
  tr.classList.add('is-found');
  setTimeout(()=>tr.classList.remove('is-found'), 900);
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
}

function grade16Options(){
  return { options: ['1','2','3','4','5','6'].map(v => ({ value: v, label: v })) };
}

/** Convert option_list_items -> template_fields.options JSON */
function itemsToOptions(items){
  const arr = Array.isArray(items) ? items : [];
  const mapped = arr.map(it => ({
    value: String(it.value ?? ''),
    label: String(it.label ?? ''),
    icon_id: (it.icon_id !== null && it.icon_id !== undefined && it.icon_id !== '') ? Number(it.icon_id) : null
  })).filter(o => o.value !== '' && o.label !== '');
  return { options: mapped };
}

function normalizePdfType(rawType, multilineFlag){
  const t = String(rawType || '').trim().toUpperCase();
  if (t === 'TX') return multilineFlag ? 'multiline' : 'text';
  if (t === 'CH' || t === 'SELECT') return 'select';
  if (t === 'SIG' || t === 'SIGNATURE') return 'signature';
  if (t === 'BTN') return 'checkbox';
  if (t === 'CHECKBOX') return 'checkbox';
  if (t === 'RADIO') return 'radio';
  return multilineFlag ? 'multiline' : 'radio';
}

function normRect(rect){
  if (!Array.isArray(rect) || rect.length < 4) return null;
  const x1 = Number(rect[0]), y1 = Number(rect[1]), x2 = Number(rect[2]), y2 = Number(rect[3]);
  if (![x1,y1,x2,y2].every(Number.isFinite)) return null;
  return [Math.min(x1,x2), Math.min(y1,y2), Math.max(x1,x2), Math.max(y1,y2)];
}
function rectContains(rect, x, y){
  const r = normRect(rect);
  if (!r) return false;
  return x >= r[0] && x <= r[2] && y >= r[1] && y <= r[3];
}

async function readPdfFieldInfoFromDoc(doc){
  const out = new Map();
  if (!doc || typeof doc.getFieldObjects !== 'function') return { fields: out, names: new Set() };

  const fo = await doc.getFieldObjects();
  if (!fo || typeof fo !== 'object') return { fields: out, names: new Set() };

  const tmp = [];
  let hasZero = false;

  for (const [name, arr] of Object.entries(fo)) {
    if (!Array.isArray(arr)) continue;

    for (const it of arr) {
      let pRaw = it?.page;
      if (pRaw === undefined || pRaw === null) pRaw = it?.pageIndex;
      const pNum = Number(pRaw);
      if (!Number.isFinite(pNum)) continue;
      if (pNum === 0) hasZero = true;

      const rect = (Array.isArray(it?.rect) && it.rect.length >= 4) ? it.rect.slice(0, 4) : null;
      const rawType = it?.type || it?.fieldType || '';
      const multiline = !!(it?.multiline || it?.multiLine);
      const hint = (it?.alternativeText || it?.altText || it?.tooltip || it?.title || it?.fieldLabel || '')?.toString?.() || '';

      tmp.push({ name, pNum, rect, rawType, multiline, hint });
    }
  }

  const numPages = Number(doc?.numPages || 0);

  for (const t of tmp) {
    let page = t.pNum;
    if (hasZero) page = page + 1;
    if (page < 1) page = 1;
    if (numPages && page > numPages) page = numPages;

    if (!out.has(t.name)) {
      out.set(t.name, {
        page,
        rect: t.rect,
        rawType: t.rawType,
        type: normalizePdfType(t.rawType, t.multiline),
        multiline: !!t.multiline,
        hint: t.hint
      });
    }
  }

  return { fields: out, names: new Set(out.keys()) };
}

async function readPdfFieldInfo(){
  return readPdfFieldInfoFromDoc(pdfDoc);
}

async function importNewFieldsFromPdf(newNames, pdfInfo){
  if (!Array.isArray(newNames) || !newNames.length) return 0;

  const payload = newNames.map((name, idx) => {
    const info = pdfInfo.fields.get(name) || {};
    const meta = { ...info };
    return {
      name,
      type: info.type || 'radio',
      label: name,
      help_text: info.hint || '',
      multiline: info.type === 'multiline' ? true : !!info.multiline,
      sort: fields.length + idx + 1,
      meta
    };
  });

  const params = new URLSearchParams();
  params.set('csrf_token', csrf);
  params.set('template_id', String(templateId));
  params.set('fields', JSON.stringify(payload));

  const resp = await fetch(importFieldsUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      'X-CSRF-Token': csrf
    },
    body: params.toString()
  });

  const data = await resp.json().catch(() => ({}));
  if (!resp.ok || !data.ok) throw new Error(data.error || `Import fehlgeschlagen (HTTP ${resp.status})`);

  return data.imported || 0;
}

function updateMissingDeleteButton(names){
  lastMissingNames = Array.isArray(names) ? names : [];
  if (!btnDeleteMissing) return;
  if (lastMissingNames.length) {
    btnDeleteMissing.style.display = '';
    btnDeleteMissing.textContent = `Fehlende Felder löschen (${lastMissingNames.length})…`;
  } else {
    btnDeleteMissing.style.display = 'none';
  }
}

async function deleteFieldsByName(names){
  if (!Array.isArray(names) || !names.length) return 0;

  const payload = { action:'delete_by_names', template_id: templateId, names, csrf_token: csrf };
  const resp = await fetch(apiUrl, {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify(payload)
  });
  const data = await resp.json().catch(()=>({}));
  if (!resp.ok || !data.ok) throw new Error(data.error || `Löschen fehlgeschlagen (HTTP ${resp.status})`);
  return data.deleted || 0;
}

function buildMappingDialog(missing, newcomers){
  mapPdfFieldsList.innerHTML = '';
  mapPdfError.style.display = 'none';
  mapPdfError.textContent = '';
  mapPdfSummary.textContent = `Fehlende Felder: ${missing.length} · Neue PDF-Felder: ${newcomers.length}`;

  const options = [{ value:'', label:'— nicht zuordnen —' }, ...newcomers.map(n=>({ value:n, label:n }))];

  for (const m of missing){
    const wrap = document.createElement('div');
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.gap = '6px';

    const label = document.createElement('label');
    label.textContent = `Bestehendes Feld: ${m}`;
    const select = document.createElement('select');
    select.dataset.missingName = m;
    for (const opt of options){
      const o = document.createElement('option');
      o.value = opt.value;
      o.textContent = opt.label;
      select.appendChild(o);
    }

    wrap.append(label, select);
    mapPdfFieldsList.appendChild(wrap);
  }
}

function collectMappingFromDialog(){
  const selects = mapPdfFieldsList.querySelectorAll('select[data-missing-name]');
  const mapping = {};
  const chosenTargets = new Set();

  for (const sel of selects){
    const missingName = sel.dataset.missingName;
    const target = sel.value.trim();
    if (!target) continue;
    if (chosenTargets.has(target)) {
      mapPdfError.textContent = `„${target}“ wurde mehrfach ausgewählt. Bitte jede neue PDF-Feld nur einmal zuordnen.`;
      mapPdfError.style.display = 'block';
      return null;
    }
    chosenTargets.add(target);
    mapping[missingName] = target;
  }

  mapPdfError.style.display = 'none';
  mapPdfError.textContent = '';
  return mapping;
}

async function promptPdfFieldMapping(missing, newcomers){
  if (!missing?.length || !newcomers?.length) return {};

  buildMappingDialog(missing, newcomers);
  mapPdfFieldsDialog.returnValue = '';
  mapPdfFieldsDialog.showModal();

  return await new Promise((resolve)=>{
    const onClose = ()=>{
      if (mapPdfFieldsDialog.returnValue !== 'ok') { mapPdfFieldsDialog.removeEventListener('close', onClose); resolve(null); return; }
      const mapping = collectMappingFromDialog();
      if (mapping === null) { mapPdfFieldsDialog.showModal(); return; }
      mapPdfFieldsDialog.removeEventListener('close', onClose);
      resolve(mapping);
    };
    mapPdfFieldsDialog.addEventListener('close', onClose);
  });
}

async function assessNewPdfFile(file){
  const buf = await file.arrayBuffer();

  let doc = null;
  try {
    doc = await pdfjsLib.getDocument({ data: buf }).promise;
  } catch (e) {
    throw new Error('Neue PDF konnte nicht gelesen werden: ' + (e?.message || e));
  }

  try {
    const info = await readPdfFieldInfoFromDoc(doc);
    const pdfNames = new Set(info.fields.keys());
    const existingNames = new Set(fields.map(f => String(f.name)));

    const missing = [...existingNames].filter(n => !pdfNames.has(n));
    const newcomers = [...pdfNames].filter(n => !existingNames.has(n));

    return { missing, newcomers };
  } finally {
    try { doc?.destroy?.(); } catch (e) {}
  }
}

async function syncPdfPositionsWithFields(opts={}){
  const mapping = opts.mapping || {};
  const pdfInfo = await readPdfFieldInfo();

  const pdfNames = new Set(pdfInfo.fields.keys());
  const renameApplied = [];
  if (mapping && Object.keys(mapping).length) {
    for (const [oldNameRaw, newNameRaw] of Object.entries(mapping)) {
      const oldName = String(oldNameRaw || '').trim();
      const newName = String(newNameRaw || '').trim();
      if (!oldName || !newName) continue;
      if (!pdfNames.has(newName)) continue;

      const idx = fields.findIndex(f => String(f.name) === oldName);
      if (idx < 0) continue;

      const collision = fields.find(f => String(f.name) === newName && f.id !== fields[idx].id);
      if (collision) continue;

      const info = pdfInfo.fields.get(newName) || {};
      const next = { ...fields[idx], name: newName };
      next.meta = { ...(next.meta || {}), page: info.page, rect: info.rect, detectedType: info.rawType, multiline: info.multiline };
      fields[idx] = next;
      markDirty(next.id);
      renameApplied.push({ from: oldName, to: newName });
    }
  }

  const pdfNamesAfter = new Set(pdfInfo.fields.keys());
  const existingNames = new Set(fields.map(f => String(f.name)));

  const missing = [...existingNames].filter(n => !pdfNamesAfter.has(n));
  const newcomers = [...pdfNamesAfter].filter(n => !existingNames.has(n));

  let updated = 0;
  fields = fields.map(f => {
    const info = pdfInfo.fields.get(String(f.name));
    if (!info) return f;

    const next = { ...f };
    next.meta = { ...(next.meta || {}), page: info.page, rect: info.rect, detectedType: info.rawType, multiline: info.multiline };
    markDirty(next.id);
    updated++;
    return next;
  });

  renderTable();
  updateMeta();

  if (missing.length) {
    alert('Warnung: Diese Felder fehlen in der neuen PDF und könnten Daten verlieren: ' + missing.join(', '));
  }

  if (dirty.size) await save();

  let imported = 0;
  if (newcomers.length) {
    const wantImport = confirm(`Neue Felder in PDF entdeckt (${newcomers.length}). Jetzt anlegen?`);
    if (wantImport) {
      imported = await importNewFieldsFromPdf(newcomers, pdfInfo);
      await load();
    }
  }

  let deleted = 0;
  updateMissingDeleteButton(missing);
  if (missing.length && opts?.promptDeleteMissing !== false) {
    const wantDelete = confirm(`Soll(en) ${missing.length} fehlende Feld(er) dauerhaft gelöscht werden? (${missing.join(', ')})`);
    if (wantDelete) {
      deleted = await deleteFieldsByName(missing);
      await load();
      updateMissingDeleteButton([]);
    }
  }

  return { updated, missing: missing.length, missingNames: missing, added: imported, renamed: renameApplied.length, deleted };
}

function getGroupPath(f){
  const g = f?.meta?.group;
  return (g && String(g).trim()) ? String(g).trim() : '—';
}

function markDirty(id){
  dirty.add(id);
  saveHint.textContent = dirty.size ? `Ungespeicherte Änderungen: ${dirty.size}` : ' ';

  if (dirty.size) document.getElementById("dirtyWarning").style.display = "block";
  else document.getElementById("dirtyWarning").style.display = "none";
}

/* ---- Split hint ("DE | EN") ---- */
function hideSplitBanner(){
  splitCandidate = null;
  splitBanner.style.display = 'none';
  splitBannerText.textContent = '—';
}

function getRowInputsForField(fieldId){
  const id = String(fieldId);

  // Falls die Zeile gerade nicht gerendert ist (gefiltert/zugeklappt), geben wir null zurück
  const tr = tbody.querySelector(`tr[data-id="${CSS.escape(id)}"]`);
  if (!tr) return { inpDE: null, inpEN: null };

  const inpDE = tr.querySelector(`input[data-role="label_de"][data-field-id="${CSS.escape(id)}"]`);
  const inpEN = tr.querySelector(`input[data-role="label_en"][data-field-id="${CSS.escape(id)}"]`);

  return { inpDE: inpDE || null, inpEN: inpEN || null };
}

function scanForSplitCandidate(){
  // Wenn schon ein Banner aktiv ist, nicht überschreiben
  if (splitCandidate) return;

  for (let i = 0; i < fields.length; i++){
    const f = fields[i];
    const raw = String(f.label ?? '').trim();
    const en = String(f.label_en ?? '').trim();

    if (!raw.includes('|')) continue;
    if (en !== '') continue;

    // optional: nur wenn Feld aktuell sichtbar (falls du willst)
    // if (!isVisibleByFilter(f)) continue;
    
    if (ignoredSplit.has(Number(f.id))) continue;

    queueSplitCandidate(f.id, i, null, null);

    // Banner soll genau EINEN Kandidaten zeigen
    if (splitCandidate) return;
  }
}

function queueSplitCandidate(fieldId, idx, inpDE=null, inpEN=null){
    if (ignoredSplit.has(Number(fieldId))) return; // <- ignoriert bleibt ignoriert

  // Falls wir keine Inputs haben (Scan), versuche sie aus der Tabelle zu holen
  if (!inpDE || !inpEN) {
    const got = getRowInputsForField(fieldId);
    inpDE = inpDE || got.inpDE;
    inpEN = inpEN || got.inpEN;
  }

  const raw = String(inpDE?.value ?? fields[idx]?.label ?? '').trim();
  const parsed = parseDeEnSplit(raw);
  if (!parsed) {
    if (splitCandidate?.fieldId === fieldId) hideSplitBanner();
    return;
  }

  const { de, en } = parsed;

  const currentEN = String(inpEN?.value ?? fields[idx]?.label_en ?? '').trim();
  if (currentEN !== '') {
    if (splitCandidate?.fieldId === fieldId) hideSplitBanner();
    return;
  }

  if (splitCandidate && splitCandidate.fieldId !== fieldId) return;

  splitCandidate = { fieldId, idx, de, en, inpDE, inpEN };

  const total = countSplitCandidates();
  splitBannerText.innerHTML =
    `<strong>Hinweis:</strong> „DE | EN“ erkannt (${total}×) → <span class="muted2">DE: ${escapeHtml(de)} · EN: ${escapeHtml(en)}</span>`;

  splitBanner.style.display = 'flex';
}

const btnSplitAll = document.getElementById('btnSplitAll');

function parseDeEnSplit(raw){
  const s = String(raw ?? '').trim();
  if (!s.includes('|')) return null;

  const parts = s.split('|').map(x => String(x).trim()).filter(Boolean);
  if (parts.length < 2) return null;

  const de = parts[0];
  const en = parts.slice(1).join(' | ');
  if (!de || !en) return null;

  return { de, en };
}

function syncFieldLabelDom(fieldId, de, en){
  const id = String(fieldId);

  // Update im aktuell gerenderten DOM (falls sichtbar)
  const deInp = tbody.querySelector(`input[data-role="label_de"][data-field-id="${CSS.escape(id)}"]`);
  const enInp = tbody.querySelector(`input[data-role="label_en"][data-field-id="${CSS.escape(id)}"]`);

  if (deInp && deInp.value !== String(de)) deInp.value = String(de);
  if (enInp && enInp.value !== String(en)) enInp.value = String(en);
}

function applySplitToFieldByIndex(idx){
  const f = fields[idx];
  if (!f) return false;

  const parsed = parseDeEnSplit(f.label);
  if (!parsed) return false;

  // Nur splitten, wenn EN noch leer ist
  const currentEN = String(f.label_en ?? '').trim();
  if (currentEN !== '') return false;

  fields[idx].label = parsed.de;
  fields[idx].label_en = parsed.en;
  markDirty(f.id);

  // Sofort sichtbar machen (ohne renderTable)
  syncFieldLabelDom(f.id, parsed.de, parsed.en);

  return true;
}

function countSplitCandidates(){
  let n = 0;
  for (const f of fields){
    const parsed = parseDeEnSplit(f.label);
    if (!parsed) continue;
    if (String(f.label_en ?? '').trim() !== '') continue;
    n++;
  }
  return n;
}

btnSplitNow.addEventListener('click', ()=>{
  if (!splitCandidate) return;

  const { fieldId, idx, de, en } = splitCandidate;

  // Daten aktualisieren
  fields[idx].label = de;
  fields[idx].label_en = en;
  markDirty(fieldId);

  // Sofort in Tabelle sichtbar machen (auch wenn Candidate aus Scan kam)
  syncFieldLabelDom(fieldId, de, en);

  hideSplitBanner();

  // Nächsten Kandidaten direkt anbieten
  scanForSplitCandidate();

  // Optional: Meta/Status aktualisieren
  updateMeta();
});

btnSplitAll.addEventListener('click', ()=>{
  let changed = 0;

  // Wenn du NUR "sichtbare" splitten willst:
  // const list = fields.map((f,i)=>({f,i})).filter(x=>isVisibleByFilter(x.f));
  // for (const {i} of list) { if (applySplitToFieldByIndex(i)) changed++; }

  // Standard: ALLE im Template splitten
  for (let i = 0; i < fields.length; i++){
    if (applySplitToFieldByIndex(i)) changed++;
  }

  hideSplitBanner();

  // Wenn viele geändert wurden, ist ein einmaliges Render ok (damit garantiert alles konsistent ist)
  // (und weil du gerade NICHT in einem Input tippst, ist Fokusverlust egal)
  if (changed > 0) {
    // scroll position erhalten
    const st = tableScroll.scrollTop;
    renderTable();
    tableScroll.scrollTop = st;
  }

  // Neu scannen (falls noch Kandidaten übrig sind)
  scanForSplitCandidate();
  updateMeta();

  // Optional: kleine Statusmeldung
  if (changed) saveHint.textContent = `Getrennt: ${changed} Felder (noch offen: ${countSplitCandidates()})`;
});

btnSplitDismiss.addEventListener('click', ()=>{
  if (splitCandidate?.fieldId) {
    ignoredSplit.add(Number(splitCandidate.fieldId));
    saveIgnoredSplit();
  }
  hideSplitBanner();
  scanForSplitCandidate();
});

btnSplitResetIgnored.addEventListener('click', ()=>{
  ignoredSplit = new Set();
  try { localStorage.removeItem(IGN_SPLIT_LS_KEY); } catch(e) {}
  hideSplitBanner();
  scanForSplitCandidate();
  saveHint.textContent = 'Ignorierte Split-Hinweise zurückgesetzt.';
});

function isVisibleByFilter(f){
  const gpath = getGroupPath(f);
  if (hiddenGroups.has(gpath)) return false;

  if (groupFilter && gpath !== groupFilter && !gpath.startsWith(groupFilter + '/')) return false;

  const q = (filterText || '').toLowerCase().trim();
  const nq = (excludeText || '').toLowerCase().trim();

  const hay = (
    String(f.name||'').toLowerCase() + ' ' +
    String(f.label||'').toLowerCase() + ' ' +
    gpath.toLowerCase()
  );

  if (nq && hay.includes(nq)) return false;
  if (!q) return true;
  return hay.includes(q);
}

function rebuildGroupDatalist(){
  const set = new Set();
  for (const f of fields) {
    const g = getGroupPath(f);
    if (g && g !== '—') set.add(g);
  }
  const arr = [...set].sort((a,b)=>a.localeCompare(b, undefined, { sensitivity:'base' }));
  groupList.innerHTML = arr.map(g=>`<option value="${escapeHtml(g)}"></option>`).join('');
}

function updateMeta(){
  const total = fields.length;
  const visible = fields.filter(isVisibleByFilter).length;
  const sel = selected.size;
  metaLine.textContent =
    `Template #${template?.id ?? templateId} – ${template?.name ?? ''} v${template?.template_version ?? ''} · Felder: ${total} (sichtbar: ${visible}) · Auswahl: ${sel}`;
  selCount.textContent = String(sel);
}

function optionTemplateNameById(id){
  const t = optionTemplates.find(x=>String(x.id)===String(id));
  return t ? t.name : '';
}

function computeExtras(f){
  const badges = [];

  if (f.type === 'date') {
    const mode = f?.meta?.date_format_mode || 'preset';
    const fmt = mode === 'custom' ? (f?.meta?.date_format_custom || '') : (f?.meta?.date_format_preset || '');
    badges.push(`📅 ${escapeHtml(fmt || mode)}`);
  }

  if (['radio','select','grade'].includes(f.type)) {
    const tid = f?.meta?.option_list_template_id;
    if (tid) badges.push(`🧩 ${escapeHtml(optionTemplateNameById(tid) || ('Template ' + tid))}`);

    const opt = f.options;
    let count = 0;
    if (opt && typeof opt === 'object' && Array.isArray(opt.options)) count = opt.options.length;
    if (count) badges.push(`☰ ${count} Optionen`);
    else badges.push('☰ — Optionen');

    if (f.type === 'grade') badges.push('🎓 1–6 verfügbar');
  }

  if (f.type === 'multiline' || f.multiline) badges.push('↵ multiline');

  return badges.map(b=>`<span class="badge">${b}</span>`).join('') || '<span class="muted2">—</span>';
}

function scrollRowIntoView(id){
  const tr = tbody.querySelector(`tr[data-id="${CSS.escape(String(id))}"]`);
  if (!tr) return;
  const top = tr.offsetTop - 40;
  tableScroll.scrollTo({ top, behavior: 'smooth' });
}
function setFoundRow(id){
  if (lastFoundRowId) {
    const prev = tbody.querySelector(`tr[data-id="${CSS.escape(String(lastFoundRowId))}"]`);
    if (prev) prev.classList.remove('is-found');
  }
  lastFoundRowId = id;
  const tr = tbody.querySelector(`tr[data-id="${CSS.escape(String(id))}"]`);
  if (tr) tr.classList.add('is-found');
}

/* ---------- GROUPS BAR ---------- */
function renderGroupsBar(){
  const visible = fields.filter(isVisibleByFilter);
  const counts = new Map();
  for (const f of visible) {
    const g = getGroupPath(f);
    counts.set(g, (counts.get(g) ?? 0) + 1);
  }
  const groups = [...counts.keys()].sort((a,b)=>a.localeCompare(b, undefined, { sensitivity:'base' }));
  groupsBar.innerHTML = '';

  for (const g of groups) {
    const pill = document.createElement('div');
    pill.className = 'group-pill' + ((groupFilter===g) ? ' is-active' : '');
    pill.title = 'Klick = filtern, Toggle = ein/ausklappen, Alt-Klick = ausblenden';

    const tog = document.createElement('span');
    tog.className = 'toggle';
    tog.textContent = collapsedGroupHeaders.has(g) ? '+' : '–';
    tog.addEventListener('click', (e)=>{
      e.stopPropagation();
      if (collapsedGroupHeaders.has(g)) collapsedGroupHeaders.delete(g);
      else collapsedGroupHeaders.add(g);
      renderTable();
      scanForSplitCandidate();
      renderGroupsBar();
    });

    const name = document.createElement('span');
    name.textContent = g;

    const cnt = document.createElement('span');
    cnt.className = 'count';
    cnt.textContent = `(${counts.get(g)})`;

    pill.append(tog, name, cnt);

    pill.addEventListener('click', (e)=>{
      if (e.altKey) {
        if (g !== '—') hiddenGroups.add(g);
        rebuildGroupDatalist();
        renderGroupsBar();
        renderTable();
        scanForSplitCandidate();
        updateMeta();
        return;
      }
      groupFilter = (groupFilter === g) ? '' : g;
      renderGroupsBar();
      renderTable();
      scanForSplitCandidate();
      updateMeta();
    });

    groupsBar.appendChild(pill);
  }
}

/* ---------- TABLE ---------- */
function renderTable(){
  tbody.innerHTML = '';
  rowByFieldName = new Map();

  const visibleFields = fields.filter(isVisibleByFilter);
  const byGroup = new Map();
  for (const f of visibleFields) {
    const g = getGroupPath(f);
    if (!byGroup.has(g)) byGroup.set(g, []);
    byGroup.get(g).push(f);
  }

  const sortedVisible = [...visibleFields].sort((a,b)=>(a.sort_order??0)-(b.sort_order??0) || a.id-b.id);
  const seenGroup = new Set();

  for (const f of sortedVisible) {
    const g = getGroupPath(f);
    if (!seenGroup.has(g)) {
      seenGroup.add(g);

      const gr = document.createElement('tr');
        gr.className = 'group-row';

        const isCollapsed = collapsedGroupHeaders.has(g);
        const cnt = byGroup.get(g)?.length ?? 0;

        const html = `
          <div class="gwrap">
            <span class="gbtn">${isCollapsed ? '+' : '–'}</span>
            <span>${escapeHtml(g)}</span>
            <span class="gmeta">(${cnt})</span>
            <span class="gmeta">Alt-Klick = ausblenden</span>
          </div>
        `;

        // Linke, sticky Zelle: deckt ✓ + Feldname ab
        const tdLeft = document.createElement('td');
        tdLeft.className = 'group-sticky';
        tdLeft.colSpan = 3;
        tdLeft.innerHTML = html;

        // Rechte Zelle: füllt Rest (damit Row optisch über volle Breite geht)
        const tdRight = document.createElement('td');
        tdRight.colSpan = 11;
        tdRight.innerHTML = '&nbsp;'; // nur Fläche

        // Klick-Handling auf der ganzen Zeile (nicht nur links)
        gr.addEventListener('click', (e)=>{
          e.preventDefault();
          if (e.altKey) {
            if (g !== '—') hiddenGroups.add(g);
            rebuildGroupDatalist();
            renderGroupsBar();
            renderTable();
            scanForSplitCandidate();
            updateMeta();
            return;
          }
          if (collapsedGroupHeaders.has(g)) collapsedGroupHeaders.delete(g);
          else collapsedGroupHeaders.add(g);
          renderGroupsBar();
          renderTable();
          scanForSplitCandidate();
        });

        gr.append(tdLeft, tdRight);
        tbody.appendChild(gr);
    }

    if (collapsedGroupHeaders.has(g)) continue;

    const idx = fields.findIndex(x=>x.id===f.id);
    if (idx < 0) continue;

    const tr = document.createElement('tr');
    tr.dataset.id = String(f.id);

    if (!rowByFieldName.has(f.name)) rowByFieldName.set(f.name, tr);

    if (selected.has(f.id)) tr.classList.add('is-selected');
    if (lastFoundRowId === f.id) tr.classList.add('is-found');

    tr.addEventListener('dragover', (e)=>{
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    tr.addEventListener('drop', (e)=>{
      e.preventDefault();
      const srcId = Number(e.dataTransfer.getData('text/plain') || 0);
      const dstId = f.id;
      if (!srcId || srcId === dstId) return;
      reorderByDrag(srcId, dstId);
    });

    tr.addEventListener('click', async (e) => {
      const tag = (e.target?.tagName || '').toLowerCase();
      if (['input','select','textarea','button','label'].includes(tag)) return;

      const any = [...pageWidgets.entries()].find(([p, arr]) => arr.some(w => w.name === f.name));
      const targetPage = any ? Number(any[0]) : (f.meta?.page ? Number(f.meta.page) : currentPage);

      const wlist = pageWidgets.get(targetPage) || [];
      const w = wlist.find(x => x.name === f.name);

      if (w) {
        currentHighlight = { page: targetPage, rect: w.rect, name: f.name, id: f.id };
        currentPage = targetPage;
        setFoundRow(f.id);
        scrollRowIntoView(f.id);
        await renderPage();
      } else if (f.meta?.page && f.meta?.rect) {
        currentHighlight = { page: f.meta.page, rect: f.meta.rect, name: f.name, id: f.id };
        currentPage = f.meta.page;
        setFoundRow(f.id);
        scrollRowIntoView(f.id);
        await renderPage();
      } else {
        currentHighlight = null;
        pdfHint.textContent = `Keine Position für „${f.name}“ gefunden.`;
        await renderPage();
      }
    });

    const tdS = document.createElement('td');
    tdS.className = 'sticky-col-0';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.checked = selected.has(f.id);
    cb.addEventListener('click', (e)=>e.stopPropagation());
    cb.addEventListener('change', (e)=>{
      e.stopPropagation();
      if (cb.checked) selected.add(f.id); else selected.delete(f.id);
      renderTable();
      scanForSplitCandidate();
      updateMeta();
    });
    tdS.appendChild(cb);

    const tdN = document.createElement('td');
    tdN.className = 'sticky-col-1';

    const fnWrap = document.createElement('div');
    fnWrap.className = 'fieldname-cell';

    const handle = document.createElement('span');
    handle.className = 'drag-handle';
    handle.title = 'Ziehen zum Sortieren';
    handle.textContent = '⋮⋮';
    handle.draggable = true;

    // Dragstart NUR am Handle
    handle.addEventListener('dragstart', (e)=>{
        isRowDragging = true;
        lastDragClientY = e.clientY;

        e.dataTransfer.setData('text/plain', String(f.id));
        e.dataTransfer.effectAllowed = 'move';
      });
      
    handle.addEventListener('dragend', ()=>{
        isRowDragging = false;
        if (dragScrollRaf) cancelAnimationFrame(dragScrollRaf);
        dragScrollRaf = 0;
      });

    // Nicht row-click auslösen
    handle.addEventListener('click', (e)=>e.stopPropagation());
    handle.addEventListener('mousedown', (e)=>e.stopPropagation());

    const fnText = document.createElement('span');
    fnText.className = 'fieldname-text';
    fnText.textContent = f.name;

    fnWrap.append(handle, fnText);
    tdN.appendChild(fnWrap);

    const tdG = document.createElement('td');
    const inpG = document.createElement('input');
    inpG.type = 'text';
    inpG.value = (f.meta && f.meta.group) ? String(f.meta.group) : '';
    inpG.setAttribute('list','groupList');
    inpG.addEventListener('click', (e)=>e.stopPropagation());
    inpG.addEventListener('input', (e)=>{
      e.stopPropagation();
      fields[idx].meta = fields[idx].meta || {};
      const v = inpG.value.trim();
      if (v) fields[idx].meta.group = v; else delete fields[idx].meta.group;
      markDirty(f.id);
      rebuildGroupDatalist();
      renderGroupsBar();
      updateMeta();
    });
    tdG.appendChild(inpG);

    // Gruppentitel (EN) – wird in meta gespeichert und auf alle Felder derselben Gruppe angewendet
    // Wichtig: NICHT bei jedem Tastendruck renderTable() (sonst Fokusverlust).
    const tdGE = document.createElement('td');
    const inpGE = document.createElement('input');
    inpGE.dataset.role = 'group_title_en';
    inpGE.dataset.fieldId = String(f.id);
    inpGE.type = 'text';
    inpGE.placeholder = 'z. B. Language Arts';
    inpGE.value = (f.meta && f.meta.group_title_en) ? String(f.meta.group_title_en) : '';
    inpGE.addEventListener('click', (e)=>e.stopPropagation());
    inpGE.addEventListener('input', (e)=>{
      e.stopPropagation();
      fields[idx].meta = fields[idx].meta || {};
      const v = inpGE.value;
      if (String(v).trim()) fields[idx].meta.group_title_en = String(v).trim();
      else delete fields[idx].meta.group_title_en;
      markDirty(f.id);
      // absichtlich kein renderTable() hier -> Fokus bleibt
    });
    inpGE.addEventListener('blur', (e)=>{
      // Beim Verlassen einmalig auf die gesamte Gruppe übertragen (ohne Re-Render während der Eingabe)
      e.stopPropagation();
      const v = String(inpGE.value || '').trim();
      const gCur = getGroupPath(fields[idx]);
      for (let i=0; i<fields.length; i++){
        if (getGroupPath(fields[i]) !== gCur) continue;
        fields[i].meta = fields[i].meta || {};
        if (v) fields[i].meta.group_title_en = v;
        else delete fields[i].meta.group_title_en;
        markDirty(fields[i].id);
      }
      // Metazeile / Gruppenbar aktualisieren reicht
      syncGroupTitleEnDom(gCur, v);
      renderGroupsBar();
      updateMeta();
    });
    tdGE.appendChild(inpGE);

    const tdT = document.createElement('td');
    const selT = document.createElement('select');
    ['text','multiline','date','number','grade','checkbox','radio','select','signature'].forEach(t=>{
      const o=document.createElement('option'); o.value=t; o.textContent=t;
      if (t===f.type) o.selected=true;
      selT.appendChild(o);
    });
    selT.addEventListener('click',(e)=>e.stopPropagation());
    selT.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].type = selT.value;
      if (selT.value === 'multiline') fields[idx].multiline = 1;
      if (selT.value === 'grade' && (!fields[idx].options || !fields[idx].options.options)) {
        fields[idx].options = grade16Options();
        fields[idx].meta = fields[idx].meta || {};
        delete fields[idx].meta.option_list_template_id;
      }
      markDirty(f.id);
      renderTable();
      scanForSplitCandidate();
    });
    tdT.appendChild(selT);

    const tdL = document.createElement('td');
    const inpL = document.createElement('input');
    inpL.type = 'text';
    inpL.dataset.role = 'label_de';
    inpL.dataset.fieldId = String(f.id);
    inpL.value = f.label || f.name;
    inpL.addEventListener('click',(e)=>e.stopPropagation());
    inpL.addEventListener('input',(e)=>{
        ignoredSplit.delete(Number(f.id));
        saveIgnoredSplit();
      e.stopPropagation();
      fields[idx].label = inpL.value;
      markDirty(f.id);
      // nur Hinweis einblenden, KEIN confirm/blur => Fokus bleibt
      queueSplitCandidate(f.id, idx, inpL, inpLE);
    });
    tdL.appendChild(inpL);

    const tdLE = document.createElement('td');
    const inpLE = document.createElement('input');
    inpLE.type = 'text';
    inpLE.dataset.role = 'label_en';
    inpLE.dataset.fieldId = String(f.id);
    inpLE.placeholder = 'English label (optional)';
    inpLE.value = (f.label_en !== undefined && f.label_en !== null) ? String(f.label_en) : '';
    inpLE.addEventListener('click',(e)=>e.stopPropagation());
    inpLE.addEventListener('input',(e)=>{
      e.stopPropagation();
      fields[idx].label_en = inpLE.value;
      markDirty(f.id);
      // wenn EN gefüllt wurde, Hinweis ggf. schließen
      if (String(inpLE.value||'').trim() !== '' && splitCandidate?.fieldId === f.id) hideSplitBanner();
    });
    tdLE.appendChild(inpLE);

    // System binding
    const tdB = document.createElement('td');
    const selB = document.createElement('select');
    selB.innerHTML = `
      <option value="">—</option>
      <option value="student.first_name">Schüler: Vorname</option>
      <option value="student.last_name">Schüler: Nachname</option>
      <option value="student.date_of_birth">Schüler: Geburtsdatum</option>
      <option value="class.display">Klasse: Anzeige (z.B. 4a)</option>
      <option value="class.grade_level">Klasse: Stufe</option>
      <option value="class.label">Klasse: Bezeichnung</option>
      <option value="class.school_year">Schuljahr</option>
    `;
    const curBind = (f.meta && f.meta.system_binding) ? String(f.meta.system_binding) : '';
    if (curBind) {
      const opt = selB.querySelector(`option[value="${CSS.escape(curBind)}"]`);
      if (opt) opt.selected = true;
    }
    selB.addEventListener('click',(e)=>e.stopPropagation());
    selB.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].meta = fields[idx].meta || {};
      const v = selB.value;
      if (v) fields[idx].meta.system_binding = v; else delete fields[idx].meta.system_binding;
      markDirty(f.id);
    });
    tdB.appendChild(selB);

    const tdH = document.createElement('td');
    const inpH = document.createElement('input');
    inpH.type = 'text';
    inpH.value = f.help_text || '';
    inpH.addEventListener('click',(e)=>e.stopPropagation());
    inpH.addEventListener('input',(e)=>{
      e.stopPropagation();
      fields[idx].help_text = inpH.value;
      markDirty(f.id);
    });
    tdH.appendChild(inpH);

    const tdC = document.createElement('td');
    const cbC = document.createElement('input');
    cbC.type = 'checkbox';
    cbC.checked = f.can_child_edit === 1;
    cbC.addEventListener('click',(e)=>e.stopPropagation());
    cbC.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].can_child_edit = cbC.checked ? 1 : 0;
      markDirty(f.id);
      updateMeta();
    });
    tdC.appendChild(cbC);

    const tdTe = document.createElement('td');
    const cbTe = document.createElement('input');
    cbTe.type = 'checkbox';
    cbTe.checked = f.can_teacher_edit === 1;
    cbTe.addEventListener('click',(e)=>e.stopPropagation());
    cbTe.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].can_teacher_edit = cbTe.checked ? 1 : 0;
      markDirty(f.id);
      updateMeta();
    });
    tdTe.appendChild(cbTe);

    const tdK = document.createElement('td');
    const cbK = document.createElement('input');
    cbK.type = 'checkbox';
    cbK.checked = (f.meta && String(f.meta.scope||'').toLowerCase()==='class') || (f.meta && Number(f.meta.is_class_field||0)===1);
    cbK.addEventListener('click',(e)=>e.stopPropagation());
    cbK.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].meta = fields[idx].meta || {};
      if (cbK.checked) {
        fields[idx].meta.scope = 'class';
        fields[idx].can_teacher_edit = 1;
        fields[idx].can_child_edit = 0;
      } else {
        if (fields[idx].meta) {
          delete fields[idx].meta.scope;
          delete fields[idx].meta.is_class_field;
          if (Object.keys(fields[idx].meta).length === 0) fields[idx].meta = null;
        }
      }
      markDirty(f.id);
      renderTable();
      scanForSplitCandidate();
      updateMeta();
    });
    tdK.appendChild(cbK);

    const tdR = document.createElement('td');
    const cbR = document.createElement('input');
    cbR.type = 'checkbox';
    cbR.checked = f.required === 1;
    cbR.addEventListener('click',(e)=>e.stopPropagation());
    cbR.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].required = cbR.checked ? 1 : 0;
      markDirty(f.id);
    });
    tdR.appendChild(cbR);

    const tdX = document.createElement('td');
    const wrap = document.createElement('div');
    wrap.className = 'extras';

    if (f.type === 'date') {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn secondary';
      btn.textContent = 'Datumsformat…';
      btn.addEventListener('click', (e)=>{ e.stopPropagation(); openDateModal(f.id); });
      wrap.appendChild(btn);
    }

    if (['radio','select','grade'].includes(f.type)) {
      const tplSel = document.createElement('select');
      tplSel.style.maxWidth = '320px';
      tplSel.innerHTML =
        `<option value="">Vorlage wählen…</option>` +
        optionTemplates.map(t=>`<option value="${escapeHtml(t.id)}">${escapeHtml(t.name)}</option>`).join('');
      const curTid = f?.meta?.option_list_template_id ? String(f.meta.option_list_template_id) : '';
      if (curTid) {
        const opt = tplSel.querySelector(`option[value="${CSS.escape(curTid)}"]`);
        if (opt) opt.selected = true;
      }
      tplSel.addEventListener('click',(e)=>e.stopPropagation());
      tplSel.addEventListener('change', async (e)=>{
        e.stopPropagation();
        const tid = tplSel.value;
        if (!tid) {
          fields[idx].meta = fields[idx].meta || {};
          delete fields[idx].meta.option_list_template_id;
          markDirty(f.id);
          renderTable();
          scanForSplitCandidate();
          return;
        }
        try {
          await applyOptionTemplateToField(f.id, tid);
        } catch(err) {
          alert(err?.message || err);
        }
      });
      wrap.appendChild(tplSel);

      const btnOpt = document.createElement('button');
      btnOpt.type = 'button';
      btnOpt.className = 'btn secondary';
      btnOpt.textContent = 'Optionen…';
      btnOpt.addEventListener('click', (e)=>{ e.stopPropagation(); openOptionsModal(f.id); });
      wrap.appendChild(btnOpt);

      if (f.type === 'grade') {
        const btnG = document.createElement('button');
        btnG.type = 'button';
        btnG.className = 'btn secondary';
        btnG.textContent = 'Noten 1–6';
        btnG.title = 'Setzt Standard-Notenskala (1–6).';
        btnG.addEventListener('click', (e)=>{ e.stopPropagation(); setGrade16(f.id); });
        wrap.appendChild(btnG);
      }
    }

    const badges = document.createElement('div');
    badges.innerHTML = computeExtras(f);
    wrap.appendChild(badges);

    tdX.appendChild(wrap);

    // ✓ | Feldname | Gruppe | Gruppentitel EN | Typ | Label | Label EN | Stammfeld | Help | Kind | Lehrer | Klassenfeld | Req | Extras
    tr.append(tdS, tdN, tdG, tdGE, tdT, tdL, tdLE, tdB, tdH, tdC, tdTe, tdK, tdR, tdX);
    tbody.appendChild(tr);
  }
}

function syncGroupTitleEnDom(groupPath, value){
  const v = String(value || '');
  const inputs = tbody.querySelectorAll('input[data-role="group_title_en"][data-field-id]');
  for (const el of inputs) {
    const fid = Number(el.dataset.fieldId || 0);
    if (!fid) continue;
    const ff = fields.find(x => x.id === fid);
    if (!ff) continue;
    if (getGroupPath(ff) !== groupPath) continue;
    if (el.value !== v) el.value = v;
  }
}

function reorderByDrag(srcId, dstId){
  const srcIdx = fields.findIndex(f=>f.id===srcId);
  const dstIdx = fields.findIndex(f=>f.id===dstId);
  if (srcIdx < 0 || dstIdx < 0) return;

  const item = fields[srcIdx];
  fields.splice(srcIdx, 1);
  const insertAt = srcIdx < dstIdx ? dstIdx - 1 : dstIdx;
  fields.splice(insertAt, 0, item);

  fields.forEach((f,i)=>{
    const newSort = i + 1;
    if ((f.sort_order ?? 0) !== newSort) {
      f.sort_order = newSort;
      markDirty(f.id);
    }
  });
  renderTable();
  scanForSplitCandidate();
  updateMeta();
}

/* ---------- API helpers ---------- */
async function apiPost(payload){
  const resp = await fetch(apiUrl, {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify({ csrf_token: csrf, template_id: templateId, ...payload })
  });
  const j = await resp.json().catch(()=>({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || 'API Fehler');
  return j;
}

async function optionListsPost(payload){
  const resp = await fetch(optionListsApiUrl, {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify({ csrf_token: csrf, ...payload })
  });
  const j = await resp.json().catch(()=>({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || 'Option-API Fehler');
  return j;
}

async function loadOptionTemplates(){
  const j = await optionListsPost({ action: 'list_templates' });
  optionTemplates = j.templates || [];

  bulkTpl.innerHTML = `<option value="">— keine —</option>` + optionTemplates.map(t=>{
    return `<option value="${t.id}">${escapeHtml(t.name)}</option>`;
  }).join('');

  optTpl.innerHTML = `<option value="">—</option>` + optionTemplates.map(t=>{
    return `<option value="${t.id}">${escapeHtml(t.name)}</option>`;
  }).join('');
}

async function fetchOptionTemplate(listId){
  const j = await optionListsPost({ action:'get_template', list_id: listId });
  return { template: j.template, items: j.items };
}

async function applyOptionTemplateToField(fieldId, listId){
  const { template: tpl, items } = await fetchOptionTemplate(listId);
  if (!tpl) throw new Error('Vorlage nicht gefunden.');

  const idx = fields.findIndex(x=>x.id===fieldId);
  if (idx < 0) return;

  fields[idx].options = itemsToOptions(items);
  fields[idx].meta = fields[idx].meta || {};
  fields[idx].meta.option_list_template_id = String(tpl.id);

  markDirty(fieldId);
  renderTable();
  scanForSplitCandidate();
  updateMeta();
}

/* ---------- Bulk ---------- */
function buildBulkPatch(){
  const patch = {};
  const g = bulkGroup.value.trim();
  patch.meta_merge = {};
  if (g) patch.meta_merge.group = g;

  if (bulkType.value) patch.type = bulkType.value;

  if (bulkChild.value !== '') patch.can_child_edit = Number(bulkChild.value);
  if (bulkTeacher.value !== '') patch.can_teacher_edit = Number(bulkTeacher.value);
  if (bulkRequired.value !== '') patch.required = Number(bulkRequired.value);

  if (bulkDateMode.value) {
    patch.meta_merge.date_format_mode = bulkDateMode.value;
    if (bulkDateMode.value === 'preset') {
      patch.meta_merge.date_format_preset = bulkDatePreset.value || 'MM/DD/YYYY';
      patch.meta_merge.date_format_custom = null;
    } else {
      patch.meta_merge.date_format_custom = bulkDateCustom.value.trim() || 'MM/DD/YYYY';
      patch.meta_merge.date_format_preset = null;
    }
  }
  return patch;
}

function getSelectedIds(){ return Array.from(selected); }
function getVisibleIds(){ return fields.filter(isVisibleByFilter).map(f=>f.id); }

async function applyBulk(targetIds){
  if (!targetIds.length) return;
  const patch = buildBulkPatch();

  let tplPack = null;
  if (bulkTpl.value) tplPack = await fetchOptionTemplate(bulkTpl.value);

  const idsSet = new Set(targetIds.map(Number));
  fields = fields.map(f=>{
    if (!idsSet.has(f.id)) return f;
    const nf = structuredClone(f);

    if (patch.type) {
      nf.type = patch.type;
      if (nf.type === 'multiline') nf.multiline = 1;
      if (nf.type === 'grade' && (!nf.options || !nf.options.options)) {
        nf.options = grade16Options();
        nf.meta = nf.meta || {};
        delete nf.meta.option_list_template_id;
      }
    }

    if (patch.hasOwnProperty('can_child_edit')) nf.can_child_edit = patch.can_child_edit;
    if (patch.hasOwnProperty('can_teacher_edit')) nf.can_teacher_edit = patch.can_teacher_edit;
    if (patch.hasOwnProperty('required')) nf.required = patch.required;

    nf.meta = nf.meta || {};
    if (patch.meta_merge) {
      for (const [k,v] of Object.entries(patch.meta_merge)) {
        if (k === 'group' && (v === null || v === '')) { delete nf.meta.group; continue; }
        if (v === null) { delete nf.meta[k]; continue; }
        nf.meta[k] = v;
      }
    }

    if (tplPack && ['radio','select','grade'].includes(nf.type)) {
      nf.options = itemsToOptions(tplPack.items);
      nf.meta.option_list_template_id = String(tplPack.template?.id ?? bulkTpl.value);
    }

    markDirty(nf.id);
    return nf;
  });

  rebuildGroupDatalist();
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
  bulkGroup.value = '';
}

/* ---------- Auto group ---------- */
function extractPrefix(nameRaw){
  let name = String(nameRaw || '').trim();
  if (!name) return '';

  if (name.includes('-')) name = name.split('-')[0];

  let m = name.match(/^([A-Za-z]+)\d+$/);
  if (m) return m[1];

  m = name.match(/^([A-Za-z]+)[._-]\d+$/);
  if (m) return m[1];

  m = name.match(/^([A-Za-z]+)[._-]/);
  if (m) return m[1];

  m = name.match(/^([A-Za-z]+)$/);
  if (m) return m[1];

  return '';
}

function autoGroupPrefix(ids){
  const idsSet = new Set(ids.map(Number));
  fields = fields.map(f=>{
    if (!idsSet.has(f.id)) return f;
    const nf = structuredClone(f);
    const prefix = extractPrefix(nf.name);
    if (prefix) {
      nf.meta = nf.meta || {};
      nf.meta.group = prefix;
      markDirty(nf.id);
    }
    return nf;
  });
  rebuildGroupDatalist();
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
}

function autoGroupPage(ids){
  const idsSet = new Set(ids.map(Number));
  fields = fields.map(f=>{
    if (!idsSet.has(f.id)) return f;
    const nf = structuredClone(f);
    const p = nf.meta?.page;
    if (p) {
      nf.meta = nf.meta || {};
      nf.meta.group = `Seite ${p}`;
      markDirty(nf.id);
    }
    return nf;
  });
  rebuildGroupDatalist();
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
}

/* ---------- Save ---------- */
async function save(){
  if (!dirty.size) { saveHint.textContent = 'Nichts zu speichern.'; return; }

  const updates = fields
    .filter(f => dirty.has(f.id))
    .map(f => ({
      id: f.id,
      name: f.name,
      type: f.type,
      label: f.label,
      label_en: (f.label_en ?? ''),
      help_text: f.help_text,
      multiline: f.multiline,
      required: f.required,
      can_child_edit: f.can_child_edit,
      can_teacher_edit: f.can_teacher_edit,
      sort_order: f.sort_order ?? 0,
      options: f.options ?? null,
      meta: f.meta ?? null
    }));

  const j = await apiPost({ action:'save', updates });
  dirty.clear();
  saveHint.textContent = `Gespeichert: ${j.saved}`;
  updateMeta();

  if (dirty.size) document.getElementById("dirtyWarning").style.display = "block";
  else document.getElementById("dirtyWarning").style.display = "none";
}

/* ---------- Options dialog ---------- */
function setGrade16(fieldId){
  const idx = fields.findIndex(x => x.id === fieldId);
  if (idx < 0) return;
  fields[idx].options = grade16Options();
  fields[idx].meta = fields[idx].meta || {};
  delete fields[idx].meta.option_list_template_id;
  markDirty(fieldId);
  renderTable();
  scanForSplitCandidate();
  updateMeta();
}

function openOptionsModal(fieldId){
  modalFieldId = fieldId;
  const f = fields.find(x=>x.id===fieldId);
  if (!f) return;
  optSubtitle.textContent = f.name;
  optTpl.value = (f?.meta?.option_list_template_id ? String(f.meta.option_list_template_id) : '');
  optJson.value = (f.options != null) ? JSON.stringify(f.options, null, 2) : '';
  optionsModal.showModal();
}

btnGrade16.addEventListener('click', ()=>{
  optJson.value = JSON.stringify(grade16Options(), null, 2);
  optTpl.value = '';
});
btnClearOptions.addEventListener('click', ()=>{ optJson.value = ''; optTpl.value=''; });

optTpl.addEventListener('change', async ()=>{
  if (!optTpl.value) return;
  try {
    const { items } = await fetchOptionTemplate(optTpl.value);
    optJson.value = JSON.stringify(itemsToOptions(items), null, 2);
  } catch(e) {
    alert(e?.message || e);
  }
});

optionsModal.addEventListener('close', ()=>{
  if (optionsModal.returnValue !== 'ok') { modalFieldId = null; return; }
  const fieldId = modalFieldId;
  modalFieldId = null;
  const idx = fields.findIndex(x=>x.id===fieldId);
  if (idx < 0) return;

  let parsed = null;
  const raw = optJson.value.trim();
  if (raw) {
    try { parsed = JSON.parse(raw); }
    catch(e) { alert('options_json ist kein gültiges JSON.'); return; }
  }

  fields[idx].options = parsed;
  fields[idx].meta = fields[idx].meta || {};
  if (optTpl.value) fields[idx].meta.option_list_template_id = optTpl.value;
  else delete fields[idx].meta.option_list_template_id;

  markDirty(fieldId);
  renderTable();
  scanForSplitCandidate();
});

/* ---------- Date dialog ---------- */
function openDateModal(fieldId){
  modalFieldId = fieldId;
  const f = fields.find(x=>x.id===fieldId);
  if (!f) return;
  dateSubtitle.textContent = f.name;
  const mode = f?.meta?.date_format_mode || 'preset';
  dateMode.value = mode;
  datePreset.value = f?.meta?.date_format_preset || 'MM/DD/YYYY';
  dateCustom.value = f?.meta?.date_format_custom || '';
  dateModal.showModal();
}

dateModal.addEventListener('close', ()=>{
  if (dateModal.returnValue !== 'ok') { modalFieldId = null; return; }
  const fieldId = modalFieldId;
  modalFieldId = null;
  const idx = fields.findIndex(x=>x.id===fieldId);
  if (idx < 0) return;

  fields[idx].meta = fields[idx].meta || {};
  fields[idx].meta.date_format_mode = dateMode.value || 'preset';
  if ((fields[idx].meta.date_format_mode || 'preset') === 'custom') {
    fields[idx].meta.date_format_custom = dateCustom.value.trim() || 'MM/DD/YYYY';
    delete fields[idx].meta.date_format_preset;
  } else {
    fields[idx].meta.date_format_preset = datePreset.value || 'MM/DD/YYYY';
    delete fields[idx].meta.date_format_custom;
  }
  markDirty(fieldId);
  renderTable();
  scanForSplitCandidate();
});

/* ---------- Preview resizer ---------- */
const LS_KEY = 'template_fields_preview_ratio_v2';

function applyPreviewRatio(ratio){
  const r = clamp(Number(ratio)||0.45, 0.22, 0.70);
  layout2.style.gridTemplateColumns = `${(1-r).toFixed(4)}fr 10px ${r.toFixed(4)}fr`;
  try { localStorage.setItem(LS_KEY, String(r)); } catch(e) {}
  if (!layout2.classList.contains('hide-preview')) setTimeout(()=>renderPage(), 60);
}

(function initResizer(){
  try {
    const saved = localStorage.getItem(LS_KEY);
    if (saved) applyPreviewRatio(Number(saved));
  } catch(e) {}

  let dragging = false;

  const onMove = (ev)=>{
    if (!dragging) return;
    const rect = layout2.getBoundingClientRect();
    const x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - rect.left;
    const total = rect.width;
    const previewWidth = total - x;
    const ratio = previewWidth / total;
    applyPreviewRatio(ratio);
  };

  const onUp = ()=>{
    if (!dragging) return;
    dragging = false;
    colResizer.classList.remove('dragging');
    window.removeEventListener('mousemove', onMove);
    window.removeEventListener('mouseup', onUp);
    window.removeEventListener('touchmove', onMove);
    window.removeEventListener('touchend', onUp);
  };

  const onDown = (ev)=>{
    if (layout2.classList.contains('hide-preview')) return;
    dragging = true;
    colResizer.classList.add('dragging');
    window.addEventListener('mousemove', onMove, { passive:false });
    window.addEventListener('mouseup', onUp);
    window.addEventListener('touchmove', onMove, { passive:false });
    window.addEventListener('touchend', onUp);
    ev.preventDefault();
  };

  colResizer.addEventListener('mousedown', onDown);
  colResizer.addEventListener('touchstart', onDown, { passive:false });
})();

/* ---------- UI wiring ---------- */
fieldFilter.addEventListener('input', ()=>{
  filterText = fieldFilter.value || '';
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
});

fieldExclude.addEventListener('input', ()=>{
  excludeText = fieldExclude.value || '';
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
});
btnClearFilter.addEventListener('click', ()=>{
  fieldFilter.value='';
  filterText='';
  if (fieldExclude) fieldExclude.value='';
  excludeText='';
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
});

btnApplySelected.addEventListener('click', ()=>applyBulk(getSelectedIds()));
btnApplyVisible.addEventListener('click', ()=>applyBulk(getVisibleIds()));
btnAutoGroupPrefix.addEventListener('click', ()=>autoGroupPrefix(getSelectedIds().length ? getSelectedIds() : getVisibleIds()));
btnAutoGroupPage.addEventListener('click', ()=>autoGroupPage(getSelectedIds().length ? getSelectedIds() : getVisibleIds()));
btnSave.addEventListener('click', save);
btnSaveTop.addEventListener('click', save);

btnReplacePdf.addEventListener('click', async ()=>{
  const file = replacePdfInput?.files?.[0] ?? null;
  if (!file) { replacePdfStatus.textContent = 'Bitte neue PDF auswählen.'; return; }

  btnReplacePdf.disabled = true;
  replacePdfStatus.textContent = 'Prüfe Felder in neuer PDF…';

  try {
    const assessment = await assessNewPdfFile(file);
    let plannedMapping = {};
    if (assessment?.missing?.length) {
      const msg = `Warnung: ${assessment.missing.length} vorhandene Felder fehlen in der neuen PDF (${assessment.missing.join(', ')}) und Daten könnten verloren gehen. PDF trotzdem ersetzen?`;
      const proceed = confirm(msg);
      if (!proceed) { replacePdfStatus.textContent = 'Abgebrochen: PDF wurde nicht ersetzt.'; return; }
    }

    if (assessment?.missing?.length && assessment?.newcomers?.length) {
      const mapping = await promptPdfFieldMapping(assessment.missing, assessment.newcomers);
      if (mapping === null) { replacePdfStatus.textContent = 'Abgebrochen: Zuordnung abgebrochen.'; return; }
      plannedMapping = mapping || {};
    }

    replacePdfStatus.textContent = 'Lade PDF hoch…';

    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('template_id', String(templateId));
    fd.append('pdf', file);

    const resp = await fetch(replacePdfUrl, { method:'POST', body: fd });
    const data = await resp.json().catch(()=>({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || `Upload fehlgeschlagen (HTTP ${resp.status})`);

    pdfUrl = String(data.pdf_url || pdfUrl) + '&cache=' + Date.now();
    replacePdfStatus.textContent = 'PDF gespeichert. Aktualisiere Vorschau und Felder…';

    await loadPdf();
    const summary = await syncPdfPositionsWithFields({ mapping: plannedMapping });
    const parts = [
      `Positionen aktualisiert (${summary.updated})`,
      `fehlend: ${summary.missing}`,
      `neu: ${summary.added}`
    ];
    if (summary.renamed) parts.push(`zugeordnet: ${summary.renamed}`);
    if (summary.deleted) parts.push(`gelöscht: ${summary.deleted}`);
    replacePdfStatus.textContent = parts.join(', ');
  } catch (e) {
    replacePdfStatus.textContent = 'Fehler: ' + (e?.message || e);
    } finally {
      btnReplacePdf.disabled = false;
      if (replacePdfInput) replacePdfInput.value = '';
    }
});

btnDeleteMissing.addEventListener('click', async ()=>{
  if (!lastMissingNames.length) { replacePdfStatus.textContent = 'Keine fehlenden Felder erkannt.'; return; }
  const want = confirm(`Fehlende Felder jetzt löschen? (${lastMissingNames.join(', ')})`);
  if (!want) return;
  try {
    const deleted = await deleteFieldsByName(lastMissingNames);
    replacePdfStatus.textContent = `Fehlende Felder gelöscht (${deleted}).`;
    updateMissingDeleteButton([]);
    await load();
  } catch (e) {
    replacePdfStatus.textContent = 'Fehler beim Löschen: ' + (e?.message || e);
  }
});

btnShowAllGroups.addEventListener('click', ()=>{
  hiddenGroups = new Set();
  groupFilter = '';
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
});
btnClearGroupFilter.addEventListener('click', ()=>{
  groupFilter = '';
  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();
});

btnTogglePreview.addEventListener('click', ()=>{
  const hidden = layout2.classList.toggle('hide-preview');
  previewCard.style.display = hidden ? 'none' : '';
  colResizer.style.display = hidden ? 'none' : '';
  btnTogglePreview.textContent = hidden ? 'Vorschau einblenden' : 'Vorschau ausblenden';
  if (!hidden) setTimeout(()=>renderPage(), 80);
});

/* ---------- Load ---------- */
async function load(){
  const resp = await fetch(apiUrl + "?action=list&template_id=" + encodeURIComponent(templateId), { headers:{ 'X-CSRF-Token': csrf }});
  const j = await resp.json().catch(()=>({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || 'Konnte Felder nicht laden.');

  template = j.template;
  fields = j.fields || [];

  fields = fields.map((f, i)=>({
    ...f,
    label: (f.label && String(f.label).trim()) ? f.label : f.name,
    label_en: (f.label_en ?? ''),
    can_teacher_edit: (typeof f.can_teacher_edit === 'number') ? f.can_teacher_edit : 1
  }));

  fields.forEach((f,i)=>{
    if (!f.sort_order || Number(f.sort_order) <= 0) f.sort_order = i + 1;
  });

  selected = new Set();
  dirty = new Set();
  rebuildGroupDatalist();

  await loadOptionTemplates();

  renderGroupsBar();
  renderTable();
  updateMeta();
  scanForSplitCandidate();
  await loadPdf();
}

/* ---------- PDF widgets like templates.php ---------- */
async function buildPageWidgetsFromPdf(){
  pageWidgets = new Map();
  if (!pdfDoc || typeof pdfDoc.getFieldObjects !== 'function') return;

  const fo = await pdfDoc.getFieldObjects();
  if (!fo || typeof fo !== 'object') return;

  const tmp = [];
  let hasZero = false;

  for (const [name, arr] of Object.entries(fo)) {
    if (!Array.isArray(arr)) continue;

    for (const it of arr) {
      let pRaw = it?.page;
      if (pRaw === undefined || pRaw === null) pRaw = it?.pageIndex;

      const pNum = Number(pRaw);
      const r = it?.rect;

      if (!Number.isFinite(pNum)) continue;
      if (!Array.isArray(r) || r.length < 4) continue;

      if (pNum === 0) hasZero = true;

      tmp.push({ name, pNum, rect: r });
    }
  }

  const numPages = Number(pdfDoc?.numPages || 0);

  for (const t of tmp) {
    let p = t.pNum;
    if (hasZero) p = p + 1;

    if (p < 1) p = 1;
    if (numPages && p > numPages) p = numPages;

    if (!pageWidgets.has(p)) pageWidgets.set(p, []);
    pageWidgets.get(p).push({ name: t.name, rect: t.rect });
  }
}

/* ---------- PDF ---------- */
async function loadPdf(){
  pdfDoc = await pdfjsLib.getDocument({ url: pdfUrl, withCredentials:true }).promise;
  await buildPageWidgetsFromPdf();
  currentPage = 1;
  await renderPage();
}

async function renderPage(){
  if (!pdfDoc || layout2.classList.contains('hide-preview')) return;

  const page = await pdfDoc.getPage(currentPage);
  const viewport = page.getViewport({ scale: 1.2 });
  const ctx = pdfCanvas.getContext('2d');

  pdfCanvas.width = Math.floor(viewport.width);
  pdfCanvas.height = Math.floor(viewport.height);

  await page.render({ canvasContext: ctx, viewport }).promise;

  const widgets = pageWidgets.get(currentPage) || [];
  if (widgets.length) {
    ctx.save();
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(0,150,255,0.55)';
    ctx.fillStyle = 'rgba(0,150,255,0.12)';

    for (const w of widgets) {
      const [x1,y1,x2,y2] = w.rect;
      const p1 = viewport.convertToViewportPoint(x1,y1);
      const p2 = viewport.convertToViewportPoint(x2,y2);
      const rx = Math.min(p1[0],p2[0]);
      const ry = Math.min(p1[1],p2[1]);
      const rw = Math.abs(p2[0]-p1[0]);
      const rh = Math.abs(p2[1]-p1[1]);
      ctx.fillRect(rx, ry, rw, rh);
      ctx.strokeRect(rx, ry, rw, rh);
    }
    ctx.restore();
  }

  if (currentHighlight && currentHighlight.page === currentPage && currentHighlight.rect) {
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
    ctx.fillRect(rx, ry, rw, rh);
    ctx.strokeRect(rx, ry, rw, rh);
    ctx.restore();

    pdfHint.textContent = `Markiert: ${currentHighlight.name}`;
  } else {
    pdfHint.textContent = 'Klicke links ein Feld, um es im PDF zu markieren.';
  }

  pageInfo.textContent = `Seite ${currentPage} / ${pdfDoc.numPages}`;
  btnPrevPage.disabled = currentPage <= 1;
  btnNextPage.disabled = currentPage >= pdfDoc.numPages;
}

btnPrevPage.addEventListener('click', async ()=>{ if (currentPage>1){ currentPage--; await renderPage(); }});
btnNextPage.addEventListener('click', async ()=>{ if (pdfDoc && currentPage<pdfDoc.numPages){ currentPage++; await renderPage(); }});

pdfCanvas.addEventListener('click', async (ev) => {
  if (!pdfDoc) return;

  const rect = pdfCanvas.getBoundingClientRect();
  const sx = pdfCanvas.width / rect.width;
  const sy = pdfCanvas.height / rect.height;

  const cx = (ev.clientX - rect.left) * sx;
  const cy = (ev.clientY - rect.top) * sy;

  const page = await pdfDoc.getPage(currentPage);
  const viewport = page.getViewport({ scale: 1.2 });
  const [pdfX, pdfY] = viewport.convertToPdfPoint(cx, cy);

  const widgets = pageWidgets.get(currentPage) || [];
  const hits = [];
  for (const w of widgets) {
    if (rectContains(w.rect, pdfX, pdfY)) {
      const r = normRect(w.rect);
      const mx = (r[0]+r[2]) / 2;
      const my = (r[1]+r[3]) / 2;
      const d2 = (mx-pdfX)*(mx-pdfX) + (my-pdfY)*(my-pdfY);
      hits.push({ w, d2 });
    }
  }
  hits.sort((a,b)=>a.d2-b.d2);

  if (!hits.length) {
    pdfHint.textContent = 'Kein Feld an dieser Stelle gefunden.';
    return;
  }

  const hit = hits[0].w;

  currentHighlight = { page: currentPage, rect: hit.rect, name: hit.name };
  await renderPage();

  const field = fields.find(f=>String(f.name) === String(hit.name));
  if (field) {
    const g = getGroupPath(field);
    hiddenGroups.delete(g);
    collapsedGroupHeaders.delete(g);
  }

  renderGroupsBar();
  renderTable();
  scanForSplitCandidate();
  updateMeta();

  let tr = rowByFieldName.get(hit.name);

  if (!tr && field) {
    groupFilter = '';
    const q = (filterText || '').toLowerCase().trim();
    const ok = !q || String(field.name||'').toLowerCase().includes(q) || String(field.label||'').toLowerCase().includes(q) || getGroupPath(field).toLowerCase().includes(q);
    if (!ok) {
      fieldFilter.value = '';
      filterText = '';
    }
    renderGroupsBar();
    renderTable();
    scanForSplitCandidate();
    updateMeta();
    tr = rowByFieldName.get(hit.name);
  }

  if (tr) {
    tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    flashRow(tr);
    const id = Number(tr.dataset.id || 0);
    if (id) setFoundRow(id);
  } else {
    pdfHint.textContent = `Feld „${hit.name}“ gefunden, aber Zeile ist aktuell nicht sichtbar.`;
  }
});

load().catch(err=>{ metaLine.textContent = 'Fehler: ' + (err?.message || err); });
</script>

<?php render_admin_footer(); ?>
