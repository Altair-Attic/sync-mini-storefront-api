<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\Email\EmailMessage;
use ProjectSync\Services\NotificationProcessor;
use ProjectSync\Services\OrderEmailBuilder;
use ProjectSync\Services\WhatsAppHandoffService;

final class NotificationContentTest extends TestCase
{
    public function testEmailMessageRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailMessage('victim@example.com', "Order\r\nBcc: attacker@example.com", 'Body');
    }

    public function testMerchantAndCustomerContentUseSnapshotsAndProtectCustomerContent(): void
    {
        $builder = new OrderEmailBuilder();
        $order = $this->order();
        $business = $this->business();

        $merchant = $builder->merchant($order, $business, 'merchant@example.com');
        $customer = $builder->customer($order, $business, 'customer@example.com');

        self::assertStringContainsString('Snapshot Product x 2', $merchant->body);
        self::assertStringContainsString('+2349035732952', $merchant->body);
        self::assertStringContainsString('NGN 2,500.00', $merchant->body);
        self::assertStringNotContainsString('+2349035732952', $customer->body);
        self::assertStringNotContainsString('internal-order-id', $customer->body);
        self::assertStringNotContainsString('idempotency', $customer->body);
        self::assertStringContainsString('remains unpaid', $customer->body);
    }

    public function testWhatsAppUsesBusinessNumberAndEncodesDeliveryMessage(): void
    {
        $url = (new WhatsAppHandoffService())->url($this->order(), $this->business());

        self::assertNotNull($url);
        self::assertStringStartsWith('https://wa.me/2348035732952?text=', $url);
        $message = rawurldecode((string) parse_url($url, PHP_URL_QUERY));
        self::assertStringContainsString('Deliver to: 12 Example Street, Ogun', $message);
        self::assertStringContainsString('Snapshot Product x 2', $message);
        self::assertStringNotContainsString('internal-order-id', $message);
        self::assertStringNotContainsString('confirmation', $message);
    }

    public function testWhatsAppReturnsNullWhenDisabledOrNumberMissingAndPickupOmitsAddress(): void
    {
        $service = new WhatsAppHandoffService();
        $business = $this->business();
        $business['whatsapp_handoff_enabled'] = false;
        self::assertNull($service->url($this->order(), $business));
        $business['whatsapp_handoff_enabled'] = true;
        $business['whatsapp_number'] = '';
        self::assertNull($service->url($this->order(), $business));

        $business = $this->business();
        $order = $this->order();
        $order['fulfilment_method'] = 'pickup';
        $order['delivery_address'] = null;
        $order['state'] = null;
        $url = $service->url($order, $business);
        self::assertNotNull($url);
        self::assertStringNotContainsString('Deliver to', rawurldecode((string) parse_url($url, PHP_URL_QUERY)));
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id' => 'internal-order-id', 'reference' => 'SYNC-TEST', 'customer_name' => 'John Doe',
            'phone_number' => '+2349035732952', 'customer_email' => 'customer@example.com',
            'fulfilment_method' => 'delivery', 'delivery_address' => '12 Example Street', 'state' => 'Ogun',
            'subtotal_kobo' => 500000, 'delivery_fee_kobo' => 150000, 'total_kobo' => 650000,
            'currency' => 'NGN', 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid',
            'fulfilment_status' => 'new', 'created_at' => '2026-08-13T15:00:00Z',
            'items' => [[
                'product_public_id' => 'public-id', 'product_title' => 'Snapshot Product', 'product_slug' => 'snapshot-product',
                'unit_price_kobo' => 250000, 'quantity' => 2, 'line_total_kobo' => 500000,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function business(): array
    {
        return [
            'business_name' => 'Demo Store', 'whatsapp_number' => '+2348035732952',
            'support_email' => 'support@example.com', 'whatsapp_handoff_enabled' => true,
        ];
    }
}
