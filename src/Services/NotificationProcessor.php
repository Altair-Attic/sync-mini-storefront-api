<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\Email\EmailDeliveryException;
use ProjectSync\Infrastructure\Email\EmailSenderInterface;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class NotificationProcessor
{
    public function __construct(
        private NotificationJobRepository $jobs,
        private OrderRepository $orders,
        private OrderItemRepository $items,
        private BusinessProfileRepository $profiles,
        private EmailSenderInterface $sender,
        private OrderEmailBuilder $emails,
        private LoggerInterface $logger,
        private int $baseDelaySeconds,
        private int $processingTimeoutSeconds,
    ) {
    }

    /** @return array{claimed: int, sent: int, failed: int, recovered: int} */
    public function process(int $limit): array
    {
        $recovered = $this->jobs->recoverStale($this->processingTimeoutSeconds);
        $claimed = $this->jobs->claimDue($limit);
        $sent = 0;
        $failed = 0;
        foreach ($claimed as $job) {
            try {
                $message = $this->message($job);
                $this->sender->send($message);
                $this->jobs->markSent($this->string($job, 'id'));
                $sent++;
            } catch (Throwable $exception) {
                $attempts = $this->integer($job, 'attempts');
                $maximum = $this->integer($job, 'max_attempts');
                $code = $exception instanceof EmailDeliveryException ? $exception->getMessage() : 'NOTIFICATION_DELIVERY_FAILED';
                if (preg_match('/^[A-Z0-9_]{1,64}$/', $code) !== 1) {
                    $code = 'NOTIFICATION_DELIVERY_FAILED';
                }
                $this->jobs->markFailed($this->string($job, 'id'), $attempts, $maximum, $this->delay($attempts), $code);
                $this->logger->warning('Notification delivery failed.', [
                    'notification_channel' => 'email',
                    'recipient_type' => $job['recipient_type'],
                    'error_code' => $code,
                ]);
                $failed++;
            }
        }

        return ['claimed' => count($claimed), 'sent' => $sent, 'failed' => $failed, 'recovered' => $recovered];
    }

    public function processOne(string $id): void
    {
        $job = $this->jobs->claim($id);
        if ($job === null) {
            return;
        }
        try {
            $this->sender->send($this->message($job));
            $this->jobs->markSent($id);
        } catch (Throwable $exception) {
            $attempts = $this->integer($job, 'attempts');
            $maximum = $this->integer($job, 'max_attempts');
            $code = $exception instanceof EmailDeliveryException ? $exception->getMessage() : 'NOTIFICATION_DELIVERY_FAILED';
            if (preg_match('/^[A-Z0-9_]{1,64}$/', $code) !== 1) {
                $code = 'NOTIFICATION_DELIVERY_FAILED';
            }
            $this->jobs->markFailed($id, $attempts, $maximum, $this->delay($attempts), $code);
            $this->logger->warning('Notification delivery failed.', ['notification_channel' => 'email', 'recipient_type' => $job['recipient_type'], 'error_code' => $code]);
        }
    }

    public function delay(int $attempt): int
    {
        if ($attempt <= 1) {
            return $this->baseDelaySeconds;
        }
        $power = min($attempt - 1, 8);

        return min($this->baseDelaySeconds * (3 ** $power), 7 * 24 * 60 * 60);
    }

    /** @param array<string, mixed> $job */
    private function message(array $job): \ProjectSync\Infrastructure\Email\EmailMessage
    {
        $order = $this->orders->findById($this->string($job, 'order_id'));
        $business = $this->profiles->notificationConfiguration();
        if ($order === null || $business === null) {
            throw new EmailDeliveryException('NOTIFICATION_DATA_UNAVAILABLE');
        }
        $order['items'] = $this->items->findByOrderId($this->string($order, 'id'));
        $recipientType = $job['recipient_type'] ?? null;
        if ($recipientType === 'merchant' || $recipientType === 'merchant_payment_received' || $recipientType === 'merchant_late_payment_action') {
            if (($business['merchant_email_notifications_enabled'] ?? false) !== true) {
                throw new EmailDeliveryException('NOTIFICATION_RECIPIENT_UNAVAILABLE');
            }
            $recipient = $business['order_notification_email'] ?? $business['support_email'] ?? null;
        } elseif ($recipientType === 'customer' || $recipientType === 'customer_payment_confirmed' || (is_string($recipientType) && str_starts_with($recipientType, 'customer_status_'))) {
            if (($business['customer_email_notifications_enabled'] ?? false) !== true) {
                throw new EmailDeliveryException('NOTIFICATION_RECIPIENT_UNAVAILABLE');
            }
            $recipient = $order['customer_email'] ?? null;
        } else {
            throw new EmailDeliveryException('NOTIFICATION_RECIPIENT_INVALID');
        }
        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new EmailDeliveryException('NOTIFICATION_RECIPIENT_UNAVAILABLE');
        }

        if ($recipientType === 'merchant') {
            return $this->emails->merchant($order, $business, $recipient);
        }
        if ($recipientType === 'merchant_payment_received') {
            return $this->emails->merchantPaymentReceived($order, $business, $recipient);
        }
        if ($recipientType === 'merchant_late_payment_action') {
            return $this->emails->merchantLatePaymentAction($order, $business, $recipient);
        }

        if ($recipientType === 'customer_payment_confirmed') {
            return $this->emails->customerPaymentConfirmed($order, $business, $recipient);
        }
        if (str_starts_with($recipientType, 'customer_status_')) {
            $status = substr($recipientType, strlen('customer_status_'));
            return $this->emails->customerStatusUpdate($order, $business, $recipient, $status);
        }

        return $this->emails->customer($order, $business, $recipient);
    }


    /** @param array<array-key, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new EmailDeliveryException('NOTIFICATION_DATA_INVALID');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value)) {
            throw new EmailDeliveryException('NOTIFICATION_DATA_INVALID');
        }

        return $value;
    }
}
