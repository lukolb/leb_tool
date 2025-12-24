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

    if (($_POST['action'] ?? '') === 'upload') {
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

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$templates = $pdo->query("
  SELECT id, name, template_version, pdf_storage_path, pdf_original_filename, is_active, created_at
  FROM templates
  ORDER BY created_at DESC
")->fetchAll();

render_admin_header('Admin – Templates');
?>

<style>
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

/* Preview toggle */
#wizGrid.is-preview-hidden{
  grid-template-columns: 1fr !important;
}
#wizPreviewCol.is-hidden{
  display:none !important;
}

/* Row highlight flash */
tr.flash {
  animation: flashRow 0.7s ease;
}
@keyframes flashRow {
  0% { background: rgba(176,0,32,0.18); }
  100% { background: transparent; }
}
</style>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Admin</a>
    <a class="btn secondary" href="<?=h(url('admin/icon_library.php'))?>">Icon Library</a>
    <a class="btn secondary" href="<?=h(url('admin/settings.php'))?>">Settings</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2>PDF Template hochladen</h2>
  <form method="post" enctype="multipart/form-data" autocomplete="off">
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
    <input type="file" name="pdf" accept=".pdf,application/pdf" required>

    <div class="actions" style="margin-top:12px;">
      <button class="btn primary" type="submit">Hochladen</button>
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
        <tr>
          <th>ID</th><th>Name</th><th>Version</th><th>PDF</th><th>Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <td><?=h((string)$t['id'])?></td>
            <td><?=h($t['name'])?></td>
            <td><?=h((string)$t['template_version'])?></td>
            <td>
              <a href="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>" target="_blank">
                <?=h($t['pdf_original_filename'] ?: 'PDF')?>
              </a>
            </td>
            <td style="white-space:nowrap;">
              <button
                class="btn secondary js-extract"
                type="button"
                data-template-id="<?=h((string)$t['id'])?>"
                data-pdf-url="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>">
                Felder auslesen
              </button>
              <a class="btn secondary" href="<?=h(url('admin/template_fields.php?template_id='.(int)$t['id']))?>">Bearbeiten</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" id="wizard" style="display:none;">
  <h2>Import-Wizard: Rechte & Basisdaten</h2>
  <p class="muted" id="wizMeta"></p>

  <div class="actions" style="margin-top:12px; flex-wrap:wrap;">
    <button class="btn secondary" id="btnChildNone" type="button">Kind: nichts (sichtbar)</button>
    <button class="btn secondary" id="btnChildAll" type="button">Kind: alle (sichtbar)</button>
    <button class="btn secondary" id="btnTeachNone" type="button">Lehrer: nichts (sichtbar)</button>
    <button class="btn secondary" id="btnTeachAll" type="button">Lehrer: alle (sichtbar)</button>

    <!-- toggle preview -->
    <button class="btn secondary" id="btnTogglePreview" type="button">Vorschau ausblenden</button>

    <button class="btn primary" id="btnImport" type="button">Importieren</button>
    <button class="btn secondary" id="btnCancel" type="button">Abbrechen</button>
  </div>

  <!-- COPY FROM TEMPLATE -->
  <div class="copybar">
    <div class="block">
      <label>Eigenschaften übernehmen von Template</label>
      <select id="copyFromTemplate">
        <option value="">— kein —</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?=h((string)$t['id'])?>">
            #<?=h((string)$t['id'])?> · <?=h($t['name'])?> v<?=h((string)$t['template_version'])?>
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

          <!-- NEW: toggle highlight all fields -->
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

let currentTemplateId = null;
let currentPdfUrl = null;

let fields = [];
let filterText = '';

let pdfDoc = null;
let currentPage = 1;
let currentHighlight = null;

const FIELD_TYPES = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];

// mapping for preview click -> table row
let pageWidgets = new Map();     // pageNo -> [{name, rect}]
let rowByFieldName = new Map();  // fieldName -> <tr>

// NEW: show/ hide soft highlights for ALL widgets
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
  void tr.offsetWidth; // reflow
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
    tr.dataset.fieldName = String(f.name || '');

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

