# Hosting and production deployment

This is a host-agnostic deployment checklist. It does not deploy the application or make a local development database production-ready. A real deployment requires a hosting account, production domain, TLS certificate, production database, and an authorized mail sender.

The existing application is server-rendered PHP with same-origin sessions and routes; it has no separate JavaScript frontend to host on Vercel. Keep the application and its static assets together on a PHP-capable host rather than rewriting the frontend or moving PHP routes behind a different origin.

## Hosting requirements

- PHP 8.1 or newer with PDO MySQL, OpenSSL, and the extensions required by the PHP runtime checks and upload features.
- MySQL compatible with the project's migrations, with InnoDB and `utf8mb4` support.
- Apache with PHP support or Nginx with PHP-FPM.
- HTTPS for every public route and an SMTP account authorized to send as the configured address.

## Prepare the application

1. Provision a clean production database and a dedicated database account. Grant only the permissions needed by the application and migrations; do not use the MySQL root account.
2. Set the web server document root to the project's `public/` directory. Do not expose the project root, `config/`, `database/`, `docs/`, `storage/`, or `vendor/` as public directories. Keep the existing deny rules when using Apache.
3. Install locked production dependencies from the project root:

   ```sh
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   ```

4. Copy `config/local.example.php` to `config/local.php`. Keep `local.php` outside the document root, restrict filesystem access, and configure:
   - `app_url` to the final `https://` URL (including any deployment subdirectory).
   - `db` with the production database host, database name, dedicated user, and password.
   - `mail` with the verified SMTP host, port, encryption, credentials, and authorized sender.
   - `ai.base_url` with the trusted Ollama service URL. Use HTTPS for a separately hosted service on a private or otherwise secured network; unencrypted HTTP is accepted only for loopback development.
   - `auth_pepper` and `app_key` with different, independently generated random secrets. For example, generate each value using `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Store them in protected deployment configuration and never commit or publish them.
   - `timezone` to the school's intended timezone.
5. Run database migrations only after a verified backup, on a staging copy first, and in the order documented in `README.md`. Review the database before applying migrations to an existing system; do not blindly rerun migrations against a database that may already include them. Apply migration 064 and migration 065 only once each. Migration 065 updates only automatic/default zero weighting and empty draft assessments without student results; explicitly saved school weighting and recorded results are preserved.
6. Enable HTTPS and configure the web server so PHP correctly receives the HTTPS state. Confirm secure session cookies are set on the deployed HTTPS site.
7. Set uploaded/private storage directories to be writable only by the application account and not directly accessible from the web. Confirm scheduled backups, restore procedures, error-log monitoring, and email delivery before inviting users.
8. Smoke-test registration, login, MFA, password recovery, school scoping, support requests/replies, fee and academic workflows, uploads, and mobile layouts on staging using non-production accounts and data.

## Before going live

- Rotate any SMTP credentials or application secrets that were exposed during development; update the protected production configuration.
- Confirm the SMTP provider authorizes the configured sender and test delivery only with an account controlled by the operator.
- Check that no debug output, PHP errors, database details, backups, or configuration files are publicly accessible.
- Take and verify a production database backup before each schema change. Test restoring it to a separate database.
- Record the production URL, hosting provider, database backup owner, and incident contact in the operator's protected runbook (not in source control).

## Render deployment (Docker)

The repository includes a Render Blueprint (`render.yaml`) and a PHP 8.3/Apache Docker image. The image serves only `public/`; it does not install, seed, import, reset, or migrate a database at startup. The health check at `/healthz.php` is a liveness check only and intentionally does not expose database status or configuration.

### Required services and storage

1. Provision an external MySQL or MariaDB database compatible with the project's schema and migrations. Render does not supply a managed MySQL database through this Blueprint. Use a dedicated database and least-privilege application user; configure the database provider's TLS and network allowlist/firewall as required.
2. Select the Render plan that supports persistent disks. The Blueprint mounts a disk at `/var/www/html/storage`; this is needed for uploaded logos, profile/student photos, support attachments, and PHP sessions. Back up this disk separately from the database, and copy existing uploads to it before switching live traffic. The Docker build excludes local `storage/` content.
3. Keep the web service at one instance while PHP sessions use the persistent disk. Do not scale horizontally until sessions use a shared session service and file writes have a shared storage strategy.

### Configure environment variables

Connect the repository to Render and create the web service from `render.yaml`. Set the prompted secret/environment values in the Render dashboard:

- `APP_URL`: the final HTTPS URL assigned to the service.
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`: connection settings for the external MySQL/MariaDB service. Do not use a PostgreSQL `DATABASE_URL`.
- `DB_SSL_CA` is optional; when the provider requires a custom CA certificate, add it as a protected Render secret file and set this value to the mounted file path. The PDO connection verifies the certificate when this setting is present. Follow the database provider's current TLS requirements.
- `AUTH_PEPPER` and `APP_KEY`: two different randomly generated secrets, each at least 32 characters. Generate values locally (for example, with PHP `random_bytes(32)`); never paste them into source files or commit them.
- `OLLAMA_BASE_URL`: the trusted HTTPS endpoint for the separate Ollama server, reachable only over a secured network. Ollama is the only supported AI provider; there is no third-party model fallback. Keep its endpoint private and verify the installed model and request latency before enabling assistants for users.
- `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_EMAIL`, and related mail settings: the authorized SMTP provider values if email delivery is enabled.
- `APP_ENV` must remain `production`. The remaining public display/timezone variables have non-secret defaults in the Blueprint and may be adjusted in Render.

