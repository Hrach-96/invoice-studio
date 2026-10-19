<?php

declare(strict_types=1);

/*
 * Dependency-free test runner:  php tests/run.php
 *
 *  1. Money math against tests/vectors.json (same file the JS tests use)
 *  2. Validation rules
 *  3. Repository on an in-memory SQLite database
 *  4. Email rendering + MIME safety
 *  5. HTTP API end-to-end on a throwaway server and SQLite file
 */

use App\Database;
use App\Env;
use App\InvoiceEmail;
use App\InvoiceRepository;
use App\InvoiceValidator;
use App\Mail\MailMessage;
use App\Money;

require __DIR__ . '/../src/bootstrap.php';

$passed = 0;
$failed = [];

function check(bool $ok, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
    } else {
        $failed[] = $name . ($detail !== '' ? "\n      $detail" : '');
    }
}

function eq(mixed $actual, mixed $expected, string $name): void
{
    check($actual === $expected, $name, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function section(string $title): void
{
    echo "\n• $title\n";
}

function invoiceFor(array $items, string $discount = '0', string $taxRate = '0'): array
{
    return [
        'number' => 'T-1',
        'issueDate' => '2026-01-10',
        'dueDate' => '2026-01-24',
        'business' => ['name' => 'Test Co'],
        'customer' => ['name' => 'Client'],
        'items' => array_map(static fn (array $it): array => ['description' => 'Item', 'quantity' => $it[0], 'unitPrice' => $it[1]], $items),
        'discount' => $discount,
        'taxRate' => $taxRate,
    ];
}

// ---------------------------------------------------------------------------
section('Money vectors');
$vectors = json_decode(file_get_contents(__DIR__ . '/vectors.json'), true, 512, JSON_THROW_ON_ERROR);

foreach ($vectors['totals'] as $v) {
    $result = InvoiceValidator::validate(invoiceFor($v['items'], $v['discount'], $v['taxRate']));
    check($result['errors'] === [], "valid: {$v['name']}", json_encode($result['errors']));
    $t = $result['totals'] ?? [];
    eq([$t['lines'] ?? null, $t['subtotal'] ?? null, $t['tax'] ?? null, $t['total'] ?? null],
        [$v['expect']['lines'], $v['expect']['subtotal'], $v['expect']['tax'], $v['expect']['total']], $v['name']);
}

foreach ($vectors['invalid'] as $v) {
    $result = InvoiceValidator::validate(invoiceFor($v['items'], $v['discount'], $v['taxRate']));
    $msg = $result['errors'][$v['errorPath']] ?? '';
    check(str_contains($msg, $v['errorContains']), "rejects: {$v['name']}", 'errors: ' . json_encode($result['errors']));
    check($result['totals'] === null, "no totals when invalid: {$v['name']}");
}

eq(Money::parse('1,250.5', 2), 125050, 'parse accepts thousands separators');
eq(Money::parse('.5', 2), 50, 'parse accepts leading dot');
eq(Money::parse('', 2), null, 'parse rejects empty');
eq(Money::parse('.', 2), null, 'parse rejects lone dot');
eq(Money::parse(' 7 ', 2), 700, 'parse trims spaces');
eq(Money::format(9900), '$99.00', 'format');
eq(Money::format(123456789), '$1,234,567.89', 'format thousands');

// ---------------------------------------------------------------------------
section('Validation');
$r = InvoiceValidator::validate([]);
foreach (['number', 'issueDate', 'dueDate', 'business.name', 'customer.name', 'items'] as $path) {
    check(isset($r['errors'][$path]), "empty invoice requires $path");
}

$r = InvoiceValidator::validate(array_merge(invoiceFor([['1', '1']]), ['dueDate' => '2026-01-09']));
check(str_contains($r['errors']['dueDate'] ?? '', 'before'), 'due date before issue date rejected');

$r = InvoiceValidator::validate(array_merge(invoiceFor([['1', '1']]), ['issueDate' => '2026-02-30']));
check(isset($r['errors']['issueDate']), 'impossible date rejected');

$r = InvoiceValidator::validate(array_merge(invoiceFor([['1', '1']]), ['dueDate' => '2026-01-10']));
check(!isset($r['errors']['dueDate']), 'due date equal to issue date allowed');

$inv = invoiceFor([['1', '1']]);
$inv['customer']['email'] = 'not-an-email';
$inv['business']['email'] = 'ok@example.com';
$r = InvoiceValidator::validate($inv);
check(isset($r['errors']['customer.email']) && !isset($r['errors']['business.email']), 'email format validated');

$inv = invoiceFor([['1', '1']]);
$inv['customFields'] = [
    ['id' => 'a1', 'label' => 'PO', 'value' => '42', 'visible' => true],
    ['id' => 'b2', 'label' => 'Secret', 'value' => 'x', 'visible' => false],
    ['id' => 'c3', 'label' => '', 'value' => 'orphan', 'visible' => true],
];
$r = InvoiceValidator::validate($inv);
check(isset($r['errors']['customFields.2.label']), 'custom field without label rejected');
eq($r['invoice']['customFields'][1]['visible'], false, 'hidden custom field keeps visible=false');
eq($r['invoice']['customFields'][0]['id'], 'a1', 'custom field id preserved');

$inv['customFields'] = array_slice($inv['customFields'], 0, 2);
$withFields = InvoiceValidator::validate($inv);
$without = InvoiceValidator::validate(invoiceFor([['1', '1']]));
eq($withFields['totals'], $without['totals'], 'custom fields never affect totals');

$inv = invoiceFor([['1', '1']]);
$inv['items'][0]['description'] = "  multi   spaced\tname ";
$inv['notes'] = "Line 1\r\nLine 2";
$inv['accent'] = 'neon';
$r = InvoiceValidator::validate($inv);
eq($r['invoice']['items'][0]['description'], 'multi spaced name', 'single-line text collapses whitespace');
eq($r['invoice']['notes'], "Line 1\nLine 2", 'notes keep line breaks');
eq($r['invoice']['accent'], 'forest', 'unknown accent falls back to default');

$r = InvoiceValidator::validate(invoiceFor(array_fill(0, 201, ['1', '1'])));
check(isset($r['errors']['items']), 'more than 200 items rejected');

$r = InvoiceValidator::validate(['items' => 'nope', 'business' => 'x', 'customFields' => 5]);
check(isset($r['errors']['items']), 'garbage types do not crash the validator');

$inv = invoiceFor([['2', '50']]);
$inv['items'][0]['quantity'] = 2;     // JSON numbers are accepted too
$inv['items'][0]['unitPrice'] = 49.99;
$r = InvoiceValidator::validate($inv);
eq($r['totals']['total'] ?? null, 9998, 'JSON numbers accepted (2 x 49.99)');

// ---------------------------------------------------------------------------
section('Repository (SQLite in memory)');
$repo = new InvoiceRepository(Database::sqlite(':memory:'));
eq($repo->nextNumber(), 'INV-0001', 'first number');

$valid = InvoiceValidator::validate(invoiceFor([['2', '50']], '10', '10'));
$id = $repo->create(array_merge($valid['invoice'], ['number' => 'INV-0007']), $valid['totals']['total']);
eq($repo->nextNumber(), 'INV-0008', 'next number follows the highest');
eq($repo->find($id)['invoice']['number'], 'INV-0007', 'find returns saved data');
eq($repo->all()[0]['totalCents'], 9900, 'list includes total');
check($repo->numberTaken('inv-0007'), 'number uniqueness is case-insensitive');
check(!$repo->numberTaken('INV-0007', $id), 'own number is not "taken" when updating');
check($repo->update($id, array_merge($valid['invoice'], ['number' => 'INV-0007', 'notes' => 'changed']), 9900), 'update succeeds');
check($repo->update($id, array_merge($valid['invoice'], ['number' => 'INV-0007', 'notes' => 'changed']), 9900), 'update with identical data still reports success');
eq($repo->find($id)['invoice']['notes'], 'changed', 'update persisted');
eq($repo->lastBusiness()['name'], 'Test Co', 'last business remembered for new invoices');
$repo->logEmail($id, 'a@example.com', 'sent');
eq(count($repo->emailHistory($id)), 1, 'email history logged');
check($repo->delete($id), 'delete');
eq($repo->find($id), null, 'deleted invoice is gone');
check(!$repo->delete($id), 'deleting twice reports not found');

// ---------------------------------------------------------------------------
section('Email rendering & MIME safety');
$inv = invoiceFor([['2', '50']], '10', '10');
$inv['items'][0]['description'] = '<script>alert(1)</script>';
$inv['customFields'] = [
    ['label' => 'PO', 'value' => 'PO-77', 'visible' => true],
    ['label' => 'Internal', 'value' => 'TOP-SECRET', 'visible' => false],
];
$valid = InvoiceValidator::validate($inv);
$email = InvoiceEmail::render($valid['invoice'], $valid['totals'], "Hello <b>there</b>");
check(!str_contains($email['html'], '<script>'), 'HTML escapes user content');
check(str_contains($email['html'], '&lt;script&gt;'), 'escaped content still shown');
check(str_contains($email['html'], '$99.00') && str_contains($email['text'], '$99.00'), 'email shows the $99.00 total');
check(str_contains($email['html'], 'PO-77') && str_contains($email['text'], 'PO-77'), 'visible custom field in email');
check(!str_contains($email['html'], 'TOP-SECRET') && !str_contains($email['text'], 'TOP-SECRET'), 'hidden custom field NOT in email');
eq($email['subject'], 'Invoice T-1 from Test Co', 'subject');

$mime = (new MailMessage('from@example.com', 'Shop', "victim@example.com\r\nBcc: evil@example.com", "Hi\r\nBcc: evil@example.com", '<p>x</p>', 'x'))->toMime();
check(!preg_match('/^Bcc:/mi', $mime), 'CR/LF in headers cannot inject a Bcc header');
$mime = (new MailMessage('from@example.com', 'Café Ünïcode', 'to@example.com', 'Счёт № 1', '<p>x</p>', 'x'))->toMime();
check(str_contains($mime, '=?UTF-8?B?'), 'non-ASCII headers are encoded');

// ---------------------------------------------------------------------------
section('SMTP client against a fake SMTP server');

function fakeSmtp(int $port, string $transcript, string $mode = ''): mixed
{
    @unlink($transcript);
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port, $transcript, $mode], [], $pipes);
    usleep(400_000);

    return $proc;
}

