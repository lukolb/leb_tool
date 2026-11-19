<?php
declare(strict_types=1);

function student_demo_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
$sources = [];
foreach ([
  'bootstrap' => 'bootstrap.php',
  'launcher' => 'teacher/student_demo.php',
  'student' => 'student/index.php',
  'wizard' => 'student/ajax/wizard_api.php',
  'tts' => 'student/ajax/tts_audio_cache_api.php',
  'ai' => 'student/ajax/ai_explain_api.php',
  'exit' => 'student/demo_exit.php',
  'layout' => 'shared/_layout.php',
  'translations' => 'shared/translations.php',
] as $key => $path) {
  $sources[$key] = file_get_contents($root . '/' . $path);
  student_demo_assert(is_string($sources[$key]), "Could not read {$path}");
}

student_demo_assert(
  str_contains($sources['launcher'], "['groups', 'items', 'beginner']")
    && str_contains($sources['launcher'], "name=\"display_mode\"")
    && str_contains($sources['launcher'], "\$_SESSION['student_demo']"),
  'teachers and admins must choose all three supported views before starting an isolated demo session'
);
student_demo_assert(
  str_contains($sources['launcher'], 'user_can_access_class($pdo, $userId, $classId)')
    && str_contains($sources['launcher'], "(\$user['role'] ?? '') !== 'admin'")
    && str_contains($sources['launcher'], "d.status='open'"),
  'demo launch must enforce the same class scope, include every actively delegated class and allow admins'
);
student_demo_assert(
  str_contains($sources['student'], "user_can_access_class(\$pdo, \$userId, (int)\$studentDemo['class_id'])")
    && str_contains($sources['wizard'], "user_can_access_class(\$pdo, \$userId, (int)\$demo['class_id'])")
    && str_contains($sources['tts'], "user_can_access_class(\$pdo, \$userId, (int)\$demo['class_id'])"),
  'an active demo must recheck class access on the page and protected student APIs'
);
student_demo_assert(
  str_contains($sources['bootstrap'], 'function current_student_demo()')
    && str_contains($sources['bootstrap'], 'current_student_demo() === null'),
  'the student endpoints must recognize a validated demo session without creating a real student account'
);
student_demo_assert(
  str_contains($sources['wizard'], "'report_instance_id' => 0")
    && str_contains($sources['wizard'], "\$_SESSION['student_demo']['values']")
    && str_contains($sources['wizard'], "if (\$isDemo) ensure_editable_or_throw") === false
    && str_contains($sources['wizard'], "if (!\$isDemo) ensure_editable_or_throw"),
  'demo values must stay in the session and must never be written through a real report instance'
);
student_demo_assert(
  str_contains($sources['student'], 'const DEMO_MODE =')
    && str_contains($sources['student'], 'student_demo.banner_text')
    && str_contains($sources['student'], "'student/demo_exit.php'")
    && str_contains($sources['student'], '.demo-banner{')
    && !str_contains($sources['student'], 'class="alert demo-banner"'),
  'the student screen must use a compact demo marker and provide one safe exit in the regular header'
);
student_demo_assert(
  str_contains($sources['wizard'], "'ai_enabled' => ai_provider_enabled()")
    && str_contains($sources['ai'], 'current_student_demo()')
    && str_contains($sources['ai'], 'user_can_access_class($pdo, $userId, $classId)'),
  'admin-enabled student AI help must also be available in demo mode without bypassing class access'
);
student_demo_assert(
  str_contains($sources['tts'], 'current_student_demo()')
    && str_contains($sources['tts'], "SELECT id, tts_enabled FROM classes"),
  'read-aloud testing must use the selected demo class settings'
);
student_demo_assert(
  str_contains($sources['exit'], "unset(\$_SESSION['student_demo'])")
    && !str_contains($sources['exit'], 'session_destroy'),
  'ending a demo must preserve the teacher or admin login'
);
student_demo_assert(
  substr_count($sources['translations'], "'nav.student_demo'") === 2
    && substr_count($sources['translations'], "'student_demo.start'") === 2
    && substr_count($sources['layout'], "t('nav.student_demo'") === 2
    && str_contains($sources['layout'], "'files'=>['classes.php','students.php','student_demo.php'], 'children'=>[")
    && str_contains($sources['layout'], "'files'=>['classes.php', 'class_intro.php', 'students.php', 'student_demo.php'], 'children'=>["),
  'the translated demo entry point must be nested in the class area for both staff roles'
);

fwrite(STDOUT, "student_demo_mode_test: OK\n");
