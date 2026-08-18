# Project Sync Swagger API Documentation

This document describes the interactive Swagger UI and OpenAPI documentation system for Project Sync merchant backend deployments.

---

## 1. Documentation Endpoints

The backend exposes interactive Swagger documentation and machine-readable OpenAPI specifications through standard HTTP routes:

| Route | Content-Type | Description |
|---|---|---|
| `GET /api/docs` | `text/html` | Interactive Swagger UI dashboard |
| `GET /api/v1/docs` | `text/html` | Versioned alias for Swagger UI |
| `GET /api/openapi.yaml` | `application/yaml` | Canonical OpenAPI 3.0.3 YAML specification |
| `GET /api/v1/openapi.yaml` | `application/yaml` | Versioned alias for OpenAPI YAML |
| `GET /api/openapi.json` | `application/json` | Canonical OpenAPI 3.0.3 JSON specification |
| `GET /api/v1/openapi.json` | `application/json` | Versioned alias for OpenAPI JSON |

---

## 2. Environment Availability & Security

As decided in [Architecture Decision 2026-08-18 (Phase 7)](file:///c:/Users/Davytun/Desktop/Altair_Attic/project-sync/api/docs/DECISIONS.md):

- **Configurable Exposure (`API_DOCS_ENABLED`)**:
  - Controlled via `API_DOCS_ENABLED=true|false` in `.env` (defaults to `true`).
  - When enabled, Swagger UI and raw OpenAPI specs are accessible across all environments.
  - When disabled (`API_DOCS_ENABLED=false`), all documentation routes (`/api/docs`, `/api/v1/docs`, `/api/openapi.yaml`, `/api/openapi.json`) return a standard HTTP 404 `NOT_FOUND` JSON error envelope.
- **Zero Secret Exposure**:
  - The specification contains purely public API contracts and synthetic placeholder values.
  - Zero database passwords, SMTP credentials, JWT secrets, or Paystack secret keys (`sk_live_` / `sk_test_`) are ever served or embedded.
  - Server filesystem paths and internal configuration are hidden.
- **Secure Swagger UI Configuration**:
  - `persistAuthorization: false`: Bearer JWT tokens entered into Swagger UI are held only in browser memory and are immediately cleared when the page is reloaded.
  - `validatorUrl: null`: Disables third-party external network calls to `validator.swagger.io`.
  - Security headers (`X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`) are strictly enforced.

---

## 3. How to Use Swagger UI for Frontend Development

### 3.1 Step 1: Open Swagger UI
Navigate to `http://localhost:8000/api/docs` in your browser.

### 3.2 Step 2: Authenticate as Administrator
To test protected merchant administration endpoints (`/api/v1/admin/*`):
1. Expand the **Authentication** tag and open `POST /api/v1/admin/login`.
2. Click **Try it out**.
3. Supply valid administrator credentials:
   ```json
   {
     "email": "admin@example.com",
     "password": "ValidAdminPassword123!"
   }
   ```
4. Click **Execute**.
5. Copy the returned `data.access_token` string.
6. Scroll to the top of the Swagger UI page and click the green **Authorize** button with the lock icon.
7. Paste the token into the `bearerAuth` field and click **Authorize**, then **Close**.
8. All subsequent calls to `/api/v1/admin/*` will automatically include `Authorization: Bearer <token>`.

### 3.3 Step 3: Test Public APIs & Checkout
Public APIs (`/api/v1/store`, `/api/v1/categories`, `/api/v1/products`, `/api/v1/orders`) require zero authentication.
- For `POST /api/v1/orders` (Guest Checkout), supply a unique `Idempotency-Key` header (e.g. `checkout-test-1234567890123456`).
- Upon successful order creation, the response returns `confirmation_token`.
- Use this `confirmation_token` in `X-Confirmation-Token` header to test `/api/v1/orders/{reference}/confirmation` and `/api/v1/orders/{reference}/payments`.

---

## 4. Key Request Headers & Conventions

### `Idempotency-Key` (Header)
- **Required For**: `POST /api/v1/orders`, `POST /api/v1/orders/{reference}/payments`
- **Format**: 16 to 200 ASCII characters.
- **Behavior**: Protects against accidental double-submissions. Replays with identical keys return the original resource with `meta.idempotent_replay: true`.

### `X-Confirmation-Token` (Header) / `token` (Query Parameter)
- **Required For**: Guest order confirmation lookup and payment initialization.
- **Behavior**: Verifies that the client possesses the unguessable order secret issued at checkout.

---

## 5. Critical Payment Architecture Warnings

> [!WARNING]
> **Zero Trust on Client Payment Callbacks**:
> A browser redirect or Paystack client popup callback is NEVER proof of payment. Payment state must always be read from Project Sync after backend verification (`GET /api/v1/orders/{reference}/payments/{paymentReference}`).

> [!NOTE]
> **Paystack Webhook (`POST /api/v1/payments/paystack/webhook`)**:
> This endpoint is provider-only and requires a cryptographic HMAC-SHA512 `x-paystack-signature` header calculated over the raw request payload. It cannot be triggered directly with arbitrary JSON from Swagger UI.

---

## 6. Maintaining & Updating the OpenAPI Specification

1. **Source of Truth**: The canonical specification lives at [docs/openapi.yaml](file:///c:/Users/Davytun/Desktop/Altair_Attic/project-sync/api/docs/openapi.yaml).
2. **Build-Time Generation & Commit Policy**:
   - Generate [docs/openapi.json](file:///c:/Users/Davytun/Desktop/Altair_Attic/project-sync/api/docs/openapi.json) before deployment/CI and commit the generated file to version control:
     ```bash
     php scripts/generate-openapi-json.php
     # Or via Composer script:
     composer openapi:generate
     ```
   - **Production Isolation**: Production servers only serve the static `docs/openapi.json` (and `docs/openapi.yaml`) file directly. Dev dependencies (`symfony/yaml`) and YAML parsers are never required on live production environments.
3. **Run Validation & Contract Tests**:
   ```bash
   vendor/bin/phpunit tests/Integration/DocumentationEndpointTest.php tests/Integration/ProductionSwaggerPolicyTest.php tests/Contract/OpenApiSpecificationTest.php
   ```

---

## 7. Self-Hosted Swagger UI Assets & Upgrades

The Swagger UI distribution assets are self-hosted in `public/docs/`:
- `swagger-ui-bundle.js` (Swagger UI v5.18.2)
- `swagger-ui-standalone-preset.js` (Swagger UI v5.18.2)
- `swagger-ui.css` (Swagger UI v5.18.2)
- `index.html` (Branded Project Sync HTML shell)

### Asset Upgrade Procedure:
To update Swagger UI to a newer minor version:
```bash
powershell -Command "curl.exe -s -L 'https://unpkg.com/swagger-ui-dist@<VERSION>/swagger-ui.css' -o 'public/docs/swagger-ui.css'; curl.exe -s -L 'https://unpkg.com/swagger-ui-dist@<VERSION>/swagger-ui-bundle.js' -o 'public/docs/swagger-ui-bundle.js'; curl.exe -s -L 'https://unpkg.com/swagger-ui-dist@<VERSION>/swagger-ui-standalone-preset.js' -o 'public/docs/swagger-ui-standalone-preset.js'"
```
Validate with `vendor/bin/phpunit` after upgrade.
