<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Exceptions\ProductNotFoundException;
use ProjectSync\Infrastructure\ProductImageStorage;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Validators\ProductImageValidator;
use Throwable;

final readonly class ProductImageService
{
    public function __construct(
        private ProductRepository $products,
        private ProductImageValidator $validator,
        private ProductImageStorage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function upload(string $productId, array $file): array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new ProductNotFoundException();
        }
        $stored = $this->storage->store($this->validator->validate($file));
        try {
            $this->products->updateImage($productId, $stored['url']);
        } catch (Throwable $exception) {
            $this->storage->deleteManaged($stored['url']);
            throw $exception;
        }
        $oldUrl = $product['image_url'] ?? null;
        $this->storage->deleteManaged(is_string($oldUrl) ? $oldUrl : null);
        $updated = $this->products->find($productId);
        if ($updated === null) {
            throw new ProductNotFoundException();
        }

        return $updated;
    }
}
