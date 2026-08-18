<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$yamlPath = $root . '/docs/openapi.yaml';
$jsonPath = $root . '/docs/openapi.json';

if (!file_exists($yamlPath)) {
    fwrite(STDERR, sprintf("Error: Canonical YAML spec not found at %s%s", $yamlPath, PHP_EOL));
    exit(1);
}

try {
    /** @var mixed $parsed */
    $parsed = Yaml::parseFile($yamlPath);
    if (!is_array($parsed)) {
        fwrite(STDERR, sprintf("Error: Failed to parse %s as YAML array.%s", $yamlPath, PHP_EOL));
        exit(1);
    }

    $json = json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if ($json === false) {
        fwrite(STDERR, sprintf("Error: Failed to encode specification to JSON.%s", PHP_EOL));
        exit(1);
    }

    file_put_contents($jsonPath, $json);
    echo sprintf("Successfully generated %s from canonical %s%s", $jsonPath, $yamlPath, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("OpenAPI generation failed: %s%s", $e->getMessage(), PHP_EOL));
    exit(1);
}
