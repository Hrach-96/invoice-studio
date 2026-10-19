<?php

declare(strict_types=1);

namespace App;

/**
 * Server-side source of truth for invoice rules. The browser runs the same
 * rules (public/assets/invoice.js) for instant feedback, but nothing is
 * trusted until it passes here.
 *
 * Errors are keyed by field path, e.g. "customer.name", "items.0.quantity".
 */
final class InvoiceValidator
{
    public const MAX_ITEMS = 200;
    public const MAX_CUSTOM_FIELDS = 30;
    public const MAX_QUANTITY_MILLI = 100_000 * Money::QTY_SCALE;   // 100,000 units
    public const MAX_PRICE_CENTS = 1_000_000_00;                    // $1,000,000.00
    public const ACCENTS = ['forest', 'ink', 'clay', 'plum', 'ocean'];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @return array{invoice: array<string, mixed>, totals: ?array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(mixed $input): array
    {
        return (new self())->run(is_array($input) ? $input : []);
    }

    private function run(array $in): array
    {
        $business = is_array($in['business'] ?? null) ? $in['business'] : [];
        $customer = is_array($in['customer'] ?? null) ? $in['customer'] : [];

        $invoice = [
            'number' => $this->text($in, 'number', 'Invoice number', 40, required: true),
            'issueDate' => $this->date($in, 'issueDate', 'Issue date'),
            'dueDate' => $this->date($in, 'dueDate', 'Due date'),
            'business' => [
                'name' => $this->text($business, 'name', 'Business name', 120, required: true, path: 'business.name'),
                'email' => $this->email($business, 'business.email'),
                'phone' => $this->text($business, 'phone', 'Phone', 40, path: 'business.phone'),
                'address' => $this->text($business, 'address', 'Address', 500, path: 'business.address', multiline: true),
            ],
            'customer' => [
                'name' => $this->text($customer, 'name', 'Customer name', 120, required: true, path: 'customer.name'),
                'email' => $this->email($customer, 'customer.email'),
                'address' => $this->text($customer, 'address', 'Address', 500, path: 'customer.address', multiline: true),
            ],
            'items' => [],
            'discount' => '0.00',
            'taxRate' => '0',
            'notes' => $this->text($in, 'notes', 'Notes', 2000, multiline: true),
            'customFields' => [],
            'accent' => in_array($in['accent'] ?? null, self::ACCENTS, true) ? $in['accent'] : self::ACCENTS[0],
        ];

        if ($invoice['issueDate'] !== '' && $invoice['dueDate'] !== '' && $invoice['dueDate'] < $invoice['issueDate']) {
            $this->errors['dueDate'] = 'Due date can’t be before the issue date.';
        }

        // Line items
        $rawItems = is_array($in['items'] ?? null) ? array_values($in['items']) : [];
        if ($rawItems === []) {
            $this->errors['items'] = 'Add at least one line item.';
        } elseif (count($rawItems) > self::MAX_ITEMS) {
            $this->errors['items'] = 'An invoice can have at most ' . self::MAX_ITEMS . ' line items.';
            $rawItems = array_slice($rawItems, 0, self::MAX_ITEMS);
        }

        $amounts = [];
        foreach ($rawItems as $i => $item) {
            $item = is_array($item) ? $item : [];
            $path = "items.$i";
            $quantity = $this->amount($item, 'quantity', "$path.quantity", 'Quantity', 3, self::MAX_QUANTITY_MILLI, mustBePositive: true);
            $price = $this->amount($item, 'unitPrice', "$path.unitPrice", 'Unit price', 2, self::MAX_PRICE_CENTS);
            $invoice['items'][] = [
                'id' => $this->id($item),
                'description' => $this->text($item, 'description', 'Description', 300, required: true, path: "$path.description"),
                'quantity' => $quantity['text'],
                'unitPrice' => $price['text'],
            ];
            $amounts[] = ['quantity' => $quantity['value'], 'unitPrice' => $price['value']];
        }

        // Adjustments
        $discount = $this->amount($in, 'discount', 'discount', 'Discount', 2, self::MAX_PRICE_CENTS * self::MAX_ITEMS, default: '0');
        $taxRate = $this->amount($in, 'taxRate', 'taxRate', 'Tax rate', 3, 100 * 1000, default: '0', maxLabel: '100%');
        $invoice['discount'] = $discount['text'];
        $invoice['taxRate'] = $taxRate['text'];

        // Custom fields — display only, never part of the math.
        $rawFields = is_array($in['customFields'] ?? null) ? array_values($in['customFields']) : [];
        if (count($rawFields) > self::MAX_CUSTOM_FIELDS) {
            $this->errors['customFields'] = 'An invoice can have at most ' . self::MAX_CUSTOM_FIELDS . ' custom fields.';
            $rawFields = array_slice($rawFields, 0, self::MAX_CUSTOM_FIELDS);
        }
        foreach ($rawFields as $i => $field) {
            $field = is_array($field) ? $field : [];
            $invoice['customFields'][] = [
                'id' => $this->id($field),
                'label' => $this->text($field, 'label', 'Label', 60, required: true, path: "customFields.$i.label"),
                'value' => $this->text($field, 'value', 'Value', 300, path: "customFields.$i.value"),
                'visible' => ($field['visible'] ?? true) !== false,
            ];
        }

        $totals = null;
        $numbersValid = $discount['value'] !== null && $taxRate['value'] !== null && $amounts !== []
            && !in_array(null, array_merge(...array_map('array_values', $amounts)), true);
        if ($numbersValid) {
            $totals = Money::totals($amounts, 0, 0);
            if ($discount['value'] > $totals['subtotal']) {
                $this->errors['discount'] = 'Discount can’t exceed the subtotal (' . Money::format($totals['subtotal']) . ').';
                $totals = null;
            } else {
                $totals = Money::totals($amounts, $discount['value'], $taxRate['value']);
            }
        }

        return ['invoice' => $invoice, 'totals' => $totals, 'errors' => $this->errors];
    }

    private function text(array $src, string $key, string $label, int $max, bool $required = false, ?string $path = null, bool $multiline = false): string
    {
        $path ??= $key;
        $raw = $src[$key] ?? '';
        $value = is_scalar($raw) ? (string) $raw : '';
        $value = str_replace("\r\n", "\n", $value);
        $value = $multiline ? trim($value) : trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        if ($required && $value === '') {
            $this->errors[$path] = "$label is required.";
        } elseif (mb_strlen($value) > $max) {
            $this->errors[$path] = "$label must be $max characters or fewer.";
        }

        return $value;
    }

    private function email(array $src, string $path): string
    {
        $value = trim(is_scalar($src['email'] ?? null) ? (string) $src['email'] : '');
        if ($value !== '' && !self::isEmail($value)) {
            $this->errors[$path] = 'Enter a valid email address, like name@example.com.';
        }

        return $value;
    }

    public static function isEmail(string $value): bool
    {
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function date(array $src, string $key, string $label): string
    {
        $value = trim(is_scalar($src[$key] ?? null) ? (string) $src[$key] : '');
        if ($value === '') {
            $this->errors[$key] = "$label is required.";

            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$key] = "$label must be a valid date.";

            return '';
        }

        return $value;
    }

    /**
     * @return array{text: string, value: ?int}
     */
    private function amount(array $src, string $key, string $path, string $label, int $decimals, int $max, bool $mustBePositive = false, ?string $default = null, ?string $maxLabel = null): array
    {
        $raw = $src[$key] ?? '';
        $text = trim(is_string($raw) || is_int($raw) ? (string) $raw : (is_float($raw) ? rtrim(rtrim(sprintf('%.6F', $raw), '0'), '.') : ''));
        if ($text === '' && $default !== null) {
            $text = $default;
        }

        $error = null;
        $value = null;
        if ($text === '') {
            $error = "$label is required.";
        } elseif (str_starts_with($text, '-')) {
            $error = "$label can’t be negative.";
        } elseif (($value = Money::parse($text, $decimals)) === null) {
            $error = "$label must be a number with up to $decimals decimal places.";
        } elseif ($mustBePositive && $value === 0) {
            $error = "$label must be greater than 0.";
        } elseif ($value > $max) {
            $limit = $maxLabel ?? ($decimals === 2 ? Money::format($max) : number_format($max / 10 ** $decimals));
            $error = "$label can’t be more than $limit.";
        }

        if ($error !== null) {
            $this->errors[$path] = $error;

            return ['text' => $text, 'value' => null];
        }

        return ['text' => $text, 'value' => $value];
    }

    private function id(array $src): string
    {
        $id = $src['id'] ?? '';

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) ? $id : bin2hex(random_bytes(6));
    }
}
