<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use JsonException;

final class RequestParser
{
    /** @param array<string, mixed> $server */
    public static function isContentType(array $server, string $expected): bool
    {
        $value = $server['CONTENT_TYPE'] ?? null;
        if (!is_string($value)) {
            return false;
        }

        return strtolower(trim(explode(';', $value, 2)[0])) === $expected;
    }

    /** @return array<string, mixed> */
    public static function jsonObject(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException('The JSON body must be an object.');
        }
        $result = [];
        foreach ($decoded as $field => $value) {
            if (!is_string($field)) {
                throw new JsonException('The JSON object must use named fields.');
            }
            $result[$field] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function query(array $server): array
    {
        $uri = $server['REQUEST_URI'] ?? '';
        $queryString = is_string($uri) ? parse_url($uri, PHP_URL_QUERY) : null;
        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }
        $result = [];
        foreach ($query as $field => $value) {
            if (is_string($field)) {
                $result[$field] = $value;
            }
        }

        return $result;
    }
}
