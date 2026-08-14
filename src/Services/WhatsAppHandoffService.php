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
            $this->string($business, 'business_name'),
            'Order ' . $this->string($order, 'reference'),
            'Customer: ' . $this->string($order, 'customer_name'),
            'Phone: ' . $this->string($order, 'phone_number'),
            'Fulfilment: ' . $this->string($order, 'fulfilment_method'),
        ];
        if (($order['fulfilment_method'] ?? null) === 'delivery') {
            $lines[] = 'Deliver to: ' . $this->string($order, 'delivery_address') . ', ' . $this->string($order, 'state');
        }
        $items = $order['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $lines[] = '- ' . $this->string($item, 'product_title') . ' x ' . $this->integer($item, 'quantity');
                }
            }
        }
        $total = is_int($order['total_kobo'] ?? null) ? $order['total_kobo'] : 0;
        $lines[] = 'Total: NGN ' . number_format(intdiv($total, 100)) . '.' . str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
        $lines[] = 'Payment: ' . $this->string($order, 'payment_method');
        $lines[] = 'Status: ' . $this->string($order, 'fulfilment_status');

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
}
