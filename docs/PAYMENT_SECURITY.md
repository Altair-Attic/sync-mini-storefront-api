# Payment Security Architecture and Threat Model

> Phase: 6A.1 (Payment Architecture Hardening)  
> Provider: Paystack  
> Target Implementation: Phase 6B

This document defines the payment security policies, threat model, secret handling, logging policy, test/live mode separation, and failure recovery matrices for Project Sync.

---

## 1. Secrets Management Policy

### 1.1 Credential Invariants

- **Storage Location**: Paystack secrets exist **only** in the server `.env` configuration file outside the web root and source control.
- **Prohibited Exposures**:
  - `PAYSTACK_SECRET_KEY` must **never** appear in frontend JavaScript, HTML, bundle artifacts, cookies, or public API responses.
  - Secrets must **never** be logged in application logs, database tables, or exception stack traces.
  - Secrets must **never** be committed to Git.
- **cPanel Deployment**: In production cPanel environments, permissions on `.env` must be set to `0600` or `0640` and owned by the merchant cPanel account user.

### 1.2 Configuration Keys

```dotenv
# Paystack API Configuration
# Secret key used strictly server-side for signature validation, transaction initialization, and S2S verification
PAYSTACK_SECRET_KEY=sk_test_change_this_to_merchant_paystack_secret_key

# Base API endpoint for Paystack communication (defaults to https://api.paystack.co)
PAYSTACK_BASE_URL=https://api.paystack.co

# HTTP timeout in seconds for Paystack API calls (fail-fast timeout)
PAYSTACK_TIMEOUT_SECONDS=10
```

> **Note on Public Key**: Because Project Sync uses backend-initiated transaction initialization (`POST /transaction/initialize` via secret key) which returns `authorization_url` and `access_code`, `PAYSTACK_PUBLIC_KEY` is not needed in backend configuration. Minimizing configuration eliminates deployment mistakes.

---

## 2. Test vs. Live Mode Separation

To prevent accidental test key usage in production or live key usage in staging/testing:

1. **Environment Prefix Validation**:
   - In `APP_ENV=production`:
     - `PAYSTACK_SECRET_KEY` **must** begin with `sk_live_`.
     - Any configuration with `sk_test_` or containing `change-this` triggers an immediate `ConfigurationException` on application bootstrap.
   - In `APP_ENV=testing` or `local`:
     - Only `sk_test_` or mock keys are allowed.
2. **Automated CI/Testing**:
   - Automated unit and integration tests must run with mock HTTP handlers and test signatures. No live network calls to Paystack are permitted in test suites.

---

## 3. Cryptographic Verification and Raw-Body Preservation

### 3.1 Raw Request Body Invariant

Webhook signatures are computed over the exact byte stream transmitted over HTTP. Re-encoding parsed JSON into a string with `json_encode()` introduces whitespace, sorting, and Unicode escaping discrepancies that break cryptographic verification.

**Mandatory Implementation Rules**:
1. Read raw payload directly from `php://input` as a binary-safe string.
2. Compute the HMAC signature directly on this raw string before any JSON parsing:
   ```php
   $rawBody = ($this->readRawBody)(); // Reads php://input directly
   $computedSignature = hash_hmac('sha512', $rawBody, $paystackSecretKey);
   ```
3. Use timing-safe constant-time string comparison:
   ```php
   if (!hash_equals($signatureHeader, $computedSignature)) {
       // Log safe diagnostic failure and reject immediately with 401
       return JsonResponse::error('UNAUTHORIZED', 'Invalid webhook signature.', $requestId, 401);
   }
   ```
4. Only parse JSON payload after signature validation passes.

---

## 4. Logging and Redaction Policy

To prevent sensitive customer and financial data exposure in observability logs:

### 4.1 Permitted (Safe to Log)
- Internal payment attempt ID (`id`)
- Public order reference (`SYNC-98A7F12C`)
- Internal payment reference (`PAY-SYNC-...`)
- Provider name (`paystack`)
- Payment state transitions (`unpaid -> pending`, `pending -> paid`)
- Verification result categories (`success`, `amount_mismatch`, `currency_mismatch`, `invalid_signature`)
- HTTP status code and request ID

### 4.2 Prohibited (Never Logged)
- Paystack Secret Key (`PAYSTACK_SECRET_KEY`)
- `Authorization` header contents
- Webhook signature header (`X-Paystack-Signature`)
- Customer credit card numbers (PAN), CVV, PIN, or expiry dates
- Customer bank account numbers / BVN
- Full raw JSON webhook payload containing sensitive personal customer data

---

## 5. Threat Model

