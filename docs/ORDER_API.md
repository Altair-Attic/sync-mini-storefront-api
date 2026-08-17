# Guest Order API

Phase 3 provides guest checkout, token-protected confirmation, persistent email notifications, and a server-generated WhatsApp handoff URL. Customers do not authenticate or receive sessions.

Phase 6A defines the Paystack payment security and architecture specification (see `docs/PAYMENTS.md` and `docs/PAYMENT_SECURITY.md`). Checkout and payment initialization remain strictly decoupled: orders are captured as `payment_status: unpaid` during guest checkout; payment initialization occurs subsequently against `POST /api/v1/orders/{reference}/payments`.


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
- `INVALID_STATUS_TRANSITION`
- `RATE_LIMITED`
- `INTERNAL_ERROR`

Database errors, SQL, stack traces, secrets, and administrator data are never exposed.

---

# Merchant Order Management API

Phase 4 provides merchant order management endpoints. All endpoints require an active administrator JWT sent as `Authorization: Bearer <access-token>`.

## Endpoints

### 1. List Orders: `GET /api/v1/admin/orders`

Retrieves a paginated list of orders for the merchant's store.

**Query Parameters:**
- `page`: positive integer, default `1`.
- `per_page`: integer `1`–`100`, default `20`.
- `status`: optional filter matching a valid status (`new`, `confirmed`, `processing`, `ready`, `completed`, `cancelled`).
- `search`: optional text up to 100 characters searching order reference, customer name, email, or phone number.
- `sort`: `newest` (default), `oldest`, `total_high`, `total_low`.
- `date_from`, `date_to`: optional ISO 8601 or `YYYY-MM-DD` timestamps.

**Response Structure:**
```json
{
  "success": true,
  "data": [
    {
      "id": "c2a1db61-c9b8-4d50-a223-93a15a6c4370",
      "reference": "SYNC-98A7F12C",
      "customer_name": "John Doe",
      "phone_number": "+2349035732952",
      "customer_email": "john@example.com",
      "fulfilment_method": "delivery",
      "delivery_address": "12 Example Street, Abeokuta",
      "state": "Ogun",
      "subtotal_kobo": 10000,
      "delivery_fee_kobo": 5000,
      "total_kobo": 15000,
      "currency": "NGN",
      "payment_method": "cash_on_delivery",
      "payment_status": "unpaid",
      "fulfilment_status": "confirmed",
      "created_at": "2026-08-17T12:00:00Z",
      "updated_at": "2026-08-17T12:05:00Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1,
    "request_id": "req_123"
  }
}
```

### 2. Orders Summary: `GET /api/v1/admin/orders/summary`

Returns status counts across the merchant's store.

**Response Structure:**
```json
{
  "success": true,
  "data": {
    "summary": {
      "new": 4,
      "confirmed": 3,
      "processing": 2,
      "ready": 1,
      "completed": 20,
      "cancelled": 2,
      "total": 32
    }
  },
  "meta": {
    "request_id": "req_123"
  }
}
```

### 3. Order Details: `GET /api/v1/admin/orders/{orderId}`

Retrieves complete merchant-facing order details including immutable item snapshots and audit status history. Accepts either UUID or public order reference.

**Response Structure:**
```json
{
  "success": true,
  "data": {
    "order": {
      "id": "c2a1db61-c9b8-4d50-a223-93a15a6c4370",
      "reference": "SYNC-98A7F12C",
      "customer_name": "John Doe",
      "phone_number": "+2349035732952",
      "customer_email": "john@example.com",
      "fulfilment_method": "delivery",
      "delivery_address": "12 Example Street, Abeokuta",
      "state": "Ogun",
      "subtotal_kobo": 10000,
      "delivery_fee_kobo": 5000,
      "total_kobo": 15000,
      "currency": "NGN",
      "payment_method": "cash_on_delivery",
      "payment_status": "unpaid",
      "fulfilment_status": "confirmed",
      "items": [
        {
          "id": "uuid",
          "order_id": "uuid",
          "product_id": "uuid",
          "product_public_id": "uuid",
          "product_title": "Product A",
          "product_slug": "product-a",
          "unit_price_kobo": 5000,
          "quantity": 2,
          "line_total_kobo": 10000,
          "created_at": "2026-08-17 12:00:00"
        }
      ],
      "status_history": [
        {
          "id": "uuid",
          "previous_status": "new",
          "new_status": "confirmed",
          "changed_by": "admin-uuid",
          "created_at": "2026-08-17T12:05:00Z"
        }
      ],
      "created_at": "2026-08-17T12:00:00Z",
      "updated_at": "2026-08-17T12:05:00Z"
    }
  },
  "meta": {
    "request_id": "req_123"
  }
}
```

### 4. Update Order Status: `PATCH /api/v1/admin/orders/{orderId}/status`

Accepts JSON `{"status": "<new_status>"}`.

**Lifecycle Transitions:**
- `new` $\rightarrow$ `confirmed`, `cancelled`
- `confirmed` $\rightarrow$ `processing`, `cancelled`
- `processing` $\rightarrow$ `ready`, `cancelled`
- `ready` $\rightarrow$ `completed`, `cancelled`
- `completed`, `cancelled` are terminal; no further transitions are allowed.

**Concurrency & Idempotency:**
- Transactions use pessimistic `FOR UPDATE` locking.
- Setting the same status (`current === requested`) is idempotent: returns HTTP 200 with `meta.idempotent_replay: true` without creating duplicate status history or duplicate notification jobs.
- Valid status transitions append a record to `order_status_history` and enqueue customer notification jobs asynchronously.
