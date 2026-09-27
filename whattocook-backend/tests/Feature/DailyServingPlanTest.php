<?php

namespace Tests\Feature;

use App\Models\NutritionFood;
use App\Models\User;
use App\Services\DailyServingPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyServingPlanTest extends TestCase
{
    use RefreshDatabase;

    private function foods(): array
    {
        $values = [];
        foreach (['rice_staple' => ['Rice', 130, 28], 'ulam_protein' => ['Chicken', 165, 0], 'fruit' => ['Apple', 52, 14], 'vegetable' => ['Carrot', 41, 10]] as $group => [$name, $calories, $carbs]) {
            $food = NutritionFood::create(['description' => $name, 'normalized_name' => strtolower($name).'-'.uniqid(), 'source' => 'local', 'nutrients' => ['calories' => $calories, 'protein' => 5, 'carbs' => $carbs, 'fat' => 2]]);
            $values[$group] = ['nutrition_food_id' => $food->id];
        }

        return $values;
    }

    private function inputs(array $extra = []): array
    {
        return array_merge(['age' => 30, 'sex' => 'female', 'weight_kg' => 65, 'height_cm' => 165, 'activity_level' => 'moderate', 'goal' => 'maintain', 'foods' => $this->foods()], $extra);
    }

    public function test_normal_inputs_return_sane_targets_and_servings_for_each_group(): void
    {
        $result = app(DailyServingPlanner::class)->plan($this->inputs());
        $this->assertGreaterThan(1200, $result['daily_calories']);
        $this->assertCount(4, $result['per_group']);
        $this->assertTrue(collect($result['per_group'])->every(fn ($group) => $group['servings_per_day'] > 0));
        $this->assertStringContainsString('not medical advice', $result['disclaimer']);
    }

    public function test_diabetic_condition_reduces_the_rice_staple_target_share(): void
    {
        $planner = app(DailyServingPlanner::class);
        $normal = $planner->plan($this->inputs());
        $diabetic = $planner->plan($this->inputs(['health_conditions' => ['diabetic']]));
        $this->assertLessThan($normal['per_group'][0]['target_calories'], $diabetic['per_group'][0]['target_calories']);
        $this->assertLessThan($normal['macro_targets']['carbs_g'], $diabetic['macro_targets']['carbs_g']);
    }

    public function test_missing_or_nutritionally_incomplete_food_is_explicitly_incomplete(): void
    {
        $foods = $this->foods();
        $partial = NutritionFood::create(['description' => 'Partial', 'normalized_name' => 'partial', 'source' => 'usda', 'nutrients' => ['calories' => 100, 'available_nutrients' => ['calories']]]);
        $foods['rice_staple'] = ['nutrition_food_id' => $partial->id];
        unset($foods['fruit']);
        $result = app(DailyServingPlanner::class)->plan($this->inputs(['foods' => $foods]));
        $this->assertSame('incomplete', $result['per_group'][0]['data_status']);
        $this->assertNull($result['per_group'][0]['servings_per_day']);
        $this->assertSame('incomplete', $result['per_group'][2]['data_status']);
        $this->assertNull($result['per_group'][2]['servings_per_day']);
    }

    public function test_admin_can_open_and_calculate_daily_plan(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->get('/admin/nutrition/daily-plan')->assertOk();
        $this->actingAs($user)->postJson('/admin/nutrition/daily-plan', $this->inputs())->assertOk()->assertJsonPath('is_complete', true);
    }
}
