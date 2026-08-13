# Project Sync - Product Architecture and Delivery Plan

> Status: Engineering draft v0.1
>
> Updated: 13 August 2026
>
> Source: Project Sync PRD
>
> Intended location: Project repository root

## 1. Purpose

This file is the engineering source of truth for Project Sync. It defines how the product should be built, the boundaries between the storefront and the central Sync platform, the minimum release scope, the backend structure, API standards, data rules, testing requirements and phased delivery plan.

Read this file before planning or implementing any feature.

## 2. Mandatory AI and developer workflow

Before changing code, the developer or coding agent must:

1. Read this entire file and the Project Sync PRD.
2. Inspect the existing repository structure and current implementation.
3. State which requirement and delivery phase the task belongs to.
4. Identify missing product decisions that affect the implementation.
5. Ask focused questions when a missing answer would materially change the database, API or customer flow.
6. Present a small implementation plan before editing code.
7. Preserve existing working behaviour and unrelated user changes.
8. Implement one vertical slice at a time.
9. Add or update automated tests with every functional change.
10. Run the relevant tests and report the result before declaring the task complete.

Do not invent missing business rules. Record unresolved decisions in the decision log.

## 3. Product overview

Sync is a mini-SaaS e-commerce storefront builder for small merchants. It gives merchants an independent online storefront instead of making WhatsApp or Instagram their only business presence.

Each merchant receives:

- A branded storefront on their own domain.
- A dedicated cPanel deployment folder.
- A dedicated MySQL database.
- A customer-facing React storefront.
- A React merchant administration interface.
- A structured plain-PHP API.
- Isolated configuration, credentials, uploads and business data.

The agency manages client lifecycle, renewals, operational metrics and deployed versions through the central Sync application at `sync.business`.

## 4. Product outcomes

The product must achieve the following outcomes:

- A new merchant storefront can be launched within 24 hours after payment and receipt of complete onboarding information.
- Customers can browse and submit an order without creating an account.
- An order is captured on the website before any WhatsApp handoff occurs.
- Merchants can manage products, orders and their basic business profile without technical assistance.
- Every merchant's customer and business data remains isolated.
- Storefronts remain fast on typical Nigerian mobile connections.
- The team can deploy fixes without manually modifying client-specific application code.
- Version drift, backups and rollback are controlled before multi-store updates are automated.

## 5. Engineering principles

### 5.1 Simple by default

Provide one obvious path for common merchant and customer tasks. Use familiar language, clear validation messages and complete loading, empty, success and failure states.

### 5.2 Reliable order capture

The database commit is the success boundary for checkout. Email delivery and WhatsApp handoff must never determine whether a valid order is saved.

### 5.3 Configuration over customization

Business name, logo, colours, template, domain and WhatsApp number must come from configuration or database records. Do not add client-specific conditional code to the shared boilerplate.

### 5.4 Secure isolation

A merchant installation must never receive another merchant's database credentials, secret, uploads, logs or customer data.

### 5.5 Repeatable operations

Provisioning, migrations, releases, backups and rollback must follow documented and testable procedures.

### 5.6 Measurable quality

Performance, reliability and security requirements must have objective checks. "It works on my machine" is not an acceptance criterion.

## 6. Release scope

### 6.1 MVP scope

#### Customer storefront

- Store profile and branding.
- Product catalogue.
- Product detail page.
- Cart: add, remove and update quantity.
- Guest checkout.
- Order confirmation.
- WhatsApp handoff after successful order capture.

#### Merchant administration

- Secure login and logout.
- Product listing, creation, update and deactivation.
- Product-image upload.
- Order listing and order details.
- Controlled fulfilment-status updates.
- Business name, logo, template and WhatsApp settings.

#### Backend and operations foundation

- Configuration loading.
- Database migrations.
- Request validation.
- Authentication and authorization.
- Structured logging and request IDs.
- Email notification with retry support.
- Backups and recovery procedure.
- Application and schema version tracking.
- Repeatable manual deployment checklist.

### 6.2 Deferred from MVP

