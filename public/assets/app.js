import { formatCents, parseDecimal } from './money.js';
import {
  ACCENTS, LIMITS, addDays, blankField, blankInvoice, blankItem, evaluate, formatDate,
  isEmail, renderPreview, sampleInvoice,
} from './invoice.js';

// ------------------------------------------------------------------ state

const state = {
  invoice: blankInvoice(),
  id: null,              // database id once saved
  snapshot: null,        // JSON of the last saved/loaded version, for "unsaved changes"
  ev: null,              // latest evaluate() result: { errors, totals, lineTotals, subtotal }
  touched: new Set(),    // field paths the user has left at least once
  showAll: false,        // after a save attempt, show every error
  serverErrors: {},      // errors returned by the API, keyed like client paths
  saving: false,
  mail: null,            // { transport, description, from }
};

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const els = {
  editor: $('#editor'),
  items: $('#items'),
  fields: $('#custom-fields'),
  fieldsEmpty: $('#fields-empty'),
  preview: $('#preview'),
  summary: $('#summary'),
  errorSummary: $('#error-summary'),
  status: $('#status-chip'),
  docNumber: $('#doc-number'),
  save: $('#btn-save'),
  listDialog: $('#dlg-list'),
  listBody: $('#list-body'),
  emailDialog: $('#dlg-email'),
  emailForm: $('#email-form'),
  toasts: $('#toasts'),
  workspace: $('.workspace'),
};

// ------------------------------------------------------------------ API

async function api(path, { method = 'GET', body } = {}) {
  let res;
  try {
    res = await fetch(`api/${path}`, {
      method,
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw Object.assign(new Error('Can’t reach the server. Check your connection and try again.'), { status: 0 });
  }
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON error page */ }
  if (!res.ok) {
    throw Object.assign(new Error(data?.error ?? `Request failed (${res.status}).`), { status: res.status, data });
  }
  return data;
}

// ------------------------------------------------------------------ helpers

function getPath(obj, path) {
  return path.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);
}

function setPath(obj, path, value) {
  const keys = path.split('.');
  const last = keys.pop();
  keys.reduce((o, k) => o[k], obj)[last] = value;
}

