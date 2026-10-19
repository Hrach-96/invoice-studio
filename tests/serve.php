<?php

declare(strict_types=1);

/*
 * Router for running the browser tests:
 *   php -S 127.0.0.1:8766 -t public tests/serve.php
 * then open http://127.0.0.1:8766/tests/browser.html
 * Serves /tests/* from this folder, everything else like public/index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Progress lines from the test page (append) — handy when a run hangs.
if ($path === '/tests/progress' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    @mkdir(__DIR__ . '/.tmp', 0775, true);
    file_put_contents(__DIR__ . '/.tmp/browser-progress.txt', file_get_contents('php://input') . "\n", FILE_APPEND);

    return true;
}

// The test page posts its results here so CLI runs can read them.
if ($path === '/tests/report' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    @mkdir(__DIR__ . '/.tmp', 0775, true);
    file_put_contents(__DIR__ . '/.tmp/browser-report.txt', file_get_contents('php://input'));

    return true;
}

if (str_starts_with($path, '/tests/')) {
    $file = realpath(__DIR__ . '/' . substr($path, 7));
    if ($file === false || !str_starts_with($file, realpath(__DIR__)) || !is_file($file)) {
        http_response_code(404);

        return true;
    }
    $types = ['html' => 'text/html', 'js' => 'text/javascript', 'json' => 'application/json'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'text/plain') . '; charset=utf-8');
    readfile($file);

    return true;
}

return require __DIR__ . '/../public/index.php';
