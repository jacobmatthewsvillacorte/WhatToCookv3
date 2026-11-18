# WhatToCook API

The WhatToCook API is built with Laravel 13, PHP 8.3+, SQLite, and Laravel Sanctum.

## Setup

```powershell
composer setup
php artisan serve
```

`composer setup` installs PHP and frontend dependencies, copies `.env.example` to `.env` when necessary, generates an application key, runs migrations, and builds Laravel's Vite assets. The default SQLite database is `database/database.sqlite`; it is created by the Laravel project setup workflow and is intentionally ignored by Git.

If you set up manually, ensure `.env` contains `DB_CONNECTION=sqlite` and that `database/database.sqlite` exists before running `php artisan migrate`.

## Tests

```powershell
php artisan test
```

The test suite uses an in-memory SQLite database, so it does not modify your local development database.

## Production configuration

Use a managed MySQL or PostgreSQL database for the shared API; SQLite is only the local default. Set production values in the hosting provider's secret/configuration store, never in Git: `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (HTTPS), `APP_FORCE_HTTPS=true`, `DB_*`, `USDA_API_KEY`, and `CORS_ALLOWED_ORIGINS`.

`CORS_ALLOWED_ORIGINS` is a comma-separated list of approved web client HTTPS origins. Capacitor's local native origins are included separately so a signed Android app can call the same HTTPS API. The API uses bearer tokens, so cross-site cookies are disabled. See the [deployment guide](../docs/01-setup-run-test.md) for migration, health-check, backup, and rollback steps.

## API authentication

The mobile client registers or signs in at `/api/register` and `/api/login`, then sends the returned Sanctum token as a Bearer token. Protected endpoints return JSON `401` responses for expired or invalid tokens. `/api/logout` revokes the account's bearer tokens.

## USDA nutrition data

Set `USDA_API_KEY` in `.env` to enable FoodData Central search and caching. The key remains server-side; do not put it in the mobile app. If it is absent, USDA endpoints return `503` and clients must show nutrition as unavailable. Recipe nutrition returns `data_status` (`complete`, `partial`, or `incomplete`) and `unknown_nutrients`; totals are estimates, not medical advice, and missing values are never silently treated as zero.
