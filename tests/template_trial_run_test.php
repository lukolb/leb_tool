<?php
declare(strict_types=1);

function template_trial_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$menu = file_get_contents($root . '/admin/templates.php');
$page = file_get_contents($root . '/admin/template_trial.php');
$helper = file_get_contents($root . '/shared/template_trial.php');
$generator = file_get_contents($root . '/assets/js/report_pdf_generator.mjs');
$translations = file_get_contents($root . '/shared/translations.php');

foreach ([$menu, $page, $helper, $generator, $translations] as $source) {
  template_trial_assert(is_string($source), 'template trial sources must be readable');
}

template_trial_assert(
  str_contains($menu, "admin/template_trial.php?template_id=")
    && str_contains($menu, "t('admin.templates.action.trial')"),
  'each template action menu must link to the trial run'
);

template_trial_assert(
  str_contains($page, 'data-student="first_name"')
    && str_contains($page, 'value="Max"')
    && str_contains($page, 'value="Mustermann"')
    && str_contains($page, 'value="2020-01-01"')
    && str_contains($page, 'data-custom-field=')
    && str_contains($page, 'data-class="school_year"')
    && str_contains($page, 'data-ag-name')
    && str_contains($page, 'data-trial-field='),
  'the trial run must accept master data, custom student data, class data, AGs and every report field'
);

template_trial_assert(
  str_contains($page, 'id="trialRandomizeButton"')
    && str_contains($page, 'function randomizeTrialData()')
    && str_contains($page, "document.querySelectorAll('[data-trial-field]')")
    && str_contains($page, "document.querySelectorAll('[data-ag-name]')")
    && str_contains($page, 'I18N.random_long'),
  'one action must randomly fill regular fields, free text and activities while retaining the sample student'
);

template_trial_assert(
  str_contains($page, 'function scheduleAutomaticRefresh()')
    && str_contains($page, "el.addEventListener('input',scheduleAutomaticRefresh)")
    && str_contains($page, "el.addEventListener('change',scheduleAutomaticRefresh)")
    && str_contains($page, 'refreshAutomaticFields();')
    && str_contains($page, 'data-automatic-kind='),
  'automatic fields must be clearly marked and refreshed without a page reload after dependent input changes'
);

template_trial_assert(
  str_contains($page, 'generateFilledReportPdf({')
    && str_contains($page, 'pdfLibUrl: PDF_LIB_URL')
    && str_contains($page, "url('assets/pdf-lib.min.js')")
    && str_contains($page, 'fieldValues: result.values_by_name')
    && str_contains($page, 'pdfjsLib.getDocument({data:new Uint8Array(renderedPdfBytes)})')
    && !str_contains($page, 'trial-overlay')
    && !str_contains($page, 'renderField(overlay'),
  'the preview must render the generated filled PDF and must not use HTML field overlays'
);

template_trial_assert(
  str_contains($generator, 'PDFLib.PDFDocument.load')
    && str_contains($generator, 'fillForm(pdfDoc')
    && str_contains($generator, 'updateTextAppearances')
    && str_contains($generator, 'applyRadioCrosses')
    && str_contains($generator, 'drawSignaturePlaceholders')
    && str_contains($generator, 'pdfDoc.save()'),
  'the trial run must build the final AcroForm PDF with the same core steps as the report preview'
);

foreach (['zip', 'merged', 'single', 'booklet_single', 'booklet_merged'] as $mode) {
  template_trial_assert(
    str_contains($page, 'value="' . $mode . '"'),
    "the regular {$mode} export variant must be available in the trial run"
  );
}
template_trial_assert(
  str_contains($page, 'flattenReportPdf(sourceBytes,PDF_LIB_URL)')
    && str_contains($page, 'createReportBookletPdf(finalBytes,PDF_LIB_URL)')
    && str_contains($page, "jszip@3.10.1")
    && str_contains($page, 'zip.file(`${studentName}.pdf`,finalBytes)')
    && str_contains($generator, 'export async function flattenReportPdf')
    && str_contains($generator, 'export async function createReportBookletPdf'),
  'trial exports must apply the normal flattening, ZIP and booklet transformations'
);

foreach (['admin/template_trial.php' => $page, 'shared/template_trial.php' => $helper] as $name => $source) {
  template_trial_assert(
    !preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[a-z_]|DELETE\s+FROM|REPLACE\s+INTO)\b/i', $source),
    "{$name} must not contain a persistent database write path"
  );
}

template_trial_assert(
  !str_contains($page, 'localStorage')
    && !str_contains($page, 'sessionStorage')
    && !str_contains($page, 'audit(')
    && !str_contains($helper, 'audit('),
  'trial data must not be retained in browser storage or the audit log'
);

