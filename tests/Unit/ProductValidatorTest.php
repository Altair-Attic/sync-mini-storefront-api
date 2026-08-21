<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\ProductListQueryValidator;
use ProjectSync\Validators\ProductValidator;

final class ProductValidatorTest extends TestCase
{
    public function testValidUncategorizedProductUsesIntegerKoboAndGeneratedSlug(): void
    {
        $result = (new ProductValidator())->validate(['title' => '  Ankara Bag  ', 'price_kobo' => 250000]);

        self::assertSame('ankara-bag', $result['slug']);
        self::assertSame(250000, $result['price_kobo']);
        self::assertNull($result['category_id']);
        self::assertTrue($result['is_active']);
        self::assertTrue($result['is_available']);
        self::assertSame(0, $result['stock_quantity']);

        $explicit = (new ProductValidator())->validate(['title' => 'Unavailable Bag', 'price_kobo' => 100000, 'is_available' => false]);
        self::assertFalse($explicit['is_available']);

        $stocked = (new ProductValidator())->validate(['title' => 'Stocked Bag', 'price_kobo' => 100000, 'stock_quantity' => 12]);
        self::assertSame(12, $stocked['stock_quantity']);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidFields(string $field, mixed $value): void
    {
        try {
            (new ProductValidator())->validate(['title' => 'Valid Product', 'price_kobo' => 100, $field => $value]);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->fields);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'short title' => ['title', 'x'];
        yield 'decimal price' => ['price_kobo', 25.50];
        yield 'numeric string price' => ['price_kobo', '2500'];
        yield 'negative price' => ['price_kobo', -1];
        yield 'invalid category UUID' => ['category_id', 'bad'];
        yield 'insecure image URL' => ['image_url', 'http://example.com/image.png'];
        yield 'traversal image path' => ['image_url', '/uploads/../secret'];
        yield 'invalid slug' => ['slug', 'Bad Slug'];
        yield 'non-bool is_active' => ['is_active', 'yes'];
        yield 'non-bool is_available' => ['is_available', 'no'];
        yield 'negative stock quantity' => ['stock_quantity', -1];
        yield 'decimal stock quantity' => ['stock_quantity', 1.5];
        yield 'unknown field' => ['stock', 4];
        yield 'immutable currency' => ['currency', 'NGN'];
    }

    #[DataProvider('sortModes')]
    public function testAllPublicSortModesAreAccepted(string $sort): void
    {
        $query = (new ProductListQueryValidator())->publicQuery(['sort' => $sort]);

        self::assertSame($sort, $query['sort']);
    }

    /** @return iterable<string, array{string}> */
    public static function sortModes(): iterable
    {
        foreach (['display_order', 'title', 'price_low', 'price_high', 'newest'] as $sort) {
            yield $sort => [$sort];
        }
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testInvalidListQueriesAreRejected(array $query, string $field): void
    {
        try {
            (new ProductListQueryValidator())->publicQuery($query);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->fields);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'zero page' => [['page' => '0'], 'page'];
        yield 'oversize page' => [['per_page' => '101'], 'per_page'];
        yield 'bad sort' => [['sort' => 'random'], 'sort'];
        yield 'bad category' => [['category' => 'Bad Slug'], 'category'];
        yield 'unknown filter' => [['currency' => 'NGN'], 'currency'];
    }

    public function testAdminQueryAcceptsAvailabilityAndStatusFilters(): void
    {
        $validator = new ProductListQueryValidator();
        $query = $validator->adminQuery(['availability' => 'unavailable', 'status' => 'active']);
        self::assertSame('unavailable', $query['availability']);
        self::assertSame('active', $query['status']);

        $defaultQuery = $validator->adminQuery([]);
        self::assertSame('all', $defaultQuery['availability']);
        self::assertSame('all', $defaultQuery['status']);
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidAdminQueries')]
    public function testInvalidAdminListQueriesAreRejected(array $query, string $field): void
    {
        try {
            (new ProductListQueryValidator())->adminQuery($query);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->fields);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidAdminQueries(): iterable
    {
        yield 'bad availability' => [['availability' => 'in_stock'], 'availability'];
        yield 'bad status' => [['status' => 'archived'], 'status'];
        yield 'bad category id' => [['category_id' => 'not-a-uuid'], 'category_id'];
        yield 'oversize search' => [['search' => str_repeat('a', 161)], 'search'];
        yield 'unknown admin filter' => [['tags' => 'food'], 'tags'];
    }
}
