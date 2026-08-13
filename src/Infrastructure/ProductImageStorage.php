<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use Closure;
use ProjectSync\Exceptions\UploadException;
use RuntimeException;
use Throwable;

final readonly class ProductImageStorage
{
    /** @var Closure(string, string): bool */
    private Closure $moveUpload;

    /** @param Closure(string, string): bool|null $moveUpload */
    public function __construct(
        private string $storagePath,
        private string $publicPath,
        private int $maximumWidth,
        private int $maximumHeight,
        ?Closure $moveUpload = null,
    ) {
        $this->moveUpload = $moveUpload ?? static fn (string $source, string $target): bool => is_uploaded_file($source) && move_uploaded_file($source, $target);
    }

    /**
     * @param array{temporary_path: string, mime_type: string, extension: string, size: int} $upload
     * @return array{url: string, path: string}
     */
    public function store(array $upload): array
    {
        $this->ensureDirectory();
        $dimensions = getimagesize($upload['temporary_path']);
        if ($dimensions === false) {
            throw new UploadException('UNSUPPORTED_MEDIA_TYPE', 415, 'The uploaded file is not a valid image.');
        }
        $useWebp = function_exists('imagewebp') && function_exists('imagecreatefromstring');
        $extension = $useWebp ? 'webp' : $upload['extension'];
        $filename = bin2hex(random_bytes(24)) . '.' . $extension;
        $target = $this->storagePath . DIRECTORY_SEPARATOR . $filename;
        try {
            $stored = $useWebp
                ? $this->convertToWebp($upload['temporary_path'], $target, (int) $dimensions[0], (int) $dimensions[1])
                : ($this->moveUpload)($upload['temporary_path'], $target);
            if (!$stored) {
                throw new RuntimeException('The image could not be stored.');
            }
            @chmod($target, 0640);
        } catch (Throwable $exception) {
            if (is_file($target)) {
                unlink($target);
            }
            throw $exception;
        }

        return ['url' => rtrim($this->publicPath, '/') . '/' . $filename, 'path' => $target];
    }

    public function deleteManaged(?string $url): void
    {
        if ($url === null) {
            return;
        }
        $prefix = rtrim($this->publicPath, '/') . '/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }
        $filename = substr($url, strlen($prefix));
        if ($filename === '' || basename($filename) !== $filename) {
            return;
        }
        $path = $this->storagePath . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0750, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException('The product image directory is unavailable.');
        }
        if (!is_writable($this->storagePath)) {
            throw new RuntimeException('The product image directory is not writable.');
        }
    }

    private function convertToWebp(string $source, string $target, int $width, int $height): bool
    {
        $contents = file_get_contents($source);
        if (!is_string($contents)) {
            return false;
        }
        $image = imagecreatefromstring($contents);
        if ($image === false) {
            return false;
        }
        $scale = min(1, $this->maximumWidth / $width, $this->maximumHeight / $height);
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $output = $image;
        if ($targetWidth !== $width || $targetHeight !== $height) {
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($resized === false) {
                imagedestroy($image);
                return false;
            }
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            if (!imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                imagedestroy($image);
                imagedestroy($resized);
                return false;
            }
            $output = $resized;
        }
        $stored = imagewebp($output, $target, 82);
        if ($output !== $image) {
            imagedestroy($output);
        }
        imagedestroy($image);

        return $stored;
    }
}
