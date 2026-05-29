<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();
require_once __DIR__ . '/../shared/generated_template_packages.php';
require_once __DIR__ . '/../shared/template_import.php';

$pdo = db();
ensure_generated_template_packages_table($pdo);
ensure_generated_template_package_storage_dir();

function tp_decode_json(?string $json): array {
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function tp_bool_label($value): string {
    return !empty($value) ? 'Ja' : 'Nein';
}

function tp_package_summary(array $pkg): array {
    $meta = tp_decode_json($pkg['metadata_json'] ?? null);
    $rcff = tp_decode_json($pkg['rcff_json'] ?? null);
    return [
        'metadata' => $meta,
        'rcff' => $rcff,
        'field_count' => is_array($rcff['fields'] ?? null) ? count($rcff['fields']) : 0,
        'suggested_name' => trim((string)($meta['title'] ?? $pkg['title'] ?? '')) ?: 'Importierte Vorlage',
    ];
}

$msg = '';
$err = '';
$importedTemplateId = 0;
$importStats = null;
$cleanupPdf = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'import_package') {
            throw new RuntimeException('Unbekannte Aktion.');
        }

        $packageId = (int)($_POST['package_id'] ?? 0);
        $templateName = trim((string)($_POST['template_name'] ?? ''));
        $useRcff = (string)($_POST['apply_rcff'] ?? '1') === '1';
        $fieldsJson = (string)($_POST['fields_json'] ?? '');
        if ($packageId <= 0) throw new RuntimeException('Paket fehlt.');
        if ($templateName === '') throw new RuntimeException('Template-Name darf nicht leer sein.');
        if ((function_exists('mb_strlen') ? mb_strlen($templateName) : strlen($templateName)) > 255) throw new RuntimeException('Template-Name ist zu lang.');
        $fields = json_decode($fieldsJson, true);
        if (!is_array($fields) || !$fields) throw new RuntimeException('PDF-Formularfelder konnten nicht gelesen werden.');

        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM generated_template_packages WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([$packageId]);
        $pkg = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$pkg) throw new RuntimeException('Template-Paket nicht gefunden.');
        $status = (string)($pkg['status'] ?? '');
        if (!in_array($status, ['draft', 'submitted'], true)) {
            throw new RuntimeException('Dieses Paket kann nicht übernommen werden (Status: ' . $status . ').');
        }

        $pdfAbs = generated_template_package_pdf_absolute_path((string)$pkg['pdf_path']);
        $expectedSha = trim((string)($pkg['pdf_sha256'] ?? ''));
        if ($expectedSha !== '') {
            $actualSha = hash_file('sha256', $pdfAbs) ?: '';
            if (!hash_equals($expectedSha, $actualSha)) {
                throw new RuntimeException('SHA-256-Prüfung der Paket-PDF ist fehlgeschlagen.');
            }
        }

        $rcff = tp_decode_json($pkg['rcff_json'] ?? null);
        if ($useRcff) {
            if (($rcff['format'] ?? '') !== 'rcff' || (int)($rcff['version'] ?? 0) !== 1 || !is_array($rcff['fields'] ?? null)) {
                throw new RuntimeException('Gespeicherte RCFF-Daten sind ungültig.');
            }
        }

        $origFilename = (string)($pkg['pdf_filename'] ?? 'vorlage.pdf');
        if (!preg_match('/\.pdf$/i', $origFilename)) $origFilename = 'vorlage.pdf';
        $created = create_template_from_pdf_file($pdo, $pdfAbs, $templateName, $origFilename, (int)current_user()['id']);
        $cleanupPdf = $created['pdf_abs_path'] ?? null;
        $templateId = (int)$created['template_id'];

        $fieldCount = import_pdf_fields_to_template($pdo, $templateId, $fields);
        $rcffStats = $useRcff ? apply_rcff_to_template_fields($pdo, $templateId, $rcff) : null;

        $metadata = tp_decode_json($pkg['metadata_json'] ?? null);
        $metadata['import'] = [
            'imported_by_user_id' => (int)current_user()['id'],
            'imported_template_id' => $templateId,
            'imported_at' => gmdate('c'),
            'applied_rcff' => $useRcff,
            'field_count' => $fieldCount,
            'rcff_stats' => $rcffStats,
        ];
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) $metadataJson = (string)$pkg['metadata_json'];

        $upd = $pdo->prepare('UPDATE generated_template_packages SET status=\'imported\', imported_template_id=?, imported_at=NOW(), metadata_json=? WHERE id=?');
        $upd->execute([$templateId, $metadataJson, $packageId]);

        audit('generated_template_package_import', (int)current_user()['id'], [
            'package_id' => $packageId,
            'template_id' => $templateId,
            'field_count' => $fieldCount,
            'rcff_stats' => $rcffStats,
        ]);

        $pdo->commit();
        $cleanupPdf = null;
        $importedTemplateId = $templateId;
        $importStats = ['fields' => $fieldCount, 'rcff' => $rcffStats];
        $msg = 'Template wurde angelegt.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_string($cleanupPdf) && $cleanupPdf !== '' && is_file($cleanupPdf)) {
            @unlink($cleanupPdf);
            @rmdir(dirname($cleanupPdf));
        }
        $err = $e->getMessage();
    }
}

