<?php

declare(strict_types=1);

namespace ProjectSync\Middleware;

final class RequestIdMiddleware
{
    public function requestId(): string
    {
        return 'req_' . bin2hex(random_bytes(12));
    }
}
