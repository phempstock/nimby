<?php
declare(strict_types=1);

namespace Nimby\Mail;

interface Mailer
{
    /** Send one message, or throw RuntimeException saying why it could not be sent. */
    public function send(Message $message): void;
}
