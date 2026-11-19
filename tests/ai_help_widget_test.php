<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/ai_help.php';

function ai_help_test_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$id = '12345678-1234-1234-1234-123456789abc';
$token = '0123456789abcdef0123456789abcdef';
$expected = "https://app.fobizz.com/ai/chats/public_assistants/{$id}?embed=true&token={$token}";

ai_help_test_assert(
  ai_help_normalize_embed_url("https://app.fobizz.com/ai/chats/public_assistants/{$id}?token={$token}") === $expected,
  'a public fobizz share URL must become the canonical embed URL'
);
ai_help_test_assert(
  ai_help_normalize_embed_url('<iframe src="https://app.fobizz.com/ai/chats/public_assistants/' . $id . '?embed=true&amp;token=' . $token . '" frameborder="0"></iframe>') === $expected,
  'the full fobizz iframe snippet must be accepted'
);
ai_help_test_assert(
  ai_help_public_url($expected) === "https://app.fobizz.com/ai/chats/public_assistants/{$id}?token={$token}",
  'the external link must omit the embed flag'
);

foreach ([
  "http://app.fobizz.com/ai/chats/public_assistants/{$id}?token={$token}",
  "https://evil.example/ai/chats/public_assistants/{$id}?token={$token}",
  "https://app.fobizz.com/ai/chats/public_assistants/{$id}",
  "https://app.fobizz.com/ai/chats/public_assistants/not-a-uuid?token={$token}",
] as $invalid) {
  ai_help_test_assert(ai_help_normalize_embed_url($invalid) === '', 'invalid embed input must be rejected');
}

$root = dirname(__DIR__);
$layout = file_get_contents($root . '/shared/_layout.php');
$settings = file_get_contents($root . '/admin/settings.php');
$translations = file_get_contents($root . '/shared/translations.php');
$css = file_get_contents($root . '/assets/app.css');
$sample = file_get_contents($root . '/config.sample.php');
$handbook = file_get_contents($root . '/docs/leb_tool_benutzerhandbuch.tex');

ai_help_test_assert(
  is_string($layout) && str_contains($layout, 'class="ai-help-fab"')
    && str_contains($layout, 'data-src="<?=h($embedUrl)?>"')
    && str_contains($layout, "frame.setAttribute('src', frame.dataset.src || '')"),
  'the shared authenticated layout must lazily load the floating help widget'
);
ai_help_test_assert(
  is_string($settings) && str_contains($settings, 'name="help_assistant_form"')
    && str_contains($settings, 'type="password"')
    && str_contains($settings, 'value="save_help_assistant"')
    && str_contains($settings, "elseif (\$action === 'save_help_assistant')")
    && str_contains($settings, 'ai_help_normalize_embed_url'),
  'admin settings must configure the assistant through an isolated save action'
);
ai_help_test_assert(
  is_string($translations) && substr_count($translations, "'ai_help.title'") === 2
    && substr_count($translations, "'admin.settings.help_assistant.title'") === 2,
  'widget and settings labels must be translated in German and English'
);
ai_help_test_assert(
  is_string($css) && str_contains($css, '.ai-help-fab') && str_contains($css, '.ai-help-panel'),
  'the floating button and panel must be styled'
);
ai_help_test_assert(
  is_string($sample) && str_contains($sample, "'help_assistant' => [")
    && str_contains($sample, "'enabled' => false")
    && str_contains($sample, "'embed_url' => ''"),
  'the sample configuration must keep the assistant disabled and token-free'
);
ai_help_test_assert(
  is_string($handbook) && str_contains($handbook, 'Schwebendes Fragezeichen')
    && str_contains($handbook, 'jeweils aktuelle Handbuch-PDF der LEB-Webseite'),
  'the handbook must document access, privacy, and current web sources'
);

fwrite(STDOUT, "ai_help_widget_test: OK\n");
