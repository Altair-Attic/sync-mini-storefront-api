<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Repositories\LoginAttemptRepository;

final readonly class LoginRateLimiter
{
    public function __construct(private LoginAttemptRepository $repo, private Config $config) {}
    private function hash(string $email, string $ip): string { return hash('sha256', $email . '|' . $ip); }
    public function blocked(string $email, string $ip): bool { $attempt = $this->repo->find($this->hash($email, $ip)); return $attempt !== null && $attempt['blocked_until'] !== null && strtotime($attempt['blocked_until']) > time(); }
    public function fail(string $email, string $ip): void { $hash = $this->hash($email, $ip); $attempt = $this->repo->find($hash); $now = new DateTimeImmutable('now', new DateTimeZone('UTC')); $window = (int) $this->config->requiredString('login.window_seconds'); $withinWindow = $attempt !== null && strtotime($attempt['window_started_at']) > $now->getTimestamp() - $window; $count = $withinWindow ? $attempt['attempt_count'] + 1 : 1; $started = $withinWindow ? new DateTimeImmutable($attempt['window_started_at'], new DateTimeZone('UTC')) : $now; $blocked = $count >= (int) $this->config->requiredString('login.max_attempts') ? $now->modify('+' . $this->config->requiredString('login.block_seconds') . ' seconds') : null; $this->repo->save($hash, $count, $started, $blocked); }
    public function clear(string $email, string $ip): void { $this->repo->clear($this->hash($email, $ip)); }
}
