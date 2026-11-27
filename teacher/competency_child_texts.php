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

function competency_child_text_json_reply(array $payload, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$templates = competency_child_accessible_templates($pdo, $userId, $isAdmin);
$requestedTemplateId = (int)($_POST['template_id'] ?? $_GET['template_id'] ?? 0);
$templateId = isset($templates[$requestedTemplateId]) ? $requestedTemplateId : (int)(array_key_first($templates) ?? 0);
$error = '';
$isAjaxRequest = is_ajax_request();

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

    if ($action === 'reset_all') {
      $delete = $pdo->prepare("DELETE FROM template_competency_child_texts WHERE template_id=?");
      $delete->execute([$templateId]);
      $resetCount = $delete->rowCount();
      audit('competency_child_text_reset_all', $userId, ['template_id' => $templateId, 'count' => $resetCount]);
      tts_audio_cleanup_template_classes($pdo, $templateId);
      header('Location: ' . url('teacher/competency_child_texts.php?template_id=' . $templateId . '&saved=reset_all'));
      exit;
    }

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

    if ($isAjaxRequest) {
      $saved = $childDe !== '' || $childEn !== '';
      competency_child_text_json_reply([
        'ok' => true,
        'saved' => $saved,
        'competency_key' => $competencyKey,
        'child_text_de' => $childDe,
        'child_text_en' => $childEn,
        'message' => $saved
          ? t('competency_child_texts.saved', 'Der gemeinsame Kindertext wurde gespeichert.')
          : t('competency_child_texts.reset_saved', 'Der gemeinsame Kindertext wurde zurückgesetzt.'),
        'csrf_token' => csrf_token(),
      ]);
    }

    $anchor = 'competency-' . substr(hash('sha256', $competencyKey), 0, 12);
    header('Location: ' . url('teacher/competency_child_texts.php?template_id=' . $templateId . '&saved=1#' . $anchor));
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
    if ($isAjaxRequest) {
      competency_child_text_json_reply([
        'ok' => false,
        'error' => $error,
        'csrf_token' => csrf_token(),
      ], 400);
    }
  }
}