/** Format a valid money string to 2 decimals without floating point ("50" -> "50.00"). */
function tidyMoney(text) {
  const cents = parseDecimal(text, 2);
  if (cents === null) return text;
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

function toast(message, { type = 'info', timeout = 4200 } = {}) {
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<svg class="icon" aria-hidden="true"><use href="#${type === 'error' ? 'i-alert' : 'i-check'}"/></svg><span></span>`;
  el.querySelector('span').textContent = message;
  els.toasts.append(el);
  setTimeout(() => el.remove(), timeout);
}

const isDirty = () => JSON.stringify(state.invoice) !== state.snapshot;

/** Normalise invoice data coming from the server so every key the UI expects exists. */
function hydrate(data) {
  const base = blankInvoice();
  return {
    ...base,
    ...data,
    business: { ...base.business, ...(data.business ?? {}) },
    customer: { ...base.customer, ...(data.customer ?? {}) },
    items: (data.items ?? []).map((it) => ({ ...blankItem(), ...it })),
    customFields: (data.customFields ?? []).map((f) => ({ ...blankField(), ...f })),
  };
}

// ------------------------------------------------------------------ rendering: rows

function renderItems() {
  els.items.innerHTML = state.invoice.items.map((item, i) => {
    const n = i + 1;
    const id = `it-${item.id}`;
    return `<li class="item-row" data-id="${esc(item.id)}">
      <div class="cell cell-desc">
        <label class="mobile-label" for="${id}-d">Description</label>
        <input id="${id}-d" data-item="description" data-path="items.${esc(item.id)}.description" value="${esc(item.description)}"
          aria-label="Description, line ${n}" placeholder="What did you provide?" maxlength="300" autocomplete="off">
      </div>
      <div class="cell cell-qty">
        <label class="mobile-label" for="${id}-q">Qty</label>
        <input id="${id}-q" data-item="quantity" data-path="items.${esc(item.id)}.quantity" value="${esc(item.quantity)}"
          aria-label="Quantity, line ${n}" inputmode="decimal" class="num-input num" autocomplete="off">
      </div>
      <div class="cell cell-price">
        <label class="mobile-label" for="${id}-p">Unit price</label>
        <div class="affix"><span class="affix-sym" aria-hidden="true">$</span>
          <input id="${id}-p" data-item="unitPrice" data-path="items.${esc(item.id)}.unitPrice" value="${esc(item.unitPrice)}"
            aria-label="Unit price in USD, line ${n}" inputmode="decimal" class="num-input num" placeholder="0.00" autocomplete="off">
        </div>
      </div>
      <output class="line-amount" aria-label="Amount, line ${n}"></output>
      <button type="button" class="icon-btn danger" data-remove-item aria-label="Remove line ${n}">
        <svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>
      </button>
    </li>`;
  }).join('');
  $('#btn-add-item').disabled = state.invoice.items.length >= LIMITS.items;
}

function renderFields() {
  els.fields.innerHTML = state.invoice.customFields.map((f, i) => {
    const n = i + 1;
    const id = `cf-${f.id}`;
    return `<li class="field-row${f.visible ? '' : ' is-hidden'}" data-id="${esc(f.id)}">
      <div class="cell cell-label">
        <label for="${id}-l" class="mobile-label">Label</label>
        <input id="${id}-l" data-cf="label" data-path="customFields.${esc(f.id)}.label" value="${esc(f.label)}"
          aria-label="Label, custom field ${n}" placeholder="e.g. PO number" maxlength="60" autocomplete="off">
      </div>
      <div class="cell cell-value">
        <label for="${id}-v" class="mobile-label">Value</label>
        <input id="${id}-v" data-cf="value" data-path="customFields.${esc(f.id)}.value" value="${esc(f.value)}"
          aria-label="Value, custom field ${n}" placeholder="e.g. HL-2291" maxlength="300" autocomplete="off">
        ${f.visible ? '' : '<span class="badge">Hidden — not on invoice</span>'}
      </div>
      <button type="button" class="icon-btn vis" data-toggle-field aria-pressed="${f.visible}"
        aria-label="Show on invoice, custom field ${n}" title="${f.visible ? 'Visible on invoice — click to hide' : 'Hidden — click to show on invoice'}">
        <svg class="icon" aria-hidden="true"><use href="#${f.visible ? 'i-eye' : 'i-eye-off'}"/></svg>
      </button>
      <button type="button" class="icon-btn danger del" data-remove-field aria-label="Remove custom field ${n}">
        <svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>
      </button>
    </li>`;
  }).join('');
  els.fieldsEmpty.hidden = state.invoice.customFields.length > 0;
  $('#btn-add-field').disabled = state.invoice.customFields.length >= LIMITS.customFields;
}

function renderSwatches() {
  $('#swatches').innerHTML = ACCENTS.map((a) => `
    <div class="swatch" style="--sw: var(--sw-${a.id})">
      <input type="radio" name="accent" id="acc-${a.id}" value="${a.id}" ${state.invoice.accent === a.id ? 'checked' : ''}>
      <label for="acc-${a.id}">${a.label}</label>
    </div>`).join('');
}

/** Put the current invoice into every static input. */
function fillStaticInputs() {
  for (const input of $$('[data-bind]', els.editor)) {
    input.value = getPath(state.invoice, input.dataset.bind) ?? '';
  }
}

function renderAll() {
  fillStaticInputs();
  renderItems();
  renderFields();
  renderSwatches();
  update();
}

// ------------------------------------------------------------------ rendering: derived

function update() {
  state.ev = evaluate(state.invoice);
  const { totals, lineTotals, subtotal } = state.ev;

  els.preview.innerHTML = renderPreview(state.invoice, state.ev);

  $$('.item-row', els.items).forEach((row, i) => {
    const out = $('.line-amount', row);
    const v = lineTotals[i];
    out.textContent = v === null || v === undefined ? '—' : formatCents(v);
    out.classList.toggle('is-empty', v === null || v === undefined);
  });

  const money = (c) => (c === null || c === undefined ? '—' : formatCents(c));
  els.summary.innerHTML = `
    <div><dt>Subtotal</dt><dd>${money(subtotal)}</dd></div>
    <div><dt>Discount</dt><dd>${totals ? `−${money(totals.discount)}` : '—'}</dd></div>
    <div><dt>Tax</dt><dd>${money(totals?.tax)}</dd></div>
    <div class="grand"><dt>Total due</dt><dd>${money(totals?.total)}</dd></div>`;

  for (const chip of $$('[data-terms]')) {
    const due = state.invoice.issueDate ? addDays(state.invoice.issueDate, Number(chip.dataset.terms)) : null;
    chip.setAttribute('aria-pressed', String(due === state.invoice.dueDate));
  }

  refreshErrors();
  updateStatus();
}

function updateStatus() {
  const number = state.invoice.number.trim();
  els.docNumber.textContent = state.id ? number || 'Untitled' : `New · ${number || 'invoice'}`;
  let s;
  if (state.saving) s = ['saving', 'Saving…'];
  else if (!state.id) s = ['new', 'Not saved yet'];
  else if (isDirty()) s = ['dirty', 'Unsaved changes'];
  else s = ['saved', 'Saved'];
  els.status.dataset.state = s[0];
  els.status.textContent = s[1];
}

// ------------------------------------------------------------------ validation display

const REQUIRED = /is required\.$/;

function errorFor(path, value) {
  const msg = state.serverErrors[path] ?? state.ev.errors[path];
  if (!msg) return null;
  // Format errors show while typing; "required" waits until the field is left or a save is attempted.
  const show = state.showAll || state.touched.has(path) || (String(value ?? '').trim() !== '' && !REQUIRED.test(msg));
  return show ? msg : null;
}

function refreshErrors() {
  for (const input of $$('[data-bind], [data-path]', els.editor)) {
    const path = input.dataset.bind ?? input.dataset.path;
    const msg = errorFor(path, input.value);
    const box = input.closest('.field, .cell');
    const errId = `${input.id}-error`;
    let errEl = document.getElementById(errId);

    if (input.dataset.hint === undefined) input.dataset.hint = input.getAttribute('aria-describedby') ?? '';
    if (msg) {
      if (!errEl) {
        errEl = document.createElement('p');
        errEl.className = 'field-error';
        errEl.id = errId;
        box.append(errEl);
      }
      errEl.textContent = msg;
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', `${errId} ${input.dataset.hint}`.trim());
    } else {
      errEl?.remove();
      input.removeAttribute('aria-invalid');
      if (input.dataset.hint) input.setAttribute('aria-describedby', input.dataset.hint);
      else input.removeAttribute('aria-describedby');
    }
  }

  for (const key of ['items', 'customFields']) {
    const el = $(`#err-${key}`);
    const msg = state.showAll ? state.ev.errors[key] : null;
    el.hidden = !msg;
    el.textContent = msg ?? '';
  }
}

