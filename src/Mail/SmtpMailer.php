<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Small dependency-free SMTP client: implicit TLS (465) or STARTTLS (587),
 * AUTH LOGIN, one recipient per message. Works with Mailtrap, Brevo,
 * SendGrid/Mailgun SMTP relays, Gmail app passwords, etc.
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // "ssl" | "tls" | "none"
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $caFile = null,
        private readonly int $timeout = 20,
    ) {
    }

    public function describe(): string
    {
        return "SMTP ({$this->host})";
    }

    public function send(MailMessage $message): void
    {
        try {
            $this->connect();
            $this->command('EHLO ' . $this->heloName(), 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                $methods = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (@stream_socket_enable_crypto($this->socket, true, $methods) !== true) {
                    throw new MailException('Could not start TLS with the SMTP server. Check SMTP_CA_FILE / certificates.');
                }
                $this->command('EHLO ' . $this->heloName(), 250);
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334, hideInErrors: true);
                $this->command(base64_encode($this->password), 235, hideInErrors: true);
            }

            $this->command('MAIL FROM:<' . $message->fromEmail . '>', 250);
            $this->command('RCPT TO:<' . $message->to . '>', [250, 251]);
            $this->command('DATA', 354);

            // Dot-stuffing (RFC 5321 §4.5.2): lines starting with "." get an extra ".".
            $body = preg_replace('/^\./m', '..', $message->toMime());
            $this->command($body . "\r\n.", 250);
            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host];
        if ($this->caFile !== null && $this->caFile !== '') {
            $ssl['cafile'] = $this->caFile;
        }

        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            "$scheme://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl]),
        );
        if ($socket === false) {
            throw new MailException("Could not connect to SMTP server {$this->host}:{$this->port} ($errstr).");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->expect(220);
    }

    /** @param int|list<int> $expected */
    private function command(string $line, int|array $expected, bool $hideInErrors = false): void
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new MailException('Lost connection to the SMTP server.');
        }
        $this->expect($expected, $hideInErrors ? '[credentials]' : strtok($line, "\r\n"));
    }

    /** @param int|list<int> $expected */
    private function expect(int|array $expected, string $after = 'connect'): void
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-..." and end with "250 ...".
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            $detail = $response === '' ? 'no response (timeout)' : trim($response);
            throw new MailException("SMTP error after \"$after\": $detail");
        }
    }

    private function heloName(): string
    {
        $name = gethostname();

        return is_string($name) && preg_match('/^[A-Za-z0-9.-]+$/', $name) ? $name : 'localhost';
    }
}
