<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Paystack;

use JsonException;
use ProjectSync\Exceptions\PaystackException;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaystackClient implements PaystackClientInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly PaystackHttpTransportInterface $transport,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }


    /**
     * @param array<string, mixed> $metadata
     */
    public function initializeTransaction(
        string $email,
        int $amountKobo,
        string $currency,
        string $reference,
        array $metadata = [],
    ): PaystackInitializationResult {
        if ($this->secretKey === '') {
            throw new PaystackException('PAYSTACK_CONFIG_INVALID', 'Paystack secret key is not configured.');
        }

        $url = rtrim($this->baseUrl, '/') . '/transaction/initialize';
        $payload = [
            'email' => $email,
            'amount' => $amountKobo,
            'currency' => $currency,
            'reference' => $reference,
        ];
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        try {
            $jsonBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new PaystackException('PAYSTACK_PAYLOAD_INVALID', 'Failed to encode Paystack initialization payload.', $e);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $response = $this->transport->request('POST', $url, $headers, $jsonBody, $this->timeoutSeconds);
        $decoded = $this->decodeResponse($response['body'], $response['status'], 'initialization');

        $data = $decoded['data'] ?? null;
        if (!is_array($data)
            || !is_string($data['authorization_url'] ?? null)
            || !is_string($data['access_code'] ?? null)
            || !is_string($data['reference'] ?? null)
        ) {
            $this->logger?->error('Paystack initialization response missing required data fields.', [
                'status_code' => $response['status'],
                'reference' => $reference,
            ]);
            throw new PaystackException('PAYSTACK_RESPONSE_INVALID', 'Paystack initialization response was missing required fields.');
        }

        return new PaystackInitializationResult(
            authorizationUrl: $data['authorization_url'],
            accessCode: $data['access_code'],
            reference: $data['reference'],
        );
    }

    public function verifyTransaction(string $reference): PaystackVerificationResult
    {
        if ($this->secretKey === '') {
            throw new PaystackException('PAYSTACK_CONFIG_INVALID', 'Paystack secret key is not configured.');
        }

        $url = rtrim($this->baseUrl, '/') . '/transaction/verify/' . rawurlencode($reference);
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
        ];

        $response = $this->transport->request('GET', $url, $headers, null, $this->timeoutSeconds);
        $decoded = $this->decodeResponse($response['body'], $response['status'], 'verification');

        $data = $decoded['data'] ?? null;
        if (!is_array($data)
            || !is_string($data['status'] ?? null)
            || !is_string($data['reference'] ?? null)
            || !isset($data['amount'])
            || !is_string($data['currency'] ?? null)
        ) {
            $this->logger?->error('Paystack verification response missing required data fields.', [
                'status_code' => $response['status'],
                'reference' => $reference,
            ]);
            throw new PaystackException('PAYSTACK_RESPONSE_INVALID', 'Paystack verification response was missing required fields.');
        }

        $rawAmount = $data['amount'];
        $amountKobo = is_int($rawAmount) ? $rawAmount : (is_numeric($rawAmount) ? (int) $rawAmount : 0);
        $statusStr = strtolower($data['status']);
        $referenceStr = $data['reference'];
        $currencyStr = strtoupper($data['currency']);


        return new PaystackVerificationResult(
            status: $statusStr,
            reference: $referenceStr,
            amountKobo: $amountKobo,
            currency: $currencyStr,
            channel: is_string($data['channel'] ?? null) ? (string) $data['channel'] : null,
            gatewayResponse: is_string($data['gateway_response'] ?? null) ? (string) $data['gateway_response'] : null,
            paidAt: is_string($data['paid_at'] ?? null) ? (string) $data['paid_at'] : null,
            paystackId: isset($data['id']) && (is_int($data['id']) || is_numeric($data['id'])) ? (int) $data['id'] : null,
        );

    }

    public function verifySignature(string $rawBody, string $signatureHeader): bool
    {
        if ($signatureHeader === '' || $rawBody === '') {
            return false;
        }

        $computed = hash_hmac('sha512', $rawBody, $this->secretKey);

        return hash_equals($signatureHeader, $computed);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $body, int $statusCode, string $action): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger?->error('Paystack returned non-JSON response.', [
                'action' => $action,
                'status_code' => $statusCode,
            ]);
            throw new PaystackException('PAYSTACK_RESPONSE_INVALID', 'Paystack returned an invalid response payload.', $e);
        }

        if (!is_array($decoded)) {
            throw new PaystackException('PAYSTACK_RESPONSE_INVALID', 'Paystack response payload was not an object.');
        }

        $status = $decoded['status'] ?? null;
        if ($status !== true) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Paystack API returned an error.';
            $this->logger?->warning('Paystack API error response.', [
                'action' => $action,
                'status_code' => $statusCode,
                'provider_message' => $message,
            ]);
            throw new PaystackException('PAYSTACK_API_ERROR', 'Paystack: ' . $message);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
