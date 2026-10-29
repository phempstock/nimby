<?php
declare(strict_types=1);

/**
 * Confirm a sign-up. The emailed link (GET) shows a button; only pressing it (POST)
 * confirms, so email security scanners that follow links can't confirm on someone's behalf.
 */

use Nimby\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Web::page('Confirm your email address',
        '<p>Press the button to start receiving the NIMBY weekly email of new planning applications in and around The Park.</p>'
        . '<form method="post" action="confirm.php" class="page-form">'
        . '<input type="hidden" name="token" value="' . Web::e($token) . '">'
        . '<button type="submit" class="page-button">Confirm my email address</button></form>');
}

$result = Web::signup()->confirm($token, Web::now());
match ($result['code']) {
    'confirmed' => Web::page('You’re subscribed',
        '<p>Thank you. The NIMBY weekly email will arrive on Monday mornings when there are new planning applications in or near The Park.</p>'
        . '<p>Every email has a link to unsubscribe.</p>'),
    'expired' => Web::page('This link has expired',
        '<p>Confirmation links work for 7 days. <a href="./#signup">Sign up again</a> and we’ll send a new one.</p>', 410),
    default => Web::page('This link doesn’t work',
        '<p>It may have been used already, in which case you’re subscribed. If not, <a href="./#signup">sign up again</a>.</p>', 404),
};
