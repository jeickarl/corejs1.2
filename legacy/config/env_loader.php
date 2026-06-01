<?php

final class EnvLoader
{
    public static function load(): void
    {
        $base = __DIR__;
        self::loadFile($base . DIRECTORY_SEPARATOR . '.env', false);
        self::loadFile($base . DIRECTORY_SEPARATOR . '.env.local', true);
    }

    private static function loadFile(string $path, bool $override): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }

            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $q = $val[0];
                if (substr($val, -1) === $q) {
                    $val = substr($val, 1, -1);
                }
            }

            $current = getenv($key);
            if ($override || $current === false || $current === '') {
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }
}

EnvLoader::load();