/** Human label for an error path, used in the summary box. */
function describePath(path) {
  const [root, id] = path.split('.');
  if (root === 'items' && id) return `Line ${state.invoice.items.findIndex((it) => it.id === id) + 1}`;
  if (root === 'customFields' && id) return `Custom field ${state.invoice.customFields.findIndex((f) => f.id === id) + 1}`;
  if (root === 'business') return 'From';
  if (root === 'customer') return 'Bill to';
  return null;
}

function showErrorSummary(errors) {
  const entries = Object.entries(errors);
  if (!entries.length) {
    els.errorSummary.hidden = true;
    return;
  }
  els.errorSummary.innerHTML = `
    <h2>Please fix ${entries.length === 1 ? 'this' : `these ${entries.length}`} before saving</h2>
    <ul>${entries.map(([path, msg]) => {
      const where = describePath(path);
      return `<li><a href="#" data-goto="${esc(path)}">${where ? `${esc(where)}: ` : ''}${esc(msg)}</a></li>`;
    }).join('')}</ul>`;
  els.errorSummary.hidden = false;
  els.errorSummary.tabIndex = -1;
  showView('edit');
  els.errorSummary.focus();
  els.errorSummary.scrollIntoView({ block: 'start', behavior: 'smooth' });
}

function inputForPath(path) {
  return $$('[data-bind], [data-path]', els.editor).find((el) => (el.dataset.bind ?? el.dataset.path) === path)
    ?? (path === 'items' ? $('#btn-add-item') : path === 'customFields' ? $('#btn-add-field') : null);
}

