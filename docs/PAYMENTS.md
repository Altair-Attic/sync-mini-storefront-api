# Payment Architecture and Lifecycle Specification

> Phase: 6A.1 (Payment Architecture Hardening)  
> Provider: Paystack  
> Target Implementation: Phase 6B (Server-Authoritative Payment Engine)

This document defines the payment trust model, state machine, data model, initialization idempotency, verification engine, server-to-server reconciliation, and checkout decoupling for Project Sync.

---

## 1. Core Principles and Trust Model

Project Sync enforces a **server-authoritative, fail-closed payment architecture**.

### 1.1 Non-Authoritative Sources

The following sources are untrusted and can **never** mark an order paid:

- Frontend JavaScript state or React components.
- Paystack inline JavaScript popup callbacks (`onSuccess`, `onClose`).
- Browser redirects and query parameters (`?reference=...&trxref=...`).
- Customer-submitted payment references or transaction identifiers.
- Customer-provided screenshots, bank receipts, or email confirmations.
- Merchant/admin manual state mutation requests (no admin endpoint can directly set `payment_status = 'paid'`).
- Unverified webhook payloads.

### 1.2 Authoritative Sources

Only two authoritative mechanisms may establish payment truth and transition an order to `paid`:

1. **Cryptographically Verified Paystack Webhook**: A webhook delivered to `POST /api/v1/payments/paystack/webhook`, verified via HMAC-SHA512 using `hash_equals()`, which satisfies all business-level validation rules (reference, amount, currency, order status).
2. **Server-to-Server (S2S) Direct Paystack Verification**: A direct TLS backend HTTP call (`GET https://api.paystack.co/transaction/verify/{reference}`) initiated by the backend using the configured `PAYSTACK_SECRET_KEY`, satisfying all business-level validation rules.

If payment truth cannot be established with 100% cryptographic and business-level certainty, the order remains `unpaid` or `pending`.

---

## 2. Decoupled Checkout and Payment Contract

Checkout and payment initialization remain strictly decoupled into two distinct phases:

```text
[Customer Browser]               [Project Sync API]                  [Paystack API]
        |                                |                                  |
 1.     |-- POST /api/v1/orders -------->|                                  |
        |   (cart, customer info)        |                                  |
        |<-- 201 Created ----------------| (Order saved: payment_status = unpaid)
        |   (order reference + token)    |                                  |
        |                                |                                  |
 2.     |-- POST /orders/{ref}/payments->|                                  |
        |   (token, Idempotency-Key)     |-- POST /transaction/initialize ->|
        |                                |<-- 200 OK (auth_url, ref) -------|
        |<-- 200/201 (auth_url, ref) ----|                                  |
        |                                |                                  |
 3.     |-- Redirect to Paystack --------+--------------------------------->|
        |                                |                                  |
 4.     |                                |<-- POST /payments/paystack/webhook (HMAC)
        |                                |-- Validate signature & amount    |
        |                                |-- Transaction commit: paid       |
        |                                |-- Enqueue notification job       |
        |<-- Browser Redirect -----------+----------------------------------|
```

### Rationale for Decoupling:
1. **Cart and Inventory Isolation**: Orders are reliably captured in MySQL before external payment dependencies are invoked. A transient Paystack outage never prevents order capture.
2. **Multi-Attempt Support**: A customer can make multiple payment attempts against the same order (e.g. first card declined -> second attempt with bank transfer) without duplicating orders or corrupting order-item snapshots.
3. **Auditability**: Order creation timestamps and payment attempt lifecycles are tracked independently.

---

## 3. State Machines and Lifecycle Separation

Payment state and fulfilment state are completely independent lifecycles.

### 3.1 Fulfilment Lifecycle (Order Level)

Canonical fulfilment transitions remain:

$$\text{new} \longrightarrow \text{confirmed} \longrightarrow \text{processing} \longrightarrow \text{ready} \longrightarrow \text{completed}$$

With $\text{cancelled}$ as a valid terminal state from any active state.

### 3.2 Simplified Aggregate Payment State (Order Level: `orders.payment_status`)

Aggregate order payment status represents the overall financial truth of the order. Attempt-level failures do **not** mark the order `failed`, preserving the customer's ability to retry payment on an active order:

| Status | Semantics |
|---|---|
| `unpaid` | Default state on order creation. No successful payment exists. |
| `pending` | An active server-created payment attempt is in-flight awaiting confirmation. |
| `paid` | Authoritative confirmation received matching exact order amount and currency. Terminal for positive payment flow. |
| `refunded` | Verified full refund completed (deferred operational flow). |