- Live Paystack checkout.
- Automated cPanel, domain or database provisioning.
- Remote update installation.
- Merchant-facing analytics.
- Customer accounts.
- Promotions and discount codes.
- Inventory reservation.
- Advanced delivery logistics.

## 7. System architecture

Project Sync uses a single-tenant-per-installation deployment model.

```text
Central Sync control plane
  |-- Client registry
  |-- Renewals and lifecycle status
  |-- Operational metrics
  |-- Application/schema version inventory
  |
  |-- Merchant A deployment --> MySQL database A
  |-- Merchant B deployment --> MySQL database B
  `-- Merchant N deployment --> MySQL database N
```

### 7.1 Per-merchant deployment

Every merchant deployment contains:

- Compiled React storefront and merchant admin.
- Plain-PHP API.
- Merchant-specific `.env` file.
- Merchant-specific uploads.
- Merchant-specific logs.
- Application version metadata.
- Connection to exactly one merchant database.

### 7.2 Central Sync control plane

The central system owns:

- Client registry.
- Business name and domain reference.
- Active or disabled lifecycle status.
- Renewal date.
- Per-site reporting credential or credential fingerprint.
- Latest reported application and schema versions.
- Aggregated operational metrics.
- Deployment and update history.

The central database must not become the operational order database for individual storefronts.

### 7.3 External dependencies

- SMTP or approved email service for merchant notifications.
- WhatsApp deep links for manual customer follow-up.
- Paystack in a later phase.

## 8. Repository and application structure

Use a structured PHP application. Do not place validation, database queries and business logic directly inside route files.

```text
project-sync/
|-- app/
|   |-- Controllers/
|   |-- Exceptions/
|   |-- Infrastructure/
|   |-- Middleware/
|   |-- Repositories/
|   |-- Services/
|   `-- Validators/
|-- config/
|-- database/
|   |-- migrations/
|   `-- seeders/
|-- public/
|   |-- assets/
|   |-- uploads/
|   `-- index.php
|-- routes/
|-- storage/
|   `-- logs/
|-- tests/
|   |-- Integration/
|   |-- Unit/
|   `-- Contract/
|-- .env.example
|-- composer.json
|-- PROJECT_ARCHITECTURE.md
`-- version.json
```

The domain document root must point to `public/`. The `.env`, logs, migrations and application source must not be publicly accessible.

## 9. PHP application layers

Use this dependency direction:

```text
Route -> Middleware -> Controller -> Validator -> Service -> Repository -> Database
```

### Routes

- Map HTTP methods and paths.
- Do not contain business rules or SQL.

### Middleware

- Authentication.
- Authorization.
- CSRF protection.
- Rate limiting.
- Request IDs.
- Site-status enforcement.

### Controllers

- Read validated HTTP input.
- Call one or more application services.
- Return the standard API response.
- Remain thin.

### Validators

- Own reusable validation rules.
- Return field-level errors.
- Never silently correct unsafe input.

### Services

- Own product and order business rules.
- Control transactions and workflow boundaries.
- Coordinate repositories and infrastructure adapters.

### Repositories

- Own database access.
- Use PDO prepared statements only.
- Return well-defined domain data.
- Do not contain HTTP response logic.

### Infrastructure

- Database connection.
- Email transport.
- File storage and image processing.
- Logging.
- Reporting client.
- Configuration adapters.

## 10. Dependency strategy

Plain PHP does not mean writing every infrastructure feature manually. Use small, established Composer packages where they reduce security and maintenance risk.

The team must approve the exact packages for:

- Routing.
- Environment-variable loading.
- Validation.
- Logging.
- Email delivery.
- Testing.

Rules:

- Pin compatible versions in `composer.lock`.
- Commit the lock file.
- Avoid abandoned or unnecessary packages.
- Do not introduce a package for a trivial helper.
- Review dependency security alerts before releases.

## 11. Configuration and deployment contract

### 11.1 Environment configuration

`.env` contains secrets and infrastructure settings only, including:

- Environment name.
- Application URL.
- Database host, name, username and password.
- Email transport credentials.
- Central Sync URL.
- Unique site-reporting secret.
- Logging level.

Never expose backend secrets through React or commit real credentials.

### 11.2 Database configuration

Store editable merchant configuration in `business_profiles`, including:

- Business name.
- Logo URL.
- Domain.
- WhatsApp number.
- Template ID.
- Currency.
- Timezone.
- Supported storefront configuration.

### 11.3 Files protected during updates

Updates must never overwrite:

- `.env`.
- `public/uploads/`.
- `storage/logs/`.
- Merchant database data.
- Runtime-generated local state.

### 11.4 Version metadata

Every deployment must contain a `version.json` file similar to:

```json
{
  "application_version": "0.1.0",
  "schema_version": "202608130001",
  "minimum_compatible_schema": "202608130001",
  "built_at": "2026-08-13T00:00:00Z"
}
```

## 12. Data architecture

### 12.1 Global data rules

- Store money as integer kobo, never floating-point values.
- Store an explicit ISO currency code such as `NGN`.
- Store timestamps in UTC.
- Use opaque public identifiers or references in public URLs.
- Add foreign keys where supported and appropriate.
- Add indexes for lookup and filter fields.
- Never delete historical information required to understand an order.
- Use database transactions for multi-table business operations.

### 12.2 Per-client database tables

#### `merchant_users`

```text
id
name
email unique
password_hash
status
last_login_at nullable
created_at
updated_at
```

#### `business_profiles`

```text
id
business_name
logo_url nullable
domain
whatsapp_number
template_id
currency default NGN
timezone default Africa/Lagos
created_at
updated_at
```

#### `products`

```text
id
public_id unique
slug unique
title
description nullable
price_kobo unsigned
image_url
is_active boolean
created_at
updated_at
```

Do not hard-delete a product referenced by an order. Mark it inactive.

#### `orders`

```text
id
reference unique
idempotency_key unique
customer_name
delivery_address
state
phone_number
subtotal_kobo
delivery_fee_kobo
total_kobo
currency
payment_method
payment_status
fulfilment_status
created_at
updated_at
```

#### `order_items`

```text
id
order_id foreign key
product_id nullable foreign key
product_title
unit_price_kobo
quantity
line_total_kobo
created_at
```

`product_title` and `unit_price_kobo` are immutable snapshots. A later product edit must not change an existing order.

#### `order_status_history`

```text
id
order_id foreign key
previous_status nullable
new_status
changed_by nullable
created_at
```

#### `notification_jobs`

```text
id
order_id foreign key
channel
status
attempts
available_at
last_error nullable
created_at
updated_at
```

#### `schema_migrations`

```text
migration unique
batch
executed_at
```

#### `reporting_state`

```text
id
last_success_at nullable
next_attempt_at nullable
attempts
payload_hash nullable
last_error nullable
updated_at
```

### 12.3 Central database tables

At minimum:

- `clients`.
- `client_credentials` or securely managed credential records.
- `client_metrics`.
- `client_versions`.
- `renewals`.
- `deployment_history`.
- `update_packages` in the later update phase.
- `central_admin_users`.

## 13. Order lifecycle

Keep payment and fulfilment states separate.

### Payment status

- `unpaid`: v1 default.
- `pending`: Paystack initialization started.
- `paid`: verified server-side.
- `failed`: payment failed or expired.
- `refunded`: verified refund completed.

### Fulfilment status

- `new`.
- `confirmed`.
- `processing`.
- `completed`.
- `cancelled`.

Allowed fulfilment transitions must be approved by product management before implementation. Every transition must be validated and recorded in `order_status_history`.

## 14. API standards

### 14.1 Versioning

All product endpoints begin with `/api/v1`.

### 14.2 Successful response

```json
{
  "success": true,
  "data": {},
  "meta": {
    "request_id": "req_..."
  }
}
```

### 14.3 Error response

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the highlighted fields.",
    "fields": {
      "phone_number": ["Enter a valid phone number."]
    }
  },
  "meta": {
    "request_id": "req_..."
  }
}
```