/** Server errors use list indexes ("items.0.quantity"); the UI uses row ids. */
function mapServerErrors(errors) {
  const mapped = {};
  for (const [path, msg] of Object.entries(errors ?? {})) {
    const m = /^(items|customFields)\.(\d+)\.(.+)$/.exec(path);
    const row = m ? state.invoice[m[1]][Number(m[2])] : null;
    mapped[m && row ? `${m[1]}.${row.id}.${m[3]}` : path] = msg;
  }
  return mapped;
}

// ------------------------------------------------------------------ editing events

function onEdit(path, value) {
  setPath(state.invoice, path, value);
  delete state.serverErrors[path];
  if (!els.errorSummary.hidden && Object.keys(evaluate(state.invoice).errors).length === 0) els.errorSummary.hidden = true;
  update();
}

els.editor.addEventListener('input', (e) => {
  const t = e.target;
  if (t.dataset.bind) return onEdit(t.dataset.bind, t.value);
  if (t.name === 'accent') return onEdit('accent', t.value);

  const row = t.closest('[data-id]');
  if (!row) return;
  if (t.dataset.item) {
    const item = state.invoice.items.find((it) => it.id === row.dataset.id);
    item[t.dataset.item] = t.value;
    delete state.serverErrors[t.dataset.path];
    update();
  } else if (t.dataset.cf) {
    const field = state.invoice.customFields.find((f) => f.id === row.dataset.id);
    field[t.dataset.cf] = t.value;
    delete state.serverErrors[t.dataset.path];
    update();
  }
});

// Leaving a field: mark it touched and tidy money values ("50" -> "50.00").
els.editor.addEventListener('focusout', (e) => {
  const t = e.target;
  const path = t.dataset?.bind ?? t.dataset?.path;
  if (!path) return;
  state.touched.add(path);
  const isMoney = path === 'discount' || t.dataset.item === 'unitPrice';
  if (isMoney && t.value.trim() !== '') {
    const tidy = tidyMoney(t.value.trim());
    if (tidy !== t.value) {
      t.value = tidy;
      if (t.dataset.bind) setPath(state.invoice, path, tidy);
      else state.invoice.items.find((it) => it.id === t.closest('[data-id]').dataset.id).unitPrice = tidy;
    }
  }
  update();
});

els.editor.addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;

  if (btn.dataset.terms !== undefined) {
    if (!state.invoice.issueDate) return toast('Pick an issue date first.', { type: 'error' });
    const due = addDays(state.invoice.issueDate, Number(btn.dataset.terms));
    $('#f-due').value = due;
    state.touched.add('dueDate');
    return onEdit('dueDate', due);
  }

  const row = btn.closest('[data-id]');
  if (btn.hasAttribute('data-remove-item')) {
    const index = state.invoice.items.findIndex((it) => it.id === row.dataset.id);
    state.invoice.items.splice(index, 1);
    renderItems();
    update();
    const next = $$('.item-row', els.items)[Math.min(index, state.invoice.items.length - 1)];
    (next ? $('input', next) : $('#btn-add-item')).focus();
    toast(`Line ${index + 1} removed.`);
  } else if (btn.hasAttribute('data-remove-field')) {
    const index = state.invoice.customFields.findIndex((f) => f.id === row.dataset.id);
    const [removed] = state.invoice.customFields.splice(index, 1);
    renderFields();
    update();
    const next = $$('.field-row', els.fields)[Math.min(index, state.invoice.customFields.length - 1)];
    (next ? $('input', next) : $('#btn-add-field')).focus();
    toast(`Custom field “${removed.label || index + 1}” removed.`);
  } else if (btn.hasAttribute('data-toggle-field')) {
    const field = state.invoice.customFields.find((f) => f.id === row.dataset.id);
    field.visible = !field.visible;
    renderFields();
    update();
    $(`[data-id="${CSS.escape(field.id)}"] [data-toggle-field]`, els.fields).focus();
  }
});

// Enter in the last line item adds a new one — fast keyboard entry.
els.editor.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
  e.preventDefault();
  const row = e.target.closest('.item-row');
  if (row && row === els.items.lastElementChild) addItem();
});

els.editor.addEventListener('submit', (e) => e.preventDefault());

function addItem() {
  if (state.invoice.items.length >= LIMITS.items) return;
  const item = blankItem();
  state.invoice.items.push(item);
  renderItems();
  update();
  $(`[data-id="${item.id}"] input`, els.items).focus();
}