$catalog = $templateId > 0 ? competency_child_text_catalog($pdo, $templateId) : [];
$overrides = $templateId > 0 ? competency_child_text_overrides($pdo, $templateId) : [];
$aiGradeLevels = $templateId > 0 ? competency_child_text_grade_levels($pdo, $templateId) : [];
$aiGradeLabel = $aiGradeLevels ? implode(', ', $aiGradeLevels) : t('competency_child_texts.ai_grade_unknown', 'nicht eindeutig hinterlegt');
$catalogGroups = [];
foreach ($catalog as $item) {
  $categoryDe = trim((string)($item['category_de'] ?? ''));
  $categoryEn = trim((string)($item['category_en'] ?? ''));
  $subcategoryDe = trim((string)($item['subcategory_de'] ?? ''));
  $subcategoryEn = trim((string)($item['subcategory_en'] ?? ''));
  $categoryLabel = ui_lang() === 'en' && $categoryEn !== '' ? $categoryEn : $categoryDe;
  $subcategoryLabel = ui_lang() === 'en' && $subcategoryEn !== '' ? $subcategoryEn : $subcategoryDe;
  if ($categoryLabel === '') $categoryLabel = t('competency_child_texts.ungrouped', 'Ohne Gruppe');
  if ($subcategoryLabel === '') $subcategoryLabel = t('competency_child_texts.unsubgrouped', 'Ohne Untergruppe');
  $categoryKey = hash('sha256', $categoryDe . "\n" . $categoryEn);
  $subcategoryKey = hash('sha256', $subcategoryDe . "\n" . $subcategoryEn);
  if (!isset($catalogGroups[$categoryKey])) {
    $catalogGroups[$categoryKey] = ['label' => $categoryLabel, 'count' => 0, 'subgroups' => []];
  }
  if (!isset($catalogGroups[$categoryKey]['subgroups'][$subcategoryKey])) {
    $catalogGroups[$categoryKey]['subgroups'][$subcategoryKey] = ['label' => $subcategoryLabel, 'items' => []];
  }
  $catalogGroups[$categoryKey]['subgroups'][$subcategoryKey]['items'][] = $item;
  $catalogGroups[$categoryKey]['count']++;
}
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
<?php if ((string)($_GET['saved'] ?? '') === 'reset_all'): ?><div class="alert success"><strong><?=h(t('competency_child_texts.reset_all_saved', 'Alle Kindertexte dieser Vorlage wurden zurückgesetzt.'))?></strong></div><?php endif; ?>

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

  <div class="card competency-child-view-actions">
    <div>
      <strong><?=h(t('competency_child_texts.group_view', 'Gruppenansicht'))?></strong>
      <p class="muted"><?=h(t('competency_child_texts.group_view_hint', 'Kompetenzen sind nach Gruppe und Untergruppe sortiert. Öffne nur die Bereiche, die du gerade prüfen möchtest.'))?></p>
    </div>
    <div class="actions">
      <button class="btn secondary" type="button" id="expandAllGroups"><?=h(t('competency_child_texts.expand_all', 'Alle öffnen'))?></button>
      <button class="btn secondary" type="button" id="collapseAllGroups"><?=h(t('competency_child_texts.collapse_all', 'Alle schließen'))?></button>
      <?php if ($overrides): ?>
        <form method="post" class="competency-child-reset-all-form">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
          <input type="hidden" name="action" value="reset_all">
          <button class="btn danger" type="submit" onclick="return confirm(<?=h(json_encode(t('competency_child_texts.reset_all_confirm', 'Wirklich alle gemeinsam gespeicherten Kindertexte dieser Vorlage zurücksetzen? Danach werden wieder die ursprünglichen Vorlagentexte verwendet.'), JSON_UNESCAPED_UNICODE))?>)"><?=h(t('competency_child_texts.reset_all', 'Alle Kindertexte zurücksetzen'))?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php $aiConfig = app_config()['ai'] ?? []; $aiAvailable = (bool)($aiConfig['enabled'] ?? true) && trim((string)($aiConfig['api_key'] ?? getenv('OPENAI_API_KEY') ?: '')) !== ''; ?>
  <div class="card competency-child-ai-panel">
    <div>
      <h2><?=h(t('competency_child_texts.ai_title', 'Kindertexte mit KI vorbereiten'))?></h2>
      <p class="muted"><?=h(t('competency_child_texts.ai_intro', 'Die hinterlegte KI erstellt gesammelt deutsche und englische Vorschläge. Prüfe und bearbeite sie vor dem gemeinsamen Speichern.'))?></p>
      <p class="competency-child-ai-grade"><?=h(str_replace('{grade}', $aiGradeLabel, t('competency_child_texts.ai_grade', 'Zielgruppe: Klassenstufe {grade}')))?></p>
      <label for="aiMode"><strong><?=h(t('competency_child_texts.ai_mode', 'KI-Aufgabe'))?></strong></label>
      <select class="input" id="aiMode">
        <option value="simplify_both"><?=h(t('competency_child_texts.ai_mode_simplify', 'Deutsch und Englisch kindgerechter formulieren'))?></option>
        <option value="translate_to_en"><?=h(t('competency_child_texts.ai_mode_translate_en', 'Deutschen Kindertext ins Englische übersetzen'))?></option>
        <option value="translate_to_de"><?=h(t('competency_child_texts.ai_mode_translate_de', 'Englischen Kindertext ins Deutsche übersetzen'))?></option>
      </select>
      <label class="choice-chip"><input type="checkbox" id="aiReplaceExisting" value="1"> <span><?=h(t('competency_child_texts.ai_replace_existing', 'Bereits ausgefüllte Zieltexte ebenfalls bearbeiten und überschreiben'))?></span></label>
    </div>
    <div class="competency-child-ai-actions">
      <button class="btn" type="button" id="aiGenerateAll" <?=$aiAvailable?'':'disabled'?>><?=h(t('competency_child_texts.ai_generate', 'KI-Vorschläge für Vorlage erstellen'))?></button>
      <button class="btn secondary" type="button" id="aiReviewAll" hidden><?=h(t('competency_child_texts.ai_review', 'KI-Vorschläge prüfen'))?></button>
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
  <?php foreach ($catalogGroups as $group): ?>
    <details class="competency-child-group">
      <summary><span><?=h((string)$group['label'])?></span><span class="competency-child-count"><?= (int)$group['count'] ?></span></summary>
      <div class="competency-child-group-body">
      <?php foreach ($group['subgroups'] as $subgroup): ?>
        <details class="competency-child-subgroup">
          <summary><span><?=h((string)$subgroup['label'])?></span><span class="competency-child-count"><?= count($subgroup['items']) ?></span></summary>
          <div class="competency-child-subgroup-body">
          <?php foreach ($subgroup['items'] as $item):
    $key = (string)$item['competency_key'];
    $override = $overrides[$key] ?? [];
    $childDe = (string)($override['child_text_de'] ?? '');
    $childEn = (string)($override['child_text_en'] ?? '');
    $hash = (string)$item['teacher_text_hash'];
    $suggestions = $suggestionsByHash[$hash] ?? [];
    $anchor = 'competency-' . substr(hash('sha256', $key), 0, 12);
    $category = (string)$group['label'];
    $subcategory = (string)$subgroup['label'];
    $searchText = implode(' ', [(string)$item['code'], $category, $subcategory, (string)$item['teacher_text_de'], (string)$item['teacher_text_en'], $childDe, $childEn]);
  ?>
    <section class="card competency-child-card" id="<?=h($anchor)?>" data-search="<?=h(function_exists('mb_strtolower') ? mb_strtolower($searchText, 'UTF-8') : strtolower($searchText))?>">
      <div class="competency-child-heading">
        <div>
          <?php if ((string)$item['code'] !== ''): ?><span class="chip"><?=h((string)$item['code'])?></span><?php endif; ?>
          <span class="competency-child-ai-badge" hidden><?=h(t('competency_child_texts.ai_badge', 'KI-Vorschlag – noch nicht gespeichert'))?></span>
        </div>
        <span class="competency-child-shared" <?=($childDe !== '' || $childEn !== '')?'':'hidden'?>><?=h(t('competency_child_texts.shared', 'Gemeinsam gespeichert'))?></span>
      </div>

      <div class="competency-child-teacher-grid">
        <div><span class="competency-child-label"><?=h(t('competency_child_texts.teacher_de', 'Lehrkrafttext Deutsch'))?></span><div class="competency-child-readonly"><?=nl2br(h((string)$item['teacher_text_de']))?></div></div>
        <div><span class="competency-child-label"><?=h(t('competency_child_texts.teacher_en', 'Lehrkrafttext Englisch'))?></span><div class="competency-child-readonly"><?=nl2br(h((string)$item['teacher_text_en']))?></div></div>
      </div>

      <form method="post" action="<?=h(url('teacher/competency_child_texts.php'))?>" class="competency-child-form" data-has-child-text="<?=($childDe !== '' || $childEn !== '')?'1':'0'?>">
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
          <span class="competency-child-save-status" aria-live="polite"></span>
          <button class="btn secondary competency-child-reset" type="submit" name="reset" value="1" <?=($childDe !== '' || $childEn !== '')?'':'hidden'?> onclick="return confirm(<?=h(json_encode(t('competency_child_texts.reset_confirm', 'Kindertext zurücksetzen und wieder den Lehrkrafttext verwenden?'), JSON_UNESCAPED_UNICODE))?>)"><?=h(t('competency_child_texts.reset', 'Zurücksetzen'))?></button>
          <button class="btn" type="submit"><?=h(t('competency_child_texts.save', 'Gemeinsam speichern'))?></button>
        </div>
      </form>
    </section>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
  <p class="card muted" id="competencyChildEmpty" hidden><?=h(t('competency_child_texts.no_search_results', 'Keine passende Kompetenz gefunden.'))?></p>
