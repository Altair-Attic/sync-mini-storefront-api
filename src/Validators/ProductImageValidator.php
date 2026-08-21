<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use finfo;
use ProjectSync\Exceptions\UploadException;

final readonly class ProductImageValidator
{
    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function __construct(private int $maximumBytes)
    {
    }

    /**
     * @param array<string, mixed> $file
     * @return array{temporary_path: string, mime_type: string, extension: string, size: int}
     */
    public function validate(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException('UPLOAD_TOO_LARGE', 413, 'The uploaded image is too large.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException('VALIDATION_FAILED', 422, 'A valid image upload is required.');
        }
        $path = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_string($path) || !is_file($path) || !is_int($size) || $size < 1) {
            throw new UploadException('VALIDATION_FAILED', 422, 'A valid image upload is required.');
        }
        if ($size > $this->maximumBytes) {
            throw new UploadException('UPLOAD_TOO_LARGE', 413, 'The uploaded image is too large.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) {
            throw new UploadException('UNSUPPORTED_MEDIA_TYPE', 415, 'Only JPEG, PNG, WebP, and AVIF images are supported.');
        }

        return ['temporary_path' => $path, 'mime_type' => $mime, 'extension' => self::EXTENSIONS[$mime], 'size' => $size];
    }
}
