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
        private int $maxAttempts,
        private bool $immediateEmailEnabled,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Invalid notification retry configuration.');
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
        return hash('sha256', 'notification-recipient-v1|' . strtolower($recipient));
    }

    /**
     * @param array<string, mixed> $order
     */
    public function notifyStatusChange(array $order, string $newStatus): void
    {
        $business = $this->profiles->notificationConfiguration();
        if ($business === null) {
            return;
        }
        $orderId = $order['id'] ?? null;
        $customerRecipient = $order['customer_email'] ?? null;
        if (!is_string($orderId) || !is_string($customerRecipient) || ($business['customer_email_notifications_enabled'] ?? false) !== true) {
            return;
        }

        try {
            $id = UuidGenerator::v4();
            $recipientType = 'customer_status_' . $newStatus;
            if ($this->jobs->create($id, $orderId, $recipientType, $this->recipientHash($customerRecipient), $this->maxAttempts)) {
                if ($this->immediateEmailEnabled) {
                    $this->processor->processOne($id);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('Order status notification job creation failed.', [
                'order_reference' => $order['reference'] ?? null,
                'status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    public function notifyPaymentSuccess(array $order): void
    {
        $business = $this->profiles->notificationConfiguration();
        if ($business === null) {
            return;
        }
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId)) {
            return;
        }

        // 1. Merchant payment received notification
        $merchantRecipient = $business['order_notification_email'] ?? $business['support_email'] ?? null;
        if (($business['merchant_email_notifications_enabled'] ?? false) === true && is_string($merchantRecipient)) {
            try {
                $id = UuidGenerator::v4();
                if ($this->jobs->create($id, $orderId, 'merchant_payment_received', $this->recipientHash($merchantRecipient), $this->maxAttempts)) {
                    if ($this->immediateEmailEnabled) {
                        $this->processor->processOne($id);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('Merchant payment notification job creation failed.', [
                    'order_reference' => $order['reference'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Customer payment confirmed notification
        $customerRecipient = $order['customer_email'] ?? null;
        if (($business['customer_email_notifications_enabled'] ?? false) === true && is_string($customerRecipient)) {
            try {
                $id = UuidGenerator::v4();
                if ($this->jobs->create($id, $orderId, 'customer_payment_confirmed', $this->recipientHash($customerRecipient), $this->maxAttempts)) {
                    if ($this->immediateEmailEnabled) {
                        $this->processor->processOne($id);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('Customer payment notification job creation failed.', [
                    'order_reference' => $order['reference'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    public function notifyLatePaymentRequiresAction(array $order): void
    {
        $business = $this->profiles->notificationConfiguration();
        if ($business === null) {
            return;
        }
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId)) {
            return;
        }

        $merchantRecipient = $business['order_notification_email'] ?? $business['support_email'] ?? null;
        if (($business['merchant_email_notifications_enabled'] ?? false) === true && is_string($merchantRecipient)) {
            try {
                $id = UuidGenerator::v4();
                if ($this->jobs->create($id, $orderId, 'merchant_late_payment_action', $this->recipientHash($merchantRecipient), $this->maxAttempts)) {
                    if ($this->immediateEmailEnabled) {
                        $this->processor->processOne($id);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('Late payment merchant action notification job creation failed.', [
                    'order_reference' => $order['reference'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array{whatsapp_url: null, notification: array{merchant_email: string, customer_email: string}} */
    private function emptyState(): array
    {
        return ['whatsapp_url' => null, 'notification' => ['merchant_email' => 'skipped', 'customer_email' => 'skipped']];
    }
}