template_trial_assert(
  substr_count($translations, "'admin.templates.action.trial'") === 2
    && substr_count($translations, "'admin.template_trial.notice_text'") === 2,
  'the trial entry point and no-save notice must be translated in German and English'
);

putenv('APP_CONFIG_FILE=' . $root . '/config.sample.php');
require_once $root . '/bootstrap.php';
require_once $root . '/shared/template_trial.php';

template_trial_assert(template_trial_is_signature_field('text', 'teachersign'), 'legacy teacher signature names must be detected');
template_trial_assert(template_trial_is_signature_field('text', 'headsign'), 'legacy head signature names must be detected');
template_trial_assert(template_trial_is_signature_field('text', 'guardiansign'), 'legacy guardian signature names must be detected');
template_trial_assert(!template_trial_is_signature_field('text', 'design'), 'ordinary field names ending in sign-like letters must stay editable');

$definition = [
  'custom_fields' => [
    ['field_key' => 'pronoun', 'default_value' => ''],
  ],
  'ags' => [
    ['name' => 'Theater-AG'],
    ['name' => 'Schach-AG'],
  ],
  'fields' => [
    [
      'id' => 1, 'field_name' => 'Name', 'field_type' => 'text', 'system_bound' => 1,
      'binding_template' => '{{student.first_name}} {{student.last_name}}', 'binding' => '',
      'date_format' => '', 'meta' => [],
    ],
    [
      'id' => 2, 'field_name' => 'Klasse', 'field_type' => 'text', 'system_bound' => 1,
      'binding_template' => '{{class.display}}', 'binding' => '',
      'date_format' => '', 'meta' => [],
    ],
    [
      'id' => 3, 'field_name' => 'AG', 'field_type' => 'multiline', 'system_bound' => 1,
      'binding_template' => '{{ag.sentence}}', 'binding' => '',
      'date_format' => '', 'meta' => [],
    ],
    [
      'id' => 4, 'field_name' => 'Kommentar', 'field_type' => 'multiline', 'system_bound' => 0,
      'binding_template' => '', 'binding' => '', 'date_format' => '', 'meta' => [],
    ],
    [
      'id' => 5, 'field_name' => 'Anrede', 'field_type' => 'text', 'system_bound' => 1,
      'binding_template' => '{{student.custom.pronoun}}', 'binding' => '',
      'date_format' => '', 'meta' => [],
    ],
    [
      'id' => 6, 'field_name' => 'teachersign', 'label' => 'Unterschrift Lehrkraft',
      'field_type' => 'signature', 'system_bound' => 1, 'automatic_kind' => 'signature',
      'binding_template' => '', 'binding' => '', 'date_format' => '', 'meta' => [],
    ],
    [
      'id' => 7, 'field_name' => 'pdf_default', 'label' => 'PDF-Vorbelegung',
      'field_type' => 'text', 'system_bound' => 1, 'automatic_kind' => 'template',
      'binding_template' => '', 'binding' => '', 'date_format' => '', 'meta' => [],
    ],
  ],
];

$resolved = template_trial_resolve($definition, [
  'student' => [
    'first_name' => 'Mia',
    'last_name' => 'Muster',
    'date_of_birth' => '2019-03-02',
    'custom_fields' => ['pronoun' => 'sie'],
  ],
  'class' => ['grade_level' => 1, 'label' => 'a', 'school_year' => '2026/27', 'period_label' => 'H1'],
  'field_values' => ['4' => 'Ein flüchtiger Testkommentar.'],
  'ag_names' => ['Theater-AG', 'Schach-AG', 'Nicht erlaubt'],
]);

template_trial_assert(($resolved['values_by_name']['Name'] ?? '') === 'Mia Muster', 'student mapping must resolve in memory');
template_trial_assert(($resolved['values_by_name']['Klasse'] ?? '') === '1a', 'class mapping must resolve in memory');
template_trial_assert(($resolved['values_by_name']['Kommentar'] ?? '') === 'Ein flüchtiger Testkommentar.', 'manual report values must reach the generated PDF map');
template_trial_assert(($resolved['values_by_name']['Anrede'] ?? '') === 'sie', 'custom student fields must resolve in memory');
template_trial_assert(($resolved['values_by_name']['teachersign'] ?? '') === 'M. Musterlehrkraft', 'signature fields must receive a read-only trial signature');
template_trial_assert(!array_key_exists('pdf_default', $resolved['values_by_name']), 'unmapped read-only fields must retain their PDF template defaults');
template_trial_assert(
  ($resolved['values_by_name']['AG'] ?? '') === 'Mia hat erfolgreich an der Theater-AG und der Schach-AG teilgenommen.',
  'AG selection must use the regular report sentence generator and reject unknown catalog values'
);

fwrite(STDOUT, "template_trial_run_test: OK\n");
