<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Email;

use PHPMailer\PHPMailer\PHPMailer;
use ProjectSync\Infrastructure\Config;
use Throwable;

final readonly class SmtpEmailSender implements EmailSenderInterface
{
    public function __construct(private Config $config)
    {
    }

    public function send(EmailMessage $message): void
    {
        if (!$this->config->bool('mail.enabled')) {
            throw new EmailDeliveryException('MAIL_DISABLED');
        }
        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $this->config->requiredString('mail.host');
            $mailer->Port = (int) $this->config->requiredString('mail.port');
            $username = $this->config->string('mail.username');
            $password = $this->config->string('mail.password');
            $mailer->SMTPAuth = $username !== '';
            $mailer->Username = $username;
            $mailer->Password = $password;
            $encryption = $this->config->requiredString('mail.encryption');
            if ($encryption === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl' || $encryption === 'smtps') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption !== 'none') {
                throw new EmailDeliveryException('MAIL_CONFIGURATION_INVALID');
            }
            $mailer->Timeout = (int) $this->config->requiredString('mail.timeout_seconds');
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($this->config->requiredString('mail.from_address'), $this->config->requiredString('mail.from_name'));
            $mailer->addAddress($message->recipient);
            $mailer->Subject = $message->subject;
            $mailer->Body = $message->body;
            $mailer->isHTML(false);
            $mailer->send();
        } catch (EmailDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new EmailDeliveryException('SMTP_DELIVERY_FAILED', 0, $exception);
        }
    }
}
