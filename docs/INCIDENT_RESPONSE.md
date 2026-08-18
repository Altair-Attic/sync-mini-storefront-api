# Project Sync Incident Response Playbooks

This document provides actionable runbooks and operational procedures for diagnosing, containing, and resolving production incidents in Project Sync deployments.

---

## 1. Severity Classification Matrix

| Level | Definition | Response SLA | Examples |
|---|---|---|---|
| **SEV-1 (Critical)** | Core storefront ordering or admin unusable; secret compromised | < 15 minutes | Database connection down, Paystack secret leaked, 500 errors on checkout |
| **SEV-2 (High)** | Major degraded functionality; payment webhooks failing | < 1 hour | Webhook signature rejection, late payment `requires_action` anomalies, email delivery blocked |
| **SEV-3 (Medium)** | Non-critical component degradation | < 4 hours | Product image upload failure, cron notification queue delayed |

---

## 2. Incident Playbooks

### Playbook 1: Late Payment Arrives on Cancelled Order (`requires_action`)

**Symptom**: `orders.fulfilment_status = 'cancelled'` while `orders.payment_status = 'paid'`. Payment attempt is flagged `resolution_status = 'requires_action'` and note `payment_received_after_cancellation`.

**Investigation**:
1. Inspect the order in MySQL:
   ```sql
   SELECT id, order_reference, fulfilment_status, payment_status, total_amount_kobo FROM orders WHERE id = '<ORDER_ID>';
   SELECT id, reference, status, resolution_status, amount_kobo FROM payment_attempts WHERE order_id = '<ORDER_ID>';
   SELECT event_type, processing_status, payload FROM payment_events WHERE order_id = '<ORDER_ID>';
   ```
2. Check customer details and inventory availability.

**Resolution Options**:
- **Option A (Merchant Fulfils Order)**:
  If stock is available and the merchant agrees to fulfill, merchant administrator updates the order to `confirmed` or `processing` and notes customer.
- **Option B (Merchant Refunds Customer via Paystack)**:
  If stock is unavailable, merchant initiates a refund from the Paystack Dashboard, updates `orders.payment_status = 'refunded'`, and logs the refund reference in internal notes.

---

### Playbook 2: Inbound Paystack Webhook Failures (HTTP 401 / 400)

**Symptom**: Customers complete payments on Paystack, but orders remain `unpaid` or `pending`. Paystack Dashboard webhook logs show HTTP 401 `INVALID_SIGNATURE` or HTTP 500 errors.

**Diagnosis**:
1. Check `storage/logs/` for webhook errors:
   ```bash
   grep -E "webhook|paystack" storage/logs/app.log | tail -n 30
   ```
2. Verify `PAYSTACK_SECRET_KEY` in `.env` matches the Secret Key in Paystack Dashboard (*Settings* $\rightarrow$ *API Keys*).
3. Ensure the webhook URL in Paystack Dashboard is pointing to:
   ```
   https://store.merchant.com/api/v1/payments/paystack/webhook
   ```
4. Verify raw request bytes are unaltered by Apache/cPanel proxy rules.

**Recovery**:
- Trigger administrator S2S reconciliation for affected orders:
  ```http
  POST /api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile
  Authorization: Bearer <ADMIN_JWT>
  ```
- Paystack transaction status is verified directly server-to-server and marks the order `paid` if successful.

---

### Playbook 3: Service Dependency Outage (HTTP 503 on `/health/ready`)

**Symptom**: `GET /api/v1/health/ready` returns HTTP 503 `SERVICE_UNAVAILABLE`.

**Diagnosis**:
1. Check MySQL service status in cPanel or via CLI:
   ```bash
   php scripts/production-preflight.php
   ```
2. Check MySQL error log in cPanel for connection pool exhaustion, max connections, or table lock timeouts.
3. Verify database disk quota and file permissions on shared hosting.

**Recovery**:
- If database server crashed, restart MySQL via cPanel or contact hosting provider.
- If schema or tables were damaged, follow [docs/BACKUP_AND_RECOVERY.md](file:///c:/Users/Davytun/Desktop/Altair_Attic/project-sync/api/docs/BACKUP_AND_RECOVERY.md) to restore from the latest verified snapshot.

---

### Playbook 4: Notification Queue Stall / SMTP Failures

**Symptom**: Customers or merchants report not receiving confirmation emails or WhatsApp webhook triggers.

**Diagnosis**:
1. Inspect `notification_jobs` in MySQL:
   ```sql
   SELECT id, job_type, channel, status, attempts, error_message, created_at, scheduled_for 
   FROM notification_jobs 
   WHERE status IN ('pending', 'failed') 
   ORDER BY id DESC LIMIT 20;
   ```
2. Check whether the cron is executing:
   ```bash
   php scripts/process-notifications.php
   ```
3. If lock file is stale: check `storage/locks/notification_worker.lock`.

**Recovery**:
- If SMTP authentication failed, verify `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` in `.env`.
- To retry failed jobs after resolving SMTP credentials:
  ```sql
  UPDATE notification_jobs SET status = 'pending', attempts = 0 WHERE status = 'failed';
  ```
- Run `php scripts/process-notifications.php` to immediately process pending jobs.

---

### Playbook 5: Production Credential Leak

**Symptom**: API keys, database credentials, or JWT secrets accidentally exposed.

**Immediate Actions**:
1. Follow [docs/SECRET_ROTATION.md](file:///c:/Users/Davytun/Desktop/Altair_Attic/project-sync/api/docs/SECRET_ROTATION.md) immediately.
2. Invalidate all active administrator sessions:
   ```sql
   UPDATE admin_refresh_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE revoked_at IS NULL;
   ```
3. Inspect `login_attempts` and audit logs for suspicious activity.