function addField() {
  if (state.invoice.customFields.length >= LIMITS.customFields) return;
  const field = blankField();
  state.invoice.customFields.push(field);
  renderFields();
  update();
  $(`[data-id="${field.id}"] input`, els.fields).focus();
}

$('#btn-add-item').addEventListener('click', addItem);
$('#btn-add-field').addEventListener('click', addField);

els.errorSummary.addEventListener('click', (e) => {
  const link = e.target.closest('[data-goto]');
  if (!link) return;
  e.preventDefault();
  const input = inputForPath(link.dataset.goto);
  input?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  input?.focus({ preventScroll: true });
});

// ------------------------------------------------------------------ save / load

async function save() {
  if (state.saving) return false;
  const { errors } = evaluate(state.invoice);
  if (Object.keys(errors).length) {
    state.showAll = true;
    update();
    showErrorSummary(errors);
    return false;
  }

  const payload = JSON.parse(JSON.stringify(state.invoice));
  state.saving = true;
  els.save.disabled = true;
  updateStatus();
  try {
    const res = await api(state.id ? `invoices/${state.id}` : 'invoices', { method: state.id ? 'PUT' : 'POST', body: payload });
    const isNew = !state.id;
    state.id = res.id;
    state.snapshot = JSON.stringify(payload);
    state.serverErrors = {};
    els.errorSummary.hidden = true;
    if (isNew) replaceRoute(`#/invoice/${res.id}`);
    toast(`Invoice ${payload.number} saved.`);
    return true;
  } catch (err) {
    if (err.status === 422 && err.data?.errors) {
      state.serverErrors = mapServerErrors(err.data.errors);
      state.showAll = true;
      showErrorSummary(state.serverErrors);
    } else {
      toast(err.message, { type: 'error', timeout: 6000 });
    }
    return false;
  } finally {
    state.saving = false;
    els.save.disabled = false;
    update();
  }
}

function resetUiState() {
  state.touched.clear();
  state.showAll = false;
  state.serverErrors = {};
  els.errorSummary.hidden = true;
}

async function loadInvoice(id) {
  try {
    const res = await api(`invoices/${id}`);
    state.invoice = hydrate(res.invoice);
    state.id = res.id;
    state.snapshot = JSON.stringify(state.invoice);
    resetUiState();
    renderAll();
  } catch (err) {
    toast(err.status === 404 ? 'That invoice no longer exists — starting a new one.' : err.message, { type: 'error' });
    replaceRoute('#/new');
    await loadNew();
  }
}

async function loadNew() {
  let defaults = { number: 'INV-0001', business: null, isFirstInvoice: false };
  try {
    defaults = await api('invoices/new');
  } catch (err) {
    toast(err.message, { type: 'error' });
  }
  state.invoice = defaults.isFirstInvoice
    ? sampleInvoice(defaults.number)
    : blankInvoice({ number: defaults.number, business: defaults.business });
  state.id = null;
  state.snapshot = JSON.stringify(state.invoice);
  resetUiState();
  renderAll();
}

let currentRoute = location.hash;

/** Change the URL without adding a history entry (and keep currentRoute in sync). */
function replaceRoute(hash) {
  history.replaceState(null, '', hash);
  currentRoute = hash;
}

async function route() {
  const m = /^#\/invoice\/(\d+)$/.exec(location.hash);
  currentRoute = location.hash;
  if (m) await loadInvoice(Number(m[1]));
  else await loadNew();
}

function confirmDiscard() {
  return !isDirty() || confirm('You have unsaved changes. Discard them?');
}

async function navigate(hash) {
  if (!confirmDiscard()) return;
  history.pushState(null, '', hash);
  await route();
  els.editor.focus({ preventScroll: true });
  window.scrollTo({ top: 0 });
}

window.addEventListener('popstate', () => {
  if (location.hash === currentRoute) return;
  if (!confirmDiscard()) {
    history.pushState(null, '', currentRoute);
    return;
  }
  route();
});

window.addEventListener('beforeunload', (e) => {
  if (isDirty()) {
    e.preventDefault();
    e.returnValue = '';
  }
});

// ------------------------------------------------------------------ top bar actions

