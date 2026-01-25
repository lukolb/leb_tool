<?php
// teacher/parents.php
// Manage parent preview requests (teacher side)
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);
$role = (string)($u['role'] ?? '');
$cfg = app_config();
$parentCfg = $cfg['parent'] ?? [];
$parentAutoApprove = (bool)($parentCfg['auto_approve_requests'] ?? false);
$signaturePurpose = 'parent_export';
$signatureEnabled = (bool)($parentCfg['signature_enabled'] ?? false);
$signatureConfigured = $signatureEnabled && signature_configured();
$meetingFeedbackEnabled = (bool)($parentCfg['meeting_feedback_enabled'] ?? false);

function parent_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

function latest_report_for_student(PDO $pdo, int $studentId): ?array {
  $st = $pdo->prepare(
    "SELECT id, template_id, student_id, school_year, period_label, status\n" .
    "FROM report_instances\n" .
    "WHERE student_id=?\n" .
    "ORDER BY updated_at DESC, id DESC\n" .
    "LIMIT 1"
  );
  $st->execute([$studentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function sanitize_email(?string $email): ?string {
  $email = trim((string)$email);
  if ($email === '') return null;
  return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function read_signature_payload_from_post(): ?array {
  $raw = $_POST['signature_payload'] ?? '';
  if (!is_string($raw) || trim($raw) === '') return null;
  return signature_sanitize_payload($raw);
}

function format_parent_link_expiry(?string $expiresAt, string $format): string {
  if (!$expiresAt) return '';
  try {
    $dt = new DateTimeImmutable((string)$expiresAt);
  } catch (Throwable $e) {
    return '';
  }
  return $dt->format($format);
}

function build_parent_mail_html(string $template, array $student, string $link, ?string $expiresAt): string {
  $studentName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
  $safeName = h($studentName);
  $safeFirst = h((string)($student['first_name'] ?? ''));
  $safeLast = h((string)($student['last_name'] ?? ''));
  $safeLink = h($link);
  $expiryDe = h(format_parent_link_expiry($expiresAt, 'd.m.Y'));
  $expiryUs = h(format_parent_link_expiry($expiresAt, 'm/d/Y'));
  $escaped = nl2br(h($template));
  $linkHtml = '<a href="' . $safeLink . '">' . $safeLink . '</a>';
  return strtr($escaped, [
    '{{student_name}}' => $safeName,
    '{{first_name}}' => $safeFirst,
    '{{last_name}}' => $safeLast,
    '{{parent_link}}' => $linkHtml,
    '{{link}}' => $linkHtml,
    '{{link_expires_de}}' => $expiryDe,
    '{{link_expires_us}}' => $expiryUs,
  ]);
}

function build_feedback_reply_mailto(array $emails, string $studentName, string $message, string $createdAt): ?string {
  if (!$emails) return null;
  $subject = 'Re: Eltern-Rückmeldung zum Lernentwicklungsbericht von ' . $studentName;
  $replyText = trim($message) !== '' ? $message : '—';
  $body = "Hallo,\n\n\n\n---\nIhre Rückmeldung vom {$createdAt}:\n" . $replyText;
  $recipients = implode(',', $emails);
  $query = 'subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
  return 'mailto:' . $recipients . '?' . $query;
}

function meeting_feedback_stats(PDO $pdo, string $whereSql, array $params): array {
  $sql = "SELECT\n" .
    "  COUNT(*) AS total,\n" .
    "  SUM(q1=4) AS q1_4,\n" .
    "  SUM(q1=3) AS q1_3,\n" .
    "  SUM(q1=2) AS q1_2,\n" .
    "  SUM(q1=1) AS q1_1,\n" .
    "  SUM(q2=4) AS q2_4,\n" .
    "  SUM(q2=3) AS q2_3,\n" .
    "  SUM(q2=2) AS q2_2,\n" .
    "  SUM(q2=1) AS q2_1,\n" .
    "  SUM(q3=4) AS q3_4,\n" .
    "  SUM(q3=3) AS q3_3,\n" .
    "  SUM(q3=2) AS q3_2,\n" .
    "  SUM(q3=1) AS q3_1\n" .
    "FROM parent_meeting_feedback";
  if ($whereSql !== '') {
    $sql .= " WHERE " . $whereSql;
  }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($row as $k => $v) {
    $out[$k] = (int)$v;
  }
  return $out;
}

function meeting_feedback_option_labels(): array {
  return [
    4 => 'Stimme völlig zu / Strongly agree',
    3 => 'Stimme eher zu / Agree',
    2 => 'Stimme eher nicht zu / Disagree',
    1 => 'Stimme nicht zu / Strongly disagree',
  ];
}

function meeting_feedback_texts(PDO $pdo, string $whereSql, array $params): array {
  $sql = "SELECT pmf.id, pmf.message, pmf.created_at, pmf.is_anonymous, s.first_name, s.last_name, c.school_year, c.grade_level, c.label, c.name\n" .
    "FROM parent_meeting_feedback pmf\n" .
    "JOIN students s ON s.id=pmf.student_id\n" .
    "JOIN classes c ON c.id=pmf.class_id";
  if ($whereSql !== '') $sql .= " WHERE " . $whereSql;
  $sql .= " ORDER BY pmf.created_at DESC LIMIT 200";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function render_meeting_feedback_pies(array $stats, array $questions): void {
  $total = (int)($stats['total'] ?? 0);
  $segments = [
    1 => ['label' => 'Stimme nicht zu', 'color' => '#d32f2f'],
    2 => ['label' => 'Stimme eher nicht zu', 'color' => '#f57c00'],
    3 => ['label' => 'Stimme eher zu', 'color' => '#558dfc'],
    4 => ['label' => 'Stimme völlig zu', 'color' => '#16bc00'],
  ];
  echo '<div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:12px;">';
  foreach ($questions as $key => $title) {
    if ($total <= 0) {
      echo '<div style="flex:1; min-width:220px; padding:12px; border:1px solid var(--border); border-radius:8px; background:#fff;">';
      echo '<div style="font-weight:600; margin-bottom:10px;">' . h($title) . '</div>';
      echo '<div class="muted">Noch keine Rückmeldungen vorhanden.</div>';
      echo '</div>';
      continue;
    }
    $offset = 0;
    $slices = [];
    foreach ($segments as $score => $seg) {
      $count = (int)($stats[$key . '_' . $score] ?? 0);
      $pct = $total > 0 ? ($count / $total) * 100 : 0;
      $start = $offset;
      $end = $offset + $pct;
      $offset = $end;
      $slices[] = $seg['color'] . ' ' . $start . '% ' . $end . '%';
    }
    $gradient = $slices ? 'conic-gradient(' . implode(', ', $slices) . ')' : 'conic-gradient(#e0e0e0 0 100%)';
    echo '<div style="flex:1; min-width:220px; padding:12px; border:1px solid var(--border); border-radius:8px; background:#fff;">';
    echo '<div style="font-weight:600; margin-bottom:10px;">' . h($title) . '</div>';
    echo '<div style="display:flex; gap:12px; align-items:center;">';
    echo '<div style="width:120px; height:120px; border-radius:50%; background:' . h($gradient) . ';"></div>';
    echo '<div style="display:flex; flex-direction:column; gap:6px;">';
    foreach ($segments as $score => $seg) {
      $count = (int)($stats[$key . '_' . $score] ?? 0);
      $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0;
      echo '<div style="display:flex; align-items:center; gap:6px; font-size:13px;">';
      echo '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:' . h($seg['color']) . ';"></span>';
      echo '<span>' . h($seg['label']) . ' · ' . h((string)$pct) . '% (' . h((string)$count) . ')</span>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
  }
  echo '</div>';
}

function teacher_feedback_query_url(array $overrides): string {
  $params = $_GET;
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    } else {
      $params[$key] = $value;
    }
  }
  $query = http_build_query($params);
  return url('teacher/parents.php' . ($query ? ('?' . $query) : ''));
}

// --- classes for teacher/admin ---
if ($role === 'admin') {
  $classes = $pdo->query(
    "SELECT id, school_year, grade_level, label, name FROM classes WHERE is_active=1 ORDER BY school_year DESC, grade_level DESC, label ASC, name ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
} else {
  $st = $pdo->prepare(
    "SELECT c.id, c.school_year, c.grade_level, c.label, c.name\n" .
    "FROM classes c\n" .
    "JOIN user_class_assignments uca ON uca.class_id=c.id\n" .
    "WHERE uca.user_id=? AND c.is_active=1\n" .
    "ORDER BY c.school_year DESC, c.grade_level DESC, c.label ASC, c.name ASC"
  );
  $st->execute([$userId]);
  $classes = $st->fetchAll(PDO::FETCH_ASSOC);
}

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0 && $classes) {
  $classId = (int)($classes[0]['id'] ?? 0);
}
$selectedClassGradeLevel = null;
$availableGrades = [];
foreach ($classes as $c) {
  if ($c['grade_level'] !== null) $availableGrades[] = (int)$c['grade_level'];
  if ((int)($c['id'] ?? 0) === $classId) {
    $selectedClassGradeLevel = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  }
}
$availableGrades = array_values(array_unique($availableGrades));
sort($availableGrades);
$gradeLevel = null;
$selectedClassLabel = '';
$selectedClassYear = '';
foreach ($classes as $c) {
  if ((int)($c['id'] ?? 0) === $classId) {
    $selectedClassLabel = parent_class_display($c);
    $selectedClassYear = (string)($c['school_year'] ?? '');
    break;
  }
}
if ($role === 'admin') {
  if (isset($_GET['grade_level']) && $_GET['grade_level'] !== '') {
    $gradeLevel = (int)$_GET['grade_level'];
  } elseif ($selectedClassGradeLevel !== null) {
    $gradeLevel = (int)$selectedClassGradeLevel;
  }
}

$alerts = [];
$errors = [];
$mailForm = [
  'mode' => 'class',
  'class_id' => $classId > 0 ? $classId : 0,
  'student_id' => 0,
  'subject' => 'Lernentwicklungsbericht für {{student_name}} - Student Progress Report for {{student_name}}',
  'body' => "Liebe Eltern,\n\nüber den folgenden Link können Sie auf den Lernebtwicklungsbericht für {{student_name}} zugreifen:\n\n{{parent_link}}\n\nDer Link ist gültig bis {{link_expires_de}}. Bei Rückfragen melden Sie sich gerne.\n\nViele Grüße,\n\n\n\nDear Parents,\n\nYou can access the Student Progress Report for {{student_name}} via the following link:\n\n{{parent_link}}\n\nThe link is valid until {{link_expires_us}}. If you have any questions, please feel free to contact us.\n\nKind regards,\n\n",
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $accessibleClassIds = array_values(array_filter(array_map(fn($c)=>(int)($c['id'] ?? 0), $classes), fn($id)=>$id>0));

    if ($action === 'save_signature') {
      if (!$signatureConfigured) {
        throw new RuntimeException('Signatur-Funktion ist nicht konfiguriert.');
      }
      $signaturePayload = read_signature_payload_from_post();
      if (!$signaturePayload) {
        throw new RuntimeException('Signaturdaten fehlen.');
      }
      signature_store_payload($pdo, $userId, $signaturePurpose, $signaturePayload);
      if (is_ajax_request()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
      }
      $alerts[] = 'Grafische Signatur gespeichert.';
    }
    if ($action === 'load_signature') {
      if (!$signatureConfigured) {
        throw new RuntimeException('Signatur-Funktion ist nicht konfiguriert.');
      }
      $payload = signature_get_active_payload($pdo, $userId, $signaturePurpose);
      header('Content-Type: application/json');
      echo json_encode(['ok' => true, 'payload' => $payload]);
      exit;
    }

    $signatureUse = (string)($_POST['signature_use'] ?? '') === '1';
    $signaturePayload = null;
    if ($signatureUse && $signatureConfigured && in_array($action, ['request_all','request_link'], true)) {
      $signaturePayload = read_signature_payload_from_post();
      if ($signaturePayload) {
        signature_store_payload($pdo, $userId, $signaturePurpose, $signaturePayload);
        $alerts[] = 'Grafische Signatur gespeichert.';
      }
    }

    if ($action === 'delete_signature') {
      if (!$signatureConfigured) {
        throw new RuntimeException('Signatur-Funktion ist nicht konfiguriert.');
      }
      signature_deactivate($pdo, $userId, $signaturePurpose);
      $alerts[] = 'Grafische Signatur wurde gelöscht.';
    } elseif ($action === 'save_signature') {
      // handled above
    } elseif ($action === 'send_parent_email') {
      $mailForm = [
        'mode' => (string)($_POST['send_mode'] ?? 'class'),
        'class_id' => (string)($_POST['mail_class_id'] ?? ''),
        'student_id' => (int)($_POST['mail_student_id'] ?? 0),
        'subject' => (string)($_POST['mail_subject'] ?? ''),
        'body' => (string)($_POST['mail_body'] ?? ''),
      ];
      $sendMode = $mailForm['mode'] === 'single' ? 'single' : 'class';
      $subjectTemplate = trim((string)$mailForm['subject']);
      $bodyTemplate = trim((string)$mailForm['body']);
      if ($subjectTemplate === '' || $bodyTemplate === '') {
        throw new RuntimeException('Betreff und Nachricht sind erforderlich.');
      }

      $studentsToSend = [];
      if ($sendMode === 'single') {
        $studentId = $mailForm['student_id'];
        if ($studentId <= 0) throw new RuntimeException('Schüler fehlt.');
        $stStudent = $pdo->prepare(
          "SELECT id, class_id, first_name, last_name, email_parent1, email_parent2\n" .
          "FROM students WHERE id=? LIMIT 1"
        );
        $stStudent->execute([$studentId]);
        $studentRow = $stStudent->fetch(PDO::FETCH_ASSOC);
        if (!$studentRow) throw new RuntimeException('Schüler nicht gefunden.');
        $studentClassId = (int)($studentRow['class_id'] ?? 0);
        if ($role !== 'admin' && !in_array($studentClassId, $accessibleClassIds, true)) {
          throw new RuntimeException('Keine Berechtigung.');
        }
        $studentsToSend = [$studentRow];
      } else {
        $targetClassId = (string)$mailForm['class_id'];
        $classIds = [];
        if ($targetClassId === 'all') {
          $classIds = $accessibleClassIds;
        } else {
          $cid = (int)$targetClassId;
          if ($cid <= 0) throw new RuntimeException('Klasse fehlt.');
          if ($role !== 'admin' && !in_array($cid, $accessibleClassIds, true)) {
            throw new RuntimeException('Keine Berechtigung.');
          }
          $classIds = [$cid];
        }

        if (!$classIds) throw new RuntimeException('Keine Klassen verfügbar.');
        $in = implode(',', array_fill(0, count($classIds), '?'));
        $stStudents = $pdo->prepare(
          "SELECT id, class_id, first_name, last_name, email_parent1, email_parent2\n" .
          "FROM students WHERE class_id IN ($in) AND is_active=1\n" .
          "ORDER BY last_name ASC, first_name ASC"
        );
        $stStudents->execute($classIds);
        $studentsToSend = $stStudents->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }

      if (!$studentsToSend) throw new RuntimeException('Keine Schüler gefunden.');

      $linkStmt = $pdo->prepare(
        "SELECT token, expires_at\n" .
        "FROM parent_portal_links\n" .
        "WHERE student_id=? AND status='approved' AND (expires_at IS NULL OR expires_at > NOW())\n" .
        "ORDER BY updated_at DESC, id DESC LIMIT 1"
      );

      $sent = 0;
      $failed = 0;
      $skippedNoEmail = 0;
      $skippedNoLink = 0;
      $skippedNoEmailNames = [];
      $skippedNoLinkNames = [];
      $failedStudentNames = [];

      foreach ($studentsToSend as $student) {
        $sid = (int)($student['id'] ?? 0);
        if ($sid <= 0) continue;
        $linkStmt->execute([$sid]);
        $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
        $token = $linkRow['token'] ?? null;
        $studentName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
        if (!$token) {
          $skippedNoLink++;
          $skippedNoLinkNames[] = $studentName;
          continue;
        }
        $link = absolute_url('parent/portal.php?token=' . urlencode((string)$token));
        $expiresAt = $linkRow['expires_at'] ?? null;
        $expiresDe = format_parent_link_expiry($expiresAt, 'd.m.Y');
        $expiresUs = format_parent_link_expiry($expiresAt, 'm/d/Y');

        $emails = array_filter([
          sanitize_email($student['email_parent1'] ?? null),
          sanitize_email($student['email_parent2'] ?? null),
        ]);
        $emails = array_values(array_unique($emails));
        if (!$emails) {
          $skippedNoEmail++;
          $skippedNoEmailNames[] = $studentName;
          continue;
        }

        $subject = strtr($subjectTemplate, [
          '{{student_name}}' => $studentName,
          '{{first_name}}' => (string)($student['first_name'] ?? ''),
          '{{last_name}}' => (string)($student['last_name'] ?? ''),
          '{{parent_link}}' => $link,
          '{{link}}' => $link,
          '{{link_expires_de}}' => $expiresDe,
          '{{link_expires_us}}' => $expiresUs,
        ]);
        $bodyHtml = build_parent_mail_html($bodyTemplate, $student, $link, $expiresAt);

        $studentSent = 0;
        foreach ($emails as $email) {
          $ok = send_email((string)$email, $subject, $bodyHtml);
          if ($ok) {
            $sent++;
            $studentSent++;
          } else {
            $failed++;
          }
        }
        if ($studentSent === 0) {
          $failedStudentNames[] = $studentName;
        }
      }

      $alertMsg = 'Serienmail versendet: ' . $sent . ' E-Mails. Ohne Link: ' . $skippedNoLink . ', ohne E-Mail: ' . $skippedNoEmail . '.';
      $missingNames = array_values(array_filter(array_unique(array_merge($skippedNoLinkNames, $skippedNoEmailNames, $failedStudentNames))));
      if ($missingNames) {
        $alertMsg .= ' Keine Mail an: ' . implode(', ', $missingNames) . '.';
      }
      $alerts[] = $alertMsg;
      if ($failed > 0) {
        $errors[] = $failed . ' E-Mails konnten nicht versendet werden.';
      }
    } elseif ($action === 'request_all') {
      $targetClassId = (int)($_POST['class_id'] ?? 0);
      if ($targetClassId <= 0) throw new RuntimeException('Klasse fehlt.');
      if ($role !== 'admin' && !user_can_access_class($pdo, $userId, $targetClassId)) throw new RuntimeException('Keine Berechtigung.');

      $days = (int)($_POST['valid_days'] ?? 14);
      if ($days < 1) $days = 1;
      if ($days > 90) $days = 90;
      $expiresAt = end_of_day_after_days($days);
      $status = $parentAutoApprove ? 'approved' : 'requested';
      $approvedAt = $parentAutoApprove ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null;
      $publishedAt = $parentAutoApprove ? $approvedAt : null;

      $stStudents = $pdo->prepare("SELECT id FROM students WHERE class_id=? AND is_active=1 ORDER BY last_name ASC, first_name ASC");
      $stStudents->execute([$targetClassId]);
      $created = 0; $skippedReport = 0; $skippedActive = 0;
      $stLatestLink = $pdo->prepare("SELECT status, expires_at FROM parent_portal_links WHERE student_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
      $ins = $pdo->prepare(
        "INSERT INTO parent_portal_links (student_id, report_instance_id, token, status, requested_by_user_id, preferred_lang, expires_at, published_at, approved_by_user_id, approved_at)\n" .
        "VALUES (?, ?, ?, ?, ?, 'de', ?, ?, ?, ?)"
      );
      foreach ($stStudents->fetchAll(PDO::FETCH_ASSOC) as $stuRow) {
        $sid = (int)$stuRow['id'];
        $stLatestLink->execute([$sid]);
        $existing = $stLatestLink->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
          $exStatus = (string)($existing['status'] ?? '');
          $exExpires = $existing['expires_at'] ?? null;
          $stillActive = ($exStatus === 'approved' && $exExpires && strtotime((string)$exExpires) > time());
          if ($stillActive) { $skippedActive++; continue; }
        }

        $report = latest_report_for_student($pdo, $sid);
        if (!$report) { $skippedReport++; continue; }

        $token = bin2hex(random_bytes(32));
        $ins->execute([
          $sid,
          (int)$report['id'],
          $token,
          $status,
          $userId,
          $expiresAt,
          $publishedAt,
          null,
          $approvedAt,
        ]);
        $created++;
      }
      $prefix = $parentAutoApprove ? 'Sammelfreischaltung' : 'Sammelanfrage';
      $alerts[] = $prefix . ' erstellt: ' . $created . ' neu, ' . $skippedActive . ' bereits aktiv, ' . $skippedReport . ' ohne Bericht.';
    } else {
      $studentId = (int)($_POST['student_id'] ?? 0);

      if ($studentId <= 0) {
        throw new RuntimeException('Schüler fehlt.');
      }

      $stStudent = $pdo->prepare("SELECT id, class_id, first_name, last_name FROM students WHERE id=? LIMIT 1");
      $stStudent->execute([$studentId]);
      $studentRow = $stStudent->fetch(PDO::FETCH_ASSOC);
      if (!$studentRow) {
        throw new RuntimeException('Schüler nicht gefunden.');
      }

      $studentClassId = (int)($studentRow['class_id'] ?? 0);
      if ($role !== 'admin' && ($studentClassId <= 0 || !user_can_access_class($pdo, $userId, $studentClassId))) {
        throw new RuntimeException('Keine Berechtigung.');
      }

      if ($action === 'request_link') {
        $report = latest_report_for_student($pdo, $studentId);
        if (!$report) throw new RuntimeException('Es gibt noch keinen Berichtseintrag für diese Person.');

        $days = (int)($_POST['valid_days'] ?? 14);
        if ($days < 1) $days = 1;
        if ($days > 90) $days = 90;
        $expiresAt = end_of_day_after_days($days);
        $status = $parentAutoApprove ? 'approved' : 'requested';
        $approvedAt = $parentAutoApprove ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null;
        $publishedAt = $parentAutoApprove ? $approvedAt : null;

        $token = bin2hex(random_bytes(32));

        $ins = $pdo->prepare(
          "INSERT INTO parent_portal_links (student_id, report_instance_id, token, status, requested_by_user_id, preferred_lang, expires_at, published_at, approved_by_user_id, approved_at)\n" .
          "VALUES (?, ?, ?, ?, ?, 'de', ?, ?, ?, ?)"
        );
        $ins->execute([
          $studentId,
          (int)$report['id'],
          $token,
          $status,
          $userId,
          $expiresAt,
          $publishedAt,
          null,
          $approvedAt,
        ]);
        $alerts[] = $parentAutoApprove
          ? 'Elternmodus wurde freigeschaltet.'
          : 'Elternmodus angefragt. Admin-Bestätigung erforderlich.';
      }

      if ($action === 'extend_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId <= 0) throw new RuntimeException('Link fehlt.');
        $days = (int)($_POST['extend_days'] ?? 7);
        if ($days < 1) $days = 1;
        if ($days > 120) $days = 120;

        $stLink = $pdo->prepare(
          "SELECT status, expires_at\n" .
          "FROM parent_portal_links\n" .
          "WHERE id=? AND student_id=?\n" .
          "LIMIT 1"
        );
        $stLink->execute([$linkId, $studentId]);
        $linkRow = $stLink->fetch(PDO::FETCH_ASSOC);
        if (!$linkRow) throw new RuntimeException('Freigabe nicht gefunden.');
        $linkStatus = (string)($linkRow['status'] ?? '');
        if (!in_array($linkStatus, ['approved', 'expired'], true)) {
          throw new RuntimeException('Freigabe kann nicht verlängert werden.');
        }

        $base = $linkRow['expires_at'] ?? null;
        $start = $base ? new DateTimeImmutable((string)$base) : new DateTimeImmutable('today');
        $newExpiry = end_of_day_after_days($days, $start);
        $nextStatus = $linkStatus === 'expired' ? 'approved' : $linkStatus;
        $upd = $pdo->prepare("UPDATE parent_portal_links SET status=?, expires_at=?, updated_at=NOW() WHERE id=?");
        $upd->execute([$nextStatus, $newExpiry, $linkId]);
        $alerts[] = 'Gültigkeit wurde verlängert.';
      }

    }

    if ($action === 'revoke_link') {
      $linkId = (int)($_POST['link_id'] ?? 0);
      if ($linkId > 0) {
        $upd = $pdo->prepare("UPDATE parent_portal_links SET status='revoked', updated_at=NOW() WHERE id=? LIMIT 1");
        $upd->execute([$linkId]);
        $alerts[] = 'Elternzugriff wurde beendet.';
      }
    }

    if ($action === 'mark_feedback_reviewed') {
      $feedbackId = (int)($_POST['feedback_id'] ?? 0);
      if ($feedbackId > 0) {
        $upd = $pdo->prepare(
          "UPDATE parent_feedback pf\n" .
          "JOIN parent_portal_links ppl ON ppl.id=pf.link_id\n" .
          "JOIN students s ON s.id=ppl.student_id\n" .
          "SET pf.is_reviewed=1, pf.reviewed_by_user_id=?, pf.reviewed_at=NOW()\n" .
          "WHERE pf.id=? AND s.class_id=?"
        );
        $upd->execute([$userId, $feedbackId, $studentClassId]);
        $alerts[] = 'Feedback wurde als geprüft markiert.';
      }
    }

  } catch (Throwable $e) {
    if (is_ajax_request()) {
      header('Content-Type: application/json', true, 400);
      echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
      exit;
    }
    $errors[] = $e->getMessage();
  }
}

$signatureActive = $signatureConfigured ? signature_has_active($pdo, $userId, $signaturePurpose) : false;

// Students in selected class
$students = [];
$linkIds = [];
if ($classId > 0) {
  $stStu = $pdo->prepare(
    "SELECT s.*, c.school_year, c.grade_level, c.label, c.name,\n" .
    "  (SELECT id FROM parent_portal_links ppl WHERE ppl.student_id=s.id ORDER BY ppl.updated_at DESC, ppl.id DESC LIMIT 1) AS parent_link_id\n" .
    "FROM students s\n" .
    "JOIN classes c ON c.id=s.class_id\n" .
    "WHERE s.class_id=? AND s.is_active=1\n" .
    "ORDER BY s.last_name ASC, s.first_name ASC"
  );
  $stStu->execute([$classId]);
  $students = $stStu->fetchAll(PDO::FETCH_ASSOC);
  foreach ($students as $row) {
    $lid = (int)($row['parent_link_id'] ?? 0);
    if ($lid > 0) $linkIds[] = $lid;
  }
}

$linkMap = [];
if ($linkIds) {
  $in = implode(',', array_fill(0, count($linkIds), '?'));
  $stLinks = $pdo->prepare(
    "SELECT ppl.*, req.display_name AS requested_by_name, appr.display_name AS approved_by_name\n" .
    "FROM parent_portal_links ppl\n" .
    "LEFT JOIN users req ON req.id=ppl.requested_by_user_id\n" .
    "LEFT JOIN users appr ON appr.id=ppl.approved_by_user_id\n" .
    "WHERE ppl.id IN ($in)"
  );
  $stLinks->execute($linkIds);
  foreach ($stLinks->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $linkMap[(int)$row['id']] = $row;
  }
}

$feedbackCounts = [];
if ($linkIds) {
  $in = implode(',', array_fill(0, count($linkIds), '?'));
  $stFbCount = $pdo->prepare(
    "SELECT link_id,\n" .
    "  SUM(CASE WHEN feedback_type='question' AND is_reviewed=0 THEN 1 ELSE 0 END) AS pending_questions,\n" .
    "  SUM(CASE WHEN feedback_type='question' THEN 1 ELSE 0 END) AS total_questions,\n" .
    "  SUM(CASE WHEN feedback_type='ack' THEN 1 ELSE 0 END) AS total_acks,\n" .
    "  MAX(CASE WHEN feedback_type='ack' THEN created_at END) AS ack_latest\n" .
    "FROM parent_feedback WHERE link_id IN ($in) GROUP BY link_id"
  );
  $stFbCount->execute($linkIds);
  foreach ($stFbCount->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $feedbackCounts[(int)$row['link_id']] = [
      'pending_questions' => (int)($row['pending_questions'] ?? 0),
      'total_questions' => (int)($row['total_questions'] ?? 0),
      'total_acks' => (int)($row['total_acks'] ?? 0),
      'ack_latest' => $row['ack_latest'] ?? null,
    ];
  }
}

// Feedback moderation list for this class
$feedbackList = [];
if ($classId > 0) {
$stFb = $pdo->prepare(
    "SELECT pf.*, s.first_name, s.last_name, s.email_parent1, s.email_parent2, ppl.status AS link_status, ppl.student_id\n" .
    "FROM parent_feedback pf\n" .
    "JOIN parent_portal_links ppl ON ppl.id=pf.link_id\n" .
    "JOIN students s ON s.id=ppl.student_id\n" .
    "WHERE s.class_id=? AND pf.feedback_type='question'\n" .
    "ORDER BY pf.is_reviewed ASC, pf.created_at DESC\n" .
    "LIMIT 40"
  );
  $stFb->execute([$classId]);
  $feedbackList = $stFb->fetchAll(PDO::FETCH_ASSOC);
}

$meetingStatsClass = null;
$meetingStatsGrade = null;
$meetingStatsOverall = null;
$meetingTextsClass = [];
$meetingTextsAdmin = [];
$meetingTextScope = $role === 'admin' ? (string)($_GET['feedback_scope'] ?? 'class') : 'class';
$meetingFeedbackAnonymous = (bool)($parentCfg['meeting_feedback_anonymous'] ?? false);
$meetingFeedbackId = isset($_GET['meeting_feedback_id']) && $_GET['meeting_feedback_id'] !== ''
  ? (int)$_GET['meeting_feedback_id']
  : 0;
if ($meetingFeedbackEnabled) {
  if ($classId > 0) {
    $meetingStatsClass = meeting_feedback_stats(
      $pdo,
      $meetingFeedbackId > 0 ? 'class_id=? AND id=?' : 'class_id=?',
      $meetingFeedbackId > 0 ? [$classId, $meetingFeedbackId] : [$classId]
    );
    $meetingTextsClass = meeting_feedback_texts(
      $pdo,
      $meetingFeedbackId > 0
        ? "pmf.class_id=? AND pmf.id=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''"
        : "pmf.class_id=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''",
      $meetingFeedbackId > 0 ? [$classId, $meetingFeedbackId] : [$classId]
    );
  }
  if ($role === 'admin') {
    if ($gradeLevel !== null) {
      $meetingStatsGrade = meeting_feedback_stats(
        $pdo,
        $meetingFeedbackId > 0 ? 'grade_level=? AND id=?' : 'grade_level=?',
        $meetingFeedbackId > 0 ? [$gradeLevel, $meetingFeedbackId] : [$gradeLevel]
      );
    }
    $meetingStatsOverall = meeting_feedback_stats(
      $pdo,
      $meetingFeedbackId > 0 ? 'id=?' : '',
      $meetingFeedbackId > 0 ? [$meetingFeedbackId] : []
    );
    if ($meetingTextScope === 'grade' && $gradeLevel !== null) {
      $meetingTextsAdmin = meeting_feedback_texts(
        $pdo,
        $meetingFeedbackId > 0
          ? "pmf.grade_level=? AND pmf.id=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''"
          : "pmf.grade_level=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''",
        $meetingFeedbackId > 0 ? [$gradeLevel, $meetingFeedbackId] : [$gradeLevel]
      );
    } elseif ($meetingTextScope === 'all') {
      $meetingTextsAdmin = meeting_feedback_texts(
        $pdo,
        $meetingFeedbackId > 0
          ? "pmf.id=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''"
          : "pmf.message IS NOT NULL AND TRIM(pmf.message)<>''",
        $meetingFeedbackId > 0 ? [$meetingFeedbackId] : []
      );
    } else {
      $meetingTextScope = 'class';
      if ($classId > 0) {
        $meetingTextsAdmin = meeting_feedback_texts(
          $pdo,
          $meetingFeedbackId > 0
            ? "pmf.class_id=? AND pmf.id=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''"
            : "pmf.class_id=? AND pmf.message IS NOT NULL AND TRIM(pmf.message)<>''",
          $meetingFeedbackId > 0 ? [$classId, $meetingFeedbackId] : [$classId]
        );
      }
    }
  }
}

$pageTitle = t('teacher.parents.title', 'Elternmodus');
$missingTx = [
  'missing_title' => t('teacher.parents.missing_title', 'Fehlende Einträge gefunden'),
  'missing_search' => t('teacher.parents.missing_search', 'Suchen (Schüler oder Feld) …'),
  'expand_all' => t('teacher.parents.missing_expand_all', 'Alle ausklappen'),
  'collapse_all' => t('teacher.parents.missing_collapse_all', 'Alle einklappen'),
  'cancel' => t('teacher.parents.missing_cancel', 'Abbrechen'),
  'continue' => t('teacher.parents.missing_continue', 'Trotzdem freigeben'),
  'summary' => t('teacher.parents.missing_summary', 'Insgesamt {total} fehlende Einträge bei {students} Schüler(n).'),
  'check_failed' => t('teacher.parents.missing_check_failed', 'Prüfung fehlgeschlagen. Trotzdem fortfahren?'),
];
render_teacher_header($pageTitle);
$introText = $parentAutoApprove
  ? 'Elternmodus wird automatisch freigeschaltet und ist zeitlich begrenzt. Eltern sehen den ausgefüllten Bericht als nicht herunterladbare PDF-Vorschau und können moderierte Rückfragen oder eine Lesebestätigung senden.'
  : 'Elternmodus wird von dir angefragt, von der Admin bestätigt und ist zeitlich begrenzt. Eltern sehen den ausgefüllten Bericht als nicht herunterladbare PDF-Vorschau und können moderierte Rückfragen oder eine Lesebestätigung senden.';
?>
<div class="card">
  <h1><?=h($pageTitle)?></h1>
  <p class="muted" style="max-width:760px;">
    <?=h(t('teacher.parents.intro', $introText))?>
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert danger"><?php foreach ($errors as $e): ?><div><?=h($e)?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($alerts): ?>
  <div class="alert success"><?php foreach ($alerts as $a): ?><div><?=h($a)?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px;">
  <h2><?=h(t('teacher.parents.class_label', 'Klasse'))?></h2>
  <form method="get" class="row">
    <div>
      <select name="class_id" class="input" onchange="this.closest('form').submit();">
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$classId) ? 'selected' : '' ?>>
            <?=h((string)$c['school_year'])?> · <?=h(parent_class_display($c))?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<?php if ($signatureEnabled): ?>
  <div class="card" style="margin-bottom:14px;">
    <h2>Grafische Lehrkraft-Unterschrift</h2>
    <?php if (!$signatureConfigured): ?>
      <div class="alert warn">Die Signatur-Funktion ist nicht konfiguriert. Bitte SIGNATURE_MASTER_KEY setzen.</div>
    <?php else: ?>
      <p class="muted" style="max-width:760px;">
        Optional kann eine handschriftliche Unterschrift im Eltern-PDF angezeigt werden. Die Signatur wird als Vektordaten gespeichert und beim nächsten Export verwendet.
      </p>
      <label style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" id="signatureToggle" <?= $signatureActive ? 'checked' : '' ?>>
        <span>Grafische Unterschrift hinzufügen</span>
      </label>
      <div id="signaturePadWrap" style="<?= $signatureActive ? '' : 'display:none;' ?>">
        <div class="signature-pad">
          <canvas id="signatureCanvas" aria-label="Unterschrift"></canvas>
        </div>
        <div class="row" style="gap:8px; margin-top:8px; align-items:center;">
          <button class="btn secondary" type="button" id="signatureClearBtn">Löschen</button>
          <button class="btn primary" type="button" id="signatureApplyBtn">Übernehmen</button>
          <span id="signatureStatus" class="muted" style="font-size:12px;"></span>
        </div>
      </div>
      <div class="row" style="gap:8px; align-items:center; margin-top:8px;">
        <span id="signatureSavedPill" class="pill <?= $signatureActive ? 'green' : '' ?>" style="<?= $signatureActive ? '' : 'background:#f1f3f5;' ?>">
          <?= $signatureActive ? 'Aktive Signatur gespeichert' : 'Keine Signatur gespeichert' ?>
        </span>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="delete_signature">
          <button class="btn danger" type="submit" onclick="return confirm('Grafische Signatur wirklich löschen?');">Signatur löschen</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($classId > 0 && $students): ?>
<div class="card" style="margin-bottom:14px;">
    <h2>Klassen-Freischaltung</h2>
  <form method="post" class="row parent-request-form" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="request_all">
    <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
    <?php if ($signatureEnabled && $signatureConfigured): ?>
      <input type="hidden" name="signature_use" value="0" class="signature-use-input">
      <input type="hidden" name="signature_payload" value="" class="signature-payload-input">
    <?php endif; ?>
    <div>
      <label class="muted" style="font-size:12px;"><?=h(t('teacher.parents.bulk_days', 'Freischalten für'))?></label>
    </div>
    <div>
      <input type="number" name="valid_days" value="14" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 20px;font-size: 13px;">Tage</span>
      <button class="btn primary" type="submit"><?=h(t('teacher.parents.bulk_request', 'Alle Zugänge dieser Klasse anfragen'))?></button>
    </div>
  </form>
</div>
<?php endif; ?>

<details class="card" open id="teacherParentsApprovals" data-storage-key="teacherParentsApprovals">
  <summary style="cursor:pointer; font-weight:600; padding:6px 0;"><?=h(t('teacher.parents.table_title', 'Freigaben'))?></summary>
  <div style="margin-top:12px;">
    <?php if (!$students): ?>
      <p class="muted"><?=h(t('teacher.parents.no_students', 'Keine Schülerdaten gefunden.'))?></p>
    <?php else: ?>
      <div class="responsive-table">
        <table>
          <thead>
            <tr>
              <th><?=h(t('teacher.parents.student', 'Schüler'))?></th>
              <th><?=h(t('teacher.parents.status', 'Status'))?></th>
              <th><?=h(t('teacher.parents.expires', 'Gültig bis'))?></th>
              <th><?=h(t('teacher.parents.feedback', 'Feedback'))?></th>
              <th><?=h(t('teacher.parents.actions', 'Aktionen'))?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($students as $s):
            $linkId = (int)($s['parent_link_id'] ?? 0);
            $link = $linkId > 0 ? ($linkMap[$linkId] ?? null) : null;
            $status = $link['status'] ?? '-';
            $statusLabel = $status;
            if ($status === 'requested') $statusLabel = t('teacher.parents.status.requested', 'Angefragt');
            if ($status === 'approved') $statusLabel = t('teacher.parents.status.approved', 'Freigeschaltet');
            if ($status === 'revoked') $statusLabel = t('teacher.parents.status.revoked', 'Beendet');
            if ($status === 'expired') $statusLabel = t('teacher.parents.status.expired', 'Abgelaufen');
              $statusColor = $status;
              if ($status === 'requested') $statusColor = 'blue';
              if ($status === 'approved') $statusColor = 'green';
              if ($status === 'revoked') $statusColor = 'red';
              if ($status === 'expired') $statusColor = 'red';
            $expiresAt = $link['expires_at'] ?? null;
            $pending = $linkId && isset($feedbackCounts[$linkId]) ? (int)$feedbackCounts[$linkId]['pending_questions'] : 0;
            $totalQuestions = $linkId && isset($feedbackCounts[$linkId]) ? (int)$feedbackCounts[$linkId]['total_questions'] : 0;
            $totalAcks = $linkId && isset($feedbackCounts[$linkId]) ? (int)$feedbackCounts[$linkId]['total_acks'] : 0;
            $ackLatest = $linkId && isset($feedbackCounts[$linkId]) ? ($feedbackCounts[$linkId]['ack_latest'] ?? null) : null;
            $shareUrl = ($link && $status === 'approved') ? absolute_url('parent/portal.php?token=' . urlencode((string)$link['token'])) : '';
          ?>
            <tr>
              <td><strong><?=h((string)$s['first_name'] . ' ' . (string)$s['last_name'])?></strong></td>
              <td><span class="pill <?=h($statusColor)?>"><?=h($statusLabel)?></span></td>
              <td><?= render_local_datetime($expiresAt, 'd.m.Y H:i') ?></td>
              <td>
                <?php if ($totalAcks > 0): ?>
                  <span class="pill" style="background:#e6f4ea; border:1px solid var(--border);"<?= render_local_datetime_title_attr($ackLatest, 'd.m.Y H:i') ?>>
                    <?=h(t('teacher.parents.feedback.ack', 'Lesebestätigung'))?>
                  </span>
                <?php endif; ?>
                <?php if ($totalQuestions > 0): ?>
                  <span class="pill" style="background:<?= $pending>0 ? '#fff3cd' : '#e6f4ea' ?>; border:1px solid var(--border);">
                    <?=h($pending . ' / ' . $totalQuestions)?> <?=h(t('teacher.parents.feedback.pending', 'offen/gesamt'))?>
                  </span>
                <?php else: ?>
                  <?php if ($totalAcks === 0): ?>
                    <span class="muted">–</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($status === 'approved' && $shareUrl): ?>
                  <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <input type="text" readonly value="<?=h($shareUrl)?>" style="min-width:240px;">
                    <button class="btn secondary" type="button" onclick="copyToClipboard('<?=h($shareUrl)?>');">Kopieren</button>
                    <form method="post" style="margin:0; display:flex; gap:6px; align-items:center;">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="extend_link">
                      <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                      <input type="hidden" name="link_id" value="<?= (int)$linkId ?>">
                      <input type="number" name="extend_days" value="7" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 10px;font-size: 13px;">Tage</span>
                      <button class="btn secondary" type="submit"><?=h(t('teacher.parents.extend', 'Verlängern'))?></button>
                    </form>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="revoke_link">
                      <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                      <input type="hidden" name="link_id" value="<?= (int)$linkId ?>">
                      <button class="btn danger" type="submit" onclick="return confirm('<?=h(t('teacher.parents.revoke_confirm', 'Zugriff wirklich beenden?'))?>');"><?=h(t('teacher.parents.revoke', 'Beenden'))?></button>
                    </form>
                  </div>
                  <div class="muted" style="font-size:12px; margin-top:4px;">
                    <?=h(t('teacher.parents.note_readonly', 'Nur Vorschau, kein Download. Rückmeldungen sind moderiert.'))?>
                  </div>
                <?php elseif ($status === 'expired'): ?>
                  <form method="post" style="margin:0; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="extend_link">
                    <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="link_id" value="<?= (int)$linkId ?>">
                    <input type="number" name="extend_days" value="7" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 10px;font-size: 13px;">Tage</span>
                    <button class="btn secondary" type="submit"><?=h(t('teacher.parents.extend', 'Verlängern'))?></button>
                  </form>
                <?php elseif ($status === 'requested'): ?>
                  <span class="pill" style="background:#fff3cd; border:1px solid #ffe08a;"><?=h(t('teacher.parents.pending_admin', 'Wartet auf Admin-Freigabe'))?></span>
                <?php else: ?>
                  <form method="post" class="row parent-request-form" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="request_link">
                    <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                    <?php if ($signatureEnabled && $signatureConfigured): ?>
                      <input type="hidden" name="signature_use" value="0" class="signature-use-input">
                      <input type="hidden" name="signature_payload" value="" class="signature-payload-input">
                    <?php endif; ?>
                    <div>
                      <label class="muted" style="font-size:12px;"><?=h(t('teacher.parents.valid_days', 'Freischalten für'))?></label>
                    </div>
                    <div>
                      <input type="number" name="valid_days" value="14" min="1" max="120" style="width:90px;padding-right:35px; text-align:right;"></input><span style="margin-left: -40px;margin-right: 20px;font-size: 13px;">Tage</span>
                      <button class="btn primary" type="submit"><?=h(t('teacher.parents.request', 'Elternmodus anfragen'))?></button>
                    </div>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</details>

<details class="card" style="margin-top:14px;" open id="teacherParentsFeedback" data-storage-key="teacherParentsFeedback">
  <summary style="cursor:pointer; font-weight:600; padding:6px 0;"><?=h(t('teacher.parents.feedback_title', 'Eltern-Rückmeldung'))?></summary>
  <div style="margin-top:12px;">
    <p class="muted" style="margin-top:0;"><?=h(t('teacher.parents.feedback_hint', 'Rückmeldungen werden hier gesammelt. Markiere sie nach Sichtung als geprüft.'))?></p>
    <?php if (!$feedbackList): ?>
      <p class="muted"><?=h(t('teacher.parents.feedback_none', 'Noch keine Rückmeldungen.'))?></p>
    <?php else: ?>
      <div class="responsive-table">
        <table>
          <thead>
            <tr>
              <th><?=h(t('teacher.parents.feedback_student', 'Schüler'))?></th>
              <th style="width: 30%;"><?=h(t('teacher.parents.feedback_msg', 'Nachricht'))?></th>
              <th><?=h(t('teacher.parents.feedback_msg_date', 'Datum'))?></th>
              <th><?=h(t('teacher.parents.feedback_state', 'Status'))?></th>
              <th><?=h(t('teacher.parents.feedback_actions', 'Aktionen'))?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($feedbackList as $fb): ?>
              <?php
                $feedbackStudent = trim((string)($fb['first_name'] ?? '') . ' ' . (string)($fb['last_name'] ?? ''));
                $feedbackDate = '';
                if (!empty($fb['created_at'])) {
                  try {
                    $feedbackDate = (new DateTimeImmutable((string)$fb['created_at'], new DateTimeZone('UTC')))->format('d.m.Y H:i');
                  } catch (Throwable $e) {
                    $feedbackDate = '';
                  }
                }
                $feedbackEmails = array_values(array_unique(array_filter([
                  sanitize_email($fb['email_parent1'] ?? null),
                  sanitize_email($fb['email_parent2'] ?? null),
                ])));
                $replyLink = build_feedback_reply_mailto($feedbackEmails, $feedbackStudent, (string)($fb['message'] ?? ''), $feedbackDate);
              ?>
              <tr>
                <td><strong><?=h($feedbackStudent)?></strong></td>
                <td>
                  <?php if (trim((string)($fb['message'] ?? '')) === ''): ?>
                    <span class="muted">–</span>
                  <?php else: ?>
                    <?= nl2br(h((string)$fb['message'])) ?>
                  <?php endif; ?>
                </td>
                <td><?= render_local_datetime((string)$fb['created_at'], 'd.m.Y H:i') ?></td>
                <td>
                  <?php if ((int)($fb['is_reviewed'] ?? 0) === 1): ?>
                    <span class="pill green"><?=h(t('teacher.parents.reviewed', 'Geprüft'))?></span>
                  <?php else: ?>
                    <span class="pill yellow"><?=h(t('teacher.parents.pending_review', 'Offen'))?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex; flex-direction:column; gap:6px;">
                    <?php if ($replyLink): ?>
                      <a class="btn secondary" href="<?=h($replyLink)?>"><?=h(t('teacher.parents.reply_mail', 'Per Mail antworten'))?></a>
                    <?php else: ?>
                      <span class="muted">–</span>
                    <?php endif; ?>
                    <?php if ((int)($fb['is_reviewed'] ?? 0) === 0): ?>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="action" value="mark_feedback_reviewed">
                        <input type="hidden" name="feedback_id" value="<?= (int)$fb['id'] ?>">
                        <input type="hidden" name="student_id" value="<?= (int)($fb['student_id'] ?? 0) ?>">
                        <button class="btn secondary" type="submit"><?=h(t('teacher.parents.mark_reviewed', 'Als geprüft markieren'))?></button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</details>

<?php if ($meetingFeedbackEnabled): ?>
  <div class="card" style="margin-top:14px;" id="meetingFeedbackSection">
    <h2>Feedback zum Lernentwicklungsgespräch</h2>
    <p class="muted" style="margin-top:0;">Auswertung des Feedbackbogens für Eltern. Jede Rückmeldung kann nur einmal pro Kind abgegeben werden.</p>
    <div style="margin-top:8px;">
      <a class="btn secondary" href="<?=h(teacher_feedback_query_url(['meeting_feedback_id' => null]))?>" data-meeting-reset style="<?= $meetingFeedbackId > 0 ? '' : 'display:none;' ?>">Filter zurücksetzen</a>
    </div>

    <?php if ($classId <= 0): ?>
      <p class="muted">Keine Klasse ausgewählt.</p>
    <?php else: ?>
      <h3 style="margin-top:10px;">Klasse <?=h(trim($selectedClassYear . ' · ' . $selectedClassLabel))?></h3>
      <?php
        render_meeting_feedback_pies($meetingStatsClass ?? [], [
          'q1' => '1. Das Gespräch war verständlich und informativ.',
          'q2' => '2. Ich weiß jetzt, wie ich mein Kind zuhause weiter unterstützen kann.',
          'q3' => '3. Die besprochenen nächsten Schritte sind für mich nachvollziehbar.',
        ]);
      ?>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
      <hr style="margin:20px 0;">
      <h3 style="margin-top:0;">Klassenstufe</h3>
      <form method="get" class="row" style="gap:12px; align-items:center;">
        <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
        <label class="muted" style="font-size:12px;">Stufe auswählen</label>
        <select name="grade_level" class="input" onchange="this.form.submit();">
          <option value="">—</option>
          <?php foreach ($availableGrades as $g): ?>
            <option value="<?= (int)$g ?>" <?= ($gradeLevel !== null && (int)$gradeLevel === (int)$g) ? 'selected' : '' ?>><?=h((string)$g)?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($gradeLevel === null): ?>
        <p class="muted">Keine Klassenstufe ausgewählt.</p>
      <?php else: ?>
        <?php
          render_meeting_feedback_pies($meetingStatsGrade ?? [], [
            'q1' => '1. Das Gespräch war verständlich und informativ.',
            'q2' => '2. Ich weiß jetzt, wie ich mein Kind zuhause weiter unterstützen kann.',
            'q3' => '3. Die besprochenen nächsten Schritte sind für mich nachvollziehbar.',
          ]);
        ?>
      <?php endif; ?>

      <hr style="margin:20px 0;">
      <h3 style="margin-top:0;">Gesamt</h3>
      <?php
        render_meeting_feedback_pies($meetingStatsOverall ?? [], [
          'q1' => '1. Das Gespräch war verständlich und informativ.',
          'q2' => '2. Ich weiß jetzt, wie ich mein Kind zuhause weiter unterstützen kann.',
          'q3' => '3. Die besprochenen nächsten Schritte sind für mich nachvollziehbar.',
        ]);
      ?>

      <hr style="margin:20px 0;">
      <h3 style="margin-top:0;">Freitexte</h3>
      <form method="get" class="row" style="gap:12px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
        <?php if ($gradeLevel !== null): ?>
          <input type="hidden" name="grade_level" value="<?= (int)$gradeLevel ?>">
        <?php endif; ?>
        <label class="muted" style="font-size:12px;">Ansicht</label>
        <select name="feedback_scope" class="input" onchange="this.form.submit();">
          <option value="class" <?= $meetingTextScope === 'class' ? 'selected' : '' ?>>Klasse</option>
          <option value="grade" <?= $meetingTextScope === 'grade' ? 'selected' : '' ?>>Klassenstufe</option>
          <option value="all" <?= $meetingTextScope === 'all' ? 'selected' : '' ?>>Alle</option>
        </select>
      </form>
      <?php if (!$meetingTextsAdmin): ?>
        <p class="muted">Keine Freitext-Rückmeldungen vorhanden.</p>
      <?php else: ?>
        <div class="responsive-table">
          <table>
            <thead>
              <tr>
                <th>Schüler</th>
                <th>Klasse</th>
                <th>Nachricht</th>
                <th>Datum</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($meetingTextsAdmin as $row): ?>
                <?php
                  $isAnonymous = $meetingFeedbackAnonymous || ((int)($row['is_anonymous'] ?? 0) === 1);
                  $studentName = $isAnonymous
                    ? 'Anonym'
                    : trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                  $classLabel = parent_class_display($row);
                ?>
                <tr data-meeting-id="<?= (int)($row['id'] ?? 0) ?>">
                  <td><strong><?=h($studentName)?></strong></td>
                  <td><?=h((string)($row['school_year'] ?? ''))?> · <?=h($classLabel)?></td>
                  <td>
                    <a href="<?=h(teacher_feedback_query_url(['meeting_feedback_id' => (int)($row['id'] ?? 0)]))?>" data-meeting-filter="<?= (int)($row['id'] ?? 0) ?>" style="color:inherit; text-decoration:underline;">
                      <?= nl2br(h((string)($row['message'] ?? ''))) ?>
                    </a>
                  </td>
                  <td><?= render_local_datetime((string)($row['created_at'] ?? ''), 'd.m.Y H:i') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <hr style="margin:20px 0;">
    <h3 style="margin-top:0;">Freitexte (Klasse)</h3>
    <?php if (!$meetingTextsClass): ?>
      <p class="muted">Keine Freitext-Rückmeldungen vorhanden.</p>
    <?php else: ?>
      <div class="responsive-table">
        <table>
          <thead>
            <tr>
              <th>Schüler</th>
              <th>Nachricht</th>
              <th>Datum</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($meetingTextsClass as $row): ?>
              <?php
                $isAnonymous = $meetingFeedbackAnonymous || ((int)($row['is_anonymous'] ?? 0) === 1);
                $studentName = $isAnonymous
                  ? 'Anonym'
                  : trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
              ?>
              <tr data-meeting-id="<?= (int)($row['id'] ?? 0) ?>">
                <td><strong><?=h($studentName)?></strong></td>
                <td>
                  <a href="<?=h(teacher_feedback_query_url(['meeting_feedback_id' => (int)($row['id'] ?? 0)]))?>" data-meeting-filter="<?= (int)($row['id'] ?? 0) ?>" style="color:inherit; text-decoration:underline;">
                    <?= nl2br(h((string)($row['message'] ?? ''))) ?>
                  </a>
                </td>
                <td><?= render_local_datetime((string)($row['created_at'] ?? ''), 'd.m.Y H:i') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:18px; border:1px solid var(--border); background:linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);">
  <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
    <div>
      <h2 style="margin-bottom:6px;"><?=h(t('teacher.parents.mail_merge_title', 'Serienmail an Eltern'))?></h2>
      <p class="muted" style="max-width:820px; margin-top:0;">
        <?=h(t('teacher.parents.mail_merge_hint', 'Verwendbare Platzhalter: {{student_name}}, {{first_name}}, {{last_name}}, {{parent_link}}, {{link_expires_de}}, {{link_expires_us}}.'))?>
      </p>
    </div>
  </div>
  <form method="post" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="send_parent_email">

    <div style="grid-column: 1 / -1;">
      <label style="display:flex; gap:18px; flex-wrap:wrap; text-align: center;">
        <span><input type="radio" name="send_mode" value="class" <?= ($mailForm['mode'] ?? '') !== 'single' ? 'checked' : '' ?>> <?=h(t('teacher.parents.mail_mode_class', 'Ganze Klasse'))?></span>
        <span><input type="radio" name="send_mode" value="single" <?= ($mailForm['mode'] ?? '') === 'single' ? 'checked' : '' ?>> <?=h(t('teacher.parents.mail_mode_single', 'Einzeln'))?></span>
      </label>
    </div>

    <div style="display: none;">
      <label><?=h(t('teacher.parents.mail_class', 'Klasse'))?></label>
      <select name="mail_class_id" id="mailClassSelect">
        <?php if (count($classes) > 1): ?>
          <option value="all" <?= ($mailForm['class_id'] ?? '') === 'all' ? 'selected' : '' ?>><?=h(t('teacher.parents.mail_class_all', 'Alle Klassen'))?></option>
        <?php endif; ?>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)($mailForm['class_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>>
            <?=h((string)$c['school_year'])?> · <?=h(parent_class_display($c))?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="mailStudentWrap">
      <label><?=h(t('teacher.parents.mail_student', 'Schüler (einzeln)'))?></label>
      <select name="mail_student_id">
        <option value=""><?=h(t('teacher.parents.mail_student_choose', '— wählen —'))?></option>
        <?php foreach ($students as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)($mailForm['student_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
            <?=h((string)$s['first_name'] . ' ' . (string)$s['last_name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column: 1 / -1;">
      <label><?=h(t('teacher.parents.mail_subject', 'Betreff'))?></label>
      <input name="mail_subject" type="text" required value="<?=h((string)($mailForm['subject'] ?? ''))?>">
    </div>

    <div style="grid-column: 1 / -1;">
      <label><?=h(t('teacher.parents.mail_body', 'Nachricht'))?></label>
      <textarea name="mail_body" rows="15" style="width: 100%; max-width: 100%; min-width: 100%;" required><?=h((string)($mailForm['body'] ?? ''))?></textarea>
    </div>

    <div class="actions" style="justify-content:flex-start; grid-column: 1 / -1;">
      <button class="btn primary" type="submit"><?=h(t('teacher.parents.mail_send', 'Serienmail senden'))?></button>
    </div>
  </form>
</div>

<!-- missing fields modal -->
<div id="missingModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div style="max-width:920px; margin:6vh auto; background:#fff; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;">
    <div style="padding:16px 18px; border-bottom:1px solid #eee;">
      <div style="font-size:18px; font-weight:700;"><?=h($missingTx['missing_title'])?></div>
      <div class="muted" id="missingModalSummary" style="margin-top:4px;"></div>

      <div class="row" style="gap:10px; margin-top:12px; flex-wrap:wrap; align-items:center;">
        <input id="missingSearch" class="input" style="flex:1; min-width:260px; margin-bottom: 10px;" placeholder="<?=h($missingTx['missing_search'])?>">
        <button class="btn secondary" id="btnExpandAll" type="button"><?=h($missingTx['expand_all'])?></button>
        <button class="btn secondary" id="btnCollapseAll" type="button"><?=h($missingTx['collapse_all'])?></button>
      </div>
    </div>

    <div style="padding:14px 18px; max-height:58vh; overflow:auto;">
      <div id="missingModalList"></div>
    </div>

    <div style="padding:14px 18px; border-top:1px solid #eee; display:flex; gap:10px; justify-content:flex-end;">
      <button class="btn secondary" id="btnMissingCancel" type="button"><?=h($missingTx['cancel'])?></button>
      <button class="btn" id="btnMissingContinue" type="button"><?=h($missingTx['continue'])?></button>
    </div>
  </div>
</div>

  <?php if ($signatureEnabled && $signatureConfigured): ?>
  <style>
    .signature-pad {
      margin-top: 10px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      height: 170px;
      position: relative;
      overflow: hidden;
    }
    .signature-pad::before {
      content: '';
      position: absolute;
      left: 16px;
      right: 16px;
      bottom: 30px;
      height: 1px;
      background: #d4d9e2;
      pointer-events: none;
    }
    .signature-pad::after {
      content: 'X';
      position: absolute;
      left: 16px;
      bottom: 16px;
      color: #b0b6c2;
      font-size: 103px;
      font-weight: 300;
      pointer-events: none;
    }
    .signature-pad canvas {
      width: 100%;
      height: 100%;
      display: block;
      touch-action: none;
      cursor: crosshair;
    }
  </style>
  <?php endif; ?>

  <script>
  const EXPORT_API_URL = <?= json_encode(url('teacher/ajax/export_api.php')) ?>;
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const MISSING_TX = <?= json_encode($missingTx, JSON_UNESCAPED_UNICODE) ?>;
  const CURRENT_CLASS_ID = <?= (int)$classId ?>;

  document.querySelectorAll('details[data-storage-key]').forEach((el) => {
    const key = el.getAttribute('data-storage-key');
    if (key) {
      const stored = window.localStorage.getItem(key);
      if (stored === 'open') el.open = true;
      if (stored === 'closed') el.open = false;
    }
    el.addEventListener('toggle', () => {
      if (!key) return;
      window.localStorage.setItem(key, el.open ? 'open' : 'closed');
    });
  });

  (function(){
    const section = document.getElementById('meetingFeedbackSection');
    if (!section) return;
    const resetLink = section.querySelector('[data-meeting-reset]');

    function applyFilter(id) {
      section.querySelectorAll('tbody tr[data-meeting-id]').forEach((row) => {
        row.style.display = id && row.dataset.meetingId !== String(id) ? 'none' : '';
      });
      if (resetLink) {
        resetLink.style.display = id ? 'inline-flex' : 'none';
      }
      const url = new URL(window.location.href);
      if (id) {
        url.searchParams.set('meeting_feedback_id', String(id));
      } else {
        url.searchParams.delete('meeting_feedback_id');
      }
      window.history.replaceState({}, '', url.toString());
      //section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    section.querySelectorAll('[data-meeting-filter]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        applyFilter(link.dataset.meetingFilter || '');
      });
    });

    if (resetLink) {
      resetLink.addEventListener('click', (event) => {
        event.preventDefault();
        applyFilter('');
      });
    }

    const params = new URLSearchParams(window.location.search);
    if (params.has('meeting_feedback_id')) {
      applyFilter(params.get('meeting_feedback_id'));
    }
  })();

  const missingModal = document.getElementById('missingModal');
  const missingModalSummary = document.getElementById('missingModalSummary');
  const missingModalList = document.getElementById('missingModalList');
  const btnMissingCancel = document.getElementById('btnMissingCancel');
  const btnMissingContinue = document.getElementById('btnMissingContinue');
  const missingSearch = document.getElementById('missingSearch');
  const btnExpandAll = document.getElementById('btnExpandAll');
  const btnCollapseAll = document.getElementById('btnCollapseAll');

  let __missingRenderSource = null;

  function escapeHtml(s){
    return (s ?? '').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  async function apiFetch(payload){
    const resp = await fetch(EXPORT_API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': CSRF,
      },
      body: JSON.stringify(payload),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || data?.ok !== true) {
      const msg = data?.error || data?.message || 'Request failed';
      throw new Error(msg);
    }
    return data;
  }

  function buildMissingHtml(preview, q){
    const sum = preview?.warnings_summary || {};
    const byStudent = Array.isArray(sum.by_student) ? sum.by_student : [];
    const query = (q||'').toString().trim().toLowerCase();

    const parts = [];
    for (const s of byStudent) {
      const studentName = (s.student_name || ('ID ' + s.student_id)).toString();
      const fields = Array.isArray(s.missing_fields) ? s.missing_fields : [];
      if (!fields.length) continue;

      const filteredFields = !query ? fields : fields.filter(f => {
        const label = ((f.label || f.field_name || '') + '').toLowerCase();
        return studentName.toLowerCase().includes(query) || label.includes(query);
      });
      if (!filteredFields.length) continue;

      const showN = 60;
      const items = filteredFields.slice(0, showN).map(f => {
        const label = (f.label || f.field_name || '').toString();
        const req = Number(f.is_required || 0) === 1 ? ' (Pflicht)' : '';
        return `<li style="margin:2px 0;">${escapeHtml(label)}${req}</li>`;
      }).join('');

      const moreCount = filteredFields.length - showN;
      const more = moreCount > 0
        ? `<div class="muted" style="margin-top:6px;">… und ${moreCount} weitere</div>`
        : '';

      parts.push(`
        <details data-student="${escapeHtml(studentName)}" open>
          <summary style="cursor:pointer; padding:8px 10px; border:1px solid #eee; border-radius:10px; margin:8px 0; background:#fafafa;">
            <strong>${escapeHtml(studentName)}</strong>
            <span class="muted"> – ${filteredFields.length} fehlend</span>
          </summary>
          <div style="padding:4px 10px 10px 10px;">
            <ul style="margin:6px 0 0 18px; padding:0;">${items}</ul>
            ${more}
          </div>
        </details>
      `);
    }
    return parts.join('') || '<div class="muted">Keine passenden Treffer.</div>';
  }

  function formatMissingSummary(total, students){
    const tpl = MISSING_TX?.summary || 'Insgesamt {total} fehlende Einträge bei {students} Schüler(n).';
    return tpl.replace('{total}', String(total)).replace('{students}', String(students));
  }

  function openMissingModal(preview){
    return new Promise((resolve) => {
      const sum = preview?.warnings_summary || {};
      const total = Number(sum.total_missing || 0);
      const studentsWith = Number(sum.students_with_missing || 0);

      __missingRenderSource = preview;

      if (missingModalSummary) missingModalSummary.textContent = formatMissingSummary(total, studentsWith);

      if (missingSearch) missingSearch.value = '';
      if (missingModalList) missingModalList.innerHTML = buildMissingHtml(preview, '');

      function cleanup(){
        if (btnMissingCancel) btnMissingCancel.onclick = null;
        if (btnMissingContinue) btnMissingContinue.onclick = null;
        if (missingModal) missingModal.style.display = 'none';
      }

      if (btnMissingCancel) btnMissingCancel.onclick = () => { cleanup(); resolve(false); };
      if (btnMissingContinue) btnMissingContinue.onclick = () => { cleanup(); resolve(true); };

      if (missingModal) missingModal.style.display = '';
    });
  }

  if (missingSearch) {
    missingSearch.addEventListener('input', () => {
      if (!__missingRenderSource || !missingModalList) return;
      missingModalList.innerHTML = buildMissingHtml(__missingRenderSource, missingSearch.value);
    });
  }
  if (btnExpandAll) {
    btnExpandAll.addEventListener('click', () => {
      document.querySelectorAll('#missingModalList details').forEach(d => d.open = true);
    });
  }
  if (btnCollapseAll) {
    btnCollapseAll.addEventListener('click', () => {
      document.querySelectorAll('#missingModalList details').forEach(d => d.open = false);
    });
  }

  async function checkMissingBeforeSubmit(form){
    const action = form.querySelector('input[name="action"]')?.value || '';
    if (action !== 'request_all' && action !== 'request_link') return true;

    const classId = Number(form.querySelector('input[name="class_id"]')?.value || CURRENT_CLASS_ID || 0);
    if (!classId) return true;

    const studentId = Number(form.querySelector('input[name="student_id"]')?.value || 0);

    const payload = {
      action: 'preview',
      class_id: classId,
      only_submitted: 0,
      csrf_token: CSRF,
    };
    if (studentId > 0) payload.student_id = studentId;

    const preview = await apiFetch(payload);
    const sum = preview?.warnings_summary || {};
    const totalMissing = Number(sum.total_missing || 0);

    if (totalMissing > 0) {
      const proceed = await openMissingModal(preview);
      return Boolean(proceed);
    }
    return true;
  }

  document.querySelectorAll('form.parent-request-form').forEach(form => {
    form.addEventListener('submit', async (ev) => {
      if (form.dataset.missingCheck === '1') return;
      ev.preventDefault();
      try {
        const proceed = await checkMissingBeforeSubmit(form);
        if (!proceed) return;
      } catch (err) {
        const ok = window.confirm(MISSING_TX?.check_failed || 'Prüfung fehlgeschlagen. Trotzdem fortfahren?');
        if (!ok) return;
      }
      form.dataset.missingCheck = '1';
      form.submit();
    });
  });

  const mailModeRadios = document.querySelectorAll('input[name="send_mode"]');
  const mailStudentWrap = document.getElementById('mailStudentWrap');
  function updateMailMode(){
    const mode = Array.from(mailModeRadios).find(r => r.checked)?.value || 'class';
    if (mailStudentWrap) {
      mailStudentWrap.style.display = mode === 'single' ? 'block' : 'none';
    }
  }
  mailModeRadios.forEach(radio => radio.addEventListener('change', updateMailMode));
  updateMailMode();

  async function copyToClipboard(text){
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
        const ok = copyHttp(text);
        if(ok) {
        } else {
        }
    }
  }
  
  function copyHttp(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);

    ta.focus();
    ta.select();

    try {
      document.execCommand('copy');
      return true;
    } catch {
      return false;
    } finally {
      document.body.removeChild(ta);
    }
  }

  <?php if ($signatureEnabled && $signatureConfigured): ?>
  (function(){
    const toggle = document.getElementById('signatureToggle');
    const padWrap = document.getElementById('signaturePadWrap');
    const canvas = document.getElementById('signatureCanvas');
    const clearBtn = document.getElementById('signatureClearBtn');
    const applyBtn = document.getElementById('signatureApplyBtn');
    const statusEl = document.getElementById('signatureStatus');
    const savedPill = document.getElementById('signatureSavedPill');
    const useInputs = Array.from(document.querySelectorAll('.signature-use-input'));
    const payloadInputs = Array.from(document.querySelectorAll('.signature-payload-input'));
    const hasActiveSignature = <?= $signatureActive ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    if (!toggle || !canvas) return;

    if (hasActiveSignature) {
      toggle.checked = true;
      if (padWrap) padWrap.style.display = '';
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const state = { strokes: [], current: null, drawing: false };

    function setStatus(msg){
      if (statusEl) statusEl.textContent = msg || '';
    }

    function setFormPayload(payload){
      const useVal = toggle.checked ? '1' : '0';
      useInputs.forEach(input => input.value = useVal);
      payloadInputs.forEach(input => input.value = payload || '');
    }

    function setSavedState(saved){
      if (!savedPill) return;
      savedPill.textContent = saved ? 'Aktive Signatur gespeichert' : 'Keine Signatur gespeichert';
      if (saved) {
        savedPill.classList.add('green');
        savedPill.style.background = '';
      } else {
        savedPill.classList.remove('green');
        savedPill.style.background = '#f1f3f5';
      }
    }

    function resizeCanvas(){
      const rect = canvas.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const ratio = window.devicePixelRatio || 1;
      canvas.width = rect.width * ratio;
      canvas.height = rect.height * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#0b3d91';
      redraw();
    }

    function redraw(){
        const rect = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);

        if (!state.strokes.length) return;

        // etwas Innenabstand, damit nichts abgeschnitten wird
        const pad = Math.max(8, rect.height * 0.08);
        const availW = Math.max(1, rect.width - 2 * pad);
        const availH = Math.max(1, rect.height - 2 * pad);

        // Bounds in "width-units" berechnen
        let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
        for (const stroke of state.strokes) {
          if (!Array.isArray(stroke)) continue;
          for (const pt of stroke) {
            if (!pt || typeof pt.x !== 'number' || typeof pt.y !== 'number') continue;
            if (pt.x < minX) minX = pt.x;
            if (pt.x > maxX) maxX = pt.x;
            if (pt.y < minY) minY = pt.y;
            if (pt.y > maxY) maxY = pt.y;
          }
        }
        if (!isFinite(minX) || !isFinite(minY) || !isFinite(maxX) || !isFinite(maxY)) return;

        const bw = Math.max(0.001, maxX - minX); // width-units
        const bh = Math.max(0.001, maxY - minY); // width-units

        // Umrechnung der Bounds in Pixel (weil width-units * rect.width)
        const rawWpx = bw * rect.width;
        const rawHpx = bh * rect.width;

        // Proportionaler Fit in availW/availH
        const scale = Math.max(0.0001, Math.min(availW / rawWpx, availH / rawHpx));

        const drawW = rawWpx * scale;
        const drawH = rawHpx * scale;

        // Zentriert innerhalb des Canvas (mit Padding)
        const originX = pad + (availW - drawW) / 2 - (minX * rect.width * scale);
        const originY = pad + (availH - drawH) / 2 - (minY * rect.width * scale);

        for (const stroke of state.strokes) {
          if (!stroke.length) continue;
          ctx.beginPath();
          stroke.forEach((pt, idx) => {
            const x = originX + (pt.x * rect.width * scale);
            const y = originY + (pt.y * rect.width * scale);
            if (idx === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
          });
          ctx.stroke();
        }
      }

    function pointFromEvent(e){
        const rect = canvas.getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width;

        const pad = Math.max(6, rect.height * 0.06);

        // x/y in width-units, aber mit Innenrand
        const xWU = (e.clientX - rect.left) / rect.width;
        const yWU = (e.clientY - rect.top)  / rect.width;

        // Innenrand in width-units umrechnen
        const padWU = pad / rect.width;

        const maxX = 1 - padWU;
        const maxY = (rect.height / rect.width) - padWU;

        return {
          x: Math.max(padWU, Math.min(maxX, xWU)),
          y: Math.max(padWU, Math.min(maxY, yWU)),
        };
      }

    function startStroke(e){
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      canvas.setPointerCapture(e.pointerId);
      state.drawing = true;
      state.current = [];
      const pt = pointFromEvent(e);
      state.current.push(pt);
      state.strokes.push(state.current);
      redraw();
    }

    function moveStroke(e){
      if (!state.drawing || !state.current) return;
      e.preventDefault();
      const pt = pointFromEvent(e);
      state.current.push(pt);
      redraw();
    }

    function endStroke(e){
      if (!state.drawing) return;
      e.preventDefault();
      state.drawing = false;
      state.current = null;
    }

    function clearPad(){
      state.strokes = [];
      state.current = null;
      redraw();
      setStatus('Signatur gelöscht.');
      setFormPayload('');
    }

    async function applyPad(){
      if (!state.strokes.length) {
        setStatus('Bitte zuerst unterschreiben.');
        return;
      }
      let rect = canvas.getBoundingClientRect();
      if (!rect.width || !rect.height) {
        resizeCanvas();
        rect = canvas.getBoundingClientRect();
      }
      if (!rect.width || !rect.height) {
        setStatus('Signaturfeld ist nicht bereit.');
        return;
      }
      let ratio = rect.height > 0 ? (rect.width / rect.height) : null;
        if (typeof ratio === 'number') {
          // breitere Range zulassen (dein Canvas kann z.B. 2232/336 = 6.64 haben)
          if (!Number.isFinite(ratio) || ratio <= 0 || ratio < 0.2 || ratio > 50) {
            ratio = null;
          }
        }
        const payload = JSON.stringify({ v: 2, strokes: state.strokes, ratio });
      const body = new URLSearchParams();
      body.set('csrf_token', csrfToken);
      body.set('action', 'save_signature');
      body.set('signature_payload', payload);
      try {
        const resp = await fetch(window.location.href, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body,
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) {
          const errMsg = data?.error || data?.message || 'Speichern fehlgeschlagen.';
          throw new Error(errMsg);
        }
        if (!data || data.ok !== true) throw new Error(data?.error || 'Signatur konnte nicht gespeichert werden.');
        setFormPayload('');
        setStatus('Signatur gespeichert.');
        setSavedState(true);
      } catch (e) {
        setStatus(e?.message || 'Signatur konnte nicht gespeichert werden.');
      }
    }

    async function loadSavedSignature(){
      if (!hasActiveSignature) return;
      const body = new URLSearchParams();
      body.set('csrf_token', csrfToken);
      body.set('action', 'load_signature');
      try {
        const resp = await fetch(window.location.href, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body,
        });
        if (!resp.ok) return;
        const data = await resp.json().catch(() => null);
        const payload = data?.payload;
        const strokes = payload?.strokes;
        if (Array.isArray(strokes) && strokes.length) {
          const v = Number(payload?.v || 1);
          const savedRatio = (typeof payload?.ratio === 'number' && isFinite(payload.ratio) && payload.ratio > 0) ? payload.ratio : null;

          if (v >= 2) {
            // neue Daten sind bereits in width-units
            state.strokes = strokes;
          } else {
            // alte Daten (y relativ zur Höhe) -> in width-units konvertieren
            // y_new = y_old / ratio
            const rect = canvas.getBoundingClientRect();
            const ratio = savedRatio || (rect.height > 0 ? (rect.width / rect.height) : 1);

            state.strokes = strokes.map(stroke => {
              if (!Array.isArray(stroke)) return [];
              return stroke.map(pt => {
                const x = typeof pt?.x === 'number' ? pt.x : 0;
                const yOld = typeof pt?.y === 'number' ? pt.y : 0;
                const yNew = ratio ? (yOld / ratio) : yOld; // convert to width-units
                return { x, y: yNew };
              });
            });
          }

          resizeCanvas();
        }
      } catch (e) {}
    }

    toggle.addEventListener('change', () => {
      if (toggle.checked) {
        padWrap.style.display = '';
        resizeCanvas();
        setStatus(hasActiveSignature ? 'Vorhandene Signatur bleibt aktiv (neu aufnehmen überschreibt).' : '');
      } else {
        padWrap.style.display = 'none';
        setFormPayload('');
        setStatus(hasActiveSignature ? 'Aktive Signatur bleibt gespeichert.' : '');
      }
    });

    canvas.addEventListener('pointerdown', startStroke);
    canvas.addEventListener('pointermove', moveStroke);
    canvas.addEventListener('pointerup', endStroke);
    canvas.addEventListener('pointercancel', endStroke);

    clearBtn?.addEventListener('click', clearPad);
    applyBtn?.addEventListener('click', applyPad);

    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();
    loadSavedSignature();
    setSavedState(hasActiveSignature);
    setFormPayload('');
  })();
  <?php endif; ?>
  </script>

<?php render_teacher_footer(); ?>
