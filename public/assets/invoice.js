// Invoice model helpers: defaults, validation (mirrors src/InvoiceValidator.php)
// and the HTML for the live preview.
import { parseDecimal, totals as computeTotals, formatCents, QTY_SCALE } from './money.js';

export const LIMITS = {
  items: 200,
  customFields: 30,
  quantityMilli: 100_000 * QTY_SCALE,
  priceCents: 1_000_000_00,
};

export const ACCENTS = [
  { id: 'forest', label: 'Forest' },
  { id: 'ink', label: 'Ink' },
  { id: 'clay', label: 'Clay' },
  { id: 'plum', label: 'Plum' },
  { id: 'ocean', label: 'Ocean' },
];

export const uid = () => Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);

export function isoDate(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function addDays(iso, days) {
  const [y, m, d] = iso.split('-').map(Number);
  return isoDate(new Date(y, m - 1, d + days));
}

export const blankItem = () => ({ id: uid(), description: '', quantity: '1', unitPrice: '' });
export const blankField = () => ({ id: uid(), label: '', value: '', visible: true });

export function blankInvoice({ number = 'INV-0001', business = null } = {}) {
  const today = isoDate(new Date());
  return {
    number,
    issueDate: today,
    dueDate: addDays(today, 14),
    business: { name: '', email: '', phone: '', address: '', ...(business ?? {}) },
    customer: { name: '', email: '', address: '' },
    items: [blankItem()],
    discount: '0',
    taxRate: '0',
    notes: '',
    customFields: [],
    accent: 'forest',
  };
}

/** Fictional demo data for the very first visit — matches the brief's check: 2 × $50 − $10 + 10% = $99.00 */
export function sampleInvoice(number) {
  const inv = blankInvoice({ number });
  inv.business = {
    name: 'Juniper & Pine Studio',
    email: 'billing@juniperpine.example',
    phone: '+1 (555) 014-2290',
    address: '418 Alder Street, Suite 2\nPortland, OR 97205',
  };
  inv.customer = {
    name: 'Harbor Light Coffee Co.',
    email: 'accounts@harborlight.example',
    address: '77 Wharf Road\nSeattle, WA 98101',
  };
  inv.items = [{ id: uid(), description: 'Brand workshop — half day', quantity: '2', unitPrice: '50.00' }];
  inv.discount = '10.00';
  inv.taxRate = '10';
  inv.notes = 'Thank you for your business! Payment by bank transfer within 14 days.';
  inv.customFields = [
    { id: uid(), label: 'PO number', value: 'HL-2291', visible: true },
    { id: uid(), label: 'Project', value: 'Spring rebrand', visible: true },
    { id: uid(), label: 'Internal ref', value: 'Lead from referral', visible: false },
  ];
  return inv;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export const isEmail = (v) => v.length <= 254 && EMAIL_RE.test(v);

function checkAmount(errors, path, raw, label, decimals, max, { positive = false, fallback = null, maxLabel = null } = {}) {
  let text = String(raw ?? '').trim();
  if (text === '' && fallback !== null) text = fallback;
  let error = null;
  let value = null;
  if (text === '') error = `${label} is required.`;
  else if (text.startsWith('-')) error = `${label} can’t be negative.`;
  else if ((value = parseDecimal(text, decimals)) === null) error = `${label} must be a number with up to ${decimals} decimal places.`;
  else if (positive && value === 0) error = `${label} must be greater than 0.`;
  else if (value > max) error = `${label} can’t be more than ${maxLabel ?? (decimals === 2 ? formatCents(max) : (max / 10 ** decimals).toLocaleString('en-US'))}.`;
  if (error) {
    errors[path] = error;
    return null;
  }
  return value;
}

function checkText(errors, path, value, label, max, required = false) {
  const v = String(value ?? '').trim();
  if (required && v === '') errors[path] = `${label} is required.`;
  else if (v.length > max) errors[path] = `${label} must be ${max} characters or fewer.`;
}

/**
 * Validate and total an invoice. Item/field errors are keyed by id
 * ("items.<id>.quantity") so they follow rows as they're added and removed.
 */
export function evaluate(inv) {
  const errors = {};

  checkText(errors, 'number', inv.number, 'Invoice number', 40, true);
  if (!inv.issueDate) errors.issueDate = 'Issue date is required.';
  if (!inv.dueDate) errors.dueDate = 'Due date is required.';
  else if (inv.issueDate && inv.dueDate < inv.issueDate) errors.dueDate = 'Due date can’t be before the issue date.';

  checkText(errors, 'business.name', inv.business.name, 'Business name', 120, true);
  checkText(errors, 'business.phone', inv.business.phone, 'Phone', 40);
  checkText(errors, 'business.address', inv.business.address, 'Address', 500);
  checkText(errors, 'customer.name', inv.customer.name, 'Customer name', 120, true);
  checkText(errors, 'customer.address', inv.customer.address, 'Address', 500);
  for (const who of ['business', 'customer']) {
    const email = inv[who].email.trim();
    if (email && !isEmail(email)) errors[`${who}.email`] = 'Enter a valid email address, like name@example.com.';
  }

  if (inv.items.length === 0) errors.items = 'Add at least one line item.';
  else if (inv.items.length > LIMITS.items) errors.items = `An invoice can have at most ${LIMITS.items} line items.`;

  const amounts = inv.items.map((item) => {
    const p = `items.${item.id}`;
    checkText(errors, `${p}.description`, item.description, 'Description', 300, true);
    return {
      quantity: checkAmount(errors, `${p}.quantity`, item.quantity, 'Quantity', 3, LIMITS.quantityMilli, { positive: true }),
      unitPrice: checkAmount(errors, `${p}.unitPrice`, item.unitPrice, 'Unit price', 2, LIMITS.priceCents),
    };
  });

  const discount = checkAmount(errors, 'discount', inv.discount, 'Discount', 2, LIMITS.priceCents * LIMITS.items, { fallback: '0' });
  const taxRate = checkAmount(errors, 'taxRate', inv.taxRate, 'Tax rate', 3, 100 * 1000, { fallback: '0', maxLabel: '100%' });

  if (inv.customFields.length > LIMITS.customFields) errors.customFields = `An invoice can have at most ${LIMITS.customFields} custom fields.`;
  for (const f of inv.customFields) {
    checkText(errors, `customFields.${f.id}.label`, f.label, 'Label', 60, true);
    checkText(errors, `customFields.${f.id}.value`, f.value, 'Value', 300);
  }

  // Per-line totals are shown even when other lines are invalid.
  const lineTotals = amounts.map((a) => (a.quantity !== null && a.unitPrice !== null ? computeTotals([a], 0, 0).lines[0] : null));
  const linesValid = amounts.length > 0 && lineTotals.every((v) => v !== null);
  let totals = null;
  let subtotal = linesValid ? lineTotals.reduce((s, v) => s + v, 0) : null;

  if (linesValid && discount !== null && taxRate !== null) {
    if (discount > subtotal) errors.discount = `Discount can’t exceed the subtotal (${formatCents(subtotal)}).`;
    else totals = computeTotals(amounts, discount, taxRate);
  }

  return { errors, totals, lineTotals, subtotal };
}

// ---------------------------------------------------------------- preview

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const multiline = (s) => esc(s).replace(/\n/g, '<br>');

export function formatDate(iso) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(iso ?? '')) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

/** A muted placeholder that is visible on screen but never printed. */
const orPlaceholder = (value, placeholder, render = esc) =>
  String(value ?? '').trim() ? render(value) : `<span class="ph">${esc(placeholder)}</span>`;

export function renderPreview(inv, { totals, lineTotals, subtotal }) {
  const money = (cents) => (cents === null || cents === undefined ? '<span class="ph">—</span>' : esc(formatCents(cents)));
  const visibleFields = inv.customFields.filter((f) => f.visible && f.label.trim());
  const contact = [inv.business.email, inv.business.phone].map((s) => s.trim()).filter(Boolean);

  const meta = [
    ['Issued', formatDate(inv.issueDate)],
    ['Due', formatDate(inv.dueDate)],
    ...visibleFields.map((f) => [f.label, f.value]),
  ]
    .map(([k, v]) => `<div class="pv-meta-row"><dt>${esc(k)}</dt><dd>${orPlaceholder(v, '—')}</dd></div>`)
    .join('');

  const rows = inv.items
    .map((item, i) => {
      const price = parseDecimal(item.unitPrice, 2);
      const qty = parseDecimal(item.quantity, 3);
      return `<tr>
        <td class="pv-desc">${orPlaceholder(item.description, 'Item description')}</td>
        <td class="num">${qty === null ? '<span class="ph">—</span>' : esc(String(item.quantity).trim())}</td>
        <td class="num">${price === null ? '<span class="ph">—</span>' : esc(formatCents(price))}</td>
        <td class="num">${money(lineTotals[i])}</td>
      </tr>`;
    })
    .join('');

  // Show the discount row whenever a discount is entered, but only a checked amount.
  const enteredDiscount = parseDecimal(inv.discount || '0', 2);
  const discountCents = enteredDiscount === 0 ? 0 : totals ? totals.discount : null;
  const taxLabel = parseDecimal(inv.taxRate || '0', 3) !== null ? `Tax (${esc(String(inv.taxRate || '0').trim())}%)` : 'Tax';

  return `
  <article class="paper" data-accent="${esc(inv.accent)}" aria-label="Invoice preview">
    <header class="pv-head">
      <div class="pv-from">
        <p class="pv-business">${orPlaceholder(inv.business.name, 'Your business name')}</p>
        ${inv.business.address.trim() ? `<p class="pv-address">${multiline(inv.business.address.trim())}</p>` : ''}
        ${contact.length ? `<p class="pv-contact">${contact.map(esc).join('<span aria-hidden="true"> · </span>')}</p>` : ''}
      </div>
      <div class="pv-title">
        <h2>Invoice</h2>
        <p class="pv-number">${orPlaceholder(inv.number, 'INV-0000')}</p>
      </div>
    </header>

    <section class="pv-parties">
      <div class="pv-billto">
        <h3>Bill to</h3>
        <p class="pv-customer">${orPlaceholder(inv.customer.name, 'Customer name')}</p>
        ${inv.customer.address.trim() ? `<p class="pv-address">${multiline(inv.customer.address.trim())}</p>` : ''}
        ${inv.customer.email.trim() ? `<p class="pv-contact">${esc(inv.customer.email.trim())}</p>` : ''}
      </div>
      <dl class="pv-meta">${meta}</dl>
    </section>

    <table class="pv-items">
      <thead><tr><th scope="col">Description</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Unit price</th><th scope="col" class="num">Amount</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>

    <section class="pv-bottom">
      <div class="pv-notes">${inv.notes.trim() ? `<h3>Notes</h3><p>${multiline(inv.notes.trim())}</p>` : ''}</div>
      <dl class="pv-totals">
        <div><dt>Subtotal</dt><dd>${money(subtotal)}</dd></div>
        ${discountCents !== 0 ? `<div><dt>Discount</dt><dd>${discountCents === null ? money(null) : `−${money(discountCents)}`}</dd></div>` : ''}
        <div><dt>${taxLabel}</dt><dd>${money(totals?.tax)}</dd></div>
        <div class="pv-grand"><dt>Total due <span>USD</span></dt><dd>${money(totals?.total)}</dd></div>
      </dl>
    </section>
  </article>`;
}
