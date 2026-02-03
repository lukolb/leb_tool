<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../shared/font_utils.php';
require_admin();

$pdo = db();
$err = '';
$ok = '';

$templates = $pdo->query("
  SELECT id, name, template_version, pdf_original_filename, is_active
  FROM templates
  ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload_font') {
      if (!isset($_FILES['font']) || ($_FILES['font']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('admin.fonts.error.file_required'));
      }

      $fontName = trim((string)($_POST['font_name'] ?? ''));
      $fontFamily = trim((string)($_POST['font_family'] ?? ''));
      if ($fontName === '') throw new RuntimeException(t('admin.fonts.error.name_missing'));

      $tmp = $_FILES['font']['tmp_name'];
      $origName = (string)($_FILES['font']['name'] ?? 'font.ttf');
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      if (!in_array($ext, ['ttf', 'otf'], true)) {
        throw new RuntimeException(t('admin.fonts.error.invalid_type'));
      }

      ensure_font_storage_dir();
      $key = sanitize_font_key($fontName);
      if ($key === '') throw new RuntimeException(t('admin.fonts.error.invalid_name'));

      $filename = $key . '.' . $ext;
      $dest = font_storage_root() . '/' . $filename;

      if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException(t('admin.fonts.error.save_failed'));

      $manifest = load_font_manifest();
      $manifest[$key] = [
        'name' => $fontName,
        'family' => $fontFamily,
        'file' => $filename,
        'sha256' => hash_file('sha256', $dest) ?: '',
        'uploaded_at' => date('c'),
        'original_filename' => $origName,
      ];
      save_font_manifest($manifest);
      audit('font_upload', (int)current_user()['id'], ['font_key' => $key, 'font_name' => $fontName]);
      $ok = t('admin.fonts.status.uploaded');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$manifest = load_font_manifest();
$fonts = array_values($manifest);

render_admin_header(t('admin.fonts.title'));
?>

<div class="card"><h1><?=h(t('admin.fonts.heading'))?></h1></div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2><?=h(t('admin.fonts.check_heading'))?></h2>
  <p class="muted"><?=h(t('admin.fonts.check_hint'))?></p>
  <div class="row" style="gap:12px; flex-wrap:wrap; align-items:flex-end;">
    <div style="min-width:260px;">
      <label><?=h(t('admin.fonts.template_label'))?></label>
      <select id="fontTemplateSelect" class="input" style="width:100%;">
        <option value="0"><?=h(t('admin.fonts.template_select'))?></option>
        <?php foreach ($templates as $tpl): ?>
          <option value="<?=h((string)$tpl['id'])?>" data-active="<?=h((string)$tpl['is_active'])?>">
            <?=h($tpl['name'])?><?=((int)($tpl['template_version'] ?? 0) > 0 ? ' (v' . h((string)$tpl['template_version']) . ')' : '')?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button id="btnCheckFonts" class="btn secondary" type="button"><?=h(t('admin.fonts.check_button'))?></button>
  </div>
  <div id="fontCheckResults" class="muted" style="margin-top:12px;"></div>
  <div id="fontMissingList" style="margin-top:12px;"></div>
</div>

<div class="card">
  <h2><?=h(t('admin.fonts.upload_heading'))?></h2>
  <p class="muted"><?=h(t('admin.fonts.upload_hint'))?></p>
  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="upload_font">
    <input type="hidden" id="fontNameInput" name="font_name" value="">
    <input type="hidden" id="fontFamilyInput" name="font_family" value="">
    <div class="row" style="gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <div style="min-width:240px;">
        <label><?=h(t('admin.fonts.file_label'))?></label>
        <input id="fontFileInput" class="file-input" type="file" name="font" accept=".ttf,.otf,font/ttf,font/otf" required>
      </div>
      <div class="muted" id="fontFileHint"><?=h(t('admin.fonts.file_hint'))?></div>
      <button class="btn primary" type="submit"><?=h(t('admin.fonts.upload_button'))?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.fonts.list_heading'))?></h2>
  <?php if (!$fonts): ?>
    <p class="muted"><?=h(t('admin.fonts.list_empty'))?></p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th><?=h(t('admin.fonts.table.name'))?></th>
          <th><?=h(t('admin.fonts.table.family'))?></th>
          <th><?=h(t('admin.fonts.table.file'))?></th>
          <th><?=h(t('admin.fonts.table.uploaded'))?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fonts as $font): ?>
          <tr>
            <td><?=h((string)($font['name'] ?? ''))?></td>
            <td><?=h((string)($font['family'] ?? ''))?></td>
            <td><?=h((string)($font['file'] ?? ''))?></td>
            <td><?=h((string)($font['uploaded_at'] ?? ''))?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script src="<?=h(url('assets/pdf-lib.min.js'))?>"></script>
<script src="https://unpkg.com/fontkit@2.0.2/dist/fontkit.umd.min.js"></script>
<script>
const FONT_MANIFEST_URL = <?=json_encode(url('shared/font_manifest.php'))?>;
const TEMPLATE_FILE_URL = <?=json_encode(url('admin/file.php?template_id='))?>;

const fontFileInput = document.getElementById('fontFileInput');
const fontNameInput = document.getElementById('fontNameInput');
const fontFamilyInput = document.getElementById('fontFamilyInput');
const fontFileHint = document.getElementById('fontFileHint');

