<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Development fallback: writes each email to storage/mail/*.eml instead of
 * sending it. Lets the app run without SMTP credentials; open the .eml file
 * in any mail client to see exactly what would have been sent.
 */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $directory)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MailException('Could not create the mail log directory.');
        }

        $file = $this->directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        if (file_put_contents($file, $message->toMime()) === false) {
            throw new MailException('Could not write the email to the mail log.');
        }
    }

    public function describe(): string
    {
        return 'Log only (saved to storage/mail, not delivered)';
    }
}
