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
      if ($templateId <= 0) throw new RuntimeException(t('admin.templates.error.invalid_template'));

      $st = $pdo->prepare("SELECT is_active FROM templates WHERE id=? LIMIT 1");
      $st->execute([$templateId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException(t('admin.templates.error.not_found'));

      $cur = (int)($row['is_active'] ?? 0);
      $next = $cur === 1 ? 0 : 1;

      $pdo->prepare("UPDATE templates SET is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
          ->execute([$next, $templateId]);

      audit('template_toggle_active', (int)current_user()['id'], ['template_id' => $templateId, 'is_active' => $next]);
      $ok = $next === 1
        ? str_replace('{id}', (string)$templateId, t('admin.templates.status.activated'))
        : str_replace('{id}', (string)$templateId, t('admin.templates.status.deactivated'));
    }



    if ($action === 'rename') {
      $templateId = (int)($_POST['template_id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      if ($templateId <= 0) throw new RuntimeException(t('admin.templates.error.invalid_template'));
      if ($name === '') throw new RuntimeException(t('admin.templates.error.name_missing'));

      $st = $pdo->prepare("SELECT id FROM templates WHERE id=? LIMIT 1");
      $st->execute([$templateId]);
      if (!$st->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException(t('admin.templates.error.not_found'));

      $pdo->prepare("UPDATE templates SET name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
          ->execute([$name, $templateId]);

      audit('template_rename', (int)current_user()['id'], ['template_id' => $templateId]);
      $ok = str_replace('{id}', (string)$templateId, t('admin.templates.status.renamed'));
    }

    if ($action === 'delete') {
      $templateId = (int)($_POST['template_id'] ?? 0);
      if ($templateId <= 0) throw new RuntimeException(t('admin.templates.error.invalid_template'));

      $st = $pdo->prepare("SELECT id, pdf_storage_path FROM templates WHERE id=? LIMIT 1");
      $st->execute([$templateId]);
      $tpl = $st->fetch(PDO::FETCH_ASSOC);
      if (!$tpl) throw new RuntimeException(t('admin.templates.error.not_found'));

      $stClass = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE template_id=?");
      $stClass->execute([$templateId]);
      $classCount = (int)$stClass->fetchColumn();

      $stReports = $pdo->prepare("SELECT COUNT(*) FROM report_instances WHERE template_id=?");
      $stReports->execute([$templateId]);
      $reportCount = (int)$stReports->fetchColumn();

      if ($classCount > 0 || $reportCount > 0) {
        $msg = t('admin.templates.error.delete_in_use');
        $msg = str_replace('{classes}', (string)$classCount, $msg);
        $msg = str_replace('{reports}', (string)$reportCount, $msg);
        throw new RuntimeException($msg);
      }

      $pdo->prepare("DELETE FROM templates WHERE id=?")->execute([$templateId]);

      $pdfRel = (string)($tpl['pdf_storage_path'] ?? '');
      if ($pdfRel !== '') {
        $cfg = app_config();
        $uploadsRel = (string)($cfg['app']['uploads_dir'] ?? 'uploads');
        $rootAbs = realpath(__DIR__ . '/..');
        if ($rootAbs) {
          $pdfAbs = $rootAbs . '/' . ltrim($pdfRel, '/');
          if (is_file($pdfAbs)) @unlink($pdfAbs);
          $tplDirAbs = dirname($pdfAbs);
          if (is_dir($tplDirAbs)) @rmdir($tplDirAbs);
        }
      }

      audit('template_delete', (int)current_user()['id'], ['template_id' => $templateId]);
      $ok = str_replace('{id}', (string)$templateId, t('admin.templates.status.deleted'));
    }

    if ($action === 'upload') {
      $name = trim((string)($_POST['name'] ?? ''));
      $version = (int)($_POST['version'] ?? 1);

      if ($name === '') throw new RuntimeException(t('admin.templates.error.name_missing'));
      if ($version < 1) throw new RuntimeException(t('admin.templates.error.invalid_version'));

      if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('admin.templates.error.pdf_required'));
      }

      $tmp = $_FILES['pdf']['tmp_name'];
      $origName = (string)($_FILES['pdf']['name'] ?? 'template.pdf');
      if (!preg_match('/\.pdf$/i', $origName)) throw new RuntimeException(t('admin.templates.error.not_pdf'));

      $sha = hash_file('sha256', $tmp) ?: null;

      $cfg = app_config();
      $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';
      $rootAbs = realpath(__DIR__ . '/..');
      if (!$rootAbs) throw new RuntimeException(t('admin.templates.error.root_missing'));
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

      if (!move_uploaded_file($tmp, $destAbs)) throw new RuntimeException(t('admin.templates.error.save_failed'));

      $pdo->prepare("UPDATE templates SET pdf_storage_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
          ->execute([$destRel, $tplId]);

      audit('template_upload', (int)current_user()['id'], ['template_id'=>$tplId]);
      $ok = str_replace('{id}', (string)$tplId, t('admin.templates.status.uploaded'));
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

render_admin_header(t('admin.templates.title'));
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

<div class="card"><h1><?=h(t('admin.templates.heading'))?></h1></div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card">
  <h2><?=h(t('admin.templates.upload_heading'))?></h2>
  <form id="uploadTemplateForm" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="upload">
    <div class="grid">
      <div>
        <label><?=h(t('admin.templates.label.name'))?></label>
        <input name="name" required placeholder="<?=h(t('admin.templates.placeholder.name'))?>">
      </div>
      <div>
        <label><?=h(t('admin.templates.label.version'))?></label>
        <input name="version" type="number" min="1" value="1" required>
      </div>
    </div>
    <label><?=h(t('admin.templates.label.pdf'))?></label>
    <div class="actions actions-row">
      <input class="file-input" type="file" name="pdf" accept=".pdf,application/pdf" required>
      <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;"><?=h(t('admin.templates.upload_button'))?></a>
    </div>
  </form>
</div>

<div class="card">
  <h2><?=h(t('admin.templates.list_heading'))?></h2>
  <?php if (!$templates): ?>
    <p class="muted"><?=h(t('admin.templates.list_empty'))?></p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th><?=h(t('admin.templates.table.id'))?></th>
          <th><?=h(t('admin.templates.table.name'))?></th>
          <th><?=h(t('admin.templates.table.version'))?></th>
          <th><?=h(t('admin.templates.table.status'))?></th>
          <th><?=h(t('admin.templates.table.pdf'))?></th>
          <th><?=h(t('admin.templates.table.actions'))?></th>
        </tr>
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
                        title="<?=h($isActive ? t('admin.templates.status.deactivate_title') : t('admin.templates.status.activate_title'))?>">
                  <?=h($isActive ? t('admin.templates.status.active') : t('admin.templates.status.inactive'))?>
                </button>
              </form>
            </td>
            <td>
              <a href="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>" target="_blank">
                <?=h($t['pdf_original_filename'] ?: t('admin.templates.pdf_fallback'))?>
              </a>
            </td>
            <td>
              <div class="action-menu">
                <button class="btn secondary action-menu-toggle" type="button" aria-haspopup="menu" aria-expanded="false">
                  <?=h(t('admin.templates.table.actions'))?> <span aria-hidden="true">▾</span>
                </button>
                <template class="action-menu-template">
                  <a class="btn primary js-extract" type="button"
                     data-template-id="<?=h((string)$t['id'])?>"
                     data-pdf-url="<?=h(url('admin/file.php?template_id='.(int)$t['id']))?>"><?=h(t('admin.templates.action.extract_fields'))?></a>
                  <a class="btn primary" href="<?=h(url('admin/template_fields.php?template_id='.(int)$t['id']))?>"><?=h(t('admin.templates.action.edit'))?></a>
                  <a class="btn secondary" href="<?=h(url('admin/template_mappings.php?template_id='.(int)$t['id']))?>"><?=h(t('admin.templates.action.mapping'))?></a>
                  <form method="post" onsubmit="const n = prompt(<?=h(json_encode(t('admin.templates.prompt.rename')) )?>, <?=h(json_encode((string)$t['name']))?>); if (n === null) return false; this.querySelector('input[name=\"name\"]').value = n; return true;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"> 
                    <input type="hidden" name="action" value="rename"> 
                    <input type="hidden" name="template_id" value="<?=h((string)$t['id'])?>"> 
                    <input type="hidden" name="name" value=""> 
                    <button class="btn secondary" type="submit"><?=h(t('admin.templates.action.rename'))?></button>
                  </form>
                  <form method="post" onsubmit="return confirm(<?=h(json_encode(str_replace('{id}', (string)$t['id'], t('admin.templates.confirm.delete'))))?>);">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="template_id" value="<?=h((string)$t['id'])?>">
                    <button class="btn danger" type="submit"><?=h(t('admin.templates.action.delete'))?></button>
                  </form>
                </template>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div id="rowActionMenu" class="action-dropdown-menu hidden" role="menu" aria-hidden="true"></div>

<div class="card" id="wizard" style="display:none;">
  <h2><?=h(t('admin.templates.wizard.heading'))?></h2>
  <p class="muted" id="wizMeta"></p>

  <div class="actions" style="margin-top:12px; flex-wrap:wrap;">
    <button class="btn secondary" id="btnChildNone" type="button"><?=h(t('admin.templates.wizard.child_none'))?></button>
    <button class="btn secondary" id="btnChildAll" type="button"><?=h(t('admin.templates.wizard.child_all'))?></button>
    <button class="btn secondary" id="btnTeachNone" type="button"><?=h(t('admin.templates.wizard.teacher_none'))?></button>
    <button class="btn secondary" id="btnTeachAll" type="button"><?=h(t('admin.templates.wizard.teacher_all'))?></button>

    <button class="btn secondary" id="btnTogglePreview" type="button"><?=h(t('admin.templates.wizard.preview_hide'))?></button>

    <button class="btn primary" id="btnImport" type="button"><?=h(t('admin.templates.wizard.import_button'))?></button>
    <button class="btn secondary" id="btnCancel" type="button"><?=h(t('admin.templates.wizard.cancel_button'))?></button>
  </div>

  <div class="copybar">
    <div class="block">
      <label><?=h(t('admin.templates.copy.title'))?></label>
      <select id="copyFromTemplate">
        <option value=""><?=h(t('admin.templates.copy.none_option'))?></option>
        <?php foreach ($templates as $t): ?>
          <option value="<?=h((string)$t['id'])?>">
            #<?=h((string)$t['id'])?> · <?=h($t['name'])?> v<?=h((string)$t['template_version'])?>
            <?=((int)($t['is_active'] ?? 0) === 1 ? '' : h(t('admin.templates.status.inactive_suffix')))?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="muted small"><?=h(t('admin.templates.copy.match_hint'))?></div>
    </div>

    <div class="block" style="min-width:520px;">
      <div class="muted small" style="margin-bottom:6px;"><?=h(t('admin.templates.copy.select_hint'))?></div>
      <div class="copyopts">
        <label><input type="checkbox" id="cpType" checked> <?=h(t('admin.templates.copy.type'))?></label>
        <label><input type="checkbox" id="cpLabel" checked> <?=h(t('admin.templates.copy.label'))?></label>
        <label><input type="checkbox" id="cpHelp" checked> <?=h(t('admin.templates.copy.help'))?></label>
        <label><input type="checkbox" id="cpRights" checked> <?=h(t('admin.templates.copy.rights'))?></label>
        <label><input type="checkbox" id="cpMeta" checked> <?=h(t('admin.templates.copy.meta'))?></label>
      </div>
      <div class="muted small" style="margin-top:6px;">
        <?=h(t('admin.templates.copy.meta_hint'))?>
      </div>
    </div>

    <div class="actions">
      <button class="btn secondary" id="btnCopyVisible" type="button"><?=h(t('admin.templates.copy.apply_visible'))?></button>
      <button class="btn secondary" id="btnCopyAll" type="button"><?=h(t('admin.templates.copy.apply_all'))?></button>
    </div>

    <div class="muted small" id="copyResult" style="min-width:220px;">&nbsp;</div>
  </div>

  <details class="card expert-settings" id="expertSettings" style="margin-top:12px;">
    <summary><?=h(t('admin.templates.parser.heading'))?></summary>
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
      <div>
        <label for="parsePadLeft">padLeft</label>
        <input id="parsePadLeft" type="number" min="0" step="1" value="18">
        <div class="muted small"><?=h(t('admin.templates.parser.pad_left_hint'))?></div>
      </div>
      <div>
        <label for="parseYtol">yTol</label>
        <input id="parseYtol" type="number" min="0" step="1" value="14">
        <div class="muted small"><?=h(t('admin.templates.parser.y_tol_hint'))?></div>
      </div>
      <div>
        <label for="parseLineCluster">yLineCluster</label>
        <input id="parseLineCluster" type="number" min="0" step="1" value="7">
        <div class="muted small"><?=h(t('admin.templates.parser.line_cluster_hint'))?></div>
      </div>
      <div>
        <label for="parseGapWord">gapWord</label>
        <input id="parseGapWord" type="number" min="0" step="1" value="4">
        <div class="muted small"><?=h(t('admin.templates.parser.word_gap_hint'))?></div>
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
      <?=h(t('admin.templates.parser.tip'))?>
    </div>
  </details>

  <div class="grid" id="wizGrid" style="grid-template-columns: 1.2fr 0.8fr; gap:14px; margin-top:12px;">
    <div style="overflow:hidden;">
      <div class="grid" style="grid-template-columns: 1fr 200px; gap:12px; align-items:end;">
        <div>
          <label><?=h(t('admin.templates.filter.label'))?></label>
          <input id="fieldFilter" placeholder="<?=h(t('admin.templates.filter.placeholder'))?>">
          <div class="muted small"><?=h(t('admin.templates.filter.hint'))?></div>
        </div>
        <div class="actions" style="justify-content:flex-start;">
          <button class="btn secondary" type="button" id="btnClearFilter"><?=h(t('admin.templates.filter.clear'))?></button>
        </div>
      </div>

      <div class="table-scroll" style="margin-top:10px;">
        <table id="fieldsTbl">
          <thead>
            <tr>
              <th class="col-child"><?=h(t('admin.templates.table.child'))?></th>
              <th class="col-teach"><?=h(t('admin.templates.table.teacher'))?></th>
              <th class="col-name"><?=h(t('admin.templates.table.field_name'))?></th>
              <th class="col-type"><?=h(t('admin.templates.table.type'))?></th>
              <th class="col-label"><?=h(t('admin.templates.table.label'))?></th>
              <th class="col-help"><?=h(t('admin.templates.table.help'))?></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <p class="muted small" style="margin-top:8px;">
        <?=t('admin.templates.type_hint')?>
      </p>
    </div>

    <div id="wizPreviewCol">
      <div class="card wiz-preview" style="margin:0;">
        <h3 style="margin-top:0;"><?=h(t('admin.templates.preview.heading'))?></h3>
        <div class="muted" id="pdfHint"><?=h(t('admin.templates.preview.hint_default'))?></div>

        <div style="display:flex; gap:8px; align-items:center; margin:10px 0; flex-wrap:wrap;">
          <button class="btn secondary" id="btnPrevPage" type="button">←</button>
          <div class="muted" id="pageInfo"><?=h(t('admin.templates.preview.page_placeholder'))?></div>
          <button class="btn secondary" id="btnNextPage" type="button">→</button>
          <button class="btn secondary" id="btnToggleHighlights" type="button"><?=h(t('admin.templates.preview.highlight_on'))?></button>
        </div>

        <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden;">
          <canvas id="pdfCanvas" style="display:block; width:100%; height:auto;"></canvas>
        </div>

        <div class="muted small" style="margin-top:10px;">
          <?=h(t('admin.templates.preview.tip'))?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const menu = document.getElementById('rowActionMenu');
  if (!menu) return;

  let currentButton = null;

  const closeMenu = () => {
    if (!currentButton) return;
    currentButton.setAttribute('aria-expanded', 'false');
    currentButton = null;
    menu.classList.add('hidden');
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    menu.innerHTML = '';
  };

  const positionMenu = () => {
    if (!currentButton) return;
    const rect = currentButton.getBoundingClientRect();
    const scrollX = window.scrollX || window.pageXOffset;
    const scrollY = window.scrollY || window.pageYOffset;
    const maxLeft = scrollX + document.documentElement.clientWidth - menu.offsetWidth - 8;
    const left = Math.max(scrollX + 8, Math.min(rect.right + scrollX - menu.offsetWidth, maxLeft));
    menu.style.left = `${left}px`;
    menu.style.top = `${rect.bottom + scrollY}px`;
  };

  const openMenu = (button, template) => {
    if (currentButton === button) {
      closeMenu();
      return;
    }
    if (currentButton) {
      currentButton.setAttribute('aria-expanded', 'false');
    }
    menu.innerHTML = '';
    if (template && template.content) {
      menu.appendChild(template.content.cloneNode(true));
    }
    menu.classList.remove('hidden');
    menu.classList.add('open');
    menu.setAttribute('aria-hidden', 'false');
    button.setAttribute('aria-expanded', 'true');
    currentButton = button;
    positionMenu();
  };

  document.addEventListener('click', function (event) {
    const button = event.target.closest('.action-menu-toggle');
    if (button) {
      event.preventDefault();
      const wrapper = button.closest('.action-menu');
      const template = wrapper ? wrapper.querySelector('.action-menu-template') : null;
      openMenu(button, template);
      return;
    }
    if (menu.contains(event.target)) return;
    closeMenu();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
    }
  });

  window.addEventListener('resize', closeMenu);
  window.addEventListener('scroll', closeMenu, true);
});
</script>

<script type="module">
import * as pdfjsLib from "<?=h(url('assets/pdfjs/pdf.min.mjs'))?>";
pdfjsLib.GlobalWorkerOptions.workerSrc = "<?=h(url('assets/pdfjs/pdf.worker.min.mjs'))?>";

const csrf = "<?=h(csrf_token())?>";
const I18N = <?=json_encode([
  'preview_hide' => t('admin.templates.wizard.preview_hide'),
  'preview_show' => t('admin.templates.wizard.preview_show'),
  'highlight_on' => t('admin.templates.preview.highlight_on'),
  'highlight_off' => t('admin.templates.preview.highlight_off'),
  'pdf_no_rect' => t('admin.templates.preview.no_rect'),
  'loading' => t('admin.templates.loading'),
  'wizard_meta' => t('admin.templates.wizard.meta'),
  'copy_source_required' => t('admin.templates.copy.source_required'),
  'copy_visible_done' => t('admin.templates.copy.visible_done'),
  'copy_all_done' => t('admin.templates.copy.all_done'),
  'error_prefix' => t('admin.templates.error.prefix'),
  'extract_error' => t('admin.templates.error.extract_failed'),
  'wizard_missing_id' => t('admin.templates.error.wizard_missing_id'),
  'import_failed' => t('admin.templates.error.import_failed'),
  'import_ok' => t('admin.templates.status.import_ok'),
  'import_error' => t('admin.templates.error.import_error')
], JSON_UNESCAPED_UNICODE)?>;
const tTpl = (key) => I18N[key] ?? key;
const tfmtTpl = (key, vars = {}) => {
  let base = tTpl(key);
  Object.entries(vars).forEach(([k, v]) => {
    base = base.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
  });
  return base;
};

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
  wizMeta.textContent = tfmtTpl('wizard_meta', {
    id: String(currentTemplateId),
    total: String(n),
    visible: String(visible),
    child: String(cChild),
    teacher: String(cTeach)
  });
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
        pdfHint.textContent = tfmtTpl('pdf_no_rect', { name: f.name });
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
  btnToggleHighlights.textContent = showAllWidgetHighlights ? tTpl('highlight_on') : tTpl('highlight_off');
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
    if (!fromId){ copyResult.textContent = tTpl('copy_source_required'); return; }
    copyResult.textContent = tTpl('loading');
    const map = await fetchTemplateFieldsMap(fromId);
    const n = applyFromSourceMap(map, true);
    copyResult.textContent = tfmtTpl('copy_visible_done', { count: String(n) });
  } catch(e){
    copyResult.textContent = tTpl('error_prefix') + (e && e.message ? e.message : e);
  }
});

