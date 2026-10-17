/* Fast Deploy client transport. Files go only to the API-issued signed URLs. */
(function (root) {
  'use strict';

  const MAX_BACKUP_BYTES = 50 * 1024 * 1024 * 1024;
  const PART_ATTEMPTS = 3;
  const PART_TIMEOUT_MS = 60000;
  const MAX_MULTIPART_PARTS = 10000;
  const ACTIVE = new Set(['uploading', 'queued', 'parsing', 'provisioning', 'importing', 'validating', 'syncing']);
  const HUMAN = new Set(['discovery_review', 'awaiting_approval', 'cutover_pending']);

  function quoteEtag(value) {
    const clean = String(value || '').trim();
    return clean.startsWith('"') ? clean : `"${clean}"`;
  }

  function bytes(value) {
    if (!Number.isFinite(value) || value < 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let index = 0;
    while (value >= 1024 && index < units.length - 1) { value /= 1024; index += 1; }
    return `${value >= 10 || index === 0 ? Math.round(value) : value.toFixed(1)} ${units[index]}`;
  }

  function phaseMeta(phase, copy) {
    const values = {
      draft: ['Ready to send backup', 0], uploading: ['Uploading backup', 5], queued: ['Migration queued', 12],
      parsing: ['Analyzing backup', 20], discovery_review: ['Review found domain', 20],
      provisioning: ['Preparing hosting', 35], importing: ['Importing backup', 50], validating: ['Checking migration', 72],
      awaiting_approval: ['Ready for your review', 80], syncing: ['Finishing migration', 87],
      cutover_pending: ['Waiting for domain activation', 100], cutover_stalled: ['Domain needs attention', 100],
      live: ['Migration complete', 100], failed: ['Migration could not continue', null], cancelled: ['Migration cancelled', null],
    };
    const result = values[phase] || ['Waiting for an update', null];
    return [copy && copy[`phase_${phase}`] ? copy[`phase_${phase}`] : result[0], result[1]];
  }

  function safePreviewUrl(value) {
    try { const url = new URL(value); return url.protocol === 'https:' ? url.href : ''; } catch (_) { return ''; }
  }

  function safeInternalUrl(value, origin) {
    if (typeof value !== 'string' || !value.trim() || ['undefined', 'null'].includes(value.trim().toLowerCase())) return '';
    const expectedOrigin = origin === undefined ? window.location.origin : origin;
    try { const url = new URL(value, expectedOrigin); return url.origin === expectedOrigin && (url.protocol === 'https:' || url.protocol === 'http:') ? url.href : ''; } catch (_) { return ''; }
  }

  function domainLabel(migration, copy) {
    const value = migration && (migration.primaryDomain || migration.requestedPrimaryDomain || migration.sourcePrimaryDomain);
    return typeof value === 'string' && value.trim() ? value.trim() : (copy.unknown || '');
  }

  function pollInterval(phase) {
    if (ACTIVE.has(phase)) return 2000;
    if (HUMAN.has(phase)) return 15000;
    return false;
  }

  /** A fresh, browser-cryptographic intent key for idempotent customer actions. */
  function operationId(cryptoApi) {
    const source = cryptoApi === undefined ? globalThis.crypto : cryptoApi;
    if (!source || typeof source.getRandomValues !== 'function') {
      throw new Error('Your browser cannot create the secure identifier required for this action. Refresh the page and try again.');
    }
    const bytes = new Uint8Array(16);
    source.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (part) => part.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  function validateUploadPlan(file, plan) {
    if (!file || !plan || !Number.isInteger(plan.partSize) || plan.partSize <= 0 || !Array.isArray(plan.parts)) throw new Error('Invalid upload plan.');
    const expected = Math.ceil(file.size / plan.partSize);
    if (expected < 1 || expected > MAX_MULTIPART_PARTS || plan.parts.length !== expected) throw new Error('The secure upload plan is incomplete. Start the upload again.');
    const seen = new Set();
    plan.parts.forEach((part, index) => {
      if (!part || !Number.isInteger(part.partNumber) || part.partNumber !== index + 1 || seen.has(part.partNumber)) throw new Error('The secure upload plan has invalid file parts.');
      const url = safePreviewUrl(part.url);
      if (!url) throw new Error('The secure upload URL is invalid.');
      seen.add(part.partNumber);
    });
    return true;
  }

  function formBody(token, requestUuid, operation, payload) {
    const body = new URLSearchParams({ token: String(token), request: String(requestUuid), operation: String(operation) });
    Object.entries(payload || {}).forEach(([key, value]) => {
      if (value === undefined || value === null) return;
      body.set(key === 'parts' ? 'parts_json' : key, key === 'parts' ? JSON.stringify(value) : String(value));
    });
    return body;
  }

  function request(endpoint, token, requestUuid, operation, payload) {
    return fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
      body: formBody(token, requestUuid, operation, payload),
    }).then(async (response) => {
      let body;
      try { body = await response.json(); } catch (_) { throw new Error('Unable to read the server response.'); }
      if (!response.ok || !body || body.ok !== true) throw new Error((body && body.error) || 'This action could not be completed.');
      return body.data;
    });
  }

  function isDetail(value) {
    return Boolean(value && typeof value === 'object' && value.requestUuid && value.migration && value.commercial && value.actions);
  }

  function policyLabel(policy, copy) {
    if (!policy || !policy.mode) return '';
    const label = copy[`policy_${policy.mode}`] || '';
    return policy.mode === 'trial' ? label.replace('{days}', String(policy.trialDays || '')) : label;
  }

  function uploadErrorMessage(error, copy) {
    if (error && error.code && copy[error.code]) return copy[error.code];
    return error && error.message ? error.message : (copy.upload_error || '');
  }

  function putPart(part, blob, onProgress, signal) {
    return new Promise((resolve, reject) => {
      if (signal && signal.aborted) { reject(new Error('Upload cancelled')); return; }
      const xhr = new XMLHttpRequest();
      const abort = () => xhr.abort();
      if (signal) signal.addEventListener('abort', abort, { once: true });
      const cleanup = () => signal && signal.removeEventListener('abort', abort);
      xhr.open('PUT', part.url, true);
      xhr.timeout = PART_TIMEOUT_MS;
      xhr.upload.onprogress = (event) => { if (event.lengthComputable) onProgress(event.loaded); };
      xhr.onload = () => {
        cleanup();
        if (xhr.status >= 200 && xhr.status < 300) {
          const etag = xhr.getResponseHeader('ETag');
          if (!etag) { reject(new Error('The storage service did not return a file confirmation.')); return; }
          onProgress(blob.size); resolve(quoteEtag(etag)); return;
        }
        const error = new Error(`Part upload failed (${xhr.status})`);
        error.restart = xhr.status === 401 || xhr.status === 403;
        reject(error);
      };
      xhr.onerror = () => { cleanup(); const error = new Error('Network error while sending the file.'); error.code = 'network_error'; reject(error); };
      xhr.ontimeout = () => { cleanup(); const error = new Error('The connection timed out while sending the file.'); error.code = 'upload_timeout'; reject(error); };
      xhr.onabort = () => { cleanup(); reject(new Error('Upload cancelled')); };
      xhr.send(blob);
    });
  }

  async function uploadMultipart(file, plan, callbacks, signal) {
    validateUploadPlan(file, plan);
    let completedBytes = 0;
    const done = [];
    const parts = plan.parts.slice().sort((a, b) => a.partNumber - b.partNumber);
    for (let index = 0; index < parts.length; index += 1) {
      const part = parts[index];
      const start = (part.partNumber - 1) * plan.partSize;
      const blob = file.slice(start, Math.min(start + plan.partSize, file.size));
      if (!blob.size) throw new Error('Invalid file part.');
      let lastError;
      for (let attempt = 1; attempt <= PART_ATTEMPTS; attempt += 1) {
        if (signal && signal.aborted) throw new Error('Upload cancelled');
        callbacks.activity && callbacks.activity({ part: index + 1, count: parts.length, attempt: attempt });
        try {
          const etag = await putPart(part, blob, (loaded) => callbacks.progress && callbacks.progress(Math.min(file.size, completedBytes + loaded), file.size, index + 1, parts.length), signal);
          completedBytes += blob.size;
          done.push({ partNumber: part.partNumber, etag: etag });
          callbacks.progress && callbacks.progress(completedBytes, file.size, index + 1, parts.length);
          lastError = null;
          break;
        } catch (error) {
          lastError = error;
          if ((signal && signal.aborted) || error.restart) throw error;
          if (attempt < PART_ATTEMPTS) await new Promise((resolve) => setTimeout(resolve, 300 * attempt));
        }
      }
      if (lastError) throw lastError;
    }
    return done;
  }

  function init(element) {
    let detail;
    try { detail = JSON.parse(element.getAttribute('data-detail') || '{}'); } catch (_) { return; }
    let copy;
    try { copy = JSON.parse(element.getAttribute('data-copy') || '{}'); } catch (_) { copy = {}; }
    const endpoint = element.getAttribute('data-endpoint') || window.location.href;
    const token = element.getAttribute('data-token') || '';
    const requestUuid = detail.requestUuid || '';
    if (!requestUuid || !token) return;
    let file = null;
    let abortController = null;
    let polling = null;
    const q = (selector) => element.querySelector(selector);
    const qa = (selector) => Array.prototype.slice.call(element.querySelectorAll(selector));
    const error = q('[data-flow-error]');
    const status = q('[data-flow-status]');

    function showError(message) { if (error) { error.textContent = message; error.hidden = !message; } }
    function busy(value) { qa('button').forEach((button) => { if (button.dataset.flowRefresh === undefined && button.dataset.flowOperation !== 'cancel') button.disabled = value; }); }
    function refresh(next) {
      detail = next;
      const migration = detail.migration || {};
      const actions = detail.actions || {};
      const phase = migration.phase || 'draft';
      const meta = phaseMeta(phase, copy);
      const phaseElement = q('[data-flow-phase]'); if (phaseElement) phaseElement.textContent = meta[0];
      const title = q('[data-flow-title]'); if (title) title.textContent = meta[0];
      const description = q('[data-flow-description]'); if (description) description.textContent = copy[`phase_help_${phase}`] || copy.waiting || '';
      const phaseStep = { draft: 1, uploading: 1, queued: 2, parsing: 2, discovery_review: 3, provisioning: 4, importing: 4, validating: 4, awaiting_approval: 4, syncing: 5, cutover_pending: 6, cutover_stalled: 6, live: 6, failed: 0, cancelled: 0 }[phase] || 0;
      qa('.bcp-flow-steps li').forEach((step, index) => { const number = index + 1; step.classList.toggle('is-current', number === phaseStep); step.classList.toggle('is-done', phaseStep > number); if (number === phaseStep) step.setAttribute('aria-current', 'step'); else step.removeAttribute('aria-current'); });
      const upload = q('[data-flow-upload]'); if (upload) upload.hidden = !actions.upload;
      const confirm = q('[data-flow-confirm-upload]'); if (confirm) confirm.hidden = !actions.confirmUpload;
      const review = q('[data-flow-review]'); if (review) review.hidden = !actions.review;
      const staging = q('[data-flow-staging]'); const url = safePreviewUrl(migration.stagingUrl); if (staging) { staging.hidden = !url; if (url) staging.href = url; }
      ['approve', 'cancel', 'retry'].forEach((name) => { const button = q(`[data-flow-operation="${name}"]`); if (button) button.hidden = !actions[name]; });
      const domain = domainLabel(migration, copy);
      const summaries = qa('[data-flow-summary] dd'); if (summaries[0]) summaries[0].textContent = domain; if (summaries[1]) summaries[1].textContent = copy[`commercial_${(detail.commercial || {}).state}`] || copy.unknown || '';
      const commercial = q('[data-flow-commercial]');
      if (commercial) {
        commercial.textContent = '';
        const record = detail.commercial || {};
        const policy = policyLabel(record.policy, copy);
        if (policy) { const badge = document.createElement('p'); badge.className = 'bcp-flow-commercial-policy'; badge.textContent = policy; commercial.appendChild(badge); }
        [['dueAt', copy.expires], ['graceEndsAt', copy.grace], ['retentionEndsAt', copy.retention]].forEach(([key, label]) => {
          if (!record[key]) return;
          const row = document.createElement('p'); row.textContent = `${label}: ${record[key]}`; commercial.appendChild(row);
        });
        const invoiceUrl = safeInternalUrl(record.invoiceUrl);
        if (invoiceUrl) { const link = document.createElement('a'); link.href = invoiceUrl; link.textContent = copy.invoice || 'Open invoice'; commercial.appendChild(link); }
      }
      const components = q('[data-flow-components]'); const list = components && components.querySelector('ul');
      if (components && list) { list.textContent = ''; (migration.components || []).forEach((component) => { const li = document.createElement('li'); const name = copy[`component_${component.name}`] || copy.unknown || ''; const state = copy[`status_${component.status}`] || copy.unknown || ''; li.textContent = `${name}: ${state}`; list.appendChild(li); }); components.hidden = !list.children.length; }
      const discovery = q('[data-flow-discovery]'); const dl = discovery && discovery.querySelector('dl');
      if (discovery && dl) { dl.textContent = ''; const facts = migration.discovery || {}; [['discovery_php', facts.phpVersion], ['discovery_database', [facts.databaseEngine, facts.databaseVersion].filter(Boolean).join(' ')], ['discovery_files', facts.fileCount], ['discovery_size', facts.fileBytes == null ? '' : bytes(facts.fileBytes)], ['discovery_emails', facts.emailAccountCount], ['discovery_email_history', facts.emailHistoryBytes == null ? '' : bytes(facts.emailHistoryBytes)], ['discovery_multisite', Array.isArray(facts.multisiteDomains) ? facts.multisiteDomains.join(', ') : '']].forEach(([labelKey, value]) => { if (value === null || value === undefined || value === '') return; const row = document.createElement('div'); const term = document.createElement('dt'); term.textContent = copy[labelKey] || ''; const definition = document.createElement('dd'); definition.textContent = String(value); row.appendChild(term); row.appendChild(definition); dl.appendChild(row); }); discovery.hidden = !dl.children.length; }
      setPolling(phase);
      maybeReconcile(phase, actions);
    }
    function poll() { return fetch(`${endpoint}&format=json&request=${encodeURIComponent(requestUuid)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(async (response) => { const body = await response.json(); if (!response.ok || !body || body.ok !== true) throw new Error((body && body.error) || 'Could not refresh migration.'); refresh(body.data); }).catch((message) => showError(message.message)); }
    function setPolling(phase) { if (polling) { window.clearTimeout(polling); polling = null; } const delay = pollInterval(phase); if (abortController || !delay) return; polling = window.setTimeout(() => { poll().finally(() => setPolling((detail.migration || {}).phase)); }, delay); }
    const reconciled = new Set();
    function maybeReconcile(phase, actions) {
      const commercial = detail.commercial || {};
      const key = `${phase}:${commercial.state || ''}`;
      if (!actions.reconcile || reconciled.has(key)) return;
      reconciled.add(key);
      request(endpoint, token, requestUuid, 'reconcile').then((data) => { if (isDetail(data)) refresh(data); else return poll(); }).catch(() => {
        showError(copy.reconcile_error || 'We could not update the purchase status. Try again.');
        const retry = q('[data-flow-operation="reconcile"]'); if (retry) retry.hidden = false;
      });
    }
    function operation(name, payload) { showError(''); busy(true); return request(endpoint, token, requestUuid, name, payload).then((data) => { if (isDetail(data)) { refresh(data); return data; } return poll(); }).catch((message) => { showError(message.message); throw message; }).finally(() => busy(false)); }
    function choose(next) {
      if (!next || next.size <= 0 || next.size > MAX_BACKUP_BYTES) { showError(next && next.size > MAX_BACKUP_BYTES ? 'The backup exceeds the 50 GB limit.' : 'Choose a non-empty backup file.'); return; }
      file = next; showError('');
      const name = q('[data-flow-file-name]'); if (name) name.textContent = `${next.name} (${bytes(next.size)})`;
      const meta = q('[data-flow-upload-meta]'); if (meta) meta.hidden = false;
      const start = q('[data-flow-start-upload]'); if (start) start.disabled = false;
    }
    const input = q('[data-flow-file]'); if (input) input.addEventListener('change', () => choose(input.files && input.files[0]));
    const drop = q('[data-flow-drop]'); if (drop) ['dragenter', 'dragover'].forEach((event) => drop.addEventListener(event, (e) => { e.preventDefault(); drop.classList.add('is-dragging'); }));
    if (drop) ['dragleave', 'drop'].forEach((event) => drop.addEventListener(event, (e) => { e.preventDefault(); drop.classList.remove('is-dragging'); if (event === 'drop') choose(e.dataTransfer.files && e.dataTransfer.files[0]); }));
    const remove = q('[data-flow-remove-file]'); if (remove) remove.addEventListener('click', () => { file = null; if (input) input.value = ''; q('[data-flow-upload-meta]').hidden = true; q('[data-flow-start-upload]').disabled = true; });
    const start = q('[data-flow-start-upload]'); if (start) start.addEventListener('click', async () => {
      if (!file || abortController) return; showError(''); busy(true); abortController = new AbortController();
      const progress = q('[data-flow-progress]'); if (progress) progress.hidden = false;
      try {
        const intent = operationId();
        const plan = await request(endpoint, token, requestUuid, 'upload-url', { sizeBytes: file.size, filename: file.name, operation_id: intent });
        const parts = await uploadMultipart(file, plan, { progress: (loaded, total, current, count) => { const percent = Math.min(99, Math.round((loaded / total) * 100)); q('[data-flow-progress-bar]').value = percent; q('[data-flow-progress-text]').textContent = `${percent}%`; q('[data-flow-part]').textContent = `${current}/${count} · ${bytes(loaded)} / ${bytes(total)}`; } }, abortController.signal);
        const updated = await request(endpoint, token, requestUuid, 'complete-upload', { parts: parts });
        if (isDetail(updated)) refresh(updated); else await poll();
      } catch (message) { showError(uploadErrorMessage(message, copy)); } finally { abortController = null; busy(false); setPolling((detail.migration || {}).phase); }
    });
    qa('[data-flow-operation]').forEach((button) => button.addEventListener('click', () => { const name = button.getAttribute('data-flow-operation'); if (name === 'cancel') { if (!window.confirm(copy.confirm_cancel || 'Cancel this migration?')) return; if (abortController) abortController.abort(); } let payload = name === 'discovery-review' ? { primaryDomain: (q('[data-flow-domain]') || {}).value || '' } : {}; if (name === 'retry') { try { payload = { operation_id: operationId() }; } catch (message) { showError(message.message); return; } } operation(name, payload).catch(() => {}); }));
    const refreshButton = q('[data-flow-refresh]'); if (refreshButton) refreshButton.addEventListener('click', () => poll());
    refresh(detail);
  }

  const exposed = { MAX_BACKUP_BYTES, PART_ATTEMPTS, PART_TIMEOUT_MS, quoteEtag, phaseMeta, pollInterval, operationId, validateUploadPlan, uploadMultipart, isDetail, policyLabel, safeInternalUrl, domainLabel, formBody, uploadErrorMessage };
  if (typeof module !== 'undefined' && module.exports) module.exports = exposed;
  if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-fast-deploy-flow]').forEach(init));
})(typeof window === 'undefined' ? globalThis : window);
