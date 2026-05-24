<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../shared/latex_layout_templates.php';
require_admin();

$pdo = db();
ensure_default_latex_layout_template($pdo);
ensure_latex_layout_storage_dir();
$msg = '';
$err = '';

function is_system_annual_template(array $tpl): bool {
    return ((string)($tpl['key_name'] ?? '') === 'annual_report')
        && ((string)($tpl['file_path'] ?? '') === 'latex/layout.tex');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['layout_action'] ?? '');
    try {
        if ($action === 'set_default') {
            $id = (int)($_POST['template_id'] ?? 0);
            $tpl = find_active_latex_layout_template($pdo, $id);
            if (!$tpl) throw new RuntimeException('Vorlage nicht gefunden oder inaktiv.');
            $pdo->exec("UPDATE latex_layout_templates SET is_default=0");
            $st = $pdo->prepare("UPDATE latex_layout_templates SET is_default=1 WHERE id=?");
            $st->execute([$id]);
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
            if ((int)($tpl['is_default'] ?? 0) === 1) throw new RuntimeException('Standardvorlage kann nicht gelöscht werden.');
            if (is_system_annual_template($tpl)) throw new RuntimeException('Systemvorlage Jahreszeugnis kann nicht gelöscht werden.');

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

            $pdo->prepare("DELETE FROM latex_layout_templates WHERE id=?")->execute([$id]);

            if ($canDeleteFile && is_file($fileAbs)) {
                @unlink($fileAbs);
            }
            $msg = 'Layoutvorlage gelöscht.';
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
            $stored = 'uploads/latex_layouts/layout_template_' . $id . '.tex';
            $abs = latex_layout_absolute_path($stored);
            if (!move_uploaded_file($tmp, $abs)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');
            $st2 = $pdo->prepare("UPDATE latex_layout_templates SET file_path=?, is_active=?, updated_at=NOW() WHERE id=?");
            $st2->execute([$stored, $isActive, $id]);
            if ($setDefault) {
                $pdo->exec("UPDATE latex_layout_templates SET is_default=0");
                $pdo->prepare("UPDATE latex_layout_templates SET is_default=1 WHERE id=?")->execute([$id]);
            }
            $msg = 'Layoutvorlage gespeichert.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$templates = get_latex_layout_templates($pdo, false);

render_admin_header(t('latex.title', 'Kompetenz-PDF erstellen'));
$latexBuildUrl = url('admin/pdf_preview.php');
require __DIR__ . '/../shared/latex_page.php';
?>

<?php if($msg): ?><div class="card" style="border-left:4px solid #067647;"><?= h($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="border-left:4px solid #b42318;"><?= h($err) ?></div><?php endif; ?>

<details class="card" style="margin-top:20px;">
  <summary><strong>Layoutvorlagen / Titelseiten</strong></summary>
  <div style="margin-top:10px;">
    <p>Die Layout-Datei muss dieselben Makros bereitstellen wie die bestehende layout.tex, insbesondere \CoverPage und \AGSection.</p>
    <table class="table">
      <tr><th>Name</th><th>Key</th><th>Aktiv</th><th>Standard</th><th>Datei</th><th>Aktionen</th></tr>
      <?php foreach($templates as $tpl):
        $exists = is_file(latex_layout_absolute_path((string)$tpl['file_path']));
        $isDefault = ((int)$tpl['is_default'] === 1);
        $isSystemAnnual = is_system_annual_template($tpl);
        $canDelete = !$isDefault && !$isSystemAnnual;
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
            <?php if ($canDelete): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Vorlage wirklich löschen?');">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="layout_action" value="delete">
                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                <button class="btn" type="submit">Löschen</button>
              </form>
            <?php else: ?>
              <button class="btn" type="button" disabled title="Standardvorlage kann nicht gelöscht werden.">Löschen</button>
            <?php endif; ?>
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
