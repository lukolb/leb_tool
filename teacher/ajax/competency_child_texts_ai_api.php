<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_teacher();

header('Content-Type: application/json; charset=utf-8');

function competency_child_ai_reply(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function competency_child_ai_input(): array {
  $raw = file_get_contents('php://input');
  $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
  return is_array($data) ? $data : [];
}

function competency_child_ai_template_access(PDO $pdo, int $templateId, int $userId, bool $isAdmin): bool {
  if ($templateId <= 0) return false;
  if ($isAdmin) {
    $st = $pdo->prepare('SELECT 1 FROM templates WHERE id=? AND is_active=1 LIMIT 1');
    $st->execute([$templateId]);
    return (bool)$st->fetchColumn();
  }
  $st = $pdo->prepare(
    "SELECT 1
       FROM classes c
       LEFT JOIN user_class_assignments uca ON uca.class_id=c.id AND uca.user_id=?
       LEFT JOIN class_group_delegations cgd ON cgd.class_id=c.id AND cgd.user_id=? AND cgd.status='open'
      WHERE c.template_id=? AND c.is_active=1
        AND (uca.user_id IS NOT NULL OR cgd.user_id IS NOT NULL)
      LIMIT 1"
  );
  $st->execute([$userId, $userId, $templateId]);
  return (bool)$st->fetchColumn();
}

function competency_child_ai_config(): array {
  $ai = app_config()['ai'] ?? [];
  $ai = is_array($ai) ? $ai : [];
  $enabled = array_key_exists('enabled', $ai) ? (bool)$ai['enabled'] : true;
  $apiKey = trim((string)($ai['api_key'] ?? getenv('OPENAI_API_KEY') ?: ''));
  $baseUrl = rtrim(trim((string)($ai['base_url'] ?? 'https://api.openai.com')), '/');
  $model = trim((string)($ai['model'] ?? 'gpt-4o-mini'));
  $timeout = max(10, (int)($ai['timeout_seconds'] ?? 60));
  if (!$enabled) throw new RuntimeException(t('competency_child_texts.ai_disabled', 'Die KI-Vorschläge sind in den Einstellungen deaktiviert.'));
  if ($apiKey === '') throw new RuntimeException(t('competency_child_texts.ai_missing_key', 'Für KI-Vorschläge ist kein API-Schlüssel hinterlegt.'));
  if ($baseUrl === '') $baseUrl = 'https://api.openai.com';
  if ($model === '') $model = 'gpt-4o-mini';
  return ['api_key' => $apiKey, 'base_url' => $baseUrl, 'model' => $model, 'timeout' => $timeout];
}

function competency_child_ai_chat(array $messages, array $config): string {
  $base = (string)$config['base_url'];
  $endpoint = str_ends_with(strtolower($base), '/v1') ? ($base . '/chat/completions') : ($base . '/v1/chat/completions');
  $payload = [
    'model' => (string)$config['model'],
    'messages' => $messages,
    'temperature' => 0.35,
    'max_tokens' => 3500,
  ];
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . (string)$config['api_key'],
    ],
    CURLOPT_TIMEOUT => (int)$config['timeout'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ]);
  $response = curl_exec($ch);
  $httpCode = (int)(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
  if ($response === false) {
    $message = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException(str_replace('{error}', $message, t('teacher.ai.error.request_failed', 'KI-Anfrage fehlgeschlagen: {error}')));
  }
  curl_close($ch);
  $decoded = json_decode((string)$response, true);
  if ($httpCode < 200 || $httpCode >= 300) {
    $message = is_array($decoded) ? trim((string)($decoded['error']['message'] ?? '')) : '';
    throw new RuntimeException($message !== '' ? $message : ('HTTP ' . $httpCode));
  }
  $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
  if ($content === '') throw new RuntimeException(t('teacher.ai.error.empty_response', 'Die KI hat keine Antwort geliefert.'));
  return $content;
}

function competency_child_ai_decode(string $content): array {
  $content = trim($content);
  if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $content, $match)) $content = trim((string)$match[1]);
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) throw new RuntimeException(t('competency_child_texts.ai_unreadable', 'Die KI-Antwort konnte nicht als Vorschlagsliste gelesen werden.'));
  $items = $decoded['items'] ?? $decoded['competencies'] ?? null;
  if (!is_array($items)) throw new RuntimeException(t('competency_child_texts.ai_unreadable', 'Die KI-Antwort konnte nicht als Vorschlagsliste gelesen werden.'));
  return $items;
}