### 14.4 API rules

- Use correct HTTP status codes.
- Use stable machine-readable error codes.
- Never return internal exception traces in production.
- Paginate collection endpoints.
- Define default and maximum page sizes.
- Validate request content type.
- Do not return password hashes, secrets or unnecessary customer information.
- Document all requests and responses in an OpenAPI specification.

## 15. Preliminary endpoints

### Public storefront

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/v1/store` | Store profile and template configuration |
| `GET` | `/api/v1/products` | Active product catalogue |
| `GET` | `/api/v1/products/{slug}` | Product details |
| `POST` | `/api/v1/orders` | Validate and capture a guest order |
| `GET` | `/api/v1/orders/{reference}/confirmation` | Confirmation-safe summary |

The confirmation endpoint must use an unguessable access token or another approved mechanism. Do not expose customer order data through predictable order references.

### Merchant administration

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/v1/admin/login` | Create merchant session |
| `POST` | `/api/v1/admin/logout` | Terminate merchant session |
| `GET` | `/api/v1/admin/me` | Current merchant user |
| `GET` | `/api/v1/admin/products` | Paginated product list |
| `POST` | `/api/v1/admin/products` | Create product |
| `GET` | `/api/v1/admin/products/{id}` | Product details |
| `PUT` | `/api/v1/admin/products/{id}` | Update product |
| `DELETE` | `/api/v1/admin/products/{id}` | Deactivate product |
| `GET` | `/api/v1/admin/orders` | Paginated and filterable orders |
| `GET` | `/api/v1/admin/orders/{id}` | Order details |
| `PATCH` | `/api/v1/admin/orders/{id}/status` | Change fulfilment status |
| `GET` | `/api/v1/admin/profile` | Business profile |
| `PUT` | `/api/v1/admin/profile` | Update business profile |

