# Performance budget and latency

**Read this on day 4.** It explains what was slow, what was fixed, what the numbers really are, and how to stop regressions.

## What "fast" means here

The target discussed for this project was **under 50 ms**. That is achievable for most read endpoints and **not yet** for pantry-matching endpoints. Here is the honest picture.

Measured in-process (Laravel handles the request, no network), PHP 8.4 CLI on a Windows dev laptop, SQLite, seeded recipes (14) and a 50-item pantry. Medians of 40 warm requests, numbers vary about plus or minus 20% between runs:

| Endpoint | Before | After | Under 50 ms? |
| --- | --- | --- | --- |
| `GET /recipes/{id}` | about 5 ms | about 5 ms | yes |
| `GET /shopping-list`, `/meal-plans` (small data) | about 2-5 ms | about 2-5 ms | yes |
| `GET /pantry` (50 items) | about 25 ms | about 25 ms | yes |
| `GET /recipes/recommendations` | about 210-250 ms | about 85 ms | **no** |
| `GET /recipes?include_match=1` | about 210-250 ms | about 85-90 ms | **no** |

The numbers that matter are the A/B ones: the matcher rewrite made recommendations **2.5x faster** under identical conditions, and the old version also issued hundreds of SQL queries once package conversions are involved.

### What the claim can and cannot mean

- It **can** mean: server time inside Laravel for an authenticated read on warm requests, with `php artisan optimize` run and OPcache on in production.
- It **cannot** mean: end-to-end latency (network, TLS, tunnel, Ionic rendering), cold starts, write endpoints (cooking and batch generation run transactions and will be slower), concurrent load on SQLite (writers serialise), or catalogue sizes far beyond about 100 recipes (filtering is O(recipes)).
- `php artisan serve` serialises requests. Never use it to judge concurrency.

## What was slow and what we changed

| # | Cause | Change | Where |
| --- | --- | --- | --- |
| 1 | One SQL query per pantry item per recipe ingredient (package conversions) | Memoised per request | `RecipeMatcher::packageConversionFor` |
| 2 | Pantry matching re-normalised strings and used Eloquent attribute access and collection closures in a recipes x ingredients x pantry loop | Pantry indexed once into plain arrays; `normalise` memoised; lots filtered once | `RecipeMatcher::matchingLots`, `indexPantry` |
| 3 | Full recipe list plus ingredients loaded on every request | Cached forever, invalidated by model events | `RecipeCatalog` |
| 4 | Batch generation reloaded catalogue and roster per day | Loaded once before the loop | `MealPlanBatchController::generate` |
| 5 | No indexes on foreign keys | New migration | [10-database-and-indexes.md](10-database-and-indexes.md) |
| 6 | Cache and rate limiter hit the `cache` DB table on every request | `CACHE_STORE=file` in `.env.example` | `.env.example` |
| 7 | SQLite rollback journal, no busy timeout | WAL, `synchronous=NORMAL`, 5 s busy timeout | `config/database.php` |

## Known limits (next wins, biggest first)

1. **Recommendation CPU.** Remaining cost is matching plus building JSON. The response repeats each ingredient in up to three lists (`ingredients`, `available_ingredients`, `missing_ingredients`). Return the full list once, or slim the payload for the list view, to cut both JSON time and bytes. Needs a frontend change.
2. **Hydration cost.** Each `pantry_items` row builds several Carbon objects through date casts. About 20 ms for 50 rows. Select only needed columns for list views, or return raw date strings.
3. **Safety filtering in PHP.** `RecipeController::index` loads and filters every matching recipe before paginating. Fine at 100 recipes; at thousands, move to SQL.
4. **`whereDate` on `planned_date`** cannot use an index; see the gotcha in doc 10.
5. **Sanctum** updates `last_used_at` on every authenticated request: a write per read on SQLite. On a production database it is cheap; on SQLite consider a throttled update.

## How to measure

```dotenv
# whattocook-backend/.env  (APP_ENV=local)
PERF_TIMING=true
```

Restart, call an endpoint, then read:

- Response header `Server-Timing: app;dur=..., sql;dur=...`
- `storage/logs/laravel.log` entry `request.performance` (route, duration, query count, response bytes)

Source: `app/Http/Middleware/RequestPerformanceTiming.php`. It does nothing unless `PERF_TIMING` is on and the environment is `local`.

To time in-process without a server, copy the pattern in `tests/Feature/PerformanceBudgetTest.php` and wrap `$this->getJson(...)` in `hrtime(true)`. Disable throttling in such a test with `withoutMiddleware(ThrottleRequests::class)`.

## How regressions are prevented

`tests/Feature/PerformanceBudgetTest.php` asserts **query counts** and cache invalidation, not milliseconds (wall-clock assertions are flaky in CI). If it fails, someone reintroduced an N+1 or broke `RecipeCatalog` invalidation.

## Production checklist

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize          # config + route + view cache
```

`APP_DEBUG=false`, `LOG_LEVEL=warning`, OPcache enabled, `CACHE_STORE=file` or Redis, MySQL/PostgreSQL instead of SQLite for concurrent use.