$smtpPort = 2600 + random_int(0, 300);
$transcript = __DIR__ . '/.tmp/smtp-transcript.txt';
$proc = fakeSmtp($smtpPort, $transcript);
$mailer = new App\Mail\SmtpMailer('127.0.0.1', $smtpPort, 'none', 'user@test', 'p@ss', null, 5);
$sentOk = true;
try {
    $mailer->send(new MailMessage('from@example.com', 'Shop', 'to@example.com', 'Subject', "<p>x</p>", ".leading dot line\nnormal"));
} catch (Throwable $e) {
    $sentOk = false;
    echo '      ' . $e->getMessage() . "\n";
}
proc_close($proc);
$t = (string) @file_get_contents($transcript);
check($sentOk, 'SMTP send completes');
check(str_contains($t, 'AUTH LOGIN') && str_contains($t, base64_encode('user@test')) && str_contains($t, base64_encode('p@ss')), 'AUTH LOGIN with base64 credentials');
check(str_contains($t, 'MAIL FROM:<from@example.com>') && str_contains($t, 'RCPT TO:<to@example.com>'), 'envelope sender and recipient');
check(str_contains($t, "Subject: Subject\r\n") && str_contains($t, 'multipart/alternative'), 'MIME headers sent');
check(str_contains($t, "\r\n.\r\n") && str_contains($t, 'QUIT'), 'DATA terminated and QUIT sent');

