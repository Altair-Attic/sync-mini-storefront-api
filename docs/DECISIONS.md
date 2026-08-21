# Architecture Decisions

## 2026-08-21 — Product deletion policy

Status: approved and implemented.

- `DELETE /api/v1/admin/products/{id}` permanently removes a product only when no `order_items` record references it.
- A product referenced by an existing order is archived (`is_active = FALSE`) instead; immutable order-item snapshots remain intact.
- The response always includes an `action` and human-readable `message` so the merchant knows whether deletion or archival occurred.

## 2026-08-21 — Simplified storefront API security

Status: approved and implemented.

- Merchant administration uses one stateless HS256 JWT Bearer token. It validates signature, subject, and expiry; the default lifetime is eight hours. Logout is a frontend state operation, so issued tokens are not revoked before expiry.
- Refresh cookies, rotation, refresh-token families, same-origin checks for cookie operations, JWT issuer/audience/JTI/token-version rules, and the revoked-token denylist are removed. The legacy refresh/revocation tables remain temporarily for retention only and are not queried at runtime.
- Guest checkout no longer requires `Idempotency-Key`. Each accepted submission creates an independent transaction; frontend submit controls must prevent ordinary double clicks. Paystack initialization and webhook event processing remain idempotent.
- Order confirmation uses a fresh cryptographically random token per order and stores only its SHA-256 hash. No order security secret or deterministic replay credential remains.
- CLI-only notification processing has no notification security secret. Basic login, checkout, and payment rate limits remain; Paystack verification, webhook HMAC, server-side amounts, and secure uploads are unchanged.

## 2026-08-14 — Administrator JWT access and rotating refresh credentials

Status: approved for Phase 1.

- Administrator API access uses a signed JWT Bearer token with a 15-minute default lifetime. JWTs contain only `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`, `jti`, and `token_version`; the signing algorithm is pinned in trusted configuration.
- JWT was selected to give the React administrator an explicit, short-lived API credential. The frontend keeps it only in memory. Backend examples must never direct clients to persist it in `localStorage` or `sessionStorage`.
- Renewal uses an opaque random refresh token in a Secure, HttpOnly, host-only, SameSite cookie. Only an HMAC-SHA-256 token hash is stored, under a secret distinct from the JWT signing secret.
- Refresh tokens rotate on every successful use. The replaced row remains as a reuse marker; presenting it again revokes the complete token family. Logout revokes the current family and expires the cookie. A valid Bearer token supplied at logout is deny-listed by JWT ID until its expiry, so that one session loses access immediately without signing out other devices.
- Login, refresh, and logout enforce the configured same-origin policy. Public storefront and guest checkout remain unauthenticated, and public administrator registration remains out of scope.
- A bounded database cleanup runs during authentication activity, so cPanel installations need neither Redis nor a persistent worker.

## 2026-08-13 — Phase 3A fulfilment pricing and confirmation access

Status: approved for MVP.

- Guest checkout supports `pickup` and `delivery`; at least one must remain enabled.
- Pickup has no fee. Delivery uses one fixed administrator-configured fee in integer Nigerian kobo. State-based pricing is deferred.
- Checkout reloads product prices, currency, and fulfilment settings from the merchant MySQL database.
- `cash_on_delivery` is the only current payment method; online payment remains deferred.
- Confirmation requires a reference and an unguessable token. The token is deterministically derived using a per-deployment application secret so identical idempotent retries can recover the same credential; only its hash is stored.

## 2026-08-13 — Phase 3B notifications and WhatsApp handoff

Status: approved for MVP.

- The database commit is the checkout success boundary; notification work starts afterward.
- Merchant and optional customer email use persistent MySQL jobs and PHPMailer SMTP with bounded at-least-once retry processing.
- Jobs store no addresses or bodies; recipients are resolved from permitted order/business fields during processing.
- WhatsApp handoff creates a URL for the business number without an API call or automatic sending.
- Merchant order notification uses a dedicated address with `support_email` fallback. Customer email is opt-in at business level and requires an order email.

