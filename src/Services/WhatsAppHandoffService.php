<?php

declare(strict_types=1);

namespace ProjectSync\Services;

final class WhatsAppHandoffService
{
    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $business
     */
    public function url(array $order, array $business): ?string
    {
        if (($business['whatsapp_handoff_enabled'] ?? false) !== true) {
            return null;
        }
        $number = $business['whatsapp_number'] ?? null;
        if (!is_string($number)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $number);
        if (!is_string($digits) || preg_match('/^[1-9][0-9]{7,14}$/', $digits) !== 1) {
            return null;
        }
        $lines = [
            'Hello! I just placed an order on your website.',
            '',
            'Order ID: #' . $this->string($order, 'reference'),
            '',
            'Order Details',
        ];
        $items = $order['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $lines[] = '- ' . $this->integer($item, 'quantity') . ' × ' . $this->string($item, 'product_title');
                }
            }
        }
        $subtotal = is_int($order['subtotal_kobo'] ?? null) ? $order['subtotal_kobo'] : 0;
        $deliveryFee = is_int($order['delivery_fee_kobo'] ?? null) ? $order['delivery_fee_kobo'] : 0;
        if (($order['fulfilment_method'] ?? null) === 'delivery') {
            $deliveryAddress = $this->string($order, 'delivery_address') . ', ' . $this->string($order, 'state');
        } else {
            $deliveryAddress = 'Pickup';
        }
        $total = is_int($order['total_kobo'] ?? null) ? $order['total_kobo'] : 0;
        $lines[] = 'Subtotal: ' . $this->money($subtotal);
        $lines[] = 'Delivery Fee: ' . $this->money($deliveryFee);
        $lines[] = 'Total: ' . $this->money($total);
        $lines[] = 'Payment Status: ' . ucfirst($this->string($order, 'payment_status'));
        $lines[] = '';
        $lines[] = 'Delivery Details';
        $lines[] = 'Name: ' . $this->string($order, 'customer_name');
        $lines[] = 'Address: ' . $deliveryAddress;
        $lines[] = 'Phone: ' . $this->string($order, 'phone_number');
        $lines[] = '';
        $lines[] = 'Kindly confirm that my order has been received and is being processed. Thank you.';

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode(implode("\n", $lines));
    }

    /** @param array<array-key, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid WhatsApp handoff data.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Invalid WhatsApp handoff data.');
        }

        return $value;
    }

    private function money(int $kobo): string
    {
        return '₦' . number_format(intdiv($kobo, 100)) . '.' . str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }
}
