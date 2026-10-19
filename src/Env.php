<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal .env loader. Real environment variables always win over the file,
 * so the same code works locally and on a host that injects secrets.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $quoted = strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[-1] === $value[0];
            if ($quoted) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }
}