els.save.addEventListener('click', save);
$('#btn-new').addEventListener('click', () => navigate('#/new'));
// Don't print an invoice with invalid numbers — show what to fix instead.
$('#btn-print').addEventListener('click', () => {
  const { errors } = evaluate(state.invoice);
  if (Object.keys(errors).length) {
    state.showAll = true;
    update();
    showErrorSummary(errors);
    toast('Fix the highlighted fields before printing.', { type: 'error' });
    return;
  }
  window.print();
});

document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault();
    save();
  }
});

// The PDF file name comes from the document title.
let titleBeforePrint = document.title;
window.addEventListener('beforeprint', () => {
  titleBeforePrint = document.title;
  const who = state.invoice.customer.name.trim();
  document.title = `Invoice ${state.invoice.number.trim() || 'draft'}${who ? ` - ${who}` : ''}`;
});
window.addEventListener('afterprint', () => { document.title = titleBeforePrint; });

// ------------------------------------------------------------------ dialogs

for (const dialog of $$('dialog')) {
  dialog.addEventListener('click', (e) => {
    if (e.target.closest('[data-close]') || e.target === dialog) dialog.close();
  });
}

$('#btn-list').addEventListener('click', async () => {
  els.listBody.innerHTML = '<p class="list-empty">Loading…</p>';
  els.listDialog.showModal();
  await renderList();
});

async function renderList() {
  try {
    const { invoices } = await api('invoices');
    if (!invoices.length) {
      els.listBody.innerHTML = '<p class="list-empty">No saved invoices yet. Save this one to see it here.</p>';
      return;
    }
    els.listBody.innerHTML = `<ul class="inv-list">${invoices.map((inv) => `
      <li class="inv-item${inv.id === state.id ? ' is-current' : ''}">
        <button type="button" class="inv-open" data-open="${inv.id}" aria-label="Open invoice ${esc(inv.number)} for ${esc(inv.customerName)}">
          <strong>${esc(inv.number)}</strong>
          <span>${esc(inv.customerName)} · due ${esc(formatDate(inv.dueDate))}</span>
        </button>
        <span class="inv-total">${formatCents(inv.totalCents)}</span>
        <button type="button" class="icon-btn danger" data-delete="${inv.id}" data-number="${esc(inv.number)}" aria-label="Delete invoice ${esc(inv.number)}">
          <svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>
        </button>
      </li>`).join('')}</ul>`;
  } catch (err) {
    els.listBody.innerHTML = `<p class="list-empty">${esc(err.message)}</p>`;
  }
}

els.listBody.addEventListener('click', async (e) => {
  const open = e.target.closest('[data-open]');
  const del = e.target.closest('[data-delete]');
  if (open) {
    els.listDialog.close();
    await navigate(`#/invoice/${open.dataset.open}`);
  } else if (del) {
    const id = Number(del.dataset.delete);
    if (!confirm(`Delete invoice ${del.dataset.number}? This can’t be undone.`)) return;
    try {
      await api(`invoices/${id}`, { method: 'DELETE' });
      toast(`Invoice ${del.dataset.number} deleted.`);
      if (id === state.id) {
        state.snapshot = JSON.stringify(state.invoice); // nothing left to protect
        state.id = null;
        replaceRoute('#/new');
        await route();
      }
      await renderList();
      ($('[data-open]', els.listBody) ?? $('[data-close]', els.listDialog)).focus();
    } catch (err) {
      toast(err.message, { type: 'error' });
    }
  }
});

// Email
$('#btn-email').addEventListener('click', () => {
  const { errors, totals } = evaluate(state.invoice);
  if (Object.keys(errors).length) {
    state.showAll = true;
    update();
    showErrorSummary(errors);
    toast('Fix the highlighted fields before emailing.', { type: 'error' });
    return;
  }
  const inv = state.invoice;
  $('#m-to').value = inv.customer.email.trim();
  $('#m-message').value = `Hi ${inv.customer.name.trim()},\n\nPlease find invoice ${inv.number.trim()} for ${formatCents(totals.total)}, due ${formatDate(inv.dueDate)}.\n\nThank you!\n${inv.business.name.trim()}`;
  clearEmailErrors();
  renderMailMode();
  renderEmailHistory(null);
  els.emailDialog.showModal();
  if (state.id) api(`invoices/${state.id}`).then((r) => renderEmailHistory(r.emails)).catch(() => {});
  $('#m-to').focus();
});

