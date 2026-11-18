# Database and indexes

**Read this on day 2, after [02-architecture.md](02-architecture.md).**

## How to read the schema

Migrations in `whattocook-backend/database/migrations/` run in filename order and later ones alter earlier tables. Read them in order once; read the models in `app/Models` for the current shape.

| Migration group | What it creates |
| --- | --- |
| `0001_01_01_*` | users, cache, jobs (Laravel defaults) |
| `2026_07_14_*` | recipes, ingredients, pantry_items, meal_plans, shopping_lists, tokens |
| `2026_07_21_*` | profiles, families, family members, favourites/reviews, meal history |
| `2026_07_28_*` | household profiles, pantry freshness and usage, invitations |
| `2026_07_29_*` / `2026_07_30_*` | nutrition foods, meal plan batches (drafts), catalogue, package conversions, child plans |
| `2026_08_*` / `2026_10_*` | admin flag, image clean-up, purchase suggestions |
| `2026_11_19_000001_add_performance_indexes` | indexes described below |

## Table ownership

| Table | Scope | Notes |
| --- | --- | --- |
| `pantry_items` | `user_id` + nullable `family_id` | `freshness_status`: fresh, review, spoiled, used, discarded |
| `meal_plans` | `user_id` + nullable `family_id` + nullable `meal_plan_batch_id` | `status`: draft, scheduled, completed. `planned_date` has a `date` cast |
| `meal_plan_batches` | `user_id` + nullable `family_id` | `status`: draft, saved, discarded |
| `shopping_lists` | `user_id` + nullable `family_id` | purchase suggestion columns added later |
| `meal_history` | `user_id` + nullable `family_id` | one row per cooked meal |
| `recipes`, `ingredients` | global | read through `RecipeCatalog` cache |
| `ingredient_catalog` | global | approved names and aliases |
| `ingredient_package_conversions` | per user or family | e.g. `1 pack = 250 g` |

## Indexes

SQLite does **not** index foreign key columns automatically, and `constrained()` does not add an index. Without these, every `with('ingredients')` and every "my plans" list scans the table.

| Index | Serves |
| --- | --- |
| `ingredients(recipe_id)` | loading a recipe's ingredients (every recipe endpoint) |
| `pantry_items(user_id, family_id, freshness_status)` | pantry list and matching input |
| `meal_plans(user_id)`, `(family_id)`, `(recipe_id)`, `(meal_plan_batch_id)` | plan lists, batch loads, recipe joins |
| `shopping_lists(user_id, family_id)` | shopping list |
| `family_members(user_id, status)` | "is this user an accepted member" check, run on most requests |
| `meal_history(user_id, family_id)` | history list |
| `recipe_reviews(recipe_id)` | reviews and rating |

Pre-existing: `meal_plans(status, planned_date)`, `meal_plan_batches(user_id, status)`, `(family_id, status)`, and the unique indexes on members, profiles and conversions.

The migration is guarded: it skips an index that already exists and can be re-run safely. To add one, add a line to `indexes()` in that file or write a new migration.

## Gotcha: `planned_date`

`planned_date` is a `date` cast, which Eloquent writes as `Y-m-d 00:00:00`. Filtering with `where('planned_date', '<=', '2026-11-20')` would **drop** rows on the 20th. That is why `MealPlanController` uses `whereDate(...)`. It compiles to `strftime()` and cannot use an index. At current volumes (tens of rows per user) this is fine; if it matters, store a plain `Y-m-d` string and switch to range comparisons in one migration.

## SQLite settings

`config/database.php` now sets WAL journal mode, `synchronous=NORMAL` and a 5 s busy timeout (override with `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_BUSY_TIMEOUT`). WAL lets reads proceed during a write and cuts the fsync cost of each write.

## Exercises

1. Run `php artisan tinker` and `DB::select("explain query plan select * from ingredients where recipe_id = 1")`. Confirm it says `USING INDEX`.
2. Find every controller that reads `family_members`. How many copy the same membership check?
