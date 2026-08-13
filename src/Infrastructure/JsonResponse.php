<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

final class JsonResponse
{
    /**
     * @param array<array-key, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function success(array $data, string $requestId, int $status = 200, array $meta = []): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json; charset=utf-8'], [
            'success' => true,
            'data' => $data,
            'meta' => $meta + ['request_id' => $requestId],
        ]);
    }

    /** @param array<string, list<string>> $fields */
    public static function error(string $code, string $message, string $requestId, int $status, array $fields = []): HttpResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return new HttpResponse($status, ['Content-Type' => 'application/json; charset=utf-8'], [
            'success' => false,
            'error' => $error,
            'meta' => ['request_id' => $requestId],
        ]);
    }
}