The checked-in `.env.example` contains placeholders only and is for reference; it is not a populated production configuration. The application reads Render environment variables without creating or committing `config/local.php`. For local XAMPP, continue to use the ignored `config/local.php` copied from `config/local.example.php`.

### Import existing data and deploy schema updates

1. Take a consistent backup of the existing database and uploads, and test restoring each backup in an isolated environment. For InnoDB tables, a local MySQL client can export without embedding a password in the command line:

   ```sh
   mysqldump --single-transaction --routines --triggers --events --default-character-set=utf8mb4 -h SOURCE_HOST -P SOURCE_PORT -u SOURCE_USER -p SOURCE_DATABASE > protected-backup.sql
   ```

   Create an empty database and least-privilege user at the external provider, then import the reviewed dump with:

   ```sh
   mysql --default-character-set=utf8mb4 -h DESTINATION_HOST -P DESTINATION_PORT -u DESTINATION_USER -p DESTINATION_DATABASE < protected-backup.sql
   ```

   Keep the dump outside the repository and public web root, restrict file permissions, and securely remove temporary copies only after verifying the destination and an independent backup.
2. Verify the import by comparing table names and row counts with the source, checking representative user/student/fee/attendance/report/support records and their IDs, and testing key relationships in staging. Transfer private uploads to the mounted disk without changing their stored paths. Do not use the development SQL snapshot as an automatic Render startup step.
3. Review the current production schema against the migration history. Apply only migrations that are not already present, in the documented order, to a staging copy first. Migration SQL is explicit and is not run by the container; record which migration files were applied. Migration 080 adds per-message support read markers, ticket read/replied timestamps, and a reply idempotency key without deleting conversation history. Back up before each production schema change.
4. Configure all environment values and the persistent disk, deploy the Blueprint, and confirm the service reports healthy at `/healthz.php`. Then verify HTTPS login/session cookies, database connectivity through an authenticated application workflow, role/school isolation, uploads, SMTP, and critical school workflows using authorized test accounts.
5. Keep `autoDeploy` disabled in the Blueprint until the operator is comfortable with the release process. Review changes, back up data, deploy, and smoke-test each update; deployment must never be treated as a database backup or migration.

Render's proxy terminates HTTPS. In production the application trusts the forwarded HTTPS scheme for setting the secure session-cookie flag, while local development continues to use PHP's HTTPS state.

Online deployment is not complete until a hosting provider and domain are selected, production configuration is supplied securely, migrations are reviewed and applied, and the staging smoke tests pass.
