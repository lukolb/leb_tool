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
  .wiz-preview { position: sticky; top: 18px; align-self: start; }

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
    min-width: 1750px;
    border-collapse: separate;
    border-spacing: 0;
  }
  #fieldsTbl th, #fieldsTbl td{
    vertical-align: top;
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
  .sticky-col-0{ position: sticky; left: 0; z-index: 8; background: var(--card, #fff); }
  .sticky-col-1{ position: sticky; left: 46px; z-index: 8; background: var(--card, #fff); }
  #fieldsTbl thead .sticky-col-0, #fieldsTbl thead .sticky-col-1{ z-index: 10; }

  /* group header rows inside table */
  tr.group-row td{
    background: rgba(0,0,0,0.035);
    font-weight: 700;
  }
  tr.group-row td .gwrap{
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  }
  tr.group-row td .gbtn{
    width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center;
    border:1px solid var(--border); border-radius:8px; user-select:none;
  }
  tr.group-row td .gmeta{ font-weight:400; color: var(--muted); font-size: 12px; }

  .toolbar{
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
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
</style>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">← Templates</a>
    <a class="btn secondary" href="<?=h(url('admin/icon_library.php'))?>">Icon Library</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<div id="dirtyWarning" class="alert danger" style="display: none">
    <p><b>Achtung! Ungespeicherte Änderungen!</b></p>
</div>

<div class="card" id="metaCard">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Feld-Editor</h2>
      <div class="muted" id="metaLine">Lade…</div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <button class="btn secondary" type="button" id="btnTogglePreview">Vorschau ausblenden</button>
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

<!-- TOP GROUPS OVERVIEW -->
<div class="card panel">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
    <div>
      <h3 style="margin:0;">Gruppenübersicht</h3>
      <div class="muted2">Klick = filtern, Toggle = Gruppe in Tabelle ein/ausklappen. Alt-Klick = Gruppe ausblenden.</div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <button class="btn secondary" type="button" id="btnClearGroupFilter">Gruppenfilter löschen</button>
      <button class="btn secondary" type="button" id="btnShowAllGroups">Alle Gruppen einblenden</button>
    </div>
  </div>
  <div class="groups-bar" id="groupsBar" style="margin-top:10px;"></div>
</div>

<div class="layout2" id="layout2">
  <!-- TABLE -->
  <div class="card" style="overflow:hidden;" id="tableCard">
    <div class="grid" style="grid-template-columns: 1fr 200px; gap:12px; align-items:end;">
      <div>
        <label>Filter (Feldname/Label/Gruppe)</label>
        <input id="fieldFilter" placeholder="z.B. soc, work, eng, math …">
        <div style="margin-top:8px;">
          <label class="muted2" style="display:block; margin-bottom:4px;">Nicht enthält</label>
          <input id="fieldExclude" placeholder="z.B. -T">
        </div>
        <div class="muted2">Filter wirkt auf Bulk-Aktionen (sichtbare Zeilen) und Gruppenübersicht.</div>
      </div>
      <div class="actions" style="justify-content:flex-start;">
        <button class="btn secondary" type="button" id="btnClearFilter">Filter löschen</button>
      </div>
    </div>

    <!-- BULK TOOLBAR -->
    <div class="toolbar" style="margin-top:12px;">
      <div class="pill"><strong>Auswahl:</strong> <span id="selCount">0</span></div>

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
        <button class="btn secondary" type="button" id="btnApplySelected">Auf Auswahl anwenden</button>
        <button class="btn secondary" type="button" id="btnApplyVisible">Auf sichtbare anwenden</button>
        <button class="btn primary" type="button" id="btnSave">Speichern</button>
      </div>

      <div class="block" style="min-width:280px;">
        <label>Auto-Group</label>
        <div style="display:flex; gap:8px;">
          <button class="btn secondary" type="button" id="btnAutoGroupPrefix">Nach Prefix</button>
          <button class="btn secondary" type="button" id="btnAutoGroupPage">Nach PDF-Seite</button>
        </div>
        <div class="muted2">Prefix ignoriert alles nach <code>-</code> (z.B. <code>mu-grade</code> → <code>mu</code>).</div>
      </div>

      <div class="muted2" id="saveHint" style="min-width:220px;">&nbsp;</div>
    </div>

    <div class="table-scroll" id="tableScroll" style="margin-top:12px;">
      <table id="fieldsTbl">
        <thead>
          <tr>
            <th class="sticky-col-0" style="width:46px;">✓</th>
            <th class="sticky-col-1" style="min-width:220px;">Feldname</th>
            <th style="min-width:220px;">Gruppe</th>
            <th style="min-width:160px;">Typ</th>
            <th style="min-width:260px;">Label</th>
            <th style="min-width:240px;">Stammfeld</th>
            <th style="min-width:420px;">Help</th>
            <th style="min-width:120px;">Kind</th>
            <th style="min-width:120px;">Lehrer</th>
            <th style="min-width:140px;">Klassenfeld</th>
            <th style="min-width:120px;">Req</th>
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
    <h3 style="margin-top:0;">PDF Vorschau</h3>
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
import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

const csrf = "<?=h(csrf_token())?>";
const templateId = <?= (int)$templateId ?>;

const apiUrl = "<?=h(url('admin/ajax/template_fields_api.php'))?>";
const optionListsApiUrl = "<?=h(url('admin/ajax/option_lists_api.php'))?>";

const metaLine = document.getElementById('metaLine');
const tbody = document.querySelector('#fieldsTbl tbody');
const saveHint = document.getElementById('saveHint');

const groupList = document.getElementById('groupList');
const groupsBar = document.getElementById('groupsBar');
const btnShowAllGroups = document.getElementById('btnShowAllGroups');
const btnClearGroupFilter = document.getElementById('btnClearGroupFilter');

const fieldFilter = document.getElementById('fieldFilter');
const fieldExclude = document.getElementById('fieldExclude');
const btnClearFilter = document.getElementById('btnClearFilter');

const selCount = document.getElementById('selCount');
const btnSave = document.getElementById('btnSave');

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

let modalFieldId = null;

// --- PDF preview state
const pdfUrl = "<?=h($pdfUrl)?>";
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

function getGroupPath(f){
  const g = f?.meta?.group;
  return (g && String(g).trim()) ? String(g).trim() : '—';
}

function markDirty(id){
  dirty.add(id);
  saveHint.textContent = dirty.size ? `Ungespeicherte Änderungen: ${dirty.size}` : ' ';
  
  if (dirty.size) {
        document.getElementById("dirtyWarning").style.display = "block";
    } else {
        document.getElementById("dirtyWarning").style.display = "none";
    }
}

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

  // Exclude filter: hide anything that contains the given substring.
  if (nq && hay.includes(nq)) return false;

  // Include filter: show all if empty, otherwise must match.
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
        updateMeta();
        return;
      }
      groupFilter = (groupFilter === g) ? '' : g;
      renderGroupsBar();
      renderTable();
      updateMeta();
    });

    groupsBar.appendChild(pill);
  }
}

