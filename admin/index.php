<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$u = current_user();
render_admin_header('Admin – Dashboard');
?>
<div class="card">
  <div class="row-actions">
    <span class="pill"><?=h((string)$u['display_name'])?> · <?=h((string)$u['role'])?></span>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<div class="card">
  <h2>Verwaltung</h2>
  <div class="actions">
    <a class="btn primary" href="<?=h(url('admin/classes.php'))?>">Klassen</a>
    <a class="btn primary" href="<?=h(url('admin/users.php'))?>">Nutzer</a>
    <a class="btn primary" href="<?=h(url('admin/students.php'))?>">Schüler</a>
    <a class="btn secondary" href="<?=h(url('admin/settings.php'))?>">Settings / Branding</a>
    <a class="btn secondary" href="<?=h(url('admin/templates.php'))?>">Templates (PDF Upload & Felder auslesen)</a>
    <a class="btn secondary" href="<?=h(url('admin/export.php'))?>">PDF-Export</a>
  </div>
  <p class="muted">Empfohlene Reihenfolge: Klassen anlegen & zuordnen → Templates hochladen → Felder auslesen → Schüler importieren/erfassen → Reports pro Kind erzeugen.</p>
</div>
<?php render_admin_footer(); ?>
