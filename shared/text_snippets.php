<?php
// shared/text_snippets.php
// Utilities for managing reusable text snippets.
declare(strict_types=1);

function text_snippet_base_templates(): array {
  return [
    ['category' => 'Schülerziele', 'title' => 'Lesekompetenz stärken', 'content' => 'Das Kind erweitert seine Lesefertigkeit durch regelmäßiges Vorlesen und gemeinsame Leseübungen.'],
    ['category' => 'Schülerziele', 'title' => 'Teamarbeit fördern', 'content' => 'Arbeitet konstruktiv in Gruppen mit, übernimmt abwechselnd Rollen und reflektiert die Zusammenarbeit.'],
    ['category' => 'Lernwege', 'title' => 'Selbstorganisation', 'content' => 'Plant Arbeitsschritte eigenständig, nutzt Checklisten und hält vereinbarte Abgabefristen ein.'],
    ['category' => 'Lernwege', 'title' => 'Feedback nutzen', 'content' => 'Nimmt Rückmeldungen an, setzt konkrete nächste Schritte fest und überprüft den eigenen Fortschritt.'],
    ['category' => 'Engagement', 'title' => 'AG Teilnahme', 'content' => 'Hat an der Arbeitsgemeinschaft teilgenommen und zeigt kontinuierliches Interesse.'],
    ['category' => 'Engagement', 'title' => 'Klassensprecher:in', 'content' => 'Wurde in diesem Schuljahr als Klassensprecher:in gewählt und vertritt die Klasse zuverlässig.'],
    ['category' => 'Elternkommunikation', 'title' => 'Elterngespräch vereinbart', 'content' => 'Ein Gesprächstermin mit den Erziehungsberechtigten wurde vereinbart, um Lernfortschritte zu besprechen.'],
  ];
}

function text_snippets_list(PDO $pdo): array {
  $st = $pdo->query(
    "SELECT ts.id, ts.title, ts.category, ts.content, ts.created_by, ts.is_generated, ts.created_at, ts.updated_at, u.display_name
     FROM text_snippets ts
     LEFT JOIN users u ON u.id = ts.created_by
     WHERE ts.is_deleted = 0
     ORDER BY ts.category ASC, ts.title ASC, ts.id ASC"
  );
  $rows = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
      'id' => (int)$r['id'],
      'title' => (string)($r['title'] ?? ''),
      'category' => (string)($r['category'] ?? ''),
      'content' => (string)($r['content'] ?? ''),
      'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null,
      'created_by_name' => (string)($r['display_name'] ?? ''),
      'is_generated' => (int)($r['is_generated'] ?? 0) === 1,
      'created_at' => (string)($r['created_at'] ?? ''),
      'updated_at' => (string)($r['updated_at'] ?? ''),
    ];
  }
  return $rows;
}

function text_snippet_find(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare(
    "SELECT ts.id, ts.title, ts.category, ts.content, ts.created_by, ts.is_generated, ts.created_at, ts.updated_at, u.display_name
     FROM text_snippets ts
     LEFT JOIN users u ON u.id = ts.created_by
     WHERE ts.id=? AND ts.is_deleted=0"
  );
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  return [
    'id' => (int)$row['id'],
    'title' => (string)($row['title'] ?? ''),
    'category' => (string)($row['category'] ?? ''),
    'content' => (string)($row['content'] ?? ''),
    'created_by' => $row['created_by'] !== null ? (int)$row['created_by'] : null,
    'created_by_name' => (string)($row['display_name'] ?? ''),
    'is_generated' => (int)($row['is_generated'] ?? 0) === 1,
    'created_at' => (string)($row['created_at'] ?? ''),
    'updated_at' => (string)($row['updated_at'] ?? ''),
  ];
}

function text_snippet_save(PDO $pdo, int $userId, string $title, string $category, string $content, bool $generated = false): array {
  $title = trim($title);
  $category = trim($category);
  $content = trim($content);
  if ($title === '') throw new RuntimeException('Titel fehlt.');
  if ($content === '') throw new RuntimeException('Text fehlt.');

  $st = $pdo->prepare(
    "INSERT INTO text_snippets (title, category, content, created_by, is_generated, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
  );
  $st->execute([$title, $category, $content, $userId, $generated ? 1 : 0]);
  $id = (int)$pdo->lastInsertId();
  return text_snippet_find($pdo, $id) ?? [
    'id' => $id,
    'title' => $title,
    'category' => $category,
    'content' => $content,
    'created_by' => $userId,
    'created_by_name' => '',
    'is_generated' => $generated,
    'created_at' => '',
    'updated_at' => '',
  ];
}

function text_snippet_delete(PDO $pdo, int $id): bool {
  $st = $pdo->prepare("UPDATE text_snippets SET is_deleted=1, updated_at=NOW() WHERE id=?");
  $st->execute([$id]);
  return $st->rowCount() > 0;
}

function text_snippet_generate_base(PDO $pdo, int $userId): array {
  $templates = text_snippet_base_templates();
  $inserted = 0;
  $skipped = 0;
  foreach ($templates as $tpl) {
    $title = trim((string)($tpl['title'] ?? ''));
    $category = trim((string)($tpl['category'] ?? ''));
    if ($title === '') continue;

    $st = $pdo->prepare("SELECT id FROM text_snippets WHERE is_deleted=0 AND title=? AND category=? LIMIT 1");
    $st->execute([$title, $category]);
    if ($st->fetchColumn()) { $skipped++; continue; }

    text_snippet_save($pdo, $userId, $title, $category, (string)($tpl['content'] ?? ''), true);
    $inserted++;
  }
  return ['inserted' => $inserted, 'skipped' => $skipped];
}
