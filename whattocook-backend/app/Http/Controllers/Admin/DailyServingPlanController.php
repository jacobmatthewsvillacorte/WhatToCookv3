<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HouseholdProfile;
use App\Services\DailyServingPlanner;
use App\Services\UsdaFoodDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DailyServingPlanController extends Controller
{
    public function index(): View
    {
        return view('admin.nutrition.daily-plan', ['profiles' => HouseholdProfile::orderBy('name')->get()]);
    }

    public function store(Request $request, DailyServingPlanner $planner, UsdaFoodDataService $usda): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['nullable', 'integer', 'exists:household_profiles,id'],
            'age' => ['nullable', 'integer', 'min:18', 'max:120', 'required_without:profile_id'],
            'sex' => ['nullable', 'in:male,female', 'required_without:profile_id'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:500', 'required_without:profile_id'],
            'height_cm' => ['nullable', 'numeric', 'gt:0', 'max:250', 'required_without:profile_id'],
            'activity_level' => ['nullable', 'string', 'max:50'],
            'goal' => ['nullable', 'string', 'max:50'],
            'health_conditions' => ['nullable', 'string', 'max:500'],
            'foods' => ['nullable', 'array'],
            'foods.*.nutrition_food_id' => ['nullable', 'integer', 'exists:nutrition_foods,id'],
            'foods.*.fdc_id' => ['nullable', 'integer', 'min:1'],
            'foods.*.serving_size_g' => ['nullable', 'numeric', 'gt:0', 'max:5000'],
        ]);

        foreach ($data['foods'] ?? [] as &$food) {
            if (! empty($food['fdc_id'])) {
                $food['nutrition_food_id'] = $usda->cacheFood((int) $food['fdc_id'])->id;
            }
        }
        unset($food);

        return response()->json($planner->plan($data));
    }
}
