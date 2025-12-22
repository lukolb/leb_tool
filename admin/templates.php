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
      $uploadsAbs = realpath(__DIR__ . '/..') . '/' . $uploadsRel;

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

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Admin</a>
    <a class="btn secondary" href="<?=h(url('admin/template_fields.php'))?>">Feld-Editor</a>
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
  <h2>Import-Wizard: Feldoptionen festlegen</h2>
  <p class="muted" id="wizMeta"></p>

  <div class="grid" style="align-items:end;">
    <div>
      <label>Einstellungen übernehmen von Template</label>
      <select id="copyFrom">
        <option value="0">– keine –</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?=h((string)$t['id'])?>">
            #<?=h((string)$t['id'])?> – <?=h($t['name'])?> v<?=h((string)$t['template_version'])?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="muted">Übernimmt Typ/Label/Kind/Lehrer nach Feldname (field_name).</div>
    </div>
    <div class="actions" style="justify-content:flex-start;">
      <button class="btn secondary" id="btnCopyApply" type="button">Übernehmen</button>
    </div>
  </div>

  <div class="actions" style="margin-top:12px; flex-wrap:wrap;">
    <button class="btn secondary" id="btnNone" type="button">Kind: nichts</button>
    <button class="btn secondary" id="btnAll" type="button">Kind: alle</button>
    <button class="btn secondary" id="btnPreset" type="button">Preset: Strengths/Goals/Comments</button>
    <button class="btn primary" id="btnImport" type="button">Importieren</button>
    <button class="btn secondary" id="btnCancel" type="button">Abbrechen</button>
  </div>

  <div class="grid" style="grid-template-columns: 1.2fr 0.8fr; gap:14px; margin-top:12px;">
    <div style="overflow:auto;">
      <table id="fieldsTbl">
        <thead>
          <tr>
            <th style="width:70px;">Kind</th>
            <th style="width:90px;">Lehrer</th>
            <th>Feldname</th>
            <th style="width:190px;">Typ *</th>
            <th>Beschreibung/Label</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      <p class="muted" style="margin-top:8px;">
        * Typ ist Pflicht. Rot = bitte auswählen.
      </p>
    </div>

    <div>
      <div class="card" style="margin:0;">
        <h3 style="margin-top:0;">PDF Vorschau</h3>
        <div class="muted" id="pdfHint">Klicke links ein Feld, um es im PDF zu markieren.</div>

        <div style="display:flex; gap:8px; align-items:center; margin:10px 0;">
          <button class="btn secondary" id="btnPrevPage" type="button">←</button>
          <div class="muted" id="pageInfo">Seite –</div>
          <button class="btn secondary" id="btnNextPage" type="button">→</button>
        </div>

        <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden;">
          <canvas id="pdfCanvas" style="display:block; width:100%; height:auto;"></canvas>
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

const btnNone = document.getElementById('btnNone');
const btnAll = document.getElementById('btnAll');
const btnPreset = document.getElementById('btnPreset');
const btnImport = document.getElementById('btnImport');
const btnCancel = document.getElementById('btnCancel');

const copyFrom = document.getElementById('copyFrom');
const btnCopyApply = document.getElementById('btnCopyApply');

const pdfCanvas = document.getElementById('pdfCanvas');
const pdfHint = document.getElementById('pdfHint');
const pageInfo = document.getElementById('pageInfo');
const btnPrevPage = document.getElementById('btnPrevPage');
const btnNextPage = document.getElementById('btnNextPage');

let currentTemplateId = null;
let currentPdfUrl = null;

let fields = [];
let pdfDoc = null;
let currentPage = 1;
let currentHighlight = null;

// Neue Typen:
const FIELD_TYPES = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];

function normalizeType(rawType, multilineFlag) {
  const t = String(rawType || '').trim();
  const u = t.toUpperCase();

  // PDF.js typische Kürzel:
  if (u === 'TX' || u === 'TEXT') {
    return multilineFlag ? 'multiline' : 'text';
  }
  if (u === 'CH' || u === 'SELECT') return 'select';
  if (u === 'SIG' || u === 'SIGNATURE') return 'signature';
  if (u === 'BTN') return 'checkbox'; // default; radio vs checkbox später ggf. genauer
  if (u === 'CHECKBOX') return 'checkbox';
  if (u === 'RADIO') return 'radio';

  return 'radio';
}

