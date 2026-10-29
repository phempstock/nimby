<?php
declare(strict_types=1);

namespace Nimby\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Sends through an authenticated SMTP server (IONOS: smtp.ionos.co.uk, port 587,
 * STARTTLS), so IONOS signs each message with the domain's DKIM key.
 * One connection is kept open for a whole digest run.
 */
final class SmtpMailer implements Mailer
{
    private ?PHPMailer $mail = null;

    public function __construct(private array $config)
    {
    }

    public function send(Message $message): void
    {
        $mail = $this->connection();
        try {
            $mail->clearAddresses();
            $mail->clearCustomHeaders();
            $mail->addAddress($message->to, $message->toName ?? '');
            $mail->Subject = $message->subject;
            if ($message->html !== null) {
                $mail->isHTML(true);
                $mail->Body = $message->html;
                $mail->AltBody = $message->text;
            } else {
                $mail->isHTML(false);
                $mail->Body = $message->text;
                $mail->AltBody = '';
            }
            foreach ($message->headers as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }
            $mail->send();
        } catch (PHPMailerException $e) {
            $reason = $mail->ErrorInfo ?: $e->getMessage();
            $mail->smtpClose();
            $this->mail = null;   // reconnect for the next message
            throw new RuntimeException('Email could not be sent: ' . $reason);
        }
    }

    public function __destruct()
    {
        $this->mail?->smtpClose();
    }

    private function connection(): PHPMailer
    {
        if ($this->mail) {
            return $this->mail;
        }
        // Checked only when something is actually sent, so a digest run with nobody
        // to email works before the mail settings are installed.
        foreach (['host', 'username', 'password', 'from_email'] as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException("Mail setting '{$key}' is missing (see config.mail.php)");
            }
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = (int) ($this->config['port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];
        $mail->SMTPSecure = ($this->config['encryption'] ?? 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPKeepAlive = true;
        $mail->Timeout = 30;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = ' ';
        $mail->setFrom($this->config['from_email'], $this->config['from_name'] ?? '');
        if (!empty($this->config['reply_to'])) {
            $mail->addReplyTo($this->config['reply_to']);
        }
        return $this->mail = $mail;
    }
}