## 2026-08-17 — Phase 4 Merchant order management and status lifecycle

Status: approved for MVP.

- Merchant order management endpoints require Bearer JWT authentication and operate strictly on the isolated single-tenant merchant database.
- The order status lifecycle follows explicit allowable transitions with `new` as the single canonical initial fulfilment status:
  - `new` $\rightarrow$ `confirmed`, `cancelled`
  - `confirmed` $\rightarrow$ `processing`, `cancelled`
  - `processing` $\rightarrow$ `ready`, `cancelled`
  - `ready` $\rightarrow$ `completed`, `cancelled`
  - Terminal statuses `completed` and `cancelled` cannot transition into any active state.
- Status mutations execute inside database transactions with `FOR UPDATE` pessimistic row locking to prevent race conditions across concurrent admin requests.
- Same-status mutations are idempotent (`unchanged: true` / `meta.idempotent_replay: true`), generating no duplicate history entries and no duplicate notification jobs.
- All valid status transitions append audit records to `order_status_history`, recording `previous_status`, `new_status`, `changed_by` admin user ID, and timestamp.
- Customer notification jobs for order status updates are enqueued asynchronously in the persistent notification queue; order state changes commit independently of email transport delivery.

## 2026-08-17 — Phase 5 Product availability and catalogue operations

Status: approved for MVP.

- Canonical product availability uses two decoupled boolean flags:
  - `is_active`: Controls presence in the public storefront catalogue. Inactive products (`is_active = FALSE`) are completely hidden from public listings and slug lookup (`404 PRODUCT_NOT_FOUND`).
  - `is_available`: Controls whether an active product can be ordered. Active but unavailable products (`is_active = TRUE`, `is_available = FALSE`) remain visible in public catalogue listings with `"is_available": false`, but cannot be purchased.
- Checkout availability enforcement: Every product in a submitted cart is revalidated at checkout against the current database state. If any item is missing, inactive, or unavailable (`is_available = FALSE`), checkout rejects the entire cart with `422 PRODUCT_UNAVAILABLE` and creates zero order records.
- Server-side pricing: Unit prices and totals are strictly calculated from current database values; client-submitted totals or unit prices are never trusted or accepted. Order item snapshots in `order_items` preserve checkout-time pricing and titles immutably.
- Category rules: Products can only be assigned to existing, active categories. Deactivating a category preserves historical product assignments, but public product responses display `category: null`.
- Admin endpoints require Bearer JWT authentication and support product CRUD, bounded listing with `status`, `availability`, `category_id`, and `search` filters, and dedicated availability toggling via `PATCH /api/v1/admin/products/{id}/availability`.

## 2026-08-17 — Phase 6A / 6A.1 Paystack payment architecture and delivery sequence realignment

Status: approved for Phase 6.

- Delivery Sequence Realignment: Paystack payment processing (originally scheduled for later Phase 9 in `PROJECT_ARCHITECTURE.md`) is intentionally brought forward into Phase 6 to enable commercial merchant revenue capture earlier in the product lifecycle, while preserving single-tenant database isolation, structured plain-PHP boundaries, and zero-trust security.
- Zero Trust on Client: Frontend state, browser redirects, query parameters, JS callbacks, email receipts, and customer references are non-authoritative and can never mark an order `paid`. The system fails closed.
- Authoritative Sources: Only cryptographically verified Paystack webhooks (`X-Paystack-Signature` HMAC-SHA512 with `hash_equals()` on raw body bytes) and direct server-to-server TLS verification calls using `PAYSTACK_SECRET_KEY` are authoritative.
- Decoupled Checkout & Payments: Order creation occurs first (guest checkout commits with `payment_status = 'unpaid'`). Payment initialization is a separate customer action against `POST /api/v1/orders/{ref}/payments`, enabling safe retries, multiple payment attempts, and provider failure isolation.
- Simplified Aggregate Payment State vs Attempt Status:
  - Aggregate `orders.payment_status`: `unpaid` $\rightarrow$ `pending` $\rightarrow$ `paid`, and `refunded`. Attempt-level failures do not set order `payment_status = failed`, keeping retry viable on active orders.
  - Individual `payment_attempts.status`: `initialized` $\rightarrow$ `pending` $\rightarrow$ `successful`, `failed`, `abandoned`.
  - Order fulfilment lifecycle (`new` $\rightarrow$ `confirmed` $\rightarrow$ `processing` $\rightarrow$ `ready` $\rightarrow$ `completed` / `cancelled`) remains strictly decoupled from payment status.