### Central integration

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `sync.business/api/v1/reports` | Receive signed site metrics |
| `GET` | `/internal/v1/site-status` | Retrieve signed lifecycle status if pull support is selected |

## 16. Checkout workflow

The checkout implementation must use this sequence:

1. Accept customer data, requested product identifiers, quantities and an idempotency key.
2. Validate the customer fields and cart structure.
3. Load every product and current price from MySQL.
4. Reject inactive, missing or invalid products.
5. Calculate each line total, subtotal, delivery fee and total on the server.
6. Begin a database transaction.
7. Insert the order.
8. Insert immutable order-item snapshots.
9. Commit the transaction.
10. Create or execute the merchant email notification attempt.
11. Generate the order confirmation and WhatsApp handoff URL.
12. Return the order reference and safe confirmation data.

Never trust prices, totals, availability or payment status submitted by React.

### Checkout reliability rules

- Enforce uniqueness on the idempotency key.
- A repeated request with the same key returns the original order outcome.
- A failure before commit rolls back the order and all items.
- A failure after commit must not erase or mark the order as failed.
- Email failure creates a retryable notification record.
- Log a safe request ID for troubleshooting.

## 17. Merchant authentication

Preferred v1 approach: same-origin server-side sessions.

Requirements:

- Use `password_hash()` and `password_verify()`.
- Regenerate the session ID after login.
- Use `Secure`, `HttpOnly` and appropriate `SameSite` cookie attributes.
- Require CSRF protection for state-changing requests.
- Rate-limit login attempts.
- Use generic invalid-login messages.
- Terminate sessions securely during logout.
- Protect every merchant endpoint with middleware.

Do not use a JWT solely because the frontend uses React. Reconsider only if deployment topology makes same-origin sessions impossible.

## 18. Product-image handling

- Validate MIME type using server-side file inspection.
- Allow only approved image types.
- Enforce upload-size limits.
- Generate collision-resistant filenames.
- Prevent executable files inside upload directories.
- Resize and compress images for storefront display.
- Preserve aspect ratio.
- Remove an old image only after the database update succeeds.
- Do not delete an image that is still referenced.
- Return a stable public URL or media identifier.

## 19. Central reporting

Each storefront pushes a small metrics payload to the central system on a staggered schedule.