### 5.1 Assets
- **Payment Truth**: The integrity of whether an order is genuinely paid.
- **Merchant Revenue**: Preventing loss through underpayment or falsified success.
- **Order Integrity**: Immutability of order-item pricing, quantities, and totals.
- **Provider Secrets**: `PAYSTACK_SECRET_KEY`.
- **Customer Privacy**: Delivery details, email, and phone numbers.
- **Audit Records**: Proof of transactions and state changes.

### 5.2 Adversaries
- **Malicious Customer**: Attempts to obtain products without paying or by underpaying.
- **Unauthenticated Attacker**: Attempts webhook spoofing, replay attacks, or denial of service.
- **Compromised Browser / XSS**: Attempts to alter frontend payment callbacks or query parameters.
- **Malicious / Rogue Administrator**: Attempts to manipulate payment records.

### 5.3 Threat Matrix and Controls

| Threat | Attack Vector | Mitigation / Control |
|---|---|---|
| **Forged Success** | Customer tampers with browser redirect or JavaScript callback to claim payment succeeded. | **Zero Trust on Frontend**: Browser redirects and JS callbacks are non-authoritative. Orders are only marked paid via verified webhook or S2S verification. |
| **Amount Tampering** | Customer modifies cart total or creates a 100 kobo transaction on Paystack for a 50,000 kobo order. | **Strict Server Amount Matching**: Backend validates `paystack_amount == expected_amount_kobo == order.total_kobo`. Any difference results in rejection. |
| **Currency Tampering** | Customer completes payment in a lower-value currency (e.g. USD cents instead of NGN kobo). | **Currency Validation Invariant**: Backend validates `paystack_currency == 'NGN'`. |
| **Webhook Spoofing** | Attacker posts fake `charge.success` events to webhook endpoint. | **Cryptographic HMAC-SHA512**: Webhook checks `hash_equals()` against `PAYSTACK_SECRET_KEY` on raw bytes. |
| **Webhook Replay Attack** | Attacker intercepts a valid signed webhook and replays it multiple times. | **Persistent Event Idempotency**: `payment_events` table enforces `UNIQUE(provider, event_type, provider_reference)`. Duplicate events are skipped safely. |
| **Cross-Order Reference Hijacking** | Attacker uses a valid payment reference from Order A to mark Order B as paid. | **1:1 Attempt Binding**: Payment attempts are bound to specific `order_id` in database. Webhook verifies attempt belongs to the target order. |
| **IDOR on Payment Initialization** | Attacker guesses order references and initializes payment attempts to spam or lock orders. | **Confirmation Token Protection & Scoped Idempotency**: Payment initialization requires valid unguessable confirmation token; idempotency is scoped to `(order_id, idempotency_key_hash)`. |
| **Race Condition (Webhook vs Redirect)** | Webhook and S2S verification execute at the exact same millisecond. | **Pessimistic Row Locking**: `SELECT ... FOR UPDATE` ensures single serialized state transition to `paid`. |
| **Secret Key Leakage** | Backend leaks `PAYSTACK_SECRET_KEY` in logs or API responses. | **Strict Logging Redaction & Output Filtering**: Secret exists only in `.env`; omitted from all controllers and log formatters. |
| **Admin Direct Mutation** | Admin attempts to call `PATCH /orders/{id}` to set `payment_status = 'paid'`. | **Immutable Admin Payment Status**: Admin endpoints cannot modify `payment_status`. Only S2S reconciliation is exposed. |
| **Accidental Deletion of Financial Data** | Future admin operation attempts cascading delete of order. | **Financial Integrity Invariant**: `ON DELETE RESTRICT` is enforced on foreign keys linking `payment_attempts` and `payment_events`. |

---

## 6. Comprehensive Failure Scenario Matrix

The following matrix defines the exact system behavior for every edge case and failure mode:

| # | Failure Scenario | Persisted State | Retry Safe? | S2S Reconcile Required? | Customer Receives | Logged Diagnostics | Operator Action Needed? |
|---|---|---|---|---|---|---|---|
| 1 | **Forged frontend success response** | Order: `unpaid`, Attempt: `pending` | Yes | Yes (if customer claims paid) | Prompt to wait for verification / "Pending" | `SECURITY_EVENT: Unverified client payment claim` | None (fail-closed) |
| 2 | **Forged callback URL / query param** | Order: `unpaid`, Attempt: `pending` | Yes | Yes | Order pending confirmation | `WARN: Client redirected with unverified query params` | None |
| 3 | **Missing webhook signature** | No change | Yes | No | HTTP 401 Unauthorized | `WARN: Webhook missing X-Paystack-Signature` | None |
| 4 | **Invalid webhook signature** | No change | Yes | No | HTTP 401 Unauthorized | `SECURITY_EVENT: Webhook signature mismatch` | Investigate if repeated (attack) |
| 5 | **Duplicate webhook delivery** | Order: `paid`, Event: logged as replay | Yes | No | HTTP 200 OK | `INFO: Webhook duplicate skipped` | None |
| 6 | **Replayed valid webhook** | Order: `paid`, Event: logged as replay | Yes | No | HTTP 200 OK | `INFO: Webhook replay idempotently handled` | None |
| 7 | **Webhook for unknown reference** | No order change, Event: `mismatched` | No | No | HTTP 200 OK | `WARN: Webhook reference not found in database` | Investigate rogue transaction |
| 8 | **Successful event with wrong amount** | Order: `unpaid`, Event: `mismatched` | No | Manual review | HTTP 200 OK | `ERROR: Payment amount mismatch. Expected X, received Y` | **Yes**: Manual merchant review / refund |
| 9 | **Successful event with wrong currency** | Order: `unpaid`, Event: `mismatched` | No | Manual review | HTTP 200 OK | `ERROR: Payment currency mismatch. Expected NGN, received X` | **Yes**: Manual merchant review / refund |
| 10 | **Successful event referencing wrong order** | Target Order: `unpaid`, Event: `mismatched` | No | Manual review | HTTP 200 OK | `ERROR: Payment attempt order binding mismatch` | **Yes**: Manual investigation |
| 11 | **Provider timeout during initialization** | Order: `unpaid`, Attempt: `failed` | Yes | No | HTTP 504 Provider Timeout / Retry prompt | `WARN: Paystack initialization timeout` | None (customer retries) |
| 12 | **App timeout after Paystack creates attempt** | Order: `unpaid`, Attempt: `initialized` | Yes | Yes | Prompt to retry | `WARN: App timeout during initialization completion` | Customer retries; creates new attempt or recovers |
| 13 | **Two concurrent initialization requests** | Order: `unpaid`, Attempt: exactly 1 created | Yes | No | 1st: 201 Created; 2nd: 200 OK Replay | `INFO: Concurrent init resolved via scoped unique constraint` | None |
| 14 | **Webhook arrives before browser redirect** | Order: `paid` when redirect arrives | Yes | No | Instant confirmation of paid order | `INFO: Webhook finalized payment before redirect` | None |
| 15 | **Redirect arrives before webhook** | Order: `pending`; polling initiates | Yes | Yes (polling or fallback) | "Payment processing, awaiting confirmation" | `INFO: Client returned before webhook received` | None |
| 16 | **Reconciliation runs before webhook** | Order: `paid`; subsequent webhook no-ops | Yes | No | Confirmation of paid order | `INFO: S2S reconciliation finalized payment` | None |
| 17 | **Webhook and reconciliation run simultaneously** | Order: `paid` (locked transaction) | Yes | No | Consistent `paid` state | `INFO: Transaction lock serialized concurrent verification` | None |
| 18 | **Payment completed after order cancellation** | Order: `cancelled` + `paid`, Attempt: `resolution_status = 'requires_action'`, Event: `processing_status = 'requires_action'` | No | No | "Order was cancelled; merchant notified" | `ALERT: Payment received for cancelled order` | **Yes**: Queryable in DB; merchant fulfills or refunds |
| 19 | **Payment attempt on already-paid order** | Order: `paid` | No | No | HTTP 409 `ALREADY_PAID` | `WARN: Payment init rejected on paid order` | None |
| 20 | **Paystack API completely unavailable** | Order: `unpaid` | Yes | No | HTTP 503 Service Unavailable | `ERROR: Paystack API unavailable / network failure` | None (retry later) |
| 21 | **Database failure after Paystack initialization** | Paystack session exists, DB attempt rolled back | Yes | Yes | HTTP 500 Internal Error | `ERROR: DB transaction rollback during payment init` | Customer retries fresh |
| 22 | **Notification failure after payment confirmation** | Order: `paid`, Notification job: `pending` retry | Yes | No | Order is paid; email retries via cron | `WARN: Notification job enqueued; SMTP retryable` | Background worker delivers email |
| 23 | **Malformed provider JSON in webhook** | No change | Yes | No | HTTP 400 Bad Request | `WARN: Webhook payload JSON syntax error` | Investigate provider format |
| 24 | **Invalid environment configuration** | Boot fails | No | No | HTTP 500 Bootstrap Error | `CRITICAL: Insecure Paystack configuration` | **Yes**: Fix environment settings |
| 25 | **Test/Live mode confusion** | Boot fails (in production) | No | No | HTTP 500 Bootstrap Error | `CRITICAL: sk_test key supplied in production` | **Yes**: Configure correct live key |
