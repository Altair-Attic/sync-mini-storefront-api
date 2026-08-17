# Administrator Authentication API

Administrator authentication uses short-lived JWT access tokens and rotating opaque refresh tokens. Customers remain unauthenticated and there is no administrator registration endpoint.

Access tokens are returned by login and refresh, held only in frontend memory, and sent as `Authorization: Bearer <access-token>`. Never write an access token to `localStorage` or `sessionStorage`. Refresh tokens are never returned in JSON: they exist only in a Secure, HttpOnly, host-only cookie and only their HMAC-SHA-256 hashes are stored in MySQL.

Browser requests to login, refresh, and logout must be same-origin. When a browser supplies `Origin`, or HTTPS `Referer` when `Origin` is absent, the API compares it with `APP_URL`. Headerless non-browser clients such as HTTPie and Postman are permitted for API testing; a supplied mismatched or malformed origin/referer is always rejected. Production cookies are Secure, HttpOnly, omit `Domain`, use `SameSite=Strict` by default, and use `/api/v1/admin` as the narrow common path for refresh and logout.

## Endpoints

### `POST /api/v1/admin/login`

Accepts JSON `email` and `password`, preserves generic credential failures and login rate limiting, and returns `access_token`, `token_type: Bearer`, `expires_in`, and a safe `administrator` object. The response also sets the refresh cookie. The raw refresh token never appears in the body.

### `POST /api/v1/admin/refresh`

Reads the refresh token only from its HttpOnly cookie, rotates it, sets the replacement cookie, and returns `access_token`, `token_type`, and `expires_in`. An expired, revoked, malformed, or otherwise invalid token returns generic `401 UNAUTHENTICATED`. Reuse of a rotated token revokes its complete family.

### `GET /api/v1/admin/me`

Requires a single, correctly formatted Bearer authorization value. The API verifies the full JWT and reloads the active administrator from MySQL before returning safe fields under `data.administrator`.

### `POST /api/v1/admin/logout`

Uses the refresh cookie when present to revoke its family. When the request also supplies a valid Bearer token, that exact access token is revoked immediately until its natural expiry; other device sessions remain active. The endpoint always expires the refresh cookie and returns predictable success when credentials are missing or invalid.

## Access-token claims

The pinned algorithm is `HS256`. Required claims are `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`, `jti`, and `token_version`. Issuer and audience match configuration exactly. Numeric dates and token version must be integers; identifiers must be non-empty strings. A small configured clock skew applies only to time validation. JWTs contain no administrator profile or business data.

## Configuration

Required production configuration is `JWT_SECRET`, `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_ALGORITHM`, `REFRESH_TOKEN_SECURITY_SECRET`, `APP_URL`, and secure refresh-cookie settings. Defaults are 900 seconds for access tokens and 2,592,000 seconds for refresh tokens. JWT and refresh-hashing secrets must differ and each be at least 32 bytes in production.

The complete contract is in `docs/authentication.openapi.yaml`.
