<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\UploadException;
use ProjectSync\Validators\ProductImageValidator;

final class ProductImageValidatorTest extends TestCase
{
    /** @param non-empty-string $base64 */
    #[DataProvider('validImages')]
    public function testAcceptsAllowedImageContent(string $base64, string $mime): void
    {
        $path = $this->temporary(base64_decode($base64, true));
        try {
            $result = (new ProductImageValidator(10000))->validate(['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => filesize($path)]);
            self::assertSame($mime, $result['mime_type']);
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{non-empty-string, string}> */
    public static function validImages(): iterable
    {
        yield 'jpeg' => ['/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', 'image/jpeg'];
        yield 'png' => ['iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'image/png'];
        yield 'webp' => ['UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', 'image/webp'];
    }

    public function testRejectsDisguisedPhpAndOversizedFiles(): void
    {
        $path = $this->temporary('<?php echo "bad";');
        try {
            $this->expectException(UploadException::class);
            (new ProductImageValidator(10000))->validate(['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => filesize($path), 'name' => 'photo.jpg', 'type' => 'image/jpeg']);
        } finally {
            unlink($path);
        }
    }

    public function testOversizedFileUses413Error(): void
    {
        $path = $this->temporary(str_repeat('x', 20));
        try {
            (new ProductImageValidator(10))->validate(['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => 20]);
            self::fail('UploadException was not thrown.');
        } catch (UploadException $exception) {
            self::assertSame(413, $exception->httpStatus);
            self::assertSame('UPLOAD_TOO_LARGE', $exception->errorCode);
        } finally {
            unlink($path);
        }
    }

    private function temporary(string|false $contents): string
    {
        if (!is_string($contents)) {
            self::fail('Invalid test fixture.');
        }
        $path = tempnam(sys_get_temp_dir(), 'catalogue-image-');
        if ($path === false) {
            self::fail('Could not create temporary file.');
        }
        file_put_contents($path, $contents);

        return $path;
    }
}
