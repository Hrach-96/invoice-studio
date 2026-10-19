<?php

declare(strict_types=1);

namespace App;

/**
 * Renders the invoice as an email. Email clients ignore most modern CSS,
 * so this uses tables and inline styles on purpose.
 */
final class InvoiceEmail
{
    private const ACCENT_HEX = [
        'forest' => '#1f5f4a',
        'ink' => '#26314a',
        'clay' => '#a4462b',
        'plum' => '#6b3a6e',
        'ocean' => '#1c5d86',
    ];

    /**
     * @param array<string, mixed> $invoice Validated invoice data
     * @param array<string, mixed> $totals  Output of Money::totals()
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(array $invoice, array $totals, string $message): array
    {
        $accent = self::ACCENT_HEX[$invoice['accent']] ?? self::ACCENT_HEX['forest'];
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nl = static fn (string $s): string => nl2br($e($s), false);
        $date = static fn (string $d): string => $d === '' ? '' : date('M j, Y', strtotime($d));
        $business = $invoice['business'];
        $customer = $invoice['customer'];

        $subject = "Invoice {$invoice['number']} from {$business['name']}";
        $fields = array_values(array_filter(
            $invoice['customFields'],
            static fn (array $f): bool => $f['visible'] && $f['label'] !== '',
        ));

        // Meta rows: number, dates, then visible custom fields.
        $meta = [['Invoice no.', $invoice['number']], ['Issued', $date($invoice['issueDate'])], ['Due', $date($invoice['dueDate'])]];
        foreach ($fields as $f) {
            $meta[] = [$f['label'], $f['value']];
        }
        $metaHtml = '';
        foreach ($meta as [$label, $value]) {
            $metaHtml .= '<tr><td style="padding:2px 12px 2px 0;color:#6b675e;font-size:13px;">' . $e($label)
                . '</td><td style="padding:2px 0;font-size:13px;color:#1d1b16;">' . $e($value) . '</td></tr>';
        }

        $rows = '';
        foreach ($invoice['items'] as $i => $item) {
            $rows .= '<tr>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7e2d6;font-size:14px;color:#1d1b16;">' . $e($item['description']) . '</td>'
                . '<td align="right" style="padding:10px 0 10px 12px;border-bottom:1px solid #e7e2d6;font-size:14px;color:#1d1b16;">' . $e($item['quantity']) . '</td>'
                . '<td align="right" style="padding:10px 0 10px 12px;border-bottom:1px solid #e7e2d6;font-size:14px;color:#1d1b16;">' . Money::format(Money::parse($item['unitPrice'], 2) ?? 0) . '</td>'
                . '<td align="right" style="padding:10px 0 10px 12px;border-bottom:1px solid #e7e2d6;font-size:14px;color:#1d1b16;">' . Money::format($totals['lines'][$i]) . '</td>'
                . '</tr>';
        }

        $sumRow = static fn (string $label, string $value, bool $strong = false): string => '<tr>'
            . '<td style="padding:4px 0;font-size:' . ($strong ? '16px;font-weight:700' : '14px') . ';color:' . ($strong ? '#1d1b16' : '#6b675e') . ';">' . $label . '</td>'
            . '<td align="right" style="padding:4px 0 4px 24px;font-size:' . ($strong ? '16px;font-weight:700' : '14px') . ';color:#1d1b16;">' . $value . '</td></tr>';
        $summary = $sumRow('Subtotal', Money::format($totals['subtotal']));
        if ($totals['discount'] > 0) {
            $summary .= $sumRow('Discount', '−' . Money::format($totals['discount']));
        }
        $summary .= $sumRow('Tax (' . $e($invoice['taxRate']) . '%)', Money::format($totals['tax']));
        $summary .= $sumRow('Total due (USD)', Money::format($totals['total']), true);

        $messageHtml = trim($message) === '' ? '' :
            '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#1d1b16;">' . $nl($message) . '</p>';
        $notesHtml = $invoice['notes'] === '' ? '' :
            '<p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#6b675e;"><strong style="color:#1d1b16;">Notes</strong><br>' . $nl($invoice['notes']) . '</p>';
        $contact = implode(' · ', array_filter([$business['email'], $business['phone']]));

        $html = <<<HTML
<!doctype html>
<html><body style="margin:0;padding:24px 12px;background:#f4f1ea;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:6px;border-top:6px solid {$accent};">
<tr><td style="padding:32px 32px 8px;">
  {$messageHtml}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td valign="top" style="font-size:13px;line-height:1.5;color:#6b675e;">
      <div style="font-size:18px;font-weight:700;color:#1d1b16;">{$e($business['name'])}</div>
      {$nl($business['address'])}<br>{$e($contact)}
    </td>
    <td valign="top" align="right" style="font-family:Georgia,serif;font-size:32px;color:{$accent};">Invoice</td>
  </tr></table>
</td></tr>
<tr><td style="padding:16px 32px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td valign="top" style="font-size:13px;line-height:1.5;color:#6b675e;">
      <div style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;color:{$accent};font-weight:700;">Bill to</div>
      <div style="font-size:15px;font-weight:700;color:#1d1b16;">{$e($customer['name'])}</div>
      {$nl($customer['address'])}<br>{$e($customer['email'])}
    </td>
    <td valign="top" align="right"><table role="presentation" cellpadding="0" cellspacing="0">{$metaHtml}</table></td>
  </tr></table>
</td></tr>
<tr><td style="padding:8px 32px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <th align="left" style="padding:8px 0;border-bottom:2px solid #1d1b16;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b675e;">Description</th>
      <th align="right" style="padding:8px 0 8px 12px;border-bottom:2px solid #1d1b16;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b675e;">Qty</th>
      <th align="right" style="padding:8px 0 8px 12px;border-bottom:2px solid #1d1b16;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b675e;">Unit price</th>
      <th align="right" style="padding:8px 0 8px 12px;border-bottom:2px solid #1d1b16;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b675e;">Amount</th>
    </tr>
    {$rows}
  </table>
</td></tr>
<tr><td style="padding:8px 32px 32px;" align="right">
  <table role="presentation" cellpadding="0" cellspacing="0">{$summary}</table>
  <div style="text-align:left;">{$notesHtml}</div>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;

        // Plain-text alternative for clients that don't render HTML.
        $text = trim($message) === '' ? '' : trim($message) . "\n\n";
        $text .= "INVOICE {$invoice['number']}\n{$business['name']}\n\n"
            . "Bill to: {$customer['name']}\nIssued: {$date($invoice['issueDate'])}\nDue: {$date($invoice['dueDate'])}\n";
        foreach ($fields as $f) {
            $text .= "{$f['label']}: {$f['value']}\n";
        }
        $text .= "\n";
        foreach ($invoice['items'] as $i => $item) {
            $text .= "- {$item['description']} — {$item['quantity']} × " . Money::format(Money::parse($item['unitPrice'], 2) ?? 0)
                . ' = ' . Money::format($totals['lines'][$i]) . "\n";
        }
        $text .= "\nSubtotal: " . Money::format($totals['subtotal']) . "\n";
        if ($totals['discount'] > 0) {
            $text .= 'Discount: -' . Money::format($totals['discount']) . "\n";
        }
        $text .= "Tax ({$invoice['taxRate']}%): " . Money::format($totals['tax']) . "\n"
            . 'Total due (USD): ' . Money::format($totals['total']) . "\n";
        if ($invoice['notes'] !== '') {
            $text .= "\nNotes:\n{$invoice['notes']}\n";
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}
