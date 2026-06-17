<?php

declare(strict_types=1);

function loadLocalEnv(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Failed to read environment file: ' . $file);
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $delimiter = strpos($line, '=');
        if ($delimiter === false) {
            continue;
        }

        $name = trim(substr($line, 0, $delimiter));
        if ($name === '') {
            continue;
        }

        $value = trim(substr($line, $delimiter + 1));
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function envValue(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException('Missing required environment variable: ' . $name);
    }

    return $value;
}

function createPdoFromEnvironment(): PDO
{
    loadLocalEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

    $host = envValue('DB_HOST', '127.0.0.1');
    $port = (int) envValue('DB_PORT', '3306');
    $database = envValue('DB_NAME');
    $username = envValue('DB_USER', 'root');
    $password = envValue('DB_PASS', '');
    $charset = envValue('DB_CHARSET', 'utf8mb4');

    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}
