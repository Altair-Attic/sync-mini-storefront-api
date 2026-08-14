<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Exceptions\CheckoutException;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Repositories\LoginAttemptRepository;

final readonly class CheckoutRateLimiter
{
    public function __construct(private LoginAttemptRepository $attempts, private Config $config)
    {
    }

    public function consumeCheckout(string $ip): void
    {
        $this->record('checkout', $ip, (int) $this->config->requiredString('checkout.max_attempts'));
    }

    public function assertConfirmationAllowed(string $ip, string $reference): void
    {
        $attempt = $this->attempts->find($this->hash('confirmation', $ip . '|' . $reference));
        if ($attempt !== null && $attempt['blocked_until'] !== null && strtotime($attempt['blocked_until']) > time()) {
            throw new CheckoutException('RATE_LIMITED', 'Too many requests. Try again later.', 429);
        }
    }

    public function recordInvalidConfirmation(string $ip, string $reference): void
    {
        $this->record('confirmation', $ip . '|' . $reference, (int) $this->config->requiredString('checkout.confirmation_max_attempts'));
    }

    public function clearConfirmation(string $ip, string $reference): void
    {
        $this->attempts->clear($this->hash('confirmation', $ip . '|' . $reference));
    }

    private function record(string $scope, string $identifier, int $maximum): void
    {
        $hash = $this->hash($scope, $identifier);
        $attempt = $this->attempts->find($hash);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($attempt !== null && $attempt['blocked_until'] !== null && strtotime($attempt['blocked_until']) > $now->getTimestamp()) {
            throw new CheckoutException('RATE_LIMITED', 'Too many requests. Try again later.', 429);
        }
        $window = (int) $this->config->requiredString('checkout.window_seconds');
        $withinWindow = $attempt !== null && strtotime($attempt['window_started_at']) > $now->getTimestamp() - $window;
        $count = $withinWindow ? $attempt['attempt_count'] + 1 : 1;
        $started = $withinWindow ? new DateTimeImmutable($attempt['window_started_at'], new DateTimeZone('UTC')) : $now;
        $blocked = $count >= $maximum
            ? $now->modify('+' . $this->config->requiredString('checkout.block_seconds') . ' seconds')
            : null;
        $this->attempts->save($hash, $count, $started, $blocked);
    }

    private function hash(string $scope, string $identifier): string
    {
        return hash_hmac('sha256', 'public-rate-v1|' . $scope . '|' . $identifier, $this->config->requiredString('checkout.security_secret'));
    }
}
