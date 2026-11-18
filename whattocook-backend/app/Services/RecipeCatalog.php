<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The full recipe list (with ingredients) is read by recommendations, search
 * and every meal-plan generation, but changes only when an admin edits a
 * recipe. It is cached forever and flushed by model events registered in
 * AppServiceProvider. See docs/11-recipe-matching-and-caching.md.
 *
 * Callers must treat the result as read-only.
 * Bulk writes that bypass model events (DB::table()->insert, seeders) must
 * call RecipeCatalog::flush() themselves.
 */
class RecipeCatalog
{
    private const KEY = 'recipes.catalog.v2';

    /** Per-process copy so a request rebuilds the models at most once. */
    private static ?Collection $local = null;

    /** @return Collection<int, Recipe> */
    public static function all(): Collection
    {
        if (self::$local !== null) {
            return self::$local;
        }

        // Cache plain arrays, never models: cache stores refuse to unserialise
        // objects (config/cache.php serializable_classes=false), and models
        // would come back as __PHP_Incomplete_Class.
        $rows = Cache::rememberForever(self::KEY, fn () => Recipe::with('ingredients')->get()
            ->map(fn (Recipe $recipe) => [
                'recipe' => $recipe->getAttributes(),
                'ingredients' => $recipe->ingredients->map(fn (Ingredient $ingredient) => $ingredient->getAttributes())->all(),
            ])->all());

        return self::$local = new Collection(array_map(function (array $row) {
            $recipe = (new Recipe)->newFromBuilder($row['recipe']);
            $ingredient = new Ingredient;
            $recipe->setRelation('ingredients', $ingredient->newCollection(
                array_map(fn (array $attributes) => $ingredient->newFromBuilder($attributes), $row['ingredients']),
            ));

            return $recipe;
        }, $rows));
    }

    public static function flush(): void
    {
        self::$local = null;
        Cache::forget(self::KEY);
    }

    /** For tests: drop the in-process copy only, so the next read hits the cache store. */
    public static function forgetLocalCopy(): void
    {
        self::$local = null;
    }

    /** Wire invalidation; called once from AppServiceProvider::boot(). */
    public static function registerInvalidation(): void
    {
        foreach ([Recipe::class, Ingredient::class] as $model) {
            $model::saved(fn () => self::flush());
            $model::deleted(fn () => self::flush());
        }
    }
}
