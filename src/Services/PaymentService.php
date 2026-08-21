<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use JsonException;
use PDO;
use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Exceptions\PaystackException;
use ProjectSync\Infrastructure\Paystack\PaystackClientInterface;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use Psr\Log\LoggerInterface;

final readonly class PaymentService
{
    public function __construct(
        private PDO $db,
        private OrderRepository $orders,
        private PaymentAttemptRepository $attempts,
        private PaymentEventRepository $events,
        private PaystackClientInterface $paystack,
        private PaymentFinalizationService $finalizer,
        private OrderConfirmationTokenService $tokens,
        private PaymentReferenceGenerator $references,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{
     *     payment_reference: string,
     *     authorization_url: string,
     *     access_code: string,
     *     status: string,
     *     expected_amount_kobo: int,
     *     currency: string,
     *     idempotent_replay: bool,
     * }
     */
    public function initialize(string $orderReference, string $confirmationToken, string $idempotencyKey): array
    {
        $order = $this->orders->findByReference($orderReference);
        $storedHash = $order['confirmation_token_hash'] ?? null;
        if ($order === null || !is_string($storedHash) || !$this->tokens->valid($confirmationToken, $storedHash)) {
            throw new PaymentException('ORDER_NOT_FOUND', 'The order was not found.', 404);
        }

        $orderId = is_string($order['id'] ?? null) ? (string) $order['id'] : '';
        $fulfilmentStatus = is_string($order['fulfilment_status'] ?? null) ? (string) $order['fulfilment_status'] : '';
        $paymentStatus = is_string($order['payment_status'] ?? null) ? (string) $order['payment_status'] : '';
        $rawTotal = $order['total_kobo'] ?? 0;
        $totalKobo = is_int($rawTotal) ? $rawTotal : (is_numeric($rawTotal) ? (int) $rawTotal : 0);
        $currency = is_string($order['currency'] ?? null) ? (string) $order['currency'] : '';

        // Business eligibility rules
        if ($fulfilmentStatus === 'cancelled') {
            throw new PaymentException('ORDER_CANCELLED', 'Cancelled orders cannot be paid.', 422);
        }
        if ($paymentStatus === 'paid') {
            throw new PaymentException('ALREADY_PAID', 'This order is already paid.', 409);
        }
        if ($fulfilmentStatus === 'completed') {
            throw new PaymentException('ORDER_COMPLETED', 'Completed orders cannot accept new payments.', 409);
        }
        if ($totalKobo <= 0) {
            throw new PaymentException('INVALID_ORDER_TOTAL', 'Order total must be positive.', 422);
        }
        if ($currency !== 'NGN') {
            throw new PaymentException('UNSUPPORTED_CURRENCY', 'Only NGN currency is supported.', 422);
        }

        $idempotencyHash = hash('sha256', 'pay-init-v1|' . $idempotencyKey);

        // Check for order-scoped idempotent replay
        $existing = $this->attempts->findByOrderAndIdempotencyHash($orderId, $idempotencyHash);
        if ($existing !== null) {
            $existingOrderId = is_string($existing['order_id'] ?? null) ? (string) $existing['order_id'] : '';
            if ($existingOrderId !== $orderId) {
                throw new PaymentException('IDEMPOTENCY_KEY_CONFLICT', 'Idempotency key scope mismatch.', 409);
            }
            if (is_string($existing['authorization_url'] ?? null) && is_string($existing['access_code'] ?? null)) {
                $rawExp = $existing['expected_amount_kobo'] ?? 0;
                $expKobo = is_int($rawExp) ? $rawExp : (is_numeric($rawExp) ? (int) $rawExp : 0);

                return [
                    'payment_reference' => is_string($existing['internal_reference'] ?? null) ? (string) $existing['internal_reference'] : '',
                    'authorization_url' => (string) $existing['authorization_url'],
                    'access_code' => (string) $existing['access_code'],
                    'status' => is_string($existing['status'] ?? null) ? (string) $existing['status'] : 'pending',
                    'expected_amount_kobo' => $expKobo,
                    'currency' => is_string($existing['currency'] ?? null) ? (string) $existing['currency'] : 'NGN',
                    'idempotent_replay' => true,
                ];
            }
        }

        // Check for an active pending attempt to prevent multiplying provider sessions
        $activeAttempt = $this->attempts->findActiveByOrderId($orderId, 900);
        if ($activeAttempt !== null && is_string($activeAttempt['authorization_url'] ?? null) && is_string($activeAttempt['access_code'] ?? null)) {
            $rawExp = $activeAttempt['expected_amount_kobo'] ?? 0;
            $expKobo = is_int($rawExp) ? $rawExp : (is_numeric($rawExp) ? (int) $rawExp : 0);

            return [
                'payment_reference' => is_string($activeAttempt['internal_reference'] ?? null) ? (string) $activeAttempt['internal_reference'] : '',
                'authorization_url' => (string) $activeAttempt['authorization_url'],
                'access_code' => (string) $activeAttempt['access_code'],
                'status' => is_string($activeAttempt['status'] ?? null) ? (string) $activeAttempt['status'] : 'pending',
                'expected_amount_kobo' => $expKobo,
                'currency' => is_string($activeAttempt['currency'] ?? null) ? (string) $activeAttempt['currency'] : 'NGN',
                'idempotent_replay' => true,
            ];
        }

        $attemptId = UuidGenerator::v4();
        $internalReference = $this->references->generate();
        $customerEmail = is_string($order['customer_email'] ?? null) && $order['customer_email'] !== ''
            ? (string) $order['customer_email']
            : 'customer+' . $orderReference . '@project-sync.local';

        // 1. Record initialized attempt in database
        $this->attempts->insert([
            'id' => $attemptId,
            'order_id' => $orderId,
            'provider' => 'paystack',
            'internal_reference' => $internalReference,
            'provider_reference' => null,
            'access_code' => null,
            'authorization_url' => null,
            'idempotency_key_hash' => $idempotencyHash,
            'expected_amount_kobo' => $totalKobo,
            'currency' => 'NGN',
            'status' => 'initialized',
            'resolution_status' => 'none',
        ]);

        // 2. Call Paystack API
        try {
            $initResult = $this->paystack->initializeTransaction(
                email: $customerEmail,
                amountKobo: $totalKobo,
                currency: 'NGN',
                reference: $internalReference,
                metadata: [
                    'order_reference' => $orderReference,
                ],
            );
        } catch (PaystackException $e) {
            $this->attempts->updateStatus($attemptId, 'failed', 'initialization_error');
            $this->logger?->error('Paystack transaction initialization failed.', [
                'order_reference' => $orderReference,
                'internal_reference' => $internalReference,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
            throw new PaymentException('PAYMENT_INITIALIZATION_FAILED', 'Payment provider was unable to initialize transaction.', 502);
        }

        // 3. Update attempt with provider credentials
        $this->attempts->updateProviderInitialization(
            id: $attemptId,
            status: 'pending',
            providerReference: $initResult->reference,
            accessCode: $initResult->accessCode,
            authorizationUrl: $initResult->authorizationUrl,
        );

        // 4. Update aggregate order payment status to pending if unpaid
        if ($paymentStatus === 'unpaid') {
            $stmt = $this->db->prepare("UPDATE orders SET payment_status = 'pending', updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $stmt->execute(['id' => $orderId]);
        }

        $this->logger?->info('Payment attempt initialized.', [
            'order_reference' => $orderReference,
            'internal_reference' => $internalReference,
            'amount_kobo' => $totalKobo,
        ]);

        return [
            'payment_reference' => $internalReference,
            'authorization_url' => $initResult->authorizationUrl,
            'access_code' => $initResult->accessCode,
            'status' => 'pending',
            'expected_amount_kobo' => $totalKobo,
            'currency' => 'NGN',
            'idempotent_replay' => false,
        ];
    }

    /**
     * @return array{
     *     payment_reference: string,
     *     order_reference: string,
     *     status: string,
     *     payment_status: string,
     *     amount_kobo: int,
     *     currency: string,
     *     resolution_status: string,
     *     created_at: string,
     * }
     */
    public function status(string $orderReference, string $confirmationToken, string $paymentReference): array
    {
        $order = $this->orders->findByReference($orderReference);
        $storedHash = $order['confirmation_token_hash'] ?? null;
        if ($order === null || !is_string($storedHash) || !$this->tokens->valid($confirmationToken, $storedHash)) {
            throw new PaymentException('ORDER_NOT_FOUND', 'The order was not found.', 404);
        }

        $attempt = $this->attempts->findByInternalReference($paymentReference);
        $attemptOrderId = is_string($attempt['order_id'] ?? null) ? (string) $attempt['order_id'] : '';
        $orderId = is_string($order['id'] ?? null) ? (string) $order['id'] : '';
        if ($attempt === null || $attemptOrderId !== $orderId) {
            throw new PaymentException('PAYMENT_NOT_FOUND', 'Payment attempt not found.', 404);
        }

        $rawExp = $attempt['expected_amount_kobo'] ?? 0;
        $amountKobo = is_int($rawExp) ? $rawExp : (is_numeric($rawExp) ? (int) $rawExp : 0);

        return [
            'payment_reference' => is_string($attempt['internal_reference'] ?? null) ? (string) $attempt['internal_reference'] : '',
            'order_reference' => $orderReference,
            'status' => is_string($attempt['status'] ?? null) ? (string) $attempt['status'] : '',
            'payment_status' => is_string($order['payment_status'] ?? null) ? (string) $order['payment_status'] : '',
            'amount_kobo' => $amountKobo,
            'currency' => is_string($attempt['currency'] ?? null) ? (string) $attempt['currency'] : 'NGN',
            'resolution_status' => is_string($attempt['resolution_status'] ?? null) ? (string) $attempt['resolution_status'] : 'none',
            'created_at' => is_string($attempt['created_at'] ?? null) ? (string) $attempt['created_at'] : '',
        ];
    }

    /**
     * @return array{status: string, idempotent_replay?: bool, event?: string, reason?: string}
     */
    public function handleWebhook(string $rawBody, string $signatureHeader): array
    {
        if (!$this->paystack->verifySignature($rawBody, $signatureHeader)) {
            $this->logger?->warning('Paystack webhook received with invalid signature.', [
                'signature_header_present' => $signatureHeader !== '',
            ]);
            throw new PaymentException('UNAUTHORIZED', 'Invalid webhook signature.', 401);
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger?->warning('Paystack webhook payload could not be parsed as JSON.', [
                'error' => $e->getMessage(),
            ]);
            throw new PaymentException('BAD_REQUEST', 'Malformed JSON payload.', 400);
        }

        if (!is_array($decoded) || !is_string($decoded['event'] ?? null) || !is_array($decoded['data'] ?? null)) {
            throw new PaymentException('BAD_REQUEST', 'Invalid webhook payload structure.', 400);
        }

        $eventType = (string) $decoded['event'];
        $data = $decoded['data'];

        // Only handle charge.success in Phase 6B; ignore other validly signed events gracefully
        if ($eventType !== 'charge.success') {
            $this->logger?->info('Ignoring non-charge.success Paystack webhook event.', ['event' => $eventType]);
            return ['status' => 'ignored', 'event' => $eventType];
        }

        $providerReference = is_string($data['reference'] ?? null) ? (string) $data['reference'] : '';
        if ($providerReference === '') {
            throw new PaymentException('BAD_REQUEST', 'Missing payment reference in webhook payload.', 400);
        }

        $payloadHash = hash('sha256', $rawBody);

        // Check if event was already recorded for this reference
        if ($this->events->existsForProviderReference('paystack', 'charge.success', $providerReference)) {
            $this->logger?->info('Paystack webhook event is duplicate replay; skipping safely.', [
                'provider_reference' => $providerReference,
            ]);
            return ['status' => 'success', 'idempotent_replay' => true];
        }

        // Locate attempt
        $attempt = $this->attempts->findByInternalReference($providerReference);
        if ($attempt === null) {
            $this->events->record([
                'id' => UuidGenerator::v4(),
                'payment_attempt_id' => null,
                'order_id' => null,
                'provider' => 'paystack',
                'event_type' => 'charge.success',
                'provider_reference' => $providerReference,
                'payload_hash' => $payloadHash,
                'processing_status' => 'mismatched',
                'processing_notes' => 'unknown_reference',
            ]);
            $this->logger?->warning('Paystack webhook reference not found in payment attempts.', [
                'provider_reference' => $providerReference,
            ]);
            return ['status' => 'mismatched', 'reason' => 'reference_not_found'];
        }

        $rawAmount = $data['amount'] ?? 0;
        $verifiedAmountKobo = is_int($rawAmount) ? $rawAmount : (is_numeric($rawAmount) ? (int) $rawAmount : 0);
        $verifiedCurrency = is_string($data['currency'] ?? null) ? (string) $data['currency'] : 'NGN';
        $providerStatus = is_string($data['status'] ?? null) ? (string) $data['status'] : 'success';
        $channel = is_string($data['channel'] ?? null) ? (string) $data['channel'] : null;

        $attemptInternalRef = is_string($attempt['internal_reference'] ?? null) ? (string) $attempt['internal_reference'] : '';

        $finalizationResult = $this->finalizer->finalize(
            internalReference: $attemptInternalRef,
            providerReference: $providerReference,
            verifiedAmountKobo: $verifiedAmountKobo,
            verifiedCurrency: $verifiedCurrency,
            providerStatus: $providerStatus,
            channel: $channel,
            payloadHash: $payloadHash,
            source: 'webhook',
        );

        return [
            'status' => 'success',
            'idempotent_replay' => $finalizationResult['idempotent_replay'],
        ];
    }

    /**
     * @return array{
     *     payment_attempt_id: string,
     *     order_id: string,
     *     status: string,
     *     payment_status: string,
     *     verified: bool,
     *     resolution_status: string,
     *     provider_status: string,
     * }
     */
    public function reconcile(string $orderId, string $paymentId): array
    {
        $attempt = $this->attempts->findById($paymentId);
        $attemptOrderId = is_string($attempt['order_id'] ?? null) ? (string) $attempt['order_id'] : '';
        if ($attempt === null || $attemptOrderId !== $orderId) {
            throw new PaymentException('PAYMENT_NOT_FOUND', 'Payment attempt not found.', 404);
        }

        $internalRef = is_string($attempt['internal_reference'] ?? null) ? (string) $attempt['internal_reference'] : '';
        $refToVerify = is_string($attempt['provider_reference'] ?? null) && $attempt['provider_reference'] !== ''
            ? (string) $attempt['provider_reference']
            : $internalRef;

        try {
            $verifyResult = $this->paystack->verifyTransaction($refToVerify);
        } catch (PaystackException $e) {
            $this->logger?->error('Paystack S2S verification call failed during reconciliation.', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
            throw new PaymentException('RECONCILIATION_UNAVAILABLE', 'Payment provider verification failed: ' . $e->getMessage(), 502);
        }

        $payloadHash = hash('sha256', 'reconcile|' . $refToVerify . '|' . $verifyResult->status . '|' . $verifyResult->amountKobo);

        if ($verifyResult->status === 'success') {
            $finalized = $this->finalizer->finalize(
                internalReference: $internalRef,
                providerReference: $verifyResult->reference,
                verifiedAmountKobo: $verifyResult->amountKobo,
                verifiedCurrency: $verifyResult->currency,
                providerStatus: 'success',
                channel: $verifyResult->channel,
                payloadHash: $payloadHash,
                source: 'reconciliation',
            );

            return [
                'payment_attempt_id' => $finalized['payment_attempt_id'],
                'order_id' => $finalized['order_id'],
                'status' => 'successful',
                'payment_status' => $finalized['payment_status'],
                'verified' => true,
                'resolution_status' => $finalized['resolution_status'],
                'provider_status' => 'success',
            ];
        }

        // If not successful (e.g. failed or abandoned or pending)
        $mappedStatus = match ($verifyResult->status) {
            'failed' => 'failed',
            'abandoned' => 'abandoned',
            default => 'pending',
        };
        $this->attempts->updateStatus($paymentId, $mappedStatus, $verifyResult->status);

        $order = $this->orders->findById($orderId);
        $orderPaymentStatus = is_array($order) && is_string($order['payment_status'] ?? null) ? (string) $order['payment_status'] : 'unpaid';
        $resStatus = is_string($attempt['resolution_status'] ?? null) ? (string) $attempt['resolution_status'] : 'none';

        return [
            'payment_attempt_id' => $paymentId,
            'order_id' => $orderId,
            'status' => $mappedStatus,
            'payment_status' => $orderPaymentStatus,
            'verified' => false,
            'resolution_status' => $resStatus,
            'provider_status' => $verifyResult->status,
        ];
    }
}
