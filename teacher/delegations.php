<?php
// teacher/delegations.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

$pageTitle = t('teacher.delegations.title', 'Delegationen');
$pageIntro = t('teacher.delegations.intro', 'Hier siehst du alle Delegationen in Klassen, auf die du Zugriff hast – sowohl <strong>an dich</strong> als auch <strong>an andere</strong>. Du kannst Delegationen hier auch <strong>neu zuweisen</strong> oder <strong>aufheben</strong>.');
$pageSearchLabel = t('teacher.delegations.search', 'Suche');
$pageSearchPlaceholder = t('teacher.delegations.search_placeholder', 'Klasse / Gruppe / Kollege…');
$pageSearchHint = t('teacher.delegations.search_hint', '„Bearbeiten…“ öffnet den Dialog zum Zuweisen/Status/Kommentar.');
$statusOpen = t('teacher.delegations.status.open', 'offen');
$statusDone = t('teacher.delegations.status.done', 'fertig');
$modalTitle = t('teacher.delegations.modal.title', 'Delegation bearbeiten');
$modalDelegatedTo = t('teacher.delegations.modal.delegated_to', 'Delegiert an');
$modalClearNote = t('teacher.delegations.modal.clear_note', '„— aufheben —“ entfernt die Delegation komplett.');
$modalStatus = t('teacher.delegations.modal.status', 'Status');
$modalComment = t('teacher.delegations.modal.comment', 'Kommentar');
$modalCommentPlaceholder = t('teacher.delegations.modal.comment_placeholder', 'z.B. bitte prüfen…');
$modalClose = t('teacher.delegations.modal.close', 'Schließen');
$modalSave = t('teacher.delegations.modal.save', 'Speichern');
$emptyText = t('teacher.delegations.empty', 'Keine Delegationen vorhanden.');
$badgeAssigned = t('teacher.delegations.badge.assigned', '{who}');
$badgeRemoved = t('teacher.delegations.badge.removed', 'aufgehoben');
$statusLabel = t('teacher.delegations.status_label', 'Status:');
$delegatedLabel = t('teacher.delegations.delegated_label', 'Delegiert an:');
$clearShort = t('teacher.delegations.modal.clear_short', 'aufheben');

render_teacher_header($pageTitle);
?>

<div class="card">
  <h1><?=h($pageTitle)?></h1>
  <p class="muted" style="margin-top:-6px;"><?=$pageIntro?></p>

  <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap; display: none;">
    <div style="min-width:260px;">
      <label class="label"><?=h($pageSearchLabel)?></label>
      <input class="input" id="q" type="search" placeholder="<?=h($pageSearchPlaceholder)?>" style="width:100%;">
    </div>
    <div class="muted" style="padding-bottom:10px;"><?=h($pageSearchHint)?></div>
  </div>
</div>

<div id="errBox" class="card" style="display:none;"><div class="alert danger"><strong id="errMsg"></strong></div></div>

<div class="card" id="listCard" style="display:none;">
  <div id="list"></div>
</div>

<div id="dlgEdit" class="modal" style="display:none;">
  <div class="modal-backdrop" data-close="1"></div>
  <div class="modal-card" style="width:min(860px, calc(100vw - 24px));">
    <div class="row" style="align-items:center; justify-content:space-between; gap:10px;">
      <h3 style="margin:0;"><?=h($modalTitle)?></h3>
    </div>

    <div class="muted" style="margin-top:6px;" id="dlgMeta">—</div>

    <div class="row" style="gap:10px; margin-top:12px; align-items:flex-end; flex-wrap:wrap;">
      <div style="min-width:280px;">
        <label class="label"><?=h($modalDelegatedTo)?></label>
        <select class="input" id="dlgUser" style="width:100%;"></select>
        <div class="muted" style="font-size:12px; margin-top:4px;"><?=h($modalClearNote)?></div>
      </div>

      <div style="min-width:160px;">
        <label class="label"><?=h($modalStatus)?></label>
        <select class="input" id="dlgSt" style="width:100%;">
          <option value="open"><?=h($statusOpen)?></option>
          <option value="done"><?=h($statusDone)?></option>
        </select>
      </div>

      <div style="flex:1; min-width:240px;">
        <label class="label"><?=h($modalComment)?></label>
        <input class="input" id="dlgNote" type="text" placeholder="<?=h($modalCommentPlaceholder)?>" style="width:100%;">
      </div>

      <div style="display:flex; gap:8px; margin-top: 10px;">
        <button class="btn secondary" type="button" data-close="1"><?=h($modalClose)?></button>
        <button class="btn" type="button" id="dlgSave"><?=h($modalSave)?></button>
      </div>
    </div>
  </div>
</div>

