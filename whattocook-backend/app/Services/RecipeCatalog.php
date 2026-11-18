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
    private const KEY = 'recipes.catalog.v1';

    /** @return Collection<int, Recipe> */
    public static function all(): Collection
    {
        return Cache::rememberForever(self::KEY, fn () => Recipe::with('ingredients')->get());
    }

    public static function flush(): void
    {
        Cache::forget(self::KEY);
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