Possible payload fields:

```json
{
  "client_id": "client_...",
  "site_id": "site_...",
  "application_version": "0.1.0",
  "schema_version": "202608130001",
  "captured_at": "2026-08-13T10:00:00Z",
  "metrics": {
    "orders_since_last_report": 4,
    "products_active": 12,
    "last_order_at": "2026-08-13T09:55:00Z"
  }
}
```

Security requirements:

- Unique secret per client site.
- Sign the request body using HMAC or an approved equivalent.
- Include a timestamp and nonce.
- Reject expired or replayed requests.
- Rate-limit the reporting endpoint.
- Support secret revocation and replacement.
- A compromised site secret must not authorize access to another client's data.

Do not send customer names, phone numbers or delivery addresses as analytics metrics.

## 20. Store lifecycle status

Do not make every storefront request depend on a live request to `sync.business`.

Recommended approach:

1. The central control plane owns the official active or disabled status.
2. Each storefront periodically retrieves or receives a signed status value.
3. The storefront stores the last valid status locally with an expiry time.
4. Customer requests use the local cached status.
5. A temporary central outage uses a product-approved grace period.
6. Invalid signatures or expired status outside the grace period generate an operational alert.

The exact grace-period behaviour requires PM and operations approval.

## 21. Security baseline

- PDO prepared statements only.
- Output encoding against XSS.
- CSRF protection for cookie-authenticated state changes.
- Authentication and authorization middleware.
- Login and checkout rate limits.
- Strict server-side input validation.
- Secure upload allowlist and size limits.
- Secrets outside source control and outside the public document root.
- Unique credentials for every merchant database.
- Production error pages without stack traces.
- Logs must redact passwords, cookies, authorization values and secrets.
- HTTPS required for production.
- Directory listing disabled.
- Security headers configured at application or server level.
- Dependencies reviewed and updated through controlled releases.
- Database and upload backups access-controlled and restore-tested.

## 22. Performance requirements

- The primary catalogue should become usable within approximately 2.5 seconds on a representative mid-range phone and mobile connection.
- Routine API endpoints target p95 below 500 ms, excluding external email delivery.
- Paginate product and order collections.
- Return only fields required by the current screen.
- Index product slugs, public IDs, order references, statuses and `created_at`.
- Generate appropriately sized product images.
- Cache versioned React assets for a long duration.
- Use short, safe caching for public store configuration and product data.
- Never publicly cache merchant administration or customer-specific responses.
- Enable PHP OPcache when supported by the cPanel environment.
- Verify memory, process, cron and storage limits using the real hosting account.

## 23. Logging and observability

Every API request must receive a request ID.

Structured logs should include:

- Timestamp.
- Severity.
- Request ID.
- Environment.
- Route.
- HTTP method.
- Site or client identifier.
- Safe error code.
- Execution duration where useful.

Track at minimum:

- Checkout failures.
- Duplicate checkout attempts.
- Email failures and retry exhaustion.
- Authentication rate-limit events.
- Reporting failures.
- Migration failures.
- Deployment failures.
- Application and schema version drift.

## 24. Testing strategy

### Unit tests

- Validators.
- Money and total calculations.
- Order-reference generation.
- Order status transitions.
- Reporting signatures.
- WhatsApp URL construction.

### Integration tests

- Repositories and prepared queries.
- Database migrations.
- Merchant authentication.
- Checkout transaction and rollback.
- Product deactivation with historical orders.
- Notification-job creation.

### Contract tests

- Response envelope.
- HTTP status codes.
- Machine-readable error codes.
- Pagination metadata.
- Frontend-required response fields.

### End-to-end tests

- Browse products -> add to cart -> checkout -> confirmation.
- Merchant login -> create product -> edit -> deactivate.
- Merchant order list -> details -> valid status transition.
- Invalid and expired merchant session behaviour.

### Mandatory checkout regression cases

- A valid cart creates exactly one order.
- Order items contain correct immutable snapshots.
- A manipulated frontend price cannot change the total.
- An inactive or missing product is rejected safely.
- Reusing an idempotency key does not create a second order.
- A partial database failure rolls back everything.
- Email failure does not fail a saved order.
- An unauthenticated user cannot access merchant resources.

