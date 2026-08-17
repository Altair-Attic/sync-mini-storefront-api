# Business Profile API

All endpoints use the standard JSON envelope and return a `meta.request_id`. Administrator endpoints require `Authorization: Bearer <access-token>`. `PUT` requires an `application/json` content type; media-type parameters such as `charset=utf-8` are accepted.

The machine-readable contract is in `docs/business-profile.openapi.yaml`.

## `GET /api/v1/store`

Public. Returns `data.profile` with exactly `business_name`, `slug`, `domain`, `whatsapp_number`, `support_email`, `logo_url`, `template_id`, `currency`, `timezone`, `delivery_enabled`, `pickup_enabled`, and `fixed_delivery_fee_kobo`. The fulfilment settings support storefront estimates; checkout reloads them from MySQL. It never returns the profile ID, timestamps, private operational settings, administrator data, or secrets.

Returns `404 BUSINESS_PROFILE_NOT_FOUND` until onboarding creates the profile.

## `GET /api/v1/admin/profile`

Requires an active administrator Bearer token. Returns `data.profile` with the editable fields, including private notification settings, plus immutable identity and audit metadata: `id`, `slug`, `domain`, `created_at`, and `updated_at`. Timestamps use UTC RFC 3339 values such as `2026-08-13T12:00:00Z`. It does not return SMTP credentials, password information, or secrets.

Returns `401 UNAUTHENTICATED` without an active administrator and `404 BUSINESS_PROFILE_NOT_FOUND` when onboarding is incomplete.

## `PUT /api/v1/admin/profile`

This is a full replacement of the editable profile fields. Every documented field must be present:

```json
{
  "business_name": "Demo Store",
  "whatsapp_number": "+2348035732952",
  "support_email": "support@example.com",
  "logo_url": "https://cdn.example.com/logo.png",
  "template_id": "classic",
  "currency": "NGN",
  "timezone": "Africa/Lagos",
  "delivery_enabled": true,
  "pickup_enabled": true,
  "fixed_delivery_fee_kobo": 150000,
  "order_notification_email": "orders@example.com",
  "merchant_email_notifications_enabled": true,
  "customer_email_notifications_enabled": false,
  "whatsapp_handoff_enabled": true
}
```

`support_email` and `logo_url` may be `null`. Unknown fields are rejected, including immutable `id`, `slug`, `domain`, `created_at`, and `updated_at`.

Validation and normalization:

- `business_name`: trimmed, required, 2–120 characters.
- `whatsapp_number`: accepts E.164 international numbers or Nigerian mobile numbers beginning with `070`, `080`, `081`, `090`, or `091`; spaces, parentheses, and hyphens are removed, and Nigerian local numbers are stored as `+234...`.
- `support_email`: nullable; otherwise a valid email up to 254 characters, trimmed and lowercased.
- `logo_url`: nullable; otherwise a valid HTTPS URL up to 2,048 characters.
- `template_id`: trimmed and lowercased; 1–64 characters matching `[a-z0-9][a-z0-9_-]*`.
- `currency`: normalized to uppercase; only `NGN` is supported.
- `timezone`: trimmed and required to match a PHP timezone identifier.
- `delivery_enabled` and `pickup_enabled`: strict booleans; at least one must remain enabled.
- `fixed_delivery_fee_kobo`: a non-negative integer in Nigerian kobo; numeric strings and floats are rejected.
- `order_notification_email`: nullable valid email, trimmed and lowercased; `support_email` is used when this is null.
- Notification and WhatsApp enabled fields: strict booleans; numeric and string alternatives are rejected.

`order_notification_email` and the enabled switches are private and never appear in `GET /api/v1/store`.

Errors are `401 UNAUTHENTICATED`, `415 UNSUPPORTED_MEDIA_TYPE`, `422 VALIDATION_FAILED`, `404 BUSINESS_PROFILE_NOT_FOUND`, or the production-safe `500 INTERNAL_ERROR` envelope. Validation errors contain only field-level safe messages.
