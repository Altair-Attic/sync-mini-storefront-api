# Project Sync Secret & Credential Rotation Guide

This document defines the procedures for scheduled and emergency rotation of sensitive cryptographic secrets, API keys, database credentials, and SMTP credentials in Project Sync deployments.

---

## 1. Secret Inventory & Impact Matrix

| Secret Key | Purpose | Rotation Impact | Recommended Schedule |
|---|---|---|---|
| `JWT_SECRET` | Signing short-lived administrator Bearer JWTs | Requires re-login or refresh on next API request | 90 Days / On Breach |
| `REFRESH_TOKEN_SECURITY_SECRET` | HMAC hashing for rotating refresh cookie tokens | Invalidates active refresh sessions (admins must re-login) | 90 Days / On Breach |
| `ORDER_SECURITY_SECRET` | Generates customer `X-Confirmation-Token` | Existing unconfirmed order confirmation links expire | 180 Days / On Breach |
| `RATE_LIMIT_SECRET` | Hashes IP addresses for login rate limiting | Resets current rate-limiting counters | 180 Days |
| `NOTIFICATION_SECURITY_SECRET` | Signs outbound merchant webhook payloads | Webhook consumer endpoints must update shared secret | 180 Days / On Breach |
| `PAYSTACK_SECRET_KEY` | Paystack API authentication & webhook signatures | Requires simultaneous Paystack Dashboard update | On Leak / Paystack Cycle |
| `DB_PASSWORD` | MySQL database connection | Application throws 500 until `.env` updated | 90 Days |
| `MAIL_PASSWORD` | SMTP mail server authentication | Outbound email queue fails until `.env` updated | 90 Days |

---

## 2. Standard Secret Rotation Procedures

### Procedure A: Rotating JWT & Refresh Token Secrets

1. Generate new 64-character hexadecimal secrets:
   ```bash
   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
   ```
2. Update `/home/username/project-sync-api/.env`:
   ```ini
   JWT_SECRET=NEW_64_CHAR_HEX_SECRET_1
   REFRESH_TOKEN_SECURITY_SECRET=NEW_64_CHAR_HEX_SECRET_2
   ```
3. *(Optional)* Revoke active sessions in MySQL:
   ```sql
   UPDATE admin_refresh_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE revoked_at IS NULL;
   ```
4. Verify admin login flow via test or browser:
   ```bash
   php scripts/smoke-test.php --url=https://store.merchant.com
   ```

---

### Procedure B: Rotating Paystack Secret Keys (`sk_live_`)

> [!CAUTION]
> Rotating a Paystack live secret key changes the HMAC-SHA512 webhook signature. Updating the key in the Paystack Dashboard without updating `.env` will cause inbound webhooks to fail signature verification (HTTP 401).

1. Generate a new Secret Key in the **Paystack Dashboard** (*Settings* $\rightarrow$ *API Keys & Webhooks*).
2. Copy the new live key (`sk_live_...`).
3. Immediately update `.env`:
   ```ini
   PAYSTACK_SECRET_KEY=sk_live_NEW_PAYSTACK_KEY_VALUE
   ```
4. Confirm webhook URL is set to `https://store.merchant.com/api/v1/payments/paystack/webhook` in Paystack Dashboard.
5. Send a test webhook from Paystack Dashboard or trigger a test initialization to confirm HTTP 200 response.

---

### Procedure C: Rotating Database Credentials

1. In cPanel, navigate to **MySQL® Databases** $\rightarrow$ **Current Users**.
2. Change the password for the merchant database user (`username_sync_usr`).
3. Update `.env`:
   ```ini
   DB_PASSWORD=YOUR_NEW_SECURE_PASSWORD
   ```
4. Test database connectivity immediately:
   ```bash
   php scripts/production-preflight.php
   ```

---

### Procedure D: Rotating Order & Notification Security Secrets

1. Generate high-entropy replacement secrets:
   ```bash
   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
   ```
2. Update `.env`:
   ```ini
   ORDER_SECURITY_SECRET=NEW_ORDER_SECRET_HEX
   NOTIFICATION_SECURITY_SECRET=NEW_NOTIF_SECRET_HEX
   ```
3. If external merchant webhooks are configured, notify the merchant system integrator to update their payload signature verification secret.

---

## 3. Emergency Leak Incident Response

If a production secret is accidentally committed to source control, leaked in logs, or exposed in communication channels:

1. **Containment**: Treat the leaked key as immediately compromised.
2. **Immediate Rotation**: Execute the relevant procedure above within 15 minutes.
3. **Audit Log Inspection**: Review MySQL `login_attempts`, `payment_attempts`, `payment_events`, and `storage/logs/` for unauthorized operations during the exposure window.
4. **Invalidate Sessions**: Truncate or revoke `admin_refresh_tokens` and active tokens.
