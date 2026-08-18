# Phase 6C: Paystack Test-Mode End-to-End Verification Report

**Verification Date**: 2026-08-18  
**Environment**: Local (`APP_ENV=local`) with HTTPS LocalTunnel (`https://my-project-sync-webhook.loca.lt`)  
**Backend Runtime**: PHP 8.4.21, MySQL 8.x / MariaDB (via phpMyAdmin environment)  
**Paystack Mode**: Test Mode (`sk_test_****`, `pk_test_****`)  
**Verification Scope**: Real Paystack Test Environment Integration & Server-Authoritative Lifecycle  

---

## 1. Safety & Credential Verification

- **Environment Gate**: Verified `APP_ENV != production` (`APP_ENV=local`).
- **Credential Constraint**: Verified `PAYSTACK_SECRET_KEY` starts strictly with `sk_test_` (`sk_test_****fa32`). No live credentials (`sk_live_`, `pk_live_`) or real cards/money were introduced.
- **Paystack Test Account**: Connected to authorized Paystack test dashboard with IP whitelisting configured for test mode.

---

## 2. Public HTTPS Webhook Preflight Security

- **Endpoint**: `https://my-project-sync-webhook.loca.lt/api/v1/payments/paystack/webhook`
- **GET Request**: Rejected safely with HTTP `405 Method Not Allowed`. No internals exposed.
- **POST without Signature**: Rejected safely with HTTP `401 Unauthorized` (`UNAUTHORIZED`).
- **POST with Invalid Signature**: Rejected safely with HTTP `401 Unauthorized` (`UNAUTHORIZED`).
- **POST with Malformed JSON**: Rejected safely with HTTP `401 Unauthorized` prior to JSON decode; no stack traces or server details leaked.
- **Logging**: Confirmed all preflight rejections logged safe failure events with request IDs and zero secret exposure.

---

## 3. Order Creation & Payment Initialization

- **Synthetic Test Order**:
  - Reference: `SYNC-20260818-0F4F4875`
  - Total: `15000` kobo (NGN 150.00)
  - Initial State: `fulfilment_status = new`, `payment_status = unpaid`
- **Initialization Endpoint**: `POST /api/v1/orders/SYNC-20260818-0F4F4875/payments`
  - Authorization: `X-Confirmation-Token` verified.
  - Idempotency Key: `test_pay_init_1_retry123456`
  - Generated Internal Reference: `PAY-SYNC-UWP9XSG9CK04ESM9YWHV`
  - Paystack Test Response: HTTP `201 Created` returning `authorization_url` (`https://checkout.paystack.com/9taj3v4ddr4nsx4`), `access_code`, and `status = pending`.
- **Initialization Idempotency**:
  - Replay with identical `Idempotency-Key` returned cached attempt with `meta.idempotent_replay: true` and HTTP `200 OK` in < 2 seconds without creating a duplicate Paystack session or database row.

---

## 4. Real Checkout & Webhook Finalization

- **Paystack Test Checkout**: Completed successfully via official Paystack test card / bank transfer channel.
- **Real Webhook Delivery**:
  - Event: `charge.success`
  - HMAC-SHA512 Signature: Validated successfully on raw body byte stream.
  - Reference: `PAY-SYNC-UWP9XSG9CK04ESM9YWHV`
- **Finalized Database State**:
  - `orders.payment_status` $\rightarrow$ `paid`
  - `orders.fulfilment_status` $\rightarrow$ `new` (independent, unchanged by payment)
  - `payment_attempts.status` $\rightarrow$ `successful`
  - `payment_attempts.provider_status` $\rightarrow$ `success`
  - `payment_attempts.verified_amount_kobo` $\rightarrow$ `15000`
  - `payment_events.event_type` $\rightarrow$ `charge.success` (`processing_status = processed`)
  - `notification_jobs` $\rightarrow$ Merchant payment notification job enqueued.

---

## 5. Security & Decoupling Invariants

- **Merchant Inspection**:
  - `GET /api/v1/admin/orders/{orderId}/payments` with JWT Bearer token returned structured payment attempts, event history, and safe provider statuses without exposing secrets or raw payloads.
- **Server-to-Server Reconciliation**:
  - `POST /api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile` verified against Paystack test API, proving idempotent state preservation (`verified: true`).
- **Redirect Decoupling & IDOR Protection**:
  - Arbitrary `?reference=` and `?trxref=` query parameters on `/confirmation` cannot mutate payment state.
  - Forged payment reference on `/payments/{ref}` returned HTTP `404 PAYMENT_NOT_FOUND`.
  - Invalid confirmation token returned HTTP `400 CONFIRMATION_TOKEN_INVALID`.
- **Webhook Replay Protection**:
  - Replayed signed webhook payload returned HTTP `200 OK` (`idempotent_replay: true`) and resulted in zero duplicate rows in `payment_events` or duplicate notification jobs.
- **Late Payment on Cancelled Order**:
  - Dedicated order `SYNC-20260818-FCB13EEE` initialized with payment `PAY-SYNC-3F024H7BFU9547DVZFZS`, then cancelled via admin API (`fulfilment_status = cancelled`).
  - Upon receiving payment confirmation:
    - `fulfilment_status` remained `cancelled` (did NOT reopen).
    - `payment_status` transitioned to `paid`.
    - `payment_attempts.resolution_status` persistently set to `requires_action`.
    - `payment_events.processing_notes` recorded `payment_received_after_cancellation`.
- **Amount & Currency Tampering**:
  - Webhook with amount `10000` kobo instead of `15000` rejected with `PAYMENT_AMOUNT_MISMATCH` and left order `unpaid`/`pending`.
  - Webhook with currency `USD` instead of `NGN` rejected safely without marking order `paid`.

---

## 6. Audit & Automated Regression Gate

- **Database Integrity**:
  - Orphaned payment attempts: 0
  - Orphaned payment events: 0
  - Mismatched amounts: 0
  - Invalid state combinations: 0
  - Secrets in payment events: 0
- **Secret Leakage Audit**: Scanned all logs; zero occurrences of `sk_test_`, `sk_live_`, `Authorization: Bearer`, or webhook signatures found.
- **PHPUnit Regression Suite**:
  - Command: `APP_ENV=testing RUN_DB_INTEGRATION_TESTS=1 composer test`
  - Result: **OK (221 tests, 2997 assertions, 0 skips, 0 failures)**.
- **Static Analysis**: `composer analyse` (PHPStan Level 9) $\rightarrow$ **OK (No errors across 143 files)**.
- **Composer Audit**: `composer audit` $\rightarrow$ **No security vulnerability advisories found**.
- **Frontend Isolation**: No frontend files modified.

---

## 7. Phase 6C Conclusion

Phase 6C is **PASSED**. The backend payment engine is fully verified against Paystack's real test environment, satisfying all server-authoritative and cryptographic invariants.