Every production bug fix must include a regression test when practical.

## 25. Definition of done

A feature is complete only when all applicable conditions are satisfied:

- Product behaviour and acceptance criteria are approved.
- API request, response and error contracts are documented.
- Validation, authentication and authorization are implemented.
- Database migrations are tested against existing data.
- Unit, integration and contract tests pass.
- Frontend loading, empty, success and failure states are complete.
- Mobile and desktop behaviour is verified.
- Logs provide safe diagnostic context.
- Performance impact is checked.
- Staging smoke tests pass.
- Deployment and rollback notes are updated.
- PM or the designated product owner accepts the feature on staging.

## 26. Release and update process

Do not implement remote ZIP updates until all controls below work for a manual release.

1. Build immutable backend and frontend artifacts.
2. Attach application version, compatibility metadata and checksums.
3. Run automated tests.
4. Deploy to staging with production-like data.
5. Back up the target database and protected files.
6. Confirm current application and schema compatibility.
7. Deploy code using a temporary directory or atomic swap where hosting permits.
8. Run ordered migrations.
9. Run health, catalogue, authentication and safe checkout smoke tests.
10. Record the operator, client, versions, time and result.
11. Roll back code and restore a compatible backup if a required gate fails.

## 27. Delivery plan

Estimates assume one backend engineer, one frontend engineer, product management and part-time QA/operations support. Re-estimate after resolving the open decisions.

### Phase 0 - Architecture and contracts

Duration: 3-5 working days.

Deliverables:

- Approved architecture.
- Product decision log.
- ERD.
- Migration convention.
- OpenAPI contract.
- Error-code catalogue.
- Confirmed cPanel constraints.

Exit gate: PM, backend, frontend and operations approve the implementation boundaries.

### Phase 1 - Backend foundation

Duration: 4-6 working days.

Deliverables:

- Application structure.
- Composer setup.
- Configuration loader.
- Database connection.
- Migration runner.
- Request IDs and logging.
- Standard API responses.
- Merchant authentication.
- Test foundation.

Exit gate: a deployable skeleton passes CI checks and authentication tests.

### Phase 2 - Catalogue and business profile

Duration: 5-7 working days.

Deliverables:

- Product CRUD.
- Safe product-image handling.
- Business-profile endpoints.
- Store-profile and catalogue endpoints.
- Frontend integration.

Exit gate: a merchant can configure a real catalogue on staging.

### Phase 3 - Ordering core

Duration: 6-8 working days.

Deliverables:

- Server-controlled price calculation.
- Transactional order capture.
- Order-item snapshots.
- Idempotent checkout.
- Confirmation flow.
- Email notification and retry record.
- WhatsApp handoff.

Exit gate: the full customer order journey and mandatory regression cases pass.

### Phase 4 - Merchant order operations

Duration: 4-6 working days.

Deliverables:

- Paginated order list.
- Filters and order details.
- Controlled status transitions.
- Status history.
- Complete empty and error states.

Exit gate: a merchant can manage daily incoming orders without technical assistance.

### Phase 5 - Pilot deployment

Duration: 5-7 working days.

Deliverables:

- One polished storefront template.
- Staging instance.
- Provisioning checklist.
- Backup and restore verification.
- Release and support runbooks.
- First controlled merchant pilot.

Exit gate: the pilot passes mobile, performance, security and recovery checks.

### Phase 6 - Template expansion

Duration: 5-8 working days.

Deliverables:

- Remaining three templates.
- Shared configuration contract.
- Template regression checks.

Exit gate: all four templates support the same product and order features without client-specific backend branches.

### Phase 7 - Central control plane

Duration: 7-10 working days.

Deliverables:

- Client registry.
- Renewal tracking.
- Signed metrics ingestion.
- Lifecycle status.
- Version inventory.
- Agency dashboard.

Exit gate: the agency can see client health and lifecycle state without accessing individual merchant dashboards.

