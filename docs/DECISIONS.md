# Architecture Decisions

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
