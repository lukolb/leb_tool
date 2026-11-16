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

$student = (string)file_get_contents($root . '/student/index.php');
$classes = (string)file_get_contents($root . '/teacher/classes.php');
$schema = (string)file_get_contents($root . '/bootstrap.php');
$wizard = (string)file_get_contents($root . '/student/ajax/wizard_api.php');
$layout = (string)file_get_contents($root . '/shared/_layout.php');
$parent = (string)file_get_contents($root . '/parent/portal.php');

$cacheCall = strpos($student, 'await speakWithCachedAudio(normalizedText)');
$liveCall = strpos($student, 'await speakWithVits(normalizedText)');
assert_true($cacheCall !== false && $liveCall !== false && $cacheCall < $liveCall, 'Student playback must check the cache before live generation.');
assert_true(str_contains($student, 'const blob = await session.predict(normalized);'), 'Existing live VITS generation must remain available.');
assert_true(str_contains($student, 'void prefetchVitsModel(voiceId);'), 'The proven live VITS model prefetch must remain unchanged.');
assert_true(str_contains($student, 'void uploadGeneratedTts(normalized, voiceId, blob);'), 'A live-generated file must be saved for subsequent playback.');
assert_true(str_contains($classes, 'if ($new === 1) $ttsGenerationClassId = $classId;'), 'Enabling read aloud must automatically start class cache preparation.');
assert_true(str_contains($schema, 'CREATE TABLE tts_audio_files') && str_contains($schema, 'CREATE TABLE tts_audio_class_links'), 'Audio cache tables must be migrated automatically.');

assert_true(str_contains($schema, 'syllable_coloring_enabled'), 'Class schema must store the syllable-color setting.');
assert_true(str_contains($classes, 'toggle_syllable_coloring'), 'Teachers must be able to toggle syllable colors per class.');
assert_true(str_contains($student, 'syllableTextHtml'), 'Student competency labels must support alternating syllable colors.');

assert_true(str_contains($schema, 'student_intro_html_en'), 'Class schema must store an English intro.');
assert_true(str_contains($wizard, "\$lang === 'en'"), 'Student bootstrap must select the intro by language.');
assert_true(str_contains($student, 'STUDENT_LANGUAGE_KEY') && str_contains($student, 'languageChoiceModal'), 'Students must receive a per-device first-login language choice.');
assert_true(!str_contains($student . $layout . $parent, '🇬🇧'), 'The British flag must no longer be used by language selectors.');
assert_true(str_contains($student . $layout . $parent, '🇺🇸'), 'The English language selector must use the US flag.');

echo "TTS audio cache, syllable colors, bilingual intro and language-choice tests passed.\n";
