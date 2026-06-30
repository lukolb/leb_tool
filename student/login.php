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
    $msg = $e->getMessage();
    if (str_contains(strtolower($msg), 'csrf') || str_contains(strtolower($msg), 'token')) {
      unset($_SESSION['csrf_token']);
      csrf_token();
      $err = t('auth.login.csrf_expired', 'Die Login-Seite war zu lange geöffnet. Bitte versuche es erneut.');
    } else {
      $err = $msg;
    }
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
  <link rel="stylesheet" href="<?=h(url('assets/font-awesome/font-awesome.css'))?>">
  <script src="<?=h(url('assets/js/jsqr.min.js'))?>"></script>
  <style>
      body.page{
        font-family: "Druckschrift";
      }
      
    :root{--primary:<?=h((string)($b['primary'] ?? '#0b57d0'))?>;--secondary:<?=h((string)($b['secondary'] ?? '#111'))?>;}

    .code-wrap{
      position: relative;
      width: 100%;
      max-width: 200px;
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
      padding: 10px 48px 10px 12px;
      font-size: 18px;           /* auf Handy besser */
      line-height: 1.2;
      background: transparent;

      color: transparent;
      caret-color: transparent;
      user-select: none;

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
      gap: 2px;

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

    .qr-button{
      position: absolute;
      right: 6px;
      top: 50%;
      transform: translateY(-50%);
      z-index: 3;
      width: 36px;
      height: 36px;
      border-radius: 9px;
      display: grid;
      place-items: center;
      padding: 0;
      border: none;
      background: transparent;
      box-shadow: none;
      color: #1d2433;
      cursor: pointer;
    }

    .qr-button .fa{
      width: 25px;
      height: 25px;
    }

    .qr-scan{
      margin-top: 16px;
      border: 1px dashed #cfd6e4;
      border-radius: 12px;
      padding: 12px;
      display: none;
      gap: 12px;
      align-items: center;
      background: #f7f9fc;
    }

    .qr-scan.active{
      display: grid;
      grid-template-columns: minmax(0, 1fr);
    }

    .qr-video{
      width: 100%;
      max-height: 260px;
      border-radius: 10px;
      background: #111;
      object-fit: cover;
    }

    .qr-video-wrap{
      position: relative;
    }

    .qr-overlay{
      position: absolute;
      left: 8px;
      right: 8px;
      bottom: 8px;
      background: rgba(17, 24, 39, 0.78);
      color: #fff;
      padding: 6px 10px;
      border-radius: 10px;
      font-size: 14px;
      line-height: 1.3;
      display: flex;
      align-items: center;
      min-height: 32px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .qr-actions{
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .qr-hint{
      font-size: 14px;
      color: #5a667a;
      margin: 0;
    }

    .qr-overlay .qr-hint{
      color: #fff;
    }
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
            autocorrect="off"
            autocapitalize="characters"
            spellcheck="false"
            inputmode="text"
            maxlength="8"
            aria-label="<?=h(t('student.login.aria_code', 'Login-Code im Format ABCD-1234'))?>"
            autofocus
          >

          <button class="qr-button" type="button" id="qrScanButton" aria-label="<?=h(t('student.login.scan_qr', 'QR-Code scannen'))?>">
            <i class="fa fa-qrcode" aria-hidden="true"></i>
          </button>
        </div>

        <input
          id="login_code"
          name="login_code"
          type="hidden"
          value="<?=h($code)?>"
        >

        <div class="actions">
          <a class="btn primary" type="submit" onclick="this.closest('form').submit(); return false;"><?=h(t('student.login.submit', 'Einloggen'))?></a>
        </div>

        <div class="qr-scan" id="qrScanPanel" aria-live="polite">
          <div class="qr-video-wrap">
            <video class="qr-video" id="qrScanVideo" playsinline></video>
            <div class="qr-overlay">
              <p class="qr-hint" id="qrScanHint"><?=h(t('student.login.scan_hint', 'Kamera öffnen und QR-Code in den Rahmen halten.'))?></p>
            </div>
          </div>
          <div class="qr-actions">
            <button class="btn secondary" type="button" id="qrScanStop">
              <?=h(t('student.login.scan_stop', 'Kamera schließen'))?>
            </button>
          </div>
        </div>

        <!-- Dezent, damit Schüler nicht aus Versehen drauf klicken -->
        <div class="alt-login">
          <a href="<?=h(url('login.php'))?>"><?=h(t('student.login.alt', 'Lehrkraft/Admin'))?></a>
        </div>
      </form>

      <script>
      document.addEventListener('DOMContentLoaded', () => {
        const msgCodeInvalid = <?= json_encode(t('student.login.code_invalid')) ?>;
        const msgQrActiveHint = <?= json_encode(t('student.login.qr_active_hint')) ?>;
        const msgQrScanFailed = <?= json_encode(t('student.login.qr_scan_failed')) ?>;
        const msgQrCameraFailed = <?= json_encode(t('student.login.qr_camera_failed')) ?>;
        const msgQrInvalidPayload = <?= json_encode(t('student.login.qr_invalid_payload')) ?>;
        const msgQrOpening = <?= json_encode(t('student.login.qr_opening')) ?>;
        const msgQrDeviceUnsupported = <?= json_encode(t('student.login.qr_device_unsupported')) ?>;
        const msgQrRequiresHttps = <?= json_encode(t('student.login.qr_requires_https')) ?>;
        const msgQrUnsupportedBrowser = <?= json_encode(t('student.login.qr_unsupported_browser')) ?>;
        const msgQrNotAvailable = <?= json_encode(t('student.login.qr_not_available')) ?>;
        const msgQrPermissionDenied = <?= json_encode(t('student.login.qr_permission_denied')) ?>;
        const wrap = document.getElementById('code_wrap');
        const input = document.getElementById('login_code_mask');
        const overlay = document.getElementById('login_code_overlay');
        const hidden = document.getElementById('login_code');
        const qrScanButton = document.getElementById('qrScanButton');
        const qrScanPanel = document.getElementById('qrScanPanel');
        const qrScanVideo = document.getElementById('qrScanVideo');
        const qrScanHint = document.getElementById('qrScanHint');
        const qrScanStop = document.getElementById('qrScanStop');

        let qrStream = null;
        let qrScanActive = false;
        let qrScanTimer = null;
        let qrDetector = null;
        let qrAudioCtx = null;
        let qrCanvas = null;
        let qrCanvasCtx = null;

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
          input.value = raw;
          input.setCustomValidity(raw.length === 8 ? '' : msgCodeInvalid);
          wrap.classList.toggle('is-complete', raw.length === 8);
          renderOverlay(raw);
          setCaretToEnd(raw.length);
        }

        function setCaretToEnd(len) {
          const pos = Math.min(Math.max(len, 0), 8);
          input.setSelectionRange(pos, pos);
        }

        function ensureInputFocus() {
          if (qrScanActive) {
            return;
          }
          if (document.activeElement !== input) {
            input.focus({ preventScroll: true });
            setCaretToEnd(getRaw().length);
          }
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

        // Eingabe robust über input-Event (iOS-kompatibel)
        input.addEventListener('input', () => {
          const raw = cleanRaw(input.value);
          setRaw(raw);
        });

        // Cursor nie manuell verschieben (Pfeiltasten/Home/Ende unterbinden)
        input.addEventListener('keydown', (e) => {
          const blocked = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
          if (blocked.includes(e.key)) {
            e.preventDefault();
            setCaretToEnd(getRaw().length);
          }
        });

        input.addEventListener('select', () => {
          setCaretToEnd(getRaw().length);
        });

        document.addEventListener('selectionchange', () => {
          if (document.activeElement === input) {
            setCaretToEnd(getRaw().length);
          }
        });

        input.addEventListener('pointerdown', (e) => {
          e.preventDefault();
          input.focus({ preventScroll: true });
          setCaretToEnd(getRaw().length);
        });

        // Klick ins Feld: immer “Eingabemodus” + Cursor zur nächsten Stelle
        input.addEventListener('focus', () => {
          setRaw(getRaw());
        });

        input.addEventListener('click', () => {
          setTimeout(() => {
            setRaw(getRaw());
          }, 0);
        });

        input.addEventListener('blur', () => {
          setTimeout(() => {
            ensureInputFocus();
          }, 0);
        });

        document.addEventListener('pointerdown', (event) => {
          if (event.target !== input) {
            setTimeout(() => {
              ensureInputFocus();
            }, 0);
          }
        });

        document.addEventListener('visibilitychange', () => {
          if (!document.hidden) {
            setTimeout(() => {
              ensureInputFocus();
            }, 0);
          }
        });

        // Init
        const initialRaw = cleanRaw(hidden.value);
        setRaw(initialRaw);

        function stopQrScan() {
          qrScanActive = false;
          if (qrScanTimer) {
            clearInterval(qrScanTimer);
            qrScanTimer = null;
          }
          if (qrStream) {
            qrStream.getTracks().forEach((track) => track.stop());
            qrStream = null;
          }
          if (qrScanVideo) {
            qrScanVideo.srcObject = null;
          }
          if (qrScanPanel) {
            qrScanPanel.classList.remove('active');
          }
          if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
          }
          if (document.webkitFullscreenElement) {
            document.webkitExitFullscreen();
          }
          ensureInputFocus();
        }

        function playQrBeep() {
          if (!window.AudioContext && !window.webkitAudioContext) return;
          if (!qrAudioCtx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            qrAudioCtx = new Ctx();
          }
          if (qrAudioCtx.state === 'suspended') {
            qrAudioCtx.resume();
          }
          const osc = qrAudioCtx.createOscillator();
          const gain = qrAudioCtx.createGain();
          osc.type = 'sine';
          osc.frequency.value = 880;
          gain.gain.value = 0.12;
          osc.connect(gain);
          gain.connect(qrAudioCtx.destination);
          osc.start();
          setTimeout(() => {
            osc.stop();
            osc.disconnect();
            gain.disconnect();
          }, 120);
        }

        function handleQrPayload(payload) {
          if (!payload) return;

          const trimmed = payload.trim();
          const codeMatch = trimmed.replace(/[^A-Z0-9]/gi, '').toUpperCase();
          const codeCandidate = codeMatch.length === 8 ? codeMatch : '';

          if (codeCandidate) {
            playQrBeep();
            setRaw(codeCandidate);
            stopQrScan();
            input.focus({ preventScroll: true });
            return;
          }

          const urlCandidate = (() => {
            try {
              return new URL(trimmed);
            } catch (e) {
              return null;
            }
          })();

          if (urlCandidate) {
            const sameOrigin = urlCandidate.origin === window.location.origin;
            const token = urlCandidate.searchParams.get('token');
            if (token && sameOrigin) {
              playQrBeep();
              window.location.href = urlCandidate.toString();
              return;
            }
          }

          qrScanHint.textContent = msgQrInvalidPayload;
        }

        async function startQrScan() {
          if (qrScanPanel) {
            qrScanPanel.classList.add('active');
          }
          if (qrScanHint) {
            qrScanHint.textContent = msgQrOpening;
          }
          const getUserMedia = navigator.mediaDevices?.getUserMedia
            ? navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices)
            : null;
          if (!getUserMedia) {
            qrScanHint.textContent = window.isSecureContext
              ? msgQrDeviceUnsupported
              : msgQrRequiresHttps;
            return;
          }

          const hasBarcodeDetector = 'BarcodeDetector' in window;
          const hasJsQr = typeof window.jsQR === 'function';
          if (!hasBarcodeDetector && !hasJsQr) {
            qrScanHint.textContent = msgQrUnsupportedBrowser;
            return;
          }
          
          qrDetector = null;

          if (hasBarcodeDetector) {
            const supported = await window.BarcodeDetector.getSupportedFormats();
            if (!supported.includes('qr_code')) {
              qrScanHint.textContent = msgQrNotAvailable;
              return;
            }
            qrDetector = new window.BarcodeDetector({ formats: ['qr_code'] });
          }

          try {
            qrStream = await getUserMedia({
              video: { facingMode: { ideal: 'environment' } },
              audio: false
            });
          } catch (e) {
            qrScanHint.textContent = msgQrPermissionDenied;
            return;
          }

          qrScanVideo.srcObject = qrStream;
          qrScanVideo.muted = true;
          qrScanVideo.setAttribute('playsinline', '');
          qrScanVideo.setAttribute('webkit-playsinline', '');
          
          await qrScanVideo.play();
          if (qrScanVideo.requestFullscreen) {
            qrScanVideo.requestFullscreen().catch(() => {});
          } else if (qrScanVideo.webkitRequestFullscreen) {
            qrScanVideo.webkitRequestFullscreen();
          }
          qrScanActive = true;
          qrScanHint.textContent = msgQrActiveHint;

          qrScanTimer = setInterval(async () => {
            if (!qrScanActive) return;
            try {
              if (qrDetector) {
                const results = await qrDetector.detect(qrScanVideo);
                if (results && results.length > 0) {
                  handleQrPayload(results[0].rawValue || results[0].data);
                }
                return;
              }

              if (!qrCanvas) {
                qrCanvas = document.createElement('canvas');
                qrCanvasCtx = qrCanvas.getContext('2d', { willReadFrequently: true });
              }
              const width = qrScanVideo.videoWidth;
              const height = qrScanVideo.videoHeight;
              if (!width || !height || !qrCanvasCtx) return;
              qrCanvas.width = width;
              qrCanvas.height = height;
              qrCanvasCtx.drawImage(qrScanVideo, 0, 0, width, height);
              const imageData = qrCanvasCtx.getImageData(0, 0, width, height);
              const result = window.jsQR(imageData.data, width, height);
              if (result && result.data) {
                handleQrPayload(result.data);
              }
            } catch (e) {
              qrScanHint.textContent = msgQrScanFailed;
            }
          }, 350);
        }

        if (qrScanButton) {
          qrScanButton.addEventListener('click', () => {
            if (qrScanActive) {
              stopQrScan();
              return;
            }
            startQrScan().catch((err) => {
              if (qrScanPanel) {
                qrScanPanel.classList.add('active');
              }
              if (qrScanHint) {
                qrScanHint.textContent = msgQrCameraFailed;
              }
              console.error('QR scan start failed', err);
            });
          });
        }

        if (qrScanStop) {
          qrScanStop.addEventListener('click', () => {
            stopQrScan();
          });
        }

        window.addEventListener('beforeunload', () => {
          stopQrScan();
        });

        setTimeout(() => {
          ensureInputFocus();
          setRaw(getRaw());
        }, 0);
      });
      </script>
    </div>
  </div>
<?php render_history_replace_state_script(); ?>
</body>
</html>
