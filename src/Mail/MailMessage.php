<?php

declare(strict_types=1);

namespace App\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $replyTo = null,
    ) {
    }

    /** Full RFC 5322 message (headers + multipart body) with CRLF line endings. */
    public function toMime(): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($this->fromEmail, '@') ?: '@localhost', 1);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'From: ' . self::address($this->fromEmail, $this->fromName),
            'To: ' . self::address($this->to),
            'Subject: ' . self::encodeHeader($this->subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($this->replyTo !== null) {
            $headers[] = 'Reply-To: ' . self::address($this->replyTo);
        }

        $part = static fn (string $type, string $body): string => "--$boundary\r\n"
            . "Content-Type: $type; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $this->text)
            . $part('text/html', $this->html)
            . "--$boundary--\r\n";
    }

    private static function address(string $email, string $name = ''): string
    {
        $email = self::stripNewlines($email);

        return $name === '' ? "<$email>" : self::encodeHeader($name) . " <$email>";
    }

    private static function encodeHeader(string $value): string
    {
        $value = self::stripNewlines($value);

        return preg_match('/^[\x20-\x7E]*$/', $value) ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Defence against header injection — user input must never add headers. */
    private static function stripNewlines(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
