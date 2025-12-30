<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

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
