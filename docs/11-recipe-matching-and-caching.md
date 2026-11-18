# Recipe matching and caching

**Read this on day 3.** It covers the hottest code path in the app: turning "recipes + pantry" into "what can I cook".

## Read in this order

1. `app/Services/FoodSafetyTaxonomy.php` - which recipes are forbidden for a set of profiles.
2. `app/Services/RecipeMatcher.php` - pantry coverage and unit conversion.
3. `app/Services/RecipeCatalog.php` - the cache.
4. `RecipeController::recommendations` and `::index` - how they are combined.

## The pipeline for `GET /recipes/recommendations`

```text
RecipeCatalog::all()            all recipes + ingredients (cached)
  -> FoodSafetyTaxonomy         drop recipes that conflict with the profiles
  -> RecipeMatcher::match       per recipe: available / needs review / missing + percent
  -> sortByDesc(match_percentage)
```

The pantry is loaded once for the active scope (personal or family), only `fresh` and `review` items.

## RecipeMatcher in short

- **Name match** (`isIngredientMatch`): normalised names match if equal, if one contains the other (`chicken breast` / `chicken`), or if they share a word longer than 2 letters. This is deliberately loose for *pantry coverage*. It is **not** the safety check: safety uses the stricter word-aware `FoodSafetyTaxonomy`, so "eggplant" never trips an egg allergy.
- **Lots:** several pantry rows for one ingredient are summed in the recipe unit.
- **Units:** weight converts within g/kg, volume within mL/L. Anything else needs a `ingredient_package_conversions` row for that scope, or the ingredient is *Needs review*. The matcher never guesses.
- **Same logic for cooking:** `matchingPantryItemsFor` and `quantityFromUnit` are used again at cook time so deduction removes exactly what preflight promised.

## Latency fixes in this layer

| Problem | Fix |
| --- | --- |
| `packageConversionFor` ran one SQL query per pantry item per ingredient (hundreds of queries on recommendations) | Results are memoised per matcher instance, keyed by `user|family|ingredient|unit`. One query per distinct item per request |
| Every endpoint ran `Recipe::with('ingredients')->get()` (about 100 recipes plus all ingredients) | `RecipeCatalog::all()` caches the collection forever |
| Batch generation reloaded the catalogue, and the household roster twice, for every day of the range | Loaded once before the day loop in `MealPlanBatchController::generate` |

## RecipeCatalog rules

- Cache key `recipes.catalog.v1`, store from `CACHE_STORE` (file in dev/prod, array in tests).
- **Invalidation:** `AppServiceProvider::boot()` calls `RecipeCatalog::registerInvalidation()`, which flushes on `saved` and `deleted` of `Recipe` and `Ingredient`. Admin edits, image upload and nutrition linking all go through models, so they flush automatically.
- **You must call `RecipeCatalog::flush()` yourself** if you write recipes with `DB::table()`, `insert()`, `upsert()` or mass `update()` on a query builder. Model events do not fire for those. Seeders are the usual culprit.
- Results are shared objects. **Do not mutate** models you get from `all()`; clone or map to arrays.
- The cache stores about 100 recipes. If the catalogue grows to thousands, replace it with SQL-side filtering (see [14-performance-budget.md](14-performance-budget.md)).

## Tests

- `RecipeDiscoveryApiTest`, `MealPlanBatchApiTest`, `MealPlanCookingPreflightApiTest` guard behaviour.
- `PerformanceBudgetTest` guards query counts and cache invalidation.