/** @return array<string,array{key:string,de:string,en:string}> */
function competency_child_ai_results(array $generated, array $keys): array {
  $requested = array_fill_keys($keys, true);
  $results = [];
  foreach ($generated as $item) {
    if (!is_array($item)) continue;
    $key = trim((string)($item['key'] ?? ''));
    $de = trim((string)($item['de'] ?? ''));
    $en = trim((string)($item['en'] ?? ''));
    if ($key === '' || !isset($requested[$key]) || isset($results[$key]) || $de === '' || $en === '') continue;
    if ((function_exists('mb_strlen') ? mb_strlen($de, 'UTF-8') : strlen($de)) > 2000) continue;
    if ((function_exists('mb_strlen') ? mb_strlen($en, 'UTF-8') : strlen($en)) > 2000) continue;
    $results[$key] = ['key' => $key, 'de' => $de, 'en' => $en];
  }
  return $results;
}

function competency_child_ai_same_wording(string $left, string $right): bool {
  $left = competency_child_text_normalize($left);
  $right = competency_child_text_normalize($right);
  $left = (string)(preg_replace('/[\p{P}\p{S}\s]+/u', '', $left) ?? $left);
  $right = (string)(preg_replace('/[\p{P}\p{S}\s]+/u', '', $right) ?? $right);
  return $left !== '' && $left === $right;
}

/** @return array<string,array{key:string,de:string,en:string}> */
function competency_child_ai_translation_results(array $generated, array $keys, array $sourceTexts, string $mode): array {
  $requested = array_fill_keys($keys, true);
  $results = [];
  foreach ($generated as $item) {
    if (!is_array($item)) continue;
    $key = trim((string)($item['key'] ?? ''));
    $translation = trim((string)($item['translation'] ?? ''));
    if ($key === '' || !isset($requested[$key]) || isset($results[$key]) || $translation === '') continue;
    if ((function_exists('mb_strlen') ? mb_strlen($translation, 'UTF-8') : strlen($translation)) > 2000) continue;
    $source = is_array($sourceTexts[$key] ?? null) ? $sourceTexts[$key] : [];
    $de = trim((string)($source['de'] ?? ''));
    $en = trim((string)($source['en'] ?? ''));
    if ($mode === 'translate_to_en' && $de !== '') $en = $translation;
    if ($mode === 'translate_to_de' && $en !== '') $de = $translation;
    if ($de === '' || $en === '') continue;
    $results[$key] = ['key' => $key, 'de' => $de, 'en' => $en];
  }
  return $results;
}