/* ---------- TABLE ---------- */
function renderTable(){
  tbody.innerHTML = '';
  rowByFieldName = new Map(); // map visible rows by name (like templates.php)

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
      const td = document.createElement('td');
      td.colSpan = 12;

      const isCollapsed = collapsedGroupHeaders.has(g);
      const cnt = byGroup.get(g)?.length ?? 0;
      td.innerHTML = `
        <div class="gwrap">
          <span class="gbtn">${isCollapsed ? '+' : '–'}</span>
          <span>${escapeHtml(g)}</span>
          <span class="gmeta">(${cnt})</span>
          <span class="gmeta">Alt-Klick = ausblenden</span>
        </div>
      `;
      td.addEventListener('click', (e)=>{
        e.preventDefault();
        if (e.altKey) {
          if (g !== '—') hiddenGroups.add(g);
          rebuildGroupDatalist();
          renderGroupsBar();
          renderTable();
          updateMeta();
          return;
        }
        if (collapsedGroupHeaders.has(g)) collapsedGroupHeaders.delete(g);
        else collapsedGroupHeaders.add(g);
        renderGroupsBar();
        renderTable();
      });

      gr.appendChild(td);
      tbody.appendChild(gr);
    }

    if (collapsedGroupHeaders.has(g)) continue;

    const idx = fields.findIndex(x=>x.id===f.id);
    if (idx < 0) continue;

    const tr = document.createElement('tr');
    tr.dataset.id = String(f.id);
    tr.draggable = true;

    // map first visible row per field_name
    if (!rowByFieldName.has(f.name)) rowByFieldName.set(f.name, tr);

    if (selected.has(f.id)) tr.classList.add('is-selected');
    if (lastFoundRowId === f.id) tr.classList.add('is-found');

    tr.addEventListener('dragstart', (e)=>{
      e.dataTransfer.setData('text/plain', String(f.id));
      e.dataTransfer.effectAllowed = 'move';
    });
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

      // Use pageWidgets (like templates.php) for reliable highlight
      const widgets = pageWidgets.get(currentPage) || [];
      const any = [...pageWidgets.entries()].find(([p, arr]) => arr.some(w => w.name === f.name));
      const targetPage = any ? Number(any[0]) : (f.meta?.page ? Number(f.meta.page) : currentPage);

      // pick first widget rect for that name on that page
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
      updateMeta();
    });
    tdS.appendChild(cb);

    const tdN = document.createElement('td');
    tdN.className = 'sticky-col-1';
    tdN.textContent = f.name;

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
    });
    tdT.appendChild(selT);

    const tdL = document.createElement('td');
    const inpL = document.createElement('input');
    inpL.type = 'text';
    inpL.value = f.label || f.name;
    inpL.addEventListener('click',(e)=>e.stopPropagation());
    inpL.addEventListener('input',(e)=>{
      e.stopPropagation();
      fields[idx].label = inpL.value;
      markDirty(f.id);
    });
    tdL.appendChild(inpL);

    // System binding (maps master data -> this form field)
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
        // sensible defaults: class-wide fields are usually teacher-only
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

    // ✓ | Feldname | Gruppe | Typ | Label | Stammfeld | Help | Kind | Lehrer | Klassenfeld | Req | Extras
    tr.append(tdS, tdN, tdG, tdT, tdL, tdB, tdH, tdC, tdTe, tdK, tdR, tdX);
    tbody.appendChild(tr);
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
  updateMeta();
}

