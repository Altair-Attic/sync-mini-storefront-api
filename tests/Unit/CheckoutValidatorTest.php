<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\CheckoutValidator;

final class CheckoutValidatorTest extends TestCase
{
    private CheckoutValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CheckoutValidator(2, 10);
    }

    public function testValidDeliveryIsNormalizedAndItemsAreSorted(): void
    {
        $input = $this->valid();
        $input['customer_name'] = '  John Doe ';
        $input['phone_number'] = '0903 573 2952';
        $input['customer_email'] = ' JOHN@EXAMPLE.COM ';
        $input['items'] = [
            ['product_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'quantity' => 2],
            ['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 1],
        ];

        $result = $this->validator->validate($input);

        self::assertSame('John Doe', $result['customer_name']);
        self::assertSame('+2349035732952', $result['phone_number']);
        self::assertSame('john@example.com', $result['customer_email']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $result['items'][0]['product_id']);
    }

    public function testValidPickupRequiresNullDeliveryFields(): void
    {
        $input = $this->valid();
        $input['fulfilment_method'] = 'pickup';
        $input['delivery_address'] = null;
        $input['state'] = null;

        $result = $this->validator->validate($input);

        self::assertNull($result['delivery_address']);
        self::assertNull($result['state']);
    }

    public function testPaystackPaymentMethodIsPreserved(): void
    {
        $input = $this->valid();
        $input['payment_method'] = 'paystack';

        self::assertSame('paystack', $this->validator->validate($input)['payment_method']);
    }

    /** @param mixed $value */
    #[DataProvider('invalidFields')]
    public function testInvalidCheckoutFieldsAreRejected(string $field, mixed $value): void
    {
        $input = $this->valid();
        $input[$field] = $value;

        try {
            $this->validator->validate($input);
            self::fail('Expected checkout validation to fail.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->fields);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'missing name' => ['customer_name', ''];
        yield 'invalid phone' => ['phone_number', '123'];
        yield 'invalid email' => ['customer_email', 'bad'];
        yield 'missing delivery address' => ['delivery_address', null];
        yield 'missing state' => ['state', null];
        yield 'empty items' => ['items', []];
        yield 'too many items' => ['items', [
            ['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 1],
            ['product_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'quantity' => 1],
            ['product_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'quantity' => 1],
        ]];
        yield 'invalid UUID' => ['items', [['product_id' => 'bad', 'quantity' => 1]]];
        yield 'quantity zero' => ['items', [['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 0]]];
        yield 'negative quantity' => ['items', [['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => -1]]];
        yield 'float quantity' => ['items', [['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 1.5]]];
        yield 'quantity above maximum' => ['items', [['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 11]]];
        yield 'duplicate products' => ['items', [
            ['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 1],
            ['product_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'quantity' => 2],
        ]];
        yield 'client price' => ['price_kobo', 1];
        yield 'client subtotal' => ['subtotal_kobo', 1];
        yield 'client total' => ['total_kobo', 1];
    }

    public function testPickupRejectsDeliveryFieldsAndUnknownItemFields(): void
    {
        $input = $this->valid();
        $input['fulfilment_method'] = 'pickup';
        $input['items'] = [['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 1, 'price_kobo' => 1]];

        $this->expectException(ValidationException::class);
        $this->validator->validate($input);
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'customer_name' => 'John Doe',
            'phone_number' => '+2349035732952',
            'customer_email' => 'john@example.com',
            'fulfilment_method' => 'delivery',
            'delivery_address' => '12 Example Street, Abeokuta',
            'state' => 'Ogun',
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 2]],
        ];
    }
}
