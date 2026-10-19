<?php

declare(strict_types=1);

namespace App\Mail;

interface Mailer
{
    /** @throws MailException when the message could not be handed off. */
    public function send(MailMessage $message): void;

    /** Short label for the UI, e.g. "SMTP (smtp.example.com)". */
    public function describe(): string;
}
