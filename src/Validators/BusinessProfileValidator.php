<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class BusinessProfileValidator
{
    /** @var list<string> */
    private const array EDITABLE_FIELDS = [
        'business_name',
        'whatsapp_number',
        'support_email',
        'logo_url',
        'template_id',
        'currency',
        'timezone',
        'delivery_enabled',
        'pickup_enabled',
        'fixed_delivery_fee_kobo',
        'order_notification_email',
        'merchant_email_notifications_enabled',
        'customer_email_notifications_enabled',
        'whatsapp_handoff_enabled',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{business_name: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, delivery_enabled: bool, pickup_enabled: bool, fixed_delivery_fee_kobo: int, order_notification_email: string|null, merchant_email_notifications_enabled: bool, customer_email_notifications_enabled: bool, whatsapp_handoff_enabled: bool}
     */
    public function validate(array $input): array
    {
        $errors = [];
        foreach ($input as $field => $_) {
            if (!in_array($field, self::EDITABLE_FIELDS, true)) {
                $errors[$field] = ['Unknown or forbidden field.'];
            }
        }
        foreach (self::EDITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = ['This field is required.'];
            }
        }

        $businessName = $this->string($input, 'business_name');
        if ($businessName === null || mb_strlen($businessName) < 2 || mb_strlen($businessName) > 120) {
            $errors['business_name'] = ['Use between 2 and 120 characters.'];
        }

        $whatsappNumber = $this->phoneNumber($input['whatsapp_number'] ?? null);
        if ($whatsappNumber === null) {
            $errors['whatsapp_number'] = ['Enter a valid Nigerian or international phone number.'];
        }

        $supportEmail = $this->nullableString($input, 'support_email');
        if ($supportEmail !== null && (strlen($supportEmail) > 254 || filter_var($supportEmail, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['support_email'] = ['Enter a valid email address.'];
        }

        $logoUrl = $this->nullableString($input, 'logo_url');
        if ($logoUrl !== null && !$this->validHttpsUrl($logoUrl)) {
            $errors['logo_url'] = ['Enter a valid HTTPS URL.'];
        }

        $templateId = $this->string($input, 'template_id');
        if ($templateId === null || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', strtolower($templateId)) !== 1) {
            $errors['template_id'] = ['Enter a valid template identifier.'];
        }

        $currency = $this->string($input, 'currency');
        if ($currency === null || strtoupper($currency) !== 'NGN') {
            $errors['currency'] = ['Unsupported currency.'];
        }

        $timezone = $this->string($input, 'timezone');
        if ($timezone === null || !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = ['Enter a valid timezone.'];
        }

        $deliveryEnabled = $input['delivery_enabled'] ?? null;
        if (!is_bool($deliveryEnabled)) {
            $errors['delivery_enabled'] = ['Enter true or false.'];
        }
        $pickupEnabled = $input['pickup_enabled'] ?? null;
        if (!is_bool($pickupEnabled)) {
            $errors['pickup_enabled'] = ['Enter true or false.'];
        }
        if ($deliveryEnabled === false && $pickupEnabled === false) {
            $errors['fulfilment_methods'] = ['At least one fulfilment method must remain enabled.'];
        }
        $deliveryFee = $input['fixed_delivery_fee_kobo'] ?? null;
        if (!is_int($deliveryFee) || $deliveryFee < 0 || $deliveryFee > 4_294_967_295) {
            $errors['fixed_delivery_fee_kobo'] = ['Enter a non-negative integer amount in kobo.'];
        }
        $notificationEmail = $this->nullableString($input, 'order_notification_email');
        if ($notificationEmail !== null && (strlen($notificationEmail) > 254 || filter_var($notificationEmail, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['order_notification_email'] = ['Enter a valid email address or null.'];
        }
        $merchantNotifications = $input['merchant_email_notifications_enabled'] ?? null;
        if (!is_bool($merchantNotifications)) {
            $errors['merchant_email_notifications_enabled'] = ['Enter true or false.'];
        }
        $customerNotifications = $input['customer_email_notifications_enabled'] ?? null;
        if (!is_bool($customerNotifications)) {
            $errors['customer_email_notifications_enabled'] = ['Enter true or false.'];
        }
        $whatsappHandoff = $input['whatsapp_handoff_enabled'] ?? null;
        if (!is_bool($whatsappHandoff)) {
            $errors['whatsapp_handoff_enabled'] = ['Enter true or false.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!is_bool($pickupEnabled) || !is_int($deliveryFee) || !is_bool($merchantNotifications) || !is_bool($customerNotifications) || !is_bool($whatsappHandoff)) {
            throw new \LogicException('Validated business settings have invalid types.');
        }
        return [
            'business_name' => $businessName,
            'whatsapp_number' => $whatsappNumber,
            'support_email' => $supportEmail === null ? null : strtolower($supportEmail),
            'logo_url' => $logoUrl,
            'template_id' => strtolower($templateId),
            'currency' => strtoupper($currency),
            'timezone' => $timezone,
            'delivery_enabled' => $deliveryEnabled,
            'pickup_enabled' => $pickupEnabled,
            'fixed_delivery_fee_kobo' => $deliveryFee,
            'order_notification_email' => $notificationEmail === null ? null : strtolower($notificationEmail),
            'merchant_email_notifications_enabled' => $merchantNotifications,
            'customer_email_notifications_enabled' => $customerNotifications,
            'whatsapp_handoff_enabled' => $whatsappHandoff,
        ];
    }

    /** @param array<string, mixed> $input */
    private function string(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $input */
    private function nullableString(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? trim($value) : '__invalid__';
    }

    private function phoneNumber(mixed $value): ?string
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

    private function validHttpsUrl(string $value): bool
    {
        if (strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        return $scheme === 'https' && is_string($host) && $host !== '';
    }
}
