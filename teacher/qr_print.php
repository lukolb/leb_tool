<?php
// teacher/qr_print.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_teacher();

$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0) { http_response_code(400); echo "class_id fehlt"; exit; }
if (!user_can_access_class($pdo, $userId, $classId)) { http_response_code(403); echo "403 Forbidden"; exit; }

$cls = $pdo->prepare("SELECT id, school_year, grade_level, label, name FROM classes WHERE id=? LIMIT 1");
$cls->execute([$classId]);
$class = $cls->fetch(PDO::FETCH_ASSOC);
if (!$class) { http_response_code(404); echo "Klasse nicht gefunden"; exit; }

$st = $pdo->prepare(
  "SELECT id, first_name, last_name, login_code, qr_token
   FROM students
   WHERE class_id=? AND is_active=1
   ORDER BY last_name ASC, first_name ASC"
);
$st->execute([$classId]);
$students = $st->fetchAll(PDO::FETCH_ASSOC);

function class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)$c['id']));
}

$title = 'LEB-Tool - QR-Codes – ' . (string)$class['school_year'] . ' · ' . class_display($class);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
    <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>
    @media print {
      .noprint { display:none !important; }
      body, body.page { background:#fff; }
      .card { box-shadow:none; border:none; }
    }
    .grid-cards {
      display:grid;
      grid-template-columns: repeat(2, 1fr);
      gap:12px;
    }
    .qr-card {
      border:1px solid var(--border);
      border-radius:14px;
      padding:12px;
      display:flex;
      gap:12px;
      align-items:center;
      min-height:140px;
      page-break-inside: avoid;
    }
    .qr-box { width:128px; height:128px; display:flex; align-items:center; justify-content:center; background:#fff; border-radius:12px; border:1px dashed var(--border); }
    .qr-meta { flex:1; }
    .qr-name { font-weight:700; font-size:18px; }
    .qr-small { color:var(--muted); font-size:12px; margin-top:4px; }
    .qr-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:18px; letter-spacing:1px; margin-top:8px; }
  </style>
</head>
<body class="page">
  <div class="container">
    <div class="card noprint">
      <div class="row-actions">
        <a class="btn secondary" href="<?=h(url('teacher/students.php?class_id='.(int)$classId))?>">← zurück</a>
        <button class="btn primary" onclick="window.print()">Drucken</button>
      </div>
      <h1 style="margin-top:0;"><?=h($title)?></h1>
      <div class="alert">
        <strong>Wichtig:</strong> Falls bei einem Kind kein QR angezeigt wird, erst in der Schülerverwaltung „Login-Codes/QR erstellen“ klicken.
      </div>
    </div>

    <div class="grid-cards">
      <?php foreach ($students as $s):
        $token = (string)($s['qr_token'] ?? '');
        $loginCode = (string)($s['login_code'] ?? '');
        $loginUrl = $token ? absolute_url('student/login.php?token=' . urlencode($token)) : '';
      ?>
      <div class="qr-card">
        <div class="qr-box" data-url="<?=h($loginUrl)?>"></div>
        <div class="qr-meta">
          <div class="qr-name"><?=h((string)$s['first_name'])?> <?=h((string)$s['last_name'])?></div>
          <div class="qr-small">Schuljahr <?=h((string)$class['school_year'])?> · Klasse <?=h(class_display($class))?></div>
          <div class="qr-small">Alternative ohne Kamera:</div>
          <div class="qr-code"><?=h($loginCode ?: '—')?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <script>
    (function(){
      const boxes = document.querySelectorAll('.qr-box');
      boxes.forEach(box => {
        const url = box.getAttribute('data-url');
        if (!url) {
          box.textContent = '—';
          return;
        }
        try {
          new QRCode(box, { text: url, width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M });
        } catch (e) {
          box.textContent = 'QR Fehler';
        }
      });
    })();
  </script>
</body>
</html>