$confirmId = (int)($_GET['import'] ?? 0);
$confirmPkg = null;
$confirmSummary = null;
if ($confirmId > 0) {
    $st = $pdo->prepare('SELECT p.*, u.display_name AS created_by_name, u.email AS created_by_email FROM generated_template_packages p LEFT JOIN users u ON u.id=p.created_by_user_id WHERE p.id=? LIMIT 1');
    $st->execute([$confirmId]);
    $confirmPkg = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($confirmPkg) $confirmSummary = tp_package_summary($confirmPkg);
}

$packages = $pdo->query("SELECT p.*, u.display_name AS created_by_name, u.email AS created_by_email FROM generated_template_packages p LEFT JOIN users u ON u.id=p.created_by_user_id ORDER BY p.created_at DESC, p.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_admin_header('Template-Pakete');
?>

<h1>Template-Pakete</h1>
<p class="muted">Vorbereitete PDF+RCFF-Pakete aus der LaTeX-Vorlagengenerierung anzeigen und als echte PDF-Templates übernehmen.</p>

<?php if ($msg): ?>
  <div class="card" style="border-left:4px solid #067647;">
    <?=h($msg)?>
    <?php if ($importedTemplateId > 0): ?>
      <a class="btn primary" href="<?=h(url('admin/template_fields.php?template_id=' . $importedTemplateId))?>">Felder bearbeiten</a>
    <?php endif; ?>
    <?php if (is_array($importStats)): ?>
      <div class="muted" style="margin-top:8px;">PDF-Felder: <?=h((string)($importStats['fields'] ?? 0))?><?php if (is_array($importStats['rcff'] ?? null)): ?> · RCFF: <?=h((string)(($importStats['rcff']['matched'] ?? 0)))?> Treffer, <?=h((string)(($importStats['rcff']['ignored'] ?? 0)))?> ignoriert<?php endif; ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($err): ?><div class="card" style="border-left:4px solid #b42318;"><?=h($err)?></div><?php endif; ?>

<?php if ($confirmPkg && $confirmSummary): ?>
  <?php $canImport = in_array((string)$confirmPkg['status'], ['draft','submitted'], true); ?>
  <div class="card" style="border-left:4px solid <?= $canImport ? '#0b57d0' : '#b42318' ?>;">
    <h2>Template-Paket übernehmen</h2>
    <p><strong><?=h((string)$confirmPkg['title'])?></strong></p>
    <p class="muted">Status: <?=h((string)$confirmPkg['status'])?> · RCFF-Felder: <?=h((string)$confirmSummary['field_count'])?> · Paket #<?=h((string)$confirmPkg['id'])?></p>
    <?php if ($canImport): ?>
      <form method="post" id="importPackageForm">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="import_package">
        <input type="hidden" name="package_id" value="<?=h((string)$confirmPkg['id'])?>">
        <input type="hidden" name="fields_json" id="fieldsJson" value="">
        <label style="display:block;margin:10px 0;">
          <strong>Template-Name</strong><br>
          <input class="input" name="template_name" maxlength="255" required value="<?=h((string)$confirmSummary['suggested_name'])?>" style="max-width:620px;">
        </label>
        <label style="display:block;margin:10px 0;">
          <input type="hidden" name="apply_rcff" value="0">
          <input type="checkbox" name="apply_rcff" value="1" checked> Feldbeschriftungen, Gruppen/Untergruppen und Rating-Metadaten aus RCFF übernehmen
        </label>
        <p id="extractStatus" class="muted">PDF-Formularfelder werden ausgelesen …</p>
        <button class="btn primary" id="importSubmit" type="submit" disabled>Als Template übernehmen</button>
        <a class="btn secondary" href="<?=h(url('admin/template_packages.php'))?>">Abbrechen</a>
      </form>
      <iframe id="packagePdfPreview" src="<?=h(url('admin/template_package_file.php?package_id=' . (int)$confirmPkg['id']))?>" style="width:100%;height:70vh;margin-top:14px;border:1px solid var(--border);border-radius:10px;"></iframe>
      <script type="module">
      import * as pdfjsLib from <?=json_encode(url('assets/pdfjs/pdf.min.mjs'))?>;
      pdfjsLib.GlobalWorkerOptions.workerSrc = <?=json_encode(url('assets/pdfjs/pdf.worker.min.mjs'))?>;
      const pdfUrl = <?=json_encode(url('admin/template_package_file.php?package_id=' . (int)$confirmPkg['id']))?>;
      const statusEl = document.getElementById('extractStatus');
      const fieldsEl = document.getElementById('fieldsJson');
      const submitEl = document.getElementById('importSubmit');
      const formEl = document.getElementById('importPackageForm');
      const FIELD_TYPES = ['text','multiline','date','number','grade','checkbox','radio','select','signature'];
      function normalizeType(rawType, multilineFlag){
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
      async function extractFieldsFromPdf(){
        const pdf = await pdfjsLib.getDocument({ url: pdfUrl, withCredentials:true }).promise;
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
              out.set(String(name), { name:String(name), type, label:String(name), help_text:'', multiline:multilineFlag, sort:sort++, meta:{ type:rawType, multiline:multilineFlag } });
            }
          }
        }
        for (let p=1; p<=pdf.numPages; p++) {
          const page = await pdf.getPage(p);
          const annots = await page.getAnnotations({ intent:'display' });
          for (const a of annots) {
            if (a.subtype !== 'Widget') continue;
            const name = String(a.fieldName || '').trim();
            if (!name) continue;
            const rect = Array.isArray(a.rect) && a.rect.length === 4 ? a.rect : null;
            const rawType = a.fieldType || a.type || '';
            let type = normalizeType(rawType, false);
            if (a.radioButton === true) type = 'radio';
            if (a.checkBox === true) type = 'checkbox';
            const hint = (a.alternativeText || a.altText || a.tooltip || a.title || a.fieldLabel || '')?.toString?.() || '';
            if (!out.has(name)) {
              out.set(name, { name, type: FIELD_TYPES.includes(type) ? type : 'radio', label:name, help_text:hint || '', multiline:false, sort:sort++, meta:{ type:rawType } });
            } else {
              const item = out.get(name);
              if (item && type === 'radio') item.type = 'radio';
              if (item && !item.help_text && hint) item.help_text = hint;
            }
            const item = out.get(name);
            if (item && rect) {
              item.meta = item.meta || {};
              if (!item.meta.page) item.meta.page = p;
              if (!item.meta.rect) item.meta.rect = rect;
            }
          }
        }
        return Array.from(out.values()).sort((a,b)=>(a.sort ?? 0)-(b.sort ?? 0)).map((f,i)=>({
          ...f,
          sort:i,
          can_child_edit:0,
          can_teacher_edit:1,
          label:f.label || f.name,
          help_text:f.help_text || '',
          type:FIELD_TYPES.includes(f.type) ? f.type : 'radio'
        }));
      }
      try {
        const fields = await extractFieldsFromPdf();
        fieldsEl.value = JSON.stringify(fields);
        statusEl.textContent = fields.length + ' PDF-Formularfelder erkannt. Der Import kann gestartet werden.';
        submitEl.disabled = fields.length === 0;
      } catch (e) {
        statusEl.textContent = 'PDF-Formularfelder konnten nicht gelesen werden: ' + (e && e.message ? e.message : e);
        submitEl.disabled = true;
      }
      formEl.addEventListener('submit', (event)=>{
        if (!fieldsEl.value) {
          event.preventDefault();
          alert('Bitte warten, bis die PDF-Formularfelder ausgelesen wurden.');
        }
      });
      </script>
    <?php else: ?>
      <p>Dieses Paket kann nicht übernommen werden.</p>
    <?php endif; ?>
  </div>