async function loadPdf() {
  pdfDoc = await pdfjsLib.getDocument({ url: currentPdfUrl, withCredentials: true }).promise;
  currentPage = 1;
  currentHighlight = null;

  // build widget index for preview clicks (page -> widgets)
  pageWidgets = new Map();
  for (let p = 1; p <= pdfDoc.numPages; p++) {
    const page = await pdfDoc.getPage(p);
    const annots = await page.getAnnotations({ intent: "display" });
    const widgets = [];
    for (const a of annots) {
      if (a.subtype !== 'Widget') continue;
      const name = (a.fieldName || '').toString().trim();
      const rect = Array.isArray(a.rect) && a.rect.length === 4 ? a.rect : null;
      if (!name || !rect) continue;
      widgets.push({ name, rect });
    }
    pageWidgets.set(p, widgets);
  }

  renderPage();
}

async function renderPage() {
  if (!pdfDoc) return;
  const page = await pdfDoc.getPage(currentPage);

  const viewport = page.getViewport({ scale: 1.2 });
  const ctx = pdfCanvas.getContext('2d');

  pdfCanvas.width = Math.floor(viewport.width);
  pdfCanvas.height = Math.floor(viewport.height);

  await page.render({ canvasContext: ctx, viewport }).promise;

  // NEW: Soft-Highlight ALL widgets on this page
  if (showAllWidgetHighlights) {
    const widgets = pageWidgets.get(currentPage) || [];
    if (widgets.length) {
      ctx.save();
      ctx.lineWidth = 1;
      ctx.strokeStyle = 'rgba(0, 120, 255, 0.35)';
      ctx.fillStyle   = 'rgba(0, 120, 255, 0.10)';

      for (const w of widgets) {
        const [x1, y1, x2, y2] = w.rect;
        const p1 = viewport.convertToViewportPoint(x1, y1);
        const p2 = viewport.convertToViewportPoint(x2, y2);

        const rx = Math.min(p1[0], p2[0]);
        const ry = Math.min(p1[1], p2[1]);
        const rw = Math.abs(p2[0] - p1[0]);
        const rh = Math.abs(p2[1] - p1[1]);

        const wmin = Math.max(rw, 6);
        const hmin = Math.max(rh, 6);

        ctx.fillRect(rx, ry, wmin, hmin);
        ctx.strokeRect(rx, ry, wmin, hmin);
      }
      ctx.restore();
    }
  }

  // Red highlight for selected field
  if (currentHighlight && currentHighlight.page === currentPage && currentHighlight.rect) {
    const [x1, y1, x2, y2] = currentHighlight.rect;
    const p1 = viewport.convertToViewportPoint(x1, y1);
    const p2 = viewport.convertToViewportPoint(x2, y2);

    const rx = Math.min(p1[0], p2[0]);
    const ry = Math.min(p1[1], p2[1]);
    const rw = Math.abs(p2[0] - p1[0]);
    const rh = Math.abs(p2[1] - p1[1]);

    ctx.save();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#b00020';
    ctx.fillStyle = 'rgba(176,0,32,0.12)';
    ctx.fillRect(rx, ry, rw, rh);
    ctx.strokeRect(rx, ry, rw, rh);
    ctx.restore();

    pdfHint.textContent = `Markiert: ${currentHighlight.name}`;
  } else {
    pdfHint.textContent = 'Klicke links ein Feld, um es im PDF zu markieren. Oder klicke im PDF → Tabelle springt.';
  }

  pageInfo.textContent = `Seite ${currentPage} / ${pdfDoc.numPages}`;
  btnPrevPage.disabled = currentPage <= 1;
  btnNextPage.disabled = currentPage >= pdfDoc.numPages;

  // update highlight-button label
  btnToggleHighlights.textContent = showAllWidgetHighlights ? 'Felder hervorheben: an' : 'Felder hervorheben: aus';
}

btnPrevPage.addEventListener('click', ()=>{ if(currentPage>1){ currentPage--; renderPage(); }});
btnNextPage.addEventListener('click', ()=>{ if(pdfDoc && currentPage<pdfDoc.numPages){ currentPage++; renderPage(); }});

// NEW: toggle highlight-all-fields
btnToggleHighlights.addEventListener('click', () => {
  showAllWidgetHighlights = !showAllWidgetHighlights;
  if (pdfDoc) renderPage();
});