- Financial Record Deletion Policy: `ON DELETE RESTRICT` is enforced on foreign keys linking `payment_attempts` and `payment_events` to `orders`. Financial records must never be cascade-deleted.
- Order-Scoped Idempotency: Payment initialization idempotency is scoped strictly to the order (`UNIQUE(order_id, idempotency_key_hash)`), preventing cross-order collision and requiring `existingAttempt.order_id === requestedOrder.id` on replay.
- Webhook Event Idempotency Without Assumed Event IDs: Event deduplication for `charge.success` is enforced via `UNIQUE(provider, event_type, provider_reference)` in `payment_events`, removing artificial assumptions about provider event IDs.
- Persisted Late-Payment Operational State: When payment arrives for a cancelled order, `orders.fulfilment_status` remains `cancelled` (fail-closed), `orders.payment_status` becomes `paid` (financial truth), and `payment_attempts.resolution_status` and `payment_events.processing_status` are set to `requires_action` (`notes = 'payment_received_after_cancellation'`), ensuring the anomaly is permanently queryable in MySQL for merchant review/refund.
- Backend Configuration Minimization: `PAYSTACK_PUBLIC_KEY` is omitted from backend requirements since server-side transaction initialization (`POST /transaction/initialize` via secret key) returns `authorization_url` and `access_code`. `PAYSTACK_SECRET_KEY` is validated with `sk_live_` in production. Detailed specifications in `docs/PAYMENTS.md` and `docs/PAYMENT_SECURITY.md`.

## 2026-08-17 — Phase 6B Secure Paystack payment processing implementation

Status: approved and implemented for Phase 6B.

- Unified Transactional Finalization: Webhook processing (`POST /api/v1/payments/paystack/webhook`) and administrator S2S reconciliation (`POST /api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile`) route through a single, concurrency-safe `PaymentFinalizationService` using `SELECT ... FOR UPDATE` row locks.
- Fail-Closed Late Payment Handling: A successful payment arriving for an already cancelled order marks `orders.payment_status = 'paid'` (financial truth) while keeping `orders.fulfilment_status = 'cancelled'`. The attempt is flagged with `resolution_status = 'requires_action'`, an audit event `payment_received_after_cancellation` is recorded, and an urgent `merchant_late_payment_action` notification is queued. Fulfilment is never automatically reopened.
- Timing-Safe Cryptographic Signature Verification: Webhooks preserve raw request bytes directly from `php://input` prior to JSON decoding, validating `X-Paystack-Signature` against `PAYSTACK_SECRET_KEY` using HMAC-SHA512 and `hash_equals()`.
- Financial Foreign Key Protection: Database migrations `202608170016` and `202608170017` enforce `ON DELETE RESTRICT` on `payment_attempts.order_id` and all `payment_events` foreign keys, preventing accidental physical erasure of financial audit records.
- Order-Scoped Initialization Idempotency: Payment initialization requires `Idempotency-Key` (16–200 ASCII characters), enforce `UNIQUE(order_id, idempotency_key_hash)`, and guarantees exact replay safety while preventing cross-order collision.
- Comprehensive Test Coverage: Unit, integration, migration, webhook security, business logic, and S2S reconciliation tests pass at 100% with PHPStan Level 9 strict typing and 0 Composer audit advisories.

