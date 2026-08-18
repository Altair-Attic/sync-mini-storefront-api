# Project Sync Production Readiness & Go-Live Checklist

Use this checklist to verify every single-tenant merchant installation before opening storefront and checkout traffic to the public.

---

## 1. Environment & Server Configuration

- [ ] **PHP Version**: Server runs PHP `8.3.0` or higher (`php -v`).
- [ ] **Required PHP Extensions**: `pdo_mysql`, `curl`, `mbstring`, `json`, `ctype`, `fileinfo`, and `openssl` are installed and enabled.
- [ ] **Environment Setting**: `APP_ENV=production` in `.env`.
- [ ] **Debug Mode Disabled**: `APP_DEBUG=false` in `.env`.
- [ ] **Secure Base URL**: `APP_URL` is configured with `https://` (`APP_URL=https://store.domain.com`).
- [ ] **HTTPS / TLS Certificate**: Valid SSL/TLS certificate is active and auto-renewal is enabled.
- [ ] **HSTS Headers**: `HSTS_ENABLED=true` and `HSTS_MAX_AGE=31536000` configured for strict transport security.

---

## 2. Security, Isolation & Cryptographic Secrets

- [ ] **Document Root Isolation**: Web document root points strictly to `backend/public/`, never repository root.
- [ ] **Apache Access Restrictions**: `public/.htaccess` verified active; direct requests to `.env`, `composer.json`, or `.git` return HTTP 404/403.
- [ ] **Unique High-Entropy Secrets**: All secrets generated with `random_bytes(32)` (no default placeholders):
  - [ ] `JWT_SECRET` (64 hex characters)
  - [ ] `REFRESH_TOKEN_SECURITY_SECRET` (64 hex characters)
  - [ ] `ORDER_SECURITY_SECRET` (64 hex characters)
  - [ ] `RATE_LIMIT_SECRET` (64 hex characters)
  - [ ] `NOTIFICATION_SECURITY_SECRET` (64 hex characters)
- [ ] **Admin Cookie Security**: `REFRESH_COOKIE_SECURE=true`, `REFRESH_COOKIE_SAME_SITE=Strict`, `REFRESH_COOKIE_PATH=/api/v1/admin`.
- [ ] **CORS Origins**: `CORS_ALLOWED_ORIGINS` explicitly restricted to authorized storefront domains.
- [ ] **Monolog Log Redaction**: Log redaction processor active; verified no secrets or tokens appear in `storage/logs/`.

---

## 3. Database & Migrations

- [ ] **Dedicated MySQL User**: User has unique high-entropy password and access restricted to merchant database.
- [ ] **InnoDB & UTF8mb4**: Database collation is `utf8mb4_unicode_ci`.
- [ ] **Migrations Executed**: All 17 schema migrations applied via `php scripts/migrate.php`.
- [ ] **Zero Pending Migrations**: Confirmed with `MigrationRunner::pending() === []`.
- [ ] **Seed / Initial Profile**: Business profile initialized with merchant name, currency (`NGN`), and delivery options.

---

## 4. File Storage & Permissions

- [ ] **Storage Tree Created**: Directories `storage/logs`, `storage/uploads/products`, `storage/cache`, `storage/locks` exist.
- [ ] **Permissions**: Directory permissions set to `0755` (`chmod -R 0755 storage`).
- [ ] **Config File Protection**: `.env` permissions set to `0600` (`chmod 0600 .env`).

---

## 5. Paystack Payment Processing

- [ ] **Live Secret Key**: `PAYSTACK_SECRET_KEY` starts with `sk_live_` (no test keys in production).
- [ ] **Webhook Endpoint Configured**: Paystack Dashboard webhook URL set to `https://store.domain.com/api/v1/payments/paystack/webhook`.
- [ ] **Live Webhook Test**: Successful test webhook ping sent from Paystack Dashboard.
- [ ] **S2S Reconciliation Verified**: Admin reconciliation endpoint tested for out-of-band payment settlement.

---

## 6. Email Notifications & cPanel Cron

- [ ] **SMTP Credentials**: Valid SMTP host, port, username, password, and sender address configured in `.env`.
- [ ] **cPanel Cron Scheduled**: 1-minute cron task configured:
  ```cron
  * * * * * /usr/local/bin/php /home/username/project-sync-api/scripts/process-notifications.php >/dev/null 2>&1
  ```
- [ ] **File Locking Verified**: Non-blocking lock on `storage/locks/notification_worker.lock` verified to prevent overlapping cron runs.

---

## 7. OpenAPI Documentation Policy

- [ ] **Swagger Policy Chosen**: `API_DOCS_ENABLED=true` (or `false` if merchant requires private API documentation).
- [ ] **OpenAPI JSON Synchronized**: Generated via `php scripts/generate-openapi-json.php`.

---

## 8. Final Go-Live Verification

- [ ] **Run Production Preflight**: `php scripts/production-preflight.php` passes with exit code `0`.
- [ ] **Run Smoke Test**: `php scripts/smoke-test.php --url=https://store.domain.com` passes all 7 checks.
- [ ] **Automated Backup Configured**: Nightly `mysqldump` cron scheduled and tested.
