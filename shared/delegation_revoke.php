<?php
declare(strict_types=1);

function delegation_revoke_user_names(PDO $pdo): array {
  $stUsers = $pdo->query("SELECT id, display_name FROM users");
  $userNames = [];
  foreach ($stUsers->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
    $uid = (int)($uRow['id'] ?? 0);
    if ($uid > 0) $userNames[$uid] = trim((string)($uRow['display_name'] ?? ''));
  }
  return $userNames;
}

function annotate_revoked_delegation_texts(PDO $pdo, ?array $scopes = null): int {
  $params = [];
  $whereExtra = '';

  if ($scopes !== null) {
    $pairs = [];
    foreach ($scopes as $scope) {
      if (!is_array($scope)) continue;
      $classId = (int)($scope['class_id'] ?? 0);
      $schoolYear = trim((string)($scope['school_year'] ?? ''));
      if ($classId <= 0 || $schoolYear === '') continue;

      $groupAliases = $scope['group_aliases'] ?? null;
      if (!is_array($groupAliases)) {
        $groupKey = trim((string)($scope['group_key'] ?? ''));
        $groupAliases = $groupKey !== '' ? app_group_key_aliases_from_label($groupKey) : [];
      }
      $groupAliases = array_values(array_unique(array_filter(array_map('strval', $groupAliases), static fn($v) => trim($v) !== '')));
      if (!$groupAliases) continue;

      $stClass = $pdo->prepare("SELECT template_id, period_label FROM classes WHERE id=? LIMIT 1");
      $stClass->execute([$classId]);
      $classRow = $stClass->fetch(PDO::FETCH_ASSOC);
      if (!$classRow) continue;
      $templateId = (int)($classRow['template_id'] ?? 0);
      if ($templateId <= 0) continue;

      $stFields = $pdo->prepare("SELECT id, meta_json FROM template_fields WHERE template_id=? AND can_teacher_edit=1");
      $stFields->execute([$templateId]);
      $fieldIds = [];
      foreach ($stFields->fetchAll(PDO::FETCH_ASSOC) as $fieldRow) {
        $meta = json_decode((string)($fieldRow['meta_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $aliases = app_group_key_aliases_from_meta($meta);
        if (array_intersect($groupAliases, $aliases)) $fieldIds[] = (int)$fieldRow['id'];
      }
      $fieldIds = array_values(array_unique(array_filter($fieldIds, static fn($x) => $x > 0)));
      if (!$fieldIds) continue;

      $periodLabel = normalize_class_period_label($scope['period_label'] ?? ($classRow['period_label'] ?? 'Standard'));
      $classReportPeriod = class_report_period_label($classId, $periodLabel);
      $stReports = $pdo->prepare(
        "SELECT ri.id
         FROM report_instances ri
         LEFT JOIN students s ON s.id=ri.student_id
         WHERE ri.school_year=?
           AND (
             (s.class_id=? AND ri.period_label=?)
             OR (ri.student_id IS NULL AND ri.template_id=? AND ri.period_label=?)
           )"
      );
      $stReports->execute([$schoolYear, $classId, $periodLabel, $templateId, $classReportPeriod]);
      $reportIds = array_values(array_unique(array_filter(array_map('intval', $stReports->fetchAll(PDO::FETCH_COLUMN)), static fn($x) => $x > 0)));
      if (!$reportIds) continue;

      foreach ($reportIds as $rid) {
        foreach ($fieldIds as $fid) $pairs[] = [$rid, $fid];
      }
    }

    if (!$pairs) return 0;
    $parts = [];
    foreach ($pairs as $pair) {
      $parts[] = '(report_instance_id=? AND template_field_id=?)';
      $params[] = (int)$pair[0];
      $params[] = (int)$pair[1];
    }
    $whereExtra = ' AND (' . implode(' OR ', $parts) . ')';
  }

  $userNames = delegation_revoke_user_names($pdo);
  $st = $pdo->prepare(
    "SELECT report_instance_id, template_field_id, value_text, value_json
     FROM field_values
     WHERE source='teacher'
       AND value_json IS NOT NULL
       AND value_json LIKE '%\"free_text\"%'" . $whereExtra
  );
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return 0;

  $up = $pdo->prepare(
    "UPDATE field_values
     SET value_text=?, value_json=?, updated_at=NOW()
     WHERE report_instance_id=? AND template_field_id=? AND source='teacher'
     LIMIT 1"
  );

  $changed = 0;
  foreach ($rows as $row) {
    $decoded = json_decode((string)($row['value_json'] ?? ''), true);
    if (!is_array($decoded) || !isset($decoded['free_text']) || !is_array($decoded['free_text'])) continue;
    $free = $decoded['free_text'];
    if (!empty($free['delegation_revoked_at'])) continue;

    $classText = trim((string)($free['class_text'] ?? ''));
    $delegateTexts = [];
    if (isset($free['delegate_texts']) && is_array($free['delegate_texts'])) {
      foreach ($free['delegate_texts'] as $uidRaw => $txtRaw) {
        $uid = (int)$uidRaw;
        $txt = trim((string)$txtRaw);
        if ($txt === '') continue;
        $name = $userNames[$uid] ?? ('Nutzer #' . $uid);
        if ($name === '') $name = 'Nutzer #' . $uid;
        $delegateTexts[] = ['user_id' => $uid, 'name' => $name, 'text' => $txt];
      }
    }
    if (!$delegateTexts) {
      $txt = trim((string)($free['delegate_text'] ?? ''));
      if ($txt === '') continue;
      $uid = (int)($free['delegate_user_id'] ?? 0);
      $name = $userNames[$uid] ?? ($uid > 0 ? ('Nutzer #' . $uid) : 'Delegierte Lehrkraft');
      if ($name === '') $name = $uid > 0 ? ('Nutzer #' . $uid) : 'Delegierte Lehrkraft';
      $delegateTexts[] = ['user_id' => $uid, 'name' => $name, 'text' => $txt];
    }

    $parts = [];
    if ($classText !== '') $parts[] = $classText;
    $annotated = [];
    foreach ($delegateTexts as $item) $annotated[] = (string)$item['name'] . ":\n" . (string)$item['text'];
    if ($annotated) $parts[] = "Ergänzungen aus zurückgezogenen Delegationen:\n" . implode("\n\n", $annotated);
    $valueText = trim(implode("\n\n", $parts));
    if ($valueText === '') continue;

    $decoded['free_text']['class_text'] = $valueText;
    $decoded['free_text']['delegation_revoked_at'] = date('c');
    $decoded['free_text']['revoked_delegate_texts'] = $delegateTexts;
    $nextJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $up->execute([$valueText, $nextJson, (int)$row['report_instance_id'], (int)$row['template_field_id']]);
    $changed++;
  }
  return $changed;
}

function revoked_delegation_comment_flags(PDO $pdo, array $reportIds): array {
  $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), static fn($x) => $x > 0)));
  if (!$reportIds) return [];
  $in = implode(',', array_fill(0, count($reportIds), '?'));
  $st = $pdo->prepare(
    "SELECT report_instance_id, value_text, value_json
     FROM field_values
     WHERE source='teacher'
       AND report_instance_id IN ($in)
       AND value_json IS NOT NULL
       AND value_json LIKE '%\"revoked_delegate_texts\"%'"
  );
  $st->execute($reportIds);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rid = (string)(int)$row['report_instance_id'];
    $decoded = json_decode((string)$row['value_json'], true);
    $items = $decoded['free_text']['revoked_delegate_texts'] ?? null;
    if (!is_array($items) || !$items) continue;
    $visibleText = (string)($row['value_text'] ?? '');
    $classText = (string)($decoded['free_text']['class_text'] ?? '');
    if (strpos($visibleText, 'Ergänzungen aus zurückgezogenen Delegationen:') === false
        && strpos($classText, 'Ergänzungen aus zurückgezogenen Delegationen:') === false) {
      continue;
    }
    $names = [];
    foreach ($items as $item) {
      $name = trim((string)($item['name'] ?? ''));
      if ($name !== '') $names[$name] = true;
    }
    if (!isset($out[$rid])) $out[$rid] = ['count' => 0, 'names' => []];
    $out[$rid]['count']++;
    $out[$rid]['names'] = array_values(array_unique(array_merge($out[$rid]['names'], array_keys($names))));
  }
  return $out;
}
