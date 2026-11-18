<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the latency work in docs/14-performance-budget.md. We assert query
 * COUNTS (stable in CI) rather than milliseconds (flaky). If a change makes
 * these fail, it has reintroduced an N+1.
 */
class PerformanceBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendations_query_count_does_not_grow_with_catalog_or_pantry_size(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([]);

        for ($i = 0; $i < 30; $i++) {
            $recipe = Recipe::create(['name' => "Recipe $i", 'description' => 'x', 'meal_type' => 'lunch', 'prep_time' => 5, 'cook_time' => 5, 'servings' => 2, 'difficulty' => 'easy', 'instructions' => 'x']);
            // Pantry stores kg; recipes ask for g and "pack" so unit conversion paths run.
            $recipe->ingredients()->create(['name' => "item $i", 'quantity' => '100', 'unit' => 'g']);
            PantryItem::create(['user_id' => $user->id, 'name' => "item $i", 'quantity' => '1', 'quantity_value' => 1, 'unit' => 'pack', 'freshness_status' => 'fresh']);
        }
        RecipeCatalog::flush();

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $this->actingAs($user, 'sanctum')->getJson('/api/recipes/recommendations')->assertOk();

        // Auth + profile + pantry + catalog(2) + one conversion lookup per
        // distinct pantry item (cached per request). Budget is deliberately tight.
        $this->assertLessThanOrEqual(5 + 30, $queries, "recommendations ran $queries queries");

        $queries = 0;
        $this->actingAs($user, 'sanctum')->getJson('/api/recipes/recommendations')->assertOk();
        $this->assertLessThanOrEqual(35, $queries);
    }

    public function test_catalog_cache_is_flushed_when_a_recipe_changes(): void
    {
        $recipe = Recipe::create(['name' => 'Before', 'description' => 'x', 'meal_type' => 'lunch', 'prep_time' => 1, 'cook_time' => 1, 'servings' => 1, 'difficulty' => 'easy', 'instructions' => 'x']);
        $this->assertSame('Before', RecipeCatalog::all()->first()->name);

        $recipe->update(['name' => 'After']);

        $this->assertSame('After', RecipeCatalog::all()->first()->name);
    }

    /**
     * Regression: the array cache store never serialises, so it hid a production
     * failure. Real stores (file, redis, database) refuse to unserialise objects
     * (config/cache.php serializable_classes=false), so the catalogue must cache
     * plain arrays and rebuild models itself.
     */
    public function test_catalog_survives_a_serialising_cache_store(): void
    {
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => storage_path('framework/testing/catalog-'.uniqid()),
        ]);
        app('cache')->forgetDriver('file');

        $recipe = Recipe::create(['name' => 'Cached', 'description' => 'x', 'meal_type' => 'lunch', 'prep_time' => 1, 'cook_time' => 1, 'servings' => 1, 'difficulty' => 'easy', 'instructions' => 'x']);
        $recipe->ingredients()->create(['name' => 'rice', 'quantity' => '1', 'unit' => 'cup']);
        RecipeCatalog::flush();

        RecipeCatalog::all();               // fills the cache
        RecipeCatalog::forgetLocalCopy();   // force the next read to come from the store
        $recipes = RecipeCatalog::all();

        $this->assertInstanceOf(Recipe::class, $recipes->first());
        $this->assertSame('Cached', $recipes->first()->name);
        $this->assertInstanceOf(Ingredient::class, $recipes->first()->ingredients->first());
        $this->assertSame('rice', $recipes->first()->ingredients->first()->name);
        RecipeCatalog::flush();
    }
}
