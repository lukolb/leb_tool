<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$templateId = (int)($_GET['template_id'] ?? 0);
if ($templateId <= 0) {
  render_admin_header(t('admin.template_fields.title'));
  echo '<div class="alert danger"><strong>' . h(t('admin.template_fields.error.template_id_missing')) . '</strong></div>';
  echo '<div class="card"><a class="btn secondary" href="' . h(url('admin/templates.php')) . '">← ' . h(t('admin.template_fields.back_templates')) . '</a></div>';
  render_admin_footer();
  exit;
}

$pdfUrl = url('admin/file.php?template_id=' . $templateId);

render_admin_header(t('admin.template_fields.title'));
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
  tr.is-flash td{ animation: rowFlash 3s ease-out; }
  @keyframes rowFlash{
    0%{ background-color: rgba(255, 204, 0, 0.35); box-shadow: inset 0 0 0 9999px rgba(255, 204, 0, 0.2); }
    100%{ background-color: transparent; box-shadow: none; }
  }

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
        <a class="btn secondary" href="<?=h(url('admin/template_mappings.php?template_id='.(int)$templateId))?>"><?=h(t('admin.template_fields.goto_mapping', 'Zum Mapping'))?></a>
        <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">← <?=h(t('admin.template_fields.back_templates'))?></a>
    </div>

  <h1><?=h(t('admin.template_fields.heading'))?></h1>
</div>

<div id="dirtyWarning" class="alert danger" style="display:none">
  <p style="margin:0; display:flex; align-items:center; gap:12px;">
    <b><?=h(t('admin.template_fields.unsaved_warning'))?></b>
    <button class="btn primary" type="button" id="btnSaveTop" style="margin-left:auto;">
      <?=h(t('admin.template_fields.save_button'))?>
    </button>
  </p>
</div>

<!-- Hinweis für "DE | EN" Split (ohne Fokus-Verlust / ohne confirm()) -->
<div class="card split-banner" id="splitBanner">
  <div class="muted2" id="splitBannerText">—</div>
  <div class="actions" style="justify-content:flex-start; gap:8px;">
    <button class="btn secondary" type="button" id="btnSplitNow"><?=h(t('admin.template_fields.split_now'))?></button>
    <button class="btn secondary" type="button" id="btnSplitDismiss"><?=h(t('admin.template_fields.split_dismiss'))?></button>
    <button class="btn secondary" type="button" id="btnSplitAll"><?=h(t('admin.template_fields.split_all'))?></button>
    <button class="btn secondary" type="button" id="btnSplitResetIgnored"><?=h(t('admin.template_fields.split_reset_ignored'))?></button>
  </div>
</div>

<div class="card" id="metaCard">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
    <div>
      <div class="muted" id="metaLine"><?=h(t('admin.template_fields.loading'))?></div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <a class="btn secondary" type="button" id="btnTogglePreview"><?=h(t('admin.template_fields.preview_hide'))?></a>
    </div>
  </div>
</div>

<datalist id="groupList"></datalist>
<datalist id="subgroupList"></datalist>

<!-- OPTIONS MODAL -->
<dialog id="optionsModal">
  <div class="dlg-head">
    <h3 class="dlg-title"><?=h(t('admin.template_fields.options_title'))?></h3>
    <div class="muted2" id="optSubtitle"></div>
  </div>
  <div class="dlg-body">
    <div class="grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
      <div>
        <label><?=h(t('admin.template_fields.options_template_label'))?></label>
        <select id="optTpl"><option value=""><?=h(t('admin.template_fields.option_empty'))?></option></select>
        <div class="muted2"><?=h(t('admin.template_fields.options_template_hint'))?> <code>meta.option_list_template_id</code>.</div>
      </div>
      <div class="actions" style="justify-content:flex-start; gap:8px;">
        <button class="btn secondary" type="button" id="btnClearOptions"><?=h(t('admin.template_fields.options_clear'))?></button>
      </div>
    </div>

    <div style="margin-top:12px;">
      <label><?=h(t('admin.template_fields.options_json_label'))?></label>
      <textarea id="optJson" placeholder='{"options":[{"value":"A","label":"A","icon_id":123}]}'></textarea>
      <div class="muted2"><?=h(t('admin.template_fields.options_json_hint'))?> <code>{"options":[...]}</code>.</div>
    </div>
  </div>

  <form method="dialog">
    <div class="dlg-foot">
      <button class="btn secondary" value="cancel" type="submit"><?=h(t('admin.template_fields.cancel_button'))?></button>
      <button class="btn primary" value="ok" type="submit"><?=h(t('admin.template_fields.apply_button'))?></button>
    </div>
  </form>
</dialog>

<!-- DATE MODAL -->
<dialog id="dateModal">
  <div class="dlg-head">
    <h3 class="dlg-title"><?=h(t('admin.template_fields.date_title'))?></h3>
    <div class="muted2" id="dateSubtitle"></div>
  </div>
  <div class="dlg-body">
    <div class="grid" style="grid-template-columns: 180px 1fr; gap:12px;">
      <div>
        <label><?=h(t('admin.template_fields.date_mode_label'))?></label>
        <select id="dateMode">
          <option value="preset"><?=h(t('admin.template_fields.date_mode_preset'))?></option>
          <option value="custom"><?=h(t('admin.template_fields.date_mode_custom'))?></option>
        </select>
      </div>
      <div>
        <label><?=h(t('admin.template_fields.date_preset_label'))?></label>
        <select id="datePreset">
          <option value="MM/DD/YYYY"><?=h(t('admin.template_fields.date_preset_us'))?></option>
          <option value="DD.MM.YYYY"><?=h(t('admin.template_fields.date_preset_de'))?></option>
          <option value="DD. MMMM YYYY"><?=h(t('admin.template_fields.date_preset_de_long'))?></option>
          <option value="YYYY-MM-DD"><?=h(t('admin.template_fields.date_preset_iso'))?></option>
        </select>
      </div>
    </div>
    <div style="margin-top:12px;">
      <label><?=h(t('admin.template_fields.date_custom_label'))?></label>
      <input id="dateCustom" placeholder="<?=h(t('admin.template_fields.date_custom_placeholder'))?>">
      <div class="muted2"><?=h(t('admin.template_fields.date_custom_hint'))?></div>
    </div>
  </div>

  <form method="dialog">
  <div class="dlg-foot">
    <button class="btn secondary" value="cancel" type="submit"><?=h(t('admin.template_fields.cancel_button'))?></button>
    <button class="btn primary" value="ok" type="submit"><?=h(t('admin.template_fields.apply_button'))?></button>
  </div>
</form>
</dialog>

<!-- SNIPPET CATEGORY MODAL -->
<dialog id="snippetCategoryModal">
  <div class="dlg-head">
    <h3 class="dlg-title">Textbaustein-Kategorien</h3>
    <div class="muted2" id="snippetCategorySubtitle"></div>
  </div>
  <div class="dlg-body">
    <div class="muted2" style="margin-bottom:8px;">Verfügbare Kategorien für dieses Multiline-Feld auswählen.</div>
    <div id="snippetCategoryList" style="display:flex; flex-direction:column; gap:6px; max-height:48vh; overflow:auto;"></div>
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
      <h2><?=h(t('admin.template_fields.pdf_replace_title'))?></h2>
      <div class="muted2"><?=h(t('admin.template_fields.pdf_replace_hint'))?></div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <a class="btn secondary" type="button" id="btnReplacePdf"><?=h(t('admin.template_fields.pdf_replace_button'))?></a>
      <a class="btn secondary" type="button" id="btnDeleteMissing" style="display:none;"><?=h(t('admin.template_fields.pdf_delete_missing'))?></a>
    </div>
  </div>
  <div style="margin-top:12px; display:grid; gap:6px;">
    <div>
      <label><?=h(t('admin.template_fields.pdf_select_label'))?></label>
      <input type="file" id="replacePdfFile" accept=".pdf,application/pdf">
    </div>
    <div>
      <label>RCFF-Datei importieren (optional)</label>
      <input type="file" id="replaceRcffFile" accept=".rcff,application/json">
      <div class="muted2">Überschreibt Feldbeschriftungen anhand der Feldnamen. Das PDF wird weiterhin als Formulargrundlage verwendet.</div>
    </div>
    <div class="muted2"><?=h(t('admin.template_fields.pdf_select_hint'))?></div>
  </div>
  <div class="muted2" id="replacePdfStatus" style="margin-top:8px;"></div>
