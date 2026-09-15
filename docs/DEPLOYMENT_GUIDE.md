# Production deployment plan

## Target architecture

One Laravel API instance (or a horizontally scaled group behind HTTPS) connects to one managed MySQL 8+/PostgreSQL 16+ database. The Ionic browser client is static-hosted; the Android app is a signed Capacitor build. Both use the same versioned HTTPS API URL. The `/up` endpoint is the load-balancer health check.

| Concern | Production decision |
| --- | --- |
| API | PHP 8.3+ Laravel process behind TLS-terminating reverse proxy; document and trust the provider proxy configuration. |
| Database | Managed service with private networking/firewall access limited to the API, automatic backups, point-in-time recovery, and a tested restore. |
| Secrets | Hosting secret store only: `APP_KEY`, database password, USDA key, mail credentials, and any future provider credentials. |
| CORS | `CORS_ALLOWED_ORIGINS=https://app.your-domain.example` (add only actual browser origins). Native Capacitor origins are handled by `config/cors.php`. |
| Jobs/cache | Use a managed Redis service when asynchronous work or shared cache is enabled; otherwise the database queue/cache is acceptable for the capstone scale. |
| Logs | Centralize production logs; retain error/audit evidence without logging bearer tokens or passwords. |

## Environment configuration

Start from `whattocook-backend/.env.example` in the host's secret/configuration UI. Required production values are:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-domain.example
APP_FORCE_HTTPS=true
DB_CONNECTION=mysql # or pgsql
DB_HOST=<managed-db-host>
DB_PORT=3306 # 5432 for PostgreSQL
DB_DATABASE=<database-name>
DB_USERNAME=<least-privilege-app-user>
DB_PASSWORD=<stored-secret>
CORS_ALLOWED_ORIGINS=https://app.your-domain.example
```

Generate `APP_KEY` once with `php artisan key:generate --show` in a secure environment and save it as a host secret. Do not rotate it casually: it invalidates encrypted application data. Set `LOG_LEVEL=warning` or `error` in production. Keep `USDA_API_KEY` server-side only and leave `USDA_VERIFY_SSL=true`; install a valid CA bundle instead of disabling certificate verification in production.

## Safe release runbook

1. Back up the managed database and record the restore point.
2. Deploy the immutable application revision without changing traffic.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader` and `php artisan migrate --force` against the production database.
4. Run `php artisan optimize` after configuration is present; restart PHP workers/queue workers.
5. Check `GET /up`, then exercise register/login and one authenticated read with a test account.
6. Build Android using the real endpoint: set `WHATTOCOOK_API_BASE_URL`, run `npm run build:android`, sign outside Git, and test the signed artifact.
7. Shift traffic only after the smoke test passes. Monitor errors, latency, database connections, and backups.

No command in this repository deploys, publishes, signs, or pushes a release automatically.

## Rollback

Keep the previous application revision available. If the release fails before migrations, switch traffic back. If a migration has run, restore from the verified backup only when the migration is not safely reversible; otherwise deploy the compatible previous revision and use a reviewed corrective migration. Never use destructive migration rollback commands against production as an incident shortcut.

## Pre-release security gate

- [ ] `APP_DEBUG=false`, HTTPS enforced, and `/up` monitored.
- [ ] Database is not publicly reachable and backup/restore has been tested.
- [ ] Browser origins are exact HTTPS origins; no wildcard CORS origin is configured.
- [ ] Environment files, signing keys, API keys, and hosted database credentials are absent from Git/history.
- [ ] Android release blocks cleartext traffic and uses the generated HTTPS endpoint.
- [ ] CI is green and the physical-device checklist passes.