## 2026-08-18 — Phase 7 Swagger API documentation and cross-environment exposure

Status: approved and implemented for Phase 7.

- **Canonical Specification**: `docs/openapi.yaml` (and UTF-8 `docs/openapi.json`) serves as the single authoritative OpenAPI 3.0.3 contract for all implemented Project Sync APIs. All domain-specific documentation files remain subordinate to this canonical definition.
- **Self-Hosted Lightweight Distribution**: Swagger UI v5.18.2 distribution assets are self-hosted in `public/docs/`, satisfying cPanel shared-hosting compatibility without introducing external npm runtime dependencies or heavy PHP framework packages.
- **Cross-Environment Exposure Strategy (Option A)**: Swagger UI (`GET /api/docs`, `GET /api/v1/docs`) and raw specification endpoints (`GET /api/openapi.yaml`, `GET /api/openapi.json`) are permanently available across all environments (local, staging, and production per-merchant installations). This decision was made because:
  1. The API serves as the primary integration interface for headless frontend applications across diverse hosting domains.
  2. The specification exposes strictly public schema definitions and synthetic example values; zero database credentials, SMTP passwords, JWT signing secrets, or Paystack secret keys (`sk_live_`) are ever disclosed.
## 2026-08-18 — Phase 8 Production readiness, deployment hardening, and operational observability

Status: approved and implemented for Phase 8.

- **Configurable OpenAPI Documentation Exposure**: `API_DOCS_ENABLED` (boolean, defaults to `true`) provides granular control over `/api/docs`, `/api/v1/docs`, `/api/openapi.yaml`, and `/api/openapi.json`. When set to `false`, documentation routes return a standard 404 `NOT_FOUND` JSON error envelope.
- **Node-Free OpenAPI JSON Synchronization**: OpenAPI JSON generation is implemented as a standalone PHP CLI script (`scripts/generate-openapi-json.php`) utilizing `symfony/yaml: ^8.1` dev dependency, eliminating Node.js or npm runtime requirements from production builds and CI pipelines.
- **Production Preflight Validation**: Strict fail-fast environment checks (`scripts/production-preflight.php` and `AppFactory::validateProductionEnvironmentConfig()`) enforce `APP_DEBUG=false`, HTTPS `APP_URL`, uncompromised non-placeholder secrets, valid database credentials, and `sk_live_` Paystack secret keys in production.
- **Default Security Headers & Origin Isolation**: Injected default security headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, and configurable `Strict-Transport-Security: max-age=31536000; includeSubDomains`). Hardened CORS middleware permits standard and payment-specific headers (`Idempotency-Key`, `X-Confirmation-Token`) exclusively for configured merchant origins.
- **Automated PII and Credential Redaction**: Monolog `LogRedactionProcessor` scrubs Paystack secret keys (`sk_live_`, `sk_test_`), JWT bearer tokens, refresh cookies, database/SMTP passwords, and sensitive context keys across all log levels and exception stack traces before persistence.
- **Separated Liveness and Readiness Probes**: `GET /api/v1/health` provides minimal public liveness (`status: ok`), while `GET /api/v1/health/ready` validates local MySQL database connectivity without disclosing internal credentials or configuration paths.
- **cPanel Cron Notification Worker with Non-Blocking File Locks**: `scripts/process-notifications.php` processes queued notification jobs (email/webhook) with `flock(LOCK_EX | LOCK_NB)` file locking, preventing overlapping execution across scheduled cPanel cron intervals.
- **Safe Post-Deployment Smoke Testing**: Read-only CLI smoke test (`scripts/smoke-test.php`) validates health, readiness, public storefront profile, categories, products, Swagger policy, and `.env` web-root protection following deployments or updates.
