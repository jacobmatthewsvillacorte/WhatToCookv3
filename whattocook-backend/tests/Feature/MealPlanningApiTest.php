<?php

namespace Tests\Feature;

use App\Models\FamilyMember;
use App\Models\HouseholdProfile;
use App\Models\MealPlan;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_family_meal_keeps_diners_and_servings_and_is_visible_to_members(): void
    {
        [$owner, $member, $family] = $this->family();
        $diner = HouseholdProfile::create(['family_id' => $family['id'], 'name' => 'Mika']);
        $recipe = $this->recipe($owner, 'Tinola');

        $plan = $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $recipe->id, 'family_id' => $family['id'], 'planned_date' => '2026-08-03', 'meal_type' => 'dinner',
            'servings' => 3, 'diner_profile_ids' => [$diner->id],
        ])->assertCreated()->assertJsonPath('servings', 3)->assertJsonPath('diner_profile_ids.0', $diner->id)->json();

        $this->actingAs($member, 'sanctum')->getJson("/api/meal-plans?family_id={$family['id']}&start_date=2026-08-03&end_date=2026-08-03")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $plan['id']);
    }

    public function test_two_week_batch_generation_filters_unsafe_recipes(): void
    {
        [$owner, , $family] = $this->family();
        $diner = HouseholdProfile::create(['family_id' => $family['id'], 'name' => 'Ana', 'allergies' => ['peanut']]);
        $safe = $this->recipe($owner, 'Safe Recipe', 'Chicken');
        $this->recipe($owner, 'Unsafe Recipe', 'Peanut');

        $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'family_id' => $family['id'], 'start_date' => '2026-08-03', 'end_date' => '2026-08-16',
            'diner_profile_ids' => [$diner->id], 'meal_types' => ['dinner'],
        ])->assertCreated()
            ->assertJsonCount(14, 'meal_plans')
            ->assertJsonPath('meal_plans.0.recipe_id', $safe->id)
            ->assertJsonMissing(['name' => 'Unsafe Recipe']);
        $this->assertDatabaseMissing('meal_plans', ['recipe_id' => Recipe::where('name', 'Unsafe Recipe')->value('id')]);
    }

    public function test_cooking_scales_pantry_deduction_to_planned_servings(): void
    {
        $user = User::factory()->create();
        $recipe = $this->recipe($user, 'Rice Meal', 'Rice', '1', 'kg', 2);
        $item = PantryItem::create(['user_id' => $user->id, 'name' => 'Rice', 'quantity' => '3', 'quantity_value' => 3, 'unit' => 'kg', 'freshness_status' => 'fresh']);
        $plan = $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $recipe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 4,
        ])->assertCreated()->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/meal-plans/{$plan['id']}/complete")
            ->assertOk()->assertJsonPath('meal_plan.consumed_items.0.quantity', 2);
        $this->assertDatabaseHas('pantry_items', ['id' => $item->id, 'quantity_value' => 1]);
    }

    public function test_a_solo_plan_uses_the_users_profile_and_rejects_an_unsafe_recipe(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['allergies' => ['shrimp']]);
        $unsafe = $this->recipe($user, 'Shrimp Sinigang', 'Shrimp');
        $safe = $this->recipe($user, 'Munggo', 'Mung beans');

        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $unsafe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1,
        ])->assertUnprocessable()->assertJsonPath('errors.recipe_id.0', 'This recipe conflicts with the active diner profile or dietary restrictions.');

        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $safe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1,
        ])->assertCreated()->assertJsonPath('family_id', null);
    }

    public function test_a_family_meal_cannot_double_book_a_linked_diners_personal_meal(): void
    {
        [$owner, $member, $family] = $this->family();
        $diner = HouseholdProfile::create(['family_id' => $family['id'], 'user_id' => $member->id, 'name' => $member->name]);
        $recipe = $this->recipe($owner, 'Tinola');
        MealPlan::create(['user_id' => $member->id, 'recipe_id' => $recipe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1, 'status' => 'scheduled']);

        $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $recipe->id, 'family_id' => $family['id'], 'planned_date' => '2026-08-03', 'meal_type' => 'dinner',
            'servings' => 2, 'diner_profile_ids' => [$diner->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('personal_conflicts');
    }

    public function test_child_safety_uses_the_scheduled_meal_date_not_todays_profile_age(): void
    {
        [$owner, , $family] = $this->family();
        // This diner is a young child now but is an adult on the scheduled
        // date. The date, not a cached/current age band, governs the rule.
        $diner = HouseholdProfile::create([
            'family_id' => $family['id'], 'name' => 'Ari', 'relation' => 'child', 'birth_date' => '2026-07-01',
        ]);
        $spicy = $this->recipe($owner, 'Spicy Adult Ulam', 'siling labuyo');

        $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $spicy->id, 'family_id' => $family['id'], 'planned_date' => '2032-08-03', 'meal_type' => 'dinner',
            'servings' => 1, 'diner_profile_ids' => [$diner->id],
        ])->assertCreated();
    }

    public function test_manual_plans_apply_halal_and_vegetarian_taxonomy(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['dietary_restrictions' => ['halal', 'vegetarian']]);
        $pork = $this->recipe($user, 'Pork Adobo', 'pork belly');
        $alcohol = $this->recipe($user, 'Wine Sauce', 'cooking wine');
        $fish = $this->recipe($user, 'Fish Soup', 'fish');
        $safe = $this->recipe($user, 'Vegetable Soup', 'squash');

        foreach ([$pork, $alcohol, $fish] as $unsafe) {
            $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', [
                'recipe_id' => $unsafe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors('recipe_id');
        }
        $this->actingAs($user, 'sanctum')->postJson('/api/meal-plans', [
            'recipe_id' => $safe->id, 'planned_date' => '2026-08-03', 'meal_type' => 'dinner', 'servings' => 1,
        ])->assertCreated();
    }

    public function test_batch_generation_applies_vegetarian_taxonomy(): void
    {
        [$owner, , $family] = $this->family();
        $diner = HouseholdProfile::create(['family_id' => $family['id'], 'name' => 'Mika', 'dietary_restrictions' => ['vegetarian']]);
        $safe = $this->recipe($owner, 'Vegetable Munggo', 'mung beans');
        $this->recipe($owner, 'Chicken Tinola', 'chicken');

        $this->actingAs($owner, 'sanctum')->postJson('/api/meal-plan-batches/generate', [
            'family_id' => $family['id'], 'start_date' => '2026-08-03', 'end_date' => '2026-08-09',
            'diner_profile_ids' => [$diner->id], 'meal_types' => ['dinner'],
        ])->assertCreated()->assertJsonPath('meal_plans.0.recipe_id', $safe->id);
    }

    private function family(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Santos'])->json();
        FamilyMember::create(['family_id' => $family['id'], 'user_id' => $member->id, 'role' => 'member', 'status' => 'accepted']);

        return [$owner, $member, $family];
    }

    private function recipe(User $user, string $name, string $ingredient = 'Rice', string $quantity = '1', string $unit = 'cup', int $servings = 2): Recipe
    {
        $recipe = Recipe::create(['name' => $name, 'instructions' => 'Cook.', 'servings' => $servings, 'created_by' => $user->id]);
        $recipe->ingredients()->create(['name' => $ingredient, 'quantity' => $quantity, 'unit' => $unit]);

        return $recipe;
    }
}
