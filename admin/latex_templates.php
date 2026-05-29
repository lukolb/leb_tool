<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/latex_layout_templates.php';
require_once __DIR__ . '/../shared/latex_template_packages.php';
require_admin();

$pdo = db();
ensure_default_latex_layout_template($pdo);
ensure_latex_layout_storage_dir();
ensure_latex_template_packages_table($pdo);
ensure_latex_template_package_storage_dir();
$msg = '';
$err = '';
$ignoredUploadFiles = [];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $packageAction = (string)($_POST['latex_package_action'] ?? '');
    $action = (string)($_POST['layout_action'] ?? '');
    try {
        if ($packageAction === 'upload') {
            $result = latex_template_package_import_zip($pdo, $_FILES['latex_package_zip'] ?? [], [
                'name' => $_POST['package_name'] ?? '',
                'description' => $_POST['package_description'] ?? '',
                'main_file' => $_POST['package_main_file'] ?? 'main.tex',
                'is_active' => isset($_POST['package_is_active']),
                'is_default' => isset($_POST['package_is_default']),
            ]);
            $ignoredUploadFiles = is_array($result['manifest']['ignored_files'] ?? null) ? $result['manifest']['ignored_files'] : [];
            $ignoredCount = (int)($result['manifest']['ignored_count'] ?? count($ignoredUploadFiles));
            $msg = 'LaTeX-Vorlagenpaket importiert.';
            if ($ignoredCount > 0) {
                $msg .= ' ' . $ignoredCount . ' nicht unterstützte Dateien wurden ignoriert.';
            }
            $warnings = array_values(array_filter((array)($result['warnings'] ?? []), static fn($warning) => !str_contains((string)$warning, 'nicht unterstützte Dateien wurden ignoriert')));
            if ($warnings) $msg .= ' Hinweise: ' . implode(' ', $warnings);
            $msg .= ' Allgemeine LaTeX-Support-Dateien werden beim Build automatisch ergänzt.';
        } elseif ($packageAction === 'toggle_active') {
            $id = (int)($_POST['package_id'] ?? 0);
            $pkg = find_latex_template_package($pdo, $id, false);
            if (!$pkg) throw new RuntimeException('LaTeX-Paket nicht gefunden.');
            if ((int)($pkg['is_default'] ?? 0) === 1) throw new RuntimeException('Standardpaket kann nicht deaktiviert werden.');
            $newStatus = ((string)($pkg['status'] ?? '') === 'active') ? 'inactive' : 'active';
            $pdo->prepare('UPDATE latex_template_packages SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $id]);
            $msg = 'LaTeX-Paket-Status aktualisiert.';
        } elseif ($packageAction === 'set_default') {
            $id = (int)($_POST['package_id'] ?? 0);
            $pkg = find_latex_template_package($pdo, $id, true);
            if (!$pkg) throw new RuntimeException('Aktives LaTeX-Paket nicht gefunden.');
            $pdo->beginTransaction();
            $pdo->exec('UPDATE latex_template_packages SET is_default=0 WHERE deleted_at IS NULL');
            $pdo->prepare('UPDATE latex_template_packages SET is_default=1, updated_at=NOW() WHERE id=?')->execute([$id]);
            $pdo->commit();
            $msg = 'LaTeX-Paket als Standard gesetzt.';
        } elseif ($packageAction === 'delete') {
            $id = (int)($_POST['package_id'] ?? 0);
            $result = delete_latex_template_package($pdo, $id);
            if (!empty($result['was_default'])) {
                $msg = !empty($result['new_default_id'])
                    ? 'Standard-LaTeX-Vorlagenpaket wurde gelöscht. Ein anderes Paket wurde als Standard gesetzt.'
                    : 'Standard-LaTeX-Vorlagenpaket wurde gelöscht. Es wird wieder die Systemvorlage verwendet.';
            } else {
                $msg = 'LaTeX-Paket gelöscht.';
            }
        } elseif ($action === 'set_default') {
            $id = (int)($_POST['template_id'] ?? 0);
            $tpl = find_active_latex_layout_template($pdo, $id);
            if (!$tpl) throw new RuntimeException('Vorlage nicht gefunden oder inaktiv.');
            $pdo->beginTransaction();
            $pdo->exec("UPDATE latex_layout_templates SET is_default=0");
            $st = $pdo->prepare("UPDATE latex_layout_templates SET is_default=1 WHERE id=?");
            $st->execute([$id]);
            $countDefault = (int)$pdo->query("SELECT COUNT(*) FROM latex_layout_templates WHERE is_default=1")->fetchColumn();
            if ($countDefault !== 1) { throw new RuntimeException('Standardvorlage konnte nicht eindeutig gesetzt werden.'); }
            $pdo->commit();
            $msg = 'Standardvorlage gesetzt.';
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['template_id'] ?? 0);
            $stChk = $pdo->prepare("SELECT id, is_default FROM latex_layout_templates WHERE id=? LIMIT 1");
            $stChk->execute([$id]);
            $cur = $stChk->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$cur) throw new RuntimeException('Vorlage nicht gefunden.');
            if ((int)($cur['is_default'] ?? 0) === 1) throw new RuntimeException('Standardvorlage kann nicht deaktiviert werden.');
            $st = $pdo->prepare("UPDATE latex_layout_templates SET is_active = IF(is_active=1,0,1) WHERE id=?");
            $st->execute([$id]);
            $msg = 'Aktiv-Status aktualisiert.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['template_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM latex_layout_templates WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $tpl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$tpl) throw new RuntimeException('Vorlage nicht gefunden.');
            $wasDefault = ((int)($tpl['is_default'] ?? 0) === 1);

            $filePathRel = (string)($tpl['file_path'] ?? '');
            $fileAbs = latex_layout_absolute_path($filePathRel);
            $uploadRoot = realpath(latex_layout_storage_dir()) ?: latex_layout_storage_dir();
            $fileReal = realpath($fileAbs);
            $canDeleteFile = false;
            if ($fileReal !== false) {
                $prefix = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/';
                $fileNorm = str_replace('\\', '/', $fileReal);
                $canDeleteFile = strncmp($fileNorm, $prefix, strlen($prefix)) === 0;
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM latex_layout_templates WHERE id=?")->execute([$id]);
            $newDefaultId = $wasDefault ? ensure_default_layout_template_after_delete($pdo) : null;
            $pdo->commit();

            if ($canDeleteFile && is_file($fileAbs)) {
                @unlink($fileAbs);
            }
            if ($wasDefault) {
                $msg = $newDefaultId !== null
                    ? 'Standard-Layoutvorlage wurde gelöscht. Eine andere Vorlage wurde als Standard gesetzt.'
                    : 'Standard-Layoutvorlage wurde gelöscht. Es wird wieder die Systemvorlage verwendet.';
            } else {
                $msg = 'Layoutvorlage gelöscht.';
            }
        } elseif ($action === 'upload') {
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $keyName = trim((string)($_POST['key_name'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $setDefault = isset($_POST['is_default']) ? 1 : 0;
            if ($displayName === '') throw new RuntimeException('Anzeigename darf nicht leer sein.');
            if (!preg_match('/^[a-z0-9_-]+$/', $keyName)) throw new RuntimeException('Key enthält ungültige Zeichen.');
            if (!isset($_FILES['layout_file']) || (int)$_FILES['layout_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload fehlgeschlagen.');
            $orig = (string)($_FILES['layout_file']['name'] ?? '');
            if (basename($orig) !== $orig) throw new RuntimeException('Ungültiger Dateiname.');
            if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'tex') throw new RuntimeException('Nur .tex erlaubt.');
            $tmp = (string)$_FILES['layout_file']['tmp_name'];
            $content = (string)file_get_contents($tmp);
            if ($content === '' || stripos($content, '<?php') !== false) throw new RuntimeException('Ungültiger Dateiinhalt.');

            $st = $pdo->prepare("INSERT INTO latex_layout_templates (key_name, display_name, file_path, is_default, is_active, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), is_active=VALUES(is_active), updated_at=NOW()");
            $st->execute([$keyName, $displayName, '', 0, $isActive]);
            $id = (int)$pdo->query("SELECT id FROM latex_layout_templates WHERE key_name=" . $pdo->quote($keyName))->fetchColumn();
            $uploadsRel = trim((string)(app_config()['app']['uploads_dir'] ?? 'uploads'), '/\\');
            if ($uploadsRel === '') { $uploadsRel = 'uploads'; }
            $stored = $uploadsRel . '/latex_layouts/layout_template_' . $id . '.tex';
            $abs = latex_layout_absolute_path($stored);
            if (!move_uploaded_file($tmp, $abs)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');
            $st2 = $pdo->prepare("UPDATE latex_layout_templates SET file_path=?, is_active=?, updated_at=NOW() WHERE id=?");
            $st2->execute([$stored, $isActive, $id]);
            if ($setDefault) {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE latex_layout_templates SET is_default=0");
                $pdo->prepare("UPDATE latex_layout_templates SET is_default=1 WHERE id=?")->execute([$id]);
                $countDefault = (int)$pdo->query("SELECT COUNT(*) FROM latex_layout_templates WHERE is_default=1")->fetchColumn();
                if ($countDefault !== 1) { throw new RuntimeException('Standardvorlage konnte nicht eindeutig gesetzt werden.'); }
                $pdo->commit();
            }
            $msg = 'Layoutvorlage gespeichert.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    }
}

$templates = get_latex_layout_templates($pdo, false);
$latexPackages = get_latex_template_packages($pdo, false);
$activeLatexPackages = get_latex_template_packages($pdo, true);

render_admin_header(t('latex.title', 'Kompetenz-PDF erstellen'));
$latexBuildUrl = url('admin/pdf_preview.php');
$allowTemplatePackage = true;
$allowLatexTemplatePackageSelection = true;
$latexTemplatePackages = $activeLatexPackages;
require __DIR__ . '/../shared/latex_page.php';
?>

<div class="card" style="margin-top:16px;">
  <strong>Vorbereitete Template-Pakete</strong>
  <p class="muted">Intern vorbereitete Pakete können als echte Templates übernommen werden.</p>
  <a class="btn secondary" href="<?=h(url('admin/template_packages.php'))?>">Template-Pakete anzeigen</a>
</div>

<?php if($msg): ?>
  <div class="card" style="border-left:4px solid #067647;">
    <?= h($msg) ?>
    <?php if($ignoredUploadFiles): ?>
      <?php $ignoredPreview = array_slice($ignoredUploadFiles, 0, 30); ?>
      <details style="margin-top:8px;" open>
        <summary>Ignorierte Dateien anzeigen</summary>
        <ul style="margin:8px 0 0 18px;">
          <?php foreach($ignoredPreview as $ignored): ?>
            <?php
              $ignoredPath = (string)($ignored['path'] ?? '');
              $ignoredReason = (string)($ignored['message'] ?? latex_template_package_ignore_message((string)($ignored['reason'] ?? '')));
            ?>
            <li><code><?= h($ignoredPath) ?></code> — <?= h($ignoredReason) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if(count($ignoredUploadFiles) > count($ignoredPreview)): ?>
          <p class="muted">… und <?= h((string)(count($ignoredUploadFiles) - count($ignoredPreview))) ?> weitere.</p>
        <?php endif; ?>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if($err): ?><div class="card" style="border-left:4px solid #b42318;"><?= h($err) ?></div><?php endif; ?>

<details class="card" style="margin-top:20px;">
  <summary><strong>LaTeX-Vorlagenpakete</strong></summary>
  <div style="margin-top:10px;">
    <p class="muted">Das ZIP muss eine Hauptdatei (z. B. main.tex) enthalten. data.tex wird beim Generieren automatisch vom System bereitgestellt und überschreibt eine ggf. enthaltene Datei.</p>
    <table class="table">
      <tr><th>Name</th><th>Status</th><th>Standard</th><th>Hauptdatei</th><th>Erstellt</th><th>Dateien</th><th>Warnungen</th><th>Aktionen</th></tr>
      <?php foreach ($latexPackages as $pkg):
        $manifest = json_decode((string)($pkg['manifest_json'] ?? '{}'), true);
        $fileCount = is_array($manifest['files'] ?? null) ? count($manifest['files']) : 0;
        $warnings = is_array($manifest['warnings'] ?? null) ? $manifest['warnings'] : [];
        $isDefaultPkg = ((int)($pkg['is_default'] ?? 0) === 1);
      ?>
      <tr>
        <td><?=h((string)$pkg['name'])?></td>
        <td><?=h((string)$pkg['status'])?></td>
        <td><?=$isDefaultPkg ? 'Ja' : 'Nein'?></td>
        <td><?=h((string)$pkg['main_file'])?></td>
        <td><?=h((string)$pkg['created_at'])?></td>
        <td><?=h((string)$fileCount)?></td>
        <td><?=h(implode(' ', array_slice($warnings, 0, 3)))?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="latex_package_action" value="toggle_active">
            <input type="hidden" name="package_id" value="<?=h((string)$pkg['id'])?>">
            <button class="btn" type="submit" <?=$isDefaultPkg ? 'disabled title="Standardpaket kann nicht deaktiviert werden."' : ''?>>Aktiv/Inaktiv</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="latex_package_action" value="set_default">
            <input type="hidden" name="package_id" value="<?=h((string)$pkg['id'])?>">
            <button class="btn" type="submit" <?=((string)$pkg['status'] !== 'active') ? 'disabled title="Nur aktive Pakete können Standard sein."' : ''?>>Als Standard</button>
          </form>
          <?php
            $deletePackageConfirm = $isDefaultPkg
              ? 'Dieses LaTeX-Vorlagenpaket ist aktuell als Standard gesetzt. Wenn du es löschst, wird automatisch ein anderes Paket als Standard gesetzt oder die Systemvorlage verwendet.'
              : 'LaTeX-Paket wirklich löschen?';
          ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?=h($deletePackageConfirm)?>');">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="latex_package_action" value="delete">
            <input type="hidden" name="package_id" value="<?=h((string)$pkg['id'])?>">
            <button class="btn" type="submit">Löschen</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$latexPackages): ?><tr><td colspan="8" class="muted">Noch keine LaTeX-Vorlagenpakete importiert.</td></tr><?php endif; ?>
    </table>

    <h4>LaTeX-Vorlagenpaket hochladen</h4>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="latex_package_action" value="upload">
      <label>Name <input class="input" name="package_name" required></label>
      <label>Beschreibung <input class="input" name="package_description"></label>
      <label>Hauptdatei <input class="input" name="package_main_file" value="main.tex" required></label>
      <label>ZIP-Datei <input class="input" type="file" name="latex_package_zip" accept=".zip" required></label>
      <label><input type="checkbox" name="package_is_active" checked> Aktiv</label>
      <label><input type="checkbox" name="package_is_default"> Als Standard setzen</label>
      <button class="btn" type="submit">LaTeX-Paket importieren</button>
    </form>
  </div>
</details>

<details class="card" style="margin-top:20px;">
  <summary><strong>Layoutvorlagen / Titelseiten</strong></summary>
  <div style="margin-top:10px;">
    <p>Die Layout-Datei muss dieselben Makros bereitstellen wie die bestehende layout.tex, insbesondere \CoverPage und \AGSection.</p>
    <table class="table">
      <tr><th>Name</th><th>Key</th><th>Aktiv</th><th>Standard</th><th>Datei</th><th>Aktionen</th></tr>
      <?php foreach($templates as $tpl):
        $exists = is_file(latex_layout_absolute_path((string)$tpl['file_path']));
        $isDefault = ((int)$tpl['is_default'] === 1);
      ?>
        <tr>
          <td><?= h((string)$tpl['display_name']) ?></td>
          <td><?= h((string)$tpl['key_name']) ?></td>
          <td><?= ((int)$tpl['is_active']===1?'Ja':'Nein') ?></td>
          <td><?= $isDefault ? 'Ja' : 'Nein' ?></td>
          <td><?= $exists?'Ja':'Nein' ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
              <input type="hidden" name="layout_action" value="toggle_active">
              <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
              <button class="btn" type="submit" <?= $isDefault ? 'disabled title="Standardvorlage kann nicht deaktiviert werden."' : '' ?>>Aktiv/Inaktiv</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
              <input type="hidden" name="layout_action" value="set_default">
              <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
              <button class="btn" type="submit" <?= ((int)$tpl['is_active']!==1) ? 'disabled title="Nur aktive Vorlagen können Standard sein."' : '' ?>>Als Standard</button>
            </form>
            <?php
              $deleteLayoutConfirm = $isDefault
                ? 'Diese Layoutvorlage ist aktuell als Standard gesetzt. Wenn du sie löschst, wird automatisch eine andere Vorlage als Standard gesetzt oder die Systemvorlage verwendet.'
                : 'Vorlage wirklich löschen?';
            ?>
            <form method="post" style="display:inline" onsubmit="return confirm('<?=h($deleteLayoutConfirm)?>');">
              <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
              <input type="hidden" name="layout_action" value="delete">
              <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
              <button class="btn" type="submit">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h4>Neue Layoutvorlage hochladen / ersetzen</h4>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="layout_action" value="upload">
      <label>Anzeigename <input class="input" name="display_name" required></label>
      <label>Key <input class="input" name="key_name" required pattern="[a-z0-9_-]+"></label>
      <label>Datei (.tex) <input class="input" type="file" name="layout_file" accept=".tex" required></label>
      <label><input type="checkbox" name="is_active" checked> Aktiv</label>
      <label><input type="checkbox" name="is_default"> Als Standard setzen</label>
      <button class="btn" type="submit">Layoutvorlage hochladen</button>
    </form>
  </div>
</details>
<?php
render_admin_footer();
