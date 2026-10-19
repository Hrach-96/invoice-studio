<?php

declare(strict_types=1);

use App\Env;
use App\Mail\LogMailer;
use App\Mail\Mailer;
use App\Mail\SmtpMailer;

const APP_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

Env::load(APP_ROOT . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC'));

/** @return array{driver: string, fromAddress: string, fromName: string, allowedRecipients: list<string>} */
function mail_config(): array
{
    $allowed = array_values(array_filter(array_map(
        static fn (string $s): string => strtolower(trim($s)),
        explode(',', Env::get('MAIL_ALLOWED_RECIPIENTS', '')),
    )));

    return [
        'driver' => Env::get('MAIL_DRIVER', 'log') === 'smtp' ? 'smtp' : 'log',
        'fromAddress' => Env::get('MAIL_FROM_ADDRESS', 'invoices@example.com'),
        'fromName' => Env::get('MAIL_FROM_NAME', 'Invoices'),
        'allowedRecipients' => $allowed,
    ];
}

function make_mailer(array $config): Mailer
{
    if ($config['driver'] !== 'smtp') {
        return new LogMailer(Env::get('MAIL_LOG_DIR', APP_ROOT . '/storage/mail'));
    }

    return new SmtpMailer(
        host: Env::get('SMTP_HOST', ''),
        port: (int) Env::get('SMTP_PORT', '587'),
        encryption: strtolower(Env::get('SMTP_ENCRYPTION', 'tls')),
        username: Env::get('SMTP_USERNAME', ''),
        password: Env::get('SMTP_PASSWORD', ''),
        caFile: Env::get('SMTP_CA_FILE'),
    );
}
