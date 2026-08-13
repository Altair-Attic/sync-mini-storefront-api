<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\JsonResponse;

final class JsonResponseTest extends TestCase
{
    public function testItUsesTheStandardSuccessEnvelope(): void
    {
        $response = JsonResponse::success(['status' => 'ok'], 'req_test');

        self::assertSame(200, $response->status);
        self::assertSame(['success' => true, 'data' => ['status' => 'ok'], 'meta' => ['request_id' => 'req_test']], $response->body);
    }
}
