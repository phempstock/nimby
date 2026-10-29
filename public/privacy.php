<?php
declare(strict_types=1);

/** Privacy notice for the weekly email and the site. */

use Nimby\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();
header('Cache-Control: no-cache');

$site = Web::config()['site'];
$org = Web::e($site['organisation']);
$contact = Web::e($site['contact_email']);

Web::page('Privacy notice', <<<HTML
<p class="page-lead">NIMBY is run by {$org}. This notice explains what we keep about you if you sign up for the weekly email, and why. Questions or requests: <a href="mailto:{$contact}">{$contact}</a>.</p>

<h2>What we keep</h2>
<ul>
  <li>Your email address, and your name if you give one.</li>
  <li>When you signed up and when you confirmed your address. The confirmation is our record of your consent.</li>
  <li>Which weekly emails we sent you, and when.</li>
  <li>For up to a day, a scrambled (hashed) version of your internet address when you use the sign-up form, so we can stop the form being misused. We can’t turn it back into your address.</li>
</ul>

<h2>Why</h2>
<p>Only to send you the weekly email of new planning applications in and around The Park, which you asked for. The legal basis is your consent, which you can withdraw at any time using the unsubscribe link in every email.</p>

<h2>How long we keep it</h2>
<ul>
  <li>While you are subscribed.</li>
  <li>If you don’t confirm your address, it is deleted after 7 days.</li>
  <li>If you unsubscribe, your details are deleted after 30 days.</li>
</ul>

<h2>Who can see it</h2>
<p>Only the member of the Residents’ Association committee who runs this site. Your details are stored with IONOS, which hosts the site, and the emails are sent through IONOS’s mail service. We never sell or share your details, and never use them for anything else.</p>

<h2>Your rights</h2>
<p>You can ask to see the details we hold about you, to correct them, or to have them deleted, by emailing <a href="mailto:{$contact}">{$contact}</a>. If you are unhappy with how we handle your details, you can complain to the Information Commissioner’s Office at <a href="https://ico.org.uk/make-a-complaint/">ico.org.uk</a>.</p>

<h2>Cookies and the map</h2>
<p>This site sets no cookies and uses no tracking or analytics. The map images come from OpenStreetMap, whose servers see your internet address as any website does; see the <a href="https://osmfoundation.org/wiki/Privacy_Policy">OpenStreetMap Foundation’s privacy policy</a>.</p>

<h2>The planning information</h2>
<p>The applications shown come from Nottingham City Council’s public planning register, published as open data. NIMBY doesn’t add the names of applicants or agents.</p>
HTML);
