<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ProjectSync\Exceptions\BusinessProfileNotFoundException;
use ProjectSync\Exceptions\CheckoutException;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\ProductRepository;
use Throwable;

final readonly class CheckoutService
{
    public function __construct(
        private PDO $db,
        private BusinessProfileRepository $profiles,
        private ProductRepository $products,
        private OrderRepository $orders,
        private OrderItemRepository $orderItems,
        private OrderReferenceGenerator $references,
        private OrderConfirmationTokenService $tokens,
        private int $maxTotalKobo,
        private ?NotificationService $notifications = null,
    ) {
    }

    /**
     * @param array{customer_name: string, phone_number: string, customer_email: string|null, fulfilment_method: string, delivery_address: string|null, state: string|null, payment_method: string, items: list<array{product_id: string, quantity: int}>} $request
     */
    public function create(array $request): CheckoutResult
    {
        $configuration = $this->profiles->checkoutConfiguration();
        if ($configuration === null) {
            throw new BusinessProfileNotFoundException();
        }
        $method = $request['fulfilment_method'];
        if (($method === 'delivery' && !$configuration['delivery_enabled']) || ($method === 'pickup' && !$configuration['pickup_enabled'])) {
            throw new CheckoutException('FULFILMENT_METHOD_UNAVAILABLE', 'The selected fulfilment method is unavailable.', 422);
        }

        $requestedIds = array_column($request['items'], 'product_id');
        $loaded = $this->products->findForCheckout($requestedIds);
        $productMap = [];
        foreach ($loaded as $product) {
            $productMap[$product['public_id']] = $product;
        }
        $snapshots = [];
        $subtotal = 0;
        foreach ($request['items'] as $item) {
            $product = $productMap[$item['product_id']] ?? null;
            if ($product === null || !$product['is_active'] || !$product['is_available']) {
                throw new CheckoutException('PRODUCT_UNAVAILABLE', 'One or more products are unavailable.', 422);
            }
            $lineTotal = $this->multiply($product['price_kobo'], $item['quantity']);
            $subtotal = $this->add($subtotal, $lineTotal);
            $snapshots[] = [
                'id' => UuidGenerator::v4(),
                'product_id' => $product['id'],
                'product_public_id' => $product['public_id'],
                'product_title' => $product['title'],
                'product_slug' => $product['slug'],
                'unit_price_kobo' => $product['price_kobo'],
                'quantity' => $item['quantity'],
                'line_total_kobo' => $lineTotal,
            ];
        }
        $deliveryFee = $method === 'delivery' ? $configuration['fixed_delivery_fee_kobo'] : 0;
        $total = $this->add($subtotal, $deliveryFee);
        $orderId = UuidGenerator::v4();
        $token = $this->tokens->generate();
        $order = [
            'id' => $orderId,
            'reference' => $this->references->generate(),
            'confirmation_token_hash' => $this->tokens->tokenHash($token),
            'idempotency_key_hash' => null,
            'request_fingerprint' => null,
            'customer_name' => $request['customer_name'],
            'phone_number' => $request['phone_number'],
            'customer_email' => $request['customer_email'],
            'fulfilment_method' => $method,
            'delivery_address' => $request['delivery_address'],
            'state' => $request['state'],
            'subtotal_kobo' => $subtotal,
            'delivery_fee_kobo' => $deliveryFee,
            'total_kobo' => $total,
            'currency' => $configuration['currency'],
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
            'fulfilment_status' => 'new',
        ];
        foreach ($snapshots as &$snapshot) {
            $snapshot['order_id'] = $orderId;
        }
        unset($snapshot);

        try {
            $this->db->beginTransaction();
            $this->orders->insert($order);
            $this->orderItems->insertMany($snapshots);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }

        $stored = $this->orders->findByReference($order['reference']);
        if ($stored === null) {
            throw new \RuntimeException('Committed order could not be reloaded.');
        }

        return new CheckoutResult($this->publicOrder($stored, false), $token);
    }

    /** @return array<string, mixed> */
    public function confirmation(string $reference, string $token): array
    {
        $order = $this->orders->findByReference($reference);
        $storedHash = $order['confirmation_token_hash'] ?? null;
        if ($order === null || !is_string($storedHash) || !$this->tokens->valid($token, $storedHash)) {
            throw new CheckoutException('ORDER_NOT_FOUND', 'The order was not found.', 404);
        }

        return $this->publicOrder($order, true);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function safeOrder(array $order): array
    {
        $id = $order['id'] ?? null;
        $createdAt = $order['created_at'] ?? null;
        if (!is_string($id) || !is_string($createdAt)) {
            throw new \RuntimeException('Invalid stored order.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $createdAt, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \RuntimeException('Invalid order timestamp.');
        }

        return [
            'reference' => $order['reference'],
            'customer_name' => $order['customer_name'],
            'fulfilment_method' => $order['fulfilment_method'],
            'delivery_address' => $order['delivery_address'],
            'state' => $order['state'],
            'subtotal_kobo' => $order['subtotal_kobo'],
            'delivery_fee_kobo' => $order['delivery_fee_kobo'],
            'total_kobo' => $order['total_kobo'],
            'currency' => $order['currency'],
            'payment_method' => $order['payment_method'],
            'payment_status' => $order['payment_status'],
            'fulfilment_status' => $order['fulfilment_status'],
            'items' => $this->orderItems->findByOrderId($id),
            'created_at' => $date->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function publicOrder(array $order, bool $replay): array
    {
        $safe = $this->safeOrder($order);
        if ($this->notifications === null) {
            return $safe;
        }
        $notificationOrder = $order;
        $notificationOrder['items'] = $safe['items'];
        $notificationOrder['created_at'] = $safe['created_at'];
        try {
            $state = $this->notifications->checkoutState($notificationOrder, $replay);
        } catch (Throwable) {
            $state = ['whatsapp_url' => null, 'notification' => ['merchant_email' => 'skipped', 'customer_email' => 'skipped']];
        }

        return $safe + $state;
    }

    private function multiply(int $price, int $quantity): int
    {
        if ($price < 0 || $quantity < 1 || ($price !== 0 && $quantity > intdiv(PHP_INT_MAX, $price))) {
            throw new CheckoutException('ORDER_TOTAL_LIMIT_EXCEEDED', 'The order total exceeds the permitted limit.', 422);
        }

        return $this->withinLimit($price * $quantity);
    }

    private function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new CheckoutException('ORDER_TOTAL_LIMIT_EXCEEDED', 'The order total exceeds the permitted limit.', 422);
        }

        return $this->withinLimit($left + $right);
    }

    private function withinLimit(int $amount): int
    {
        if ($amount > $this->maxTotalKobo) {
            throw new CheckoutException('ORDER_TOTAL_LIMIT_EXCEEDED', 'The order total exceeds the permitted limit.', 422);
        }

        return $amount;
    }

    private function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