function updateMeta() {
  const n = fields.length;
  const c = fields.filter(f => f.can_child_edit === 1).length;
  const locked = fields.filter(f => f.can_teacher_edit !== 1 && f.can_child_edit !== 1).length;
  wizMeta.textContent = `Template #${currentTemplateId} – ${n} Felder – Kind: ${c} – gesperrt: ${locked}`;
}

function renderTable() {
  tbody.innerHTML = '';
  fields.forEach((f, idx) => {
    const tr = document.createElement('tr');
    tr.style.cursor = 'pointer';

    tr.addEventListener('click', () => {
      if (f.meta && f.meta.page && f.meta.rect) {
        currentHighlight = { page: f.meta.page, rect: f.meta.rect, name: f.name };
        currentPage = f.meta.page;
        renderPage();
      } else {
        currentHighlight = null;
        pdfHint.textContent = `Kein Positions-Rect für „${f.name}“ gefunden (PDF.js liefert es nicht immer).`;
        renderPage();
      }
    });

    const tdK = document.createElement('td');
    const cbK = document.createElement('input');
    cbK.type = 'checkbox';
    cbK.checked = f.can_child_edit === 1;
    cbK.addEventListener('change', () => {
      fields[idx].can_child_edit = cbK.checked ? 1 : 0;
      updateMeta();
    });
    tdK.appendChild(cbK);

    const tdT = document.createElement('td');
    const cbT = document.createElement('input');
    cbT.type = 'checkbox';
    cbT.checked = f.can_teacher_edit === 1;
    cbT.addEventListener('change', () => {
      fields[idx].can_teacher_edit = cbT.checked ? 1 : 0;
      updateMeta();
    });
    tdT.appendChild(cbT);

    const tdN = document.createElement('td');
    tdN.textContent = f.name;

    const tdTy = document.createElement('td');
    const sel = document.createElement('select');

    // "Bitte wählen"
    const optPick = document.createElement('option');
    optPick.value = 'radio';
    optPick.textContent = 'Bitte wählen…';
    sel.appendChild(optPick);

    FIELD_TYPES.forEach(t => {
      const o = document.createElement('option');
      o.value = t;
      o.textContent = t;
      if (t === f.type) o.selected = true;
      sel.appendChild(o);
    });

    if (!FIELD_TYPES.includes(f.type)) {
      sel.value = 'radio';
      sel.style.borderColor = 'var(--danger, #b00020)';
    }

    sel.addEventListener('change', () => {
      fields[idx].type = sel.value;
      sel.style.borderColor = (sel.value === 'radio') ? 'var(--danger, #b00020)' : 'var(--border)';
    });

    tdTy.appendChild(sel);

    const tdL = document.createElement('td');
    const inp = document.createElement('input');
    inp.value = f.label || f.name;
    inp.placeholder = 'Beschreibung…';
    inp.addEventListener('input', () => { fields[idx].label = inp.value; });
    tdL.appendChild(inp);

    tr.appendChild(tdK);
    tr.appendChild(tdT);
    tr.appendChild(tdN);
    tr.appendChild(tdTy);
    tr.appendChild(tdL);
    tbody.appendChild(tr);
  });

  updateMeta();
}

function setChildNone() {
  fields = fields.map(f => ({ ...f, can_child_edit: 0 }));
  renderTable();
}
function setChildAll() {
  fields = fields.map(f => ({ ...f, can_child_edit: 1 }));
  renderTable();
}
function setPreset() {
  const s = new Set(['Strengths','Comments','Goals1','Goals2']);
  fields = fields.map(f => ({ ...f, can_child_edit: s.has(f.name) ? 1 : 0 }));
  renderTable();
}

