<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use PDO;
use ProjectSync\Infrastructure\Email\EmailSenderInterface;
use ProjectSync\Infrastructure\Email\SmtpEmailSender;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Services\NotificationProcessor;
use ProjectSync\Services\NotificationService;
use ProjectSync\Services\OrderEmailBuilder;
use ProjectSync\Services\WhatsAppHandoffService;
use Psr\Log\LoggerInterface;

final class NotificationFactory
{
    public static function service(PDO $db, Config $config, LoggerInterface $logger, ?EmailSenderInterface $sender = null): NotificationService
    {
        $jobs = new NotificationJobRepository($db);
        $processor = self::processor($db, $config, $logger, $sender, $jobs);

        return new NotificationService(
            new BusinessProfileRepository($db),
            $jobs,
            $processor,
            new WhatsAppHandoffService(),
            $logger,
            $config->requiredString('notifications.security_secret'),
            (int) $config->requiredString('notifications.max_attempts'),
            $config->bool('mail.enabled'),
        );
    }

    public static function processor(PDO $db, Config $config, LoggerInterface $logger, ?EmailSenderInterface $sender = null, ?NotificationJobRepository $jobs = null): NotificationProcessor
    {
        return new NotificationProcessor(
            $jobs ?? new NotificationJobRepository($db),
            new OrderRepository($db),
            new OrderItemRepository($db),
            new BusinessProfileRepository($db),
            $sender ?? new SmtpEmailSender($config),
            new OrderEmailBuilder(),
            $logger,
            (int) $config->requiredString('notifications.retry_base_seconds'),
            (int) $config->requiredString('notifications.processing_timeout_seconds'),
        );
    }
}
