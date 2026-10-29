<?php
declare(strict_types=1);

namespace Nimby\Mail;

final class MailerFactory
{
    /** 'smtp' (default) or 'log' (writes messages to a file instead of sending). */
    public static function fromConfig(array $config): Mailer
    {
        $mail = $config['mail'] ?? [];
        if (($mail['transport'] ?? 'smtp') === 'log') {
            return new LogMailer($mail['log_file'] ?? ($config['paths']['cache'] . '/mail.log'));
        }
        return new SmtpMailer($mail);
    }
}
