# Administrator authentication

`GET /api/v1/admin/csrf-token` starts/reuses a PHP session and returns `csrf_token`. Send it as `X-CSRF-Token` for `POST /api/v1/admin/login` and `POST /api/v1/admin/logout`. `GET /api/v1/admin/me` returns safe administrator fields or `401`. Login returns generic `INVALID_CREDENTIALS`; repeated failures return `429 RATE_LIMITED`.

Production is same-origin: React is served from `https://business.com`, the API is `https://business.com/api/v1`, and admin routes are under `https://business.com/admin`. Leave `SESSION_DOMAIN` empty for a host-only API cookie. It must be `Secure`, `HttpOnly`, `SameSite=Lax`, and scoped to `/api`. Do not configure production CORS. For local React development, prefer a development-server `/api` proxy; if that is impossible, configure only the exact local origin in `CORS_ALLOWED_ORIGINS`.
