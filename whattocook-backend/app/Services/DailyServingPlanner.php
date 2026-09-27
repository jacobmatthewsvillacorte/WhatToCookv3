<?php

namespace App\Services;

use App\Models\HouseholdProfile;
use App\Models\IngredientCatalog;
use App\Models\NutritionFood;

class DailyServingPlanner
{
    private const GROUPS = ['rice_staple', 'ulam_protein', 'fruit', 'vegetable'];
    private const DISCLAIMER = 'Nutrition is an estimate from linked food records, not medical advice. Do not use partial results for medical decisions.';

    public function plan(array $input): array
    {
        $profile = isset($input['profile_id']) ? HouseholdProfile::findOrFail($input['profile_id']) : null;
        $age = (int) ($input['age'] ?? ($profile?->birth_date?->age ?? 0));
        $sex = strtolower((string) ($input['sex'] ?? $profile?->sex ?? ''));
        $weight = (float) ($input['weight_kg'] ?? $profile?->weight_kg ?? 0);
        $height = (float) ($input['height_cm'] ?? $profile?->height_cm ?? 0);
        $activity = strtolower((string) ($input['activity_level'] ?? $profile?->activity_level ?? 'moderate'));
        $goal = strtolower((string) ($input['goal'] ?? $profile?->goal ?? 'maintain'));
        $conditions = $input['health_conditions'] ?? $profile?->health_conditions ?? [];
        if (is_string($conditions)) $conditions = array_filter(array_map('trim', explode(',', $conditions)));
        $bmr = 10 * $weight + 6.25 * $height - 5 * $age + ($sex === 'male' ? 5 : -161);
        $adjustment = str_contains($goal, 'loss') ? -500 : (str_contains($goal, 'gain') ? 300 : 0);
        $rawCalories = $bmr * (['sedentary' => 1.2, 'light' => 1.375, 'lightly_active' => 1.375, 'moderate' => 1.55, 'moderately_active' => 1.55, 'active' => 1.725, 'very_active' => 1.725, 'extra_active' => 1.9][$activity] ?? 1.55) + $adjustment;
        $calories = max(1200, round($rawCalories));
        $capped = $rawCalories < 1200;
        $diabetic = collect($conditions)->contains(fn ($condition) => str_contains(strtolower((string) $condition), 'diabet'));
        $ratios = $diabetic ? ['carbs' => .35, 'protein' => .25, 'fat' => .40] : ['carbs' => .50, 'protein' => .20, 'fat' => .30];
        $shares = $diabetic ? ['rice_staple' => .25, 'ulam_protein' => .35, 'fruit' => .15, 'vegetable' => .25] : ['rice_staple' => .40, 'ulam_protein' => .30, 'fruit' => .15, 'vegetable' => .15];

        $groups = [];
        $foods = $input['foods'] ?? [];
        foreach (self::GROUPS as $group) {
            $food = null;
            $foodId = $foods[$group]['nutrition_food_id'] ?? $foods[$group]['food_id'] ?? null;
            $fdcId = $foods[$group]['fdc_id'] ?? null;
            if ($foodId) $food = NutritionFood::find($foodId);
            elseif ($fdcId) $food = NutritionFood::where('fdc_id', $fdcId)->first();
            if (! $food) $food = NutritionFood::whereIn('normalized_name', IngredientCatalog::where('food_group', $group)->pluck('canonical_name'))->orderBy('id')->first();
            $grams = (float) ($foods[$group]['serving_size_g'] ?? ($group === 'rice_staple' ? 150 : ($group === 'ulam_protein' ? 100 : 80)));
            $nutrients = $food?->nutrients ?? [];
            $available = $nutrients['available_nutrients'] ?? ($food && $food->source !== 'usda' ? array_keys($nutrients) : []);
            $complete = $food && in_array('calories', $available, true) && in_array('carbs', $available, true);
            $caloriesPerServing = $complete ? (float) $nutrients['calories'] * $grams / 100 : null;
            $groups[] = [
                'food_group' => $group, 'food_name' => $food?->description, 'fdc_id' => $food?->fdc_id,
                'serving_size_g' => $grams, 'target_calories' => round($calories * $shares[$group], 2),
                'servings_per_day' => $complete && $caloriesPerServing > 0 ? round($calories * $shares[$group] / $caloriesPerServing, 2) : null,
                'calories_per_serving' => $caloriesPerServing ? round($caloriesPerServing, 2) : null,
                'data_status' => $complete ? 'complete' : 'incomplete',
                'reason' => ! $food ? 'nutrition_food_not_linked' : (! $complete ? 'required_nutrients_missing' : null),
            ];
        }

        return ['daily_calories' => (int) $calories, 'calorie_target_capped' => $capped, 'macro_targets' => ['protein_g' => round($calories * $ratios['protein'] / 4, 1), 'carbs_g' => round($calories * $ratios['carbs'] / 4, 1), 'fat_g' => round($calories * $ratios['fat'] / 9, 1)], 'per_group' => $groups, 'is_complete' => collect($groups)->every(fn ($group) => $group['data_status'] === 'complete'), 'data_status' => collect($groups)->every(fn ($group) => $group['data_status'] === 'complete') ? 'complete' : 'incomplete', 'disclaimer' => self::DISCLAIMER];
    }
}
