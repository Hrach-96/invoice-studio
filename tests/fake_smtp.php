<?php

declare(strict_types=1);

/*
 * Tiny fake SMTP server for tests (plain TCP, no TLS).
 *   php tests/fake_smtp.php <port> <transcript-file> [fail-auth]
 * Accepts one connection, records everything the client sends, then exits.
 */

[$_, $port, $transcriptFile] = $argv + [null, '2525', __DIR__ . '/.tmp/smtp.txt'];
$failAuth = ($argv[3] ?? '') === 'fail-auth';

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}

$conn = stream_socket_accept($server, 20);
if ($conn === false) {
    exit(1);
}

$log = '';
$say = static function (string $line) use ($conn): void {
    fwrite($conn, $line . "\r\n");
};

$say('220 fake.smtp ready');
$authStep = 0;
while (($line = fgets($conn)) !== false) {
    $log .= $line;
    $cmd = strtoupper(trim($line));

    if ($authStep === 1) {          // username
        $authStep = 2;
        $say('334 UGFzc3dvcmQ6');
        continue;
    }
    if ($authStep === 2) {          // password
        $authStep = 0;
        $say($failAuth ? '535 5.7.8 Authentication credentials invalid' : '235 2.7.0 Authentication successful');
        continue;
    }

    if (str_starts_with($cmd, 'EHLO')) {
        $say('250-fake.smtp');
        $say('250-AUTH LOGIN PLAIN');
        $say('250 8BITMIME');
    } elseif ($cmd === 'AUTH LOGIN') {
        $authStep = 1;
        $say('334 VXNlcm5hbWU6');
    } elseif (str_starts_with($cmd, 'MAIL FROM') || str_starts_with($cmd, 'RCPT TO')) {
        $say('250 OK');
    } elseif ($cmd === 'DATA') {
        $say('354 End data with <CR><LF>.<CR><LF>');
        while (($data = fgets($conn)) !== false) {
            $log .= $data;
            if ($data === ".\r\n") {
                break;
            }
        }
        $say('250 OK queued');
    } elseif ($cmd === 'QUIT') {
        $say('221 Bye');
        break;
    } else {
        $say('502 Command not implemented');
    }
}

file_put_contents($transcriptFile, $log);
fclose($conn);
