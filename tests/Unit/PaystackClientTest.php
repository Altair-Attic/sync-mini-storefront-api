<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\PaystackException;
use ProjectSync\Infrastructure\Paystack\PaystackClient;
use ProjectSync\Infrastructure\Paystack\PaystackHttpTransportInterface;

final class PaystackClientTest extends TestCase
{
    public function testInitializeTransactionSendsCorrectHeadersAndPayload(): void
    {
        $mockTransport = new class implements PaystackHttpTransportInterface {
            /** @var array{method?: string, url?: string, headers?: array<string, string>, body?: array<mixed, mixed>|null, timeout?: int} */
            public array $capturedRequest = [];


            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                $decoded = $body !== null ? json_decode($body, true) : null;
                $this->capturedRequest = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => is_array($decoded) ? $decoded : null,
                    'timeout' => $timeoutSeconds,
                ];

                $json = json_encode([
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/access123',
                        'access_code' => 'access123',
                        'reference' => 'PAY-SYNC-TEST12345',
                    ],
                ]);

                return [
                    'status' => 200,
                    'body' => is_string($json) ? $json : '',
                ];
            }
        };

        $client = new PaystackClient('sk_test_1234567890abcdef', 'https://api.paystack.co', 10, $mockTransport);

        $result = $client->initializeTransaction(
            email: 'customer@example.com',
            amountKobo: 250000,
            currency: 'NGN',
            reference: 'PAY-SYNC-TEST12345',
            metadata: ['order_reference' => 'SYNC-2026-TEST'],
        );

        self::assertSame('https://checkout.paystack.com/access123', $result->authorizationUrl);
        self::assertSame('access123', $result->accessCode);
        self::assertSame('PAY-SYNC-TEST12345', $result->reference);

        $headers = $mockTransport->capturedRequest['headers'] ?? [];
        $body = $mockTransport->capturedRequest['body'] ?? [];

        self::assertSame('POST', $mockTransport->capturedRequest['method'] ?? '');
        self::assertSame('https://api.paystack.co/transaction/initialize', $mockTransport->capturedRequest['url'] ?? '');
        self::assertSame('Bearer sk_test_1234567890abcdef', $headers['Authorization'] ?? '');
        self::assertSame('customer@example.com', $body['email'] ?? '');
        self::assertSame(250000, $body['amount'] ?? 0);
        self::assertSame('NGN', $body['currency'] ?? '');
        self::assertSame('PAY-SYNC-TEST12345', $body['reference'] ?? '');
    }

    public function testVerifyTransactionSendsCorrectRequestAndParsesResponse(): void
    {
        $mockTransport = new class implements PaystackHttpTransportInterface {
            /** @var array{method?: string, url?: string, headers?: array<string, string>} */
            public array $capturedRequest = [];

            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                $this->capturedRequest = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                ];

                $json = json_encode([
                    'status' => true,
                    'message' => 'Verification successful',
                    'data' => [
                        'id' => 998877,
                        'status' => 'success',
                        'reference' => 'PAY-SYNC-REF-1',
                        'amount' => 500000,
                        'currency' => 'NGN',
                        'channel' => 'card',
                        'gateway_response' => 'Approved',
                        'paid_at' => '2026-08-17T12:00:00.000Z',
                    ],
                ]);

                return [
                    'status' => 200,
                    'body' => is_string($json) ? $json : '',
                ];
            }
        };

        $client = new PaystackClient('sk_test_1234567890abcdef', 'https://api.paystack.co', 10, $mockTransport);

        $result = $client->verifyTransaction('PAY-SYNC-REF-1');

        self::assertSame('success', $result->status);
        self::assertSame('PAY-SYNC-REF-1', $result->reference);
        self::assertSame(500000, $result->amountKobo);
        self::assertSame('NGN', $result->currency);
        self::assertSame('card', $result->channel);
        self::assertSame(998877, $result->paystackId);
        self::assertSame('GET', $mockTransport->capturedRequest['method'] ?? '');
        self::assertSame('https://api.paystack.co/transaction/verify/PAY-SYNC-REF-1', $mockTransport->capturedRequest['url'] ?? '');
    }

    public function testVerifySignatureTimingSafeVerification(): void
    {
        $secret = 'sk_test_webhook_secret_key_12345';
        $mockTransport = new class implements PaystackHttpTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                return ['status' => 200, 'body' => ''];
            }
        };

        $client = new PaystackClient($secret, 'https://api.paystack.co', 10, $mockTransport);

        $payload = '{"event":"charge.success","data":{"id":123}}';
        $validSignature = hash_hmac('sha512', $payload, $secret);

        self::assertTrue($client->verifySignature($payload, $validSignature));
        self::assertFalse($client->verifySignature($payload, 'invalid-signature'));
        self::assertFalse($client->verifySignature($payload . ' ', $validSignature));
        self::assertFalse($client->verifySignature('', $validSignature));
        self::assertFalse($client->verifySignature($payload, ''));
    }

    public function testProviderApiErrorThrowsPaystackException(): void
    {
        $mockTransport = new class implements PaystackHttpTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                $json = json_encode([
                    'status' => false,
                    'message' => 'Duplicate Transaction Reference',
                ]);

                return [
                    'status' => 400,
                    'body' => is_string($json) ? $json : '',
                ];
            }
        };

        $client = new PaystackClient('sk_test_key', 'https://api.paystack.co', 10, $mockTransport);

        $this->expectException(PaystackException::class);
        $this->expectExceptionMessage('Paystack: Duplicate Transaction Reference');

        $client->initializeTransaction('test@example.com', 1000, 'NGN', 'DUP-REF');
    }

    public function testMalformedJsonResponseThrowsPaystackException(): void
    {
        $mockTransport = new class implements PaystackHttpTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                return [
                    'status' => 502,
                    'body' => '<html><head><title>Bad Gateway</title></head><body>502 Bad Gateway</body></html>',
                ];
            }
        };

        $client = new PaystackClient('sk_test_key', 'https://api.paystack.co', 10, $mockTransport);

        $this->expectException(PaystackException::class);
        $this->expectExceptionMessage('Paystack returned an invalid response payload.');

        $client->verifyTransaction('ANY-REF');
    }
}