### 3.3 Payment Attempt State (Attempt Level: `payment_attempts.status`)

Tracks the granular lifecycle of an individual payment attempt:

$$\text{initialized} \longrightarrow \text{pending} \longrightarrow \begin{cases} \text{successful} \\ \text{failed} \\ \text{abandoned} \end{cases}$$

- `initialized`: Record created locally; call to Paystack transaction initialization in flight.
- `pending`: Paystack initialization succeeded; customer redirected; awaiting webhook/verification.
- `successful`: Authoritative verification succeeded; exact amount and currency matched.
- `failed`: Provider reported failure (card declined, insufficient funds, etc.).
- `abandoned`: Attempt expired or customer cancelled without completing payment.

---

## 4. Money Representation and Currency Rules

1. **Integer Minor Units**: All monetary values are represented strictly as unsigned 64-bit integers (`BIGINT UNSIGNED` in MySQL, `int` in PHP 8.x). Floating-point data types (`float`, `double`) are completely forbidden for monetary amounts.
2. **Nigerian Kobo Alignment**:
   - Project Sync stores all monetary values in integer kobo (`subtotal_kobo`, `delivery_fee_kobo`, `total_kobo`).
   - Paystack requires amounts in the currency's smallest sub-unit (for NGN, 1 Naira = 100 kobo).
   - Therefore, `orders.total_kobo` maps 1:1 to Paystack's `amount` field. No decimal conversions or division operations are performed.
3. **Amount Verification Invariant**:
   $$\text{paystack\_payload.amount} == \text{payment\_attempts.expected\_amount\_kobo} == \text{orders.total\_kobo}$$
   If `paystack_payload.amount` differs by even 1 kobo, the payment **must not** be marked `paid`.
4. **Currency Invariant**:
   $$\text{paystack\_payload.currency} == \text{payment\_attempts.currency} == \text{orders.currency} == \text{'NGN'}$$
   Mismatched currencies are rejected immediately.

---

## 5. Payment Reference Strategy

1. **Format**: Server-generated, cryptographically random, prefixed string:
   - Format: `PAY-SYNC-[A-Z0-9]{20}` (e.g. `PAY-SYNC-7K9F2M8X4B1N6W3Q0V5D`).
   - Entropy: Generated via `random_bytes()` / `bin2hex()` / base32 conversion.
2. **Scope**: Each payment reference is unique to **one single payment attempt**.
3. **Decoupling from Order Reference**: An order reference (`SYNC-98A7F12C`) is **never** used as a Paystack reference, ensuring multiple retry attempts on the same order have isolated, distinct provider references.

---

## 6. Payment Initialization and Scoped Idempotency

### 6.1 Initialization Endpoint: `POST /api/v1/orders/{reference}/payments`

**Access Control**: Requires order confirmation token (via `X-Confirmation-Token` header or `?token=` query param) to ensure guest ownership.

**Order-Scoped Idempotency Key**:
- Client sends `Idempotency-Key` header (16–200 printable ASCII characters).
- Server hashes key using HMAC-SHA256 (`ORDER_SECURITY_SECRET`) and enforces database uniqueness scoped to the order:
  $$\text{UNIQUE}(order\_id, idempotency\_key\_hash)$$
- This prevents accidental cross-order idempotency collisions if a client reuses a key across distinct orders.

**Idempotency Execution Rules**:
1. **Replay Validation**: When resolving an existing attempt by idempotency hash, the server explicitly checks:
   $$\text{existingAttempt.order\_id} === \text{requestedOrder.id}$$
   before returning cached initialization details.
2. **Replay (Same Key, Same Order)**: Returns existing payment attempt (`authorization_url`, `reference`, `access_code`) with HTTP 200 and `meta.idempotent_replay: true`.
3. **Active Pending Attempt Rule**: If an unexpired `pending` attempt exists for the order (created within 15 minutes), the server returns the active attempt rather than creating unnecessary duplicate Paystack sessions.
4. **New Attempt**: Server generates internal payment reference, inserts `payment_attempts` row in `initialized` status, calls Paystack `POST /transaction/initialize`, updates status to `pending`, and returns `authorization_url` to the customer.

### 6.2 Order Eligibility Matrix for Payment Initialization