btnCopyAll.addEventListener('click', async ()=>{
  try{
    const fromId = parseInt(copyFromTemplate.value||'0',10);
    if (!fromId){ copyResult.textContent = tTpl('copy_source_required'); return; }
    copyResult.textContent = tTpl('loading');
    const map = await fetchTemplateFieldsMap(fromId);
    const n = applyFromSourceMap(map, false);
    copyResult.textContent = tfmtTpl('copy_all_done', { count: String(n) });
  } catch(e){
    copyResult.textContent = tTpl('error_prefix') + (e && e.message ? e.message : e);
  }
});

let previewVisible = true;
btnTogglePreview.addEventListener('click', ()=>{
  previewVisible = !previewVisible;
  if (previewVisible){
    wizGrid.classList.remove('is-preview-hidden');
    wizPreviewCol.classList.remove('is-hidden');
    btnTogglePreview.textContent = tTpl('preview_hide');
    if (pdfDoc) setTimeout(()=>renderPage(),20);
  } else {
    wizGrid.classList.add('is-preview-hidden');
    wizPreviewCol.classList.add('is-hidden');
    btnTogglePreview.textContent = tTpl('preview_show');
  }
});

const handleExtractClick = async (btn) => {
  btn.disabled = true;
  try{
    currentTemplateId = parseInt(btn.dataset.templateId,10);
    currentPdfUrl = btn.dataset.pdfUrl;

    if (!currentTemplateId || Number.isNaN(currentTemplateId)) throw new Error(tTpl('wizard_missing_id'));

    fields = await extractFieldsFromPdf();
    fields = fields.map(f=>({ ...f, can_child_edit:0, can_teacher_edit:1, label:f.label||f.name, help_text:f.help_text||'', type: FIELD_TYPES.includes(f.type)?f.type:'radio' }));

    filterText=''; fieldFilter.value='';
    copyFromTemplate.value=''; copyResult.textContent='';

    cpType.checked=true; cpLabel.checked=true; cpHelp.checked=true; cpRights.checked=true; cpMeta.checked=true;

    previewVisible=true;
    wizGrid.classList.remove('is-preview-hidden');
    wizPreviewCol.classList.remove('is-hidden');
    btnTogglePreview.textContent = tTpl('preview_hide');

    showAllWidgetHighlights=true;
    btnToggleHighlights.textContent = tTpl('highlight_on');

    wizard.style.display='block';
    renderTable();
    await loadPdf();
  } catch(e){
    alert(tTpl('extract_error') + (e && e.message ? e.message : e));
  } finally {
    btn.disabled=false;
  }
};

document.addEventListener('click', (event) => {
  const btn = event.target.closest('.js-extract');
  if (!btn) return;
  event.preventDefault();
  handleExtractClick(btn);
});

btnChildNone.addEventListener('click', ()=>setChildVisible(0));
btnChildAll.addEventListener('click', ()=>setChildVisible(1));
btnTeachNone.addEventListener('click', ()=>setTeachVisible(0));
btnTeachAll.addEventListener('click', ()=>setTeachVisible(1));

btnImport.addEventListener('click', async ()=>{
  btnImport.disabled=true;
  try{
    if (!currentTemplateId || Number.isNaN(currentTemplateId)) throw new Error(tTpl('wizard_missing_id'));

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
    if (!resp.ok || !data.ok) throw new Error(data.error || tfmtTpl('import_failed', { status: String(resp.status) }));

    alert(tfmtTpl('import_ok', { count: String(data.imported) }));
    window.location.href = "<?=h(url('admin/template_fields.php'))?>?template_id=" + encodeURIComponent(currentTemplateId);
  } catch(e){
    alert(tTpl('import_error') + (e && e.message ? e.message : e));
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
