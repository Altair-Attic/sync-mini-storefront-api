<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class NotificationService
{
    public function __construct(
        private BusinessProfileRepository $profiles,
        private NotificationJobRepository $jobs,
        private NotificationProcessor $processor,
        private WhatsAppHandoffService $whatsapp,
        private LoggerInterface $logger,
        private string $securitySecret,
        private int $maxAttempts,
        private bool $immediateEmailEnabled,
    ) {
        if (strlen($securitySecret) < 32 || $maxAttempts < 1) {
            throw new \InvalidArgumentException('Invalid notification security or retry configuration.');
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array{whatsapp_url: string|null, notification: array{merchant_email: string, customer_email: string}}
     */
    public function checkoutState(array $order, bool $replay): array
    {
        $business = $this->profiles->notificationConfiguration();
        if ($business === null) {
            return $this->emptyState();
        }
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId)) {
            return $this->emptyState();
        }
        if (!$replay) {
            try {
                $this->createJobs($order, $business);
            } catch (Throwable) {
                $this->logger->error('Notification job creation failed.', ['order_reference' => $order['reference'] ?? null]);
            }
        }
        $states = $this->jobs->stateForOrder($orderId);

        return [
            'whatsapp_url' => $this->whatsapp->url($order, $business),
            'notification' => [
                'merchant_email' => $states['merchant'] ?? 'skipped',
                'customer_email' => $states['customer'] ?? 'skipped',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $business
     */
    private function createJobs(array $order, array $business): void
    {
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId)) {
            return;
        }
        $created = [];
        $merchantRecipient = $business['order_notification_email'] ?? $business['support_email'] ?? null;
        if (($business['merchant_email_notifications_enabled'] ?? false) === true && is_string($merchantRecipient)) {
            $id = UuidGenerator::v4();
            if ($this->jobs->create($id, $orderId, 'merchant', $this->recipientHash($merchantRecipient), $this->maxAttempts)) {
                $created[] = $id;
            }
        }
        $customerRecipient = $order['customer_email'] ?? null;
        if (($business['customer_email_notifications_enabled'] ?? false) === true && is_string($customerRecipient)) {
            $id = UuidGenerator::v4();
            if ($this->jobs->create($id, $orderId, 'customer', $this->recipientHash($customerRecipient), $this->maxAttempts)) {
                $created[] = $id;
            }
        }
        if ($this->immediateEmailEnabled) {
            foreach ($created as $id) {
                $this->processor->processOne($id);
            }
        }
    }

    private function recipientHash(string $recipient): string
    {
        return hash_hmac('sha256', 'notification-recipient-v1|' . strtolower($recipient), $this->securitySecret);
    }

    /** @return array{whatsapp_url: null, notification: array{merchant_email: string, customer_email: string}} */
    private function emptyState(): array
    {
        return ['whatsapp_url' => null, 'notification' => ['merchant_email' => 'skipped', 'customer_email' => 'skipped']];
    }
}