$proc = fakeSmtp(++$smtpPort, $transcript, 'fail-auth');
$error = '';
try {
    (new App\Mail\SmtpMailer('127.0.0.1', $smtpPort, 'none', 'user@test', 'wrong', null, 5))
        ->send(new MailMessage('from@example.com', 'Shop', 'to@example.com', 'S', 'h', 't'));
} catch (App\Mail\MailException $e) {
    $error = $e->getMessage();
}
proc_close($proc);
check(str_contains($error, '535'), 'bad SMTP credentials raise a clear MailException', $error);
check(!str_contains($error, base64_encode('wrong')), 'password never appears in error messages');

$error = '';
try {
    (new App\Mail\SmtpMailer('127.0.0.1', 1, 'none', '', '', null, 2))->send(new MailMessage('a@b.c', '', 'd@e.f', 's', 'h', 't'));
} catch (App\Mail\MailException $e) {
    $error = $e->getMessage();
}
check(str_contains($error, 'Could not connect'), 'unreachable SMTP host raises MailException');

// Dot-stuffing: a body line that is just "." must not end DATA early.
$mime = (new MailMessage('a@b.c', '', 'd@e.f', 's', 'h', "line\n.\nafter"))->toMime();
check(!str_contains($mime, "\r\n.\r\n"), 'base64 bodies never contain a bare "." line');

// ---------------------------------------------------------------------------
section('Env loader');
$tmp = __DIR__ . '/.tmp';
is_dir($tmp) || mkdir($tmp, 0775, true);
file_put_contents("$tmp/test.env", "# comment\nTEST_A=plain\nTEST_B=\"quoted value\"\nTEST_C='single'\nnot a pair\n");
Env::load("$tmp/test.env");
eq([Env::get('TEST_A'), Env::get('TEST_B'), Env::get('TEST_C'), Env::get('TEST_MISSING', 'dflt')], ['plain', 'quoted value', 'single', 'dflt'], 'env parsing');

// ---------------------------------------------------------------------------
section('HTTP API end-to-end (throwaway server + SQLite)');
$port = 8790 + random_int(0, 50);
$db = "$tmp/api-test.sqlite";
@unlink($db);
$env = getenv() + ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'MAIL_DRIVER' => 'log', 'MAIL_ALLOWED_RECIPIENTS' => 'me@example.test,@allowed.test'];
$env['DB_CONNECTION'] = 'sqlite';
$env['DB_DATABASE'] = $db;
$env['MAIL_DRIVER'] = 'log';
$env['MAIL_ALLOWED_RECIPIENTS'] = 'me@example.test,@allowed.test';
$env['MAIL_LOG_DIR'] = "$tmp/mail";
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../public', __DIR__ . '/../public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']],
    $pipes,
    null,
    $env,
);

