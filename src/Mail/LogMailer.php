<?php
declare(strict_types=1);

namespace Nimby\Mail;

use RuntimeException;

/**
 * Sends nothing: keeps each message in memory and, if given a file, appends it
 * there as a line of JSON. For tests and local trial runs.
 */
final class LogMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];
    /** @var list<string> recipients whose sending should fail (for tests) */
    public array $failFor = [];

    public function __construct(private ?string $file = null)
    {
    }

    public function send(Message $message): void
    {
        if (in_array($message->to, $this->failFor, true)) {
            throw new RuntimeException('Email could not be sent: simulated failure');
        }
        $this->sent[] = $message;
        if ($this->file) {
            $line = json_encode([
                'at' => gmdate('c'), 'to' => $message->to, 'subject' => $message->subject,
                'headers' => $message->headers, 'text' => $message->text, 'html' => $message->html,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
