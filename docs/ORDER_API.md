# Guest Order API

Phase 3 provides guest checkout, token-protected confirmation, persistent email notifications, and a server-generated WhatsApp handoff URL. Customers do not authenticate or receive sessions. Online payments, customer accounts, coupons, merchant order management, and automatic WhatsApp sending remain deferred.

## Delivery configuration

The business profile read and update contracts include:

- `delivery_enabled`: boolean
- `pickup_enabled`: boolean
- `fixed_delivery_fee_kobo`: non-negative integer

At least one fulfilment method must remain enabled. Pickup always costs `0` kobo. Delivery applies the single current fixed fee. Checkout reloads all settings and currency from MySQL; public store settings are estimates only.

## Create an order

`POST /api/v1/orders` is public, requires `Content-Type: application/json`, and must include an `Idempotency-Key` header containing 16–200 printable ASCII characters. Generate a new cryptographically random value for each intended order and retain it until checkout succeeds.

```http
POST /api/v1/orders HTTP/1.1
Content-Type: application/json
Idempotency-Key: 0e8fd2798c754dfca0f5541738eef65d

{
  "customer_name": "John Doe",
  "phone_number": "+2349035732952",
  "customer_email": "john@example.com",
  "fulfilment_method": "delivery",
  "delivery_address": "12 Example Street, Abeokuta",
  "state": "Ogun",
  "payment_method": "cash_on_delivery",
  "items": [{"product_id": "c2a1db61-c9b8-4d50-a223-93a15a6c4370", "quantity": 2}]
}
```

Pickup requires `delivery_address` and `state` to be `null`. Delivery requires both fields. Unknown fields—including client prices, currency, fees, totals, and payment status—are rejected. Item UUIDs must be unique, quantities must be integers, and configured cart and quantity limits apply.

Product activity and current prices are loaded in one bounded MySQL query. All money is integer Nigerian kobo. The only payment method is `cash_on_delivery`; new orders use payment status `unpaid`, fulfilment status `new`, and currency `NGN` from the business profile.

A new order returns HTTP `201` after its database transaction commits, independently of email delivery. The response contains its public reference, confirmation token, confirmation-safe customer/order fields, immutable item snapshots, `whatsapp_url`, safe `notification` state, and `meta.idempotent_replay: false`. Internal IDs are never returned.

`notification.merchant_email` and `notification.customer_email` are `sent`, `queued`, or `skipped`. Recipient addresses, job IDs, provider errors, and credentials are never exposed. `whatsapp_url` is nullable and no network call is made.

## Idempotency and retry behavior

The server stores an HMAC-protected hash of the idempotency key, never the raw key. It fingerprints the normalized request, checks before and inside the transaction, and relies on a unique database constraint to resolve concurrent races.

- Same key and same normalized request: HTTP `200`, the original result, and `meta.idempotent_replay: true`.
- Same key and different request: HTTP `409` with `IDEMPOTENCY_KEY_CONFLICT`.
- Failure before commit: neither the order nor any item remains.
- Retry after commit: the committed order and the same confirmation credential are returned.
- Replay never creates jobs, resends sent email, or immediately retries queued jobs; it returns current safe notification state.

The confirmation credential is a domain-separated HMAC derivation from the protected idempotency hash and a per-deployment `ORDER_SECURITY_SECRET`. Only its SHA-256 lookup hash is stored. This makes safe replay possible without storing either raw credential.

## Confirmation

`GET /api/v1/orders/{reference}/confirmation?token={confirmation-token}` is public and rate-limited. Both values are required. Unknown references and invalid tokens return the same HTTP `404 ORDER_NOT_FOUND` response. The response includes the nullable WhatsApp handoff URL and safe notification state, while omitting phone number, email, internal IDs, job IDs, provider details, idempotency data, and request fingerprints.

## Errors

All responses use the standard envelope and include `meta.request_id`. Relevant stable codes are:

- `VALIDATION_FAILED`
- `IDEMPOTENCY_KEY_REQUIRED`
- `IDEMPOTENCY_KEY_INVALID`
- `IDEMPOTENCY_KEY_CONFLICT`
- `PRODUCT_UNAVAILABLE`
- `FULFILMENT_METHOD_UNAVAILABLE`
- `ORDER_NOT_FOUND`
- `ORDER_TOTAL_LIMIT_EXCEEDED`
- `RATE_LIMITED`
- `INTERNAL_ERROR`

Database errors, SQL, stack traces, secrets, and administrator data are never exposed.
