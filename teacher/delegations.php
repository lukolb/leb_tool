<?php
// teacher/delegations.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_teacher();

render_teacher_header('Delegationen');
?>

<div class="card">
  <div class="row-actions">
    <a class="btn secondary" href="<?=h(url('teacher/index.php'))?>">← Übersicht</a>
  </div>

  <h1 style="margin-top:0;">Delegations‑Inbox</h1>
  <p class="muted" style="margin-top:-6px;">
    Hier siehst du alle <strong>an dich delegierten Fachbereiche</strong> (pro Klasse). Diese sind getrennt von deinen eigenen Klassen.
  </p>

  <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div style="min-width:260px;">
      <label class="label">Suche</label>
      <input class="input" id="q" type="search" placeholder="Klasse / Gruppe…" style="width:100%;">
    </div>
    <div class="muted" style="padding-bottom:10px;">Klicke auf „Öffnen“, um direkt in die delegierte Bearbeitung zu springen.</div>
  </div>
</div>

<div id="errBox" class="card" style="display:none;"><div class="alert danger"><strong id="errMsg"></strong></div></div>

<div class="card" id="listCard" style="display:none;">
  <div id="list"></div>
</div>

<style>
.inbox-class{ border:1px solid var(--border); border-radius:14px; padding:12px; background:#fff; margin-bottom:12px; }
.inbox-class h3{ margin:0; font-size:16px; }
.inbox-meta{ color:var(--muted); font-size:12px; margin-top:4px; }
.inbox-row{ display:flex; justify-content:space-between; gap:10px; align-items:flex-start; padding:10px; border:1px solid var(--border); border-radius:12px; margin-top:10px; }
.inbox-row .l{ min-width:0; }
.inbox-row .t{ font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.inbox-row .s{ color:var(--muted); font-size:12px; margin-top:3px; }
.badge-st{ display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; border:1px solid rgba(11,87,208,0.25); background: rgba(11,87,208,0.08); font-size:12px; color: rgba(11,87,208,0.95); }
.badge-st.done{ border-color: rgba(20,140,60,0.25); background: rgba(20,140,60,0.08); color: rgba(20,140,60,0.95); }
</style>

<script>
(function(){
  const apiUrl = <?=json_encode(url('teacher/ajax/delegations_api.php'))?>;
  const csrf = <?=json_encode(csrf_token())?>;

  const errBox = document.getElementById('errBox');
  const errMsg = document.getElementById('errMsg');
  const listCard = document.getElementById('listCard');
  const listEl = document.getElementById('list');
  const q = document.getElementById('q');

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

  function render(){
    const f = String(q.value||'').toLowerCase().trim();
    const filtered = !f ? data : data.filter(c => {
      if ((c.class_title||'').toLowerCase().includes(f)) return true;
      return (c.groups||[]).some(g => (g.group_title||g.group_key||'').toLowerCase().includes(f));
    });

    if (!filtered.length) {
      listCard.style.display = 'block';
      listEl.innerHTML = '<div class="muted">Keine Delegationen vorhanden.</div>';
      return;
    }

    const baseOpen = <?=json_encode(url('teacher/entry.php'))?>;

    const html = filtered.map(c => {
      const gHtml = (c.groups||[]).map(g => {
        const st = String(g.status||'open');
        const note = String(g.note||'').trim();
        const badgeCls = st==='done' ? 'badge-st done' : 'badge-st';
        const badgeTxt = st==='done' ? 'fertig' : 'offen';
        const openUrl = baseOpen + `?delegated=1&class_id=${encodeURIComponent(String(c.class_id))}&view=item&group_key=${encodeURIComponent(String(g.group_key))}`;
        return `
          <div class="inbox-row">
            <div class="l">
              <div class="t">${esc(g.group_title || g.group_key)}</div>
              <div class="s"><span class="${badgeCls}">${esc(badgeTxt)}</span>${note ? ' · ' + esc(note) : ''}</div>
            </div>
            <div class="row-actions" style="margin:0;">
              <a class="btn secondary" href="${openUrl}">Öffnen</a>
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
  }

  q.addEventListener('input', render);

  (async () => {
    try {
      clearErr();
      const j = await api('load', {});
      data = j.items || [];
      render();
    } catch (e) {
      showErr(e.message || String(e));
    }
  })();
})();
</script>

<?php
render_teacher_footer();
