# Order Notifications and WhatsApp Handoff

Phase 3B runs only after the order transaction commits. Email or handoff failures never roll back an order, alter order statuses, or change a successful checkout into an error.

## Business settings

Admin mutations to business notification settings require `Authorization: Bearer <access-token>` (short-lived administrator JWT) via `PUT /api/v1/admin/profile`.

Authenticated administrators configure:

- `order_notification_email`: optional merchant recipient; `support_email` is the fallback.
- `merchant_email_notifications_enabled`: defaults to `true`.
- `customer_email_notifications_enabled`: defaults to `false`.
- `whatsapp_handoff_enabled`: defaults to `true`.

Customer email requires both the enabled setting and a valid email captured on the order. Private notification settings (`order_notification_email`, `merchant_email_notifications_enabled`, `customer_email_notifications_enabled`, `whatsapp_handoff_enabled`) are excluded from public `GET /api/v1/store`.

## Idempotent replay behavior

When checkout receives a repeated request with the same `Idempotency-Key` and normalized body:
- The server recognizes the idempotent replay and returns HTTP `200` with `meta.idempotent_replay: true`.
- Replays never insert new notification jobs.
- Replays never trigger immediate email delivery or resend completed notifications.
- The response returns the current safe status of notifications (`sent`, `queued`, or `skipped`) as read from the persistent database state.

## SMTP configuration

PHPMailer provides SMTP transport. Configure `MAIL_ENABLED`, host, port, optional username/password, encryption (`tls`, `ssl`/`smtps`, or `none`), sender address/name, and connection timeout. Use a unique long `NOTIFICATION_SECURITY_SECRET` per merchant deployment. Credentials belong only in `.env` and must never be logged.

When mail is disabled, jobs remain queued and the CLI exits without claiming them. Plain-text messages are rebuilt from the order and immutable item snapshots; neither bodies nor recipient addresses are stored in jobs.

## Job lifecycle

Jobs are unique by order, channel, and recipient type (`uq_notification_jobs_recipient` on `order_id`, `channel`, `recipient_type`):

```text
pending -> processing -> sent
                     -> pending (retryable failure)
                     -> failed  (attempts exhausted)
```

Claims use a conditional atomic MySQL update. SMTP runs only after the claim and without an open database transaction. Stale `processing` jobs older than `NOTIFICATION_PROCESSING_TIMEOUT_SECONDS` return to `pending`; exhausted stale jobs become `failed`.

Delivery is at least once. A process can crash after SMTP accepts a message but before `sent` is recorded, so a later retry may duplicate that email. Atomic claims and stale timeouts minimize this unavoidable window; the system does not claim exactly-once SMTP delivery.

## Retry policy

Defaults are five attempts and a 300-second base delay. After failed attempt `n`, the next delay is:

```text
min(base_seconds * 3^(n - 1), 7 days)
```

This produces: immediate attempt, then waits of 5, 15, 45, and 135 minutes before attempts 2–5.

## cPanel cron and safe troubleshooting

The processor is a bounded CLI command and needs no daemon, Redis, Supervisor, Docker, or persistent PHP process:

```text
*/5 * * * * /usr/local/bin/php /absolute/project/path/bin/process-notifications.php --limit=20
```

The PHP path depends on the cPanel host. `--limit` must be positive and cannot exceed `NOTIFICATION_BATCH_LIMIT`. Exit codes are:
- `0`: clean execution, all claimed jobs succeeded or none were due.
- `2`: invalid CLI arguments.
- `3`: one or more deliveries failed and were scheduled for retry or marked failed safely.
- `1`: unhandled bootstrap or database failure.

Safe troubleshooting:
- Output and logs contain only aggregate counts and stable error codes (such as `NOTIFICATION_DELIVERY_FAILED`, `NOTIFICATION_RECIPIENT_UNAVAILABLE`).
- Passwords, SMTP secrets, recipient email addresses, customer names, delivery notes, message bodies, provider stack traces, and complete WhatsApp URLs are strictly excluded from logs and CLI output.

## WhatsApp handoff

The backend returns a `https://wa.me/{business-number}?text={encoded-message}` URL using the business WhatsApp number and immutable order information. It never calls an API or sends a message over the network. Disabled handoff or an invalid/missing business number returns `null`. The complete URL must not be logged because it contains customer/order details. WhatsApp Business API integration remains deferred.