// click in preview -> jump to table row + highlight + keep pdf highlight
pdfCanvas.addEventListener('click', (ev) => {
  if (!pdfDoc) return;

  const rect = pdfCanvas.getBoundingClientRect();
  const sx = pdfCanvas.width / rect.width;
  const sy = pdfCanvas.height / rect.height;

  const cx = (ev.clientX - rect.left) * sx;
  const cy = (ev.clientY - rect.top) * sy;

  pdfDoc.getPage(currentPage).then(page => {
    const viewport = page.getViewport({ scale: 1.2 });
    const [pdfX, pdfY] = viewport.convertToPdfPoint(cx, cy);

    const widgets = pageWidgets.get(currentPage) || [];
    const hit = widgets.find(w => {
      const [x1,y1,x2,y2] = w.rect;
      const minX = Math.min(x1,x2), maxX = Math.max(x1,x2);
      const minY = Math.min(y1,y2), maxY = Math.max(y1,y2);
      return (pdfX >= minX && pdfX <= maxX && pdfY >= minY && pdfY <= maxY);
    });

    if (!hit) return;

    currentHighlight = { page: currentPage, rect: hit.rect, name: hit.name };
    renderPage();

    let tr = rowByFieldName.get(hit.name);

    // NEW: if filtered out -> reset filter and rerender table, then try again
    if (!tr) {
      fieldFilter.value = '';
      filterText = '';
      renderTable();
      tr = rowByFieldName.get(hit.name);
    }

    if (tr) {
      tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
      flashRow(tr);
    } else {
      pdfHint.textContent = `Feld „${hit.name}“ gefunden, aber Zeile konnte nicht angezeigt werden.`;
    }
  });
});

// ---- Label aus Textzeile links vom Feld (heuristisch)
function pickLabelFromLine(textItems, fieldRect) {
  const cy = (fieldRect[1] + fieldRect[3]) / 2;
  const yTol = Math.max(6, Math.abs(fieldRect[3]-fieldRect[1]) * 0.6);

  const candidates = textItems
    .filter(it => Math.abs(it.y - cy) <= yTol && it.x <= fieldRect[0] - 2 && it.str && it.str.trim() !== '')
    .sort((a,b) => (fieldRect[0]-a.x) - (fieldRect[0]-b.x));

  if (!candidates.length) return null;

  const best = candidates.slice(0, 3).reverse();
  const label = best.map(x => x.str.trim()).join(' ').replace(/\s+/g,' ').trim();
  if (label.length < 2) return null;
  return label;
}

async function extractFieldsFromPdf() {
  const pdf = await pdfjsLib.getDocument({ url: currentPdfUrl, withCredentials: true }).promise;

  const out = new Map();
  let sort = 0;

  if (pdf.getFieldObjects) {
    const fo = await pdf.getFieldObjects();
    if (fo && typeof fo === 'object') {
      for (const [name, arr] of Object.entries(fo)) {
        const first = (Array.isArray(arr) && arr[0]) ? arr[0] : {};
        const rawType = first.type || first.fieldType || '';
        const multilineFlag = !!(first.multiline || first.multiLine);
        let type = normalizeType(rawType, multilineFlag);

        out.set(name, {
          name,
          type,
          label: name,
          help_text: '',
          multiline: multilineFlag,
          sort: sort++,
          meta: { type: rawType, multiline: multilineFlag }
        });
      }
    }
  }

  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);

    const textContent = await page.getTextContent();
    const textItems = (textContent.items || []).map(it => {
      const str = (it.str || '').toString();
      const tr = it.transform || [0,0,0,0,0,0];
      return { str, x: tr[4] || 0, y: tr[5] || 0 };
    });

    const annots = await page.getAnnotations({ intent: "display" });
    for (const a of annots) {
      if (a.subtype !== 'Widget') continue;
      const name = (a.fieldName || '').toString().trim();
      if (!name) continue;

      const rect = Array.isArray(a.rect) && a.rect.length === 4 ? a.rect : null;
      const rawType = a.fieldType || a.type || '';
      let type = normalizeType(rawType, false);

      if (a.radioButton === true) type = 'radio';
      if (a.checkBox === true) type = 'checkbox';

      const hint = (a.alternativeText || a.altText || a.tooltip || a.title || a.fieldLabel || '')?.toString?.() || '';

      if (!out.has(name)) {
        out.set(name, {
          name,
          type: FIELD_TYPES.includes(type) ? type : 'radio',
          label: name,
          help_text: hint || '',
          multiline: false,
          sort: sort++,
          meta: { type: rawType }
        });
      } else {
        const it = out.get(name);
        if (it && type === 'radio') it.type = 'radio';
        if (it && !it.help_text && hint) it.help_text = hint;
      }

      const item = out.get(name);
      if (item && rect) {
        item.meta = item.meta || {};
        if (!item.meta.page) item.meta.page = p;
        if (!item.meta.rect) item.meta.rect = rect;

        const suggested = pickLabelFromLine(textItems, rect);
        if (suggested) {
          if (!item.label || item.label === item.name) item.label = suggested;
          if (!item.help_text && suggested.length > 18) item.help_text = suggested;
        }
      }
    }
  }

  return Array.from(out.values()).sort((a,b)=> (a.sort??0)-(b.sort??0));
}