async function fetchTemplateFieldMap(templateId) {
  const resp = await fetch("<?=h(url('admin/ajax/template_fields_map.php'))?>?template_id=" + encodeURIComponent(templateId), {
    headers: { "X-CSRF-Token": csrf }
  });
  const j = await resp.json().catch(()=> ({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || "Konnte Template-Felder nicht laden");
  return j.map || {};
}

async function applyCopyFrom() {
  const fromId = parseInt(copyFrom.value, 10);
  if (!fromId) return;

  const map = await fetchTemplateFieldMap(fromId);
  let applied = 0;

  fields = fields.map(f => {
    const m = map[f.name];
    if (!m) return f;
    applied++;
    return {
      ...f,
      type: m.field_type || f.type,
      label: m.label || f.label,
      can_child_edit: (m.can_child_edit ?? f.can_child_edit) ? 1 : 0,
      can_teacher_edit: (m.can_teacher_edit ?? f.can_teacher_edit) ? 1 : 0,
    };
  });

  renderTable();
  alert("Übernommen für " + applied + " Felder (Match nach Feldname).");
}

function validateBeforeImport() {
  const missing = fields.filter(f => !FIELD_TYPES.includes(f.type));
  if (missing.length) {
    alert("Bitte für alle Felder einen Typ auswählen (rot markiert). Fehlend: " + missing.length);
    return false;
  }
  return true;
}

async function importToDb() {
  if (!validateBeforeImport()) return;

  btnImport.disabled = true;
  try {
    const payloadFields = fields.map(f => ({
      name: f.name,
      type: f.type,
      label: (f.label && f.label.trim() !== '') ? f.label.trim() : f.name,
      multiline: (f.type === 'multiline') ? true : !!f.multiline, // kompatibel
      sort: f.sort ?? 0,
      meta: f.meta || {},
      can_child_edit: f.can_child_edit ? 1 : 0,
      can_teacher_edit: f.can_teacher_edit ? 1 : 0
    }));

    const resp = await fetch("<?=h(url('admin/ajax/import_fields.php'))?>", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrf
      },
      body: JSON.stringify({
        csrf_token: csrf,
        template_id: currentTemplateId,
        fields: payloadFields
      })
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
}

async function loadPdf() {
  pdfDoc = await pdfjsLib.getDocument({ url: currentPdfUrl, withCredentials: true }).promise;
  currentPage = 1;
  currentHighlight = null;
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
    pdfHint.textContent = 'Klicke links ein Feld, um es im PDF zu markieren.';
  }

  pageInfo.textContent = `Seite ${currentPage} / ${pdfDoc.numPages}`;
  btnPrevPage.disabled = currentPage <= 1;
  btnNextPage.disabled = currentPage >= pdfDoc.numPages;
}

btnPrevPage.addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderPage(); } });
btnNextPage.addEventListener('click', () => { if (pdfDoc && currentPage < pdfDoc.numPages) { currentPage++; renderPage(); } });

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
        const type = normalizeType(rawType, multilineFlag);

        out.set(name, {
          name,
          type,
          label: name,
          multiline: multilineFlag,
          sort: sort++,
          meta: { type: rawType, multiline: multilineFlag }
        });
      }
    }
  }

  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const annots = await page.getAnnotations({ intent: "display" });

    for (const a of annots) {
      if (a.subtype !== 'Widget') continue;
      const name = (a.fieldName || '').toString().trim();
      if (!name) continue;

      const rect = Array.isArray(a.rect) && a.rect.length === 4 ? a.rect : null;
      const rawType = a.fieldType || a.type || '';
      const type = normalizeType(rawType, false);

      if (!out.has(name)) {
        out.set(name, {
          name,
          type: FIELD_TYPES.includes(type) ? type : 'radio',
          label: name,
          multiline: false,
          sort: sort++,
          meta: { type: rawType }
        });
      }

      const item = out.get(name);
      if (item && rect && !(item.meta && item.meta.rect)) {
        item.meta = item.meta || {};
        item.meta.page = p;
        item.meta.rect = rect;
      }
    }
  }

  return Array.from(out.values()).sort((a,b)=> (a.sort??0)-(b.sort??0));
}

document.querySelectorAll('.js-extract').forEach(btn => {
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      currentTemplateId = parseInt(btn.dataset.templateId, 10);
      currentPdfUrl = btn.dataset.pdfUrl;

      fields = await extractFieldsFromPdf();
      fields = fields.map(f => ({
        ...f,
        can_child_edit: 0,
        can_teacher_edit: 1,
        label: f.label || f.name
      }));

      wizard.style.display = 'block';
      renderTable();
      await loadPdf();
      updateMeta();
    } catch (e) {
      alert("Fehler beim Auslesen: " + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
    }
  });
});

btnNone.addEventListener('click', setChildNone);
btnAll.addEventListener('click', setChildAll);
btnPreset.addEventListener('click', setPreset);
btnImport.addEventListener('click', importToDb);

btnCancel.addEventListener('click', () => {
  wizard.style.display = 'none';
  currentTemplateId = null;
  currentPdfUrl = null;
  fields = [];
  tbody.innerHTML = '';
  pdfDoc = null;
  currentHighlight = null;
});

btnCopyApply.addEventListener('click', async () => {
  try { await applyCopyFrom(); }
  catch (e) { alert("Übernehmen fehlgeschlagen: " + (e && e.message ? e.message : e)); }
});
</script>

<?php render_admin_footer(); ?>