<?php endif; ?>

<style>
.competency-child-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(240px,.7fr);gap:16px;align-items:end}.competency-child-toolbar form,.competency-child-toolbar>div{display:grid;gap:6px}.competency-child-view-actions{display:flex;justify-content:space-between;gap:16px;align-items:center}.competency-child-view-actions p{margin:4px 0 0}.competency-child-view-actions .actions{margin:0;justify-content:flex-end}.competency-child-reset-all-form{margin:0}.competency-child-ai-panel{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px 20px;align-items:center;border-left:4px solid #7c3aed}.competency-child-ai-panel h2{margin-top:0}.competency-child-ai-panel>div:first-child{display:grid;gap:7px}.competency-child-ai-panel>div:first-child>p{margin:0}.competency-child-ai-grade{font-weight:750;color:#5b21b6}.competency-child-ai-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.competency-child-ai-actions [hidden],.competency-child-ai-badge[hidden],.competency-child-shared[hidden],.competency-child-reset[hidden],.competency-child-card[hidden],.competency-child-group[hidden],.competency-child-subgroup[hidden],#competencyChildEmpty[hidden]{display:none!important}.competency-child-ai-status{grid-column:1/-1;margin:0;font-weight:650;color:#4c1d95}.competency-child-group,.competency-child-subgroup{border:1px solid #d9e0e8;border-radius:12px;background:#fff;margin:0 0 14px}.competency-child-subgroup{margin:0 0 10px;background:#f8fafc}.competency-child-group>summary,.competency-child-subgroup>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;font-weight:800;padding:14px 16px;list-style-position:inside}.competency-child-subgroup>summary{padding:11px 14px}.competency-child-group-body{padding:0 12px 2px}.competency-child-subgroup-body{padding:0 10px 2px}.competency-child-count{min-width:28px;text-align:center;padding:2px 8px;border-radius:999px;background:#e8eef8;color:#334155;font-size:12px}.competency-child-card{scroll-margin-top:150px;margin:0 0 10px}.competency-child-card.ai-proposal{border-color:#8b5cf6;box-shadow:0 0 0 2px rgba(124,58,237,.12);background:#fdfbff}.competency-child-ai-badge{display:inline-block;border-radius:999px;padding:3px 9px;background:#ede9fe;color:#5b21b6;font-size:12px;font-weight:800}.competency-child-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.competency-child-shared{padding:4px 9px;border-radius:999px;background:#e8f5e9;color:#256029;font-size:12px;font-weight:700}.competency-child-teacher-grid,.competency-child-editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.competency-child-label{display:block;font-size:13px;font-weight:750;color:#334155;margin-bottom:5px}.competency-child-readonly{min-height:58px;padding:10px 12px;border:1px solid #dbe2ea;border-radius:10px;background:#f6f8fb;color:#475569}.competency-child-form{margin-top:14px}.competency-child-editor-grid textarea{width:100%;box-sizing:border-box;resize:vertical}.competency-child-suggestions{margin-top:12px;padding:10px 12px;border-radius:10px;background:#fff8e6;border:1px solid #f4d58d}.competency-child-copy{margin:3px 6px 3px 0}.competency-child-actions{justify-content:flex-end;margin-top:12px}.competency-child-save-status{margin-right:auto;font-size:13px;font-weight:700;color:#256029}.competency-child-save-status[data-state="error"]{color:#b42318}.chip{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#eef5ff;margin-right:6px}@media(max-width:760px){.competency-child-toolbar,.competency-child-teacher-grid,.competency-child-editor-grid,.competency-child-ai-panel{grid-template-columns:1fr}.competency-child-view-actions{display:block}.competency-child-view-actions .actions{justify-content:flex-start;margin-top:12px}.competency-child-heading{display:block}.competency-child-shared{display:inline-block;margin-top:8px}.competency-child-ai-actions{justify-content:flex-start}}
</style>
<script>
(() => {
  const search = document.getElementById('competencySearch');
  const cards = Array.from(document.querySelectorAll('.competency-child-card'));
  const groupDetails = Array.from(document.querySelectorAll('.competency-child-group'));
  const subgroupDetails = Array.from(document.querySelectorAll('.competency-child-subgroup'));
  const empty = document.getElementById('competencyChildEmpty');
  const expandAll = document.getElementById('expandAllGroups');
  const collapseAll = document.getElementById('collapseAllGroups');
  let aiReviewMode = false;

  function openCardParents(card) {
    let parent = card?.parentElement || null;
    while (parent) {
      if (parent.matches?.('details')) parent.open = true;
      parent = parent.parentElement;
    }
  }

  function applyVisibility(openMatches = true) {
    const needle = String(search?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    cards.forEach((card) => {
      const matchesSearch = needle === '' || String(card.dataset.search || '').includes(needle);
      const matchesReview = !aiReviewMode || card.classList.contains('ai-proposal');
      const show = matchesSearch && matchesReview;
      card.hidden = !show;
      if (show) {
        visible++;
        if (openMatches && (needle !== '' || aiReviewMode)) openCardParents(card);
      }
    });
    subgroupDetails.forEach((details) => {
      details.hidden = !Array.from(details.querySelectorAll('.competency-child-card')).some((card) => !card.hidden);
    });
    groupDetails.forEach((details) => {
      details.hidden = !Array.from(details.querySelectorAll('.competency-child-card')).some((card) => !card.hidden);
    });
    if (empty) empty.hidden = visible !== 0;
  }

  search?.addEventListener('input', applyVisibility);
  expandAll?.addEventListener('click', () => {
    [...groupDetails, ...subgroupDetails].filter((details) => !details.hidden).forEach((details) => { details.open = true; });
  });
  collapseAll?.addEventListener('click', () => {
    [...subgroupDetails, ...groupDetails].forEach((details) => { details.open = false; });
  });
  const anchorCard = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  if (anchorCard?.classList.contains('competency-child-card')) openCardParents(anchorCard);
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
  const aiReview = document.getElementById('aiReviewAll');
  const aiSave = document.getElementById('aiSaveAll');
  const aiMode = document.getElementById('aiMode');
  const aiReplaceExisting = document.getElementById('aiReplaceExisting');
  const aiStatus = document.getElementById('aiBatchStatus');
  const aiBatchSaveForm = document.getElementById('aiBatchSaveForm');
  const templateId = <?=json_encode($templateId)?>;
  const aiEndpoint = <?=json_encode(url('teacher/ajax/competency_child_texts_ai_api.php'))?>;
  const aiMessages = {
    working: <?=json_encode(t('competency_child_texts.ai_working', 'KI-Vorschläge werden erstellt: {done} von {total} Kompetenzen …'), JSON_UNESCAPED_UNICODE)?>,
    ready: <?=json_encode(t('competency_child_texts.ai_ready', '{count} zweisprachige Vorschläge sind bereit. Bitte prüfen und anschließend gemeinsam speichern.'), JSON_UNESCAPED_UNICODE)?>,
    nothing: <?=json_encode(t('competency_child_texts.ai_nothing', 'Für die gewählte KI-Aufgabe wurden keine passenden Texte gefunden. Prüfe die Ausgangssprache oder aktiviere das Überschreiben ausgefüllter Zieltexte.'), JSON_UNESCAPED_UNICODE)?>,
    failed: <?=json_encode(t('competency_child_texts.ai_failed', 'KI-Vorschläge konnten nicht erstellt werden: {error}'), JSON_UNESCAPED_UNICODE)?>,
    confirmSave: <?=json_encode(t('competency_child_texts.ai_save_confirm', 'Alle geprüften KI-Vorschläge jetzt systemweit für diese Vorlage speichern?'), JSON_UNESCAPED_UNICODE)?>,
    review: <?=json_encode(t('competency_child_texts.ai_review', 'KI-Vorschläge prüfen'), JSON_UNESCAPED_UNICODE)?>,
    showAll: <?=json_encode(t('competency_child_texts.ai_show_all', 'Alle Kompetenzen anzeigen'), JSON_UNESCAPED_UNICODE)?>,
    saving: <?=json_encode(t('competency_child_texts.saving', 'Wird gespeichert …'), JSON_UNESCAPED_UNICODE)?>,
    resetSaved: <?=json_encode(t('competency_child_texts.reset_saved', 'Der gemeinsame Kindertext wurde zurückgesetzt.'), JSON_UNESCAPED_UNICODE)?>,
    saveFailed: <?=json_encode(t('competency_child_texts.save_failed', 'Speichern fehlgeschlagen: {error}'), JSON_UNESCAPED_UNICODE)?>
  };
  const competencyForms = Array.from(document.querySelectorAll('.competency-child-form'));
  const formsByKey = new Map(competencyForms.map((form) => [String(form.querySelector('[name="competency_key"]')?.value || ''), form]));

  function setAiReviewMode(active, scrollToFirst = false, openMatches = true) {
    aiReviewMode = active;
    if (aiReview) aiReview.textContent = active ? aiMessages.showAll : aiMessages.review;
    applyVisibility(openMatches);
    if (active && scrollToFirst) {
      const first = cards.find((card) => card.classList.contains('ai-proposal'));
      if (first) {
        openCardParents(first);
        first.scrollIntoView({ behavior: 'smooth', block: 'start' });
        first.querySelector('[name="child_text_de"], [name="child_text_en"]')?.focus({ preventScroll: true });
      }
    }
  }

  function refreshAiProposalControls(updateStatus = false) {
    const changed = competencyForms.filter((form) => form.dataset.aiChanged === '1').length;
    if (aiReview) aiReview.hidden = changed === 0;
    if (aiSave) aiSave.hidden = changed === 0;
    if (updateStatus && aiStatus) aiStatus.textContent = changed > 0 ? aiMessages.ready.replace('{count}', String(changed)) : '';
    if (changed === 0 && aiReviewMode) setAiReviewMode(false, false, false);
    else applyVisibility(false);
  }

  async function saveSingleChildText(form, submitter) {
    if (form.dataset.saving === '1') return;
    form.dataset.saving = '1';
    const status = form.querySelector('.competency-child-save-status');
    const submitButtons = Array.from(form.querySelectorAll('button[type="submit"]'));
    const formData = new FormData(form);
    if (submitter?.name) formData.set(submitter.name, submitter.value);
    submitButtons.forEach((button) => { button.disabled = true; });
    if (status) {
      status.dataset.state = 'working';
      status.textContent = aiMessages.saving;
    }
    try {
      const endpoint = form.getAttribute('action') || window.location.href;
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) throw new Error(String(payload.error || payload.message || response.statusText || 'HTTP ' + response.status));
      const de = form.querySelector('[name="child_text_de"]');
      const en = form.querySelector('[name="child_text_en"]');
      if (de) de.value = String(payload.child_text_de || '');
      if (en) en.value = String(payload.child_text_en || '');
      const saved = payload.saved === true;
      form.dataset.hasChildText = saved ? '1' : '0';
      const card = form.closest('.competency-child-card');
      const wasAiProposal = card?.classList.contains('ai-proposal') || form.dataset.aiChanged === '1';
      const shared = card?.querySelector('.competency-child-shared');
      const reset = form.querySelector('.competency-child-reset');
      if (shared) shared.hidden = !saved;
      if (reset) reset.hidden = !saved;
      delete form.dataset.aiChanged;
      card?.classList.remove('ai-proposal');
      const badge = card?.querySelector('.competency-child-ai-badge');
      if (badge) badge.hidden = true;
      const csrf = String(payload.csrf_token || '');
      if (csrf) document.querySelectorAll('input[name="csrf_token"]').forEach((input) => { input.value = csrf; });
      if (status) {
        status.dataset.state = 'success';
        status.textContent = String(payload.message || (saved ? <?=json_encode(t('competency_child_texts.saved', 'Der gemeinsame Kindertext wurde gespeichert.'), JSON_UNESCAPED_UNICODE)?> : aiMessages.resetSaved));
      }
      if (wasAiProposal) refreshAiProposalControls(true);
    } catch (error) {
      if (status) {
        status.dataset.state = 'error';
        status.textContent = aiMessages.saveFailed.replace('{error}', String(error?.message || error));
      }
    } finally {
      delete form.dataset.saving;
      submitButtons.forEach((button) => { button.disabled = false; });
    }
  }

  competencyForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void saveSingleChildText(form, event.submitter || null);
    });
  });

  async function generateAiSuggestions() {
    if (!aiGenerate) return;
    const replaceExisting = !!aiReplaceExisting?.checked;
    const mode = String(aiMode?.value || 'simplify_both');
    const targets = competencyForms.filter((form) => {
      const de = String(form.querySelector('[name="child_text_de"]')?.value || '').trim();
      const en = String(form.querySelector('[name="child_text_en"]')?.value || '').trim();
      if (mode === 'translate_to_en') return de !== '' && (replaceExisting || en === '');
      if (mode === 'translate_to_de') return en !== '' && (replaceExisting || de === '');
      return replaceExisting || form.dataset.hasChildText !== '1';
    });
    if (!targets.length) {
      if (aiStatus) aiStatus.textContent = aiMessages.nothing;
      return;
    }
    setAiReviewMode(false);
    competencyForms.forEach((form) => {
      delete form.dataset.aiChanged;
      const card = form.closest('.competency-child-card');
      card?.classList.remove('ai-proposal');
      const badge = card?.querySelector('.competency-child-ai-badge');
      if (badge) badge.hidden = true;
    });
    if (aiReview) aiReview.hidden = true;
    if (aiSave) aiSave.hidden = true;
    aiGenerate.disabled = true;
    if (aiMode) aiMode.disabled = true;
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
            mode,
            competency_keys: batch.map((form) => String(form.querySelector('[name="competency_key"]')?.value || '')),
            source_texts: Object.fromEntries(batch.map((form) => [
              String(form.querySelector('[name="competency_key"]')?.value || ''),
              {
                de: String(form.querySelector('[name="child_text_de"]')?.value || '').trim(),
                en: String(form.querySelector('[name="child_text_en"]')?.value || '').trim()
              }
            ]))
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
          const card = form.closest('.competency-child-card');
          card?.classList.add('ai-proposal');
          const badge = card?.querySelector('.competency-child-ai-badge');
          if (badge) badge.hidden = false;
        });
        completed += batch.length;
      }
      const changed = competencyForms.filter((form) => form.dataset.aiChanged === '1').length;
      if (aiStatus) aiStatus.textContent = aiMessages.ready.replace('{count}', String(changed));
      if (aiReview) aiReview.hidden = changed === 0;
      if (aiSave) aiSave.hidden = changed === 0;
      if (changed > 0) setAiReviewMode(true, true);
    } catch (error) {
      const changed = competencyForms.filter((form) => form.dataset.aiChanged === '1').length;
      if (aiReview) aiReview.hidden = changed === 0;
      if (aiSave) aiSave.hidden = changed === 0;
      if (aiStatus) aiStatus.textContent = aiMessages.failed.replace('{error}', String(error?.message || error));
      if (changed > 0) setAiReviewMode(true, true);
    } finally {
      aiGenerate.disabled = false;
      if (aiMode) aiMode.disabled = false;
      if (aiReplaceExisting) aiReplaceExisting.disabled = false;
    }
  }

  aiGenerate?.addEventListener('click', () => { void generateAiSuggestions(); });
  aiReview?.addEventListener('click', () => { setAiReviewMode(!aiReviewMode, aiReviewMode === false); });
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
