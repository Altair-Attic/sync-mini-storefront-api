# Merchant bootstrap

After migrations complete, initialize a fresh merchant database interactively:

```bash
/opt/alt/php83/usr/bin/php scripts/bootstrap-merchant.php
```

The command asks for the business profile and, when no administrator exists, the first administrator. The password is entered twice with terminal echo disabled; it is never supplied as a command-line argument, stored in an onboarding file, or written to logs.

The command is transactional. It creates only missing records: an existing administrator is preserved when the business profile is missing, and an existing profile is preserved when only the administrator is missing. When both already exist it exits successfully without making changes. It does not create demo products, categories, or orders.

Run it from an interactive SSH terminal, after `scripts/migrate.php` and before opening the merchant administration UI. Production requires PHP 8.3, PDO MySQL, JSON, and writable `storage/logs`. Point cPanel's document root at `public/`.
