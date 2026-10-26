<?php
declare(strict_types=1);

/**
 * Shared start-up for command-line scripts and web pages:
 * autoloads classes from src/ and loads config.php.
 */

date_default_timezone_set('Europe/London');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nimby\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Load the configuration array. Throws if config.php is missing so a
 * misconfigured server fails loudly rather than silently doing nothing.
 */
function nimby_config(?string $path = null): array
{
    $path ??= __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException("Configuration file not found: {$path}. Copy config.example.php to config.php.");
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException("Configuration file must return an array: {$path}");
    }
    return $config;
}
