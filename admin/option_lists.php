<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();
$err = '';
$ok = '';

// Helper function to create directories
function ensure_dir(string $p): void {
  if (!is_dir($p)) @mkdir($p, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();

    // Handle "Create New Option List"
    if (($_POST['action'] ?? '') === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name === '') throw new RuntimeException('Name fehlt.');

      // Create new option list
      $stmt = $pdo->prepare("
        INSERT INTO option_list_templates (name, created_by_user_id)
        VALUES (?, ?)
      ");
      $stmt->execute([$name, (int)current_user()['id']]);
      $newId = (int)$pdo->lastInsertId();

      audit('option_list_create', (int)current_user()['id'], ['list_id' => $newId]);
      $ok = "Option-Liste wurde angelegt (#{$newId}).";
    }

    // Handle "Save Items"
    if (($_POST['action'] ?? '') === 'save_items') {
      $listId = (int)($_POST['list_id'] ?? 0);
      $items = $data['items'] ?? [];

      if ($listId <= 0 || !is_array($items)) throw new RuntimeException('Ungültige Eingabedaten.');

      $pdo->beginTransaction();

      // Insert/Update Items for the selected list
      $ins = $pdo->prepare("
        INSERT INTO option_list_items (list_id, value, label, icon_id, sort_order)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE value=VALUES(value), label=VALUES(label), icon_id=VALUES(icon_id), sort_order=VALUES(sort_order)
      ");

      foreach ($items as $item) {
        $value = (string)($item['value'] ?? '');
        $label = (string)($item['label'] ?? '');
        $icon_id = (int)($item['icon_id'] ?? 0);
        $sort_order = (int)($item['sort_order'] ?? 0);

        if ($value === '' || $label === '') continue; // Skip empty items

        $ins->execute([$listId, $value, $label, $icon_id, $sort_order]);
      }

      $pdo->commit();
      audit('option_list_save_items', (int)current_user()['id'], ['list_id' => $listId]);

      $ok = "Optionen für Liste #{$listId} gespeichert.";
    }

    // Handle "Delete List"
    if (($_POST['action'] ?? '') === 'delete') {
      $listId = (int)($_POST['list_id'] ?? 0);
      if ($listId <= 0) throw new RuntimeException('Ungültige Liste.');

      $stmt = $pdo->prepare("DELETE FROM option_list_templates WHERE id=?");
      $stmt->execute([$listId]);

      // Delete associated items
      $stmt = $pdo->prepare("DELETE FROM option_list_items WHERE list_id=?");
      $stmt->execute([$listId]);

      audit('option_list_delete', (int)current_user()['id'], ['list_id' => $listId]);

      $ok = "Option-Liste #{$listId} gelöscht.";
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$lists = $pdo->query("
  SELECT id, name, created_at
  FROM option_list_templates
  ORDER BY created_at DESC
")->fetchAll();

render_admin_header('Admin – Auswahllisten Vorlagen');
?>

<style>
  /* Styles for grid layout, table, etc. */
  .listbox {
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 20px;
  }

  .listbox .head {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    background: var(--card, #fff);
    position: sticky;
    top: 0;
    z-index: 2;
  }

  .table-scroll {
    max-height: 62vh;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 12px;
  }

  #fieldsTbl {
    width: 100%;
    min-width: 1400px;
    border-collapse: separate;
    border-spacing: 0;
  }

  #fieldsTbl th,
  #fieldsTbl td {
    vertical-align: top;
    border-bottom: 1px solid var(--border);
    padding: 10px;
  }

  #fieldsTbl thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    background: var(--card, #fff);
  }
  
  .muted { color: #666; }
</style>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('admin/index.php'))?>">← Admin</a>
    <a class="btn secondary" href="<?=h(url('admin/settings.php'))?>">Settings</a>
    <a class="btn secondary" href="<?=h(url('logout.php'))?>">Logout</a>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<!-- Template Management -->
<div class="card">
  <h2>Vorlagen verwalten</h2>

  <div class="grid">
    <div>
      <label>Neu anlegen</label>
      <input id="newName" placeholder="z.B. Skala 1–6, Ja/Nein, etc.">
      <button class="btn primary" id="btnCreate" type="button">Erstellen</button>
    </div>

    <div class="listbox" style="margin-top:8px;">
      <div class="head">
        <div class="grid" style="grid-template-columns:1fr 120px;">
          <div>
            <label>Suche</label>
            <input id="listFilter" placeholder="z.B. Skala, Bewertung, etc.">
          </div>
          <button class="btn secondary" id="btnReload" type="button">Neu laden</button>
        </div>
      </div>
      <div class="body" id="lists">
        <!-- List of templates will go here -->
      </div>
    </div>
  </div>
</div>

<!-- List Items Management -->
<div class="card">
  <h2>Optionen verwalten</h2>
  <?php if (empty($lists)): ?>
    <p class="muted">Noch keine Vorlagen.</p>
  <?php else: ?>
    <div id="itemsWrapper" style="display:none;">
      <div class="actions">
        <button class="btn secondary" id="btnSaveItems" type="button">Speichern</button>
        <button class="btn secondary" id="btnAddItem" type="button">Hinzufügen</button>
        <button class="btn danger" id="btnDeleteList" type="button">Liste löschen</button>
      </div>
      <div class="table-scroll" style="margin-top:10px;">
        <table id="fieldsTbl">
          <thead>
            <tr>
              <th>Value</th>
              <th>Label</th>
              <th>Icon</th>
              <th>Sort Order</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
// JavaScript to handle list actions, add/edit items, etc.
document.addEventListener('DOMContentLoaded', function () {
  let activeListId = 0;
  const newName = document.getElementById('newName');
  const btnCreate = document.getElementById('btnCreate');
  const btnReload = document.getElementById('btnReload');
  const listFilter = document.getElementById('listFilter');
  const itemsWrapper = document.getElementById('itemsWrapper');
  const btnSaveItems = document.getElementById('btnSaveItems');
  const btnAddItem = document.getElementById('btnAddItem');
  const btnDeleteList = document.getElementById('btnDeleteList');
  const listsEl = document.getElementById('lists');
  const fieldsTblBody = document.querySelector('#fieldsTbl tbody');
  
  async function fetchTemplates() {
    const response = await fetch('<?=h(url('admin/ajax/option_list_api.php'))?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'list' })
    });
    const data = await response.json();
    return data.lists || [];
  }

  async function renderLists() {
    const lists = await fetchTemplates();
    listsEl.innerHTML = lists.map(template => `
      <div class="listitem" data-id="${template.id}">
        <strong>#${template.id} - ${template.name}</strong>
      </div>
    `).join('');
  }

  listsEl.addEventListener('click', function (e) {
    const listItem = e.target.closest('.listitem');
    if (listItem) {
      activeListId = listItem.dataset.id;
      // Fetch and load the items for this template.
      loadTemplateItems(activeListId);
    }
  });

  async function loadTemplateItems(listId) {
    const response = await fetch('<?=h(url('admin/ajax/option_list_api.php'))?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'get', id: listId })
    });
    const data = await response.json();
    const { items } = data;

    // Show and render items for selected template
    itemsWrapper.style.display = 'block';
    fieldsTblBody.innerHTML = items.map(item => `
      <tr data-id="${item.id}">
        <td><input type="text" value="${item.value}"></td>
        <td><input type="text" value="${item.label}"></td>
        <td><input type="text" value="${item.icon_id}"></td>
        <td><input type="number" value="${item.sort_order}"></td>
      </tr>
    `).join('');
  }

  btnCreate.addEventListener('click', async function () {
    const name = newName.value.trim();
    if (name === '') {
      alert('Bitte einen Namen angeben');
      return;
    }
    const response = await fetch('<?=h(url('admin/ajax/option_list_api.php'))?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create', name: name })
    });
    const data = await response.json();
    if (data.ok) {
      alert('Vorlage erstellt');
      renderLists();
    }
  });

  btnReload.addEventListener('click', async function () {
    await renderLists();
  });

  btnSaveItems.addEventListener('click', async function () {
    const items = [];
    document.querySelectorAll('#fieldsTbl tbody tr').forEach(row => {
      const value = row.querySelector('input[type="text"]').value;
      const label = row.querySelector('input[type="text"]').value;
      const icon_id = row.querySelector('input[type="text"]').value;
      const sort_order = row.querySelector('input[type="number"]').value;

      items.push({ value, label, icon_id, sort_order });
    });

    const response = await fetch('<?=h(url('admin/ajax/option_list_api.php'))?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_items', list_id: activeListId, items })
    });
    const data = await response.json();
    if (data.ok) {
      alert('Änderungen gespeichert');
    }
  });

  btnAddItem.addEventListener('click', function () {
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
      <td><input type="text" placeholder="Wert"></td>
      <td><input type="text" placeholder="Label"></td>
      <td><input type="text" placeholder="Icon"></td>
      <td><input type="number" value="0"></td>
    `;
    fieldsTblBody.appendChild(newRow);
  });

  btnDeleteList.addEventListener('click', async function () {
    const confirmDelete = confirm('Möchten Sie diese Liste wirklich löschen?');
    if (confirmDelete) {
      const response = await fetch('<?=h(url('admin/ajax/option_list_api.php'))?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', list_id: activeListId })
      });
      const data = await response.json();
      if (data.ok) {
        alert('Liste gelöscht');
        await renderLists();
      }
    }
  });

  // Initialize
  renderLists();
});
</script>

<?php render_admin_footer(); ?>
