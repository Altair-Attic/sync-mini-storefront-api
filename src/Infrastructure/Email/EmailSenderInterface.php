<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Email;

interface EmailSenderInterface
{
    public function send(EmailMessage $message): void;
}
