<?php
declare(strict_types=1);

/**
 * Weekly email sign-up.
 *   GET  ?status   -> {"open": true|false}  (the map page shows the form only when open)
 *   POST email, name, consent, website (honeypot)
 * Answers JSON to the map page's script, or a full page without JavaScript.
 */

use Nimby\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['status'])) {
        try {
            $open = Web::settings()->signupOpen();
        } catch (Throwable $e) {
            $open = false;   // not set up yet (or the database is down): just hide the form
        }
        Web::json(['open' => $open]);
    }
    header('Location: ./#signup', true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$result = Web::signup()->subscribe(
    (string) ($_POST['email'] ?? ''),
    isset($_POST['name']) ? (string) $_POST['name'] : null,
    ($_POST['consent'] ?? '') === 'yes',
    (string) ($_POST['website'] ?? ''),
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    Web::now()
);

$status = $result['ok'] ? 200 : ($result['code'] === 'too_many' ? 429 : ($result['code'] === 'send_failed' ? 503 : 400));
if (Web::wantsJson()) {
    Web::json($result, $status);
}
Web::page(
    $result['ok'] ? 'Check your inbox' : 'Sign-up not completed',
    '<p>' . Web::e($result['message']) . '</p>' . ($result['ok'] ? '' : '<p><a href="./#signup">Try again</a></p>'),
    $status
);
