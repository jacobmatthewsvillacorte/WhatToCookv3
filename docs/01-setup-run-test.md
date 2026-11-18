# Setup, run, test, release

**Read this on day 1.** Goal: have the app running and the test suites green.

## Requirements

PHP 8.3+, Composer, Node 22+, npm. Android Studio only if you build the phone app. The local database is SQLite (no install needed).

## Run locally

```powershell
cd whattocook-backend
composer setup                 # .env, sqlite file, migrate, assets
php artisan db:seed            # recipes, ingredient catalogue, admin user
php artisan serve              # http://127.0.0.1:8000

cd ../frontend
npm install
npm start                      # http://localhost:4200
```

The browser build talks to `http://127.0.0.1:8000/api` (`frontend/src/environments/environment.ts`).

## Run tests

```powershell
cd whattocook-backend; php artisan test          # 118 tests
cd frontend;           npm run test:ci           # 65 tests, headless Chrome
cd frontend;           npm run lint; npm run build
```

Backend tests use an in-memory database and the `array` cache (see `phpunit.xml`), so they never touch your local data.

## Phone testing (Android)

Phone and PC on the same Wi-Fi.

```powershell
cd whattocook-backend; php artisan serve --host=0.0.0.0 --port=8000
cd frontend; npm run build:android:local; npx cap open android
```

Set your PC's LAN IP in `environment.ts` (`androidApiBaseUrl`). Release builds require HTTPS: set `WHATTOCOOK_API_BASE_URL` then run `npm run build:android` (`frontend/scripts/configure-production-api.mjs`).

## Manual demo checklist (the happy path)

1. Register, complete onboarding (allergies, diet).
2. Pantry: add `rice`. Add `bihon` and confirm the suggested catalogue name. Try `chair` and confirm it is rejected.
3. Create a family, add a child profile with a birth date, switch between Personal and Family; the pantry changes with the scope.
4. Plan setup: pick dates, meals and diners, then generate. The draft is **not** on the calendar yet.
5. Plan review: see Ready / Low stock / Missing / Needs review. Add shortages to shopping. Record a purchase. Save.
6. Meal details: run preflight. Cook and deduct; check pantry and meal history changed. Cook a second meal without deduction; stock must not change.

## Debugging slow requests

See [14-performance-budget.md](14-performance-budget.md). Short form: set `PERF_TIMING=true` in `.env` and read the `Server-Timing` response header.

## Production release

Target: HTTPS Laravel API on PHP-FPM, managed MySQL/PostgreSQL, static web build, signed Capacitor Android app.

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
APP_URL=https://api.your-domain.example
APP_FORCE_HTTPS=true
CACHE_STORE=file            # or redis
DB_CONNECTION=mysql
CORS_ALLOWED_ORIGINS=https://app.your-domain.example
```

Runbook:

1. Back up the database.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. `php artisan optimize` (caches config, routes, views; important for latency)
5. Smoke test `GET /up`, then log in and make one authenticated read.
6. Build and sign Android outside Git.

Rollback: redeploy the previous revision. Do not run `migrate:rollback` against production as a shortcut; ship a corrective migration.

Security gate: `APP_DEBUG=false`, HTTPS enforced, exact CORS origins (no wildcard), no secrets in Git, database not publicly reachable, release Android blocks cleartext traffic.

## Acceptance scenarios

| Scenario | Expected |
| --- | --- |
| Personal vs family pantry | Each recommendation uses only its own pantry; another family's pantry returns 403 |
| `bihon` / `chair` | the alias is suggested; non-food is rejected and nothing is saved |
| 500 g + 0.5 kg chicken vs a 1 kg recipe | Ready |
| 4 packs of bihon vs 250 g | Needs review until `1 pack = 250 g` is confirmed |
| Toddler diner | Portion notes shown; alcohol and siling labuyo recipes excluded |
| Peanut allergy | Peanut recipes never appear, even if liked |
| Strict 15 min budget | 45 min recipes excluded |
| Cook and deduct | Stock decreases, exactly one history row |
| Cook without deduction | History row, stock unchanged |
