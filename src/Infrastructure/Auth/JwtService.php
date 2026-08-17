<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use ProjectSync\Infrastructure\UuidGenerator;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class JwtService
{
    public function __construct(
        private string $secret,
        private string $issuer,
        private string $audience,
        private int $ttlSeconds,
        private int $clockSkewSeconds,
        private string $algorithm,
    ) {
        if ($algorithm !== 'HS256') {
            throw new RuntimeException('JWT algorithm must be HS256.');
        }
    }

    /** @return array{access_token: string, token_type: string, expires_in: int} */
    public function issue(string $administratorId): array
    {
        $now = time();
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $administratorId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => UuidGenerator::v4(),
            'token_version' => 0,
        ];

        return [
            'access_token' => JWT::encode($claims, $this->secret, $this->algorithm),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttlSeconds,
        ];
    }

    public function verify(string $token): string
    {
        return $this->inspect($token)->administratorId;
    }

    public function inspect(string $token): VerifiedAccessToken
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->clockSkewSeconds;
        try {
            $headers = new stdClass();
            $claims = JWT::decode($token, new Key($this->secret, $this->algorithm), $headers);
            if (($headers->alg ?? null) !== $this->algorithm) {
                throw new RuntimeException('Invalid access token.');
            }
            $this->validateClaims($claims);

            return new VerifiedAccessToken($claims->sub, $claims->jti, $claims->exp);
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid access token.', 0, $exception);
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    private function validateClaims(stdClass $claims): void
    {
        $required = ['iss', 'aud', 'sub', 'iat', 'nbf', 'exp', 'jti', 'token_version'];
        foreach ($required as $claim) {
            if (!property_exists($claims, $claim)) {
                throw new RuntimeException('Missing access-token claim.');
            }
        }
        $present = array_keys(get_object_vars($claims));
        sort($present);
        sort($required);
        $uuidV4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
        $now = time();
        if (!is_string($claims->iss) || !hash_equals($this->issuer, $claims->iss)
            || !is_string($claims->aud) || !hash_equals($this->audience, $claims->aud)
            || !is_string($claims->sub) || preg_match($uuidV4, $claims->sub) !== 1
            || !is_string($claims->jti) || preg_match($uuidV4, $claims->jti) !== 1
            || !is_int($claims->iat) || !is_int($claims->nbf) || !is_int($claims->exp)
            || !is_int($claims->token_version) || $claims->token_version !== 0
            || $claims->exp <= $claims->iat || $claims->nbf < $claims->iat
            || $claims->exp - $claims->iat > $this->ttlSeconds
            || $claims->iat > $now + $this->clockSkewSeconds
            || $present !== $required
        ) {
            throw new RuntimeException('Invalid access-token claims.');
        }
    }
}
