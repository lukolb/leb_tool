<?php
declare(strict_types=1);

/**
 * Accept a fobizz public-assistant share URL or the complete iframe snippet and
 * return the canonical embed URL. Empty strings are intentionally allowed so
 * installations can keep the feature disabled.
 */
function ai_help_normalize_embed_url(string $input): string {
  $value = trim($input);
  if ($value === '') return '';

  if (preg_match('~<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~is', $value, $m)) {
    $value = (string)$m[2];
  }
  $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

  $parts = parse_url($value);
  if (!is_array($parts)) return '';
  if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') return '';

  $host = strtolower((string)($parts['host'] ?? ''));
  if (!in_array($host, ['app.fobizz.com', 'tools.fobizz.com'], true)) return '';
  if (isset($parts['user']) || isset($parts['pass'])) return '';

  $path = (string)($parts['path'] ?? '');
  if (!preg_match('~^/ai/chats/public_assistants/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $path)) return '';

  $query = [];
  parse_str((string)($parts['query'] ?? ''), $query);
  $token = trim((string)($query['token'] ?? ''));
  if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token)) return '';

  return 'https://' . $host . $path . '?' . http_build_query([
    'embed' => 'true',
    'token' => $token,
  ], '', '&', PHP_QUERY_RFC3986);
}

function ai_help_embed_url(): string {
  $cfg = app_config();
  $help = $cfg['help_assistant'] ?? [];
  if (!is_array($help) || empty($help['enabled'])) return '';
  return ai_help_normalize_embed_url((string)($help['embed_url'] ?? ''));
}

function ai_help_public_url(string $embedUrl): string {
  $normalized = ai_help_normalize_embed_url($embedUrl);
  if ($normalized === '') return '';
  $parts = parse_url($normalized);
  if (!is_array($parts)) return '';
  $query = [];
  parse_str((string)($parts['query'] ?? ''), $query);
  return 'https://' . (string)$parts['host'] . (string)$parts['path'] . '?' . http_build_query([
    'token' => (string)($query['token'] ?? ''),
  ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Keep the app frame on this installation and prevent recursive help frames.
 */
function ai_help_normalize_workspace_page(string $input, string $fallback, string $basePath = ''): string {
  $value = trim($input);
  if ($value === '' || str_contains($value, "\\")) return $fallback;

  $parts = parse_url($value);
  if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
    return $fallback;
  }

  $path = '/' . ltrim((string)($parts['path'] ?? ''), '/');
  if ($path === '/' || str_contains($path, "\0")) return $fallback;
  $normalizedBase = rtrim('/' . ltrim($basePath, '/'), '/');
  if ($normalizedBase === '/') $normalizedBase = '';
  if ($normalizedBase !== '' && $path !== $normalizedBase && !str_starts_with($path, $normalizedBase . '/')) {
    return $fallback;
  }

  if (in_array(basename($path), ['help_workspace.php', 'help_assistant.php'], true)) return $fallback;

  $result = $path;
  if (isset($parts['query']) && $parts['query'] !== '') $result .= '?' . $parts['query'];
  if (isset($parts['fragment']) && $parts['fragment'] !== '') $result .= '#' . $parts['fragment'];
  return $result;
}
