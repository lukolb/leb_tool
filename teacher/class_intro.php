<?php
// teacher/class_intro.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/tts_audio_cache.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$err = '';
$ok = '';

function child_intro_file_abs(string $lang = 'de'): string {
  $cfg = app_config();
  $uploadsRel = (string)($cfg['app']['uploads_dir'] ?? 'uploads');
  $rootAbs = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
  $filename = strtolower($lang) === 'en' ? 'child_intro_en.html' : 'child_intro.html';
  return rtrim($rootAbs, '/\\') . '/' . trim($uploadsRel, '/\\') . '/' . $filename;
}

function sanitize_intro_html(string $html): string {
  $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
  return trim($html);
}

function known_intro_placeholders(): array {
  return [
    '{{org_name}}'      => t('teacher.class_intro.placeholder.org_name'),
    '{{student_name}}'  => t('teacher.class_intro.placeholder.student_name'),
    '{{first_name}}'    => t('teacher.class_intro.placeholder.first_name'),
    '{{last_name}}'     => t('teacher.class_intro.placeholder.last_name'),
    '{{class}}'         => t('teacher.class_intro.placeholder.class'),
    '{{school_year}}'   => t('teacher.class_intro.placeholder.school_year'),
  ];
}

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0) {
  $err = t('teacher.class_intro.missing_class');
}

if (!$err && ($u['role'] ?? '') !== 'admin' && !user_can_manage_class($pdo, $userId, $classId)) {
  $err = t('teacher.class_intro.no_access');
}

