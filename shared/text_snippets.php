<?php
// shared/text_snippets.php
// Utilities for managing reusable text snippets.
declare(strict_types=1);

function text_snippet_base_templates(): array {
  return [
    ['category' => 'Schülerziele', 'title' => 'Lesekompetenz stärken', 'content' => 'vertieft seine Lesefertigkeit durch regelmäßiges Vorlesen, Gesprächsrunden und gezielte Lesestrategien.'],
    ['category' => 'Schülerziele', 'title' => 'Teamarbeit fördern', 'content' => 'arbeitet konstruktiv in Gruppen mit, übernimmt wechselnde Rollen und reflektiert die Zusammenarbeit.'],
    ['category' => 'Schülerziele', 'title' => 'Arbeitsruhe einhalten', 'content' => 'hält vereinbarte Lautstärke ein, bleibt bei Aufgaben und achtet auf Materialordnung.'],
    ['category' => 'Lernwege', 'title' => 'Selbstorganisation', 'content' => 'plant Arbeitsschritte eigenständig, nutzt Checklisten und hält vereinbarte Abgabefristen ein.'],
    ['category' => 'Lernwege', 'title' => 'Feedback nutzen', 'content' => 'nimmt Rückmeldungen an, setzt konkrete nächste Schritte fest und überprüft den eigenen Fortschritt.'],
    ['category' => 'Lernwege', 'title' => 'Hausaufgaben', 'content' => 'erledigt Hausaufgaben zuverlässig, bringt Materialien mit und fragt bei Unklarheiten nach.'],
    ['category' => 'Engagement', 'title' => 'AG Teilnahme', 'content' => 'nimmt mit Interesse an der Arbeitsgemeinschaft teil und bringt eigene Ideen ein.'],
    ['category' => 'Engagement', 'title' => 'Klassensprecher:in', 'content' => 'wurde zum Klassensprecher gewählt und vertritt die Klasse zuverlässig.'],
    ['category' => 'Engagement', 'title' => 'Pausendienst', 'content' => 'übernimmt den Pausendienst zuverlässig und erinnert andere freundlich an die Regeln.'],
    ['category' => 'Elternkommunikation', 'title' => 'Elterngespräch vereinbart', 'content' => 'hat einen Gesprächstermin mit den Erziehungsberechtigten, um Lernfortschritte zu besprechen.'],
    ['category' => 'Sozialverhalten', 'title' => 'Konflikte lösen', 'content' => 'hört in Streitgesprächen zu, sucht nach gemeinsamen Lösungen und bezieht andere fair ein.'],
    ['category' => 'Sozialverhalten', 'title' => 'Empathie zeigen', 'content' => 'bietet Hilfe an, bemerkt Bedürfnisse anderer und reagiert rücksichtsvoll.'],
    ['category' => 'Motivation', 'title' => 'Interesse an Sachthemen', 'content' => 'zeigt Neugier an neuen Themen, stellt Nachfragen und teilt eigene Beispiele.'],
    ['category' => 'Motivation', 'title' => 'Durchhaltevermögen', 'content' => 'bleibt auch bei anspruchsvollen Aufgaben dran und nutzt Strategien, um weiterzuarbeiten.'],
    ['category' => 'Arbeitsverhalten', 'title' => 'Sorgfalt', 'content' => 'arbeitet sorgfältig, verbessert Fehler nach Rückmeldung und achtet auf eine ordentliche Darstellung.'],
    ['category' => 'Arbeitsverhalten', 'title' => 'Tempo anpassen', 'content' => 'passt sein Arbeitstempo an, ohne die Qualität zu verlieren, und nutzt Zeit effizient.'],
    ['category' => 'Selbstständigkeit', 'title' => 'Materialorganisation', 'content' => 'hält Materialien bereit, markiert Aufgabenübersichten und verwaltet eigene Unterlagen.'],
    ['category' => 'Selbstständigkeit', 'title' => 'Nachfragen', 'content' => 'holt sich bei Unklarheiten Unterstützung, bevor Aufgaben abgegeben werden.'],
    ['category' => 'Kommunikation', 'title' => 'Vortragen', 'content' => 'spricht vor der Gruppe deutlich, hält Blickkontakt und geht auf Rückfragen ein.'],
    ['category' => 'Kommunikation', 'title' => 'Rückmeldungen geben', 'content' => 'formuliert Feedback wertschätzend und benennt konkrete Beobachtungen.'],
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

function text_snippet_find_by_content(PDO $pdo, string $content, ?int $excludeId): ?array {
  $content = trim($content);
  if ($content === '') return null;

  $sql = "SELECT id, category FROM text_snippets WHERE is_deleted=0 AND content=?";
  $params = [$content];
  if ($excludeId !== null) {
    $sql .= " AND id<>?";
    $params[] = $excludeId;
  }

  $st = $pdo->prepare($sql . " LIMIT 1");
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  return ['id' => (int)($row['id'] ?? 0), 'category' => (string)($row['category'] ?? '')];
}

function text_snippet_save(PDO $pdo, int $userId, string $title, string $category, string $content, bool $generated = false): array {
  $title = trim($title);
  $category = trim($category);
  $content = trim($content);
  if ($title === '') throw new RuntimeException('Titel fehlt.');
  if ($content === '') throw new RuntimeException('Text fehlt.');

  $existing = text_snippet_find_by_content($pdo, $content, null);
  if ($existing) {
    $cat = $existing['category'] !== '' ? $existing['category'] : 'ohne Kategorie';
    throw new RuntimeException('Textbaustein existiert bereits in Kategorie "' . $cat . '".');
  }

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

function text_snippet_update(PDO $pdo, int $id, int $userId, string $title, string $category, string $content): array {
  $id = (int)$id;
  if ($id <= 0) throw new RuntimeException('id fehlt.');

  $title = trim($title);
  $category = trim($category);
  $content = trim($content);
  if ($title === '') throw new RuntimeException('Titel fehlt.');
  if ($content === '') throw new RuntimeException('Text fehlt.');

  $existing = text_snippet_find_by_content($pdo, $content, $id);
  if ($existing) {
    $cat = $existing['category'] !== '' ? $existing['category'] : 'ohne Kategorie';
    throw new RuntimeException('Textbaustein existiert bereits in Kategorie "' . $cat . '".');
  }

  $st = $pdo->prepare(
    "UPDATE text_snippets SET title=?, category=?, content=?, updated_at=NOW() WHERE id=? AND is_deleted=0"
  );
  $st->execute([$title, $category, $content, $id]);
  if ($st->rowCount() === 0) throw new RuntimeException('Textbaustein nicht gefunden.');

  return text_snippet_find($pdo, $id) ?? [
    'id' => $id,
    'title' => $title,
    'category' => $category,
    'content' => $content,
    'created_by' => $userId,
    'created_by_name' => '',
    'is_generated' => false,
    'created_at' => '',
    'updated_at' => '',
  ];
}

function text_snippet_move(PDO $pdo, int $id, string $category): array {
  $id = (int)$id;
  if ($id <= 0) throw new RuntimeException('id fehlt.');
  $category = trim($category);

  $st = $pdo->prepare("UPDATE text_snippets SET category=?, updated_at=NOW() WHERE id=? AND is_deleted=0");
  $st->execute([$category, $id]);
  if ($st->rowCount() === 0) throw new RuntimeException('Textbaustein nicht gefunden.');
  return text_snippet_find($pdo, $id) ?? [
    'id' => $id,
    'title' => '',
    'category' => $category,
    'content' => '',
    'created_by' => null,
    'created_by_name' => '',
    'is_generated' => false,
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

    try {
      text_snippet_save($pdo, $userId, $title, $category, (string)($tpl['content'] ?? ''), true);
      $inserted++;
    } catch (Throwable $e) {
      $skipped++;
    }
  }
  return ['inserted' => $inserted, 'skipped' => $skipped];
}
