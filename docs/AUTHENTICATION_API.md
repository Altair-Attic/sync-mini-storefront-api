# Administrator Authentication API

Administrator authentication uses a stateless HS256 JWT access token. Customers remain unauthenticated and there is no administrator registration endpoint.

Login returns an access token held only in frontend memory and sent as `Authorization: Bearer <access-token>`. Never write it to `localStorage` or `sessionStorage`. There are no refresh cookies, refresh endpoints, same-origin handshakes, or server-side JWT denylist.

## Endpoints

### `POST /api/v1/admin/login`

Accepts JSON `email` and `password`, preserves generic credential failures and login rate limiting, and returns `access_token`, `token_type: Bearer`, `expires_in`, and a safe `administrator` object. The default lifetime is 28,800 seconds (eight hours).

### `GET /api/v1/admin/me`

Requires a single, correctly formatted Bearer authorization value. The API verifies the full JWT and reloads the active administrator from MySQL before returning safe fields under `data.administrator`.

### `POST /api/v1/admin/logout`

Requires a valid Bearer token and records a safe logout event before returning success. The frontend must then remove the token from memory and redirect to login. Because this is stateless authentication, the endpoint does not revoke an already-issued JWT; it remains valid until expiry.

## Access-token claims

The pinned algorithm is `HS256`. Validation checks the signature, administrator subject, and expiry. JWTs contain no administrator profile or business data. A valid issued token expires naturally after frontend logout.

## Configuration

Required production configuration is `JWT_SECRET`, `JWT_ALGORITHM=HS256`, `JWT_ACCESS_TTL_SECONDS`, and HTTPS `APP_URL`. The JWT secret must be at least 32 bytes and must not use a placeholder value.

The complete contract is in `docs/authentication.openapi.yaml`.
