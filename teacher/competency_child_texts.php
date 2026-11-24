<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/tts_audio_cache.php';
require_teacher();

$pdo = db();
$user = current_user() ?? [];
$userId = (int)($user['id'] ?? 0);
$isAdmin = (string)($user['role'] ?? '') === 'admin';

function competency_child_accessible_templates(PDO $pdo, int $userId, bool $isAdmin): array {
  if ($isAdmin) {
    $st = $pdo->query(
      "SELECT DISTINCT t.id, t.name, t.template_version
         FROM templates t
         INNER JOIN template_fields tf ON tf.template_id=t.id AND tf.can_child_edit=1
        WHERE t.is_active=1
        ORDER BY t.name ASC, t.template_version DESC, t.id DESC"
    );
  } else {
    $st = $pdo->prepare(
      "SELECT DISTINCT t.id, t.name, t.template_version
         FROM templates t
         INNER JOIN template_fields tf ON tf.template_id=t.id AND tf.can_child_edit=1
         INNER JOIN classes c ON c.template_id=t.id AND c.is_active=1
         LEFT JOIN user_class_assignments uca ON uca.class_id=c.id AND uca.user_id=?
         LEFT JOIN class_group_delegations cgd ON cgd.class_id=c.id AND cgd.user_id=? AND cgd.status='open'
        WHERE t.is_active=1 AND (uca.user_id IS NOT NULL OR cgd.user_id IS NOT NULL)
        ORDER BY t.name ASC, t.template_version DESC, t.id DESC"
    );
    $st->execute([$userId, $userId]);
  }

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $template) {
    $templateId = (int)($template['id'] ?? 0);
    if ($templateId <= 0) continue;
    $catalog = competency_child_text_catalog($pdo, $templateId);
    if (!$catalog) continue;
    $template['competency_count'] = count($catalog);
    $out[$templateId] = $template;
  }
  return $out;
}

