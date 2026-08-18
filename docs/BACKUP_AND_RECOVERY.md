# Project Sync Backup & Disaster Recovery Guide

This document defines the backup, retention, and disaster recovery procedures for Project Sync single-tenant merchant installations.

---

## 1. Backup Scope & Assets

A complete merchant installation backup consists of two independent assets:

1. **MySQL Database**: Contains business profile, administrator accounts, categories, products, orders, order items, payment attempts, payment audit events, and notification logs.
2. **Persistent Storage (`storage/uploads/`)**: Contains uploaded merchant product images and logos.

> [!NOTE]
> Application source code is version-controlled and reproducible from release tags. Transient files (`storage/logs/`, `storage/cache/`, `storage/locks/`) do not require long-term archival.

---

## 2. Automated Daily Backup Strategy

### Backup Script (`scripts/backup.sh`)

Place the following script in `/home/username/scripts/backup-store.sh` (outside web root):

```bash
#!/bin/bash
set -euo pipefail

# Configuration
APP_DIR="/home/username/project-sync-api"
BACKUP_DIR="/home/username/backups/store"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=14

# Ensure backup directory exists
mkdir -p "${BACKUP_DIR}"

# 1. Source DB Credentials from .env
DB_NAME=$(grep -E "^DB_DATABASE=" "${APP_DIR}/.env" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_USER=$(grep -E "^DB_USERNAME=" "${APP_DIR}/.env" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_PASS=$(grep -E "^DB_PASSWORD=" "${APP_DIR}/.env" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_HOST=$(grep -E "^DB_HOST=" "${APP_DIR}/.env" | cut -d '=' -f2 | tr -d '"' | tr -d "'")

# 2. Dump MySQL Database with Transactions and UTF8mb4
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
  | gzip > "${BACKUP_DIR}/db_${DB_NAME}_${DATE}.sql.gz"

# 3. Archive Product Uploads
if [ -d "${APP_DIR}/storage/uploads" ]; then
  tar -czf "${BACKUP_DIR}/uploads_${DATE}.tar.gz" -C "${APP_DIR}/storage" uploads
fi

# 4. Prune Backups Older than Retention Period
find "${BACKUP_DIR}" -type f -name "*.gz" -mtime +${RETENTION_DAYS} -delete

echo "[$(date)] Backup completed successfully for ${DB_NAME}."
```

### Scheduled Cron Job

In cPanel **Cron Jobs**, configure a daily execution at 02:00 UTC:

```cron
0 2 * * * /bin/bash /home/username/scripts/backup-store.sh >> /home/username/backups/backup.log 2>&1
```

---

## 3. Retention & Offsite Archival Policy

- **Daily Backups**: Retained for 14 days on local disk.
- **Weekly Snapshots**: Retained for 4 weeks in offsite cloud storage (e.g. AWS S3, Cloudflare R2, or cPanel Remote Backup).
- **Monthly Snapshots**: Retained for 3 months for financial and compliance audit requirements.

---

## 4. Database Restoration Procedure

In the event of database corruption, accidental deletion, or server disaster, follow this step-by-step restoration workflow:

### Step 1: Temporarily Pause Cron Processing
Disable the notification cron in cPanel or comment out the crontab entry to prevent worker race conditions during restore.

### Step 2: Extract & Verify Database Archive
```bash
gunzip -c /home/username/backups/store/db_username_sync_store_YYYYMMDD_HHMMSS.sql.gz > /tmp/restore.sql
head -n 20 /tmp/restore.sql
```

### Step 3: Restore MySQL Database
```bash
# Source credentials from .env
mysql -h localhost -u username_sync_usr -p username_sync_store < /tmp/restore.sql
rm -f /tmp/restore.sql
```

### Step 4: Verify Migration Consistency
Run migration inspection to confirm the restored database is fully synchronized with current application code:
```bash
cd /home/username/project-sync-api
php scripts/migrate.php
```
*(Should report 0 pending migrations).*

### Step 5: Restore Media Files (If Applicable)
```bash
tar -xzf /home/username/backups/store/uploads_YYYYMMDD_HHMMSS.tar.gz -C /home/username/project-sync-api/storage
chmod -R 0755 /home/username/project-sync-api/storage/uploads
```

### Step 6: Verify Store Functionality & Resume Cron
1. Run the smoke test:
   ```bash
   php scripts/smoke-test.php --url=https://store.merchant.com
   ```
2. Re-enable the cPanel notification cron.

---

## 5. Recovery Objectives (RTO & RPO)

| Metric | Target Objective | Implementation |
|---|---|---|
| **Recovery Point Objective (RPO)** | **< 24 Hours** | Automated nightly dumps + transaction logs |
| **Recovery Time Objective (RTO)** | **< 30 Minutes** | Standardized single-database gzip restore workflow |
