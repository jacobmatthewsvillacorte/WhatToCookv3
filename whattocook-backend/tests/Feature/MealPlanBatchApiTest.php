<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\MealPlanBatch;
use App\Models\HouseholdProfile;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_creates_a_reviewable_draft_without_changing_the_saved_schedule(): void
    {
        $user = User::factory()->create();
        $recipe = $this->recipe($user, 'Chicken Tinola', 'Chicken', '1', 'kg');
        PantryItem::create(['user_id' => $user->id, 'name' => 'Chicken', 'quantity' => '3', 'quantity_value' => 3, 'unit' => 'kg', 'freshness_status' => 'fresh']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-04', 'meal_types' => ['dinner'], 'servings' => 2,
        ])->assertCreated()->assertJsonPath('summary.meal_count', 2)->assertJsonPath('meal_plans.0.status', 'draft');

        $batchId = $response->json('batch.id');
        $this->assertDatabaseCount('meal_plan_batches', 1);
        $this->assertDatabaseCount('meal_plans', 2);
        $this->actingAs($user, 'sanctum')->getJson('/api/meal-plans')->assertOk()->assertJsonCount(0);

        $this->actingAs($user, 'sanctum')->postJson("/api/meal-plan-batches/{$batchId}/save")
            ->assertOk()->assertJsonPath('batch.status', 'saved');
        $this->actingAs($user, 'sanctum')->getJson('/api/meal-plans')->assertOk()->assertJsonCount(2);
        $this->assertDatabaseHas('meal_plans', ['recipe_id' => $recipe->id, 'status' => 'scheduled']);
    }

    public function test_personal_generation_creates_a_draft_for_a_user_without_a_saved_profile(): void
    {
        $user = User::factory()->create();
        $recipe = $this->recipe($user, 'Chicken Tinola', 'Chicken', '1', 'kg');

        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'meal_types' => ['dinner'], 'servings' => 1,
        ])->assertCreated()
            ->assertJsonPath('batch.family_id', null)
            ->assertJsonPath('meal_plans.0.recipe_id', $recipe->id)
            ->assertJsonPath('meal_plans.0.status', 'draft');

        $this->assertDatabaseHas('profiles', ['user_id' => $user->id]);
    }

    public function test_discarding_a_draft_has_no_side_effects_on_saved_meals(): void
    {
        $user = User::factory()->create();
        $recipe = $this->recipe($user, 'Munggo', 'Mung beans', '1', 'cup');
        $saved = MealPlan::create(['user_id' => $user->id, 'recipe_id' => $recipe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1, 'status' => 'scheduled']);

        $batch = $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'meal_types' => ['dinner'], 'servings' => 1,
        ])->assertCreated()->json('batch');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/meal-plan-batches/{$batch['id']}")->assertNoContent();
        $this->assertDatabaseHas('meal_plans', ['id' => $saved->id, 'status' => 'scheduled']);
        $this->assertDatabaseMissing('meal_plans', ['meal_plan_batch_id' => $batch['id']]);
    }

    public function test_legacy_generation_options_remain_readable_on_saved_batches(): void
    {
        $user = User::factory()->create();
        $batch = MealPlanBatch::create([
            'user_id' => $user->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'status' => 'saved',
            'generation_options' => [
                'meal_types' => ['dinner'],
                'cuisine_preferences' => ['Filipino'],
                'available_equipment' => ['stovetop'],
            ],
        ]);

        $this->actingAs($user, 'sanctum')->getJson("/api/meal-plan-batches/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('batch.generation_options.cuisine_preferences.0', 'Filipino')
            ->assertJsonPath('batch.generation_options.available_equipment.0', 'stovetop');
    }

    public function test_a_family_draft_warns_and_cannot_save_when_a_selected_linked_diner_has_a_personal_meal_in_the_same_slot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Santos Family'])->json();
        \App\Models\FamilyMember::create(['family_id' => $family['id'], 'user_id' => $member->id, 'role' => 'member', 'status' => 'accepted']);
        $diner = HouseholdProfile::create(['family_id' => $family['id'], 'user_id' => $member->id, 'name' => $member->name]);
        $recipe = $this->recipe($owner, 'Chicken Tinola', 'Chicken', '1', 'kg');
        MealPlan::create(['user_id' => $member->id, 'recipe_id' => $recipe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1, 'status' => 'scheduled']);

        $batch = $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'family_id' => $family['id'], 'start_date' => '2026-08-03', 'end_date' => '2026-08-03',
            'meal_types' => ['dinner'], 'diner_profile_ids' => [$diner->id],
        ])->assertCreated()
            ->assertJsonPath('personal_conflicts.0.diner_profile_id', $diner->id)
            ->assertJsonPath('personal_conflicts.0.planned_date', '2026-08-03')
            ->json('batch');

        $this->actingAs($owner, 'sanctum')->postJson("/api/meal-plan-batches/{$batch['id']}/save")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('personal_conflicts');
    }

    public function test_draft_ingredient_summary_aggregates_fractional_and_convertible_units(): void
    {
        $user = User::factory()->create();
        $kilogramRecipe = Recipe::create(['name' => 'Half Kilo Rice', 'instructions' => 'Cook.', 'servings' => 2, 'created_by' => $user->id]);
        $kilogramRecipe->ingredients()->create(['name' => 'Rice', 'quantity' => '1/2', 'unit' => 'kg']);
        $gramRecipe = Recipe::create(['name' => 'Five Hundred Gram Rice', 'instructions' => 'Cook.', 'servings' => 2, 'created_by' => $user->id]);
        $gramRecipe->ingredients()->create(['name' => 'Rice', 'quantity' => '500', 'unit' => 'g']);
        PantryItem::create(['user_id' => $user->id, 'name' => 'Rice', 'quantity' => '0.5', 'quantity_value' => .5, 'unit' => 'kg', 'freshness_status' => 'fresh']);

        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-04', 'meal_types' => ['dinner'], 'servings' => 2,
        ])->assertCreated()
            ->assertJsonPath('summary.ingredients.0.unit', 'g')
            ->assertJsonPath('summary.ingredients.0.required_quantity', 1000)
            ->assertJsonPath('summary.ingredients.0.pantry_quantity', 500)
            ->assertJsonPath('summary.ingredients.0.missing_quantity', 500);
    }

    public function test_purchased_items_without_an_expiry_date_are_added_to_the_personal_pantry_and_recheck_the_draft(): void
    {
        $user = User::factory()->create();
        $this->recipe($user, 'Half Kilo Rice', 'Rice', '1/2', 'kg');
        $batch = $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'meal_types' => ['dinner'], 'servings' => 2,
        ])->assertCreated()->json('batch');

        $this->actingAs($user, 'sanctum')->postJson("/api/meal-plan-batches/{$batch['id']}/purchased-items", [
            'items' => [['name' => 'Rice', 'quantity' => 0.5, 'unit' => 'kg', 'storage_type' => 'unknown']],
        ])->assertCreated()
            ->assertJsonPath('items.0.family_id', null)
            ->assertJsonPath('items.0.quantity_value', '0.500')
            ->assertJsonPath('preview.summary.ingredients.0.status', 'ready')
            ->assertJsonPath('preview.summary.shortages', []);

        $this->assertDatabaseHas('pantry_items', ['user_id' => $user->id, 'family_id' => null, 'name' => 'Rice', 'unit' => 'kg']);
    }

    public function test_purchased_items_for_a_household_draft_are_added_to_its_shared_pantry(): void
    {
        $owner = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Santos Family'])->assertCreated()->json();
        $diner = HouseholdProfile::where('family_id', $family['id'])->where('user_id', $owner->id)->firstOrFail();
        $this->recipe($owner, 'Half Kilo Rice', 'Rice', '1/2', 'kg');
        $batch = $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'family_id' => $family['id'], 'start_date' => '2026-08-03', 'end_date' => '2026-08-03',
            'meal_types' => ['dinner'], 'diner_profile_ids' => [$diner->id],
        ])->assertCreated()->json('batch');

        $this->actingAs($owner, 'sanctum')->postJson("/api/meal-plan-batches/{$batch['id']}/purchased-items", [
            'items' => [['name' => 'Rice', 'quantity' => 0.5, 'unit' => 'kg']],
        ])->assertCreated()
            ->assertJsonPath('items.0.family_id', $family['id'])
            ->assertJsonPath('preview.summary.ingredients.0.status', 'ready');

        $this->assertDatabaseHas('pantry_items', ['user_id' => $owner->id, 'family_id' => $family['id'], 'name' => 'Rice', 'unit' => 'kg']);
    }

    public function test_child_exclusions_portions_and_allergies_are_applied_to_each_day(): void
    {
        $owner = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Child-safe household'])->json();
        $child = HouseholdProfile::create([
            'family_id' => $family['id'], 'name' => 'Ari', 'relation' => 'child',
            'birth_date' => '2024-08-01', 'allergies' => ['peanut'],
        ]);
        $safe = $this->recipe($owner, 'Chicken Soup', 'Chicken', '1', 'kg');
        $unsafe = $this->recipe($owner, 'Peanut Stew', 'Peanut butter', '1', 'cup');

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'family_id' => $family['id'], 'start_date' => '2026-07-30', 'end_date' => '2026-07-31',
            'meal_types' => ['dinner'], 'diner_profile_ids' => [$child->id],
            'attendance_by_date' => ['2026-07-30' => [$child->id], '2026-07-31' => []],
            'child_meal_modes' => [$child->id => 'family_meal_with_adaptation'],
        ])->assertCreated();

        $meal = $response->json('meal_plans.0');
        $this->assertSame($safe->id, $meal['recipe_id']);
        $this->assertNotSame($unsafe->id, $meal['recipe_id']);
        $this->assertSame('2-5_years', $meal['child_meal_plan']['children'][0]['age_band']);
        $this->assertSame(0.65, (float) $meal['serving_equivalents']);
        $this->assertNull($response->json('meal_plans.1.diner_profile_ids'));
    }

    public function test_one_two_and_three_requested_meal_slots_are_generated(): void
    {
        $user = User::factory()->create();
        $this->recipe($user, 'Quick Ulam', 'Rice', '1', 'cup');
        foreach ([['breakfast'], ['breakfast', 'lunch'], ['breakfast', 'lunch', 'dinner']] as $types) {
            $response = $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
                'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'meal_types' => $types, 'servings' => 1,
            ])->assertCreated();
            $this->assertCount(count($types), $response->json('meal_plans'));
        }
    }

    public function test_generated_plans_apply_vegan_taxonomy_without_mistaking_eggplant_for_egg(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['dietary_restrictions' => ['vegan']]);
        $safe = $this->recipe($user, 'Eggplant Stew', 'eggplant', '1', 'pc');
        $this->recipe($user, 'Egg Curry', 'egg', '1', 'pc');
        $this->recipe($user, 'Pork Stew', 'pork', '1', 'kg');

        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'meal_types' => ['dinner'], 'servings' => 1,
        ])->assertCreated()->assertJsonPath('meal_plans.0.recipe_id', $safe->id);
    }

    public function test_reuse_ulam_reuses_one_main_and_explains_the_choice(): void
    {
        $user = User::factory()->create(); $this->recipe($user, 'Adobo', 'Chicken', '1', 'kg'); $this->recipe($user, 'Munggo', 'Mung beans', '1', 'cup');
        $meals = $this->actingAs($user, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'meal_types' => ['lunch', 'dinner'], 'servings' => 2,
            'leftover_strategy' => 'reuse_ulam', 'cooking_time_budget' => '60',
        ])->assertCreated()->json('meal_plans');
        $this->assertSame($meals[0]['recipe_id'], $meals[1]['recipe_id']);
        $this->assertTrue(collect($meals)->contains(fn (array $meal) => str_contains(implode(' ', $meal['selection_reason']), 'Reuses today')));
    }

    private function recipe(User $user, string $name, string $ingredient, string $quantity, string $unit): Recipe
    {
        $recipe = Recipe::create(['name' => $name, 'instructions' => 'Cook.', 'servings' => 2, 'created_by' => $user->id]);
        $recipe->ingredients()->create(['name' => $ingredient, 'quantity' => $quantity, 'unit' => $unit]);

        return $recipe;
    }
}
