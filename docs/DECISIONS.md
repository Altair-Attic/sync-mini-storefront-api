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
