<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/template_trial.php';
require_admin();

$pdo = db();
$templateId = (int)($_GET['template_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    csrf_verify();
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) throw new RuntimeException(t('admin.template_trial.error.invalid_request'));
    $postedTemplateId = (int)($payload['template_id'] ?? 0);
    if ($postedTemplateId <= 0 || $postedTemplateId !== $templateId) {
      throw new RuntimeException(t('admin.template_trial.error.invalid_template'));
    }
    $definition = template_trial_load($pdo, $templateId, ui_lang());
    $resolved = template_trial_resolve($definition, $payload);
    echo json_encode(['ok' => true] + $resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

$templates = $pdo->query(
  "SELECT id, name, template_version, is_active
   FROM templates
   ORDER BY name ASC, template_version DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($templateId <= 0 && $templates) $templateId = (int)$templates[0]['id'];
$definition = null;
$loadError = '';
if ($templateId > 0) {
  try {
    $definition = template_trial_load($pdo, $templateId, ui_lang());
  } catch (Throwable $e) {
    $loadError = $e->getMessage();
  }
}

$fieldGroups = [];
if (is_array($definition)) {
  foreach (($definition['fields'] ?? []) as $field) {
    $groupKey = trim((string)($field['group_key'] ?? ''));
    if ($groupKey === '') $groupKey = 'Allgemein';
    $subgroupKey = trim((string)($field['subgroup'] ?? ''));
    if (!isset($fieldGroups[$groupKey])) {
      $fieldGroups[$groupKey] = [
        'key' => $groupKey,
        'title' => trim((string)($field['group_title'] ?? '')) ?: $groupKey,
        'subgroups' => [],
      ];
    }
    if (!isset($fieldGroups[$groupKey]['subgroups'][$subgroupKey])) {
      $fieldGroups[$groupKey]['subgroups'][$subgroupKey] = [
        'key' => $subgroupKey,
        'title' => $subgroupKey,
        'fields' => [],
      ];
    }
    $fieldGroups[$groupKey]['subgroups'][$subgroupKey]['fields'][] = $field;
  }
}

$now = new DateTimeImmutable('now');
$year = (int)$now->format('Y');
$schoolYearDefault = (int)$now->format('n') >= 7
  ? $year . '/' . substr((string)($year + 1), -2)
  : ($year - 1) . '/' . substr((string)$year, -2);
$periodDefault = (int)$now->format('n') >= 2 && (int)$now->format('n') <= 7 ? 'H2' : 'H1';

$cfg = app_config();
$exportCfg = is_array($cfg['export'] ?? null) ? $cfg['export'] : [];
$allowEditablePdf = (bool)($exportCfg['allow_editable_pdf'] ?? false);
$radioCrossColorMode = (string)($exportCfg['radio_cross_color_mode'] ?? 'pdf_text');
if (!in_array($radioCrossColorMode, ['pdf_text', 'admin'], true)) $radioCrossColorMode = 'pdf_text';
$legacyColor = trim((string)($exportCfg['radio_cross_color'] ?? '#0B57D0'));
$studentColor = trim((string)($exportCfg['radio_cross_color_student'] ?? $legacyColor));
$teacherColor = trim((string)($exportCfg['radio_cross_color_teacher'] ?? $legacyColor));
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $studentColor)) $studentColor = '#2E7D32';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $teacherColor)) $teacherColor = '#0B57D0';

render_admin_header(t('admin.template_trial.title'));
?>

