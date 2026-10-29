<?php
declare(strict_types=1);

namespace Nimby\Subscribers;

use Nimby\Mail\EmailLayout;
use Nimby\Mail\Mailer;
use Nimby\Mail\Message;
use Nimby\Storage\Settings;

/**
 * Sign-up with double opt-in, confirmation and unsubscribe.
 *
 * Every outcome of a sign-up shows the same "check your inbox" message, so the
 * form never reveals whether an address is already on the list.
 */
final class SignupService
{
    public const CONFIRM_DAYS = 7;
    public const RESEND_MINUTES = 10;
    public const MAX_PER_IP_PER_HOUR = 5;
    public const MAX_PER_HOUR = 60;

    public const CHECK_INBOX = 'Check your inbox. We’ve sent you a link to confirm your address; it works for 7 days.';

    public function __construct(
        private SubscriberStore $store,
        private Settings $settings,
        private Mailer $mailer,
        private string $siteUrl
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    public function subscribe(string $email, ?string $name, bool $consent, string $honeypot, string $ip, string $now): array
    {
        if (!$this->settings->signupOpen()) {
            return $this->result(false, 'closed', 'Sign-up for the weekly email isn’t open yet.');
        }
        if (trim($honeypot) !== '') {
            return $this->result(true, 'ok', self::CHECK_INBOX);   // a bot: pretend all is well
        }

        $email = self::normaliseEmail($email);
        if ($email === null) {
            return $this->result(false, 'invalid_email', 'Enter a valid email address, like name@example.com.');
        }
        if (!$consent) {
            return $this->result(false, 'consent', 'Tick the box to agree to receive the email.');
        }

        $t = strtotime($now . ' UTC');
        $hourAgo = gmdate('Y-m-d H:i:s', $t - 3600);
        $ipHash = hash_hmac('sha256', $ip, $this->settings->secret());
        if ($this->store->attemptsSince($hourAgo, $ipHash) >= self::MAX_PER_IP_PER_HOUR
            || $this->store->attemptsSince($hourAgo) >= self::MAX_PER_HOUR) {
            return $this->result(false, 'too_many', 'Too many sign-ups from here just now. Please try again in an hour.');
        }
        $this->store->recordAttempt($ipHash, $now);

        $name = self::cleanName($name);
        $existing = $this->store->findByEmail($email);
        if ($existing && $existing['status'] === 'active') {
            return $this->result(true, 'ok', self::CHECK_INBOX);
        }
        if ($existing && $existing['status'] === 'pending'
            && strtotime($existing['confirm_sent_at'] . ' UTC') > $t - self::RESEND_MINUTES * 60) {
            return $this->result(true, 'ok', self::CHECK_INBOX);   // just sent one; don't flood their inbox
        }

        $token = bin2hex(random_bytes(24));
        if ($existing) {
            $this->store->makePending((int) $existing['id'], $name, hash('sha256', $token), $now);
        } else {
            $this->store->createPending($email, $name, hash('sha256', $token), $now);
        }

        try {
            $this->mailer->send($this->confirmationMessage($email, $name, $token));
        } catch (\RuntimeException $e) {
            error_log('NIMBY: confirmation email failed: ' . $e->getMessage());
            return $this->result(false, 'send_failed', 'We couldn’t send the confirmation email just now. Please try again later.');
        }
        return $this->result(true, 'ok', self::CHECK_INBOX);
    }

    /**
     * @return array{ok: bool, code: string} code: confirmed | invalid | expired
     */
    public function confirm(string $token, string $now): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $row = $this->store->findByConfirmHash(hash('sha256', $token));
        if (!$row) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        if (strtotime($row['confirm_sent_at'] . ' UTC') < strtotime($now . ' UTC') - self::CONFIRM_DAYS * 86400) {
            return ['ok' => false, 'code' => 'expired'];
        }
        $this->store->activate((int) $row['id'], $now);
        return ['ok' => true, 'code' => 'confirmed'];
    }

    public function unsubscribeUrl(int $subscriberId): string
    {
        return $this->siteUrl . '/unsubscribe.php?s=' . $subscriberId . '&t=' . $this->unsubscribeToken($subscriberId);
    }

    public function unsubscribeToken(int $subscriberId): string
    {
        return substr(hash_hmac('sha256', 'unsubscribe:' . $subscriberId, $this->settings->secret()), 0, 32);
    }

    /** The subscriber for a valid unsubscribe link, or null. */
    public function checkUnsubscribe(int $subscriberId, string $token): ?array
    {
        if ($subscriberId <= 0 || !hash_equals($this->unsubscribeToken($subscriberId), $token)) {
            return null;
        }
        return $this->store->find($subscriberId);
    }

    public function unsubscribe(int $subscriberId, string $token, string $now): bool
    {
        $row = $this->checkUnsubscribe($subscriberId, $token);
        if (!$row) {
            return false;
        }
        if ($row['status'] !== 'unsubscribed') {
            $this->store->unsubscribe($subscriberId, $now);
        }
        return true;
    }

    public static function normaliseEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        // Lower-cased throughout: mail systems treat Jane.Doe@ and jane.doe@ as the same
        // person, and so must we, or one person could be subscribed twice.
        return mb_strtolower($email);
    }

    private static function cleanName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', (string) $name));
        $name = mb_substr($name, 0, 100);
        return $name === '' ? null : $name;
    }

    private function confirmationMessage(string $email, ?string $name, string $token): Message
    {
        $url = $this->siteUrl . '/confirm.php?token=' . $token;
        $hello = $name ? 'Hello ' . $name . ',' : 'Hello,';
        $text = "{$hello}\n\n"
            . "Someone, hopefully you, asked for the NIMBY weekly email: a Monday-morning list of new planning "
            . "applications in and around The Park, Nottingham.\n\n"
            . "To start receiving it, confirm your address within 7 days:\n{$url}\n\n"
            . "If this wasn’t you, ignore this email. Your address will be deleted within 7 days and you won’t hear from us again.\n\n"
            . "NIMBY – Planning watch for The Park\n{$this->siteUrl}\n";

        $content = '<p style="margin:0 0 16px">' . EmailLayout::e($hello) . '</p>'
            . '<p style="margin:0 0 16px">Someone, hopefully you, asked for the NIMBY weekly email: a Monday-morning list of new planning applications in and around The Park, Nottingham.</p>'
            . '<p style="margin:0 0 20px">To start receiving it, confirm your address within 7 days.</p>'
            . '<p style="margin:0 0 24px">' . EmailLayout::button($url, 'Confirm my email address') . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;color:#56635e">If the button doesn’t work, copy this link into your browser:<br>'
            . '<span style="word-break:break-all">' . EmailLayout::e($url) . '</span></p>';
        $footer = 'If this wasn’t you, ignore this email. Your address will be deleted within 7 days and you won’t hear from us again.';

        return new Message(
            $email,
            'Confirm your NIMBY weekly email',
            $text,
            EmailLayout::wrap($content, $footer, 'Confirm your address to start receiving the weekly list of planning applications.'),
            $name
        );
    }

    private function result(bool $ok, string $code, string $message): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message];
    }
}
