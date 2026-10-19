<?php

declare(strict_types=1);

namespace App;

/**
 * Exact money math with integers only — no floats anywhere.
 *
 *   quantity  -> thousandths   ("1.5"  => 1500)
 *   prices    -> cents         ("50.00" => 5000)
 *   tax rate  -> thousandths % ("8.875" => 8875)
 *
 * The same algorithm lives in public/assets/money.js; tests/vectors.json
 * is run against both so the preview and the server can never disagree.
 */
final class Money
{
    public const QTY_SCALE = 1000;
    public const RATE_SCALE = 100000; // 100% * 1000

    /**
     * Parse a non-negative decimal string into a scaled integer.
     * Returns null for anything that isn't a plain decimal with <= $decimals places.
     */
    public static function parse(string $input, int $decimals): ?int
    {
        $input = trim(str_replace(',', '', $input));
        if (!preg_match('/^(\d*)(?:\.(\d*))?$/', $input, $m) || ($m[1] === '' && ($m[2] ?? '') === '')) {
            return null;
        }

        $whole = $m[1] === '' ? '0' : ltrim($m[1], '0');
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $decimals || strlen($whole) > 12) {
            return null;
        }

        return (int) (($whole === '' ? '0' : $whole) . str_pad($fraction, $decimals, '0'));
    }

    /** round(a * b / divisor), half-up, without overflowing for large a. */
    public static function mulDivRound(int $a, int $b, int $divisor): int
    {
        $q = intdiv($a, $divisor);
        $r = $a % $divisor;

        return $q * $b + intdiv($r * $b + intdiv($divisor, 2), $divisor);
    }

    public static function lineTotal(int $quantityMilli, int $unitPriceCents): int
    {
        return self::mulDivRound($quantityMilli, $unitPriceCents, self::QTY_SCALE);
    }

    /**
     * Round each line to cents, sum, apply the fixed discount, then calculate and round tax.
     *
     * @param list<array{quantity:int, unitPrice:int}> $lines
     * @return array{lines: list<int>, subtotal: int, discount: int, taxable: int, tax: int, total: int}
     */
    public static function totals(array $lines, int $discountCents, int $taxRateMilli): array
    {
        $lineTotals = array_map(
            static fn (array $line): int => self::lineTotal($line['quantity'], $line['unitPrice']),
            $lines,
        );
        $subtotal = array_sum($lineTotals);
        $taxable = $subtotal - $discountCents;
        $tax = self::mulDivRound($taxable, $taxRateMilli, self::RATE_SCALE);

        return [
            'lines' => $lineTotals,
            'subtotal' => $subtotal,
            'discount' => $discountCents,
            'taxable' => $taxable,
            'tax' => $tax,
            'total' => $taxable + $tax,
        ];
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$') . number_format(abs($cents) / 100, 2);
    }
}
