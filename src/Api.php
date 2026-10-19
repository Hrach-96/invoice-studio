<?php

declare(strict_types=1);

namespace App;

use App\Mail\MailException;
use App\Mail\Mailer;
use App\Mail\MailMessage;

/**
 * JSON API.
 *
 *   GET    /api/config                 mail mode for the UI
 *   GET    /api/invoices               list
 *   GET    /api/invoices/new           defaults for a new invoice (next number, last business)
 *   GET    /api/invoices/{id}          one invoice
 *   POST   /api/invoices               create
 *   PUT    /api/invoices/{id}          update
 *   DELETE /api/invoices/{id}          delete
 *   POST   /api/invoices/{id}/email    send the saved invoice by email
 */
final class Api
{
    private const MAX_BODY_BYTES = 512 * 1024;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly Mailer $mailer,
        private readonly array $mailConfig,
    ) {
    }

    public function handle(string $method, string $path): void
    {
        $path = '/' . trim(preg_replace('#^.*?/api#', '', $path, 1) ?? '', '/');

        try {
            match (true) {
                $method === 'GET' && $path === '/config' => $this->config(),
                $method === 'GET' && $path === '/invoices' => self::json(['invoices' => $this->invoices->all()]),
                $method === 'GET' && $path === '/invoices/new' => $this->defaults(),
                $method === 'POST' && $path === '/invoices' => $this->save(null),
                (bool) preg_match('#^/invoices/(\d+)$#', $path, $m) => match ($method) {
                    'GET' => $this->show((int) $m[1]),
                    'PUT' => $this->save((int) $m[1]),
                    'DELETE' => $this->destroy((int) $m[1]),
                    default => self::json(['error' => 'Method not allowed.'], 405),
                },
                $method === 'POST' && (bool) preg_match('#^/invoices/(\d+)/email$#', $path, $m) => $this->email((int) $m[1]),
                default => self::json(['error' => 'Not found.'], 404),
            };
        } catch (\JsonException) {
            self::json(['error' => 'Request body must be valid JSON.'], 400);
        } catch (\Throwable $e) {
            error_log('[invoice-api] ' . $e);
            self::json(['error' => 'Something went wrong on the server. Please try again.'], 500);
        }
    }

    private function config(): void
    {
        self::json(['mail' => [
            'transport' => $this->mailConfig['driver'],
            'description' => $this->mailer->describe(),
            'from' => $this->mailConfig['fromAddress'],
        ]]);
    }

    private function defaults(): void
    {
        self::json([
            'number' => $this->invoices->nextNumber(),
            'business' => $this->invoices->lastBusiness(),
            'isFirstInvoice' => $this->invoices->all() === [],
        ]);
    }

    private function show(int $id): void
    {
        $record = $this->invoices->find($id);
        if ($record === null) {
            self::json(['error' => 'Invoice not found.'], 404);

            return;
        }

        $result = InvoiceValidator::validate($record['invoice']);
        self::json($record + ['totals' => $result['totals'], 'emails' => $this->invoices->emailHistory($id)]);
    }

    private function save(?int $id): void
    {
        if ($id !== null && $this->invoices->find($id) === null) {
            self::json(['error' => 'Invoice not found.'], 404);

            return;
        }

        $result = InvoiceValidator::validate(self::body());
        $errors = $result['errors'];
        if ($errors === [] && $this->invoices->numberTaken($result['invoice']['number'], $id)) {
            $errors['number'] = 'Another invoice already uses this number.';
        }
        if ($errors !== []) {
            self::json(['error' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);

            return;
        }

        $invoice = $result['invoice'];
        $total = $result['totals']['total'];
        try {
            if ($id === null) {
                $id = $this->invoices->create($invoice, $total);
            } else {
                $this->invoices->update($id, $invoice, $total);
            }
        } catch (\PDOException $e) {
            // Unique index race: two saves with the same number at once.
            if ($e->getCode() === '23000') {
                self::json(['error' => 'Please fix the highlighted fields.', 'errors' => ['number' => 'Another invoice already uses this number.']], 422);

                return;
            }
            throw $e;
        }

        $this->show($id);
    }

    private function destroy(int $id): void
    {
        if (!$this->invoices->delete($id)) {
            self::json(['error' => 'Invoice not found.'], 404);

            return;
        }
        self::json(['deleted' => true]);
    }

    private function email(int $id): void
    {
        $record = $this->invoices->find($id);
        if ($record === null) {
            self::json(['error' => 'Invoice not found.'], 404);

            return;
        }

        $body = self::body();
        $to = trim(is_string($body['to'] ?? null) ? $body['to'] : '');
        $message = is_string($body['message'] ?? null) ? trim(str_replace("\r\n", "\n", $body['message'])) : '';

        $errors = [];
        if ($to === '') {
            $errors['to'] = 'Recipient email is required.';
        } elseif (!InvoiceValidator::isEmail($to)) {
            $errors['to'] = 'Enter a valid email address, like name@example.com.';
        } elseif (!$this->recipientAllowed($to)) {
            $errors['to'] = 'This server only sends to approved test addresses (MAIL_ALLOWED_RECIPIENTS).';
        }
        if (mb_strlen($message) > 2000) {
            $errors['message'] = 'Message must be 2000 characters or fewer.';
        }
        if ($errors !== []) {
            self::json(['error' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);

            return;
        }

        // Always email the saved version, re-validated and re-totalled on the server.
        $result = InvoiceValidator::validate($record['invoice']);
        if ($result['errors'] !== [] || $result['totals'] === null) {
            self::json(['error' => 'This invoice has invalid data. Open it, fix it and save before sending.'], 422);

            return;
        }

        $invoice = $result['invoice'];
        $rendered = InvoiceEmail::render($invoice, $result['totals'], $message);
        $replyTo = InvoiceValidator::isEmail($invoice['business']['email']) ? $invoice['business']['email'] : null;
        $mail = new MailMessage(
            fromEmail: $this->mailConfig['fromAddress'],
            fromName: $invoice['business']['name'] !== '' ? $invoice['business']['name'] : $this->mailConfig['fromName'],
            to: $to,
            subject: $rendered['subject'],
            html: $rendered['html'],
            text: $rendered['text'],
            replyTo: $replyTo,
        );

        try {
            $this->mailer->send($mail);
        } catch (MailException $e) {
            error_log('[invoice-mail] ' . $e->getMessage());
            $this->invoices->logEmail($id, $to, 'failed', $e->getMessage());
            self::json(['error' => 'The email could not be sent. Check the SMTP settings in .env and the server log.'], 502);

            return;
        }

        $status = $this->mailConfig['driver'] === 'smtp' ? 'sent' : 'logged';
        $this->invoices->logEmail($id, $to, $status);
        self::json([
            'status' => $status,
            'to' => $to,
            'message' => $status === 'sent'
                ? "Invoice emailed to $to."
                : 'Email saved to storage/mail (log mode — configure SMTP in .env to deliver it).',
            'emails' => $this->invoices->emailHistory($id),
        ]);
    }

    /** Optional safety net: MAIL_ALLOWED_RECIPIENTS="me@example.com,@mycompany.test". */
    private function recipientAllowed(string $email): bool
    {
        $allowed = $this->mailConfig['allowedRecipients'];
        if ($allowed === []) {
            return true;
        }

        $email = strtolower($email);
        foreach ($allowed as $rule) {
            if ($email === $rule || (str_starts_with($rule, '@') && str_ends_with($email, $rule))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1) ?: '';
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new \JsonException('Body too large');
        }
        $data = json_decode($raw === '' ? '{}' : $raw, true, 64, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
