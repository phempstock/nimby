<?php
declare(strict_types=1);

namespace Nimby\Mail;

/** One email to one recipient. */
final class Message
{
    /**
     * @param array<string, string> $headers extra headers, e.g. List-Unsubscribe
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public ?string $toName = null,
        public array $headers = []
    ) {
    }
}
