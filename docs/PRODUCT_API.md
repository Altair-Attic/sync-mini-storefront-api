# Product API

Products use PHP-generated UUID v4 internal and public identifiers. Prices are non-negative integer Nigerian kobo; decimal and floating-point prices are rejected. Currency is read from the business profile (currently `NGN`).

## Public endpoints

`GET /api/v1/products` is unauthenticated and supports `category`, `page`, `per_page`, and `sort`. Page defaults to 1, `per_page` to 20 (maximum 100), and sort to `display_order`. Sort values are `display_order`, `title`, `price_low`, `price_high`, and `newest`. An unknown or inactive category produces an empty page. Every result is active; inactive category information is represented as `category: null`.

```json
{
  "success": true,
  "data": [{
    "public_id": "3be3ff88-a125-44da-a71c-bcf5977dcfab",
    "slug": "ankara-bag",
    "title": "Ankara Bag",
    "description": null,
    "price_kobo": 250000,
    "currency": "NGN",
    "image_url": "/uploads/products/generated.webp",
    "display_order": 0,
    "category": null
  }],
  "meta": {"page": 1, "per_page": 20, "total": 1, "total_pages": 1, "request_id": "req_..."}
}
```

`GET /api/v1/products/{slug}` returns one active product using the same safe fields. Unknown or inactive records return `404 PRODUCT_NOT_FOUND`. Public responses never contain internal IDs, activity flags, or timestamps.

## Admin endpoints

| Method | Path | Authentication | Content type |
|---|---|---|---|
| GET | `/api/v1/admin/products` | No | — |
| POST | `/api/v1/admin/products` | Yes | `application/json` |
| GET | `/api/v1/admin/products/{id}` | No | — |
| PUT | `/api/v1/admin/products/{id}` | Yes | `application/json` |
| DELETE | `/api/v1/admin/products/{id}` | Yes | — |
| POST | `/api/v1/admin/products/{id}/image` | Yes | `multipart/form-data` (`image`) |

All admin endpoints require an administrator JWT in `Authorization: Bearer <access-token>`. Listing supports `category_id`, `status=active|inactive|all` (default `all`), bounded `search`, the public pagination fields, and all public sort modes.

POST and PUT are full representations. `category_id` is nullable and must identify an active category when assigning or changing the assignment. Existing assignments survive category deactivation. `slug` may be generated from `title`, which is trimmed and 2–160 characters. `description` is nullable and limited to 10,000 characters. `price_kobo` must be a JSON integer at least zero. `image_url` is nullable and must be HTTPS or an application-managed absolute path. `is_active` is boolean and `display_order` is a non-negative integer. Unknown fields and immutable `id`, `public_id`, `created_at`, `updated_at`, and `currency` are rejected.

DELETE is repeatable deactivation and never hard-deletes. Catalogue changes are independent of future immutable order-item snapshots.

## Product images

Uploads allow only JPEG, PNG, and WebP, determined from file contents using `finfo`; browser MIME types and filenames are ignored. Generated names contain 48 random hexadecimal characters. Oversized uploads return `413 UPLOAD_TOO_LARGE`; invalid media returns `415 UNSUPPORTED_MEDIA_TYPE`.

If GD with WebP is available, images are converted to WebP and oversized dimensions are reduced while preserving aspect ratio. Without that support, validated originals are stored securely; resizing has not occurred. Storage is outside application source, has a deny-execution `.htaccess`, and requires a server mapping from the public path. A new file is removed if the database update fails. A previous managed image is removed only after success; external HTTPS images are never automatically deleted.

Configuration:

- `PRODUCT_IMAGE_MAX_BYTES`
- `PRODUCT_IMAGE_MAX_WIDTH`
- `PRODUCT_IMAGE_MAX_HEIGHT`
- `PRODUCT_IMAGE_STORAGE_PATH`
- `PRODUCT_IMAGE_PUBLIC_PATH`

cPanel must provide PHP `fileinfo`; GD plus WebP support is required for conversion/resizing. The storage directory must be writable by PHP, remain outside the source and document root, deny script execution, and be exposed only as static media through the configured public mapping.

Relevant errors are `PRODUCT_NOT_FOUND`, `PRODUCT_SLUG_CONFLICT`, `INVALID_CATEGORY`, `VALIDATION_FAILED`, `UNAUTHENTICATED`, `UNSUPPORTED_MEDIA_TYPE`, `UPLOAD_TOO_LARGE`, and `INTERNAL_ERROR`.