function competency_child_text_length(string $value): int {
  return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$templates = competency_child_accessible_templates($pdo, $userId, $isAdmin);
$requestedTemplateId = (int)($_POST['template_id'] ?? $_GET['template_id'] ?? 0);
$templateId = isset($templates[$requestedTemplateId]) ? $requestedTemplateId : (int)(array_key_first($templates) ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    if ($templateId <= 0 || !isset($templates[$templateId])) {
      throw new RuntimeException(t('competency_child_texts.error.template', 'Die Vorlage ist nicht verfügbar.'));
    }
    $catalog = competency_child_text_catalog($pdo, $templateId);
    $catalogByKey = [];
    foreach ($catalog as $item) $catalogByKey[(string)$item['competency_key']] = $item;
    $action = trim((string)($_POST['action'] ?? 'save_single'));

    if ($action === 'save_ai_batch') {
      $texts = json_decode((string)($_POST['texts_json'] ?? ''), true);
      if (!is_array($texts) || !$texts || count($texts) > count($catalogByKey)) {
        throw new RuntimeException(t('competency_child_texts.ai_batch_save_invalid', 'Die KI-Vorschläge konnten nicht gespeichert werden.'));
      }
      $upsert = $pdo->prepare(
        "INSERT INTO template_competency_child_texts
          (template_id, competency_key, teacher_text_hash, teacher_text_de, teacher_text_en,
           child_text_de, child_text_en, updated_by_user_id)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           teacher_text_hash=VALUES(teacher_text_hash), teacher_text_de=VALUES(teacher_text_de),
           teacher_text_en=VALUES(teacher_text_en), child_text_de=VALUES(child_text_de),
           child_text_en=VALUES(child_text_en), updated_by_user_id=VALUES(updated_by_user_id),
           updated_at=CURRENT_TIMESTAMP"
      );
      $savedCount = 0;
      $pdo->beginTransaction();
      foreach ($texts as $text) {
        if (!is_array($text)) throw new RuntimeException(t('competency_child_texts.ai_batch_save_invalid', 'Die KI-Vorschläge konnten nicht gespeichert werden.'));
        $key = trim((string)($text['key'] ?? ''));
        $childDe = trim((string)($text['de'] ?? ''));
        $childEn = trim((string)($text['en'] ?? ''));
        if ($key === '' || !isset($catalogByKey[$key]) || $childDe === '' || $childEn === '') {
          throw new RuntimeException(t('competency_child_texts.ai_batch_save_invalid', 'Die KI-Vorschläge konnten nicht gespeichert werden.'));
        }
        if (competency_child_text_length($childDe) > 2000 || competency_child_text_length($childEn) > 2000) {
          throw new RuntimeException(t('competency_child_texts.error.length', 'Ein Kindertext darf höchstens 2.000 Zeichen lang sein.'));
        }
        $item = $catalogByKey[$key];
        $upsert->execute([
          $templateId, $key, (string)$item['teacher_text_hash'], (string)$item['teacher_text_de'],
          (string)$item['teacher_text_en'], $childDe, $childEn, $userId > 0 ? $userId : null,
        ]);
        $savedCount++;
      }
      $pdo->commit();
      audit('competency_child_text_ai_batch_save', $userId, ['template_id' => $templateId, 'count' => $savedCount]);
      tts_audio_cleanup_template_classes($pdo, $templateId);
      header('Location: ' . url('teacher/competency_child_texts.php?template_id=' . $templateId . '&saved=batch'));
      exit;
    }

    $competencyKey = trim((string)($_POST['competency_key'] ?? ''));
    if ($competencyKey === '' || !isset($catalogByKey[$competencyKey])) {
      throw new RuntimeException(t('competency_child_texts.error.competency', 'Die Kompetenz wurde in dieser Vorlage nicht gefunden.'));
    }

    $reset = (string)($_POST['reset'] ?? '') === '1';
    $childDe = $reset ? '' : trim((string)($_POST['child_text_de'] ?? ''));
    $childEn = $reset ? '' : trim((string)($_POST['child_text_en'] ?? ''));
    if (competency_child_text_length($childDe) > 2000 || competency_child_text_length($childEn) > 2000) {
      throw new RuntimeException(t('competency_child_texts.error.length', 'Ein Kindertext darf höchstens 2.000 Zeichen lang sein.'));
    }

    $item = $catalogByKey[$competencyKey];
    if ($childDe === '' && $childEn === '') {
      $pdo->prepare("DELETE FROM template_competency_child_texts WHERE template_id=? AND competency_key=?")
        ->execute([$templateId, $competencyKey]);
      $event = 'competency_child_text_reset';
    } else {
      $pdo->prepare(
        "INSERT INTO template_competency_child_texts
          (template_id, competency_key, teacher_text_hash, teacher_text_de, teacher_text_en,
           child_text_de, child_text_en, updated_by_user_id)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           teacher_text_hash=VALUES(teacher_text_hash),
           teacher_text_de=VALUES(teacher_text_de),
           teacher_text_en=VALUES(teacher_text_en),
           child_text_de=VALUES(child_text_de),
           child_text_en=VALUES(child_text_en),
           updated_by_user_id=VALUES(updated_by_user_id),
           updated_at=CURRENT_TIMESTAMP"
      )->execute([
        $templateId,
        $competencyKey,
        (string)$item['teacher_text_hash'],
        (string)$item['teacher_text_de'],
        (string)$item['teacher_text_en'],
        $childDe !== '' ? $childDe : null,
        $childEn !== '' ? $childEn : null,
        $userId > 0 ? $userId : null,
      ]);
      $event = 'competency_child_text_save';
    }

    audit($event, $userId, [
      'template_id' => $templateId,
      'competency_key' => $competencyKey,
      'has_de' => $childDe !== '',
      'has_en' => $childEn !== '',
    ]);
    tts_audio_cleanup_template_classes($pdo, $templateId);

    $anchor = 'competency-' . substr(hash('sha256', $competencyKey), 0, 12);
    header('Location: ' . url('teacher/competency_child_texts.php?template_id=' . $templateId . '&saved=1#' . $anchor));
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

$catalog = $templateId > 0 ? competency_child_text_catalog($pdo, $templateId) : [];
$overrides = $templateId > 0 ? competency_child_text_overrides($pdo, $templateId) : [];
$hashes = array_values(array_unique(array_filter(array_map(static fn(array $item): string => (string)$item['teacher_text_hash'], $catalog))));
$suggestionsByHash = [];
if ($hashes && db_has_table($pdo, 'template_competency_child_texts')) {
  $placeholders = implode(',', array_fill(0, count($hashes), '?'));
  $stSuggestions = $pdo->prepare(
    "SELECT c.teacher_text_hash, c.child_text_de, c.child_text_en,
            t.id AS template_id, t.name AS template_name, t.template_version
       FROM template_competency_child_texts c
       INNER JOIN templates t ON t.id=c.template_id
      WHERE c.template_id<>? AND c.teacher_text_hash IN ($placeholders)
        AND (COALESCE(TRIM(c.child_text_de),'')<>'' OR COALESCE(TRIM(c.child_text_en),'')<>'')
      ORDER BY c.updated_at DESC, c.id DESC"
  );
  $stSuggestions->execute(array_merge([$templateId], $hashes));
  $seenSuggestions = [];
  foreach ($stSuggestions->fetchAll(PDO::FETCH_ASSOC) ?: [] as $suggestion) {
    $hash = (string)($suggestion['teacher_text_hash'] ?? '');
    $dedupe = $hash . "\n" . trim((string)($suggestion['child_text_de'] ?? '')) . "\n" . trim((string)($suggestion['child_text_en'] ?? ''));
    if ($hash === '' || isset($seenSuggestions[$dedupe])) continue;
    $seenSuggestions[$dedupe] = true;
    $suggestionsByHash[$hash][] = $suggestion;
  }
}

render_teacher_header(t('competency_child_texts.title', 'Kindertexte für Kompetenzen'));
?>
<div class="card">
  <h1><?=h(t('competency_child_texts.title', 'Kindertexte für Kompetenzen'))?></h1>
  <p class="muted"><?=h(t('competency_child_texts.intro', 'Passe die deutschen und englischen Kompetenztexte für Kinder an. Die Lehrkrafttexte bleiben unverändert. Pro Vorlage und Kompetenz gilt ein gemeinsamer Text für alle Lehrkräfte.'))?></p>
</div>

<?php if ($error !== ''): ?><div class="alert danger"><strong><?=h($error)?></strong></div><?php endif; ?>
<?php if ((string)($_GET['saved'] ?? '') === '1'): ?><div class="alert success"><strong><?=h(t('competency_child_texts.saved', 'Der gemeinsame Kindertext wurde gespeichert.'))?></strong></div><?php endif; ?>
<?php if ((string)($_GET['saved'] ?? '') === 'batch'): ?><div class="alert success"><strong><?=h(t('competency_child_texts.ai_batch_saved', 'Die geprüften KI-Vorschläge wurden gemeinsam gespeichert und werden bei der Audiogenerierung berücksichtigt.'))?></strong></div><?php endif; ?>

<?php if (!$templates): ?>
  <div class="card"><p><?=h(t('competency_child_texts.no_templates', 'Für deine Klassen ist keine Vorlage mit Schüler-Kompetenzen verfügbar.'))?></p></div>
<?php else: ?>
  <div class="card competency-child-toolbar">
    <form method="get">
      <label for="templateId"><strong><?=h(t('competency_child_texts.template', 'Vorlage'))?></strong></label>
      <select class="input" id="templateId" name="template_id" onchange="this.form.submit()">
        <?php foreach ($templates as $id => $template): ?>
          <option value="<?= (int)$id ?>" <?=$id===$templateId?'selected':''?>><?=h((string)$template['name'])?> · v<?=h((string)$template['template_version'])?> (<?= (int)$template['competency_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </form>
    <div>
      <label for="competencySearch"><strong><?=h(t('competency_child_texts.search', 'Kompetenz suchen'))?></strong></label>
      <input class="input" id="competencySearch" type="search" placeholder="<?=h(t('competency_child_texts.search_placeholder', 'Text, Code oder Bereich'))?>">
    </div>
  </div>

  <?php $aiConfig = app_config()['ai'] ?? []; $aiAvailable = (bool)($aiConfig['enabled'] ?? true) && trim((string)($aiConfig['api_key'] ?? getenv('OPENAI_API_KEY') ?: '')) !== ''; ?>
  <div class="card competency-child-ai-panel">
    <div>
      <h2><?=h(t('competency_child_texts.ai_title', 'Kindertexte mit KI vorbereiten'))?></h2>
      <p class="muted"><?=h(t('competency_child_texts.ai_intro', 'Die hinterlegte KI erstellt gesammelt deutsche und englische Vorschläge. Prüfe und bearbeite sie vor dem gemeinsamen Speichern.'))?></p>
      <label class="choice-chip"><input type="checkbox" id="aiReplaceExisting" value="1"> <span><?=h(t('competency_child_texts.ai_replace_existing', 'Auch bereits gespeicherte Kindertexte neu vorschlagen'))?></span></label>
    </div>
    <div class="competency-child-ai-actions">
      <button class="btn" type="button" id="aiGenerateAll" <?=$aiAvailable?'':'disabled'?>><?=h(t('competency_child_texts.ai_generate', 'KI-Vorschläge für Vorlage erstellen'))?></button>
      <button class="btn" type="button" id="aiSaveAll" hidden><?=h(t('competency_child_texts.ai_save_all', 'Geprüfte Vorschläge gemeinsam speichern'))?></button>
    </div>
    <p class="competency-child-ai-status" id="aiBatchStatus" aria-live="polite"><?php if (!$aiAvailable): ?><?=h(t('competency_child_texts.ai_unavailable', 'KI-Vorschläge sind deaktiviert oder es ist kein API-Schlüssel hinterlegt.'))?><?php endif; ?></p>
    <form method="post" id="aiBatchSaveForm" hidden>
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
      <input type="hidden" name="action" value="save_ai_batch">
      <input type="hidden" name="texts_json" value="">
    </form>
  </div>

  <div id="competencyChildList">
  <?php foreach ($catalog as $item):
    $key = (string)$item['competency_key'];
    $override = $overrides[$key] ?? [];
    $childDe = (string)($override['child_text_de'] ?? '');
    $childEn = (string)($override['child_text_en'] ?? '');
    $hash = (string)$item['teacher_text_hash'];
    $suggestions = $suggestionsByHash[$hash] ?? [];
    $anchor = 'competency-' . substr(hash('sha256', $key), 0, 12);
    $category = ui_lang() === 'en' && trim((string)$item['category_en']) !== '' ? (string)$item['category_en'] : (string)$item['category_de'];
    $subcategory = ui_lang() === 'en' && trim((string)$item['subcategory_en']) !== '' ? (string)$item['subcategory_en'] : (string)$item['subcategory_de'];
    $searchText = implode(' ', [(string)$item['code'], $category, $subcategory, (string)$item['teacher_text_de'], (string)$item['teacher_text_en'], $childDe, $childEn]);
  ?>
    <section class="card competency-child-card" id="<?=h($anchor)?>" data-search="<?=h(function_exists('mb_strtolower') ? mb_strtolower($searchText, 'UTF-8') : strtolower($searchText))?>">
      <div class="competency-child-heading">
        <div>
          <?php if ((string)$item['code'] !== ''): ?><span class="chip"><?=h((string)$item['code'])?></span><?php endif; ?>
          <?php if ($category !== ''): ?><strong><?=h($category)?></strong><?php endif; ?>
          <?php if ($subcategory !== ''): ?><span class="muted"> · <?=h($subcategory)?></span><?php endif; ?>
        </div>
        <?php if ($childDe !== '' || $childEn !== ''): ?><span class="competency-child-shared"><?=h(t('competency_child_texts.shared', 'Gemeinsam gespeichert'))?></span><?php endif; ?>
      </div>

      <div class="competency-child-teacher-grid">
        <div><span class="competency-child-label"><?=h(t('competency_child_texts.teacher_de', 'Lehrkrafttext Deutsch'))?></span><div class="competency-child-readonly"><?=nl2br(h((string)$item['teacher_text_de']))?></div></div>
        <div><span class="competency-child-label"><?=h(t('competency_child_texts.teacher_en', 'Lehrkrafttext Englisch'))?></span><div class="competency-child-readonly"><?=nl2br(h((string)$item['teacher_text_en']))?></div></div>
      </div>

      <form method="post" class="competency-child-form" data-has-child-text="<?=($childDe !== '' || $childEn !== '')?'1':'0'?>">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
        <input type="hidden" name="action" value="save_single">
        <input type="hidden" name="competency_key" value="<?=h($key)?>">
        <div class="competency-child-editor-grid">
          <label><span class="competency-child-label"><?=h(t('competency_child_texts.child_de', 'Kindertext Deutsch'))?></span><textarea class="input" name="child_text_de" rows="3" placeholder="<?=h((string)$item['teacher_text_de'])?>"><?=h($childDe)?></textarea></label>
          <label><span class="competency-child-label"><?=h(t('competency_child_texts.child_en', 'Kindertext Englisch'))?></span><textarea class="input" name="child_text_en" rows="3" placeholder="<?=h((string)$item['teacher_text_en'])?>"><?=h($childEn)?></textarea></label>
        </div>

        <?php if ($suggestions): ?>
          <div class="competency-child-suggestions">
            <span class="competency-child-label"><?=h(t('competency_child_texts.suggestions', 'Kindertext aus anderer Vorlage übernehmen'))?></span>
            <?php foreach ($suggestions as $suggestion): ?>
              <button class="btn secondary competency-child-copy" type="button"
                data-child-de="<?=h((string)($suggestion['child_text_de'] ?? ''))?>"
                data-child-en="<?=h((string)($suggestion['child_text_en'] ?? ''))?>">
                <?=h((string)$suggestion['template_name'])?> · v<?=h((string)$suggestion['template_version'])?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="actions competency-child-actions">
          <?php if ($childDe !== '' || $childEn !== ''): ?><button class="btn secondary" type="submit" name="reset" value="1" onclick="return confirm(<?=h(json_encode(t('competency_child_texts.reset_confirm', 'Kindertext zurücksetzen und wieder den Lehrkrafttext verwenden?'), JSON_UNESCAPED_UNICODE))?>)"><?=h(t('competency_child_texts.reset', 'Zurücksetzen'))?></button><?php endif; ?>
          <button class="btn" type="submit"><?=h(t('competency_child_texts.save', 'Gemeinsam speichern'))?></button>
        </div>
      </form>
    </section>
  <?php endforeach; ?>
  </div>
  <p class="card muted" id="competencyChildEmpty" hidden><?=h(t('competency_child_texts.no_search_results', 'Keine passende Kompetenz gefunden.'))?></p>
<?php endif; ?>

<style>
.competency-child-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(240px,.7fr);gap:16px;align-items:end}.competency-child-toolbar form,.competency-child-toolbar>div{display:grid;gap:6px}.competency-child-ai-panel{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px 20px;align-items:center;border-left:4px solid #7c3aed}.competency-child-ai-panel h2{margin-top:0}.competency-child-ai-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.competency-child-ai-status{grid-column:1/-1;margin:0;font-weight:650;color:#4c1d95}.competency-child-card{scroll-margin-top:150px}.competency-child-card.ai-proposal{border-color:#8b5cf6;box-shadow:0 0 0 2px rgba(124,58,237,.12)}.competency-child-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.competency-child-shared{padding:4px 9px;border-radius:999px;background:#e8f5e9;color:#256029;font-size:12px;font-weight:700}.competency-child-teacher-grid,.competency-child-editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.competency-child-label{display:block;font-size:13px;font-weight:750;color:#334155;margin-bottom:5px}.competency-child-readonly{min-height:58px;padding:10px 12px;border:1px solid #dbe2ea;border-radius:10px;background:#f6f8fb;color:#475569}.competency-child-form{margin-top:14px}.competency-child-editor-grid textarea{width:100%;box-sizing:border-box;resize:vertical}.competency-child-suggestions{margin-top:12px;padding:10px 12px;border-radius:10px;background:#fff8e6;border:1px solid #f4d58d}.competency-child-copy{margin:3px 6px 3px 0}.competency-child-actions{justify-content:flex-end;margin-top:12px}.chip{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#eef5ff;margin-right:6px}@media(max-width:760px){.competency-child-toolbar,.competency-child-teacher-grid,.competency-child-editor-grid,.competency-child-ai-panel{grid-template-columns:1fr}.competency-child-heading{display:block}.competency-child-shared{display:inline-block;margin-top:8px}.competency-child-ai-actions{justify-content:flex-start}}
</style>
<script>
(() => {
  const search = document.getElementById('competencySearch');
  const cards = Array.from(document.querySelectorAll('.competency-child-card'));
  const empty = document.getElementById('competencyChildEmpty');
  if (search) search.addEventListener('input', () => {
    const needle = String(search.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    cards.forEach((card) => {
      const show = needle === '' || String(card.dataset.search || '').includes(needle);
      card.hidden = !show;
      if (show) visible++;
    });
    if (empty) empty.hidden = visible !== 0;
  });
  document.querySelectorAll('.competency-child-copy').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('form');
      if (!form) return;
      const de = form.querySelector('[name="child_text_de"]');
      const en = form.querySelector('[name="child_text_en"]');
      if (de) de.value = button.dataset.childDe || '';
      if (en) en.value = button.dataset.childEn || '';
      if (de) de.focus();
    });
  });

  const aiGenerate = document.getElementById('aiGenerateAll');
  const aiSave = document.getElementById('aiSaveAll');
  const aiReplaceExisting = document.getElementById('aiReplaceExisting');
  const aiStatus = document.getElementById('aiBatchStatus');
  const aiBatchSaveForm = document.getElementById('aiBatchSaveForm');
  const templateId = <?=json_encode($templateId)?>;
  const aiEndpoint = <?=json_encode(url('teacher/ajax/competency_child_texts_ai_api.php'))?>;
  const aiMessages = {
    working: <?=json_encode(t('competency_child_texts.ai_working', 'KI-Vorschläge werden erstellt: {done} von {total} Kompetenzen …'), JSON_UNESCAPED_UNICODE)?>,
    ready: <?=json_encode(t('competency_child_texts.ai_ready', '{count} zweisprachige Vorschläge sind bereit. Bitte prüfen und anschließend gemeinsam speichern.'), JSON_UNESCAPED_UNICODE)?>,
    nothing: <?=json_encode(t('competency_child_texts.ai_nothing', 'Für alle Kompetenzen sind bereits Kindertexte gespeichert. Aktiviere die Option zum Neuvorschlagen, wenn du sie ersetzen möchtest.'), JSON_UNESCAPED_UNICODE)?>,
    failed: <?=json_encode(t('competency_child_texts.ai_failed', 'KI-Vorschläge konnten nicht erstellt werden: {error}'), JSON_UNESCAPED_UNICODE)?>,
    confirmSave: <?=json_encode(t('competency_child_texts.ai_save_confirm', 'Alle geprüften KI-Vorschläge jetzt systemweit für diese Vorlage speichern?'), JSON_UNESCAPED_UNICODE)?>
  };
  const competencyForms = Array.from(document.querySelectorAll('.competency-child-form'));
  const formsByKey = new Map(competencyForms.map((form) => [String(form.querySelector('[name="competency_key"]')?.value || ''), form]));

  async function generateAiSuggestions() {
    if (!aiGenerate) return;
    const replaceExisting = !!aiReplaceExisting?.checked;
    const targets = competencyForms.filter((form) => replaceExisting || form.dataset.hasChildText !== '1');
    if (!targets.length) {
      if (aiStatus) aiStatus.textContent = aiMessages.nothing;
      return;
    }
    competencyForms.forEach((form) => { delete form.dataset.aiChanged; form.closest('.competency-child-card')?.classList.remove('ai-proposal'); });
    if (aiSave) aiSave.hidden = true;
    aiGenerate.disabled = true;
    if (aiReplaceExisting) aiReplaceExisting.disabled = true;
    let completed = 0;
    try {
      for (let offset = 0; offset < targets.length; offset += 8) {
        const batch = targets.slice(offset, offset + 8);
        if (aiStatus) aiStatus.textContent = aiMessages.working.replace('{done}', String(completed)).replace('{total}', String(targets.length));
        const csrf = String(document.querySelector('#aiBatchSaveForm [name="csrf_token"]')?.value || '');
        const response = await fetch(aiEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({
            csrf_token: csrf,
            template_id: templateId,
            competency_keys: batch.map((form) => String(form.querySelector('[name="competency_key"]')?.value || ''))
          })
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false || !Array.isArray(payload.items)) throw new Error(String(payload.error || response.statusText || 'HTTP ' + response.status));
        payload.items.forEach((item) => {
          const form = formsByKey.get(String(item.key || ''));
          if (!form) return;
          const de = form.querySelector('[name="child_text_de"]');
          const en = form.querySelector('[name="child_text_en"]');
          if (de) de.value = String(item.de || '');
          if (en) en.value = String(item.en || '');
          form.dataset.aiChanged = '1';
          form.closest('.competency-child-card')?.classList.add('ai-proposal');
        });
        completed += batch.length;
      }
      const changed = competencyForms.filter((form) => form.dataset.aiChanged === '1').length;
      if (aiStatus) aiStatus.textContent = aiMessages.ready.replace('{count}', String(changed));
      if (aiSave) aiSave.hidden = changed === 0;
    } catch (error) {
      const changed = competencyForms.filter((form) => form.dataset.aiChanged === '1').length;
      if (aiSave) aiSave.hidden = changed === 0;
      if (aiStatus) aiStatus.textContent = aiMessages.failed.replace('{error}', String(error?.message || error));
    } finally {
      aiGenerate.disabled = false;
      if (aiReplaceExisting) aiReplaceExisting.disabled = false;
    }
  }

  aiGenerate?.addEventListener('click', () => { void generateAiSuggestions(); });
  aiSave?.addEventListener('click', () => {
    if (!aiBatchSaveForm) return;
    const entries = competencyForms.filter((form) => form.dataset.aiChanged === '1').map((form) => ({
      key: String(form.querySelector('[name="competency_key"]')?.value || ''),
      de: String(form.querySelector('[name="child_text_de"]')?.value || '').trim(),
      en: String(form.querySelector('[name="child_text_en"]')?.value || '').trim()
    })).filter((item) => item.key && item.de && item.en);
    if (!entries.length || !window.confirm(aiMessages.confirmSave)) return;
    const target = aiBatchSaveForm.querySelector('[name="texts_json"]');
    if (target) target.value = JSON.stringify(entries);
    aiBatchSaveForm.requestSubmit();
  });
})();
</script>
<?php render_teacher_footer(); ?>