try {
  $data = competency_child_ai_input();
  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $user = current_user() ?? [];
  $userId = (int)($user['id'] ?? 0);
  $isAdmin = (string)($user['role'] ?? '') === 'admin';
  $templateId = (int)($data['template_id'] ?? 0);
  if (!competency_child_ai_template_access($pdo, $templateId, $userId, $isAdmin)) {
    competency_child_ai_reply(['ok' => false, 'error' => t('session.forbidden.message', 'Keine Berechtigung.')], 403);
  }

  $keys = is_array($data['competency_keys'] ?? null) ? $data['competency_keys'] : [];
  $keys = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $keys))));
  $mode = trim((string)($data['mode'] ?? 'simplify_both'));
  if (!in_array($mode, ['simplify_both', 'translate_to_en', 'translate_to_de'], true)) {
    throw new RuntimeException(t('competency_child_texts.ai_mode_invalid', 'Die gewählte KI-Aufgabe ist ungültig.'));
  }
  $sourceTexts = is_array($data['source_texts'] ?? null) ? $data['source_texts'] : [];
  if (!$keys || count($keys) > 12) {
    throw new RuntimeException(t('competency_child_texts.ai_batch_invalid', 'Pro KI-Anfrage sind 1 bis 12 Kompetenzen zulässig.'));
  }

  $catalog = competency_child_text_catalog($pdo, $templateId);
  $catalogByKey = [];
  foreach ($catalog as $item) $catalogByKey[(string)$item['competency_key']] = $item;
  $promptItems = [];
  foreach ($keys as $key) {
    if (!isset($catalogByKey[$key])) throw new RuntimeException(t('competency_child_texts.error.competency', 'Die Kompetenz wurde in dieser Vorlage nicht gefunden.'));
    $item = $catalogByKey[$key];
    if ($mode === 'simplify_both') {
      $promptItems[] = [
        'key' => $key,
        'code' => (string)$item['code'],
        'category_de' => (string)$item['category_de'],
        'category_en' => (string)$item['category_en'],
        'teacher_de' => (string)$item['teacher_text_de'],
        'teacher_en' => (string)$item['teacher_text_en'],
      ];
      continue;
    }
    $source = is_array($sourceTexts[$key] ?? null) ? $sourceTexts[$key] : [];
    $sourceLanguage = $mode === 'translate_to_en' ? 'de' : 'en';
    $sourceText = trim((string)($source[$sourceLanguage] ?? ''));
    if ($sourceText === '' || (function_exists('mb_strlen') ? mb_strlen($sourceText, 'UTF-8') : strlen($sourceText)) > 2000) {
      throw new RuntimeException(t('competency_child_texts.ai_translation_source_missing', 'Für mindestens eine Übersetzung fehlt ein gültiger Ausgangstext.'));
    }
    $promptItems[] = [
      'key' => $key,
      'code' => (string)$item['code'],
      'category_de' => (string)$item['category_de'],
      'category_en' => (string)$item['category_en'],
      'source' => $sourceText,
    ];
  }

  $gradeLevels = competency_child_text_grade_levels($pdo, $templateId);
  $gradeContext = competency_child_text_grade_context($gradeLevels);
  $config = competency_child_ai_config();
  if ($mode !== 'simplify_both') {
    $targetDescription = $mode === 'translate_to_en'
      ? 'natürliches amerikanisches Englisch'
      : 'natürliches Deutsch';
    $sourceDescription = $mode === 'translate_to_en' ? 'deutschen' : 'englischen';
    $systemPrompt = "Du bist eine erfahrene Grundschullehrkraft und professionelle Übersetzerin. Übersetze jeden {$sourceDescription} Kindertext ausschließlich in {$targetDescription}. Bewahre Bedeutung, direkte Ansprache, Ton und Schwierigkeitsgrad des Ausgangstextes. Formuliere natürlich und altersgerecht für die angegebene Klassenstufe. Ergänze, bewerte oder vereinfache den Inhalt nicht eigenmächtig. Erhalte Platzhalter in eckigen Klammern exakt und vollständig. Gib ausschließlich JSON in dieser Form zurück: {\"items\":[{\"key\":\"unverändert\",\"translation\":\"...\"}]}. Jede angeforderte key muss genau einmal vorkommen.";
    $userPrompt = "Zielgruppe: {$gradeContext}. Übersetze nur die folgenden Ausgangstexte in {$targetDescription}:\n"
      . json_encode($promptItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $raw = competency_child_ai_chat([
      ['role' => 'system', 'content' => $systemPrompt],
      ['role' => 'user', 'content' => $userPrompt],
    ], $config);
    $results = competency_child_ai_translation_results(competency_child_ai_decode($raw), $keys, $sourceTexts, $mode);
    if (count($results) !== count($keys)) {
      throw new RuntimeException(t('competency_child_texts.ai_incomplete', 'Die KI hat nicht für alle Kompetenzen vollständige zweisprachige Vorschläge geliefert. Bitte versuche es erneut.'));
    }
    audit('competency_child_text_ai_generate', $userId, ['template_id' => $templateId, 'count' => count($results), 'grade_levels' => $gradeLevels, 'mode' => $mode]);
    competency_child_ai_reply(['ok' => true, 'items' => array_values($results), 'csrf_token' => csrf_token()]);
  }

  $systemPrompt = <<<'PROMPT'
Du bist eine erfahrene Grundschullehrkraft und erklärst Kompetenzbeschreibungen so, dass Kinder sie ohne Hilfe verstehen. Erstelle zu jeder Eingabe genau einen deutschen und einen natürlich klingenden amerikanisch-englischen Kindertext. Richte Wortwahl, Satzbau, Beispiele und Abstraktionsgrad strikt an der angegebenen Klassenstufe und am Alter der jüngsten Zielgruppe aus. Formuliere aus der Perspektive des Kindes als verständliche Ich-kann-Aussage: auf Deutsch mit „Ich …“, auf Englisch mit „I …“. Jeder Text besteht aus genau einem oder höchstens zwei kurzen Sätzen, niemals aus mehr. Der erste Satz nennt die Fähigkeit. Nur wenn eine Erklärung nötig ist, erklärt ein zweiter Satz den schwierigen oder abstrakten Begriff mit geläufigen, konkreten Wörtern oder genau einem kurzen Beispiel. Der neue Text muss deutlich einfacher und kindgerechter als der Lehrkrafttext sein und darf in keiner Sprache wörtlich mit dem Lehrkrafttext übereinstimmen. Er darf etwas länger als der Lehrkrafttext sein. Beschreibe konkret, was das Kind tut oder woran es die Kompetenz erkennt, statt nur einen Fachbegriff durch einen anderen zu ersetzen. Bewahre dabei die fachliche Bedeutung vollständig und halte den deutschen und englischen Text inhaltlich deckungsgleich. Füge keine Bewertung, kein Lob und keine neue Anforderung hinzu. Erhalte Platzhalter in eckigen Klammern exakt und vollständig. Gib ausschließlich ein JSON-Objekt in dieser Form zurück: {"items":[{"key":"unverändert","de":"...","en":"..."}]}. Jede angeforderte key muss genau einmal vorkommen.
PROMPT;
  $userPrompt = "Zielgruppe: {$gradeContext}. Schreibe jeden Lehrkrafttext als leicht verständliche Ich-kann-Erklärung für ein Kind neu. Verwende pro Sprache einen, bei Erklärungsbedarf höchstens zwei kurze Sätze. Der Kindertext darf etwas länger sein und soll mit einfachen, konkreten Worten vollständig erklären, was gemeint ist. Wiederhole den Originaltext nicht unverändert.\n"
    . json_encode($promptItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $raw = competency_child_ai_chat([
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => $userPrompt],
  ], $config);
  $results = competency_child_ai_results(competency_child_ai_decode($raw), $keys);
  if (count($results) !== count($keys)) {
    throw new RuntimeException(t('competency_child_texts.ai_incomplete', 'Die KI hat nicht für alle Kompetenzen vollständige zweisprachige Vorschläge geliefert. Bitte versuche es erneut.'));
  }

  $unchangedKeys = [];
  foreach ($results as $key => $result) {
    $source = $catalogByKey[$key];
    if (competency_child_ai_same_wording($result['de'], (string)$source['teacher_text_de'])
      || competency_child_ai_same_wording($result['en'], (string)$source['teacher_text_en'])) {
      $unchangedKeys[] = $key;
    }
  }
  if ($unchangedKeys) {
    $retryItems = array_values(array_filter($promptItems, static fn(array $item): bool => in_array((string)$item['key'], $unchangedKeys, true)));
    $retryRaw = competency_child_ai_chat([
      ['role' => 'system', 'content' => $systemPrompt],
      ['role' => 'user', 'content' => "Zielgruppe: {$gradeContext}. Die erste Fassung war noch wörtlich wie der Lehrkrafttext. Schreibe jeden Text jetzt als eigenständige, vollständige Ich-kann-Erklärung neu: Deutsch beginnt mit „Ich“, Englisch mit „I“. Nutze einfache, konkrete Alltagswörter und insgesamt höchstens zwei kurze Sätze. Der Text darf etwas länger sein, muss aber genau dieselbe Kompetenz erklären und darf keine neue Anforderung hinzufügen:\n" . json_encode($retryItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ], $config);
    $retryResults = competency_child_ai_results(competency_child_ai_decode($retryRaw), $unchangedKeys);
    foreach ($retryResults as $key => $result) $results[$key] = $result;
  }

  foreach ($results as $key => $result) {
    $source = $catalogByKey[$key];
    if (competency_child_ai_same_wording($result['de'], (string)$source['teacher_text_de'])
      || competency_child_ai_same_wording($result['en'], (string)$source['teacher_text_en'])) {
      throw new RuntimeException(t('competency_child_texts.ai_unchanged', 'Die KI hat mindestens einen Lehrkrafttext unverändert übernommen. Bitte erstelle die Vorschläge erneut.'));
    }
  }

  audit('competency_child_text_ai_generate', $userId, ['template_id' => $templateId, 'count' => count($results), 'grade_levels' => $gradeLevels, 'mode' => $mode]);
  competency_child_ai_reply(['ok' => true, 'items' => array_values($results), 'csrf_token' => csrf_token()]);
} catch (Throwable $e) {
  competency_child_ai_reply(['ok' => false, 'error' => $e->getMessage(), 'csrf_token' => csrf_token()], 400);
}
