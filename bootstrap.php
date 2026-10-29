<?php
declare(strict_types=1);

/**
 * Shared start-up for command-line scripts and web pages:
 * autoloads classes from src/ and loads config.php.
 */

date_default_timezone_set('Europe/London');

// PHPMailer, vendored in lib/phpmailer/ (see lib/phpmailer/VERSION.txt).
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/lib/phpmailer/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

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
    // Optional second file for the mail settings, so the password can be added
    // without editing config.php. Its values override config.php's.
    $mailFile = dirname($path) . '/config.mail.php';
    if (is_file($mailFile)) {
        $mail = require $mailFile;
        if (is_array($mail)) {
            $config = array_replace_recursive($config, $mail);
        }
    }
    // Defaults for settings added after the site went live.
    $config['site'] = ($config['site'] ?? []) + [
        'url'           => 'https://nimby.parknews.co.uk',
        'organisation'  => 'The Park Estate Residents\' Association',
        'contact_email' => 'nimby@parknews.co.uk',
    ];
    return $config;
}
