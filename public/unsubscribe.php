<?php
declare(strict_types=1);

/**
 * Unsubscribe.
 *   GET  ?s=ID&t=TOKEN  -> a page with a button (link scanners must not unsubscribe people)
 *   POST s, t           -> unsubscribe and say so
 *   POST from an email program's own unsubscribe button (RFC 8058,
 *   body "List-Unsubscribe=One-Click", s and t in the URL) -> unsubscribe, plain 200
 */

use Nimby\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();

$id = (int) ($_POST['s'] ?? $_GET['s'] ?? 0);
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$signup = Web::signup();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $done = $signup->unsubscribe($id, $token, Web::now());
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        http_response_code($done ? 200 : 404);
        header('Content-Type: text/plain; charset=utf-8');
        echo $done ? "Unsubscribed\n" : "Link not recognised\n";
        exit;
    }
    if ($done) {
        Web::page('You’ve unsubscribed',
            '<p>You won’t receive the NIMBY weekly email any more. Your address will be deleted within 30 days.</p>'
            . '<p>Changed your mind? You can <a href="./#signup">sign up again</a> at any time.</p>');
    }
    Web::page('This link doesn’t work', '<p>We couldn’t find a subscription for this link. You may have unsubscribed already.</p>', 404);
}

$subscriber = $signup->checkUnsubscribe($id, $token);
if (!$subscriber) {
    Web::page('This link doesn’t work', '<p>We couldn’t find a subscription for this link. You may have unsubscribed already.</p>', 404);
}
if ($subscriber['status'] === 'unsubscribed') {
    Web::page('You’ve already unsubscribed', '<p>You won’t receive the NIMBY weekly email any more.</p>');
}
Web::page('Unsubscribe from the weekly email',
    '<p>Stop sending the NIMBY weekly email to <strong>' . Web::e($subscriber['email']) . '</strong>?</p>'
    . '<form method="post" action="unsubscribe.php" class="page-form">'
    . '<input type="hidden" name="s" value="' . $id . '"><input type="hidden" name="t" value="' . Web::e($token) . '">'
    . '<button type="submit" class="page-button">Unsubscribe</button></form>');
