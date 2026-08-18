<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

final readonly class LogRedactionProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'db_password',
        'mail_password',
        'smtp_password',
        'secret',
        'jwt_secret',
        'refresh_token_security_secret',
        'order_security_secret',
        'rate_limit_secret',
        'notification_security_secret',
        'paystack_secret_key',
        'secret_key',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'x-paystack-signature',
        'cookie',
        'set-cookie',
        'confirmation_token',
        'idempotency_key_hash',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $sanitizedMessage = $this->redactString($record->message);
        $sanitizedContext = $this->redactArray($record->context);

        return $record->with(
            message: $sanitizedMessage,
            context: $sanitizedContext,
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function redactArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $isKeySensitive = is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true);
            if ($isKeySensitive) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->redactString($value);
            } elseif ($value instanceof Throwable) {
                $result[$key] = [
                    'class' => get_class($value),
                    'message' => $this->redactString($value->getMessage()),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                    'trace' => $this->redactString($value->getTraceAsString()),
                ];
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function redactString(string $input): string
    {
        // Redact Paystack live and test secret keys
        $output = (string) preg_replace('/sk_(live|test)_[A-Za-z0-9_]+/i', 'sk_$1_[REDACTED]', $input);

        // Redact JWT Bearer tokens and raw JWTs (eyJ...)
        $output = (string) preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [REDACTED_JWT]', $output);
        $output = (string) preg_replace('/eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+/i', '[REDACTED_JWT]', $output);

        // Redact refresh cookie tokens
        $output = (string) preg_replace('/(project_sync_refresh)=([^;\s&]+)/i', '$1=[REDACTED]', $output);

        // Redact sensitive query parameters or URL encoded secrets
        $output = (string) preg_replace('/(confirmation_token|token|password|secret|key|x-paystack-signature)=([^&\s]+)/i', '$1=[REDACTED]', $output);

        return $output;
    }
}
