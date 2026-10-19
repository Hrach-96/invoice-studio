// Browser tests: JS money/validation against the shared vectors, then an
// end-to-end run of the real app in an iframe (fresh SQLite DB, log mailer).
import { parseDecimal, totals, formatCents } from '/assets/money.js';
import { blankInvoice, evaluate } from '/assets/invoice.js';

const out = document.getElementById('results');
let passed = 0;
const failures = [];

const progress = (line) => fetch('/tests/progress', { method: 'POST', body: line }).catch(() => {});

function check(ok, name, detail = '') {
  if (ok) passed++;
  else failures.push(`${name}${detail ? `\n      ${detail}` : ''}`);
  progress(`${ok ? 'ok  ' : 'FAIL'} ${name}`);
}
const eq = (actual, expected, name) =>
  check(JSON.stringify(actual) === JSON.stringify(expected), name, `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
const log = (s) => { out.textContent += `${s}\n`; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function waitFor(fn, name, timeout = 8000) {
  const start = Date.now();
  for (;;) {
    try {
      const v = fn();
      if (v) return v;
    } catch { /* not ready yet */ }
    if (Date.now() - start > timeout) {
      check(false, `timeout: ${name}`);
      return null;
    }
    await sleep(50);
  }
}

function invoiceFor(items, discount, taxRate) {
  const inv = blankInvoice();
  inv.business.name = 'Test Co';
  inv.customer.name = 'Client';
  inv.items = items.map(([quantity, unitPrice], i) => ({ id: `r${i}`, description: 'Item', quantity, unitPrice }));
  inv.discount = discount;
  inv.taxRate = taxRate;
  return inv;
}

// ------------------------------------------------------------------ unit
async function unitTests() {
  log('• JS money vectors (shared with PHP)');
  const vectors = await (await fetch('/tests/vectors.json')).json();
  for (const v of vectors.totals) {
    const ev = evaluate(invoiceFor(v.items, v.discount, v.taxRate));
    eq(ev.errors, {}, `valid: ${v.name}`);
    const t = ev.totals ?? {};
    eq([t.lines, t.subtotal, t.tax, t.total], [v.expect.lines, v.expect.subtotal, v.expect.tax, v.expect.total], v.name);
  }
  for (const v of vectors.invalid) {
    const ev = evaluate(invoiceFor(v.items, v.discount, v.taxRate));
    const path = v.errorPath.replace(/^items\.(\d+)\./, (_, i) => `items.r${i}.`);
    check((ev.errors[path] ?? '').includes(v.errorContains), `rejects: ${v.name}`, JSON.stringify(ev.errors));
    check(ev.totals === null, `no totals when invalid: ${v.name}`);
  }
  eq(parseDecimal('1,250.5', 2), 125050, 'parse thousands separators');
  eq(parseDecimal('.', 2), null, 'parse rejects lone dot');
  eq(formatCents(9900), '$99.00', 'format');
  eq(totals([{ quantity: 2000, unitPrice: 5000 }], 1000, 10000).total, 9900, 'direct totals() check');
}

// ------------------------------------------------------------------ e2e
async function e2eTests() {
  log('• End-to-end UI');
  const frame = document.getElementById('app');
  const load = (url) => new Promise((resolve) => { frame.onload = resolve; frame.src = url; });

  await load('/');
  let w = frame.contentWindow;
  let d = frame.contentDocument;
  w.confirm = () => true;
  const $ = (s) => d.querySelector(s);
  const $$ = (s) => [...d.querySelectorAll(s)];
  const preview = () => $('#preview').textContent.replace(/\s+/g, ' ');
  const total = () => $('#summary .grand dd').textContent.trim();
  const type = (el, value) => {
    el.focus();
    el.value = value;
    el.dispatchEvent(new w.Event('input', { bubbles: true }));
  };
  const blur = (el) => el.dispatchEvent(new w.FocusEvent('focusout', { bubbles: true }));

  // First visit on an empty DB shows the sample invoice = the brief's check.
  await waitFor(() => preview().includes('Juniper'), 'sample invoice loaded');
  eq(total(), '$99.00', 'sample: 2 × $50 − $10 + 10% tax = $99.00 in editor');
  check(preview().includes('$99.00'), 'preview shows $99.00');
  check(preview().includes('HL-2291') && preview().includes('Spring rebrand'), 'visible custom fields on invoice');
  check(!preview().includes('Lead from referral'), 'hidden custom field NOT on invoice');
  eq($('#status-chip').dataset.state, 'new', 'status: not saved yet');

  // Negative quantity -> live error, totals blank.
  const qty = $('.item-row [data-item="quantity"]');
  type(qty, '-1');
  check(qty.getAttribute('aria-invalid') === 'true', 'negative qty marked aria-invalid');
  check(($(`#${qty.id}-error`)?.textContent ?? '').includes('negative'), 'negative qty message shown');
  check(qty.getAttribute('aria-describedby')?.includes(`${qty.id}-error`), 'error linked via aria-describedby');
  eq(total(), '—', 'no total while invalid');
  check(!preview().includes('-1'), 'invalid quantity never shown on the invoice');
  let printed = false;
  w.print = () => { printed = true; };
  $('#btn-print').click();
  check(!printed && !$('#error-summary').hidden, 'Print is blocked and errors summarised while invalid');
  type(qty, '2');
  $('#btn-print').click();
  check(printed, 'Print opens once the invoice is valid');
  check(!qty.hasAttribute('aria-invalid'), 'error clears when fixed');

  // Discount above subtotal.
  const discount = $('#f-discount');
  type(discount, '100.01');
  check(($('#f-discount-error')?.textContent ?? '').includes('exceed'), 'discount > subtotal rejected');
  type(discount, '-5');
  check(($('#f-discount-error')?.textContent ?? '').includes('negative'), 'negative discount rejected');
  const tax = $('#f-tax');
  type(tax, '-1');
  check(($('#f-tax-error')?.textContent ?? '').includes('negative'), 'negative tax rejected');
  type(tax, '10');
  type(discount, '10');
  blur(discount);
  eq(discount.value, '10.00', 'money tidied to 2 decimals on blur');
  eq(total(), '$99.00', 'total restored');

  // Price negative.
  const price = $('.item-row [data-item="unitPrice"]');
  type(price, '-50');
  check(($(`#${price.id}-error`)?.textContent ?? '').includes('negative'), 'negative price rejected');
  type(price, '50.00');

  // Add / edit / remove line items.
  $('#btn-add-item').click();
  eq($$('.item-row').length, 2, 'line item added');
  check(d.activeElement === $$('.item-row')[1].querySelector('[data-item="description"]'), 'focus moves to new line');
  const row2 = $$('.item-row')[1];
  type(row2.querySelector('[data-item="description"]'), 'Extra hour');
  type(row2.querySelector('[data-item="quantity"]'), '1');
  type(row2.querySelector('[data-item="unitPrice"]'), '5');
  eq(row2.querySelector('.line-amount').textContent, '$5.00', 'line total shown');
  eq(total(), '$104.50', '(100 + 5 − 10) × 1.10 = $104.50');
  check(preview().includes('Extra hour'), 'new line in preview');

  // Enter on the last line adds another.
  row2.querySelector('[data-item="unitPrice"]').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
  eq($$('.item-row').length, 3, 'Enter on last line adds a line');
  $$('.item-row')[2].querySelector('[data-remove-item]').click();
  $$('.item-row')[1].querySelector('[data-remove-item]').click();
  eq($$('.item-row').length, 1, 'line items removed');
  eq(total(), '$99.00', 'total back to $99.00');
  check(!preview().includes('Extra hour'), 'removed line gone from preview');

  // Custom fields: hide/show, add, edit, remove.
  const projectRow = $$('.field-row').find((r) => r.querySelector('[data-cf="value"]').value === 'Spring rebrand');
  projectRow.querySelector('[data-toggle-field]').click();
  check(!preview().includes('Spring rebrand'), 'hiding a field removes it from the invoice');
  eq(total(), '$99.00', 'hiding a field does not change totals');
  const toggled = $$('.field-row').find((r) => r.querySelector('[data-cf="value"]').value === 'Spring rebrand').querySelector('[data-toggle-field]');
  eq(toggled.getAttribute('aria-pressed'), 'false', 'toggle exposes state via aria-pressed');
  check(d.activeElement === toggled, 'focus stays on toggle');
  toggled.click();
  check(preview().includes('Spring rebrand'), 'showing it again puts it back');

  $('#btn-add-field').click();
  const newField = $$('.field-row').at(-1);
  check(d.activeElement === newField.querySelector('[data-cf="label"]'), 'focus moves to new field label');
  type(newField.querySelector('[data-cf="label"]'), 'Contract');
  type(newField.querySelector('[data-cf="value"]'), 'C-55');
  check(preview().includes('Contract') && preview().includes('C-55'), 'new custom field on invoice');
  type(newField.querySelector('[data-cf="value"]'), 'C-56');
  check(preview().includes('C-56') && !preview().includes('C-55'), 'edited custom field updates preview');
  eq(total(), '$99.00', 'custom fields never affect totals');
  newField.querySelector('[data-remove-field]').click();
  check(!preview().includes('Contract'), 'removed custom field gone');

  // Accent colour.
  const plum = $('#acc-plum');
  plum.checked = true;
  plum.dispatchEvent(new w.Event('input', { bubbles: true }));
  eq($('.paper').dataset.accent, 'plum', 'accent colour applied to preview');

  // Save -> URL gets the id -> reload restores everything.
  $('#btn-save').click();
  await waitFor(() => $('#status-chip').dataset.state === 'saved', 'saved');
  check(/^#\/invoice\/\d+$/.test(w.location.hash), 'URL points at saved invoice', w.location.hash);
  const savedHash = w.location.hash;

  await load(`/?reload=1${savedHash}`);
  w = frame.contentWindow;
  d = frame.contentDocument;
  w.confirm = () => true;
  await waitFor(() => d.querySelector('#status-chip').dataset.state === 'saved', 'reloaded invoice');
  eq(d.querySelector('#summary .grand dd').textContent.trim(), '$99.00', 'after reload: total $99.00');
  eq(d.querySelector('#f-bname').value, 'Juniper & Pine Studio', 'after reload: business name');
  eq(d.querySelector('.paper').dataset.accent, 'plum', 'after reload: accent');
  const fieldsAfter = [...d.querySelectorAll('.field-row')].map((r) => [r.querySelector('[data-cf="label"]').value, r.querySelector('[data-toggle-field]').getAttribute('aria-pressed')]);
  eq(fieldsAfter, [['PO number', 'true'], ['Project', 'true'], ['Internal ref', 'false']], 'after reload: custom fields incl. hidden state');
  check(!d.querySelector('#preview').textContent.includes('Lead from referral'), 'after reload: hidden field still hidden');

  // Unsaved-changes indicator.
  const notes = d.querySelector('#f-notes');
  notes.value = 'Changed';
  notes.dispatchEvent(new w.Event('input', { bubbles: true }));
  eq(d.querySelector('#status-chip').dataset.state, 'dirty', 'status: unsaved changes');
  notes.value = 'Thank you for your business! Payment by bank transfer within 14 days.';
  notes.dispatchEvent(new w.Event('input', { bubbles: true }));
  eq(d.querySelector('#status-chip').dataset.state, 'saved', 'status back to saved when reverted');

  // Email dialog.
  d.querySelector('#btn-email').click();
  check(d.querySelector('#dlg-email').open, 'email dialog opens');
  eq(d.querySelector('#m-to').value, 'accounts@harborlight.example', 'recipient prefilled from customer');
  check(d.querySelector('#m-message').value.includes('$99.00'), 'message mentions total');
  d.querySelector('#m-to').value = 'nope';
  d.querySelector('#email-form').requestSubmit();
  await sleep(50);
  check((d.querySelector('#m-to-error')?.textContent ?? '').includes('valid email'), 'invalid recipient blocked');
  d.querySelector('#m-to').value = 'me@example.test';
  d.querySelector('#email-form').requestSubmit();
  await waitFor(() => !d.querySelector('#dlg-email').open, 'email sent and dialog closed');
  await waitFor(() => d.querySelector('#toasts').textContent.includes('storage/mail'), 'email confirmation toast');

  // Saved list.
  d.querySelector('#btn-list').click();
  await waitFor(() => d.querySelector('#list-body .inv-item'), 'invoice list');
  check(d.querySelector('#list-body').textContent.includes('INV-0001') && d.querySelector('#list-body').textContent.includes('$99.00'), 'list shows saved invoice and total');
  d.querySelector('#dlg-list').close();

  // New invoice: next number, business remembered, required errors summarised.
  d.querySelector('#btn-new').click();
  await waitFor(() => d.querySelector('#f-number').value === 'INV-0002', 'new invoice number INV-0002');
  eq(d.querySelector('#f-bname').value, 'Juniper & Pine Studio', 'new invoice reuses business details');
  eq(d.querySelector('#f-cname').value, '', 'new invoice has empty customer');
  d.querySelector('#btn-save').click();
  await sleep(50);
  const summary = d.querySelector('#error-summary');
  check(!summary.hidden && summary.textContent.includes('Customer name is required'), 'save blocked with error summary');
  check(d.activeElement === summary, 'focus moves to error summary');
  summary.querySelector('[data-goto="customer.name"]').click();
  check(d.activeElement === d.querySelector('#f-cname'), 'summary link focuses the field');
  check(d.querySelector('#f-cname').getAttribute('aria-invalid') === 'true', 'required field marked invalid');

  // Duplicate number error from the server.
  const cn = d.querySelector('#f-cname');
  cn.value = 'Second Client';
  cn.dispatchEvent(new w.Event('input', { bubbles: true }));
  const desc = d.querySelector('.item-row [data-item="description"]');
  desc.value = 'Thing';
  desc.dispatchEvent(new w.Event('input', { bubbles: true }));
  const pr = d.querySelector('.item-row [data-item="unitPrice"]');
  pr.value = '20';
  pr.dispatchEvent(new w.Event('input', { bubbles: true }));
  const num = d.querySelector('#f-number');
  num.value = 'inv-0001';
  num.dispatchEvent(new w.Event('input', { bubbles: true }));
  d.querySelector('#btn-save').click();
  await waitFor(() => (d.querySelector('#f-number-error')?.textContent ?? '').includes('already uses'), 'server duplicate-number error shown on field');
  num.value = 'INV-0002';
  num.dispatchEvent(new w.Event('input', { bubbles: true }));
  d.querySelector('#btn-save').click();
  await waitFor(() => d.querySelector('#status-chip').dataset.state === 'saved', 'second invoice saved');

  // Print stylesheet hides everything except the invoice.
  const printRules = [...d.styleSheets].flatMap((s) => { try { return [...s.cssRules]; } catch { return []; } })
    .filter((r) => r.media && r.media.mediaText === 'print').map((r) => r.cssText).join(' ');
  check(/\.topbar[^}]*\.editor[^}]*display: none/.test(printRules), 'print CSS hides controls and editor');
}

try {
  await unitTests();
  await e2eTests();
} catch (err) {
  failures.push(`crashed: ${err.stack ?? err}`);
}

log('');
if (failures.length) {
  log(`${failures.length} FAILED, ${passed} passed`);
  failures.forEach((f) => log(`  ✗ ${f}`));
  document.getElementById('verdict').textContent = 'FAIL';
} else {
  log(`All ${passed} browser checks passed ✓`);
  document.getElementById('verdict').textContent = 'PASS';
}
document.body.dataset.done = '1';
fetch('/tests/report', { method: 'POST', body: out.textContent }).catch(() => {});
