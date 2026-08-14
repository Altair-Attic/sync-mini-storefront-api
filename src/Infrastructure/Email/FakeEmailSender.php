<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Email;

final class FakeEmailSender implements EmailSenderInterface
{
    /** @var list<EmailMessage> */
    public array $messages = [];
    public int $attempts = 0;

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function send(EmailMessage $message): void
    {
        $this->attempts++;
        if ($this->fail) {
            throw new EmailDeliveryException('FAKE_DELIVERY_FAILED');
        }
        $this->messages[] = $message;
    }
}
