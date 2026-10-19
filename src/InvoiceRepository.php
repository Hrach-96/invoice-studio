<?php

declare(strict_types=1);

namespace App;

use PDO;

final class InvoiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, number, customer_name, issue_date, due_date, total_cents, updated_at
             FROM invoices ORDER BY updated_at DESC, id DESC'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'number' => $row['number'],
            'customerName' => $row['customer_name'],
            'issueDate' => $row['issue_date'],
            'dueDate' => $row['due_date'],
            'totalCents' => (int) $row['total_cents'],
            'updatedAt' => $row['updated_at'],
        ], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'invoice' => json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $invoice */
    public function create(array $invoice, int $totalCents): int
    {
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices (number, customer_name, issue_date, due_date, total_cents, data, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([...$this->columns($invoice, $totalCents), $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $invoice */
    public function update(int $id, array $invoice, int $totalCents): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE invoices
             SET number = ?, customer_name = ?, issue_date = ?, due_date = ?, total_cents = ?, data = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([...$this->columns($invoice, $totalCents), self::now(), $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM invoices WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    public function numberTaken(string $number, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM invoices WHERE LOWER(number) = LOWER(?)');
        $stmt->execute([$number]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        return array_diff($ids, [$exceptId]) !== [];
    }

    /** Next "INV-0001"-style number, based on the highest one already used. */
    public function nextNumber(): string
    {
        $max = 0;
        foreach ($this->pdo->query('SELECT number FROM invoices')->fetchAll(PDO::FETCH_COLUMN) as $number) {
            if (preg_match('/^INV-(\d+)$/i', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('INV-%04d', $max + 1);
    }

    /** @return array<string, mixed>|null The most recently edited invoice's business block, to prefill new invoices. */
    public function lastBusiness(): ?array
    {
        $data = $this->pdo->query('SELECT data FROM invoices ORDER BY updated_at DESC, id DESC LIMIT 1')->fetchColumn();

        return $data === false ? null : (json_decode($data, true)['business'] ?? null);
    }

    public function logEmail(int $invoiceId, string $recipient, string $status, ?string $error = null): void
    {
        $this->pdo->prepare('INSERT INTO email_log (invoice_id, recipient, status, error, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$invoiceId, $recipient, $status, $error, self::now()]);
    }

    /** @return list<array<string, mixed>> */
    public function emailHistory(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT recipient, status, created_at FROM email_log WHERE invoice_id = ? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$invoiceId]);

        return array_map(static fn (array $r): array => [
            'recipient' => $r['recipient'],
            'status' => $r['status'],
            'sentAt' => $r['created_at'],
        ], $stmt->fetchAll());
    }

    /** @param array<string, mixed> $invoice */
    private function columns(array $invoice, int $totalCents): array
    {
        return [
            $invoice['number'],
            $invoice['customer']['name'],
            $invoice['issueDate'],
            $invoice['dueDate'],
            $totalCents,
            json_encode($invoice, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
