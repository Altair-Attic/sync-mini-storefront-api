<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;

final readonly class DocumentationController
{
    public function __construct(
        private string $root,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function ui(string $requestId, array $server, array $params): HttpResponse
    {
        $indexPath = $this->root . '/public/docs/index.html';
        if (!is_file($indexPath)) {
            return JsonResponse::error('NOT_FOUND', 'Documentation UI not found.', $requestId, 404);
        }

        $html = (string) file_get_contents($indexPath);

        return new HttpResponse(
            status: 200,
            headers: [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ],
            body: [],
            rawBody: $html,
        );
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function yaml(string $requestId, array $server, array $params): HttpResponse
    {
        $yamlPath = $this->root . '/docs/openapi.yaml';
        if (!is_file($yamlPath)) {
            return JsonResponse::error('NOT_FOUND', 'OpenAPI YAML specification not found.', $requestId, 404);
        }

        $yaml = (string) file_get_contents($yamlPath);

        return new HttpResponse(
            status: 200,
            headers: [
                'Content-Type' => 'application/yaml; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'public, max-age=3600',
            ],
            body: [],
            rawBody: $yaml,
        );
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function json(string $requestId, array $server, array $params): HttpResponse
    {
        $jsonPath = $this->root . '/docs/openapi.json';
        if (!is_file($jsonPath)) {
            return JsonResponse::error('NOT_FOUND', 'OpenAPI JSON specification not found.', $requestId, 404);
        }

        $json = (string) file_get_contents($jsonPath);

        return new HttpResponse(
            status: 200,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'public, max-age=3600',
            ],
            body: [],
            rawBody: $json,
        );
    }
}
