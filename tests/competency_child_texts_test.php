<?php
declare(strict_types=1);

function db_has_table(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
}

require_once dirname(__DIR__) . '/shared/competency_child_texts.php';

function competency_child_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE template_fields (
  id INTEGER PRIMARY KEY, template_id INTEGER NOT NULL, field_name TEXT NOT NULL,
  field_type TEXT NOT NULL, label TEXT, label_en TEXT, meta_json TEXT,
  can_child_edit INTEGER NOT NULL, can_teacher_edit INTEGER NOT NULL, sort_order INTEGER NOT NULL
)');
$pdo->exec('CREATE TABLE template_competency_child_texts (
  id INTEGER PRIMARY KEY, template_id INTEGER NOT NULL, competency_key TEXT NOT NULL,
  teacher_text_hash TEXT NOT NULL, teacher_text_de TEXT, teacher_text_en TEXT,
  child_text_de TEXT, child_text_en TEXT, updated_by_user_id INTEGER, updated_at TEXT
)');

$metaTeacher = json_encode(['rcff' => [
  'type' => 'rating', 'competency_id' => 17, 'competency_code' => 'SCHR-017',
  'category_de' => 'Deutsch', 'category_en' => 'German',
  'subcategory_de' => 'Schreiben', 'subcategory_en' => 'Writing', 'role' => 'teacher',
]], JSON_UNESCAPED_UNICODE);
$metaStudent = json_encode(['rcff' => [
  'type' => 'rating', 'competency_id' => 17, 'competency_code' => 'SCHR-017',
  'category_de' => 'Deutsch', 'category_en' => 'German',
  'subcategory_de' => 'Schreiben', 'subcategory_en' => 'Writing', 'role' => 'student',
]], JSON_UNESCAPED_UNICODE);
$insert = $pdo->prepare('INSERT INTO template_fields VALUES (?,?,?,?,?,?,?,?,?,?)');
$insert->execute([1, 5, 'SCHR-017-T', 'radio', 'Du schreibst in verbundener Schrift.', 'You write in cursive.', $metaTeacher, 0, 1, 1]);
$insert->execute([2, 5, 'SCHR-017-S', 'radio', 'Du schreibst in verbundener Schrift.', 'You write in cursive.', $metaStudent, 1, 0, 2]);

$catalog = competency_child_text_catalog($pdo, 5);
competency_child_assert(count($catalog) === 1, 'teacher and student variants must collapse to one competency per template');
competency_child_assert($catalog[0]['competency_key'] === 'id:17', 'catalog identity must use the stable RCFF competency ID');
competency_child_assert($catalog[0]['teacher_text_de'] === 'Du schreibst in verbundener Schrift.', 'the read-only teacher wording must come from the teacher field');

$pdo->prepare('INSERT INTO template_competency_child_texts VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
  1, 5, 'id:17', $catalog[0]['teacher_text_hash'], $catalog[0]['teacher_text_de'], $catalog[0]['teacher_text_en'],
  'Du schreibst Schreibschrift.', 'You write in cursive.', 4, '2026-11-23 10:00:00',
]);
$pdo->prepare('INSERT INTO template_competency_child_texts VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
  2, 6, 'id:17', $catalog[0]['teacher_text_hash'], $catalog[0]['teacher_text_de'], $catalog[0]['teacher_text_en'],
  'Anderer Vorlagentext.', 'Different template text.', 5, '2026-11-23 10:00:00',
]);

$childRows = $pdo->query('SELECT * FROM template_fields WHERE id=2')->fetchAll(PDO::FETCH_ASSOC);
$resolved = competency_child_text_apply_overrides($pdo, 5, $childRows);
competency_child_assert($resolved[0]['label'] === 'Du schreibst Schreibschrift.', 'the German child wording must be applied at runtime');
competency_child_assert($resolved[0]['label_en'] === 'You write in cursive.', 'the English child wording must be applied at runtime');
$otherTemplate = competency_child_text_apply_overrides($pdo, 6, $childRows);
competency_child_assert($otherTemplate[0]['label'] === 'Anderer Vorlagentext.', 'child wording must remain scoped to one template');

$root = dirname(__DIR__);
$tts = file_get_contents($root . '/shared/tts_audio_cache.php');
$student = file_get_contents($root . '/student/ajax/wizard_api.php');
$teacher = file_get_contents($root . '/teacher/ajax/entry_api.php');
$aiPage = file_get_contents($root . '/teacher/competency_child_texts.php');
$aiApi = file_get_contents($root . '/teacher/ajax/competency_child_texts_ai_api.php');
competency_child_assert(
  str_contains((string)$tts, 'competency_child_text_apply_overrides')
    && str_contains((string)$student, 'competency_child_text_apply_overrides')
    && str_contains((string)$teacher, 'competency_child_text_apply_overrides'),
  'student display, teacher pairing, and audio generation must all resolve the shared child wording'
);
competency_child_assert(
  str_contains((string)$aiPage, 'save_ai_batch')
    && str_contains((string)$aiPage, 'tts_audio_cleanup_template_classes')
    && str_contains((string)$aiApi, 'competency_child_ai_chat')
    && str_contains((string)$aiApi, "'de' => \$de, 'en' => \$en"),
  'batch AI proposals must be bilingual, reviewable, saved together, and followed by audio cleanup'
);

fwrite(STDOUT, "competency_child_texts_test: OK\n");
