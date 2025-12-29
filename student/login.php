<?php
// student/login.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pdo = db();

function student_session_set(int $studentId): void {
  session_regenerate_id(true);
  $_SESSION['student'] = ['id' => $studentId];
}

$err = '';
$code = '';
$token = (string)($_GET['token'] ?? '');

if ($token !== '') {
  try {
    $st = $pdo->prepare("SELECT id FROM students WHERE qr_token=? AND is_active=1 LIMIT 1");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException(t('student.login.error.invalid_qr', 'Ungültiger QR-Code.'));
    student_session_set((int)$row['id']);
    redirect('student/index.php');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();
    $code = strtoupper(trim((string)($_POST['login_code'] ?? '')));
    $code = preg_replace('/\s+/', '', $code);
    if ($code === '') throw new RuntimeException(t('student.login.error.missing_code', 'Code fehlt.'));

    $st = $pdo->prepare("SELECT id FROM students WHERE login_code=? AND is_active=1 LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException(t('student.login.error.not_found', 'Code nicht gefunden.'));

    student_session_set((int)$row['id']);
    redirect('student/index.php');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$b = brand();
$org = (string)($b['org_name'] ?? 'LEB Tool');
$logo = (string)($b['logo_path'] ?? '');

?>
<!doctype html>
<html lang="<?=h(ui_lang())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($org)?> – <?=h(t('student.login.html_title', 'Schüler Login'))?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>
      body.page{
        font-family: "Druckschrift";
      }
      
    :root{--primary:<?=h((string)($b['primary'] ?? '#0b57d0'))?>;--secondary:<?=h((string)($b['secondary'] ?? '#111'))?>;}

    .code-wrap{
      position: relative;
      width: 100%;
      max-width: 360px;
    }

    /* Monospace = feste Zeichenbreite => Overlay/“Blinken” sitzt perfekt */
    .code-wrap input.code-input,
    .code-wrap .code-overlay{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-variant-numeric: tabular-nums;
    }

    /* Echtes Input: nimmt Eingaben an, zeigt aber keinen Text/Caret */
    .code-wrap input.code-input{
      position: relative;
      z-index: 2;
      width: 100%;
      padding: 10px 12px;
      font-size: 18px;           /* auf Handy besser */
      line-height: 1.2;
      background: transparent;

      color: transparent;
      caret-color: transparent;

      border: 1px solid #cfd6e4;
      border-radius: 10px;
      outline: none;
    }

    .code-wrap input.code-input:focus{
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(11,87,208,.18);
    }

    /* Overlay: nur Anzeige, NICHT klickbar (wichtig fürs Handy: Tastatur bleibt offen) */
    .code-wrap .code-overlay{
      position: absolute;
      inset: 0;
      z-index: 1;
      pointer-events: none;

      display: flex;
      align-items: center;

      padding: 10px 12px;
      font-size: 18px;
      line-height: 1.2;

      white-space: pre;
      user-select: none;
      color: #1d2433;
      border-radius: 10px;
    }

    .code-overlay .cell{
      display: inline-block;
      width: 1ch;                /* exakt ein Zeichen breit (Monospace!) */
      text-align: center;
      position: relative;        /* für “_ und █ an gleicher Stelle” */
      line-height: 1.2;          /* wichtig: gleiche line-height wie input/overlay */
    }

    .code-overlay .dash{ color:#1d2433; }
    .code-overlay .placeholder{ color:#b6bfcc; }

    /* Aktive leere Stelle: "_" und "█" exakt übereinander, blinken abwechselnd */
    .code-overlay .blink .u,
    .code-overlay .blink .b{
      position: absolute;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 100%;
      text-align: center;
    }
    .code-overlay .blink .u{ color:#b6bfcc; }
    .code-overlay .blink .b{ color:#1d2433; }

    @keyframes swapU { 0%,49%{opacity:1} 50%,100%{opacity:0} }
    @keyframes swapB { 0%,49%{opacity:0} 50%,100%{opacity:1} }

    .code-overlay .blink .u{ animation: swapU 1s step-end infinite; }
    .code-overlay .blink .b{ animation: swapB 1s step-end infinite; }

    /* Wenn vollständig: kein Blinken */
    .code-wrap.is-complete .code-overlay .blink .u,
    .code-wrap.is-complete .code-overlay .blink .b{
      animation: none;
      opacity: 0;
    }

    /* Admin/Lehrkraft-Link dezent */
    .alt-login{
      margin-top: 10px;
      text-align: right;
      font-size: 12px;
      opacity: .75;
    }
    .alt-login a{
      color: inherit;
      text-decoration: underline;
      text-decoration-thickness: 1px;
      text-underline-offset: 2px;
    }
    .alt-login a:hover{ opacity: 1; }
  </style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle"><?=h(t('student.login.brand_subtitle', 'Schüler Login'))?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h1><?=h(t('student.login.heading', 'Einloggen'))?></h1>

      <?php if ($err): ?>
        <div class="alert danger"><strong><?=h($err)?></strong></div>
      <?php endif; ?>

      <p class="muted">
        <?=h(t('student.login.info', 'Login per QR-Code führt direkt hierher. Wenn dein Gerät keine Kamera hat, kannst du den Login-Code eingeben.'))?>
      </p>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">

        <label for="login_code_mask"><?=h(t('student.login.label_code', 'Login-Code'))?></label>

        <div class="code-wrap" id="code_wrap">
          <div id="login_code_overlay" class="code-overlay" aria-hidden="true"></div>

          <input
            id="login_code_mask"
            class="code-input"
            type="text"
            value=""
            required
            autocomplete="off"
            inputmode="text"
            maxlength="9"
            aria-label="<?=h(t('student.login.aria_code', 'Login-Code im Format ABCD-1234'))?>"
            autofocus
          >
        </div>

        <input
          id="login_code"
          name="login_code"
          type="hidden"
          value="<?=h($code)?>"
        >

        <div class="actions">
          <button class="btn primary" type="submit"><?=h(t('student.login.submit', 'Einloggen'))?></button>
        </div>

        <!-- Dezent, damit Schüler nicht aus Versehen drauf klicken -->
        <div class="alt-login">
          <a href="<?=h(url('login.php'))?>"><?=h(t('student.login.alt', 'Lehrkraft/Admin'))?></a>
        </div>
      </form>

      <script>
      document.addEventListener('DOMContentLoaded', () => {
        const wrap = document.getElementById('code_wrap');
        const input = document.getElementById('login_code_mask');
        const overlay = document.getElementById('login_code_overlay');
        const hidden = document.getElementById('login_code');

        const MASK_LEN = 9;                // "____-____"
        const DASH_POS = 4;
        const SLOT_TO_POS = [0,1,2,3,5,6,7,8]; // 8 slots
        const POS_TO_SLOT = new Map(SLOT_TO_POS.map((p,i)=>[p,i]));

        const cleanRaw = (s) => (s || "")
          .toUpperCase()
          .replace(/[^A-Z0-9]/g, "")
          .slice(0, 8);

        const formatForSubmit = (raw) => raw.length > 4 ? (raw.slice(0,4) + '-' + raw.slice(4)) : raw;

        function getRaw() {
          return cleanRaw((hidden.value || "").replace(/[^A-Z0-9]/g, ""));
        }

        function setRaw(raw) {
          raw = cleanRaw(raw);
          hidden.value = formatForSubmit(raw);

          // Input bleibt konstant (für selectionStart/Range)
          input.value = "____-____";

          input.setCustomValidity(raw.length === 8 ? '' : 'Bitte 8 Zeichen eingeben (ABCD-1234).');
          wrap.classList.toggle('is-complete', raw.length === 8);

          renderOverlay(raw);
        }

        function slotIndexFromCaretPos(pos) {
          let count = 0;
          for (const p of SLOT_TO_POS) if (p < pos) count++;
          return count;
        }

        function caretPosFromSlotIndex(slotIdx) {
          if (slotIdx <= 0) return SLOT_TO_POS[0];
          if (slotIdx >= SLOT_TO_POS.length) return SLOT_TO_POS[SLOT_TO_POS.length - 1] + 1;
          return SLOT_TO_POS[slotIdx];
        }

        function setCaretToNext() {
          const raw = getRaw();
          const idx = Math.min(raw.length, 8);
          const pos = caretPosFromSlotIndex(idx);
          input.setSelectionRange(pos, pos);
        }

        function setCaretToSlot(slotIdx) {
          const pos = caretPosFromSlotIndex(Math.min(Math.max(slotIdx, 0), 8));
          input.setSelectionRange(pos, pos);
        }

        function renderOverlay(raw) {
          const showBlink = raw.length < 8;
          const blinkSlot = showBlink ? Math.min(raw.length, 7) : -1;

          let html = "";
          for (let pos = 0; pos < MASK_LEN; pos++) {
            if (pos === DASH_POS) {
              html += `<span class="cell dash">-</span>`;
              continue;
            }

            const slotIdx = POS_TO_SLOT.get(pos); // 0..7
            const filled = (slotIdx !== undefined && raw[slotIdx]) ? raw[slotIdx] : null;

            if (filled) {
              html += `<span class="cell">${filled}</span>`;
            } else if (showBlink && slotIdx === blinkSlot) {
              html += `<span class="cell blink"><span class="u">_</span><span class="b">█</span></span>`;
            } else {
              html += `<span class="cell placeholder">_</span>`;
            }
          }
          overlay.innerHTML = html;
        }

        // === Eingabe komplett selbst steuern ===
        input.addEventListener('beforeinput', (e) => {
          const t = e.inputType;
          const allowed = [
            'insertText',
            'deleteContentBackward',
            'deleteContentForward',
            'insertFromPaste',
            'insertReplacementText'
          ];
          if (!allowed.includes(t)) return;

          e.preventDefault();

          let raw = getRaw();

          const selStart = input.selectionStart ?? 0;
          const selEnd   = input.selectionEnd ?? selStart;

          const startSlot = slotIndexFromCaretPos(selStart);
          const endSlot   = slotIndexFromCaretPos(selEnd);

          if (selEnd > selStart) {
            const removeCount = Math.max(0, endSlot - startSlot);
            raw = raw.slice(0, startSlot) + raw.slice(startSlot + removeCount);
          }

          if (t === 'insertText' || t === 'insertReplacementText') {
            const ch = cleanRaw(e.data || '');
            if (!ch) {
              setRaw(raw);
              setCaretToSlot(startSlot);
              return;
            }
            if (raw.length < 8) {
              raw = raw.slice(0, startSlot) + ch[0] + raw.slice(startSlot);
              raw = raw.slice(0, 8);
            }
            setRaw(raw);
            setCaretToSlot(startSlot + 1);
            return;
          }

          if (t === 'insertFromPaste') {
            const pasted = cleanRaw(
              (e.data) ||
              (e.clipboardData && e.clipboardData.getData('text')) ||
              ''
            );
            if (!pasted) {
              setRaw(raw);
              setCaretToSlot(startSlot);
              return;
            }
            const space = 8 - raw.length;
            const toInsert = pasted.slice(0, space);

            raw = raw.slice(0, startSlot) + toInsert + raw.slice(startSlot);
            raw = raw.slice(0, 8);

            setRaw(raw);
            setCaretToSlot(startSlot + toInsert.length);
            return;
          }

          if (t === 'deleteContentBackward') {
            const idx = (selEnd > selStart) ? startSlot : startSlot - 1;
            if (idx >= 0 && idx < raw.length) {
              raw = raw.slice(0, idx) + raw.slice(idx + 1);
            }
            setRaw(raw);
            setCaretToSlot(Math.max(idx, 0));
            return;
          }

          if (t === 'deleteContentForward') {
            const idx = startSlot;
            if (idx >= 0 && idx < raw.length) {
              raw = raw.slice(0, idx) + raw.slice(idx + 1);
            }
            setRaw(raw);
            setCaretToSlot(idx);
            return;
          }
        });

        // Pfeiltasten: slotweise bewegen
        input.addEventListener('keydown', (e) => {
          const key = e.key;
          const selStart = input.selectionStart ?? 0;
          const idx = slotIndexFromCaretPos(selStart);
          const raw = getRaw();

          if (key === 'ArrowLeft') {
            e.preventDefault();
            setCaretToSlot(Math.max(idx - 1, 0));
            renderOverlay(raw);
          } else if (key === 'ArrowRight') {
            e.preventDefault();
            setCaretToSlot(Math.min(idx + 1, 8));
            renderOverlay(raw);
          } else if (key === 'Home') {
            e.preventDefault();
            setCaretToSlot(0);
            renderOverlay(raw);
          } else if (key === 'End') {
            e.preventDefault();
            setCaretToSlot(Math.min(raw.length, 8));
            renderOverlay(raw);
          }
        });

        // Klick ins Feld: immer “Eingabemodus” + Cursor zur nächsten Stelle
        input.addEventListener('focus', () => {
          setCaretToNext();
          renderOverlay(getRaw());
        });

        input.addEventListener('click', () => {
          setTimeout(() => {
            setCaretToNext();
            renderOverlay(getRaw());
          }, 0);
        });

        // Init
        const initialRaw = cleanRaw(hidden.value);
        setRaw(initialRaw);

        setTimeout(() => {
          input.focus({ preventScroll: true });
          setCaretToNext();
          renderOverlay(getRaw());
        }, 0);
      });
      </script>
    </div>
  </div>
</body>
</html>
