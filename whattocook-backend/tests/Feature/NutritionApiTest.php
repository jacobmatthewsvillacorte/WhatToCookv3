<?php

namespace Tests\Feature;

use App\Models\NutritionFood;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NutritionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_macros_and_plan_summary_are_scaled_to_planned_servings(): void
    {
        $user = User::factory()->create();
        $food = NutritionFood::create(['description' => 'Chicken', 'normalized_name' => 'chicken', 'source' => 'local', 'nutrients' => ['calories' => 200, 'protein' => 30, 'carbs' => 0, 'fat' => 8, 'fiber' => 0, 'sodium' => 50, 'sugar' => 0]]);
        $recipe = Recipe::create(['name' => 'Chicken meal', 'instructions' => 'Cook.', 'servings' => 2, 'created_by' => $user->id]);
        $ingredient = $recipe->ingredients()->create(['name' => 'Chicken', 'quantity' => '200', 'unit' => 'g', 'nutrition_food_id' => $food->id]);

        $this->actingAs($user, 'sanctum')->getJson("/api/recipes/{$recipe->id}/nutrition")
            ->assertOk()->assertJsonPath('totals.calories', 400)->assertJsonPath('per_serving.protein', 30)->assertJsonPath('is_complete', true);

        $this->actingAs($user, 'sanctum')->putJson("/api/recipes/{$recipe->id}/ingredients/{$ingredient->id}/nutrition", ['nutrition_food_id' => $food->id, 'nutrition_grams' => 250])
            ->assertOk()->assertJsonPath('per_serving.calories', 250);

        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', ['recipe_id' => $recipe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 4])->assertCreated();
        $this->actingAs($user, 'sanctum')->getJson('/api/meal-plans/nutrition?start_date=2026-08-03&end_date=2026-08-03')
            ->assertOk()->assertJsonPath('meal_count', 1)->assertJsonPath('totals.calories', 1000)->assertJsonPath('totals.protein', 150);
    }

    public function test_nutrition_is_clearly_incomplete_when_a_food_or_gram_conversion_is_missing(): void
    {
        $user = User::factory()->create();
        $food = NutritionFood::create(['description' => 'Rice', 'normalized_name' => 'rice', 'source' => 'local', 'nutrients' => ['calories' => 130, 'protein' => 2, 'carbs' => 28, 'fat' => 0, 'fiber' => 0, 'sodium' => 0, 'sugar' => 0]]);
        $recipe = Recipe::create(['name' => 'Incomplete nutrition', 'instructions' => 'Cook.', 'created_by' => $user->id]);
        $recipe->ingredients()->create(['name' => 'Rice', 'quantity' => '1', 'unit' => 'cup', 'nutrition_food_id' => $food->id]);
        $recipe->ingredients()->create(['name' => 'Salt', 'quantity' => '1', 'unit' => 'g']);

        $this->actingAs($user, 'sanctum')->getJson("/api/recipes/{$recipe->id}/nutrition")
            ->assertOk()->assertJsonPath('is_complete', false)
            ->assertJsonCount(2, 'unmatched_ingredients')
            ->assertJsonPath('unmatched_ingredients.0.reason', 'quantity_cannot_be_converted_to_grams')
            ->assertJsonPath('unmatched_ingredients.1.reason', 'nutrition_food_not_linked');
    }

    public function test_partial_usda_nutrient_coverage_is_not_presented_as_complete(): void
    {
        $user = User::factory()->create();
        $food = NutritionFood::create([
            'description' => 'Partial food', 'normalized_name' => 'partial food', 'source' => 'usda',
            'nutrients' => ['calories' => 100, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'fiber' => 0, 'sodium' => 0, 'sugar' => 0, 'available_nutrients' => ['calories']],
        ]);
        $recipe = Recipe::create(['name' => 'Partial nutrition', 'instructions' => 'Cook.', 'created_by' => $user->id]);
        $recipe->ingredients()->create(['name' => 'Partial food', 'quantity' => '100', 'unit' => 'g', 'nutrition_food_id' => $food->id]);

        $this->actingAs($user, 'sanctum')->getJson("/api/recipes/{$recipe->id}/nutrition")
            ->assertOk()->assertJsonPath('is_complete', true)->assertJsonPath('is_nutrition_complete', false)
            ->assertJsonPath('data_status', 'partial')->assertJsonPath('unknown_nutrients.protein.0', 'Partial food');
    }

    public function test_meal_plan_summary_is_incomplete_when_a_linked_food_lacks_nutrient_fields(): void
    {
        $user = User::factory()->create();
        $food = NutritionFood::create([
            'description' => 'Calories only', 'normalized_name' => 'calories only', 'source' => 'usda',
            'nutrients' => ['calories' => 100, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'fiber' => 0, 'sodium' => 0, 'sugar' => 0, 'available_nutrients' => ['calories']],
        ]);
        $recipe = Recipe::create(['name' => 'Partial plan meal', 'instructions' => 'Cook.', 'servings' => 1, 'created_by' => $user->id]);
        $recipe->ingredients()->create(['name' => 'Calories only', 'quantity' => '100', 'unit' => 'g', 'nutrition_food_id' => $food->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', ['recipe_id' => $recipe->id, 'planned_date' => '2026-08-04', 'meal_type' => 'dinner', 'servings' => 1])->assertCreated();

        $this->actingAs($user, 'sanctum')->getJson('/api/meal-plans/nutrition?start_date=2026-08-04&end_date=2026-08-04')
            ->assertOk()->assertJsonPath('is_nutrition_complete', false)->assertJsonPath('data_status', 'incomplete')
            ->assertJsonPath('incomplete_meals.0.unknown_nutrients.protein.0', 'Calories only');
    }

    public function test_usda_food_is_cached_and_linked_to_an_ingredient_server_side(): void
    {
        $user = User::factory()->create();
        $recipe = Recipe::create(['name' => 'USDA linked meal', 'instructions' => 'Cook.', 'created_by' => $user->id]);
        $ingredient = $recipe->ingredients()->create(['name' => 'Chicken', 'quantity' => '100', 'unit' => 'g']);
        config()->set('services.usda.key', 'test-key');
        Http::fake(['https://api.nal.usda.gov/fdc/v1/food/123*' => Http::response([
            'fdcId' => 123, 'description' => 'Chicken breast',
            'foodNutrients' => [['nutrient' => ['id' => 1008, 'name' => 'Energy'], 'amount' => 165]],
        ])]);

        $this->actingAs($user, 'sanctum')->putJson("/api/recipes/{$recipe->id}/ingredients/{$ingredient->id}/nutrition", ['fdc_id' => 123, 'nutrition_grams' => 100])
            ->assertOk()->assertJsonPath('is_complete', true)->assertJsonPath('totals.calories', 165);
        $this->assertDatabaseHas('nutrition_foods', ['fdc_id' => 123, 'description' => 'Chicken breast']);
        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id, 'nutrition_grams' => 100]);
    }

    public function test_usda_calories_use_kcal_when_the_food_record_also_contains_kilojoules(): void
    {
        config()->set('services.usda.key', 'test-key');
        $service = app(\App\Services\UsdaFoodDataService::class);

        $nutrients = $service->normalizeNutrients([
            ['nutrient' => ['id' => 1062, 'name' => 'Energy'], 'amount' => 690],
            ['nutrient' => ['id' => 1008, 'name' => 'Energy'], 'amount' => 165],
        ]);

        $this->assertSame(165.0, $nutrients['calories']);
    }
}
