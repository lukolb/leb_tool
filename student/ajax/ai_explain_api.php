<?php
// student/ajax/ai_explain_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_student();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function t_lang(string $key, string $lang, ?string $fallback = null): string {
  $catalog = translations_catalog();
  $translations = $catalog[$lang] ?? [];
  if (array_key_exists($key, $translations)) return (string)$translations[$key];
  return $fallback ?? $key;
}

function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ai_provider_config(string $lang): array {
  $cfg = app_config();
  $ai = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];

  $enabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
  $studentEnabled = array_key_exists('student_enabled', $ai) ? (bool)$ai['student_enabled'] : $enabled;
  $apiKey = (string)($ai['api_key'] ?? getenv('OPENAI_API_KEY') ?: '');
  $provider = strtolower(trim((string)($ai['provider'] ?? 'openai')));
  $baseUrl = (string)($ai['base_url'] ?? 'https://api.openai.com');
  $model = (string)($ai['model'] ?? 'gpt-4o-mini');
  $timeout = (int)($ai['timeout_seconds'] ?? 20);

  if (!$enabled || !$studentEnabled) {
    throw new RuntimeException(t_lang('student.ai.error.disabled', $lang));
  }
  if ($apiKey === '') {
    throw new RuntimeException(t_lang('student.ai.error.api_key_missing', $lang));
  }

  return [
    'provider' => $provider ?: 'openai',
    'api_key' => $apiKey,
    'base_url' => rtrim($baseUrl !== '' ? $baseUrl : 'https://api.openai.com', '/'),
    'model' => $model !== '' ? $model : 'gpt-4o-mini',
    'timeout' => $timeout > 0 ? $timeout : 20,
  ];
}

function ai_chat_completion(array $messages, array $aiCfg, string $lang): string {
  $url = $aiCfg['base_url'] . '/v1/chat/completions';
  $payload = [
    'model' => $aiCfg['model'],
    'messages' => $messages,
    'temperature' => 0.6,
    'max_tokens' => 220,
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $aiCfg['api_key'],
    ],
    CURLOPT_TIMEOUT => $aiCfg['timeout'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ]);

  $resp = curl_exec($ch);
  $httpCode = (int)(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException(strtr(t_lang('student.ai.error.request_failed', $lang), ['{error}' => $err]));
  }
  curl_close($ch);

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) {
    throw new RuntimeException(t_lang('student.ai.error.response_unreadable', $lang));
  }
  if ($httpCode >= 400) {
    $msg = (string)($json['error']['message'] ?? t_lang('student.ai.error.provider_message_fallback', $lang));
    throw new RuntimeException(strtr(t_lang('student.ai.error.provider_error', $lang), ['{message}' => $msg]));
  }

  $choices = $json['choices'] ?? [];
  $content = '';
  if (is_array($choices) && isset($choices[0]['message']['content'])) {
    $content = (string)$choices[0]['message']['content'];
  }
  $content = trim($content);
  if ($content === '') {
    throw new RuntimeException(t_lang('student.ai.error.empty_response', $lang));
  }

  return $content;
}

function student_context(PDO $pdo, int $studentId, string $lang): array {
  $st = $pdo->prepare(
    "SELECT s.id, s.class_id, c.template_id, c.grade_level\n" .
    "FROM students s\n" .
    "LEFT JOIN classes c ON c.id=s.class_id\n" .
    "WHERE s.id=? LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException(t_lang('student.ai.error.student_not_found', $lang));
  return $row;
}

try {
  $pdo = db();
  $lang = ui_lang();
  $studentId = (int)($_SESSION['student']['id'] ?? 0);
  if ($studentId <= 0) throw new RuntimeException(t_lang('student.ai.error.not_logged_in', $lang));

  $data = read_json_body();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $fieldId = (int)($data['template_field_id'] ?? 0);
  if ($fieldId <= 0) throw new RuntimeException(t_lang('student.ai.error.missing_field_id', $lang));

  $lang = (string)($data['lang'] ?? $lang);
  $lang = ($lang === 'en') ? 'en' : 'de';

  $ctx = student_context($pdo, $studentId, $lang);
  $templateId = (int)($ctx['template_id'] ?? 0);
  if ($templateId <= 0) throw new RuntimeException(t_lang('student.ai.error.no_template', $lang));

  $st = $pdo->prepare(
    "SELECT id, field_name, field_type, label, label_en, help_text\n" .
    "FROM template_fields\n" .
    "WHERE id=? AND template_id=? AND can_child_edit=1\n" .
    "LIMIT 1"
  );
  $st->execute([$fieldId, $templateId]);
  $field = $st->fetch(PDO::FETCH_ASSOC);
  if (!$field) throw new RuntimeException(t_lang('student.ai.error.field_not_allowed', $lang));

  $label = '';
  if ($lang === 'en') {
    $label = trim((string)($field['label_en'] ?? ''));
  }
  if ($label === '') {
    $label = trim((string)($field['label'] ?? $field['field_name'] ?? ''));
  }
  $help = trim((string)($field['help_text'] ?? ''));
  $type = strtolower(trim((string)($field['field_type'] ?? '')));
  $expectsChoice = in_array($type, ['radio','select','grade','checkbox'], true);
  $inputHint = $expectsChoice
    ? t_lang('student.ai.prompt.input_hint.choice', $lang)
    : t_lang('student.ai.prompt.input_hint.text', $lang);

  $grade = isset($ctx['grade_level']) ? (int)$ctx['grade_level'] : 0;
  $gradeInfo = $grade > 0 ? (string)$grade : '';

  $sys = t_lang('student.ai.prompt.system', $lang);
  $user = strtr(t_lang('student.ai.prompt.user', $lang), [
    '{input_hint}' => $inputHint,
    '{grade}' => $gradeInfo,
    '{label}' => $label,
    '{help}' => $help,
  ]);

  $aiCfg = ai_provider_config($lang);
  $text = ai_chat_completion([
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user', 'content' => $user],
  ], $aiCfg, $lang);

  json_out(['ok' => true, 'text' => $text]);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
