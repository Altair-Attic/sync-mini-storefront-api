<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Repositories\LoginAttemptRepository;

final readonly class PaymentRateLimiter
{
    public function __construct(
        private LoginAttemptRepository $attempts,
        private Config $config,
    ) {
    }

    public function consumeInitialization(string $ip): void
    {
        $maxAttempts = (int) $this->config->string('checkout.max_attempts');
        $this->record('pay-init', $ip, $maxAttempts);
    }

    public function consumeStatus(string $ip, string $reference): void
    {
        $maxAttempts = (int) $this->config->string('checkout.confirmation_max_attempts');
        $this->record('pay-status', $ip . '|' . $reference, $maxAttempts);
    }

    public function consumeReconcile(string $adminId): void
    {
        $this->record('admin-reconcile', $adminId, 30);
    }

    private function record(string $scope, string $identifier, int $maximum): void
    {
        $hash = hash_hmac('sha256', 'pay-rate-v1|' . $scope . '|' . $identifier, $this->config->requiredString('checkout.security_secret'));
        $attempt = $this->attempts->find($hash);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($attempt !== null && $attempt['blocked_until'] !== null && strtotime((string) $attempt['blocked_until']) > $now->getTimestamp()) {
            throw new PaymentException('RATE_LIMITED', 'Too many requests. Try again later.', 429);
        }

        $window = (int) $this->config->string('checkout.window_seconds');
        $withinWindow = $attempt !== null && strtotime((string) $attempt['window_started_at']) > $now->getTimestamp() - $window;
        $count = $withinWindow ? (int) $attempt['attempt_count'] + 1 : 1;
        $started = $withinWindow ? new DateTimeImmutable((string) $attempt['window_started_at'], new DateTimeZone('UTC')) : $now;
        $blockSeconds = (int) $this->config->string('checkout.block_seconds');
        $blocked = $count >= $maximum
            ? $now->modify('+' . $blockSeconds . ' seconds')
            : null;

        $this->attempts->save($hash, $count, $started, $blocked);
    }
}