// COPY: fetch template_fields map from server
async function fetchTemplateFieldsMap(templateId){
  const url = "<?=h(url('admin/ajax/template_fields_export.php'))?>?template_id=" + encodeURIComponent(templateId);
  const resp = await fetch(url, { method: "GET" });
  const data = await resp.json().catch(()=> ({}));
  if (!resp.ok || !data.ok) throw new Error(data.error || ("HTTP " + resp.status));
  const map = new Map();
  (data.fields || []).forEach(f => { if (f && f.name) map.set(String(f.name), f); });
  return map;
}

function getCopyOptions(){
  return {
    type:  !!cpType.checked,
    label: !!cpLabel.checked,
    help:  !!cpHelp.checked,
    rights:!!cpRights.checked,
    meta:  !!cpMeta.checked
  };
}

function applyFromSourceMap(sourceMap, onlyVisible){
  const opt = getCopyOptions();
  let applied = 0;

  fields = fields.map(f => {
    if (onlyVisible && !isVisibleByFilter(f)) return f;
    const src = sourceMap.get(String(f.name));
    if (!src) return f;

    applied++;

    const next = { ...f };

    if (opt.type) {
      const t = (src.type && FIELD_TYPES.includes(src.type)) ? src.type : next.type;
      next.type = t;
      next.multiline = !!src.multiline;
    }
    if (opt.label) {
      if (src.label && String(src.label).trim() !== '') next.label = String(src.label);
    }
    if (opt.help) {
      if (src.help_text && String(src.help_text).trim() !== '') next.help_text = String(src.help_text);
    }
    if (opt.rights) {
      next.can_child_edit = src.can_child_edit ? 1 : 0;
      next.can_teacher_edit = (src.can_teacher_edit ?? 1) ? 1 : 0;
    }
    if (opt.meta) {
      if (src.meta && typeof src.meta === 'object') next.meta = src.meta;
    }

    return next;
  });

  renderTable();
  return applied;
}

btnCopyVisible.addEventListener('click', async () => {
  try {
    const fromId = parseInt(copyFromTemplate.value || '0', 10);
    if (!fromId) { copyResult.textContent = 'Bitte Quelle auswählen.'; return; }
    copyResult.textContent = 'Lade…';
    const map = await fetchTemplateFieldsMap(fromId);
    const n = applyFromSourceMap(map, true);
    copyResult.textContent = `Übernommen: ${n} (sichtbar)`;
  } catch (e) {
    copyResult.textContent = 'Fehler: ' + (e && e.message ? e.message : e);
  }
});

btnCopyAll.addEventListener('click', async () => {
  try {
    const fromId = parseInt(copyFromTemplate.value || '0', 10);
    if (!fromId) { copyResult.textContent = 'Bitte Quelle auswählen.'; return; }
    copyResult.textContent = 'Lade…';
    const map = await fetchTemplateFieldsMap(fromId);
    const n = applyFromSourceMap(map, false);
    copyResult.textContent = `Übernommen: ${n} (alle)`;
  } catch (e) {
    copyResult.textContent = 'Fehler: ' + (e && e.message ? e.message : e);
  }
});

// Preview toggle
let previewVisible = true;
btnTogglePreview.addEventListener('click', () => {
  previewVisible = !previewVisible;
  if (previewVisible) {
    wizGrid.classList.remove('is-preview-hidden');
    wizPreviewCol.classList.remove('is-hidden');
    btnTogglePreview.textContent = 'Vorschau ausblenden';
    if (pdfDoc) setTimeout(()=>renderPage(), 20);
  } else {
    wizGrid.classList.add('is-preview-hidden');
    wizPreviewCol.classList.add('is-hidden');
    btnTogglePreview.textContent = 'Vorschau einblenden';
  }
});

