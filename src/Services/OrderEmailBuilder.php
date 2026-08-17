<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\Email\EmailMessage;

final class OrderEmailBuilder
{
    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $business
     */
    public function merchant(array $order, array $business, string $recipient): EmailMessage
    {
        $lines = [
            $this->string($business, 'business_name'),
            'New order: ' . $this->string($order, 'reference'),
            'Customer: ' . $this->string($order, 'customer_name'),
            'Phone: ' . $this->string($order, 'phone_number'),
        ];
        if (is_string($order['customer_email'] ?? null)) {
            $lines[] = 'Email: ' . $order['customer_email'];
        }
        $lines = array_merge($lines, $this->details($order, true));

        return new EmailMessage($recipient, 'New order ' . $this->string($order, 'reference'), implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $business
     */
    public function customer(array $order, array $business, string $recipient): EmailMessage
    {
        $lines = [
            $this->string($business, 'business_name'),
            'Order confirmation: ' . $this->string($order, 'reference'),
            'Customer: ' . $this->string($order, 'customer_name'),
        ];
        $lines = array_merge($lines, $this->details($order, false));
        $contact = $business['support_email'] ?? null;
        if (is_string($contact)) {
            $lines[] = 'Business contact: ' . $contact;
        }

        return new EmailMessage($recipient, 'Order confirmation ' . $this->string($order, 'reference'), implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $business
     */
    public function customerStatusUpdate(array $order, array $business, string $recipient, string $status): EmailMessage
    {
        $lines = [
            $this->string($business, 'business_name'),
            'Order ' . $this->string($order, 'reference') . ' is now ' . ucfirst($status),
            'Customer: ' . $this->string($order, 'customer_name'),
        ];
        $lines = array_merge($lines, $this->details($order, false));
        $contact = $business['support_email'] ?? null;
        if (is_string($contact)) {
            $lines[] = 'Business contact: ' . $contact;
        }

        return new EmailMessage($recipient, 'Order ' . $this->string($order, 'reference') . ' status update: ' . ucfirst($status), implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $order
     * @return list<string>
     */
    private function details(array $order, bool $includePrices): array
    {
        $fulfilment = $this->string($order, 'fulfilment_method');
        $lines = ['Fulfilment: ' . $fulfilment];
        if ($fulfilment === 'delivery') {
            $lines[] = 'Delivery address: ' . $this->string($order, 'delivery_address');
            $lines[] = 'State: ' . $this->string($order, 'state');
        }
        $lines[] = 'Items:';
        $items = $order['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $line = '- ' . $this->string($item, 'product_title') . ' x ' . $this->integer($item, 'quantity');
                if ($includePrices) {
                    $line .= ' @ ' . $this->money($item['unit_price_kobo'] ?? 0) . ' = ' . $this->money($item['line_total_kobo'] ?? 0);
                }
                $lines[] = $line;
            }
        }
        $lines[] = 'Subtotal: ' . $this->money($order['subtotal_kobo'] ?? 0);
        $lines[] = 'Delivery fee: ' . $this->money($order['delivery_fee_kobo'] ?? 0);
        $lines[] = 'Total: ' . $this->money($order['total_kobo'] ?? 0);
        $lines[] = 'Currency: ' . $this->string($order, 'currency');
        $lines[] = 'Payment method: ' . $this->string($order, 'payment_method');
        $lines[] = 'Payment status: ' . $this->string($order, 'payment_status') . ' (cash on delivery remains unpaid until payment is received)';
        $lines[] = 'Fulfilment status: ' . $this->string($order, 'fulfilment_status');
        $lines[] = 'Order time: ' . $this->string($order, 'created_at');

        return $lines;
    }

    private function money(mixed $kobo): string
    {
        if (!is_int($kobo)) {
            $kobo = 0;
        }

        return 'NGN ' . number_format(intdiv($kobo, 100)) . '.' . str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<array-key, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid email data.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Invalid email data.');
        }

        return $value;
    }
}