function renderMailMode() {
  const el = $('#mail-mode');
  if (!state.mail) {
    el.hidden = true;
    return;
  }
  el.hidden = false;
  el.classList.toggle('is-log', state.mail.transport !== 'smtp');
  el.textContent = state.mail.transport === 'smtp'
    ? `Sent via ${state.mail.description} from ${state.mail.from}.`
    : 'Demo mode: SMTP isn’t configured, so emails are saved to storage/mail instead of being delivered.';
}

function renderEmailHistory(emails) {
  const el = $('#email-history');
  if (!emails?.length) {
    el.innerHTML = '';
    return;
  }
  el.innerHTML = `<strong>Recent emails</strong><ul>${emails.slice(0, 5).map((m) =>
    `<li>${esc(m.status === 'sent' ? 'Sent' : m.status === 'logged' ? 'Logged' : 'Failed')} to ${esc(m.recipient)} · ${esc(new Date(m.sentAt).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' }))}</li>`).join('')}</ul>`;
}

function setEmailError(input, msg) {
  const id = `${input.id}-error`;
  document.getElementById(id)?.remove();
  if (!msg) {
    input.removeAttribute('aria-invalid');
    input.removeAttribute('aria-describedby');
    return;
  }
  const p = document.createElement('p');
  p.className = 'field-error';
  p.id = id;
  p.textContent = msg;
  input.closest('.field').append(p);
  input.setAttribute('aria-invalid', 'true');
  input.setAttribute('aria-describedby', id);
}

function clearEmailErrors() {
  setEmailError($('#m-to'), null);
  setEmailError($('#m-message'), null);
}

els.emailForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const to = $('#m-to').value.trim();
  const message = $('#m-message').value;
  clearEmailErrors();
  if (!to || !isEmail(to)) {
    setEmailError($('#m-to'), to ? 'Enter a valid email address, like name@example.com.' : 'Recipient email is required.');
    $('#m-to').focus();
    return;
  }

  const sendBtn = $('#btn-send');
  sendBtn.disabled = true;
  sendBtn.lastChild.textContent = 'Sending…';
  try {
    if (!state.id || isDirty()) {
      const saved = await save();
      if (!saved) {
        els.emailDialog.close();
        return;
      }
    }
    const res = await api(`invoices/${state.id}/email`, { method: 'POST', body: { to, message } });
    els.emailDialog.close();
    toast(res.message, { timeout: 6000 });
  } catch (err) {
    if (err.status === 422 && err.data?.errors) {
      if (err.data.errors.to) setEmailError($('#m-to'), err.data.errors.to);
      if (err.data.errors.message) setEmailError($('#m-message'), err.data.errors.message);
      if (!err.data.errors.to && !err.data.errors.message) toast(err.message, { type: 'error', timeout: 7000 });
    } else {
      toast(err.message, { type: 'error', timeout: 7000 });
    }
  } finally {
    sendBtn.disabled = false;
    sendBtn.lastChild.textContent = 'Send email';
  }
});

// ------------------------------------------------------------------ mobile edit/preview tabs

function showView(view) {
  els.workspace.dataset.view = view;
  for (const tab of $$('.view-switch [role="tab"]')) {
    const selected = tab.id === `tab-${view}`;
    tab.setAttribute('aria-selected', String(selected));
    tab.tabIndex = selected ? 0 : -1;
  }
}

$('.view-switch').addEventListener('click', (e) => {
  const tab = e.target.closest('[role="tab"]');
  if (tab) showView(tab.id.replace('tab-', ''));
});
$('.view-switch').addEventListener('keydown', (e) => {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
  e.preventDefault();
  const next = els.workspace.dataset.view === 'edit' ? 'preview' : 'edit';
  showView(e.key === 'Home' ? 'edit' : e.key === 'End' ? 'preview' : next);
  $(`#tab-${els.workspace.dataset.view}`).focus();
});

// ------------------------------------------------------------------ boot

api('config').then((c) => { state.mail = c.mail; }).catch(() => {});
els.preview.innerHTML = '<p class="list-empty">Loading…</p>';
route();