document.querySelectorAll('.js-extract').forEach(btn => {
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      currentTemplateId = parseInt(btn.dataset.templateId, 10);
      currentPdfUrl = btn.dataset.pdfUrl;

      if (!currentTemplateId || Number.isNaN(currentTemplateId)) {
        throw new Error("template_id konnte nicht gelesen werden (data-template-id fehlt?).");
      }

      fields = await extractFieldsFromPdf();
      fields = fields.map(f => ({
        ...f,
        can_child_edit: 0,
        can_teacher_edit: 1,
        label: f.label || f.name,
        help_text: f.help_text || '',
        type: FIELD_TYPES.includes(f.type) ? f.type : 'radio'
      }));

      // reset filter & copy UI
      filterText = '';
      fieldFilter.value = '';
      copyFromTemplate.value = '';
      copyResult.textContent = '';

      // defaults: all copy options on
      cpType.checked = true;
      cpLabel.checked = true;
      cpHelp.checked = true;
      cpRights.checked = true;
      cpMeta.checked = true;

      // ensure preview shown when opening wizard
      previewVisible = true;
      wizGrid.classList.remove('is-preview-hidden');
      wizPreviewCol.classList.remove('is-hidden');
      btnTogglePreview.textContent = 'Vorschau ausblenden';

      // highlights default ON
      showAllWidgetHighlights = true;
      btnToggleHighlights.textContent = 'Felder hervorheben: an';

      wizard.style.display = 'block';
      renderTable();
      await loadPdf();
    } catch (e) {
      alert("Fehler beim Auslesen: " + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
    }
  });
});

btnChildNone.addEventListener('click', () => setChildVisible(0));
btnChildAll.addEventListener('click',  () => setChildVisible(1));
btnTeachNone.addEventListener('click', () => setTeachVisible(0));
btnTeachAll.addEventListener('click',  () => setTeachVisible(1));

btnImport.addEventListener('click', async () => {
  btnImport.disabled = true;
  try {
    if (!currentTemplateId || Number.isNaN(currentTemplateId)) {
      throw new Error("template_id ist leer – bitte Wizard neu öffnen.");
    }

    const payloadFields = fields.map((f, i) => ({
      name: f.name,
      type: f.type,
      label: (f.label && f.label.trim() !== '') ? f.label.trim() : f.name,
      help_text: (f.help_text && String(f.help_text).trim() !== '') ? String(f.help_text).trim() : '',
      multiline: (f.type === 'multiline') ? true : !!f.multiline,
      sort: i,
      meta: f.meta || {},
      can_child_edit: f.can_child_edit ? 1 : 0,
      can_teacher_edit: f.can_teacher_edit ? 1 : 0
    }));

    const params = new URLSearchParams();
    params.set('csrf_token', csrf);
    params.set('template_id', String(currentTemplateId));
    params.set('fields', JSON.stringify(payloadFields));

    const resp = await fetch("<?=h(url('admin/ajax/import_fields.php'))?>", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        "X-CSRF-Token": csrf
      },
      body: params.toString()
    });

    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || ("Import fehlgeschlagen (HTTP " + resp.status + ")"));

    alert("Import OK: " + data.imported + " Felder.");
    window.location.href = "<?=h(url('admin/template_fields.php'))?>?template_id=" + encodeURIComponent(currentTemplateId);
  } catch (e) {
    alert("Import-Fehler: " + (e && e.message ? e.message : e));
  } finally {
    btnImport.disabled = false;
  }
});

btnCancel.addEventListener('click', () => {
  wizard.style.display = 'none';
  currentTemplateId = null;
  currentPdfUrl = null;
  fields = [];
  tbody.innerHTML = '';
  pdfDoc = null;
  currentHighlight = null;
  pageWidgets = new Map();
  rowByFieldName = new Map();

  // reset filter for next open
  fieldFilter.value = '';
  filterText = '';
});

fieldFilter.addEventListener('input', () => { filterText = String(fieldFilter.value || '').trim(); renderTable(); });
btnClearFilter.addEventListener('click', () => { fieldFilter.value = ''; filterText=''; renderTable(); });

</script>

<?php render_admin_footer(); ?>