<style>
  .trial-shell{max-width:1280px;margin:0 auto}
  .trial-toolbar{display:flex;align-items:end;gap:12px;flex-wrap:wrap}
  .trial-toolbar label{margin:0;min-width:min(520px,100%)}
  .trial-toolbar select{width:100%}
  .trial-notice{border-left:5px solid #0b57d0;background:#eef3ff;padding:14px 16px;border-radius:10px;margin-top:12px}
  .trial-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
  .trial-field{display:flex;flex-direction:column;gap:5px;min-width:0}
  .trial-field.full{grid-column:1/-1}
  .trial-field input,.trial-field select,.trial-field textarea{width:100%;box-sizing:border-box}
  .trial-field textarea{min-height:90px;resize:vertical}
  .trial-field.automatic{padding:11px;border:1px solid #bfd2f5;border-radius:10px;background:#f3f7ff}
  .trial-field.automatic input,.trial-field.automatic textarea{background:#fff;color:#26364f;border-style:dashed;cursor:default}
  .trial-field-groups{display:flex;flex-direction:column;gap:18px}
  .trial-group{border:1px solid var(--border);border-radius:14px;padding:14px;background:#fbfcfe}
  .trial-group-title{margin:0 0 12px;font-size:20px;color:#17233a}
  .trial-subgroup+.trial-subgroup{margin-top:16px;padding-top:16px;border-top:1px solid var(--border)}
  .trial-subgroup-title{margin:0 0 10px;font-size:15px;color:#40506a;text-transform:none}
  .trial-label-line{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
  .trial-badge{display:inline-flex;padding:2px 7px;border-radius:999px;background:#eef1f5;color:#475569;font-size:11px;font-weight:700}
  .trial-badge.child{background:#edf8ee;color:#256b2b}
  .trial-badge.system{background:#eef3ff;color:#204f99}
  .trial-help{font-size:12px;color:var(--muted)}
  .trial-ag-list{display:flex;flex-wrap:wrap;gap:8px}
  .trial-ag-chip{position:relative;display:inline-flex;align-items:center;border:1px solid var(--border);border-radius:999px;padding:7px 12px;cursor:pointer;background:#fff;user-select:none}
  .trial-ag-chip input{position:absolute;opacity:0;pointer-events:none}
  .trial-ag-chip:has(input:checked){border-color:var(--primary,#0b57d0);background:#eaf2ff;color:var(--primary,#0b57d0);font-weight:700}
  .trial-actions{position:sticky;bottom:12px;z-index:8;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px;border:1px solid var(--border);border-radius:14px;background:rgba(255,255,255,.96);box-shadow:0 8px 25px rgba(0,0,0,.10)}
  .trial-preview{background:#f4f6f9;padding:14px;border-radius:12px;min-height:160px}
  .trial-page{position:relative;margin:0 auto 18px;background:#fff;border:1px solid #ccd3dc;box-shadow:0 6px 18px rgba(0,0,0,.10)}
  .trial-page canvas{display:block;width:100%;height:100%}
  .trial-loading{padding:28px;text-align:center;color:var(--muted)}
  .trial-export-controls{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:14px 0}
  .trial-export-controls label{min-width:min(420px,100%);margin:0}
  .trial-export-controls select{width:100%}
  @media (max-width:760px){.trial-grid{grid-template-columns:1fr}.trial-field.full{grid-column:auto}.trial-toolbar label{min-width:100%}}
</style>

<div class="trial-shell">
  <div class="card">
    <div class="trial-toolbar">
      <label>
        <span><?=h(t('admin.template_trial.template_label'))?></span>
        <select id="trialTemplateSelect">
          <?php foreach ($templates as $tpl): ?>
            <option value="<?=h((string)$tpl['id'])?>" <?=((int)$tpl['id'] === $templateId ? 'selected' : '')?>>
              <?=h((string)$tpl['name'])?> · v<?=h((string)$tpl['template_version'])?><?=((int)($tpl['is_active'] ?? 0) === 1 ? '' : ' · ' . h(t('admin.templates.status.inactive')))?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>"><?=h(t('admin.template_trial.back'))?></a>
    </div>
    <h1 style="margin:18px 0 4px"><?=h(t('admin.template_trial.heading'))?></h1>
    <?php if ($definition): ?>
      <div class="muted"><?=h((string)$definition['template']['name'])?> · v<?=h((string)$definition['template']['template_version'])?></div>
    <?php endif; ?>
    <div class="trial-notice">
      <strong><?=h(t('admin.template_trial.notice_title'))?></strong><br>
      <?=h(t('admin.template_trial.notice_text'))?>
    </div>
  </div>

  <?php if ($loadError !== ''): ?>
    <div class="alert danger"><strong><?=h($loadError)?></strong></div>
  <?php elseif (!$definition): ?>
    <div class="card"><div class="alert"><?=h(t('admin.template_trial.no_templates'))?></div></div>
  <?php else: ?>
    <div id="trialData" autocomplete="off">
      <div class="card">
        <h2><?=h(t('admin.template_trial.student_data'))?></h2>
        <div class="trial-grid">
          <label class="trial-field"><span><?=h(t('admin.template_trial.first_name'))?></span><input data-student="first_name" value="Max" autocomplete="off"></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.last_name'))?></span><input data-student="last_name" value="Mustermann" autocomplete="off"></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.date_of_birth'))?></span><input data-student="date_of_birth" type="date" value="2020-01-01"></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.absence_total'))?></span><input data-student="absence_days_total" type="number" min="0" value="0"></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.absence_unexcused'))?></span><input data-student="absence_days_unexcused" type="number" min="0" value="0"></label>
          <?php foreach ($definition['custom_fields'] as $custom): $key=(string)($custom['field_key'] ?? ''); if ($key === '') continue; ?>
            <label class="trial-field">
              <span><?=h(template_trial_label($custom['label'] ?? null, $custom['label_en'] ?? null, ui_lang()) ?: $key)?></span>
              <input data-custom-field="<?=h($key)?>" value="<?=h((string)($custom['default_value'] ?? ''))?>" autocomplete="off">
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <h2><?=h(t('admin.template_trial.class_data'))?></h2>
        <div class="trial-grid">
          <label class="trial-field"><span><?=h(t('admin.template_trial.school_year'))?></span><input data-class="school_year" value="<?=h($schoolYearDefault)?>"></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.period'))?></span><select data-class="period_label"><option value="H1" <?=$periodDefault==='H1'?'selected':''?>><?=h(t('admin.classes.period.h1'))?></option><option value="H2" <?=$periodDefault==='H2'?'selected':''?>><?=h(t('admin.classes.period.h2'))?></option></select></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.grade_level'))?></span><input data-class="grade_level" type="number" min="0" max="20" value="1"></label>
          <label class="trial-field"><span><?=h(t('admin.template_trial.class_label'))?></span><input data-class="label" value="a"></label>
          <label class="trial-field full"><span><?=h(t('admin.template_trial.class_name'))?></span><input data-class="name" placeholder="<?=h(t('admin.template_trial.class_name_placeholder'))?>"></label>
        </div>
      </div>

      <div class="card">
        <h2><?=h(t('admin.template_trial.ags'))?></h2>
        <p class="muted"><?=h(t('admin.template_trial.ags_hint'))?></p>
        <?php if ($definition['ags']): ?>
          <div class="trial-ag-list">
            <?php foreach ($definition['ags'] as $ag): ?>
              <label class="trial-ag-chip"><input type="checkbox" data-ag-name value="<?=h((string)$ag['name'])?>"><span><?=h((string)$ag['name'])?></span></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted"><?=h(t('admin.template_trial.ags_empty'))?></div>
        <?php endif; ?>
        <div class="trial-field" style="margin-top:14px"><span><?=h(t('admin.template_trial.ag_sentence'))?></span><textarea id="trialAgSentence" disabled></textarea></div>
      </div>

      <div class="card">
        <h2><?=h(t('admin.template_trial.report_fields'))?></h2>
        <p class="muted"><?=h(t('admin.template_trial.report_fields_hint'))?></p>
        <div class="actions" style="margin:0 0 14px">
          <button class="btn secondary" type="button" id="trialRandomizeButton"><?=h(t('admin.template_trial.randomize_button'))?></button>
          <span class="muted" id="trialSystemStatus" aria-live="polite"><?=h(t('admin.template_trial.automatic_updating'))?></span>
        </div>
        <div class="trial-field-groups">
          <?php foreach ($fieldGroups as $group): ?>
            <section class="trial-group" data-trial-group="<?=h((string)$group['key'])?>">
              <h3 class="trial-group-title"><?=h((string)$group['title'])?></h3>
              <?php foreach ($group['subgroups'] as $subgroup): ?>
                <div class="trial-subgroup" data-trial-subgroup="<?=h((string)$subgroup['key'])?>">
                  <h4 class="trial-subgroup-title"><?=h((string)($subgroup['title'] !== '' ? $subgroup['title'] : t('admin.template_trial.general_subgroup')))?></h4>
                  <div class="trial-grid">
                    <?php foreach ($subgroup['fields'] as $field):
                      $fid=(int)$field['id']; $type=(string)$field['field_type']; $system=(int)$field['system_bound']===1;
                      $role=(string)$field['role']; $full=((int)$field['is_multiline']===1 || $type==='multiline');
                      $automaticKind=(string)($field['automatic_kind'] ?? '');
                    ?>
                      <label class="trial-field <?=$full?'full':''?> <?=$system?'automatic':''?>">
                        <span class="trial-label-line">
                          <span><?=h((string)$field['label'])?></span>
                          <span class="trial-badge <?=$role==='child'?'child':($role==='system'?'system':'')?>"><?=h(t('admin.template_trial.role.'.$role))?></span>
                          <?php if ((int)$field['is_required']===1): ?><span class="trial-badge"><?=h(t('admin.template_trial.required'))?></span><?php endif; ?>
                        </span>
                        <?php if ($system): ?>
                          <?php if ($automaticKind === 'signature'): ?>
                            <input data-system-output="<?=h((string)$fid)?>" data-automatic-kind="<?=h($automaticKind)?>" readonly aria-readonly="true" placeholder="<?=h(t('admin.template_trial.automatic_placeholder'))?>">
                          <?php else: ?>
                            <textarea data-system-output="<?=h((string)$fid)?>" data-automatic-kind="<?=h($automaticKind)?>" readonly aria-readonly="true" rows="2" placeholder="<?=h(t('admin.template_trial.automatic_placeholder'))?>"></textarea>
                          <?php endif; ?>
                        <?php elseif ($type === 'checkbox'): ?>
                          <span><input data-trial-field="<?=h((string)$fid)?>" data-field-kind="checkbox" type="checkbox" value="1" style="width:auto"> <?=h(t('admin.template_trial.checkbox_yes'))?></span>
                        <?php elseif (!empty($field['options'])): ?>
                          <select data-trial-field="<?=h((string)$fid)?>" data-field-kind="option"><option value=""></option><?php foreach ($field['options'] as $option): ?><option value="<?=h((string)$option['value'])?>"><?=h((string)$option['label'])?></option><?php endforeach; ?></select>
                        <?php elseif ($type === 'date'): ?>
                          <input data-trial-field="<?=h((string)$fid)?>" data-field-kind="date" type="date">
                        <?php elseif ($type === 'number' || $type === 'grade'): ?>
                          <input data-trial-field="<?=h((string)$fid)?>" data-field-kind="number" type="number">
                        <?php elseif ($full): ?>
                          <textarea data-trial-field="<?=h((string)$fid)?>" data-field-kind="text"></textarea>
                        <?php else: ?>
                          <input data-trial-field="<?=h((string)$fid)?>" data-field-kind="text" autocomplete="off">
                        <?php endif; ?>
                        <?php if ($system): ?><span class="trial-help"><?=h(t('admin.template_trial.automatic_hint.' . ($automaticKind !== '' ? $automaticKind : 'template')))?></span><?php endif; ?>
                        <?php if ((string)$field['help_text'] !== ''): ?><span class="trial-help"><?=h((string)$field['help_text'])?></span><?php endif; ?>
                        <span class="trial-help"><code><?=h((string)$field['field_name'])?></code></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </section>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="trial-actions">
        <button class="btn primary" type="button" id="trialPreviewButton"><?=h(t('admin.template_trial.preview_button'))?></button>
        <span class="muted" id="trialStatus" aria-live="polite"><?=h(t('admin.template_trial.ready'))?></span>
      </div>
    </div>

    <div class="card" id="trialPreviewCard" hidden>
      <h2><?=h(t('admin.template_trial.preview_heading'))?></h2>
      <p class="muted"><?=h(t('admin.template_trial.preview_hint'))?></p>
      <div class="trial-export-controls">
        <label>
          <span><?=h(t('teacher.export.mode_label'))?></span>
          <select id="trialExportMode">
            <option value="zip"><?=h(t('teacher.export.mode.zip'))?> – <?=h(t('teacher.export.mode.zip_sub'))?></option>
            <option value="merged"><?=h(t('teacher.export.mode.merged'))?> – <?=h(t('teacher.export.mode.merged_sub'))?></option>
            <option value="single" selected><?=h(t('teacher.export.mode.single'))?> – <?=h(t('teacher.export.mode.single_sub'))?></option>
            <option value="booklet_single"><?=h(t('teacher.export.mode.booklet_single'))?> – <?=h(t('teacher.export.mode.booklet_single_sub'))?></option>
            <option value="booklet_merged"><?=h(t('teacher.export.mode.booklet_merged'))?> – <?=h(t('teacher.export.mode.booklet_merged_sub'))?></option>
          </select>
        </label>
        <button class="btn secondary" type="button" id="trialExportButton" disabled><?=h(t('teacher.export.start'))?></button>
        <span class="muted" id="trialExportStatus" aria-live="polite"></span>
      </div>
      <div id="trialError" class="alert danger" hidden></div>
      <div id="trialPreview" class="trial-preview"></div>
    </div>

    <script type="module">
      import * as pdfjsLib from <?=json_encode(url('assets/pdfjs/pdf.min.mjs'))?>;
      import { generateFilledReportPdf, flattenReportPdf, createReportBookletPdf } from <?=json_encode(url('assets/js/report_pdf_generator.mjs'))?>;
      pdfjsLib.GlobalWorkerOptions.workerSrc = <?=json_encode(url('assets/pdfjs/pdf.worker.min.mjs'))?>;

      const TEMPLATE_ID = <?=json_encode($templateId)?>;
      const PDF_URL = <?=json_encode(url('admin/file.php?template_id=' . $templateId))?>;
      const ENDPOINT_URL = <?=json_encode(url('admin/template_trial.php?template_id=' . $templateId))?>;
      const CSRF = <?=json_encode(csrf_token())?>;
      const PDF_LIB_URL = <?=json_encode(url('assets/pdf-lib.min.js'))?>;
      const FONT_MANIFEST_URL = <?=json_encode(url('shared/font_manifest.php'))?>;
      const UI_LANG = <?=json_encode(ui_lang())?>;
      const RADIO_CROSS_COLOR_MODE = <?=json_encode($radioCrossColorMode)?>;
      const ALLOW_EDITABLE_PDF = <?=$allowEditablePdf ? 'true' : 'false'?>;
      const CROSS_COLORS = { student: <?=json_encode($studentColor)?>, teacher: <?=json_encode($teacherColor)?> };
      const I18N = <?=json_encode([
        'loading' => t('admin.template_trial.loading'),
        'rendering' => t('admin.template_trial.rendering'),
        'done' => t('admin.template_trial.done'),
        'failed' => t('admin.template_trial.error.preview_failed'),
        'exporting' => t('admin.template_trial.exporting'),
        'export_done' => t('admin.template_trial.export_done'),
        'export_failed' => t('admin.template_trial.error.export_failed'),
        'booklet_suffix' => t('teacher.export.booklet_suffix'),
        'automatic_updating' => t('admin.template_trial.automatic_updating'),
        'automatic_current' => t('admin.template_trial.automatic_current'),
        'automatic_failed' => t('admin.template_trial.automatic_failed'),
        'random_short' => t('admin.template_trial.random_short'),
        'random_long' => t('admin.template_trial.random_long'),
        'random_done' => t('admin.template_trial.random_done'),
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;

      const templateSelect = document.getElementById('trialTemplateSelect');
      const previewButton = document.getElementById('trialPreviewButton');
      const previewCard = document.getElementById('trialPreviewCard');
      const preview = document.getElementById('trialPreview');
      const errorBox = document.getElementById('trialError');
      const status = document.getElementById('trialStatus');
      const agSentence = document.getElementById('trialAgSentence');
      const exportMode = document.getElementById('trialExportMode');
      const exportButton = document.getElementById('trialExportButton');
      const exportStatus = document.getElementById('trialExportStatus');
      const randomizeButton = document.getElementById('trialRandomizeButton');
      const systemStatus = document.getElementById('trialSystemStatus');
      let pdfDoc = null;
      let lastResult = null;
      let renderedPdfBytes = null;
      let systemRefreshTimer = null;
      let systemRefreshVersion = 0;

      templateSelect?.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('template_id', templateSelect.value);
        window.location.href = url.toString();
      });

      function collectPayload(){
        const student = {};
        document.querySelectorAll('[data-student]').forEach((el) => { student[el.dataset.student] = el.value; });
        student.custom_fields = {};
        document.querySelectorAll('[data-custom-field]').forEach((el) => { student.custom_fields[el.dataset.customField] = el.value; });
        const classData = {};
        document.querySelectorAll('[data-class]').forEach((el) => { classData[el.dataset.class] = el.value; });
        const fieldValues = {};
        document.querySelectorAll('[data-trial-field]').forEach((el) => {
          fieldValues[el.dataset.trialField] = el.dataset.fieldKind === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        });
        const agNames = Array.from(document.querySelectorAll('[data-ag-name]:checked')).map((el) => el.value);
        return { template_id: TEMPLATE_ID, student, class: classData, field_values: fieldValues, ag_names: agNames };
      }

      async function resolvePreviewValues(){
        const response = await fetch(ENDPOINT_URL, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify(collectPayload()),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
        return data;
      }

      function applySystemOutputs(result){
        document.querySelectorAll('[data-system-output]').forEach((el) => {
          el.value = String(result.system_values?.[el.dataset.systemOutput] ?? '');
        });
        if (agSentence) agSentence.value = String(result.ag_sentence ?? '');
      }

      async function refreshAutomaticFields(){
        const version = ++systemRefreshVersion;
        if (systemStatus) systemStatus.textContent = I18N.automatic_updating;
        try {
          const result = await resolvePreviewValues();
          if (version !== systemRefreshVersion) return;
          applySystemOutputs(result);
          lastResult = null;
          if (systemStatus) systemStatus.textContent = I18N.automatic_current;
        } catch (error) {
          if (version !== systemRefreshVersion) return;
          if (systemStatus) systemStatus.textContent = I18N.automatic_failed;
        }
      }

      function scheduleAutomaticRefresh(){
        clearTimeout(systemRefreshTimer);
        if (systemStatus) systemStatus.textContent = I18N.automatic_updating;
        systemRefreshTimer = setTimeout(refreshAutomaticFields,220);
      }

      function randomChoice(items){
        return items.length ? items[Math.floor(Math.random()*items.length)] : null;
      }

      function randomizeTrialData(){
        const customInputs = Array.from(document.querySelectorAll('[data-custom-field]'));
        customInputs.forEach((el,index) => { el.value = `${I18N.random_short} ${index+1}`; });

        const className = document.querySelector('[data-class="name"]');
        if (className) className.value = UI_LANG === 'en' ? 'Octopus Class' : 'Oktopusklasse';
        const absenceTotal = document.querySelector('[data-student="absence_days_total"]');
        const absenceUnexcused = document.querySelector('[data-student="absence_days_unexcused"]');
        if (absenceTotal) absenceTotal.value = String(1+Math.floor(Math.random()*4));
        if (absenceUnexcused) absenceUnexcused.value = String(Math.min(Number(absenceTotal?.value || 0),Math.floor(Math.random()*2)));

        document.querySelectorAll('[data-trial-field]').forEach((el,index) => {
          const kind = String(el.dataset.fieldKind || 'text');
          if (kind === 'checkbox') {
            el.checked = Math.random() >= .5;
          } else if (kind === 'option') {
            const all = Array.from(el.options || []).filter((option) => String(option.value || '') !== '');
            const preferred = all.filter((option) => !/^(?:n\/?a|nicht bewertet)$/i.test(String(option.value || option.textContent || '').trim()));
            const selected = randomChoice(preferred.length ? preferred : all);
            if (selected) el.value = selected.value;
          } else if (kind === 'date') {
            el.value = `2026-06-${String(10+(index%18)).padStart(2,'0')}`;
          } else if (kind === 'number') {
            el.value = String(1+(index%5));
          } else {
            el.value = el.tagName === 'TEXTAREA' ? I18N.random_long : `${I18N.random_short} ${index+1}`;
          }
        });

        const agInputs = Array.from(document.querySelectorAll('[data-ag-name]'));
        agInputs.forEach((el) => { el.checked = false; });
        const shuffled = agInputs.slice().sort(() => Math.random()-.5);
        const agCount = Math.min(shuffled.length,1+Math.floor(Math.random()*Math.min(3,Math.max(1,shuffled.length))));
        shuffled.slice(0,agCount).forEach((el) => { el.checked = true; });

        status.textContent = I18N.random_done;
        refreshAutomaticFields();
      }

      async function renderPreview(result){
        renderedPdfBytes = await generateFilledReportPdf({
          templateUrl: PDF_URL,
          pdfLibUrl: PDF_LIB_URL,
          fieldValues: result.values_by_name || {},
          fieldMeta: result.field_meta || {},
          fontManifestUrl: FONT_MANIFEST_URL,
          language: UI_LANG,
          radioCrossColorMode: RADIO_CROSS_COLOR_MODE,
          radioCrossColorStudentHex: CROSS_COLORS.student,
          radioCrossColorTeacherHex: CROSS_COLORS.teacher,
        });
        pdfDoc = await pdfjsLib.getDocument({data:new Uint8Array(renderedPdfBytes)}).promise;
        preview.innerHTML = '';
        const width = preview.clientWidth || 1000;
        for (let pageNumber=1; pageNumber<=pdfDoc.numPages; pageNumber++) {
          const page = await pdfDoc.getPage(pageNumber);
          const base = page.getViewport({scale:1});
          const scale = Math.min(1.65,Math.max(.55,(width-30)/base.width));
          const viewport = page.getViewport({scale});
          const wrapper = document.createElement('div');
          wrapper.className = 'trial-page';
          wrapper.style.width = `${viewport.width}px`;
          wrapper.style.height = `${viewport.height}px`;
          const canvas = document.createElement('canvas');
          const ratio = window.devicePixelRatio || 1;
          canvas.width = Math.floor(viewport.width*ratio);
          canvas.height = Math.floor(viewport.height*ratio);
          wrapper.appendChild(canvas);
          preview.appendChild(wrapper);
          await page.render({canvasContext:canvas.getContext('2d'),viewport,transform:ratio!==1?[ratio,0,0,ratio,0,0]:undefined}).promise;
        }
      }

      async function runPreview(){
        previewButton.disabled = true;
        errorBox.hidden = true;
        status.textContent = I18N.loading;
        previewCard.hidden = false;
        preview.innerHTML = `<div class="trial-loading">${I18N.loading}</div>`;
        try {
          lastResult = await resolvePreviewValues();
          applySystemOutputs(lastResult);
          status.textContent = I18N.rendering;
          await renderPreview(lastResult);
          status.textContent = I18N.done;
          exportButton.disabled = false;
          previewCard.scrollIntoView({behavior:'smooth',block:'start'});
        } catch (error) {
          errorBox.textContent = error?.message || I18N.failed;
          errorBox.hidden = false;
          preview.innerHTML = '';
          status.textContent = I18N.failed;
        } finally {
          previewButton.disabled = false;
        }
      }

      previewButton?.addEventListener('click',runPreview);
      randomizeButton?.addEventListener('click',randomizeTrialData);

      document.querySelectorAll('[data-student],[data-class],[data-custom-field],[data-trial-field],[data-ag-name]').forEach((el) => {
        el.addEventListener('input',scheduleAutomaticRefresh);
        el.addEventListener('change',scheduleAutomaticRefresh);
      });
      refreshAutomaticFields();

      function safeFilename(value){
        const clean = String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g,'')
          .replace(/[<>:"/\\|?*\x00-\x1F]/g,'-').replace(/\s+/g,' ').trim().replace(/[. ]+$/g,'');
        return clean || 'Template-Probelauf';
      }

      function downloadBytes(bytes, filename, mime){
        const blob = new Blob([bytes],{type:mime});
        const href = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = href;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(href),1000);
      }

      async function loadJsZip(){
        if (window.JSZip) return window.JSZip;
        await new Promise((resolve,reject) => {
          const script = document.createElement('script');
          script.src = 'https://unpkg.com/jszip@3.10.1/dist/jszip.min.js';
          script.onload = resolve;
          script.onerror = () => reject(new Error(I18N.export_failed));
          document.head.appendChild(script);
        });
        return window.JSZip;
      }

      async function currentGeneratedPdf(){
        const result = await resolvePreviewValues();
        applySystemOutputs(result);
        lastResult = result;
        renderedPdfBytes = await generateFilledReportPdf({
          templateUrl: PDF_URL,
          pdfLibUrl: PDF_LIB_URL,
          fieldValues: result.values_by_name || {},
          fieldMeta: result.field_meta || {},
          fontManifestUrl: FONT_MANIFEST_URL,
          language: UI_LANG,
          radioCrossColorMode: RADIO_CROSS_COLOR_MODE,
          radioCrossColorStudentHex: CROSS_COLORS.student,
          radioCrossColorTeacherHex: CROSS_COLORS.teacher,
        });
        return renderedPdfBytes;
      }

      async function runExport(){
        exportButton.disabled = true;
        exportStatus.textContent = I18N.exporting;
        try {
          const mode = String(exportMode.value || 'single');
          const sourceBytes = await currentGeneratedPdf();
          const firstName = String(document.querySelector('[data-student="first_name"]')?.value || '').trim();
          const lastName = String(document.querySelector('[data-student="last_name"]')?.value || '').trim();
          const studentName = safeFilename(`${firstName} ${lastName}`.trim() || 'Testschueler');
          const schoolYear = safeFilename(String(document.querySelector('[data-class="school_year"]')?.value || ''));
          const templateName = safeFilename(<?=json_encode((string)$definition['template']['name'])?>);
          const baseName = safeFilename(`${templateName} Probelauf ${schoolYear}`);
          const bookletSuffix = String(I18N.booklet_suffix || ' Broschuere');
          const needsFlatten = mode === 'merged' || mode === 'booklet_merged' || !ALLOW_EDITABLE_PDF;
          let finalBytes = needsFlatten ? await flattenReportPdf(sourceBytes,PDF_LIB_URL) : sourceBytes;

          if (mode === 'booklet_single' || mode === 'booklet_merged') {
            finalBytes = await createReportBookletPdf(finalBytes,PDF_LIB_URL);
          }
          if (mode === 'zip') {
            const JSZip = await loadJsZip();
            const zip = new JSZip();
            zip.file(`${studentName}.pdf`,finalBytes);
            downloadBytes(await zip.generateAsync({type:'uint8array'}),`${baseName}.zip`,'application/zip');
          } else if (mode === 'single') {
            downloadBytes(finalBytes,`${studentName}.pdf`,'application/pdf');
          } else if (mode === 'booklet_single') {
            downloadBytes(finalBytes,`${studentName}${bookletSuffix}.pdf`,'application/pdf');
          } else if (mode === 'booklet_merged') {
            downloadBytes(finalBytes,`${baseName}${bookletSuffix}.pdf`,'application/pdf');
          } else {
            downloadBytes(finalBytes,`${baseName}.pdf`,'application/pdf');
          }
          exportStatus.textContent = I18N.export_done;
        } catch (error) {
          exportStatus.textContent = error?.message || I18N.export_failed;
        } finally {
          exportButton.disabled = false;
        }
      }

      exportButton?.addEventListener('click',runExport);
      document.getElementById('trialData')?.addEventListener('submit',(event) => { event.preventDefault(); runPreview(); });
      let resizeTimer = null;
      window.addEventListener('resize',() => {
        if (!pdfDoc || !lastResult || previewCard.hidden) return;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => renderPreview(lastResult),150);
      });
    </script>
  <?php endif; ?>
</div>

<?php render_admin_footer(); ?>
