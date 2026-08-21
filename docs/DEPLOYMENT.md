# Project Sync Deployment Guide (cPanel Shared Hosting)

This guide documents the complete, step-by-step procedure for deploying, configuring, and updating Project Sync backend instances on standard cPanel-compatible shared hosting environments.

---

## 1. Architectural Overview & Hosting Principles

Project Sync operates as an isolated single-tenant backend per merchant installation. Each deployment contains:
- Its own isolated MySQL database;
- Its own non-public application root and persistent `.env` configuration file;
- Its own isolated storage for uploads, logs, and notification lock files;
- A dedicated web document root pointed strictly to `backend/public/`.

> [!IMPORTANT]
> **Zero Web-Root Exposure**: The web server Document Root must NEVER point to the repository root. It must point strictly to `backend/public/`. The parent directories, `.env`, `config/`, `src/`, and `storage/` must reside completely outside public HTTP access.

---

## 2. Server Prerequisites & Verification

Before deployment, ensure the cPanel account satisfies the following minimum prerequisites:

| Requirement | Minimum Supported | Recommended |
|---|---|---|
| **PHP Version** | `8.3.0` | `8.3.x` or `8.4.x` |
| **MySQL / MariaDB** | `MySQL 8.0` / `MariaDB 10.5` | `InnoDB` engine, `utf8mb4` charset |
| **Required Extensions** | `pdo_mysql`, `curl`, `mbstring`, `json`, `ctype`, `fileinfo`, `openssl` | All enabled via cPanel *Select PHP Version* |
| **Memory Limit** | `128M` | `256M` |
| **HTTPS / SSL** | Let's Encrypt / cPanel AutoSSL | Active with valid TLS certificate |

---

## 3. Initial Deployment Walkthrough

### Step 1: Establish cPanel Directory Layout

1. Log into cPanel via SSH or File Manager.
2. Create the private application directory outside `public_html`, for example:
   ```bash
   mkdir -p /home/username/project-sync-api
   ```
3. Upload or clone the application release artifacts into `/home/username/project-sync-api`.
4. Point your domain or subdomain Document Root in cPanel to:
   ```
   /home/username/project-sync-api/public
   ```
   *(Alternatively, if deploying directly to a primary domain where `public_html` is fixed, place backend files in `/home/username/project-sync-api` and create a symlink from `public_html` to `/home/username/project-sync-api/public`).*

### Step 2: Provision MySQL Database and User

1. In cPanel, navigate to **MySQL® Databases**.
2. Create a new database (e.g. `username_sync_store`).
3. Create a dedicated database user (e.g. `username_sync_usr`) with a high-entropy password.
4. Assign the user to the database with **ALL PRIVILEGES**.

### Step 3: Configure `.env` Environment File

1. Copy `.env.example` to `/home/username/project-sync-api/.env`.
2. Generate cryptographically strong random secrets (minimum 32 characters) using `php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"`.
3. Set production parameters:

```ini
APP_NAME="Project Sync API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://store.merchant.com
LOG_LEVEL=info
API_DOCS_ENABLED=false
HSTS_ENABLED=true
HSTS_MAX_AGE=31536000

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=username_sync_store
DB_USERNAME=username_sync_usr
DB_PASSWORD=YOUR_HIGH_ENTROPY_DB_PASSWORD

JWT_SECRET=GENERATED_64_CHAR_HEX_SECRET
JWT_ACCESS_TTL_SECONDS=28800
JWT_ALGORITHM=HS256

PAYSTACK_SECRET_KEY=sk_live_YOUR_PAYSTACK_LIVE_KEY
PAYSTACK_BASE_URL=https://api.paystack.co
PAYSTACK_TIMEOUT_SECONDS=10

MAIL_ENABLED=true
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=postmaster@store.merchant.com
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_FROM_ADDRESS=orders@merchant.com
MAIL_FROM_NAME="Merchant Store"
```

### Step 4: Configure Permissions and Storage Directories

Ensure storage directories exist and are writable by the web server process:

```bash
cd /home/username/project-sync-api
mkdir -p storage/logs storage/uploads/products storage/cache storage/locks
chmod -R 0755 storage
chmod 0600 .env
```

### Step 5: Install Production Composer Dependencies

Install production-only vendor packages with an optimized class map:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

### Step 6: Execute Database Migrations

Run all forward-only schema migrations:

```bash
php scripts/migrate.php
```

### Step 6.1: Bootstrap the Merchant Profile

For a fresh merchant database, run the interactive bootstrap command from SSH:

```bash
/opt/alt/php83/usr/bin/php scripts/bootstrap-merchant.php
```

It securely prompts for the profile and, only if no administrator exists, the first administrator. It never overwrites an existing profile or administrator. Do not put the administrator password in shell history, environment variables, or command-line arguments.

### Step 7: Run Production Preflight Verification

Verify server configuration, extensions, write permissions, and database connectivity:

```bash
php scripts/production-preflight.php
```
*The command must exit with code `0` (`PREFLIGHT CHECK RESULT: PASSED`).*

### Step 8: Configure cPanel Cron for Notification Queue

In cPanel **Cron Jobs**, add a recurring 1-minute cron task:

```cron
* * * * * /usr/local/bin/php /home/username/project-sync-api/scripts/process-notifications.php >/dev/null 2>&1
```

> [!NOTE]
> `scripts/process-notifications.php` uses non-blocking `flock` file locking on `storage/locks/notification_worker.lock`. If a previous job run is still executing, overlapping cron executions terminate immediately without duplicate processing or queue race conditions.

### Step 9: Execute Post-Deployment Smoke Test

Verify the live deployment endpoints against the public URL:

```bash
php scripts/smoke-test.php --url=https://store.merchant.com
```

---

## 4. Release & Update Procedure

When updating an existing production deployment to a new release:

1. **Pre-Update Database Backup**:
   ```bash
   mysqldump -u username_sync_usr -p username_sync_store > /home/username/backups/pre_update_$(date +%Y%m%d_%H%M%S).sql
   ```
2. **Deploy New Release Code**:
   Sync updated application code into `/home/username/project-sync-api/`, preserving `.env`, `storage/uploads/`, and `storage/logs/`.
3. **Update Dependencies**:
   ```bash
composer install --no-dev --optimize-autoloader --no-interaction
```
4. **Execute Forward Migrations**:
   ```bash
   php scripts/migrate.php
   /opt/alt/php83/usr/bin/php scripts/bootstrap-merchant.php
   ```
5. **Run Preflight & Smoke Tests**:
   ```bash
   php scripts/production-preflight.php
   php scripts/smoke-test.php --url=https://store.merchant.com
   ```

---

## 5. Rollback Strategy

If a critical failure occurs during an update:

1. **Restore Code**: Revert the file tree in `/home/username/project-sync-api` to the previous stable release commit or artifact archive.
2. **Restore Database**:
   ```bash
   mysql -u username_sync_usr -p username_sync_store < /home/username/backups/pre_update_YYYYMMDD_HHMMSS.sql
   ```
3. **Re-run Smoke Test**:
   ```bash
   php scripts/smoke-test.php --url=https://store.merchant.com
   ```