</div>

<dialog id="mapPdfFieldsDialog">
  <div class="dlg-head">
    <h3 class="dlg-title" style="margin:0;"><?=h(t('admin.template_fields.pdf_map_title'))?></h3>
    <div class="muted2"><?=h(t('admin.template_fields.pdf_map_hint'))?></div>
  </div>
  <div class="dlg-body">
    <div class="muted2" id="mapPdfSummary"></div>
    <div id="mapPdfFieldsList" class="grid" style="grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;"></div>
    <div class="muted2" id="mapPdfError" style="color:#b00020; display:none;"></div>
  </div>
  <form method="dialog">
    <div class="dlg-foot">
    <button class="btn secondary" value="cancel" type="submit"><?=h(t('admin.template_fields.cancel_button'))?></button>
    <button class="btn primary" value="ok" type="submit"><?=h(t('admin.template_fields.pdf_map_apply'))?></button>
    </div>
  </form>
</dialog>

<!-- TOP GROUPS OVERVIEW -->
<div class="card panel">
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
    <div>
      <h2><?=h(t('admin.template_fields.groups_overview_title'))?></h2>
      <div class="muted2"><?=h(t('admin.template_fields.groups_overview_hint'))?></div>
    </div>
    <div class="actions" style="justify-content:flex-start; gap:8px;">
      <a class="btn secondary" type="button" id="btnClearGroupFilter"><?=h(t('admin.template_fields.groups_filter_clear'))?></a>
      <a class="btn secondary" type="button" id="btnShowAllGroups"><?=h(t('admin.template_fields.groups_show_all'))?></a>
    </div>
  </div>
  <div class="groups-bar" id="groupsBar" style="margin-top:10px;"></div>
</div>

