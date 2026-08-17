# Architecture Decisions

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
