<?php
// Shared helpers for display-safe group labels and backward-compatible group keys.
declare(strict_types=1);

function app_group_meta_first_string(array $meta, array $keys): string {
  foreach ($keys as $key) {
    $v = $meta[$key] ?? null;
    if (is_string($v) || is_numeric($v)) {
      $s = trim((string)$v);
      if ($s !== '') return $s;
    }
  }
  return '';
}

function app_group_meta_rcff_first_string(array $meta, array $keys): string {
  $rcff = is_array($meta['rcff'] ?? null) ? $meta['rcff'] : [];
  return app_group_meta_first_string($rcff, $keys);
}

function app_group_parts_from_meta(array $meta): array {
  $raw = app_group_meta_first_string($meta, ['group', 'category', 'category_de', 'group_label']);
  if ($raw === '') $raw = app_group_meta_rcff_first_string($meta, ['category_de', 'category']);

  $parts = array_values(array_filter(array_map('trim', explode('/', $raw)), fn($p) => $p !== ''));

  // Group and subgroup labels are free-form display text. Keep hyphens intact;
  // legacy hyphen truncation is exposed only as an alias for old DB rows.
  $group = $parts[0] ?? '';
  if ($group === '') $group = 'Allgemein';

  $subgroup = app_group_meta_first_string($meta, ['subgroup', 'sub_group', 'subcategory', 'subcategory_de', 'subgroup_label']);
  if ($subgroup === '') $subgroup = app_group_meta_rcff_first_string($meta, ['subcategory_de', 'subcategory']);
  if ($subgroup === '' && count($parts) > 1) {
    $subgroup = implode(' / ', array_slice($parts, 1));
  }

  return ['group' => $group, 'subgroup' => $subgroup];
}

function app_group_label_from_meta(array $meta): string {
  $parts = app_group_parts_from_meta($meta);
  return (string)$parts['group'];
}

function app_group_key_from_meta(array $meta): string {
  // Current technical key: full group label. New rows are stored with this key.
  return app_group_label_from_meta($meta);
}

function app_legacy_group_key_from_label(string $label): string {
  $label = trim($label);
  if ($label === '') return '';
  $pos = strpos($label, '-');
  if ($pos === false) return $label;
  $legacy = trim(substr($label, 0, $pos));
  return $legacy !== '' ? $legacy : $label;
}

function app_group_key_aliases_from_label(string $label): array {
  $aliases = [];
  foreach ([$label, app_legacy_group_key_from_label($label)] as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate !== '' && !in_array($candidate, $aliases, true)) $aliases[] = $candidate;
  }
  return $aliases;
}

function app_group_key_aliases_from_meta(array $meta): array {
  return app_group_key_aliases_from_label(app_group_key_from_meta($meta));
}

function app_group_key_matches_map(array $map, array $aliases): bool {
  foreach ($aliases as $alias) {
    $alias = trim((string)$alias);
    if ($alias !== '' && !empty($map[$alias])) return true;
  }
  return false;
}

function app_group_entry_from_alias_map(array $map, array $aliases): ?array {
  foreach ($aliases as $alias) {
    $alias = trim((string)$alias);
    if ($alias !== '' && isset($map[$alias]) && is_array($map[$alias])) return $map[$alias];
  }
  return null;
}
