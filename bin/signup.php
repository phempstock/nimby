<?php
declare(strict_types=1);

/**
 * Open or close the weekly email sign-up form, or show subscriber numbers.
 *
 *   php bin/signup.php open|close|status
 *
 * Output gives counts only, never addresses.
 */

use Nimby\Storage\Database;
use Nimby\Storage\Settings;
use Nimby\Subscribers\SubscriberStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$action = $argv[1] ?? 'status';
$pdo = Database::connect(nimby_config()['db']);
$settings = new Settings($pdo);

if ($action === 'open' || $action === 'close') {
    $settings->set('signup_open', $action === 'open' ? '1' : '0');
} elseif ($action !== 'status') {
    fwrite(STDERR, "Usage: php bin/signup.php open|close|status\n");
    exit(2);
}

$c = (new SubscriberStore($pdo))->counts();
printf("Sign-up form: %s\nSubscribers: %d active, %d awaiting confirmation, %d unsubscribed\n",
    $settings->signupOpen() ? 'open' : 'closed', $c['active'], $c['pending'], $c['unsubscribed']);
