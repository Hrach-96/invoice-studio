// Exact money math with integers only — mirrors src/Money.php line for line.
//   quantity -> thousandths ("1.5" => 1500)
//   prices   -> cents       ("50.00" => 5000)
//   tax rate -> thousandths of a percent ("8.875" => 8875)

export const QTY_SCALE = 1000;
export const RATE_SCALE = 100000; // 100% * 1000

/** Parse a non-negative decimal string into a scaled integer, or null. */
export function parseDecimal(input, decimals) {
  const text = String(input ?? '').trim().replace(/,/g, '');
  const m = /^(\d*)(?:\.(\d*))?$/.exec(text);
  if (!m || (m[1] === '' && (m[2] ?? '') === '')) return null;

  const whole = m[1].replace(/^0+/, '') || '0';
  const fraction = m[2] ?? '';
  if (fraction.length > decimals || whole.length > 12) return null;

  return Number(whole + fraction.padEnd(decimals, '0'));
}

/** round(a * b / divisor), half-up, split so intermediate values stay small. */
export function mulDivRound(a, b, divisor) {
  const q = Math.floor(a / divisor);
  const r = a % divisor;
  return q * b + Math.floor((r * b + Math.floor(divisor / 2)) / divisor);
}

export function lineTotal(quantityMilli, unitPriceCents) {
  return mulDivRound(quantityMilli, unitPriceCents, QTY_SCALE);
}

/**
 * Round each line to cents, sum, apply the fixed discount, then calculate and round tax.
 * @param {{quantity:number, unitPrice:number}[]} lines
 */
export function totals(lines, discountCents, taxRateMilli) {
  const lineTotals = lines.map((l) => lineTotal(l.quantity, l.unitPrice));
  const subtotal = lineTotals.reduce((sum, v) => sum + v, 0);
  const taxable = subtotal - discountCents;
  const tax = mulDivRound(taxable, taxRateMilli, RATE_SCALE);
  return { lines: lineTotals, subtotal, discount: discountCents, taxable, tax, total: taxable + tax };
}

const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export function formatCents(cents) {
  return usd.format(cents / 100);
}
