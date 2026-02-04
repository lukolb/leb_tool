<?php
declare(strict_types=1);

function font_storage_root(): string {
  $cfg = app_config();
  $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';
  $rootAbs = realpath(__DIR__ . '/..');
  if (!$rootAbs) throw new RuntimeException('Root path missing.');
  return $rootAbs . '/' . $uploadsRel . '/fonts';
}

function font_storage_rel(): string {
  $cfg = app_config();
  $uploadsRel = $cfg['app']['uploads_dir'] ?? 'uploads';
  return $uploadsRel . '/fonts';
}

function ensure_font_storage_dir(): void {
  $dir = font_storage_root();
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

function font_manifest_path(): string {
  return font_storage_root() . '/manifest.json';
}

function normalize_font_name(string $name): string {
  $name = trim($name);
  if ($name === '') return '';
  $name = ltrim($name, '/');
  $name = preg_replace('/^[A-Z]{6}\+/', '', $name) ?? $name;
  return strtolower(trim($name));
}

function sanitize_font_key(string $name): string {
  $name = normalize_font_name($name);
  $name = preg_replace('/[^a-z0-9._-]+/', '_', $name) ?? $name;
  $name = trim($name, '_- .');
  return $name;
}

function load_font_manifest(): array {
  $path = font_manifest_path();
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function save_font_manifest(array $manifest): void {
  ensure_font_storage_dir();
  $path = font_manifest_path();
  file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