function http(string $method, string $url, ?array $body = null): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\n",
        'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) ? (int) explode(' ', $http_response_header[0])[1] : 0;

    return [$status, json_decode((string) $raw, true)];
}

$base = "http://127.0.0.1:$port/api";
for ($i = 0; $i < 50 && http('GET', "$base/config")[0] !== 200; $i++) {
    usleep(100_000);
}

[$s, $j] = http('GET', "$base/invoices/new");
eq([$s, $j['number'] ?? null, $j['isFirstInvoice'] ?? null], [200, 'INV-0001', true], 'GET /invoices/new on empty DB');

$payload = invoiceFor([['2', '50.00']], '10.00', '10');
$payload['number'] = 'INV-0001';
$payload['customFields'] = [['id' => 'f1', 'label' => 'PO', 'value' => '9', 'visible' => false]];
[$s, $j] = http('POST', "$base/invoices", $payload);
eq([$s, $j['totals']['total'] ?? null], [200, 9900], 'POST creates invoice with server-side total $99.00');
$id = $j['id'] ?? 0;

[$s, $j] = http('GET', "$base/invoices/$id");
eq([$s, $j['invoice']['customFields'][0]['visible'] ?? null], [200, false], 'GET returns saved invoice incl. hidden field state');

[$s, $j] = http('POST', "$base/invoices", $payload);
eq([$s, isset($j['errors']['number'])], [422, true], 'duplicate number -> 422 on number');

$bad = $payload;
$bad['number'] = 'INV-0002';
$bad['discount'] = '500';
[$s, $j] = http('POST', "$base/invoices", $bad);
eq([$s, isset($j['errors']['discount'])], [422, true], 'discount > subtotal rejected by the server');

$bad['discount'] = '0';
$bad['items'][0]['quantity'] = '-2';
[$s, $j] = http('POST', "$base/invoices", $bad);
eq([$s, isset($j['errors']['items.0.quantity'])], [422, true], 'negative quantity rejected by the server');

$payload['notes'] = 'Updated';
[$s, $j] = http('PUT', "$base/invoices/$id", $payload);
eq([$s, $j['invoice']['notes'] ?? null], [200, 'Updated'], 'PUT updates');
[$s] = http('PUT', "$base/invoices/$id", $payload);
eq($s, 200, 'PUT with unchanged data is still 200');

[$s, $j] = http('GET', "$base/invoices");
eq([$s, count($j['invoices'] ?? [])], [200, 1], 'list');

[$s, $j] = http('POST', "$base/invoices/$id/email", ['to' => 'stranger@example.com']);
eq([$s, isset($j['errors']['to'])], [422, true], 'recipient allow-list enforced');
[$s, $j] = http('POST', "$base/invoices/$id/email", ['to' => 'bad']);
eq($s, 422, 'invalid recipient rejected');
[$s, $j] = http('POST', "$base/invoices/$id/email", ['to' => 'someone@allowed.test', 'message' => 'Hi']);
eq([$s, $j['status'] ?? null], [200, 'logged'], 'email accepted (log driver) for allowed domain');

[$s] = http('GET', "$base/invoices/99999");
eq($s, 404, 'missing invoice 404');
[$s] = http('PUT', "$base/invoices/99999", $payload);
eq($s, 404, 'update missing invoice 404');
[$s] = http('PATCH', "$base/invoices/$id");
eq($s, 405, 'unsupported method 405');
[$s] = http('GET', "$base/nope");
eq($s, 404, 'unknown route 404');

$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => '{broken', 'ignore_errors' => true]]);
@file_get_contents("$base/invoices", false, $ctx);
eq((int) explode(' ', $http_response_header[0])[1], 400, 'malformed JSON 400');

$html = @file_get_contents("http://127.0.0.1:$port/");
check(is_string($html) && str_contains($html, 'Invoice Studio'), 'GET / serves the app');
$css = @file_get_contents("http://127.0.0.1:$port/assets/app.css");
check(is_string($css) && str_contains($css, '@media print'), 'static assets served');

[$s] = http('DELETE', "$base/invoices/$id");
eq($s, 200, 'DELETE');
[$s] = http('GET', "$base/invoices/$id");
eq($s, 404, 'deleted invoice is gone');

proc_terminate($server);
proc_close($server);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 50) . "\n";
if ($failed) {
    echo count($failed) . " FAILED, $passed passed\n\n";
    foreach ($failed as $f) {
        echo "  ✗ $f\n";
    }
    exit(1);
}
echo "All $passed checks passed ✓\n";