### Phase 8 - Safe update pipeline

Duration: 7-12 working days.

Deliverables:

- Release manifests.
- Compatibility checks.
- Targeted backups.
- Migration execution.
- Smoke testing.
- Rollback.
- Deployment history.

Exit gate: an update failure can be recovered without leaving a storefront offline.

### Phase 9 - Paystack

Later phase.

Deliverables:

- Merchant eligibility configuration.
- Payment initialization.
- Server-side verification.
- Webhook signature validation.
- Idempotent webhook handling.
- Reconciliation.
- Paid, failed and refunded states.

## 28. Team ownership

### Product management

- Product rules and priorities.
- Acceptance criteria.
- Customer-facing behaviour.
- Resolution of open business decisions.

### Backend engineering

- Schema and migrations.
- API contract.
- Business rules.
- Authentication and authorization.
- Checkout reliability.
- Security and observability.
- Reporting and central integrations.

### Frontend engineering

- Responsive storefront and merchant experience.
- API integration.
- Accessible interaction states.
- Loading, empty, success and error behaviour.

### QA

- Risk-based test plan.
- Regression coverage.
- Release evidence.

### Operations

- Domains and cPanel provisioning.
- Credentials.
- Backups and restores.
- Deployment and rollback.
- Hosting-limit confirmation.

## 29. Risks and controls

| Risk | Severity | Control |
|---|---:|---|
| Version drift | High | Central version inventory and release compatibility rules |
| Unsafe updates | High | Backup, staging, controlled swap, smoke tests and rollback |
| Plain-PHP security gaps | High | Structured layers, approved packages, review checklist and automated tests |
| Duplicate orders | High | Idempotency key, frontend button state and database uniqueness |
| Notification failure | Medium | Persist first, retry record and operational logging |
| Central outage affects stores | Medium | Signed cached lifecycle status and approved grace period |
| Shared-hosting constraints | Medium | Test actual cPanel limits before queues and reporting |
| Manual provisioning errors | Medium | Checklist, generated configuration and post-deployment verification |
| Image and storage growth | Medium | Upload limits, resizing, compression and monitoring |

## 30. Decisions required before implementation

| ID | Decision | Owner | Required by |
|---|---|---|---|
| D1 | Approve routing, validation, logging, email and testing packages | Backend + PM | Phase 1 |
| D2 | Confirm same-origin session-cookie authentication | Backend + frontend | API contract |
| D3 | Define v1 delivery-fee calculation | PM | Checkout |
| D4 | Define allowed order statuses and transitions | PM + operations | Merchant order management |
| D5 | Approve WhatsApp message template and Nigerian phone-number normalization | PM | Checkout integration |
| D6 | Confirm cPanel PHP, cron, memory, storage and database limits | Operations | Foundation/reporting |
| D7 | Define cached storefront-status grace period | PM + operations | Central control plane |
| D8 | Define backup retention, recovery point and recovery time targets | Operations | Pilot |
| D9 | Define the client-count threshold for architecture re-evaluation | Engineering leadership | Scale rollout |

## 31. Immediate execution sequence

1. Review this document with PM, frontend and operations.
2. Resolve D1-D6 before major implementation.
3. Create and approve the ERD.
4. Create and approve the OpenAPI contract.
5. Confirm the real cPanel environment using a staging addon domain.
6. Build one vertical proof: one product, one checkout, one saved order and one simulated email failure.
7. Complete Phase 1 and Phase 2.
8. Stabilize the full checkout flow.
9. Pilot one template with one controlled merchant.
10. Expand templates only after the pilot quality gate passes.
11. Build the central control plane after storefront operations are stable.
12. Build automated updates and Paystack only after recovery and version controls are proven.

## 32. Change control

Update this file when an approved decision changes any of the following:

- Deployment model.
- Data isolation.
- Authentication strategy.
- Checkout persistence.
- Order or payment lifecycle.
- API contract.
- Central reporting.
- Store lifecycle enforcement.
- Update or rollback strategy.

Record important architectural changes in a decision log instead of silently changing implementation behaviour.
