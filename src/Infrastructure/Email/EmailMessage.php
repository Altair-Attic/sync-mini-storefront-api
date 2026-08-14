<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Email;

final readonly class EmailMessage
{
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $body,
    ) {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || str_contains($recipient, "\r") || str_contains($recipient, "\n")) {
            throw new \InvalidArgumentException('Invalid email recipient.');
        }
        if ($subject === '' || str_contains($subject, "\r") || str_contains($subject, "\n")) {
            throw new \InvalidArgumentException('Invalid email subject.');
        }
    }
}
