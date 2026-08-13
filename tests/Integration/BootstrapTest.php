<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;

final class BootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/project-sync-bootstrap-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/storage/logs', 0777, true);
        file_put_contents($this->root . '/config/app.php', "<?php\nthrow new RuntimeException('Test bootstrap failure');\n");
    }

    protected function tearDown(): void
    {
        $log = $this->root . '/storage/logs/application.log';
        if (is_file($log)) {
            unlink($log);
        }
        unlink($this->root . '/config/app.php');
        rmdir($this->root . '/config');
        rmdir($this->root . '/storage/logs');
        rmdir($this->root . '/storage');
        rmdir($this->root);
    }

    public function testBootstrapFailureUsesTheStandardErrorEnvelope(): void
    {
        $response = ApplicationBootstrap::handle($this->root, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health']);

        self::assertSame(500, $response->status);
        self::assertFalse($response->body['success']);
        self::assertSame(['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.'], $response->body['error'] ?? null);
    }
}
