<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ProductImageStorage;

final class ProductImageStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/product-image-storage-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (scandir($this->directory) ?: [] as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->directory . DIRECTORY_SEPARATOR . $file);
                }
            }
            rmdir($this->directory);
        }
    }

    public function testGeneratedNamesAreCollisionResistantAndManagedDeletionIsScoped(): void
    {
        $sourceOne = $this->png();
        $sourceTwo = $this->png();
        $storage = new ProductImageStorage(
            $this->directory,
            '/uploads/products',
            2000,
            2000,
            static fn (string $source, string $target): bool => copy($source, $target),
        );
        try {
            $first = $storage->store($this->upload($sourceOne));
            $second = $storage->store($this->upload($sourceTwo));
            self::assertNotSame($first['url'], $second['url']);
            self::assertMatchesRegularExpression('#^/uploads/products/[0-9a-f]{48}\.(png|webp)$#', $first['url']);
            self::assertFileExists($first['path']);
            self::assertFileExists($second['path']);

            $storage->deleteManaged('https://cdn.example.com/external.webp');
            self::assertFileExists($first['path']);
            $storage->deleteManaged($first['url']);
            self::assertFileDoesNotExist($first['path']);
            self::assertFileExists($second['path']);
        } finally {
            unlink($sourceOne);
            unlink($sourceTwo);
        }
    }

    /** @return array{temporary_path: string, mime_type: string, extension: string, size: int} */
    private function upload(string $path): array
    {
        $size = filesize($path);
        if (!is_int($size)) {
            self::fail('Could not determine fixture size.');
        }

        return ['temporary_path' => $path, 'mime_type' => 'image/png', 'extension' => 'png', 'size' => $size];
    }

    private function png(): string
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $path = tempnam(sys_get_temp_dir(), 'product-image-source-');
        if (!is_string($contents) || $path === false) {
            self::fail('Could not create image fixture.');
        }
        file_put_contents($path, $contents);

        return $path;
    }
}
