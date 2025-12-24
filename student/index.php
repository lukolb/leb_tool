<?php
// student/index.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_student();

$pdo = db();
$studentId = (int)($_SESSION['student']['id'] ?? 0);

$st = $pdo->prepare(
  "SELECT s.id, s.first_name, s.last_name, c.school_year, c.grade_level, c.label, c.name AS class_name
   FROM students s
   LEFT JOIN classes c ON c.id=s.class_id
   WHERE s.id=? LIMIT 1"
);
$st->execute([$studentId]);
$s = $st->fetch(PDO::FETCH_ASSOC);

if (!$s) {
  // Session invalid
  unset($_SESSION['student']);
  redirect('student/login.php');
}

function class_display(array $row): string {
  $grade = $row['grade_level'] !== null ? (int)$row['grade_level'] : null;
  $label = (string)($row['label'] ?? '');
  if ($grade !== null && $label !== '') return (string)$grade . $label;
  return (string)($row['class_name'] ?? '—');
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Schülerbereich</title>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <div>
        <div class="brand-title">Schülerbereich</div>
        <div class="brand-subtitle"><?=h((string)$s['school_year'])?> · <?=h(class_display($s))?></div>
      </div>
    </div>
    <div class="row-actions">
      <a class="btn secondary" href="<?=h(url('student/logout.php'))?>">Logout</a>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1>Hallo <?=h((string)$s['first_name'])?>!</h1>
      <p class="muted">
        Hier kommt als nächstes die Eingabe der Schüler-Felder (z.B. Selbsteinschätzung), basierend auf den gemappten Formularfeldern.
      </p>
      <div class="alert">
        Status: Login/QR funktioniert. Formular-Eingabe folgt als nächster Schritt.
      </div>
    </div>
  </div>
</body>
</html>
