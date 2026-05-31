<?php

require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

class BankImportHelper
{
    private static $envLoaded = false;

    public static function loadEnv()
    {
        if (self::$envLoaded) return;

        $dotenv = Dotenv::createImmutable(__DIR__ . '/../..'); // Modulstamm
        $dotenv->safeLoad(); // lädt nur, existiert sie nicht, kein Fehler
        self::$envLoaded = true;
    }

    public static function getEnv($key, $default = null)
    {
        self::loadEnv();
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        // getenv() returns false (not null) for an unset variable, and ?? only
        // falls through on null — so the previous `getenv($key) ?? $default`
        // returned false instead of the default on a clean install with no .env
        // and no CI-provided value. Check for false explicitly.
        $val = getenv($key);
        return $val !== false ? $val : $default;
    }
}