<div class="layout2" id="layout2">
  <!-- TABLE -->
  <div class="card" style="overflow:hidden; margin: 0" id="tableCard">
    <div class="grid" style="grid-template-columns: 1fr 200px; gap:12px; align-items:end;">
      <div>
          <h3 style="margin-top: 0;"><?=h(t('admin.template_fields.filter_title'))?></h3>
        <input id="fieldFilter" placeholder="<?=h(t('admin.template_fields.filter_placeholder'))?>">
        <div style="margin-top:8px;">
          <label class="muted2" style="display:block; margin-bottom:4px;"><?=h(t('admin.template_fields.filter_exclude_label'))?></label>
          <input id="fieldExclude" placeholder="<?=h(t('admin.template_fields.filter_exclude_placeholder'))?>">
        </div>
        <div class="muted2"><?=h(t('admin.template_fields.filter_hint'))?></div>
      </div>
      <div class="actions" style="justify-content:flex-start;">
        <a class="btn secondary" type="button" id="btnClearFilter"><?=h(t('admin.template_fields.filter_clear'))?></a>
      </div>
    </div>

    <!-- BULK TOOLBAR -->
    <h3 style="margin-bottom: 0;"><?=h(t('admin.template_fields.bulk_title'))?></h3>
    <div class="toolbar" style="margin-top:12px;">

      <div class="block">
        <label><?=h(t('admin.template_fields.bulk_group_label'))?></label>
        <input id="bulkGroup" list="groupList" placeholder="<?=h(t('admin.template_fields.bulk_group_placeholder'))?>">
        <label class="muted2" style="margin-top:6px;"><?=h(t('admin.template_fields.bulk_subgroup_label'))?></label>
        <input id="bulkSubgroup" list="subgroupList" placeholder="<?=h(t('admin.template_fields.bulk_subgroup_placeholder'))?>">
        <div class="muted2"><?=h(t('admin.template_fields.bulk_subgroup_hint'))?></div>
      </div>

      <div class="block">
        <label><?=h(t('admin.template_fields.bulk_type_label'))?></label>
        <select id="bulkType">
          <option value=""><?=h(t('admin.template_fields.option_empty'))?></option>
          <option>text</option><option>multiline</option><option>date</option><option>number</option>
          <option>grade</option><option>checkbox</option><option>radio</option><option>select</option><option>signature</option>
        </select>
      </div>

      <div class="block">
        <label><?=h(t('admin.template_fields.bulk_rights_label'))?></label>
        <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap:8px;">
          <div>
            <label class="muted2"><?=h(t('admin.template_fields.bulk_rights_child'))?></label>
            <select id="bulkChild">
              <option value=""><?=h(t('admin.template_fields.option_empty'))?></option>
              <option value="1"><?=h(t('admin.template_fields.option_yes'))?></option>
              <option value="0"><?=h(t('admin.template_fields.option_no'))?></option>
            </select>
          </div>
          <div>
            <label class="muted2"><?=h(t('admin.template_fields.bulk_rights_teacher'))?></label>
            <select id="bulkTeacher">
              <option value=""><?=h(t('admin.template_fields.option_empty'))?></option>
              <option value="1"><?=h(t('admin.template_fields.option_yes'))?></option>
              <option value="0"><?=h(t('admin.template_fields.option_no'))?></option>
            </select>
          </div>
          <div>
            <label class="muted2"><?=h(t('admin.template_fields.bulk_rights_optional'))?></label>
            <select id="bulkRequired">
              <option value=""><?=h(t('admin.template_fields.option_empty'))?></option>
              <option value="1"><?=h(t('admin.template_fields.option_yes'))?></option>
              <option value="0"><?=h(t('admin.template_fields.option_no'))?></option>
            </select>
          </div>
        </div>
      </div>

      <div class="block" style="min-width:340px;">
        <label><?=h(t('admin.template_fields.options_template_label'))?></label>
        <select id="bulkTpl">
          <option value=""><?=h(t('admin.template_fields.options_template_none'))?></option>
        </select>
        <div class="muted2"><?=h(t('admin.template_fields.options_template_hint'))?> <code>meta.option_list_template_id</code>.</div>
      </div>

      <div class="block" style="min-width:360px;">
        <label><?=h(t('admin.template_fields.date_bulk_label'))?></label>
        <div style="display:flex; gap:8px;">
          <select id="bulkDateMode" style="max-width:140px;">
            <option value=""><?=h(t('admin.template_fields.option_empty'))?></option>
            <option value="preset"><?=h(t('admin.template_fields.date_mode_preset'))?></option>
            <option value="custom"><?=h(t('admin.template_fields.date_mode_custom'))?></option>
          </select>
          <select id="bulkDatePreset">
            <option value="MM/DD/YYYY"><?=h(t('admin.template_fields.date_preset_us'))?></option>
            <option value="DD.MM.YYYY"><?=h(t('admin.template_fields.date_preset_de'))?></option>
            <option value="DD. MMMM YYYY"><?=h(t('admin.template_fields.date_preset_de_long'))?></option>
            <option value="YYYY-MM-DD"><?=h(t('admin.template_fields.date_preset_iso'))?></option>
          </select>
        </div>
        <input id="bulkDateCustom" placeholder="<?=h(t('admin.template_fields.date_custom_placeholder'))?>" style="margin-top:6px;">
      </div>

      <div class="actions">
          <a class="btn secondary" type="button" id="btnApplySelected" style="gap: 0;"><?=h(t('admin.template_fields.apply_selected'))?> (<span id="selCount">0</span>)</a>
        <a class="btn secondary" type="button" id="btnApplyVisible"><?=h(t('admin.template_fields.apply_visible'))?></a>
      </div>

      <div class="block" style="min-width:280px;">
          <h3 style="margin-bottom: 0;"><?=h(t('admin.template_fields.auto_group_title'))?></h3>
        <div class="muted" style="margin-bottom: 10px;"><?=h(t('admin.template_fields.auto_group_hint'))?> <code>-</code> (z.B. <code>mu-grade</code> → <code>mu</code>).</div>
        <div style="display:flex; gap:8px;">
          <a class="btn secondary" type="button" id="btnAutoGroupPrefix"><?=h(t('admin.template_fields.auto_group_prefix'))?></a>
          <a class="btn secondary" type="button" id="btnAutoGroupPage"><?=h(t('admin.template_fields.auto_group_page'))?></a>
        </div>
      </div>
        <div class="block" style="min-width:100%; text-align: end;">
            <a class="btn secondary" type="button" id="btnAddVirtualField"><?=h(t('admin.template_fields.add_virtual_field', 'Neues Mapping-Feld (ohne PDF)'))?></a>
            <div class="muted2" id="saveHint" style="min-width:220px;">&nbsp;</div>
            <a class="btn primary" type="button" id="btnSave"><?=h(t('admin.template_fields.save_button'))?></a>
        </div>
    </div>

    <div class="table-scroll" id="tableScroll" style="margin-top:12px;">
      <table id="fieldsTbl">
        <thead>
          <tr>
            <th class="sticky-col-0" style="width:46px;"><?=h(t('admin.template_fields.table.select'))?></th>
            <th class="sticky-col-1"><?=h(t('admin.template_fields.table.field_name'))?></th>
            <th style="min-width:200px;"><?=h(t('admin.template_fields.table.group'))?></th>
            <th style="min-width:220px;"><?=h(t('admin.template_fields.table.group_title_en'))?></th>
            <th style="min-width:200px;"><?=h(t('admin.template_fields.table.subgroup'))?></th>
            <th style="min-width:220px;"><?=h(t('admin.template_fields.table.subgroup_en'))?></th>
            <th style="min-width:260px;"><?=h(t('admin.template_fields.table.label'))?></th>
            <th style="min-width:260px;"><?=h(t('admin.template_fields.table.label_en'))?></th>
            <th style="min-width:420px;"><?=h(t('admin.template_fields.table.help'))?></th>
            <th style="min-width:240px;"><?=h(t('admin.template_fields.table.base_field'))?></th>
            <th style="min-width:160px;"><?=h(t('admin.template_fields.table.type'))?></th>
            <th><?=h(t('admin.template_fields.table.child'))?></th>
            <th><?=h(t('admin.template_fields.table.teacher'))?></th>
            <th><?=h(t('admin.template_fields.table.class_field'))?></th>
            <th><?=h(t('admin.template_fields.table.optional'))?></th>
            <th style="min-width:420px;"><?=h(t('admin.template_fields.table.extras'))?></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <p class="muted2" style="margin-top:10px;">
      • <?=h(t('admin.template_fields.help_row_click'))?><br>
      • <?=h(t('admin.template_fields.help_pdf_click'))?><br>
      • <?=h(t('admin.template_fields.help_page_highlight'))?><br>
      • <?=h(t('admin.template_fields.help_drag_drop'))?> <code>sort_order</code> <?=h(t('admin.template_fields.help_drag_drop_suffix'))?><br>
      • <?=h(t('admin.template_fields.help_preview_width'))?>
    </p>
  </div>

  <!-- RESIZER -->
  <div class="col-resizer" id="colResizer" title="<?=h(t('admin.template_fields.preview_resize_title'))?>"></div>

  <!-- PDF Preview -->
  <div class="card wiz-preview" id="previewCard" style="margin:0;">
    <h2><?=h(t('admin.template_fields.pdf_preview_title'))?></h2>
    <div class="muted" id="pdfHint"><?=h(t('admin.template_fields.pdf_preview_hint'))?></div>

    <div style="display:flex; gap:8px; align-items:center; margin:10px 0; flex-wrap:wrap;">
      <button class="btn secondary" id="btnPrevPage" type="button" aria-label="<?=h(t('admin.template_fields.page_prev'))?>">←</button>
      <div class="muted" id="pageInfo"><?=h(t('admin.template_fields.page_label'))?> –</div>
      <button class="btn secondary" id="btnNextPage" type="button" aria-label="<?=h(t('admin.template_fields.page_next'))?>">→</button>
      <div class="muted2" style="margin-left:auto;"><?=h(t('admin.template_fields.page_tip'))?></div>
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
const textSnippetsApiUrl = "<?=h(url('admin/ajax/text_snippets_api.php'))?>";
const optionListsApiUrl = "<?=h(url('admin/ajax/option_lists_api.php'))?>";
const replacePdfUrl = "<?=h(url('admin/ajax/templates_replace_pdf.php'))?>";
const importFieldsUrl = "<?=h(url('admin/ajax/import_fields.php'))?>";
const I18N = <?=json_encode([
  'option_unassigned' => t('admin.template_fields.option_unassigned'),
  'split_notice_title' => t('admin.template_fields.split_notice_title'),
  'split_notice_prefix' => t('admin.template_fields.split_notice_prefix'),
  'split_reset_done' => t('admin.template_fields.split_reset_done'),
  'group_alt_hide' => t('admin.template_fields.group_alt_hide'),
  'option_empty' => t('admin.template_fields.option_empty'),
  'group_empty' => t('admin.template_fields.option_empty'),
  'option_template_none' => t('admin.template_fields.options_template_none'),
  'template_select' => t('admin.template_fields.template_select'),
  'base_field_student_first' => t('admin.template_fields.base_field_student_first'),
  'base_field_student_last' => t('admin.template_fields.base_field_student_last'),
  'base_field_student_full' => t('admin.template_fields.base_field_student_full'),
  'base_field_student_birth' => t('admin.template_fields.base_field_student_birth'),
  'base_field_class_display' => t('admin.template_fields.base_field_class_display'),
  'base_field_class_grade' => t('admin.template_fields.base_field_class_grade'),
  'base_field_class_label' => t('admin.template_fields.base_field_class_label'),
  'base_field_school_year' => t('admin.template_fields.base_field_school_year'),
  'label_en_placeholder' => t('admin.template_fields.label_en_placeholder'),
  'badge_options' => t('admin.template_fields.badge_options'),
  'groups_bar_title' => t('admin.template_fields.groups_bar_title'),
  'import_failed' => t('admin.template_fields.import_failed'),
  'delete_failed' => t('admin.template_fields.delete_failed'),
  'delete_missing_button' => t('admin.template_fields.delete_missing_button'),
  'mapping_summary' => t('admin.template_fields.mapping_summary'),
  'mapping_existing_field' => t('admin.template_fields.mapping_existing_field'),
  'mapping_duplicate' => t('admin.template_fields.mapping_duplicate'),
  'pdf_read_error' => t('admin.template_fields.pdf_read_error'),
  'warn_missing_pdf' => t('admin.template_fields.warn_missing_pdf'),
  'confirm_new_fields' => t('admin.template_fields.confirm_new_fields'),
  'confirm_delete_missing' => t('admin.template_fields.confirm_delete_missing'),
  'api_error' => t('admin.template_fields.api_error'),
  'option_api_error' => t('admin.template_fields.option_api_error'),
  'template_not_found' => t('admin.template_fields.template_not_found'),
  'preview_hide' => t('admin.template_fields.preview_hide'),
  'preview_show' => t('admin.template_fields.preview_show'),
  'load_fields_error' => t('admin.template_fields.load_fields_error'),
  'pdf_select_required' => t('admin.template_fields.pdf_select_required'),
  'pdf_checking' => t('admin.template_fields.pdf_checking'),
  'pdf_replace_warning' => t('admin.template_fields.pdf_replace_warning'),
  'pdf_replace_cancelled' => t('admin.template_fields.pdf_replace_cancelled'),
  'pdf_mapping_cancelled' => t('admin.template_fields.pdf_mapping_cancelled'),
  'pdf_uploading' => t('admin.template_fields.pdf_uploading'),
  'pdf_upload_failed' => t('admin.template_fields.pdf_upload_failed'),
  'pdf_saved_updating' => t('admin.template_fields.pdf_saved_updating'),
  'pdf_summary_updated' => t('admin.template_fields.pdf_summary_updated'),
  'pdf_summary_missing' => t('admin.template_fields.pdf_summary_missing'),
  'pdf_summary_added' => t('admin.template_fields.pdf_summary_added'),
  'pdf_summary_renamed' => t('admin.template_fields.pdf_summary_renamed'),
  'pdf_summary_deleted' => t('admin.template_fields.pdf_summary_deleted'),
  'pdf_missing_none' => t('admin.template_fields.pdf_missing_none'),
  'pdf_delete_confirm' => t('admin.template_fields.pdf_delete_confirm'),
  'pdf_deleted' => t('admin.template_fields.pdf_deleted'),
  'pdf_delete_error' => t('admin.template_fields.pdf_delete_error'),
  'split_applied' => t('admin.template_fields.split_applied'),
  'meta_line' => t('admin.template_fields.meta_line'),
  'pdf_marked' => t('admin.template_fields.pdf_marked'),
  'pdf_hint_default' => t('admin.template_fields.pdf_hint_default'),
  'page_info' => t('admin.template_fields.page_info'),
  'pdf_hit_none' => t('admin.template_fields.pdf_hit_none'),
  'pdf_hit_hidden' => t('admin.template_fields.pdf_hit_hidden'),
  'pdf_no_position' => t('admin.template_fields.pdf_no_position'),
  'template_label' => t('admin.template_fields.template_label'),
  'options_count' => t('admin.template_fields.options_count'),
  'badge_multiline' => t('admin.template_fields.badge_multiline'),
  'date_button' => t('admin.template_fields.date_button'),
  'options_button' => t('admin.template_fields.options_button'),
  'group_page' => t('admin.template_fields.group_page'),
  'error_prefix' => t('admin.template_fields.error_prefix'),
  'new_field_name' => t('admin.template_fields.new_field_name', 'Feldname (technisch):'),
  'new_field_label' => t('admin.template_fields.new_field_label', 'Label:'),
  'new_field_added' => t('admin.template_fields.new_field_added', 'Neues Mapping-Feld hinzugefügt.'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
const tAdmin = (key) => I18N[key] ?? key;
const tfmtAdmin = (key, vars = {}) => {
  const base = tAdmin(key);
  return base.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
};
const EMPTY_LABEL = tAdmin('group_empty');

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
const subgroupList = document.getElementById('subgroupList');
const groupsBar = document.getElementById('groupsBar');
const btnShowAllGroups = document.getElementById('btnShowAllGroups');
const btnClearGroupFilter = document.getElementById('btnClearGroupFilter');

const fieldFilter = document.getElementById('fieldFilter');
const fieldExclude = document.getElementById('fieldExclude');
const btnClearFilter = document.getElementById('btnClearFilter');

const selCount = document.getElementById('selCount');
const btnSave = document.getElementById('btnSave');
const btnSaveTop = document.getElementById('btnSaveTop');
const btnAddVirtualField = document.getElementById('btnAddVirtualField');

const bulkGroup = document.getElementById('bulkGroup');
const bulkSubgroup = document.getElementById('bulkSubgroup');
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
const replaceRcffInput = document.getElementById('replaceRcffFile');
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
const btnClearOptions = document.getElementById('btnClearOptions');

const dateModal = document.getElementById('dateModal');
const dateSubtitle = document.getElementById('dateSubtitle');
const dateMode = document.getElementById('dateMode');
const datePreset = document.getElementById('datePreset');
const dateCustom = document.getElementById('dateCustom');
const snippetCategoryModal = document.getElementById('snippetCategoryModal');
const snippetCategorySubtitle = document.getElementById('snippetCategorySubtitle');
const snippetCategoryList = document.getElementById('snippetCategoryList');

if (dateCustom && dateMode) {
  dateCustom.addEventListener('input', ()=>{
    if (dateMode.value !== 'custom') dateMode.value = 'custom';
  });
}

if (bulkDateCustom && bulkDateMode) {
  bulkDateCustom.addEventListener('input', ()=>{
    if (bulkDateMode.value !== 'custom') bulkDateMode.value = 'custom';
  });
}

let template = null;
let fields = [];
let optionTemplates = [];
let snippetCategoriesCache = null;

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

function updateDirtyUI(){
  const warning = document.getElementById("dirtyWarning");
  if (!warning) return;
  warning.style.display = dirty.size ? "block" : "none";
}

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

window.addEventListener('beforeunload', (event)=>{
  if (!dirty.size) return;
  event.preventDefault();
  event.returnValue = '';
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
  tr.classList.remove('is-flash');
  void tr.offsetWidth;
  tr.classList.add('is-flash');
  setTimeout(()=>tr.classList.remove('is-flash'), 3000);
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
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
      let maxLen = Number(it?.maxLen ?? it?.maxLength ?? it?.maxlength ?? it?.maxlen ?? it?.MaxLen ?? NaN);
      if (!Number.isFinite(maxLen) || maxLen <= 0) maxLen = null;

      tmp.push({ name, pNum, rect, rawType, multiline, hint, maxLen });
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
        hint: t.hint,
        pdf_max_len: t.maxLen
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
      help_text: '',
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
  if (!resp.ok || !data.ok) throw new Error(data.error || tfmtAdmin('import_failed', { status: String(resp.status) }));

  return data.imported || 0;
}

function updateMissingDeleteButton(names){
  lastMissingNames = Array.isArray(names) ? names : [];
  if (!btnDeleteMissing) return;
  if (lastMissingNames.length) {
    btnDeleteMissing.style.display = '';
    btnDeleteMissing.textContent = tfmtAdmin('delete_missing_button', { count: String(lastMissingNames.length) });
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
  if (!resp.ok || !data.ok) throw new Error(data.error || tfmtAdmin('delete_failed', { status: String(resp.status) }));
  return data.deleted || 0;
}

function buildMappingDialog(missing, newcomers){
  mapPdfFieldsList.innerHTML = '';
  mapPdfError.style.display = 'none';
  mapPdfError.textContent = '';
  mapPdfSummary.textContent = tfmtAdmin('mapping_summary', { missing: String(missing.length), newcomers: String(newcomers.length) });

  const options = [{ value:'', label: tAdmin('option_unassigned') }, ...newcomers.map(n=>({ value:n, label:n }))];

  for (const m of missing){
    const wrap = document.createElement('div');
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.gap = '6px';

    const label = document.createElement('label');
    label.textContent = tfmtAdmin('mapping_existing_field', { field: m });
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
      mapPdfError.textContent = tfmtAdmin('mapping_duplicate', { target });
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
    throw new Error(tAdmin('pdf_read_error') + ' ' + (e?.message || e));
  }

  try {
    const info = await readPdfFieldInfoFromDoc(doc);
    const pdfNames = new Set(info.fields.keys());
    const existingNames = new Set(fields.filter(f => !isPdfIndependentField(f)).map(f => String(f.name)));

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
      if (Number.isFinite(info.pdf_max_len) && info.pdf_max_len > 0) next.meta.pdf_max_len = info.pdf_max_len;
      else if (next.meta?.pdf_max_len) delete next.meta.pdf_max_len;
      fields[idx] = next;
      markDirty(next.id);
      renameApplied.push({ from: oldName, to: newName });
    }
  }

  const pdfNamesAfter = new Set(pdfInfo.fields.keys());
  const existingNames = new Set(fields.filter(f => !isPdfIndependentField(f)).map(f => String(f.name)));

  const missing = [...existingNames].filter(n => !pdfNamesAfter.has(n));
  const newcomers = [...pdfNamesAfter].filter(n => !existingNames.has(n));

  let updated = 0;
  fields = fields.map(f => {
    const info = pdfInfo.fields.get(String(f.name));
    if (!info) return f;

    const next = { ...f };
    next.meta = { ...(next.meta || {}), page: info.page, rect: info.rect, detectedType: info.rawType, multiline: info.multiline };
    if (Number.isFinite(info.pdf_max_len) && info.pdf_max_len > 0) next.meta.pdf_max_len = info.pdf_max_len;
    else if (next.meta?.pdf_max_len) delete next.meta.pdf_max_len;
    markDirty(next.id);
    updated++;
    return next;
  });

  renderTable();
  updateMeta();

  if (missing.length) {
    alert(tfmtAdmin('warn_missing_pdf', { fields: missing.join(', ') }));
  }

  if (dirty.size) await save();

  let imported = 0;
  if (newcomers.length) {
    const wantImport = confirm(tfmtAdmin('confirm_new_fields', { count: String(newcomers.length) }));
    if (wantImport) {
      imported = await importNewFieldsFromPdf(newcomers, pdfInfo);
      await load();
    }
  }

  let deleted = 0;
  updateMissingDeleteButton(missing);
  if (missing.length && opts?.promptDeleteMissing !== false) {
    const wantDelete = confirm(tfmtAdmin('confirm_delete_missing', { count: String(missing.length), fields: missing.join(', ') }));
    if (wantDelete) {
      deleted = await deleteFieldsByName(missing);
      await load();
      updateMissingDeleteButton([]);
    }
  }

  return { updated, missing: missing.length, missingNames: missing, added: imported, renamed: renameApplied.length, deleted };
}


function isPdfIndependentField(field){
  const meta = field && typeof field === 'object' ? (field.meta || {}) : {};
  return Number(meta.virtual_only || 0) === 1;
}

function parseGroupParts(raw){
  const cleaned = String(raw ?? '').trim();
  if (!cleaned) return { group: '', subgroup: '' };
  const parts = cleaned.split('/').map(p => String(p).trim()).filter(Boolean);
  if (!parts.length) return { group: '', subgroup: '' };
  const group = parts[0];
  const subgroup = parts.length > 1 ? parts.slice(1).join(' / ') : '';
  return { group, subgroup };
}

function buildGroupPath(group, subgroup){
  const g = String(group || '').trim();
  const sub = String(subgroup || '').trim();
  if (!g) return '';
  return sub ? `${g}/${sub}` : g;
}

function getGroupPath(f){
  const g = f?.meta?.group;
  return (g && String(g).trim()) ? String(g).trim() : EMPTY_LABEL;
}

function getGroupKey(f){
  const path = getGroupPath(f);
  if (path === EMPTY_LABEL) return EMPTY_LABEL;
  const parts = parseGroupParts(path);
  return parts.group || EMPTY_LABEL;
}

function getSubgroupMatchKey(f){
  const path = getGroupPath(f);
  if (path === EMPTY_LABEL) return '';
  const parts = parseGroupParts(path);
  if (!parts.group || !parts.subgroup) return '';
  return `${parts.group}||${parts.subgroup}`;
}

function markDirty(id){
  dirty.add(id);
  saveHint.textContent = dirty.size ? `Ungespeicherte Änderungen: ${dirty.size}` : ' ';
  updateDirtyUI();
}

/* ---- Split hint ("DE | EN") ---- */
function hideSplitBanner(){
  splitCandidate = null;
  splitBanner.style.display = 'none';
  splitBannerText.textContent = EMPTY_LABEL;
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
    `<strong>${tAdmin('split_notice_title')}</strong> ${tfmtAdmin('split_notice_prefix', { total: String(total) })} <span class="muted2">DE: ${escapeHtml(de)} · EN: ${escapeHtml(en)}</span>`;

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
  if (changed) saveHint.textContent = tfmtAdmin('split_applied', { changed: String(changed), remaining: String(countSplitCandidates()) });
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
  saveHint.textContent = tAdmin('split_reset_done');
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
  const groupSet = new Set();
  const subgroupSet = new Set();
  for (const f of fields) {
    const g = getGroupPath(f);
    if (!g || g === EMPTY_LABEL) continue;
    const parts = parseGroupParts(g);
    if (parts.group) groupSet.add(parts.group);
    if (parts.subgroup) subgroupSet.add(parts.subgroup);
  }
  const groups = [...groupSet].sort((a,b)=>a.localeCompare(b, undefined, { sensitivity:'base' }));
  const subs = [...subgroupSet].sort((a,b)=>a.localeCompare(b, undefined, { sensitivity:'base' }));
  groupList.innerHTML = groups.map(g=>`<option value="${escapeHtml(g)}"></option>`).join('');
  subgroupList.innerHTML = subs.map(g=>`<option value="${escapeHtml(g)}"></option>`).join('');
}

function updateMeta(){
  const total = fields.length;
  const visible = fields.filter(isVisibleByFilter).length;
  const sel = selected.size;
  metaLine.textContent = tfmtAdmin('meta_line', {
    id: String(template?.id ?? templateId),
    name: String(template?.name ?? ''),
    version: String(template?.template_version ?? ''),
    total: String(total),
    visible: String(visible),
    selected: String(sel),
  });
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
    if (tid) badges.push(`🧩 ${escapeHtml(optionTemplateNameById(tid) || (tAdmin('template_label') + ' ' + tid))}`);

    const opt = f.options;
    let count = 0;
    if (opt && typeof opt === 'object' && Array.isArray(opt.options)) count = opt.options.length;
    if (count) badges.push(`☰ ${tfmtAdmin('options_count', { count: String(count) })}`);
    else badges.push(`☰ ${tAdmin('badge_options')}`);

  }

  if (f.type === 'multiline' || f.multiline) badges.push(`↵ ${tAdmin('badge_multiline')}`);

  return badges.map(b=>`<span class="badge">${b}</span>`).join('') || `<span class="muted2">${EMPTY_LABEL}</span>`;
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
    pill.title = tAdmin('groups_bar_title');

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
        if (g !== EMPTY_LABEL) hiddenGroups.add(g);
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
            <span class="gmeta">${tAdmin('group_alt_hide')}</span>
          </div>
        `;

        // Linke, sticky Zelle: deckt ✓ + Feldname + Gruppenspalten ab
        const tdLeft = document.createElement('td');
        tdLeft.className = 'group-sticky';
        tdLeft.colSpan = 6;
        tdLeft.innerHTML = html;

        // Rechte Zelle: füllt Rest (damit Row optisch über volle Breite geht)
        const tdRight = document.createElement('td');
        tdRight.colSpan = 10;
        tdRight.innerHTML = '&nbsp;'; // nur Fläche

        // Klick-Handling auf der ganzen Zeile (nicht nur links)
        gr.addEventListener('click', (e)=>{
          e.preventDefault();
          if (e.altKey) {
            if (g !== EMPTY_LABEL) hiddenGroups.add(g);
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
        pdfHint.textContent = tfmtAdmin('pdf_no_position', { name: f.name });
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
    const tdSub = document.createElement('td');
    const tdSubEn = document.createElement('td');
    const inpG = document.createElement('input');
    const inpSub = document.createElement('input');
    const inpSubEn = document.createElement('input');
    const gParts = parseGroupParts(f.meta?.group);
    inpG.type = 'text';
    inpG.value = gParts.group;
    inpG.setAttribute('list','groupList');
    inpSub.type = 'text';
    inpSub.value = gParts.subgroup;
    inpSub.setAttribute('list','subgroupList');
    inpSubEn.dataset.role = 'subgroup_title_en';
    inpSubEn.dataset.fieldId = String(f.id);
    inpSubEn.type = 'text';
    inpSubEn.placeholder = 'z. B. Grammar';
    inpSubEn.value = (f.meta && f.meta.subgroup_title_en) ? String(f.meta.subgroup_title_en) : '';

    function syncGroupMeta(){
      const groupVal = inpG.value.trim();
      const subVal = inpSub.value.trim();
      const merged = buildGroupPath(groupVal, subVal);
      fields[idx].meta = fields[idx].meta || {};
      if (merged) fields[idx].meta.group = merged;
      else delete fields[idx].meta.group;
      markDirty(f.id);
      rebuildGroupDatalist();
      renderGroupsBar();
      updateMeta();
    }

    inpG.addEventListener('click', (e)=>e.stopPropagation());
    inpSub.addEventListener('click', (e)=>e.stopPropagation());
    inpG.addEventListener('input', (e)=>{
      e.stopPropagation();
      syncGroupMeta();
    });
    inpSub.addEventListener('input', (e)=>{
      e.stopPropagation();
      syncGroupMeta();
    });
    tdG.appendChild(inpG);
    tdSub.appendChild(inpSub);
    tdSubEn.appendChild(inpSubEn);

    // Untergruppe (EN) – wird in meta gespeichert und auf alle Felder derselben Untergruppe angewendet
    inpSubEn.addEventListener('click', (e)=>e.stopPropagation());
    inpSubEn.addEventListener('input', (e)=>{
      e.stopPropagation();
      fields[idx].meta = fields[idx].meta || {};
      const v = inpSubEn.value;
      if (String(v).trim()) fields[idx].meta.subgroup_title_en = String(v).trim();
      else delete fields[idx].meta.subgroup_title_en;
      markDirty(f.id);
    });
    inpSubEn.addEventListener('blur', (e)=>{
      e.stopPropagation();
      const v = String(inpSubEn.value || '').trim();
      const sgCur = getSubgroupMatchKey(fields[idx]);
      if (!sgCur) return;
      for (let i=0; i<fields.length; i++){
        if (getSubgroupMatchKey(fields[i]) !== sgCur) continue;
        fields[i].meta = fields[i].meta || {};
        if (v) fields[i].meta.subgroup_title_en = v;
        else delete fields[i].meta.subgroup_title_en;
        markDirty(fields[i].id);
      }
      syncSubgroupTitleEnDom(sgCur, v);
      renderGroupsBar();
      updateMeta();
    });

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
      const gCur = getGroupKey(fields[idx]);
      for (let i=0; i<fields.length; i++){
        if (getGroupKey(fields[i]) !== gCur) continue;
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
    inpLE.placeholder = tAdmin('label_en_placeholder');
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

    // System binding
    const tdB = document.createElement('td');
    const selB = document.createElement('select');
    selB.innerHTML = `
      <option value="">${tAdmin('option_empty')}</option>
      <option value="student.first_name">${tAdmin('base_field_student_first')}</option>
      <option value="student.last_name">${tAdmin('base_field_student_last')}</option>
      <option value="student.full_name">${tAdmin('base_field_student_full')}</option>
      <option value="student.date_of_birth">${tAdmin('base_field_student_birth')}</option>
      <option value="class.display">${tAdmin('base_field_class_display')}</option>
      <option value="class.grade_level">${tAdmin('base_field_class_grade')}</option>
      <option value="class.label">${tAdmin('base_field_class_label')}</option>
      <option value="class.school_year">${tAdmin('base_field_school_year')}</option>
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
      markDirty(f.id);
      renderTable();
      scanForSplitCandidate();
    });
    tdT.appendChild(selT);

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
    cbR.checked = f.required === 0;
    cbR.addEventListener('click',(e)=>e.stopPropagation());
    cbR.addEventListener('change',(e)=>{
      e.stopPropagation();
      fields[idx].required = cbR.checked ? 0 : 1;
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
      btn.textContent = tAdmin('date_button');
      btn.addEventListener('click', (e)=>{ e.stopPropagation(); openDateModal(f.id); });
      wrap.appendChild(btn);
    }

    if (f.type === 'multiline' || f.multiline) {
      const btnSnipCats = document.createElement('button');
      btnSnipCats.type = 'button';
      btnSnipCats.className = 'btn secondary';
      btnSnipCats.textContent = 'Textbaustein-Kategorien';
      btnSnipCats.addEventListener('click', (e)=>{ e.stopPropagation(); openSnippetCategoryModal(f.id); });
      wrap.appendChild(btnSnipCats);
    }

    if (['radio','select','grade'].includes(f.type)) {
      const tplSel = document.createElement('select');
      tplSel.style.maxWidth = '320px';
      tplSel.innerHTML =
        `<option value="">${tAdmin('template_select')}</option>` +
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
      btnOpt.textContent = tAdmin('options_button');
      btnOpt.addEventListener('click', (e)=>{ e.stopPropagation(); openOptionsModal(f.id); });
      wrap.appendChild(btnOpt);

    }

    const badges = document.createElement('div');
    badges.innerHTML = computeExtras(f);
    wrap.appendChild(badges);

    tdX.appendChild(wrap);

    // ✓ | Feldname | Gruppe | Gruppentitel EN | Untergruppe | Untergruppe EN | Label | Label EN | Help | Stammfeld | Typ | Kind | Lehrer | Klassenfeld | Req | Extras
    tr.append(tdS, tdN, tdG, tdGE, tdSub, tdSubEn, tdL, tdLE, tdH, tdB, tdT, tdC, tdTe, tdK, tdR, tdX);
    tbody.appendChild(tr);
  }
}

function syncGroupTitleEnDom(groupKey, value){
  const v = String(value || '');
  const inputs = tbody.querySelectorAll('input[data-role="group_title_en"][data-field-id]');
  for (const el of inputs) {
    const fid = Number(el.dataset.fieldId || 0);
    if (!fid) continue;
    const ff = fields.find(x => x.id === fid);
    if (!ff) continue;
    if (getGroupKey(ff) !== groupKey) continue;
    if (el.value !== v) el.value = v;
  }
}

function syncSubgroupTitleEnDom(matchKey, value){
  const v = String(value || '');
  const inputs = tbody.querySelectorAll('input[data-role="subgroup_title_en"][data-field-id]');
  for (const el of inputs) {
    const fid = Number(el.dataset.fieldId || 0);
    if (!fid) continue;
    const ff = fields.find(x => x.id === fid);
    if (!ff) continue;
    if (getSubgroupMatchKey(ff) !== matchKey) continue;
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
  if (!resp.ok || !j.ok) throw new Error(j.error || tAdmin('api_error'));
  return j;
}

async function textSnippetsPost(payload){
  const resp = await fetch(textSnippetsApiUrl, {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify({ csrf_token: csrf, ...payload })
  });
  const j = await resp.json().catch(()=>({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || 'Textbaustein-API Fehler');
  return j;
}

async function fetchSnippetCategories(){
  if (Array.isArray(snippetCategoriesCache)) return snippetCategoriesCache;
  const j = await textSnippetsPost({ action: 'list' });
  const map = new Map();
  (j.snippets || []).forEach(s => {
    const id = String(s?.category_id || '').trim();
    const label = String(s?.category || '').trim() || 'Allgemein';
    if (!id || map.has(id)) return;
    map.set(id, label);
  });
  snippetCategoriesCache = [...map.entries()].map(([id, label]) => ({ id, label }))
    .sort((a,b) => String(a.label).localeCompare(String(b.label), 'de'));
  return snippetCategoriesCache;
}

async function optionListsPost(payload){
  const resp = await fetch(optionListsApiUrl, {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify({ csrf_token: csrf, ...payload })
  });
  const j = await resp.json().catch(()=>({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || tAdmin('option_api_error'));
  return j;
}

async function loadOptionTemplates(){
  const j = await optionListsPost({ action: 'list_templates' });
  optionTemplates = j.templates || [];

  bulkTpl.innerHTML = `<option value="">${tAdmin('option_template_none')}</option>` + optionTemplates.map(t=>{
    return `<option value="${t.id}">${escapeHtml(t.name)}</option>`;
  }).join('');

  optTpl.innerHTML = `<option value="">${tAdmin('option_empty')}</option>` + optionTemplates.map(t=>{
    return `<option value="${t.id}">${escapeHtml(t.name)}</option>`;
  }).join('');
}

async function fetchOptionTemplate(listId){
  const j = await optionListsPost({ action:'get_template', list_id: listId });
  return { template: j.template, items: j.items };
}

async function applyOptionTemplateToField(fieldId, listId){
  const { template: tpl, items } = await fetchOptionTemplate(listId);
  if (!tpl) throw new Error(tAdmin('template_not_found'));

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
  const sub = bulkSubgroup.value.trim();
  patch.meta_merge = {};
  if (g) patch.meta_merge.group = buildGroupPath(g, sub);

  if (bulkType.value) patch.type = bulkType.value;

  if (bulkChild.value !== '') patch.can_child_edit = Number(bulkChild.value);
  if (bulkTeacher.value !== '') patch.can_teacher_edit = Number(bulkTeacher.value);
  if (bulkRequired.value !== '') patch.required = bulkRequired.value === '1' ? 0 : 1;

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
  bulkSubgroup.value = '';
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
      nf.meta.group = tfmtAdmin('group_page', { page: String(p) });
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

function sanitizeFieldName(raw){
  const n = String(raw || '').trim().replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_.-]/g, '_');
  return n.replace(/^_+/, '').replace(/_+$/, '');
}

function makeUniqueFieldName(base){
  const taken = new Set((fields || []).map(f => String(f.name || '').trim()).filter(Boolean));
  let name = sanitizeFieldName(base || 'mapping_feld');
  if (!name) name = 'mapping_feld';
  if (!taken.has(name)) return name;
  let i = 2;
  while (taken.has(`${name}_${i}`)) i++;
  return `${name}_${i}`;
}

function nextTempFieldId(){
  let minId = 0;
  for (const f of (fields || [])) {
    const id = Number(f.id || 0);
    if (id < minId) minId = id;
  }
  return minId - 1;
}

function addVirtualField(){
  const rawName = window.prompt(tAdmin('new_field_name') || 'Feldname (technisch):', 'mapping_feld');
  if (rawName === null) return;
  const name = makeUniqueFieldName(rawName);
  if (!name) return;

  const rawLabel = window.prompt(tAdmin('new_field_label') || 'Label:', name);
  const label = (rawLabel === null || String(rawLabel).trim() === '') ? name : String(rawLabel).trim();

  const tempId = nextTempFieldId();
  const maxSort = (fields || []).reduce((m, f) => Math.max(m, Number(f.sort_order || 0)), 0);
  fields.push({
    id: tempId,
    name,
    type: 'text',
    label,
    label_en: '',
    help_text: '',
    multiline: 0,
    required: 0,
    can_child_edit: 0,
    can_teacher_edit: 1,
    sort_order: maxSort + 1,
    options: null,
    meta: { virtual_only: 1 }
  });
  dirty.add(tempId);
  rebuildGroupDatalist();
  renderGroupsBar();
  renderTable();
  updateMeta();
  updateDirtyUI();
  saveHint.textContent = tAdmin('new_field_added') || 'Neues Mapping-Feld hinzugefügt.';
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
  saveHint.textContent = `Gespeichert: ${j.saved}`;
  await load();
}

/* ---------- Options dialog ---------- */
function openOptionsModal(fieldId){
  modalFieldId = fieldId;
  const f = fields.find(x=>x.id===fieldId);
  if (!f) return;
  optSubtitle.textContent = f.name;
  optTpl.value = (f?.meta?.option_list_template_id ? String(f.meta.option_list_template_id) : '');
  optJson.value = (f.options != null) ? JSON.stringify(f.options, null, 2) : '';
  optionsModal.showModal();
}

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

function openSnippetCategoryModal(fieldId){
  modalFieldId = fieldId;
  const idx = fields.findIndex(x=>x.id===fieldId);
  if (idx < 0 || !snippetCategoryModal) return;
  const f = fields[idx];
  snippetCategorySubtitle.textContent = f.name || '';
  snippetCategoryList.innerHTML = '<div class="muted2">Lade Kategorien …</div>';
  const selected = Array.isArray(f?.meta?.snippet_category_ids)
    ? f.meta.snippet_category_ids.map(x => String(x).trim()).filter(Boolean)
    : [];
  const selectedSet = new Set(selected);

  fetchSnippetCategories().then((cats) => {
    snippetCategoryList.innerHTML = '';
    if (!cats.length) {
      snippetCategoryList.innerHTML = '<div class="muted2">Keine Kategorien vorhanden.</div>';
      return;
    }
    cats.forEach(cat => {
      const row = document.createElement('label');
      row.style.display = 'flex';
      row.style.alignItems = 'center';
      row.style.gap = '8px';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = cat.id;
      cb.checked = selectedSet.has(cat.id);
      const txt = document.createElement('span');
      txt.textContent = `${cat.label}`;
      row.append(cb, txt);
      snippetCategoryList.appendChild(row);
    });
  }).catch((e) => {
    snippetCategoryList.innerHTML = `<div class="muted2">${escapeHtml(e?.message || String(e))}</div>`;
  });
  snippetCategoryModal.showModal();
}

snippetCategoryModal?.addEventListener('close', ()=>{
  if (snippetCategoryModal.returnValue !== 'ok') { modalFieldId = null; return; }
  const fieldId = modalFieldId;
  modalFieldId = null;
  const idx = fields.findIndex(x=>x.id===fieldId);
  if (idx < 0) return;
  const picked = Array.from(snippetCategoryList.querySelectorAll('input[type="checkbox"]:checked'))
    .map(el => String(el.value || '').trim()).filter(Boolean);
  fields[idx].meta = fields[idx].meta || {};
  if (picked.length) fields[idx].meta.snippet_category_ids = picked;
  else delete fields[idx].meta.snippet_category_ids;
  markDirty(fieldId);
  renderTable();
  scanForSplitCandidate();
});

/* ---------- Preview resizer ---------- */
const LS_KEY = 'template_fields_preview_ratio_v2';
const LS_PREVIEW_HIDDEN_KEY = 'template_fields_preview_hidden_v1';

function applyPreviewRatio(ratio){
  const r = clamp(Number(ratio)||0.45, 0.22, 0.70);
  layout2.style.gridTemplateColumns = `${(1-r).toFixed(4)}fr 10px ${r.toFixed(4)}fr`;
  try { localStorage.setItem(LS_KEY, String(r)); } catch(e) {}
  if (!layout2.classList.contains('hide-preview')) setTimeout(()=>renderPage(), 60);
}

function applyPreviewHidden(hidden, persist = true){
  layout2.classList.toggle('hide-preview', hidden);
  previewCard.style.display = hidden ? 'none' : '';
  colResizer.style.display = hidden ? 'none' : '';
  btnTogglePreview.textContent = hidden ? tAdmin('preview_show') : tAdmin('preview_hide');
  if (persist) {
    try { localStorage.setItem(LS_PREVIEW_HIDDEN_KEY, hidden ? '1' : '0'); } catch(e) {}
  }
  if (!hidden) setTimeout(()=>renderPage(), 80);
}

(function initResizer(){
  try {
    const saved = localStorage.getItem(LS_KEY);
    if (saved) applyPreviewRatio(Number(saved));
  } catch(e) {}
  try {
    const hidden = localStorage.getItem(LS_PREVIEW_HIDDEN_KEY) === '1';
    if (hidden) applyPreviewHidden(true, false);
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
  if (!file) { replacePdfStatus.textContent = tAdmin('pdf_select_required'); return; }

  btnReplacePdf.disabled = true;
  replacePdfStatus.textContent = tAdmin('pdf_checking');

  try {
    const assessment = await assessNewPdfFile(file);
    let plannedMapping = {};
    if (assessment?.missing?.length) {
      const msg = tfmtAdmin('pdf_replace_warning', { count: String(assessment.missing.length), fields: assessment.missing.join(', ') });
      const proceed = confirm(msg);
      if (!proceed) { replacePdfStatus.textContent = tAdmin('pdf_replace_cancelled'); return; }
    }

    if (assessment?.missing?.length && assessment?.newcomers?.length) {
      const mapping = await promptPdfFieldMapping(assessment.missing, assessment.newcomers);
      if (mapping === null) { replacePdfStatus.textContent = tAdmin('pdf_mapping_cancelled'); return; }
      plannedMapping = mapping || {};
    }

    replacePdfStatus.textContent = tAdmin('pdf_uploading');

    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('template_id', String(templateId));
    fd.append('pdf', file);

    const resp = await fetch(replacePdfUrl, { method:'POST', body: fd });
    const data = await resp.json().catch(()=>({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || tfmtAdmin('pdf_upload_failed', { status: String(resp.status) }));

    pdfUrl = String(data.pdf_url || pdfUrl) + '&cache=' + Date.now();
    replacePdfStatus.textContent = tAdmin('pdf_saved_updating');

    await loadPdf();
    const summary = await syncPdfPositionsWithFields({ mapping: plannedMapping });
    const parts = [
      tfmtAdmin('pdf_summary_updated', { count: String(summary.updated) }),
      tfmtAdmin('pdf_summary_missing', { count: String(summary.missing) }),
      tfmtAdmin('pdf_summary_added', { count: String(summary.added) })
    ];
    if (summary.renamed) parts.push(tfmtAdmin('pdf_summary_renamed', { count: String(summary.renamed) }));
    if (summary.deleted) parts.push(tfmtAdmin('pdf_summary_deleted', { count: String(summary.deleted) }));
    let statusText = parts.join(', ');
    const rcffFile = replaceRcffInput?.files?.[0] ?? null;
    if (rcffFile) {
      const rcffFd = new FormData();
      rcffFd.append('csrf_token', csrf);
      rcffFd.append('template_id', String(templateId));
      rcffFd.append('rcff', rcffFile);
      const rcffResp = await fetch("<?=h(url('admin/ajax/import_rcff.php'))?>", { method:'POST', body: rcffFd });
      const rcffData = await rcffResp.json().catch(()=>({}));
      if (!rcffResp.ok || !rcffData.ok) throw new Error(rcffData.error || 'RCFF-Import fehlgeschlagen.');
      const s = rcffData.stats || {};
      statusText += ` | RCFF importiert: ${s.read||0} gelesen, ${s.matched||0} gematcht, ${s.updated||0} Felder aktualisiert, ${s.labels_updated||0} Labels, ${s.groups_updated||0} Gruppen, ${s.subgroups_updated||0} Untergruppen, ${s.meta_updated||0} Meta aktualisiert, ${s.ignored||0} ignoriert, ${s.skipped||0} übersprungen.`;
      await load();
    }
    replacePdfStatus.textContent = statusText;
  } catch (e) {
    replacePdfStatus.textContent = tAdmin('error_prefix') + ' ' + (e?.message || e);
    } finally {
      btnReplacePdf.disabled = false;
      if (replacePdfInput) replacePdfInput.value = '';
      if (replaceRcffInput) replaceRcffInput.value = '';
    }
});

btnDeleteMissing.addEventListener('click', async ()=>{
  if (!lastMissingNames.length) { replacePdfStatus.textContent = tAdmin('pdf_missing_none'); return; }
  const want = confirm(tfmtAdmin('pdf_delete_confirm', { fields: lastMissingNames.join(', ') }));
  if (!want) return;
  try {
    const deleted = await deleteFieldsByName(lastMissingNames);
    replacePdfStatus.textContent = tfmtAdmin('pdf_deleted', { count: String(deleted) });
    updateMissingDeleteButton([]);
    await load();
  } catch (e) {
    replacePdfStatus.textContent = tAdmin('pdf_delete_error') + ' ' + (e?.message || e);
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
  const hidden = !layout2.classList.contains('hide-preview');
  applyPreviewHidden(hidden);
});
if (btnAddVirtualField) {
  btnAddVirtualField.addEventListener('click', addVirtualField);
}

/* ---------- Load ---------- */
async function load(){
  const resp = await fetch(apiUrl + "?action=list&template_id=" + encodeURIComponent(templateId), { headers:{ 'X-CSRF-Token': csrf }});
  const j = await resp.json().catch(()=>({}));
  if (!resp.ok || !j.ok) throw new Error(j.error || tAdmin('load_fields_error'));

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
  updateDirtyUI();

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

    pdfHint.textContent = tfmtAdmin('pdf_marked', { name: currentHighlight.name });
  } else {
    pdfHint.textContent = tAdmin('pdf_hint_default');
  }

  pageInfo.textContent = tfmtAdmin('page_info', { page: String(currentPage), total: String(pdfDoc.numPages) });
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
    pdfHint.textContent = tAdmin('pdf_hit_none');
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
    pdfHint.textContent = tfmtAdmin('pdf_hit_hidden', { name: hit.name });
  }
});

load().catch(err=>{ metaLine.textContent = tAdmin('error_prefix') + ' ' + (err?.message || err); });
</script>

<?php render_admin_footer(); ?>
