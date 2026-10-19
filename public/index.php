<?php

declare(strict_types=1);

/*
 * Front controller.
 *  - /api/*  -> JSON API (src/Api.php)
 *  - else    -> the single-page app (app.html)
 * Works with `php -S ... public/index.php`, nginx try_files and Apache .htaccess.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// PHP built-in server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (preg_match('#(^|/)api(/|$)#', $path)) {
    require __DIR__ . '/../src/bootstrap.php';

    $config = mail_config();
    $api = new App\Api(
        new App\InvoiceRepository(App\Database::fromEnv(APP_ROOT)),
        make_mailer($config),
        $config,
    );
    $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

    return;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/app.html');