| Order Fulfilment Status | Order Payment Status | Eligible for Payment Init? | Response / Action |
|---|---|---|---|
| `new`, `confirmed`, `processing`, `ready` | `unpaid` | **YES** | Create new payment attempt |
| `new`, `confirmed`, `processing`, `ready` | `pending` | **YES** (if previous attempt stale) | Return active attempt or create fresh attempt |
| Any | `paid` | **NO** | Reject: HTTP 409 `ALREADY_PAID` |
| `completed` | `paid` | **NO** | Reject: HTTP 409 `ORDER_COMPLETED` |
| `cancelled` | Any | **NO** | Reject: HTTP 422 `ORDER_CANCELLED` |

---

## 7. Webhook Architecture and Security

### 7.1 Webhook Endpoint: `POST /api/v1/payments/paystack/webhook`

- **Public Route**: No Bearer JWT, no session cookie, no CSRF validation.
- **Cryptographic Authentication**: Must validate `X-Paystack-Signature` header before reading or trusting body contents.
- **Raw Request Body Preservation**:
  - `php://input` must be read as raw, untouched string bytes.
  - The signature must be computed directly on the raw byte stream:
    $$\text{computed\_sig} = \text{hash\_hmac('sha512', \$rawBody, \$paystackSecretKey)}$$
  - Verification must use constant-time comparison:
    $$\text{hash\_equals}(\text{signatureHeader}, \text{computed\_sig})$$
  - Re-encoded or normalized JSON must **never** be used for signature verification.
- **Response Protocol**:
  - Return HTTP `200 OK` with minimal JSON `{"success": true}` immediately upon successful processing or safe duplicate skip.
  - Invalid signature returns HTTP `401 UNAUTHORIZED` with a generic message and safe log entry.

### 7.2 Webhook Idempotency Without Assumed Event IDs

Paystack does **not** guarantee a globally unique event ID in all webhook payloads. Idempotency is designed around the provider, event type, and verified payment reference:

1. **Uniqueness Constraint**:
   $$\text{UNIQUE}(provider, event\_type, provider\_reference)$$
   on `payment_events` for `charge.success` events.
2. **Execution Flow**:
   - Inside a database transaction with row locks:
     - Check `payment_events` for `(provider, event_type, provider_reference)`.
     - If found: Event is a replay. Return HTTP 200 immediately without re-executing business logic or enqueuing duplicate notifications.
     - If new: Insert `payment_events` record, verify payment attempt and order, transition order state, enqueue notification jobs, and commit.

---

## 8. Server-to-Server Verification and Reconciliation

### 8.1 Reconciliation Trigger Points
1. **Fallback for Missed Webhooks**: Merchant admin or automated reconciliation triggers verification if an order remains `pending` beyond a configured threshold.
2. **Customer Return Page**: When a customer returns to `/orders/{ref}/confirmation`, the frontend can poll `GET /api/v1/orders/{ref}/payments/{paymentRef}` to check status. If still `pending`, backend can run a rate-limited S2S verification check.

### 8.2 Reconciliation Invariant
S2S verification executes the exact same transaction boundary and validation rules as the webhook handler:
- Both paths converge on the exact same state machine update.
- Whichever path commits first wins; subsequent executions become safe idempotent no-ops.

---

## 9. Database Transaction Boundaries and Concurrency

When a payment is authoritatively verified (`charge.success`), the database update executes inside an atomic transaction with pessimistic locking:

```sql
START TRANSACTION;

-- 1. Lock payment attempt row
SELECT * FROM payment_attempts WHERE internal_reference = :ref FOR UPDATE;

-- 2. Lock order row
SELECT * FROM orders WHERE id = :order_id FOR UPDATE;

-- 3. Invariant Checks:
-- Verify attempt.status != 'successful'
-- Verify order.payment_status != 'paid'
-- Verify expected_amount_kobo == verified_amount_kobo
-- Verify currency == 'NGN'

-- 4. Atomically apply mutations
UPDATE payment_attempts SET status = 'successful', verified_amount_kobo = :amount, provider_status = :status, finalized_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :attempt_id;
UPDATE orders SET payment_status = 'paid', updated_at = UTC_TIMESTAMP() WHERE id = :order_id;

-- 5. Enqueue customer and merchant payment notification jobs
INSERT INTO notification_jobs (...) VALUES (...);

COMMIT;
```

**External Network Isolation**: No external HTTP calls (Paystack, SMTP) may be made while holding database row locks. All mail delivery remains deferred to the background notification worker.

---

## 10. Late Payment After Cancellation and Persisted Operational State

If a customer completes payment on Paystack *after* the merchant has already marked the order `cancelled`:

1. **Fail-Closed Fulfilment**: The order `fulfilment_status` remains `cancelled`. It is **never** automatically reopened.
2. **Financial Truth**: `orders.payment_status` is updated to `paid` (money was received), and `payment_attempts.status` is marked `successful`.
3. **Persisted Queryable Operational State**:
   - `payment_attempts.resolution_status` is set to `'requires_action'`.
   - `payment_events.processing_status` is set to `'requires_action'` with `processing_notes = 'payment_received_after_cancellation'`.
   - This ensures the anomaly is permanently queryable in MySQL by administrative filters and reports, regardless of email delivery success.
4. **Escalation**: A high-priority merchant notification job is enqueued to alert the merchant to manually fulfill the order or issue a refund.

---

## 11. Proposed Schema Specification for Phase 6B

### 11.1 Table: `payment_attempts`

```sql
CREATE TABLE payment_attempts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'paystack',
    internal_reference VARCHAR(64) NOT NULL,
    provider_reference VARCHAR(100) NULL,
    access_code VARCHAR(100) NULL,
    authorization_url VARCHAR(500) NULL,
    idempotency_key_hash CHAR(64) NOT NULL,
    expected_amount_kobo BIGINT UNSIGNED NOT NULL,
    verified_amount_kobo BIGINT UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    status VARCHAR(16) NOT NULL DEFAULT 'initialized',
    resolution_status VARCHAR(32) NOT NULL DEFAULT 'none',
    provider_status VARCHAR(32) NULL,
    channel VARCHAR(32) NULL,
    initiated_at DATETIME NOT NULL,
    finalized_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_attempts_internal_ref (internal_reference),
    UNIQUE KEY uq_payment_attempts_order_idempotency (order_id, idempotency_key_hash),
    KEY idx_payment_attempts_order (order_id),
    KEY idx_payment_attempts_provider_ref (provider, provider_reference),
    KEY idx_payment_attempts_status_initiated (status, initiated_at),
    KEY idx_payment_attempts_resolution (resolution_status),
    CONSTRAINT fk_payment_attempts_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> **Financial Integrity Invariant**: `ON DELETE RESTRICT` is enforced. Financial transaction records must never be cascaded or deleted.

### 11.2 Table: `payment_events`

```sql
CREATE TABLE payment_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    payment_attempt_id CHAR(36) NULL,
    order_id CHAR(36) NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'paystack',
    event_type VARCHAR(64) NOT NULL,
    provider_reference VARCHAR(100) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    processing_status VARCHAR(16) NOT NULL,
    processing_notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_events_provider_type_ref (provider, event_type, provider_reference),
    KEY idx_payment_events_attempt (payment_attempt_id),
    KEY idx_payment_events_order (order_id),
    KEY idx_payment_events_created_at (created_at),
    KEY idx_payment_events_status (processing_status),
    CONSTRAINT fk_payment_events_attempt FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_events_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 12. Proposed Phase 6B Endpoints

### 12.1 Public / Customer Endpoints

#### `POST /api/v1/orders/{reference}/payments`
- **Purpose**: Initializes a Paystack payment attempt.
- **Auth**: Public, requires confirmation token (`?token=` or `X-Confirmation-Token`).
- **Headers**: `Idempotency-Key` (16–200 ASCII chars).
- **Response**: `201 Created` (or `200 OK` on replay) with:
  ```json
  {
    "success": true,
    "data": {
      "payment_reference": "PAY-SYNC-...",
      "authorization_url": "https://checkout.paystack.com/...",
      "access_code": "0123456789",
      "status": "pending",
      "expected_amount_kobo": 15000,
      "currency": "NGN"
    },
    "meta": {
      "request_id": "req_...",
      "idempotent_replay": false
    }
  }
  ```

#### `GET /api/v1/orders/{reference}/payments/{paymentReference}`
- **Purpose**: Safe read-only status check for frontend polling.
- **Auth**: Public, requires confirmation token.
- **Response**: `200 OK` with payment status (`pending`, `paid`).

### 12.2 Provider Webhook Endpoint

#### `POST /api/v1/payments/paystack/webhook`
- **Purpose**: Ingestion and processing of signed Paystack webhook events.
- **Auth**: Cryptographic signature via `X-Paystack-Signature`.
- **Response**: `200 OK` with `{"success": true}`.

### 12.3 Merchant Administration Endpoints

#### `GET /api/v1/admin/orders/{orderId}/payments`
- **Purpose**: Read-only listing of all payment attempts, resolution statuses, and audit events.
- **Auth**: Bearer JWT (`Authorization: Bearer <token>`).

#### `POST /api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile`
- **Purpose**: Triggers a server-to-server Paystack transaction verification call.
- **Auth**: Bearer JWT (`Authorization: Bearer <token>`).