/* ---------- Bulk ---------- */
function buildBulkPatch(){
  const patch = {};
  const g = bulkGroup.value.trim();
  patch.meta_merge = {};
  if (g) patch.meta_merge.group = g;
  //else patch.meta_merge.group = null;

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
  updateMeta();
  bulkGroup.value = null;
}

/* ---------- Auto group ---------- */
function extractPrefix(nameRaw){
  let name = String(nameRaw || '').trim();
  if (!name) return '';

  // ✅ ignore everything after '-'
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
  updateMeta();
}

/* ---------- Save ---------- */
async function save(){
  if (!dirty.size) { saveHint.textContent = 'Nichts zu speichern.'; return; }

  const updates = fields
    .filter(f => dirty.has(f.id))
    .map(f => ({
      id: f.id,
      type: f.type,
      label: f.label,
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
  
  if (dirty.size) {
        document.getElementById("dirtyWarning").style.display = "block";
    } else {
        document.getElementById("dirtyWarning").style.display = "none";
    }
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
});

/* ---------- Preview resizer ---------- */
const LS_KEY = 'template_fields_preview_ratio_v2';
function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }

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
  updateMeta();
});

fieldExclude.addEventListener('input', ()=>{
  excludeText = fieldExclude.value || '';
  renderGroupsBar();
  renderTable();
  updateMeta();
});
btnClearFilter.addEventListener('click', ()=>{
  fieldFilter.value='';
  filterText='';
  if (fieldExclude) fieldExclude.value='';
  excludeText='';
  renderGroupsBar();
  renderTable();
  updateMeta();
});

btnApplySelected.addEventListener('click', ()=>applyBulk(getSelectedIds()));
btnApplyVisible.addEventListener('click', ()=>applyBulk(getVisibleIds()));
btnAutoGroupPrefix.addEventListener('click', ()=>autoGroupPrefix(getSelectedIds().length ? getSelectedIds() : getVisibleIds()));
btnAutoGroupPage.addEventListener('click', ()=>autoGroupPage(getSelectedIds().length ? getSelectedIds() : getVisibleIds()));
btnSave.addEventListener('click', save);

btnShowAllGroups.addEventListener('click', ()=>{
  hiddenGroups = new Set();
  groupFilter = '';
  renderGroupsBar();
  renderTable();
  updateMeta();
});
btnClearGroupFilter.addEventListener('click', ()=>{
  groupFilter = '';
  renderGroupsBar();
  renderTable();
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
  await loadPdf();
}

/* ---------- PDF widgets like templates.php ---------- */
async function buildPageWidgetsFromPdf(){
  pageWidgets = new Map();
  if (!pdfDoc || typeof pdfDoc.getFieldObjects !== 'function') return;

  const fo = await pdfDoc.getFieldObjects();
  if (!fo || typeof fo !== 'object') return;

  // 1) erst sammeln, um zu erkennen ob pdf.js 0-based liefert
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

  // 2) konsequent normalisieren
  const numPages = Number(pdfDoc?.numPages || 0);

  for (const t of tmp) {
    let p = t.pNum;

    // ✅ wenn irgendwo page=0 vorkommt -> alles als 0-based behandeln
    if (hasZero) p = p + 1;

    // Clamp
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

  // ✅ draw all widgets on current page light-blue (like templates.php)
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

  // draw current highlight in red on top
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

/* PDF click -> use widgets map (like templates.php) -> table jump */
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

  // highlight exact widget rect (important for duplicates)
  currentHighlight = { page: currentPage, rect: hit.rect, name: hit.name };
  await renderPage();

  // ensure table shows it (unhide/un-collapse if needed)
  const field = fields.find(f=>String(f.name) === String(hit.name));
  if (field) {
    const g = getGroupPath(field);
    hiddenGroups.delete(g);
    collapsedGroupHeaders.delete(g);
  }

  // do not force-clear user's filter; try to reveal minimally
  renderGroupsBar();
  renderTable();
  updateMeta();

  let tr = rowByFieldName.get(hit.name);

  // if still not visible because filter/groupFilter is active, reveal it
  if (!tr && field) {
    // turn off groupFilter first (keeps typed filter if they want)
    groupFilter = '';
    // if typed filter hides it, clear it
    const q = (filterText || '').toLowerCase().trim();
    const ok = !q || String(field.name||'').toLowerCase().includes(q) || String(field.label||'').toLowerCase().includes(q) || getGroupPath(field).toLowerCase().includes(q);
    if (!ok) {
      fieldFilter.value = '';
      filterText = '';
    }
    renderGroupsBar();
    renderTable();
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
