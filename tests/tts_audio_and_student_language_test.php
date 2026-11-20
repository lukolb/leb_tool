<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/shared/tts_audio_cache.php';

function assert_true(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

$normalized = tts_audio_normalize_text("  Hallo\n\t\u{00a0} Welt!  ");
assert_true($normalized === 'Hallo Welt!', 'TTS text normalization must match browser cache normalization.');
$keyA = tts_audio_cache_key('Hallo Welt!', 'de', 'de_DE-thorsten-medium');
$keyB = tts_audio_cache_key("Hallo   Welt!", 'de', 'de_DE-thorsten-medium');
$keyC = tts_audio_cache_key('Hello world!', 'en', 'en_US-lessac-medium');
assert_true(strlen($keyA) === 64 && $keyA === $keyB, 'Equivalent text must produce one stable cache key.');
assert_true($keyA !== $keyC, 'Language, voice and text must be part of the cache key.');
$cacheKeyFunction = new ReflectionFunction('tts_audio_cache_key');
assert_true($cacheKeyFunction->getNumberOfParameters() === 3, 'The physical audio cache key must not contain a class ID.');

$student = (string)file_get_contents($root . '/student/index.php');
$classes = (string)file_get_contents($root . '/teacher/classes.php');
$adminAudio = (string)file_get_contents($root . '/admin/tts_audio.php');
$adminAudioApi = (string)file_get_contents($root . '/admin/ajax/tts_audio_cache_api.php');
$teacherAudioApi = (string)file_get_contents($root . '/teacher/ajax/tts_audio_cache_api.php');
$studentAudioApi = (string)file_get_contents($root . '/student/ajax/tts_audio_cache_api.php');
$schema = (string)file_get_contents($root . '/bootstrap.php');
$wizard = (string)file_get_contents($root . '/student/ajax/wizard_api.php');
$layout = (string)file_get_contents($root . '/shared/_layout.php');
$parent = (string)file_get_contents($root . '/parent/portal.php');

$cacheCall = strpos($student, 'await speakWithCachedAudio(normalizedText, source, requestId)');
$liveCall = strpos($student, 'await speakWithVits(normalizedText, source, requestId)');
assert_true($cacheCall !== false && $liveCall !== false && $cacheCall < $liveCall, 'Student playback must check the cache before live generation.');
assert_true(str_contains($student, 'const blob = await session.predict(normalized);'), 'Existing live VITS generation must remain available.');
assert_true(str_contains($student, 'void prefetchVitsModel(voiceId);'), 'The proven live VITS model prefetch must remain unchanged.');
assert_true(str_contains($student, 'void uploadGeneratedTts(normalized, voiceId, blob, source);'), 'A live-generated file must be saved for subsequent playback together with its stable source identity.');
assert_true(
  str_contains($student, 'let ttsRequestId = 0;')
    && substr_count($student, 'const requestId = beginTtsRequest();') >= 2
    && str_contains($student, 'if (!ttsRequestIsActive(requestId)) return;')
    && str_contains($student, 'stopTts(false);'),
  'Starting a new read-aloud request must stop current playback and prevent older asynchronous audio from starting later.'
);
assert_true(
  str_contains($student, "cur && cur.kind === 'submit'")
    && str_contains($student, "parts.join('. ')")
    && substr_count($student, 'if (isBeginnerMode && TTS_ALLOWED && ttsSupported)') >= 3
    && strpos($student, 'speakCurrentStep(false);', strpos($student, 'else { // submit')) !== false,
  'The completion page must expose its complete text to read-aloud and start automatically in beginner mode.'
);
assert_true(str_contains($classes, 'if ($new === 1) $ttsGenerationClassId = $classId;'), 'Enabling read aloud must automatically start class cache preparation.');
assert_true(str_contains($adminAudio, "class=\"tts-bulk-class\"") && str_contains($adminAudio, "id=\"ttsSelectAll\""), 'Admins must be able to select multiple read-aloud classes in audio management.');
assert_true(str_contains($adminAudio, "for (let classIndex = 0; classIndex < selected.length; classIndex++)"), 'Admin audio preparation must process every selected class.');
assert_true(str_contains($adminAudio, "url('admin/ajax/tts_audio_cache_api.php')"), 'Admin bulk generation must use its protected admin API.');
assert_true(
  str_contains($layout, "'text_snippets.php','ags.php','tts_audio.php'")
    && !str_contains($layout, "'log.php', 'backup.php', 'tts_audio.php'"),
  'Read-aloud audio management must be grouped under template preparation rather than settings.'
);
assert_true(str_contains($adminAudio, '$rowsByAudio[$audioId]') && str_contains($adminAudio, "'class_usages'"), 'Audio management must show one physical file with its class usages instead of duplicate class-specific rows.');
assert_true(str_contains($adminAudioApi, 'require_admin();'), 'The admin audio API must require an admin session.');
assert_true(str_contains($adminAudioApi, 'tts_audio_class_plan($pdo, $classId, true)') && str_contains($adminAudioApi, 'tts_audio_store_upload($pdo, $classId, $_FILES[\'audio\'] ?? [], $_POST, true)'), 'Admin bulk generation must explicitly permit preparation before read aloud is enabled.');
assert_true(!str_contains($teacherAudioApi . $studentAudioApi, 'tts_audio_store_upload($pdo, $classId, $_FILES[\'audio\'] ?? [], $_POST, true)'), 'Teacher and student uploads must not bypass the read-aloud setting.');
$planParameters = (new ReflectionFunction('tts_audio_class_plan'))->getParameters();
$storeParameters = (new ReflectionFunction('tts_audio_store_upload'))->getParameters();
assert_true(($planParameters[2]->getDefaultValue() ?? true) === false && ($storeParameters[4]->getDefaultValue() ?? true) === false, 'Preparing audio for a disabled class must remain an explicit admin-only opt-in.');
assert_true(str_contains($adminAudio, "if ((int)(\$class['is_active'] ?? 0) !== 1) return false;") && !str_contains($adminAudio, "(int)(\$class['tts_enabled'] ?? 0) !== 1) return false;"), 'All active classes must be available to the admin bulk generator regardless of the read-aloud setting.');
assert_true(str_contains($schema, 'UNIQUE KEY uq_tts_audio_cache_key (cache_key)'), 'Identical text, language and voice must share one physical audio file across classes.');
assert_true(str_contains($schema, 'CREATE TABLE tts_audio_files') && str_contains($schema, 'CREATE TABLE tts_audio_class_links'), 'Audio cache tables must be migrated automatically.');
assert_true(str_contains($adminAudioApi, 'tts_audio_reconcile_class_plan($pdo, $classId, $items)') && str_contains($teacherAudioApi, 'tts_audio_reconcile_class_plan($pdo, $classId, $items)'), 'Preparing a class must automatically unlink obsolete planned audio.');
assert_true(str_contains($studentAudioApi, "\$_GET['source_key']") && str_contains($studentAudioApi, "\$_POST['source_key']"), 'Runtime audio must retain a stable step source so a changed text can replace its previous file.');
assert_true(str_contains($student, "source_type: 'runtime_step'") && str_contains($student, "query.set('source_key'"), 'The student browser must identify the displayed source on cache lookup and upload.');
assert_true(str_contains((string)file_get_contents($root . '/admin/ajax/template_fields_api.php'), 'tts_audio_cleanup_template_classes($pdo, $templateId)'), 'Changing child-facing template text must trigger automatic audio cleanup.');
assert_true(str_contains((string)file_get_contents($root . '/teacher/class_intro.php'), 'tts_audio_cleanup_class_plan($pdo, $classId)'), 'Changing a class intro must trigger automatic audio cleanup.');

assert_true(str_contains($schema, 'syllable_coloring_enabled'), 'Class schema must store the syllable-color setting.');
assert_true(str_contains($classes, 'toggle_syllable_coloring'), 'Teachers must be able to toggle syllable colors per class.');
assert_true(str_contains($student, 'syllableTextHtml'), 'Student competency labels must support alternating syllable colors.');
assert_true(
  str_contains($student, 'function applySyllableColoring(root)')
    && str_contains($student, 'applySyllableColoring(elNav)')
    && str_contains($student, 'applySyllableColoring(elTitle)')
    && str_contains($student, 'applySyllableColoring(elSub)')
    && str_contains($student, 'applySyllableColoring(elBody)')
    && str_contains($student, 'setSyllableText(elReqHint'),
  'Syllable colors must cover group and subgroup navigation, headings, intro content, and guidance text.'
);

assert_true(str_contains($schema, 'student_intro_html_en'), 'Class schema must store an English intro.');
assert_true(str_contains($wizard, "\$lang === 'en'"), 'Student bootstrap must select the intro by language.');
assert_true(str_contains($student, 'STUDENT_LANGUAGE_KEY') && str_contains($student, 'languageChoiceModal'), 'Students must receive a per-device first-login language choice.');
assert_true(!str_contains($student . $layout . $parent, '🇬🇧'), 'The British flag must no longer be used by language selectors.');
assert_true(str_contains($student . $layout . $parent, '🇺🇸'), 'The English language selector must use the US flag.');

echo "TTS audio cache, syllable colors, bilingual intro and language-choice tests passed.\n";