const fontTemplateSelect = document.getElementById('fontTemplateSelect');
const btnCheckFonts = document.getElementById('btnCheckFonts');
const fontCheckResults = document.getElementById('fontCheckResults');
const fontMissingList = document.getElementById('fontMissingList');

const STANDARD_FONTS = new Set([
  'helvetica','helvetica-bold','helvetica-oblique','helvetica-boldoblique',
  'times-roman','times-bold','times-italic','times-bolditalic',
  'courier','courier-bold','courier-oblique','courier-boldoblique',
  'symbol','zapfdingbats'
]);

const normalizeFontName = (name) => {
  if (!name) return '';
  let n = String(name).trim();
  n = n.replace(/^\/+/, '');
  n = n.replace(/^[A-Z]{6}\\+/, '');
  n = n.replace(/\\s+/g, ' ');
  n = n.toLowerCase().trim();
  n = n.replace(/[^a-z0-9._-]+/g, '_').replace(/^[_\\.-]+|[_\\.-]+$/g, '');
  return n;
};

const pdfNameToString = (pdfName) => {
  if (!pdfName) return '';
  if (typeof pdfName.decodeText === 'function') return pdfName.decodeText();
  if (typeof pdfName.asString === 'function') return pdfName.asString();
  if (typeof pdfName.value === 'string') return pdfName.value;
  const s = String(pdfName);
  if (s[0] === '/') return s.slice(1);
  return s;
};

const pdfStringToText = (val) => {
  if (!val) return '';
  if (typeof val.decodeText === 'function') return val.decodeText();
  if (typeof val.asString === 'function') return val.asString();
  if (typeof val.value === 'string') return val.value;
  return String(val);
};

const parseDaFontKey = (da) => {
  if (!da) return '';
  const match = /\\/([^\\s]+)\\s+[\\d.]+\\s+Tf/.exec(da);
  return match ? match[1] : '';
};

function getFieldDefaultAppearance(field, PDFName) {
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

function resolveBaseFontName(field, fontKey, PDFName, form) {
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

async function loadManifest() {
  const resp = await fetch(FONT_MANIFEST_URL, { credentials: 'same-origin' });
  if (!resp.ok) throw new Error('Font manifest not available.');
  const data = await resp.json();
  const map = new Map();
  (data.fonts || []).forEach((f) => {
    const key = normalizeFontName(f.name || f.key || '');
    if (!key) return;
    map.set(key, f);
  });
  return map;
}

async function extractFontsFromTemplate(templateId) {
  const url = TEMPLATE_FILE_URL + encodeURIComponent(templateId);
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error('Template not found.');
  const bytes = new Uint8Array(await res.arrayBuffer());
  const PDFLib = window.PDFLib;
  const { PDFDocument, PDFName } = PDFLib;
  const pdfDoc = await PDFDocument.load(bytes);
  const form = pdfDoc.getForm();
  const fonts = new Set();
  const fields = form.getFields();
  for (const field of fields) {
    const da = getFieldDefaultAppearance(field, PDFName);
    const fontKey = parseDaFontKey(da);
    const baseName = resolveBaseFontName(field, fontKey, PDFName, form);
    const norm = normalizeFontName(baseName);
    if (!norm) continue;
    if (!STANDARD_FONTS.has(norm)) fonts.add(norm);
  }
  return Array.from(fonts);
}

if (fontFileInput) {
  fontFileInput.addEventListener('change', async () => {
    const file = fontFileInput.files && fontFileInput.files[0];
    if (!file) return;
    const buf = await file.arrayBuffer();
    const font = window.fontkit.create(new Uint8Array(buf));
    const name = font.postscriptName || font.fullName || font.familyName || file.name;
    fontNameInput.value = name;
    fontFamilyInput.value = font.familyName || '';
    fontFileHint.textContent = `Name: ${name}`;
  });
}

if (btnCheckFonts) {
  btnCheckFonts.addEventListener('click', async () => {
    fontMissingList.innerHTML = '';
    fontCheckResults.textContent = '';
    const templateId = Number(fontTemplateSelect?.value || 0);
    if (!templateId) {
      fontCheckResults.textContent = <?=json_encode(t('admin.fonts.error.template_missing'))?>;
      return;
    }
    try {
      fontCheckResults.textContent = <?=json_encode(t('admin.fonts.checking'))?>;
      const [required, available] = await Promise.all([
        extractFontsFromTemplate(templateId),
        loadManifest()
      ]);

      if (!required.length) {
        fontCheckResults.textContent = <?=json_encode(t('admin.fonts.none_required'))?>;
        return;
      }

      const missing = required.filter((name) => !available.has(name));
      fontCheckResults.textContent = <?=json_encode(t('admin.fonts.check_done'))?>;
      const list = document.createElement('ul');
      missing.forEach((name) => {
        const li = document.createElement('li');
        li.textContent = name;
        list.appendChild(li);
      });
      if (missing.length) {
        const title = document.createElement('div');
        title.textContent = <?=json_encode(t('admin.fonts.missing_heading'))?>;
        title.style.fontWeight = '600';
        fontMissingList.appendChild(title);
        fontMissingList.appendChild(list);
      } else {
        fontMissingList.textContent = <?=json_encode(t('admin.fonts.none_missing'))?>;
      }
    } catch (e) {
      fontCheckResults.textContent = (e?.message || e);
    }
  });
}
</script>

<?php render_admin_footer(); ?>
