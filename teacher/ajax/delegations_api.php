<?php
// teacher/ajax/delegations_api.php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_teacher();

header('Content-Type: application/json; charset=utf-8');

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

function group_title_override(string $groupKey): string {
  $cfg = app_config();
  $map = $cfg['student']['group_titles'] ?? [];
  if (!is_array($map)) return $groupKey;
  $t = $map[$groupKey] ?? null;
  $t = is_string($t) ? trim($t) : '';
  return $t !== '' ? $t : $groupKey;
}

try {
  $data = read_json_body();

  if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) $_POST['csrf_token'] = (string)$data['csrf_token'];
  if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  csrf_verify();

  $pdo = db();
  $u = current_user();
  $userId = (int)($u['id'] ?? 0);

  $action = (string)($data['action'] ?? '');
  if ($action === '') throw new RuntimeException('action fehlt.');

  if ($action === 'load') {
    $st = $pdo->prepare(
      "SELECT d.class_id, d.school_year, d.period_label, d.group_key, d.status, d.note, d.updated_at,
              c.school_year AS c_school_year, c.grade_level, c.label, c.name
       FROM class_group_delegations d
       INNER JOIN classes c ON c.id=d.class_id
       WHERE d.user_id=? AND c.is_active=1
       ORDER BY d.updated_at DESC, d.class_id DESC, d.group_key ASC"
    );
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $byClass = [];
    foreach ($rows as $r) {
      $cid = (int)($r['class_id'] ?? 0);
      if ($cid <= 0) continue;

      if (!isset($byClass[$cid])) {
        $byClass[$cid] = [
          'class_id' => $cid,
          'school_year' => (string)($r['school_year'] ?? ''),
          'period_label' => (string)($r['period_label'] ?? ''),
          'class_title' => class_display([
            'id' => $cid,
            'school_year' => (string)($r['c_school_year'] ?? ''),
            'grade_level' => $r['grade_level'] !== null ? (int)$r['grade_level'] : null,
            'label' => (string)($r['label'] ?? ''),
            'name' => (string)($r['name'] ?? ''),
          ]),
          'groups' => [],
        ];
      }

      $gk = trim((string)($r['group_key'] ?? ''));
      if ($gk === '') continue;

      $byClass[$cid]['groups'][] = [
        'group_key' => $gk,
        'group_title' => group_title_override($gk),
        'status' => (string)($r['status'] ?? 'open'),
        'note' => (string)($r['note'] ?? ''),
        'updated_at' => (string)($r['updated_at'] ?? ''),
      ];
    }

    json_out(['ok'=>true, 'items'=>array_values($byClass)]);
  }

  throw new RuntimeException('Unbekannte action.');
} catch (Throwable $e) {
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 400);
}
