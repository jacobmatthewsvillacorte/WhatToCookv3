# Start here: one-week onboarding roadmap

WhatToCook helps Filipino households turn pantry stock into safe, reviewable meal plans. It is an **Ionic/Angular app** (`frontend/`) talking JSON to a **Laravel API** (`whattocook-backend/`).

If you read the documents below in order, you will understand the whole system in five working days and be shipping a small change by day 7. Every document has its own "read in this order" list, so you never have to guess which source file to open next.

## The three ideas that explain everything

1. **Scope.** All data is either *personal* (`user_id`, no `family_id`) or *family* (`family_id`). Pantry, shopping list, meal plans and history never mix the two. Most controller code exists to enforce this.
2. **Safety before ranking.** A recipe containing an allergen, a dietary conflict or something unsafe for a young child is removed *before* anything is scored.
3. **Draft, then save.** Meal-plan generation creates a **draft batch** that the user reviews. Only *Save* turns drafts into scheduled meals. Cooking then deducts pantry stock and writes history.

## Day-by-day

| Day | Goal | Read | Then do |
| --- | --- | --- | --- |
| 1 | Run it and click the happy path | [01-setup-run-test.md](01-setup-run-test.md) | Register, add 3 pantry items, generate a plan, cook one meal |
| 2 | Know the data | [02-architecture.md](02-architecture.md), [10-database-and-indexes.md](10-database-and-indexes.md) | Open `database/migrations` in date order; open each model in `app/Models` |
| 3 | Know the API | [12-api-reference.md](12-api-reference.md), [11-recipe-matching-and-caching.md](11-recipe-matching-and-caching.md) | Call 5 endpoints with curl; read `RecipeMatcher.php` and `FoodSafetyTaxonomy.php` |
| 4 | Know meal planning | [13-meal-planning-lifecycle.md](13-meal-planning-lifecycle.md), [14-performance-budget.md](14-performance-budget.md) | Read `MealPlanBatchController::generate`, `DeterministicMealRanker`, then run `PerformanceBudgetTest` |
| 5 | Know the app shell | [20-frontend-architecture.md](20-frontend-architecture.md) | Trace `plan-setup` to `plan-review` to `meal-details` to `cooking` in the code |
| 6 | Know the look | [21-ui-design-system.md](21-ui-design-system.md) | Change one token in `theme/variables.scss` and watch the app update |
| 7 | Ship something | Run both test suites | Take a starter ticket from "Where to start contributing" below |

## Document map

| File | Answers |
| --- | --- |
| `01-setup-run-test.md` | How do I run, test, demo, release? |
| `02-architecture.md` | What are the parts, the data model, the business rules? |
| `10-database-and-indexes.md` | Which tables exist, which indexes serve which queries? |
| `11-recipe-matching-and-caching.md` | How does matching, unit conversion and the recipe cache work? |
| `12-api-reference.md` | Every endpoint and what it does |
| `13-meal-planning-lifecycle.md` | Draft, review, save, cook, history |
| `14-performance-budget.md` | What the latency target means and how it is enforced |
| `20-frontend-architecture.md` | Routes, pages, services, state |
| `21-ui-design-system.md` | Tokens, utility classes, how to build a page |

## Where to start contributing

- Replace remaining raw `px` values in `auth.page.scss` and the reformatted page styles with tokens (UI, small).
- Move `RecipeController::index` safety filtering into SQL (backend, medium; see doc 14, "Known limits").
- Slim the recommendation response payload (see doc 14, "Known limits", item 1; backend plus frontend, medium).

## Conventions

- Backend tests: `php artisan test`. Frontend: `npm run test:ci`. Both must pass before merge.
- A change that alters behaviour updates the matching doc in the same commit.