<style>
.inbox-class{ border:1px solid var(--border); border-radius:14px; padding:12px; background:#fff; margin-bottom:12px; }
.inbox-class h3{ margin:0; font-size:16px; }
.inbox-meta{ color:var(--muted); font-size:12px; margin-top:4px; }
.inbox-row{ display:flex; justify-content:space-between; gap:10px; padding:10px; border:1px solid var(--border); border-radius:12px; margin-top:10px; }
.inbox-row .l{ min-width:0; }
.inbox-row .t{ font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.inbox-row .s{ color:var(--muted); font-size:12px; margin-top:3px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.badge-st{ display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; border:1px solid rgba(11,87,208,0.25); background: rgba(11,87,208,0.08); font-size:12px; color: rgba(11,87,208,0.95); }
.badge-st.done{ border-color: rgba(20,140,60,0.25); background: rgba(20,140,60,0.08); color: rgba(20,140,60,0.95); }
.badge-who{ display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; border:1px solid rgba(0,0,0,0.10); background: rgba(0,0,0,0.04); font-size:12px; color: rgba(0,0,0,0.75); }
.modal{ position:fixed; inset:0; z-index:9999; }
.modal-backdrop{ position:absolute; inset:0; background: rgba(0,0,0,0.35); }
.modal-card{ position:relative; margin:12px auto; background:#fff; border-radius:16px; padding:14px; box-shadow: 0 12px 40px rgba(0,0,0,0.22); border:1px solid rgba(0,0,0,0.08); max-height: calc(100vh - 24px); overflow:auto; }
</style>

<script>
(function(){
  const apiUrl = <?=json_encode(url('teacher/ajax/delegations_api.php'))?>;
  const csrf = <?=json_encode(csrf_token())?>;
  const baseOpen = <?=json_encode(url('teacher/entry.php'))?>;
  const emptyText = <?=json_encode($emptyText)?>;
  const statusOpen = <?=json_encode($statusOpen)?>;
  const statusDone = <?=json_encode($statusDone)?>;
  const badgeAssigned = <?=json_encode($badgeAssigned)?>;
  const badgeRemoved = <?=json_encode($badgeRemoved)?>;
  const statusLabel = <?=json_encode($statusLabel)?>;
  const delegatedLabel = <?=json_encode($delegatedLabel)?>;
  const clearShort = <?=json_encode($clearShort)?>;

  const errBox = document.getElementById('errBox');
  const errMsg = document.getElementById('errMsg');
  const listCard = document.getElementById('listCard');
  const listEl = document.getElementById('list');
  const q = document.getElementById('q');

  const dlg = document.getElementById('dlgEdit');
  const dlgMeta = document.getElementById('dlgMeta');
  const dlgUser = document.getElementById('dlgUser');
  const dlgSt = document.getElementById('dlgSt');
  const dlgNote = document.getElementById('dlgNote');
  const dlgSave = document.getElementById('dlgSave');

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function showErr(msg){ errMsg.textContent = msg; errBox.style.display='block'; }
  function clearErr(){ errBox.style.display='none'; errMsg.textContent=''; }

  async function api(action, payload){
    const res = await fetch(apiUrl, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action, csrf_token: csrf, ...payload })
    });
    const j = await res.json().catch(()=>null);
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Fehler');
    return j;
  }

  let data = [];
  let users = [];
  let editCtx = null; // class_id, period_label, group_key, ...

  function buildUsersSelect(selectedUserId){
    dlgUser.innerHTML = '';
    const optNone = document.createElement('option');
    optNone.value = '0';
    optNone.textContent = '— ' + clearShort + ' —';
    dlgUser.appendChild(optNone);

    users.forEach(u => {
      const opt = document.createElement('option');
      opt.value = String(u.id);
      opt.textContent = `${u.name}${u.role==='admin' ? ' (Admin)' : ''}`;
      dlgUser.appendChild(opt);
    });

    dlgUser.value = String(selectedUserId || 0);
  }

  function syncDisableIfClearing(){
    const uid = Number(dlgUser.value || '0');
    const dis = (uid <= 0);
    dlgSt.disabled = dis;
    dlgNote.disabled = dis;
    if (dis) {
      dlgSt.value = 'open';
      dlgNote.value = '';
    }
  }

  function openModal(ctx){
    editCtx = ctx;
    dlgMeta.textContent = `${ctx.class_title} · ${ctx.group_title}`;
    buildUsersSelect(ctx.user_id || 0);
    dlgSt.value = String(ctx.status || 'open');
    dlgNote.value = String(ctx.note || '');
    syncDisableIfClearing();
    dlg.style.display = 'block';
  }

  function closeModal(){
    dlg.style.display = 'none';
    editCtx = null;
  }

  function render(){
    const f = String(q.value||'').toLowerCase().trim();

    const filtered = !f ? data : data.filter(c => {
      if ((c.class_title||'').toLowerCase().includes(f)) return true;
      if ((c.school_year||'').toLowerCase().includes(f)) return true;
      return (c.groups||[]).some(g => {
        const a = (g.group_title||g.group_key||'').toLowerCase();
        const b = (g.user_name||'').toLowerCase();
        return a.includes(f) || b.includes(f);
      });
    });

    if (!filtered.length) {
      listCard.style.display = 'block';
      listEl.innerHTML = '<div class="muted">'+esc(emptyText)+'</div>';
      return;
    }

    const html = filtered.map(c => {
      const gHtml = (c.groups||[]).map(g => {
        const st = String(g.status||'open');
        const note = String(g.note||'').trim();
        const who = String(g.user_name||'').trim();
        const badgeCls = st==='done' ? 'badge-st done' : 'badge-st';
        const badgeTxt = st==='done' ? statusDone : statusOpen;
        const openUrl = baseOpen + `?delegated=1&class_id=${encodeURIComponent(String(c.class_id))}&view=item&group_key=${encodeURIComponent(String(g.group_key))}`;

        return `
          <div class="inbox-row">
            <div class="l">
              <div class="t">${esc(g.group_title || g.group_key)}</div>
              <div class="s">
                <span class="${badgeCls}">${esc(badgeTxt)}</span>
                <span class="badge-who">→ ${who ? esc(badgeAssigned.replace('{who}', who)) : '—'}</span>
                ${note ? ('<span>· ' + esc(note) + '</span>') : ''}
              </div>
            </div>
            <div class="row-actions" style="margin:0; display:flex; gap:8px; flex-wrap:wrap;">
              ${g.is_mine ? `<a class="btn secondary" href="${openUrl}"><?=h(t('teacher.delegations.open', 'Öffnen'))?></a>` : ``}
              <a class="btn primary" type="button"
                data-edit="1"
                data-class-id="${esc(c.class_id)}"
                data-period-label="${esc(c.period_label||'')}"
                data-class-title="${esc(c.class_title||'')}"
                data-group-key="${esc(g.group_key||'')}"
                data-group-title="${esc(g.group_title||g.group_key||'')}"
                data-user-id="${esc(g.user_id||'')}"
                data-user-name="${esc(g.user_name||'')}"
                data-status="${esc(st)}"
                data-note="${esc(note)}"
              ><?=h(t('teacher.delegations.edit', 'Bearbeiten…'))?></a>
            </div>
          </div>
        `;
      }).join('');

      return `
        <div class="inbox-class">
          <h3>${esc(c.class_title)}</h3>
          <div class="inbox-meta">${esc(c.school_year || '')}${c.period_label ? ' · ' + esc(c.period_label) : ''}</div>
          ${gHtml}
        </div>
      `;
    }).join('');

    listCard.style.display = 'block';
    listEl.innerHTML = html;

    listEl.querySelectorAll('[data-edit="1"]').forEach(btn => {
      btn.addEventListener('click', () => {
        openModal({
          class_id: Number(btn.getAttribute('data-class-id')||'0'),
          period_label: String(btn.getAttribute('data-period-label')||'Standard'),
          class_title: String(btn.getAttribute('data-class-title')||''),
          group_key: String(btn.getAttribute('data-group-key')||''),
          group_title: String(btn.getAttribute('data-group-title')||''),
          user_id: Number(btn.getAttribute('data-user-id')||'0'),
          user_name: String(btn.getAttribute('data-user-name')||''),
          status: String(btn.getAttribute('data-status')||'open'),
          note: String(btn.getAttribute('data-note')||''),
        });
      });
    });
  }

  q.addEventListener('input', render);

  if (dlg) {
    dlg.querySelectorAll('[data-close="1"]').forEach(el => el.addEventListener('click', closeModal));
  }
  if (dlgUser) dlgUser.addEventListener('change', syncDisableIfClearing);

  if (dlgSave) {
    dlgSave.addEventListener('click', async () => {
      if (!editCtx) return;
      try {
        const uid = Number(dlgUser.value || '0');
        await api('save', {
          class_id: editCtx.class_id,
          period_label: editCtx.period_label,
          group_key: editCtx.group_key,
          user_id: uid,
          status: uid > 0 ? String(dlgSt.value || 'open') : 'open',
          note: uid > 0 ? String(dlgNote.value || '') : ''
        });

        const j = await api('load', {});
        data = j.items || [];
        users = j.users || users;
        closeModal();
        render();
      } catch (e) {
        showErr(e.message || String(e));
      }
    });
  }

  (async () => {
    try {
      clearErr();
      const j = await api('load', {});
      data = j.items || [];
      users = j.users || [];
      render();
    } catch (e) {
      showErr(e.message || String(e));
    }
  })();
})();
</script>

<?php
render_teacher_footer();
