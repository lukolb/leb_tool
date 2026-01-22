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

function normalize_period_label(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? $s : 'Standard';
}

function load_teachers_for_delegation(PDO $pdo): array {
  $st = $pdo->query(
    "SELECT id, display_name, role
     FROM users
     WHERE is_active=1 AND deleted_at IS NULL AND role IN ('teacher','admin')
     ORDER BY display_name ASC, id ASC"
  );
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = [
      'id' => (int)$r['id'],
      'name' => trim((string)($r['display_name'] ?? '')),
      'role' => (string)($r['role'] ?? ''),
    ];
  }
  return $out;
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
    $users = load_teachers_for_delegation($pdo);

    if (($u['role'] ?? '') === 'admin') {
      $st = $pdo->query(
        "SELECT d.class_id, d.school_year, d.period_label, d.group_key, d.user_id, d.status, d.note, d.updated_at,
                c.school_year AS c_school_year, c.grade_level, c.label, c.name,
                u.display_name
         FROM class_group_delegations d
         INNER JOIN classes c ON c.id=d.class_id
         LEFT JOIN users u ON u.id=d.user_id
         WHERE c.is_active=1
         ORDER BY d.updated_at DESC, d.class_id DESC, d.group_key ASC"
      );
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $st = $pdo->prepare(
        "SELECT d.class_id, d.school_year, d.period_label, d.group_key, d.user_id, d.status, d.note, d.updated_at,
                c.school_year AS c_school_year, c.grade_level, c.label, c.name,
                u.display_name
         FROM class_group_delegations d
         INNER JOIN classes c ON c.id=d.class_id
         LEFT JOIN users u ON u.id=d.user_id
         WHERE c.is_active=1
           AND (
             EXISTS (SELECT 1 FROM user_class_assignments uca WHERE uca.class_id=c.id AND uca.user_id=?)
             OR d.user_id=?
           )
         ORDER BY d.updated_at DESC, d.class_id DESC, d.group_key ASC"
      );
      $st->execute([$userId, $userId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $assignedClassIds = [];
    if (($u['role'] ?? '') !== 'admin') {
      $stAssign = $pdo->prepare("SELECT class_id FROM user_class_assignments WHERE user_id=?");
      $stAssign->execute([$userId]);
      $assignedClassIds = array_map('intval', $stAssign->fetchAll(PDO::FETCH_COLUMN));
    }

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
          'can_assign' => (($u['role'] ?? '') === 'admin') ? true : in_array($cid, $assignedClassIds, true),
          'groups' => [],
        ];
      }

      $gk = trim((string)($r['group_key'] ?? ''));
      if ($gk === '') continue;

      $uid = (int)($r['user_id'] ?? 0);

      if (!isset($byClass[$cid]['groups'][$gk])) {
        $byClass[$cid]['groups'][$gk] = [
          'group_key' => $gk,
          'group_title' => group_title_override($gk),
          'user_ids' => [],
          'users' => [],
          'is_mine' => false,
          'status' => 'open',
          'note' => '',
          'updated_at' => (string)($r['updated_at'] ?? ''),
        ];
      }

      if ($uid > 0 && !in_array($uid, $byClass[$cid]['groups'][$gk]['user_ids'], true)) {
        $byClass[$cid]['groups'][$gk]['user_ids'][] = $uid;
        $byClass[$cid]['groups'][$gk]['users'][] = [
          'user_id' => $uid,
          'user_name' => trim((string)($r['display_name'] ?? '')),
          'status' => (string)($r['status'] ?? 'open'),
          'note' => (string)($r['note'] ?? ''),
        ];
      }
      if ($uid > 0 && $uid === $userId) {
        $byClass[$cid]['groups'][$gk]['is_mine'] = true;
      }
    }

    foreach ($byClass as $cid => $info) {
      $groups = [];
      foreach ($info['groups'] as $group) {
        $allDone = true;
        $singleNote = '';
        if (count($group['users']) === 1) {
          $singleNote = (string)($group['users'][0]['note'] ?? '');
        }
        foreach ($group['users'] as $user) {
          if ((string)($user['status'] ?? 'open') !== 'done') {
            $allDone = false;
            break;
          }
        }
        $group['status'] = $group['users'] ? ($allDone ? 'done' : 'open') : 'open';
        $group['note'] = $singleNote;
        $groups[] = $group;
      }
      $byClass[$cid]['groups'] = $groups;
    }

    json_out(['ok'=>true, 'items'=>array_values($byClass), 'users'=>$users]);
  }

  // save: reassign OR clear OR update status/note
  if ($action === 'save') {
    $classId = (int)($data['class_id'] ?? 0);
    $groupKey = trim((string)($data['group_key'] ?? ''));
    $periodLabel = normalize_period_label((string)($data['period_label'] ?? 'Standard'));
    $targetUserId = (int)($data['user_id'] ?? 0);
    $userIds = $data['user_ids'] ?? null;
    $status = trim((string)($data['status'] ?? 'open'));
    $note = trim((string)($data['note'] ?? ''));

    if ($classId <= 0 || $groupKey === '') throw new RuntimeException('Ungültige Parameter.');

    // permission: must be able to access class; only admins or class-assigned teachers may change delegates
    if (($u['role'] ?? '') !== 'admin') {
      $stAssign = $pdo->prepare("SELECT 1 FROM user_class_assignments WHERE user_id=? AND class_id=? LIMIT 1");
      $stAssign->execute([$userId, $classId]);
      if (!$stAssign->fetchColumn()) {
        throw new RuntimeException('Keine Berechtigung.');
      }
    }

    if (is_array($userIds)) {
      $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x)=>$x>0)));
    } else {
      $userIds = $targetUserId > 0 ? [$targetUserId] : [];
    }

    // validate target users (unless clearing)
    foreach ($userIds as $uid) {
      $stU = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_active=1 AND deleted_at IS NULL AND role IN ('teacher','admin') LIMIT 1");
      $stU->execute([$uid]);
      if (!$stU->fetchColumn()) throw new RuntimeException('Ungültiger Kollege.');
    }

    // school_year from classes (authoritative)
    $stc = $pdo->prepare("SELECT school_year FROM classes WHERE id=? LIMIT 1");
    $stc->execute([$classId]);
    $schoolYear = (string)($stc->fetchColumn() ?: '');
    if ($schoolYear === '') throw new RuntimeException('Klasse nicht gefunden.');

    if ($status !== 'done') $status = 'open';

    if (!$userIds) {
      // clear delegation
      $del = $pdo->prepare(
        "DELETE FROM class_group_delegations
         WHERE class_id=? AND school_year=? AND period_label=? AND group_key=?"
      );
      $del->execute([$classId, $schoolYear, $periodLabel, $groupKey]);

      audit('class_group_delegation_clear', $userId, [
        'class_id'=>$classId,'school_year'=>$schoolYear,'period_label'=>$periodLabel,'group_key'=>$groupKey
      ]);

      json_out(['ok'=>true]);
    }

    $pdo->prepare(
      "DELETE FROM class_group_delegations
       WHERE class_id=? AND school_year=? AND period_label=? AND group_key=?"
    )->execute([$classId, $schoolYear, $periodLabel, $groupKey]);

    $ins = $pdo->prepare(
      "INSERT INTO class_group_delegations
        (class_id, school_year, period_label, group_key, user_id, status, note, created_by_user_id, updated_by_user_id, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
         status=VALUES(status),
         note=VALUES(note),
         updated_by_user_id=VALUES(updated_by_user_id),
         updated_at=NOW()"
    );
    foreach ($userIds as $uid) {
      $ins->execute([$classId, $schoolYear, $periodLabel, $groupKey, $uid, $status, $note, $userId, $userId]);
      audit('class_group_delegation_upsert', $userId, [
        'class_id'=>$classId,'school_year'=>$schoolYear,'period_label'=>$periodLabel,'group_key'=>$groupKey,'user_id'=>$uid,'status'=>$status
      ]);
    }

    json_out(['ok'=>true]);
  }

  if ($action === 'mark') {
    $classId = (int)($data['class_id'] ?? 0);
    $groupKey = trim((string)($data['group_key'] ?? ''));
    $periodLabel = normalize_period_label((string)($data['period_label'] ?? 'Standard'));
    $status = trim((string)($data['status'] ?? 'open'));
    $note = trim((string)($data['note'] ?? ''));

    if ($classId <= 0 || $groupKey === '') throw new RuntimeException('Ungültige Parameter.');

    if (($u['role'] ?? '') !== 'admin' && !user_can_access_class($pdo, $userId, $classId)) {
      throw new RuntimeException('Keine Berechtigung.');
    }

    $stc = $pdo->prepare("SELECT school_year FROM classes WHERE id=? LIMIT 1");
    $stc->execute([$classId]);
    $schoolYear = (string)($stc->fetchColumn() ?: '');
    if ($schoolYear === '') throw new RuntimeException('Klasse nicht gefunden.');

    if ($status !== 'open' && $status !== 'done') $status = 'open';

    $st = $pdo->prepare(
      "SELECT 1
       FROM class_group_delegations
       WHERE class_id=? AND school_year=? AND period_label=? AND group_key=? AND user_id=?
       LIMIT 1"
    );
    $st->execute([$classId, $schoolYear, $periodLabel, $groupKey, $userId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Nicht deine Delegation.');

    upsert_class_group_delegation($pdo, $classId, $schoolYear, $periodLabel, $groupKey, $userId, $status, $note, $userId);
    json_out(['ok'=>true]);
  }

  throw new RuntimeException('Unbekannte action.');
} catch (Throwable $e) {
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 400);
}
