<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\CheckoutException;
use ProjectSync\Exceptions\ValidationException;

final readonly class CheckoutValidator
{
    /** @var list<string> */
    private const array FIELDS = [
        'customer_name', 'phone_number', 'customer_email', 'fulfilment_method',
        'delivery_address', 'state', 'payment_method', 'items',
    ];

    public function __construct(
        private int $maxDistinctItems,
        private int $maxQuantity,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{customer_name: string, phone_number: string, customer_email: string|null, fulfilment_method: string, delivery_address: string|null, state: string|null, payment_method: string, items: list<array{product_id: string, quantity: int}>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        foreach ($input as $field => $_value) {
            if (!in_array($field, self::FIELDS, true)) {
                $errors[$field] = ['Unknown field.'];
            }
        }

        $name = $this->trimmed($input['customer_name'] ?? null);
        if ($name === null || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['customer_name'] = ['Use between 2 and 120 characters.'];
        }
        $phone = $this->phone($input['phone_number'] ?? null);
        if ($phone === null) {
            $errors['phone_number'] = ['Enter a valid Nigerian or international phone number.'];
        }
        $email = $this->nullableTrimmed($input['customer_email'] ?? null);
        if ($email === false || (is_string($email) && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
            $errors['customer_email'] = ['Enter a valid email address or null.'];
        }

        $method = $input['fulfilment_method'] ?? null;
        if (!is_string($method) || !in_array($method, ['pickup', 'delivery'], true)) {
            $errors['fulfilment_method'] = ['Choose pickup or delivery.'];
        }
        $address = $this->nullableTrimmed($input['delivery_address'] ?? null);
        $state = $this->nullableTrimmed($input['state'] ?? null);
        if ($address === false || (is_string($address) && mb_strlen($address) > 500)) {
            $errors['delivery_address'] = ['Use at most 500 characters.'];
        }
        if ($state === false || (is_string($state) && mb_strlen($state) > 100)) {
            $errors['state'] = ['Use at most 100 characters.'];
        }
        if ($method === 'delivery') {
            if (!is_string($address) || $address === '') {
                $errors['delivery_address'] = ['Delivery address is required for delivery.'];
            }
            if (!is_string($state) || $state === '') {
                $errors['state'] = ['State is required for delivery.'];
            }
        } elseif ($method === 'pickup') {
            if ($address !== null) {
                $errors['delivery_address'] = ['Delivery address must be null for pickup.'];
            }
            if ($state !== null) {
                $errors['state'] = ['State must be null for pickup.'];
            }
        }

        $paymentMethod = $input['payment_method'] ?? null;
        if (!is_string($paymentMethod) || !in_array($paymentMethod, ['cash_on_delivery', 'paystack'], true)) {
            $errors['payment_method'] = ['Choose cash_on_delivery or paystack.'];
        }
        $items = $this->items($input['items'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!is_string($name) || !is_string($phone) || !is_string($method) || !is_string($paymentMethod)) {
            throw new \LogicException('Validated checkout fields have invalid types.');
        }
        usort($items, static fn (array $left, array $right): int => strcmp($left['product_id'], $right['product_id']));

        return [
            'customer_name' => $name,
            'phone_number' => $phone,
            'customer_email' => is_string($email) ? strtolower($email) : null,
            'fulfilment_method' => $method,
            'delivery_address' => is_string($address) ? $address : null,
            'state' => is_string($state) ? $state : null,
            'payment_method' => $paymentMethod,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{product_id: string, quantity: int}>
     */
    private function items(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $errors['items'] = ['Provide at least one item.'];

            return [];
        }
        if (count($value) > $this->maxDistinctItems) {
            $errors['items'] = ['Too many distinct items.'];
        }
        $result = [];
        $seen = [];
        foreach ($value as $index => $item) {
            $prefix = 'items.' . $index;
            if (!is_array($item) || array_is_list($item)) {
                $errors[$prefix] = ['Each item must be an object.'];
                continue;
            }
            foreach ($item as $field => $_itemValue) {
                if (!is_string($field) || !in_array($field, ['product_id', 'quantity'], true)) {
                    $errors[$prefix . '.' . (is_string($field) ? $field : 'unknown')] = ['Unknown field.'];
                }
            }
            $productId = $item['product_id'] ?? null;
            if (!is_string($productId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $productId) !== 1) {
                $errors[$prefix . '.product_id'] = ['Enter a valid product UUID.'];
            }
            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1 || $quantity > $this->maxQuantity) {
                $errors[$prefix . '.quantity'] = ['Enter an integer within the permitted quantity range.'];
            }
            if (is_string($productId)) {
                $normalizedId = strtolower($productId);
                if (isset($seen[$normalizedId])) {
                    $errors[$prefix . '.product_id'] = ['Duplicate products are not allowed.'];
                }
                $seen[$normalizedId] = true;
            }
            if (is_string($productId) && is_int($quantity)) {
                $result[] = ['product_id' => strtolower($productId), 'quantity' => $quantity];
            }
        }

        return $result;
    }

    private function trimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableTrimmed(mixed $value): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? trim($value) : false;
    }

    private function phone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = preg_replace('/[\s()-]+/', '', trim($value));
        if (!is_string($normalized)) {
            return null;
        }
        if (preg_match('/^0(?:70|80|81|90|91)[0-9]{8}$/', $normalized) === 1) {
            $normalized = '+234' . substr($normalized, 1);
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized) === 1 ? $normalized : null;
    }
}
