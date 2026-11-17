(function () {
  'use strict';

  const configNode = document.querySelector('[data-session-guard-config]');
  if (!configNode || window.LEBSessionGuard) return;

  let config;
  try {
    config = JSON.parse(configNode.textContent || '{}');
  } catch (_) {
    return;
  }

  const nativeFetch = window.fetch.bind(window);
  const nativeFormSubmit = HTMLFormElement.prototype.submit;
  const i18n = config.i18n || {};
  let csrfToken = String(config.csrfToken || '');
  let remainingSeconds = Number(config.idleRemainingSeconds || 0);
  let timeoutSeconds = Number(config.idleTimeoutSeconds || 0);
  let warningSeconds = Number(config.warningSeconds || 300);
  let lastStateAt = Date.now();
  let reauthToken = String(config.reauthToken || '');
  let reauthPromise = null;
  let resolveReauth = null;
  let expiryCheckPending = false;
  const pendingForms = new WeakSet();

  const style = document.createElement('style');
  style.dataset.sessionGuardStyle = '1';
  style.textContent = `
    .leb-session-warning{position:fixed;right:18px;bottom:18px;z-index:99990;width:min(390px,calc(100vw - 36px));padding:16px;border:1px solid #f0b429;border-radius:14px;background:#fff8df;color:#352b05;box-shadow:0 14px 38px rgba(15,23,42,.22);font:14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    .leb-session-warning[hidden],.leb-session-overlay[hidden],.leb-session-toast[hidden]{display:none!important}
    .leb-session-warning strong{display:block;margin-bottom:4px;font-size:16px}.leb-session-warning-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}
    .leb-session-button{appearance:none;border:0;border-radius:9px;padding:9px 13px;background:#0b57d0;color:#fff;font:600 14px/1.2 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer}.leb-session-button:disabled{cursor:wait;opacity:.7}
    .leb-session-overlay{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.62);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    .leb-session-dialog{width:min(470px,100%);padding:24px;border-radius:16px;background:#fff;color:#111827;box-shadow:0 24px 70px rgba(0,0,0,.3)}.leb-session-dialog h2{margin:0 0 8px;font-size:22px}.leb-session-dialog p{margin:0 0 16px}.leb-session-dialog label{display:block;margin:12px 0 5px;font-weight:650}.leb-session-dialog input{box-sizing:border-box;width:100%;min-height:42px;padding:9px 11px;border:1px solid #aeb8c8;border-radius:9px;background:#fff;color:#111827;font:inherit}.leb-session-dialog input[readonly]{background:#f3f5f8;color:#4b5563}.leb-session-error{min-height:22px;margin:10px 0;color:#b42318;font-weight:600}.leb-session-actions{display:flex;justify-content:flex-end;margin-top:12px}
    .leb-session-toast{position:fixed;left:50%;bottom:22px;z-index:100010;transform:translateX(-50%);padding:10px 14px;border-radius:999px;background:#173f2a;color:#fff;box-shadow:0 8px 25px rgba(15,23,42,.24);font:600 14px/1.2 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    @media (max-width:560px){.leb-session-warning{right:10px;bottom:10px;width:calc(100vw - 20px);box-sizing:border-box}.leb-session-dialog{padding:20px}}
  `;
  document.head.appendChild(style);

  const warning = document.createElement('section');
  warning.className = 'leb-session-warning';
  warning.hidden = true;
  warning.setAttribute('role', 'status');
  const warningTitle = document.createElement('strong');
  warningTitle.textContent = String(i18n.warningTitle || 'Session expires soon');
  const warningText = document.createElement('span');
  const warningRow = document.createElement('div');
  warningRow.className = 'leb-session-warning-row';
  const warningCountdown = document.createElement('span');
  const stayButton = document.createElement('button');
  stayButton.type = 'button';
  stayButton.className = 'leb-session-button';
  stayButton.textContent = String(i18n.staySignedIn || 'Stay signed in');
  warningRow.append(warningCountdown, stayButton);
  warning.append(warningTitle, warningText, warningRow);

  const overlay = document.createElement('div');
  overlay.className = 'leb-session-overlay';
  overlay.hidden = true;
  const dialog = document.createElement('div');
  dialog.className = 'leb-session-dialog';
  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');
  const dialogTitle = document.createElement('h2');
  dialogTitle.textContent = String(i18n.expiredTitle || 'Session expired');
  const dialogText = document.createElement('p');
  dialogText.textContent = String(i18n.expiredMessage || 'Sign in again. Your input will be preserved.');
  const reauthForm = document.createElement('form');
  reauthForm.dataset.sessionGuard = 'off';
  reauthForm.autocomplete = 'on';
  const accountLabel = document.createElement('label');
  accountLabel.textContent = String(i18n.account || 'Account');
  const accountInput = document.createElement('input');
  accountInput.id = 'lebSessionGuardAccount';
  accountLabel.htmlFor = accountInput.id;
  accountInput.type = 'text';
  accountInput.readOnly = true;
  accountInput.value = String(config.principalLabel || '');
  const credentialLabel = document.createElement('label');
  const credentialInput = document.createElement('input');
  credentialInput.id = 'lebSessionGuardCredential';
  credentialLabel.htmlFor = credentialInput.id;
  credentialInput.required = true;
  if (config.principalKind === 'student') {
    credentialLabel.textContent = String(i18n.loginCode || 'Login code');
    credentialInput.type = 'text';
    credentialInput.name = 'login_code';
    credentialInput.autocomplete = 'one-time-code';
    credentialInput.autocapitalize = 'characters';
  } else {
    credentialLabel.textContent = String(i18n.password || 'Password');
    credentialInput.type = 'password';
    credentialInput.name = 'password';
    credentialInput.autocomplete = 'current-password';
  }
  const reauthError = document.createElement('div');
  reauthError.className = 'leb-session-error';
  reauthError.setAttribute('role', 'alert');
  const actions = document.createElement('div');
  actions.className = 'leb-session-actions';
  const signInButton = document.createElement('button');
  signInButton.type = 'submit';
  signInButton.className = 'leb-session-button';
  signInButton.textContent = String(i18n.signIn || 'Continue');
  actions.appendChild(signInButton);
  reauthForm.append(accountLabel, accountInput, credentialLabel, credentialInput, reauthError, actions);
  dialog.append(dialogTitle, dialogText, reauthForm);
  overlay.appendChild(dialog);

  const toast = document.createElement('div');
  toast.className = 'leb-session-toast';
  toast.hidden = true;
  toast.setAttribute('role', 'status');
  document.body.append(warning, overlay, toast);

  let toastTimer = 0;
  function showToast(message) {
    window.clearTimeout(toastTimer);
    toast.textContent = String(message || '');
    toast.hidden = false;
    toastTimer = window.setTimeout(function () { toast.hidden = true; }, 2600);
  }

  function formatTime(totalSeconds) {
    const seconds = Math.max(0, Math.ceil(totalSeconds));
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return minutes + ':' + String(rest).padStart(2, '0');
  }

  function currentRemaining() {
    if (timeoutSeconds <= 0) return 0;
    return Math.max(0, remainingSeconds - Math.floor((Date.now() - lastStateAt) / 1000));
  }

  function updateCsrfInputs() {
    if (!csrfToken) return;
    document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
      input.value = csrfToken;
    });
  }

  function updateWarning() {
    const remaining = currentRemaining();
    if (timeoutSeconds <= 0 || remaining > warningSeconds || overlay.hidden === false) {
      warning.hidden = true;
      return;
    }
    warning.hidden = false;
    warningText.textContent = String(i18n.warningMessage || 'You will be signed out in {time}.').replace('{time}', formatTime(remaining));
    warningCountdown.textContent = formatTime(remaining);
    if (remaining <= 0) {
      warning.hidden = true;
      if (!expiryCheckPending) {
        expiryCheckPending = true;
        void checkNow({ interactive: true }).finally(function () { expiryCheckPending = false; });
      }
    }
  }

  function syncState(payload) {
    if (!payload || typeof payload !== 'object') return;
    if (payload.csrf_token) csrfToken = String(payload.csrf_token);
    if (payload.reauth_token) reauthToken = String(payload.reauth_token);
    if (Number.isFinite(Number(payload.idle_timeout_seconds))) timeoutSeconds = Number(payload.idle_timeout_seconds);
    if (Number.isFinite(Number(payload.idle_remaining_seconds))) remainingSeconds = Number(payload.idle_remaining_seconds);
    if (Number.isFinite(Number(payload.warning_seconds))) warningSeconds = Number(payload.warning_seconds);
    lastStateAt = Date.now();
    updateCsrfInputs();
    updateWarning();
  }

  async function parseJson(response) {
    try {
      return await response.clone().json();
    } catch (_) {
      return {};
    }
  }

  function isSessionFailure(response, payload) {
    const code = String(payload && (payload.error || payload.code) || '').toLowerCase();
    return response.status === 401
      || payload && payload.requires_login === true
      || code === 'session_expired'
      || code === 'csrf_failed'
      || code === 'login_required';
  }

  function finishReauthentication(payload) {
    syncState(payload);
    overlay.hidden = true;
    warning.hidden = true;
    credentialInput.value = '';
    reauthError.textContent = '';
    signInButton.disabled = false;
    signInButton.textContent = String(i18n.signIn || 'Continue');
    const resolver = resolveReauth;
    resolveReauth = null;
    reauthPromise = null;
    window.dispatchEvent(new CustomEvent('leb:session-restored', { detail: { csrfToken: csrfToken } }));
    if (resolver) resolver({ csrfToken: csrfToken });
  }

  function requestReauthentication() {
    if (reauthPromise) return reauthPromise;
    warning.hidden = true;
    overlay.hidden = false;
    reauthError.textContent = '';
    signInButton.disabled = true;
    signInButton.textContent = String(i18n.signingIn || 'Checking …');
    reauthPromise = new Promise(function (resolve) { resolveReauth = resolve; });
    void (async function () {
      try {
        const response = await nativeFetch(config.statusUrl, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const payload = await parseJson(response);
        syncState(payload);
        if (response.ok && payload.authenticated !== false) {
          finishReauthentication(payload);
          return;
        }
      } catch (_) {
        reauthError.textContent = String(i18n.connectionFailed || 'Connection could not be checked.');
      }
      signInButton.disabled = false;
      signInButton.textContent = String(i18n.signIn || 'Continue');
      credentialInput.focus();
    })();
    return reauthPromise;
  }

  async function checkNow(options) {
    const interactive = !options || options.interactive !== false;
    try {
      const response = await nativeFetch(config.statusUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await parseJson(response);
      syncState(payload);
      if (response.ok && payload.authenticated !== false) {
        if (!overlay.hidden) finishReauthentication(payload);
        return true;
      }
      if (isSessionFailure(response, payload) && interactive) {
        await requestReauthentication();
        return true;
      }
      return false;
    } catch (_) {
      if (interactive) showToast(i18n.connectionFailed || 'Connection could not be checked.');
      return false;
    }
  }

  async function staySignedIn() {
    stayButton.disabled = true;
    stayButton.textContent = String(i18n.extending || 'Extending …');
    try {
      const response = await nativeFetch(config.statusUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ action: 'keepalive' })
      });
      const payload = await parseJson(response);
      syncState(payload);
      if (!response.ok || payload.authenticated === false) {
        if (isSessionFailure(response, payload)) await requestReauthentication();
        return false;
      }
      warning.hidden = true;
      showToast(i18n.extended || 'Session extended.');
      return true;
    } catch (_) {
      showToast(i18n.connectionFailed || 'Connection could not be checked.');
      return false;
    } finally {
      stayButton.disabled = false;
      stayButton.textContent = String(i18n.staySignedIn || 'Stay signed in');
    }
  }

  stayButton.addEventListener('click', function () { void staySignedIn(); });

  reauthForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    reauthError.textContent = '';
    signInButton.disabled = true;
    signInButton.textContent = String(i18n.signingIn || 'Checking …');
    const payload = { reauth_token: reauthToken };
    if (config.principalKind === 'student') payload.login_code = credentialInput.value;
    else payload.password = credentialInput.value;
    try {
      const response = await nativeFetch(config.reauthUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      });
      const result = await parseJson(response);
      if (result.csrf_token) csrfToken = String(result.csrf_token);
      if (!response.ok || result.ok === false || result.authenticated === false) {
        reauthError.textContent = String(result.message || i18n.authFailed || 'Sign-in failed.');
        credentialInput.select();
        return;
      }
      finishReauthentication(result);
      showToast(i18n.extended || 'Session restored.');
    } catch (_) {
      reauthError.textContent = String(i18n.connectionFailed || 'Connection could not be checked.');
    } finally {
      signInButton.disabled = false;
      signInButton.textContent = String(i18n.signIn || 'Continue');
    }
  });

  function requestUrl(request) {
    try { return new URL(request.url, window.location.href); } catch (_) { return null; }
  }

  function isGuardEndpoint(url) {
    return url && (url.href === new URL(config.statusUrl, window.location.href).href
      || url.href === new URL(config.reauthUrl, window.location.href).href);
  }

  async function requestWithFreshCsrf(request) {
    const method = String(request.method || 'GET').toUpperCase();
    if (method === 'GET' || method === 'HEAD') return request.clone();
    const headers = new Headers(request.headers);
    headers.set('X-CSRF-Token', csrfToken);
    const contentType = String(headers.get('Content-Type') || '').toLowerCase();
    let body;
    try {
      if (contentType.includes('application/json')) {
        const text = await request.clone().text();
        const value = text ? JSON.parse(text) : {};
        if (value && typeof value === 'object' && !Array.isArray(value)) value.csrf_token = csrfToken;
        body = JSON.stringify(value);
      } else if (contentType.includes('application/x-www-form-urlencoded')) {
        const params = new URLSearchParams(await request.clone().text());
        params.set('csrf_token', csrfToken);
        body = params;
      } else if (contentType.includes('multipart/form-data')) {
        const formData = await request.clone().formData();
        formData.set('csrf_token', csrfToken);
        headers.delete('Content-Type');
        body = formData;
      } else {
        body = await request.clone().blob();
      }
      return new Request(request, { headers: headers, body: body });
    } catch (_) {
      return new Request(request, { headers: headers });
    }
  }

  window.fetch = async function (input, init) {
    let request;
    try {
      request = new Request(input, init);
    } catch (_) {
      return nativeFetch(input, init);
    }
    const url = requestUrl(request);
    if (!url || url.origin !== window.location.origin || isGuardEndpoint(url)) {
      return nativeFetch(request);
    }
    const response = await nativeFetch(request.clone());
    let redirectedToLogin = false;
    if (response.redirected && response.url) {
      try {
        redirectedToLogin = /\/(?:student\/)?login\.php$/i.test(new URL(response.url).pathname);
      } catch (_) {}
    }
    if (response.status !== 401 && response.status !== 403 && !redirectedToLogin) return response;
    const payload = redirectedToLogin
      ? { authenticated: false, requires_login: true, error: 'session_expired' }
      : await parseJson(response);
    if (!isSessionFailure(response, payload)) return response;
    syncState(payload);
    await requestReauthentication();
    return nativeFetch(await requestWithFreshCsrf(request));
  };

  function submitFormNatively(form, submitter) {
    const restore = [];
    let submitterField = null;
    if (submitter && submitter.form === form) {
      const overrides = [
        ['formaction', 'action'],
        ['formmethod', 'method'],
        ['formenctype', 'enctype'],
        ['formtarget', 'target']
      ];
      overrides.forEach(function (entry) {
        const attribute = entry[0];
        const property = entry[1];
        if (!submitter.hasAttribute(attribute)) return;
        restore.push([property, form[property]]);
        form[property] = submitter.getAttribute(attribute) || '';
      });
      if (submitter.name && !submitter.disabled) {
        submitterField = document.createElement('input');
        submitterField.type = 'hidden';
        submitterField.name = submitter.name;
        submitterField.value = submitter.value;
        form.appendChild(submitterField);
      }
    }
    try {
      nativeFormSubmit.call(form);
    } finally {
      if (submitterField) submitterField.remove();
      restore.forEach(function (entry) { form[entry[0]] = entry[1]; });
    }
  }

  async function guardForm(form, submitter) {
    if (pendingForms.has(form)) return;
    pendingForms.add(form);
    try {
      const authenticated = await checkNow({ interactive: true });
      if (!authenticated) return;
      updateCsrfInputs();
      submitFormNatively(form, submitter);
    } finally {
      pendingForms.delete(form);
    }
  }

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.sessionGuard === 'off' || String(form.method || 'get').toLowerCase() !== 'post') return;
    const action = new URL(form.action || window.location.href, window.location.href);
    if (action.origin !== window.location.origin) return;
    // A page-specific submit handler already owns the request (usually via
    // fetch). The global fetch wrapper protects that request, so redispatching
    // the submit event here would execute the action twice.
    if (event.defaultPrevented) return;
    event.preventDefault();
    void guardForm(form, event.submitter || null);
  });

  HTMLFormElement.prototype.submit = function () {
    const form = this;
    if (form.dataset.sessionGuard === 'off' || String(form.method || 'get').toLowerCase() !== 'post') {
      nativeFormSubmit.call(form);
      return;
    }
    void guardForm(form, null);
  };

  window.LEBSessionGuard = {
    checkNow: checkNow,
    staySignedIn: staySignedIn,
    requireReauthentication: requestReauthentication,
    getCsrfToken: function () { return csrfToken; }
  };

  window.setInterval(updateWarning, 1000);
  window.setInterval(function () {
    if (document.visibilityState === 'visible') void checkNow({ interactive: true });
  }, Math.max(15, Number(config.pollSeconds || 30)) * 1000);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') void checkNow({ interactive: true });
  });
  window.addEventListener('focus', function () { void checkNow({ interactive: true }); });
  updateCsrfInputs();
  updateWarning();
})();
