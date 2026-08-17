<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use PDO;
use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PaymentFinalizationService
{
    public function __construct(
        private PDO $db,
        private PaymentAttemptRepository $attempts,
        private PaymentEventRepository $events,
        private ?NotificationService $notifications = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Centralized, transactional finalization method shared by Webhook and S2S Reconciliation.
     *
     * @return array{
     *     status: string,
     *     payment_attempt_id: string,
     *     order_id: string,
     *     order_reference: string,
     *     payment_status: string,
     *     fulfilment_status: string,
     *     resolution_status: string,
     *     idempotent_replay: bool,
     * }
     */
    public function finalize(
        string $internalReference,
        string $providerReference,
        int $verifiedAmountKobo,
        string $verifiedCurrency,
        string $providerStatus,
        ?string $channel,
        string $payloadHash,
        string $source, // 'webhook' or 'reconciliation'
    ): array {
        try {
            $this->db->beginTransaction();

            // 1. Pessimistic lock on payment attempt
            $stmt = $this->db->prepare('SELECT * FROM payment_attempts WHERE internal_reference = :ref FOR UPDATE');
            $stmt->execute(['ref' => $internalReference]);
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($attempt)) {
                $this->db->rollBack();
                $this->logger?->warning('Payment finalization attempted for unknown internal reference.', [
                    'internal_reference' => $internalReference,
                    'provider_reference' => $providerReference,
                    'source' => $source,
                ]);
                throw new PaymentException('PAYMENT_NOT_FOUND', 'Payment attempt not found.', 404);
            }

            $orderId = is_string($attempt['order_id'] ?? null) ? (string) $attempt['order_id'] : '';
            $attemptId = is_string($attempt['id'] ?? null) ? (string) $attempt['id'] : '';
            $rawExpected = $attempt['expected_amount_kobo'] ?? 0;
            $expectedAmount = is_int($rawExpected) ? $rawExpected : (is_numeric($rawExpected) ? (int) $rawExpected : 0);
            $attemptCurrency = is_string($attempt['currency'] ?? null) ? (string) $attempt['currency'] : '';

            // 2. Pessimistic lock on target order
            $stmtOrder = $this->db->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $stmtOrder->execute(['id' => $orderId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            if (!is_array($order)) {
                $this->db->rollBack();
                $this->logger?->error('Payment attempt references missing order.', [
                    'attempt_id' => $attemptId,
                    'order_id' => $orderId,
                ]);
                throw new PaymentException('ORDER_NOT_FOUND', 'Associated order not found.', 404);
            }

            $orderReference = is_string($order['reference'] ?? null) ? (string) $order['reference'] : '';
            $rawOrderTotal = $order['total_kobo'] ?? 0;
            $orderTotal = is_int($rawOrderTotal) ? $rawOrderTotal : (is_numeric($rawOrderTotal) ? (int) $rawOrderTotal : 0);
            $orderCurrency = is_string($order['currency'] ?? null) ? (string) $order['currency'] : '';
            $currentPaymentStatus = is_string($order['payment_status'] ?? null) ? (string) $order['payment_status'] : '';
            $currentFulfilmentStatus = is_string($order['fulfilment_status'] ?? null) ? (string) $order['fulfilment_status'] : '';
            $currentAttemptStatus = is_string($attempt['status'] ?? null) ? (string) $attempt['status'] : '';

            // 3. Check for idempotent replay (already successful & paid)
            if ($currentAttemptStatus === 'successful' && $currentPaymentStatus === 'paid') {
                $this->db->commit();
                $attemptResStatus = is_string($attempt['resolution_status'] ?? null) ? (string) $attempt['resolution_status'] : 'none';

                return [
                    'status' => 'success',
                    'payment_attempt_id' => $attemptId,
                    'order_id' => $orderId,
                    'order_reference' => $orderReference,
                    'payment_status' => $currentPaymentStatus,
                    'fulfilment_status' => $currentFulfilmentStatus,
                    'resolution_status' => $attemptResStatus,
                    'idempotent_replay' => true,
                ];
            }

            // 4. Strict business amount and currency validation
            if ($verifiedAmountKobo !== $expectedAmount || $expectedAmount !== $orderTotal) {
                $this->events->record([
                    'id' => UuidGenerator::v4(),
                    'payment_attempt_id' => $attemptId,
                    'order_id' => $orderId,
                    'provider' => 'paystack',
                    'event_type' => 'charge.success',
                    'provider_reference' => $providerReference,
                    'payload_hash' => $payloadHash,
                    'processing_status' => 'mismatched',
                    'processing_notes' => sprintf('amount_mismatch: expected %d, verified %d', $expectedAmount, $verifiedAmountKobo),
                ]);
                $this->db->commit();

                $this->logger?->error('Payment amount mismatch.', [
                    'internal_reference' => $internalReference,
                    'provider_reference' => $providerReference,
                    'expected_amount' => $expectedAmount,
                    'verified_amount' => $verifiedAmountKobo,
                    'order_total' => $orderTotal,
                ]);

                throw new PaymentException('PAYMENT_AMOUNT_MISMATCH', 'Verified payment amount does not match expected order total.', 422);
            }

            if (strtoupper($verifiedCurrency) !== 'NGN' || $attemptCurrency !== 'NGN' || $orderCurrency !== 'NGN') {
                $this->events->record([
                    'id' => UuidGenerator::v4(),
                    'payment_attempt_id' => $attemptId,
                    'order_id' => $orderId,
                    'provider' => 'paystack',
                    'event_type' => 'charge.success',
                    'provider_reference' => $providerReference,
                    'payload_hash' => $payloadHash,
                    'processing_status' => 'mismatched',
                    'processing_notes' => sprintf('currency_mismatch: expected NGN, verified %s', $verifiedCurrency),
                ]);
                $this->db->commit();

                $this->logger?->error('Payment currency mismatch.', [
                    'internal_reference' => $internalReference,
                    'verified_currency' => $verifiedCurrency,
                ]);

                throw new PaymentException('PAYMENT_CURRENCY_MISMATCH', 'Verified payment currency does not match order currency.', 422);
            }

            // 5. Check if order was cancelled (Late Payment Scenario)
            $isCancelled = $currentFulfilmentStatus === 'cancelled';
            $resolutionStatus = $isCancelled ? 'requires_action' : 'none';
            $processingStatus = $isCancelled ? 'requires_action' : 'processed';
            $processingNotes = $isCancelled ? 'payment_received_after_cancellation' : 'payment_finalized_successfully';

            // 6. Update payment attempt
            $this->attempts->finalizeSuccessful(
                id: $attemptId,
                verifiedAmountKobo: $verifiedAmountKobo,
                providerStatus: $providerStatus,
                channel: $channel,
                resolutionStatus: $resolutionStatus,
            );

            // 7. Update aggregate order payment status (financial truth)
            $stmtUpdateOrder = $this->db->prepare(
                "UPDATE orders SET payment_status = 'paid', updated_at = UTC_TIMESTAMP() WHERE id = :id"
            );
            $stmtUpdateOrder->execute(['id' => $orderId]);

            // 8. Record audit event
            $this->events->record([
                'id' => UuidGenerator::v4(),
                'payment_attempt_id' => $attemptId,
                'order_id' => $orderId,
                'provider' => 'paystack',
                'event_type' => 'charge.success',
                'provider_reference' => $providerReference,
                'payload_hash' => $payloadHash,
                'processing_status' => $processingStatus,
                'processing_notes' => $processingNotes,
            ]);

            $this->db->commit();

            // 9. Asynchronous notification jobs (outside DB transaction locks)
            if ($this->notifications !== null) {
                /** @var array<string, mixed> $orderForNotification */
                $orderForNotification = $order;
                $orderForNotification['payment_status'] = 'paid';
                if ($isCancelled) {
                    $this->notifications->notifyLatePaymentRequiresAction($orderForNotification);
                } else {
                    $this->notifications->notifyPaymentSuccess($orderForNotification);
                }
            }

            $this->logger?->info('Payment successfully finalized.', [
                'internal_reference' => $internalReference,
                'provider_reference' => $providerReference,
                'order_reference' => $orderReference,
                'amount_kobo' => $verifiedAmountKobo,
                'resolution_status' => $resolutionStatus,
                'source' => $source,
            ]);

            return [
                'status' => 'success',
                'payment_attempt_id' => $attemptId,
                'order_id' => $orderId,
                'order_reference' => $orderReference,
                'payment_status' => 'paid',
                'fulfilment_status' => $currentFulfilmentStatus,
                'resolution_status' => $resolutionStatus,
                'idempotent_replay' => false,
            ];
        } catch (PaymentException $e) {
            $this->rollback();
            throw $e;
        } catch (Throwable $e) {
            $this->rollback();
            $this->logger?->error('Unexpected failure during payment finalization.', [
                'internal_reference' => $internalReference,
                'exception' => $e->getMessage(),
            ]);
            throw new PaymentException('PAYMENT_FINALIZATION_FAILED', 'Failed to finalize payment transaction.', 500);
        }
    }

    private function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