<?php elseif ($confirmId > 0): ?>
  <div class="card" style="border-left:4px solid #b42318;">Paket nicht gefunden.</div>
<?php endif; ?>

<div class="card">
  <h2>Vorbereitete Pakete</h2>
  <?php if (!$packages): ?>
    <p class="muted">Noch keine Template-Pakete vorhanden.</p>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="min-width:1100px;width:100%;">
        <thead><tr><th>ID</th><th>Titel</th><th>Status</th><th>Erstellt</th><th>Erstellt von</th><th>Rolle</th><th>Klasse</th><th>Layout</th><th>S/T</th><th>SEL/AG</th><th>RCFF</th><th>Ablauf</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($packages as $pkg): $summary = tp_package_summary($pkg); $meta = $summary['metadata']; $can = in_array((string)$pkg['status'], ['draft','submitted'], true); ?>
          <tr>
            <td><?=h((string)$pkg['id'])?></td>
            <td><?=h((string)$pkg['title'])?></td>
            <td><?=h((string)$pkg['status'])?></td>
            <td><?=h((string)$pkg['created_at'])?></td>
            <td><?=h(trim((string)($pkg['created_by_name'] ?? '')) ?: (string)($pkg['created_by_email'] ?? ('#' . $pkg['created_by_user_id'])))?></td>
            <td><?=h((string)$pkg['created_by_role'])?></td>
            <td><?=h((string)($meta['grade_level'] ?? ''))?></td>
            <td><?=h((string)($meta['layout_template_name'] ?? ''))?></td>
            <td><?=h(tp_bool_label($meta['student_teacher_ratings'] ?? false))?></td>
            <td><?=h('SEL ' . tp_bool_label($meta['show_sel'] ?? false) . ' / AG ' . tp_bool_label($meta['show_ag'] ?? false))?></td>
            <td><?=h((string)$summary['field_count'])?></td>
            <td><?=h((string)$pkg['expires_at'])?></td>
            <td style="white-space:nowrap;">
              <?php if ($can): ?>
                <a class="btn primary" href="<?=h(url('admin/template_packages.php?import=' . (int)$pkg['id']))?>">Als Template übernehmen</a>
              <?php endif; ?>
              <?php if ((int)($pkg['imported_template_id'] ?? 0) > 0): ?>
                <a class="btn secondary" href="<?=h(url('admin/template_fields.php?template_id=' . (int)$pkg['imported_template_id']))?>">Template öffnen</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_admin_footer(); ?>