$classRow = null;
if (!$err) {
  $st = $pdo->prepare("SELECT id, school_year, grade_level, label, name, student_intro_html, student_intro_html_en FROM classes WHERE id=? LIMIT 1");
  $st->execute([$classId]);
  $classRow = $st->fetch(PDO::FETCH_ASSOC);
  if (!$classRow) {
    $err = t('teacher.class_intro.not_found');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'save_class_intro') {
      $html = (string)($_POST['student_intro_html'] ?? '');
      $html = sanitize_intro_html($html);
      $htmlEn = sanitize_intro_html((string)($_POST['student_intro_html_en'] ?? ''));
      $trimmed = trim($html);
      $store = ($trimmed === '') ? null : $html;
      $storeEn = trim($htmlEn) === '' ? null : $htmlEn;

      $pdo->prepare("UPDATE classes SET student_intro_html=?, student_intro_html_en=? WHERE id=?")
          ->execute([$store, $storeEn, $classId]);
      $cleanup = tts_audio_cleanup_class_plan($pdo, $classId);

      audit('class_set_student_intro', $userId, [
        'class_id'=>$classId,
        'obsolete_audio_files_deleted'=>(int)$cleanup['files'],
      ]);
      $ok = t('teacher.class_intro.saved');

      $st = $pdo->prepare("SELECT id, school_year, grade_level, label, name, student_intro_html, student_intro_html_en FROM classes WHERE id=? LIMIT 1");
      $st->execute([$classId]);
      $classRow = $st->fetch(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$introAbs = child_intro_file_abs('de');
$defaultIntroHtml = '';
if (is_file($introAbs)) {
  $defaultIntroHtml = sanitize_intro_html((string)file_get_contents($introAbs));
}
$introAbsEn = child_intro_file_abs('en');
$defaultIntroHtmlEn = is_file($introAbsEn) ? sanitize_intro_html((string)file_get_contents($introAbsEn)) : $defaultIntroHtml;

$classIntroHtml = $classRow ? (string)($classRow['student_intro_html'] ?? '') : '';
$classIntroHtmlEn = $classRow ? (string)($classRow['student_intro_html_en'] ?? '') : '';
$hasOverride = trim($classIntroHtml) !== '';
$hasOverrideEn = trim($classIntroHtmlEn) !== '';
$initialHtml = $hasOverride ? $classIntroHtml : $defaultIntroHtml;
$initialHtmlEn = $hasOverrideEn ? $classIntroHtmlEn : $defaultIntroHtmlEn;

$defaultIntroHtmlText = t('teacher.class_intro.default_html');

render_teacher_header(t('teacher.class_intro.title'));
?>

<div class="card">
  <h1><?=h(t('teacher.class_intro.title'))?></h1>
  <?php if ($classRow): ?>
    <p class="muted">
      <?=h(t('teacher.class_intro.class_label'))?>:
      <strong><?=h(($classRow['grade_level'] ?? '') !== null && (string)$classRow['label'] !== '' ? ((int)$classRow['grade_level'] . (string)$classRow['label']) : (string)($classRow['name'] ?? ''))?></strong>
    </p>
  <?php endif; ?>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<?php if ($classRow): ?>
  <div class="card">
    <p class="muted">
      <?=h(t('teacher.class_intro.hint'))?>
    </p>

    <div class="panel" style="padding:10px; margin-bottom:10px;">
      <label><?=h(t('teacher.class_intro.placeholders'))?></label>
      <div class="actions" style="justify-content:flex-start; gap:10px; flex-wrap:wrap; margin-top:6px;">
        <select id="phSelect" class="input" style="min-width:260px;">
          <?php foreach (known_intro_placeholders() as $token => $label): ?>
            <option value="<?=h($token)?>"><?=h($label)?> — <?=h($token)?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn secondary" type="button" id="btnInsertPh"><?=h(t('teacher.class_intro.insert'))?></button>
        <span class="muted"><?=h(t('teacher.class_intro.example'))?></span>
      </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
    <style>
      .intro-editor{ background:#fff; border-radius:14px; overflow:hidden; }
      .intro-editor .ql-toolbar{ border-top-left-radius:14px; border-top-right-radius:14px; }
      .intro-editor .ql-container{ border-bottom-left-radius:14px; border-bottom-right-radius:14px; min-height:220px; }
    </style>

    <form method="post" id="classIntroForm">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="save_class_intro">
      <input type="hidden" name="student_intro_html" id="studentIntroHtml">
      <input type="hidden" name="student_intro_html_en" id="studentIntroHtmlEn">

      <h3>🇩🇪 <?=h(t('teacher.class_intro.german', 'Deutsch'))?></h3>
      <div id="quillEditorDe" class="intro-editor"></div>
      <h3 style="margin-top:18px;">🇺🇸 <?=h(t('teacher.class_intro.english', 'Englisch'))?></h3>
      <div id="quillEditorEn" class="intro-editor"></div>

      <div class="actions" style="margin-top:12px;">
        <button class="btn primary" type="submit"><?=h(t('teacher.class_intro.save'))?></button>
        <a class="btn secondary" href="<?=h(url('teacher/classes.php'))?>"><?=h(t('teacher.class_intro.back'))?></a>
      </div>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
    <script>
    (function(){
      const initialHtml = <?=json_encode($initialHtml)?>;
      const initialHtmlEn = <?=json_encode($initialHtmlEn)?>;
      const hasOverride = <?=json_encode($hasOverride)?>;
      const hasOverrideEn = <?=json_encode($hasOverrideEn)?>;

      const quillOptions = {
        theme: 'snow',
        modules: {
          toolbar: [
            [{ header: [1, 2, false] }],
            ['bold', 'italic', 'underline'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link'],
            ['clean']
          ]
        }
      };
      const quillDe = new Quill('#quillEditorDe', quillOptions);
      const quillEn = new Quill('#quillEditorEn', quillOptions);
      let activeQuill = quillDe;
      quillDe.root.addEventListener('focus', () => { activeQuill = quillDe; });
      quillEn.root.addEventListener('focus', () => { activeQuill = quillEn; });

      const defaultIntroHtml = <?=json_encode($defaultIntroHtmlText)?>;
      const defaultIntroHtmlEn = <?=json_encode(t('teacher.class_intro.default_html_en', '<p><strong>Hello {{first_name}}!</strong></p><p>Please complete your report step by step.</p>'))?>;

      if (initialHtml) {
        quillDe.root.innerHTML = initialHtml;
      } else {
        quillDe.root.innerHTML = hasOverride
          ? '<p></p>'
          : defaultIntroHtml;
      }
      if (initialHtmlEn) {
        quillEn.root.innerHTML = initialHtmlEn;
      } else {
        quillEn.root.innerHTML = hasOverrideEn ? '<p></p>' : defaultIntroHtmlEn;
      }

      const hidden = document.getElementById('studentIntroHtml');
      const hiddenEn = document.getElementById('studentIntroHtmlEn');
      const form = document.getElementById('classIntroForm');

      form.addEventListener('submit', () => {
        hidden.value = quillDe.root.innerHTML || '';
        hiddenEn.value = quillEn.root.innerHTML || '';
      });

      const sel = document.getElementById('phSelect');
      const btn = document.getElementById('btnInsertPh');
      btn.addEventListener('click', () => {
        const token = sel.value || '';
        if (!token) return;
        const range = activeQuill.getSelection(true);
        const pos = range ? range.index : activeQuill.getLength();
        activeQuill.insertText(pos, token, 'user');
        activeQuill.setSelection(pos + token.length, 0, 'user');
        activeQuill.focus();
      });
    })();
    </script>
  </div>
<?php endif; ?>

<?php render_teacher_footer(); ?>
