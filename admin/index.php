<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$progress = [
  'submitted' => 0,
  'locked' => 0,
  'total' => 0,
  'avg_minutes' => null,
  'recent_delegations' => 0,
];

function format_minutes_admin(?float $minutes): string {
  if ($minutes === null) return '–';
  $m = (int)round($minutes);
  if ($m <= 0) return '<1 min';
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) {
    return $h . 'h' . ($r > 0 ? ' ' . $r . 'min' : '');
  }
  return $m . ' min';
}

try {
  $st = $pdo->query(
    "SELECT status, COUNT(*) AS c
       FROM report_instances
      WHERE period_label='Standard'
      GROUP BY status"
  );
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $status = (string)($r['status'] ?? '');
    $count = (int)($r['c'] ?? 0);
    if ($status === 'submitted') $progress['submitted'] = $count;
    if ($status === 'locked') $progress['locked'] = $count;
    $progress['total'] += $count;
  }

  $avg = $pdo->query(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) AS avg_minutes
       FROM report_instances
      WHERE period_label='Standard'"
  );
  $avgVal = $avg->fetchColumn();
  $progress['avg_minutes'] = ($avgVal !== false) ? (float)$avgVal : null;

  $del = $pdo->query(
    "SELECT COUNT(*)
       FROM class_group_delegations
      WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
  );
  $progress['recent_delegations'] = (int)($del->fetchColumn() ?: 0);
} catch (Throwable $e) {
  // ignore
}

$u = current_user();
render_admin_header('Admin – Dashboard');
?>
<div class="card">
    <h1>Dashboard</h1>
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
  </div>
</div>

<div class="card">
  <h2><?=h(t('admin.progress.headline', 'Gesamt-Bearbeitungsstand'))?></h2>
  <p class="muted"><?=h(t('admin.progress.description', 'Überblick über alle Berichte und Delegationen.'))?></p>

  <?php if ($progress['total'] === 0): ?>
    <div class="alert"><?=h(t('admin.progress.empty', 'Keine Daten verfügbar.'))?></div>
  <?php else: ?>
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-value"><?=h((string)$progress['submitted'])?></div>
        <div class="stat-label"><?=h(t('admin.progress.students_done', 'fertige Schülereingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)$progress['locked'])?></div>
        <div class="stat-label"><?=h(t('admin.progress.teacher_done', 'abgeschlossene Lehrkraft-Eingaben'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h(format_minutes_admin($progress['avg_minutes']))?></div>
        <div class="stat-label"><?=h(t('admin.progress.avg_time', 'Ø Bearbeitungszeit'))?></div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?=h((string)$progress['recent_delegations'])?></div>
        <div class="stat-label"><?=h(t('admin.progress.delegation_feedback', 'neue Delegations-Rückmeldungen'))?></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Verwaltung</h2>
  <div class="nav-grid">
    <a class="nav-tile primary" href="<?=h(url('admin/classes.php'))?>">
      <div class="nav-title">Klassen</div>
      <p class="nav-desc">Klassen strukturieren, Schuljahre pflegen und Zuweisungen erledigen.</p>
    </a>
    <a class="nav-tile primary" href="<?=h(url('admin/users.php'))?>">
      <div class="nav-title">Nutzer</div>
      <p class="nav-desc">Accounts verwalten, Rollen vergeben und Zugänge aktuell halten.</p>
    </a>
    <a class="nav-tile primary" href="<?=h(url('admin/students.php'))?>">
      <div class="nav-title">Schüler</div>
      <p class="nav-desc">Schüler importieren oder erfassen und Klassen zuordnen.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/student_fields.php'))?>">
      <div class="nav-title">Schüler-Felder</div>
      <p class="nav-desc">Zusätzliche Felder anlegen, Labels pflegen und Standardwerte definieren.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/settings.php'))?>">
      <div class="nav-title">Branding & Einstellungen</div>
      <p class="nav-desc">Logo, Farben, Sprache und weitere Grundeinstellungen anpassen.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/log.php'))?>">
      <div class="nav-title">Audit-Log</div>
      <p class="nav-desc">Protokoll aller Datenbank-Änderungen.</p>
    </a>
  </div>
</div>

<div class="card">
  <h2>Vorlagen & Exporte</h2>
  <div class="nav-grid">
    <a class="nav-tile" href="<?=h(url('admin/templates.php'))?>">
      <div class="nav-title">Templates</div>
      <p class="nav-desc">PDF-Vorlagen hochladen, strukturieren und für Eingaben vorbereiten.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/icon_library.php'))?>">
      <div class="nav-title">Optionen & Skalen</div>
      <p class="nav-desc">Antwortoptionen, Skalen und Auswahllisten verwalten.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/text_snippets.php'))?>">
      <div class="nav-title">Textbausteine</div>
      <p class="nav-desc">Textbausteine für die Eingabe in freien Eingabefeldern der Lernentwicklungsberichte verwalten.</p>
    </a>
    <a class="nav-tile" href="<?=h(url('admin/export.php'))?>">
      <div class="nav-title">PDF-Export</div>
      <p class="nav-desc">Reports als PDF bündeln und für den Versand herunterladen.</p>
    </a>
  </div>
  <p class="muted">Empfohlene Reihenfolge: Klassen anlegen & zuordnen → Templates hochladen → Felder auslesen → Schüler importieren/erfassen → Reports pro Kind erzeugen.</p>
</div>
<?php render_admin_footer(); ?>
