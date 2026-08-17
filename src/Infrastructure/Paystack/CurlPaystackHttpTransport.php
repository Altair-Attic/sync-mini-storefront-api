<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Paystack;

use ProjectSync\Exceptions\PaystackException;
use Throwable;

final class CurlPaystackHttpTransport implements PaystackHttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new PaystackException('PAYSTACK_TRANSPORT_ERROR', 'Failed to initialize HTTP client.');
        }

        if ($url === '') {
            throw new PaystackException('PAYSTACK_TRANSPORT_ERROR', 'URL cannot be empty.');
        }

        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = $key . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $upperMethod = strtoupper($method);
        if ($upperMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($upperMethod !== 'GET' && $upperMethod !== '') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }


        try {
            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new PaystackException('PAYSTACK_TIMEOUT', 'Paystack request timed out.');
            }

            if ($errno !== 0 || !is_string($responseBody)) {
                throw new PaystackException('PAYSTACK_NETWORK_ERROR', 'Paystack network communication failed: ' . $error);
            }

            return [
                'status' => $statusCode,
                'body' => $responseBody,
            ];
        } catch (PaystackException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PaystackException('PAYSTACK_TRANSPORT_ERROR', 'Paystack HTTP transport failed.', $e);
        }
    }
}
