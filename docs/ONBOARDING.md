# Onboarding

Copy `resources/onboarding.example.json` to `storage/private/onboarding/onboarding.json`, edit it with non-secret business data, run migrations, then run `ONBOARDING_ADMIN_PASSWORD='temporary-strong-password' php bin/onboard.php --file=/absolute/path/onboarding.json`. Remove the temporary environment variable immediately. The file is ignored by Git and must remain outside `public/`.

The command is transactional and idempotent: a matching re-run does not duplicate records or reset the password; conflicting business identity or administrator email stops safely. Production requires PHP 8.3, PDO MySQL, JSON, and writable `storage/logs` and session storage. Point cPanel’s document root at `public/`.

Use a disposable MySQL database for migration and onboarding tests. Never reset an existing development or production database automatically. Existing installations that used the earlier numeric-ID development schema require a reviewed data migration; only disposable databases may be dropped and recreated for the UUID schema.
