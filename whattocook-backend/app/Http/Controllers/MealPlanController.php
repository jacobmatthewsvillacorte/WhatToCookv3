<?php

namespace App\Http\Controllers;

use App\Models\FamilyMember;
use App\Models\HouseholdProfile;
use App\Models\MealHistory;
use App\Models\MealPlan;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Services\FoodSafetyTaxonomy;
use App\Services\ChildMealPlanner;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\RecipeNutritionService;
use App\Services\RecipeMatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MealPlanController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorisesFamilyAccess;
    use \App\Http\Controllers\Concerns\PlansForHousehold;

    public function __construct(
        private readonly FoodSafetyTaxonomy $foodSafety,
        private readonly ChildMealPlanner $childPlanner,
    ) {}
    public function nutritionSummary(Request $request, RecipeNutritionService $nutrition)
    {
        $filters = $request->validate([
            'family_id' => 'nullable|integer|exists:families,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        if (($filters['family_id'] ?? null) !== null) {
            $this->canUseFamily($request, $filters['family_id']);
        }

        $plans = $this->visibleTo($request)
            ->when(array_key_exists('family_id', $filters), fn ($query) => $filters['family_id'] === null ? $query->whereNull('family_id') : $query->where('family_id', $filters['family_id']))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '>=', $date))
            ->when($filters['end_date'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '<=', $date))
            ->with('recipe.ingredients.nutritionFood')->orderBy('planned_date')->get();
        $totals = array_fill_keys(['calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'sugar'], 0.0);
        $byDate = [];
        $incomplete = [];
        foreach ($plans as $plan) {
            $recipeNutrition = $nutrition->calculate($plan->recipe);
            $scale = $this->servingEquivalent($plan) / max(1, $recipeNutrition['servings']);
            $date = $plan->planned_date->toDateString();
            $byDate[$date] ??= array_fill_keys(array_keys($totals), 0.0);
            foreach ($totals as $key => $_) {
                $amount = $recipeNutrition['totals'][$key] * $scale;
                $totals[$key] += $amount;
                $byDate[$date][$key] += $amount;
            }
            // A plan total is only safe to call complete when every included
            // ingredient and every displayed nutrient is covered.  A food can
            // be linked and measurable while still lacking (for example)
            // sodium or fibre in its source record.
            if (! $recipeNutrition['is_nutrition_complete']) {
                $incomplete[] = [
                    'meal_plan_id' => $plan->id,
                    'recipe_id' => $plan->recipe_id,
                    'data_status' => $recipeNutrition['data_status'],
                    'unmatched_ingredients' => $recipeNutrition['unmatched_ingredients'],
                    'unknown_nutrients' => $recipeNutrition['unknown_nutrients'],
                ];
            }
        }
        $round = fn (array $values) => collect($values)->map(fn ($value) => round($value, 2))->all();

        return response()->json([
            'meal_count' => $plans->count(),
            'totals' => $round($totals),
            'by_date' => collect($byDate)->map($round),
            'incomplete_meals' => $incomplete,
            'is_nutrition_complete' => $incomplete === [],
            'data_status' => $incomplete === [] ? 'complete' : 'incomplete',
            'disclaimer' => 'Nutrition is an estimate from linked food records, not medical advice. Do not use incomplete totals for medical decisions.',
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'family_id' => 'nullable|integer|exists:families,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        if (array_key_exists('family_id', $filters) && $filters['family_id'] !== null) {
            $this->canUseFamily($request, $filters['family_id']);
        }

        return response()->json($this->visibleTo($request)
            ->when(array_key_exists('family_id', $filters), fn ($query) => $filters['family_id'] === null ? $query->whereNull('family_id') : $query->where('family_id', $filters['family_id']))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '>=', $date))
            ->when($filters['end_date'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '<=', $date))
            ->with('recipe.ingredients')->orderBy('planned_date')->orderBy('meal_type')->get());
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $this->assertNoPersonalDinerConflict($data['family_id'] ?? null, $data['diner_profile_ids'] ?? [], $data['planned_date'], $data['meal_type']);

        return response()->json(MealPlan::create($data + ['user_id' => $request->user()->id]), 201);
    }

    public function update(Request $request, MealPlan $mealPlan)
    {
        $this->owns($request, $mealPlan);
        abort_if($mealPlan->completed_at, 422, 'A cooked meal cannot be changed. Create a new meal instead.');
        $data = $this->data($request, true, $mealPlan);
        $this->assertNoPersonalDinerConflict(
            $data['family_id'] ?? $mealPlan->family_id,
            $data['diner_profile_ids'] ?? $mealPlan->diner_profile_ids ?? [],
            $data['planned_date'] ?? $mealPlan->planned_date->toDateString(),
            $data['meal_type'] ?? $mealPlan->meal_type,
            $mealPlan->id,
        );
        $mealPlan->update($data);

        return response()->json($mealPlan);
    }

    public function destroy(Request $request, MealPlan $mealPlan)
    {
        $this->owns($request, $mealPlan);
        abort_if($mealPlan->completed_at, 422, 'A cooked meal cannot be removed because it is part of meal history.');
        $mealPlan->delete();

        return response()->noContent();
    }

    public function show(Request $request, MealPlan $mealPlan)
    {
        $this->owns($request, $mealPlan);
        $this->assertSavedMeal($mealPlan);

        return response()->json($mealPlan->load('recipe.ingredients'));
    }

    public function complete(Request $request, MealPlan $mealPlan, RecipeMatcher $matcher)
    {
        $this->owns($request, $mealPlan);
        $this->assertSavedMeal($mealPlan);
        abort_if($mealPlan->completed_at, 422, 'This meal has already been cooked.');
        $completion = $request->validate(['notes' => 'sometimes|nullable|string|max:5000']);
        $plan = DB::transaction(function () use ($mealPlan, $request, $matcher, $completion) {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $this->assertSavedMeal($mealPlan);
            abort_if($mealPlan->completed_at, 422, 'This meal has already been cooked.');
            $mealPlan->load('recipe.ingredients');
            $pantry = $this->pantryQueryForPlan($request, $mealPlan)->lockForUpdate()->get();
            $match = $matcher->match($mealPlan->recipe, $pantry, $this->servingEquivalent($mealPlan));
            $ingredients = $this->preflightIngredients($match);
            $notReady = $ingredients->whereIn('status', ['low_stock', 'missing', 'needs_review']);
            if ($notReady->isNotEmpty()) {
                throw ValidationException::withMessages(['pantry' => ['Review missing or unmeasurable ingredients before deducting pantry stock.']]);
            }
            $consumed = [];
            foreach ($ingredients as $ingredient) {
                $needed = (float) $ingredient['required_quantity'];
                foreach ($matcher->matchingPantryItemsFor($ingredient['name'], $ingredient['unit'], $pantry) as $item) {
                    if ($needed <= 0) {
                        break;
                    }
                    $available = $matcher->quantityInUnit($item, $ingredient['unit']);
                    $usedInRecipeUnit = min($needed, $available);
                    if ($usedInRecipeUnit <= 0) {
                        continue;
                    }
                    $usedFromItem = $matcher->quantityFromUnit($usedInRecipeUnit, $ingredient['unit'], $item);
                    $remaining = max(0, round((float) $item->quantity_value - $usedFromItem, 3));
                    $consumed[] = [
                        'pantry_item_id' => $item->id,
                        'quantity' => round($usedFromItem, 3),
                        'unit' => $item->unit,
                        'recipe_quantity' => round($usedInRecipeUnit, 3),
                        'recipe_unit' => $ingredient['unit'],
                    ];
                    $item->update([
                        'quantity_value' => $remaining,
                        'quantity' => (string) $remaining,
                        'last_used_quantity' => $usedFromItem,
                        'previous_freshness_status' => $item->freshness_status,
                        'freshness_status' => $remaining <= 0 ? 'used' : $item->freshness_status,
                    ]);
                    $needed = max(0, $needed - $usedInRecipeUnit);
                }
                if ($needed > 0.0005) {
                    throw ValidationException::withMessages(['pantry' => ["Not enough {$ingredient['name']} to cook this meal."]]);
                }
            }
            $mealPlan->update([
                'completed_at' => now(),
                'completion_method' => 'pantry_deducted',
                'consumed_items' => $consumed,
                'status' => 'completed',
            ]);
            $this->recordMealHistory($request, $mealPlan, $completion['notes'] ?? null);

            return $mealPlan->fresh()->load('recipe');
        });

        return response()->json(['meal_plan' => $plan, 'message' => 'Meal cooked and pantry stock deducted.']);
    }

    /**
     * Preview the exact ingredients and quantities required for this planned
     * meal before the user decides whether pantry stock should be deducted.
     */
    public function preflight(Request $request, MealPlan $mealPlan, RecipeMatcher $matcher)
    {
        $this->owns($request, $mealPlan);
        $this->assertSavedMeal($mealPlan);
        $mealPlan->load('recipe.ingredients');
        $match = $matcher->match($mealPlan->recipe, $this->pantryForPlan($request, $mealPlan), $this->servingEquivalent($mealPlan));
        $ingredients = $this->preflightIngredients($match);
        $byStatus = collect(['ready', 'low_stock', 'missing', 'needs_review'])
            ->mapWithKeys(fn (string $status) => [$status => $ingredients->where('status', $status)->values()]);

        return response()->json([
            'meal_plan' => [
                'id' => $mealPlan->id,
                'family_id' => $mealPlan->family_id,
                'planned_date' => $mealPlan->planned_date->toDateString(),
                'meal_type' => $mealPlan->meal_type,
                'servings' => $mealPlan->servings,
                'diner_profile_ids' => $mealPlan->diner_profile_ids ?? [],
                'status' => $mealPlan->status,
                'completion_method' => $mealPlan->completion_method,
            ],
            'recipe' => $mealPlan->recipe,
            'diners' => $this->dinersForPlan($request, $mealPlan),
            'pantry_scope' => $mealPlan->family_id ? 'family' : 'personal',
            'can_cook_from_pantry' => $byStatus['low_stock']->isEmpty() && $byStatus['missing']->isEmpty() && $byStatus['needs_review']->isEmpty(),
            'can_mark_cooked_without_deduction' => ! $mealPlan->completed_at,
            'match_percentage' => $match['match_percentage'],
            'ingredients' => $ingredients,
            'ingredients_by_status' => $byStatus,
        ]);
    }

    /**
     * Record an externally supplied meal (for example, ingredients bought but
     * not entered into the app) without changing any pantry quantities.
     */
    public function completeWithoutDeduction(Request $request, MealPlan $mealPlan)
    {
        $this->owns($request, $mealPlan);
        $this->assertSavedMeal($mealPlan);
        abort_if($mealPlan->completed_at, 422, 'This meal has already been cooked.');

        $completion = $request->validate(['notes' => 'sometimes|nullable|string|max:5000']);
        $plan = DB::transaction(function () use ($request, $mealPlan, $completion) {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $this->assertSavedMeal($mealPlan);
            abort_if($mealPlan->completed_at, 422, 'This meal has already been cooked.');
            $mealPlan->update([
                'completed_at' => now(),
                'consumed_items' => [],
                'completion_method' => 'without_pantry_deduction',
                'status' => 'completed',
            ]);
            $this->recordMealHistory($request, $mealPlan, $completion['notes'] ?? null);

            return $mealPlan->fresh()->load('recipe');
        });

        return response()->json([
            'meal_plan' => $plan,
            'message' => 'Meal marked as cooked; pantry stock was not deducted.',
        ]);
    }

    /** Add only this meal's scaled shortages to its personal or shared list. */
    public function addShortagesToShoppingList(Request $request, MealPlan $mealPlan, RecipeMatcher $matcher, \App\Services\ShoppingListAggregationService $shopping)
    {
        $this->owns($request, $mealPlan);
        $this->assertSavedMeal($mealPlan);
        $mealPlan->load('recipe.ingredients');
        $match = $matcher->match($mealPlan->recipe, $this->pantryForPlan($request, $mealPlan), $this->servingEquivalent($mealPlan));
        $ingredients = $this->preflightIngredients($match);
        $needsReview = $ingredients->where('status', 'needs_review')->values();
        $items = $ingredients->whereIn('status', ['low_stock', 'missing'])->map(function (array $ingredient) use ($request, $mealPlan, $shopping) {
            return $shopping->add($request->user()->id, $mealPlan->family_id, $ingredient['name'], $ingredient['missing_quantity'], $ingredient['unit'] ?? null);
        })->values();

        return response()->json([
            'items' => $items,
            'needs_review' => $needsReview,
            'message' => $items->isEmpty()
                ? 'No measurable shortages were added to the shopping list.'
                : 'Meal shortages were added to the shopping list.',
        ], 201);
    }

    private function data(Request $request, bool $partial = false, ?MealPlan $existing = null): array
    {
        $p = $partial ? 'sometimes|' : 'required|';
        $data = $request->validate([
            'recipe_id' => $p.'exists:recipes,id', 'planned_date' => $p.'date', 'meal_type' => $p.'string|in:breakfast,lunch,dinner',
            'family_id' => 'nullable|exists:families,id', 'servings' => ($partial ? 'sometimes|' : 'nullable|').'integer|min:1|max:100',
            'diner_profile_ids' => 'sometimes|nullable|array', 'diner_profile_ids.*' => 'integer|distinct',
        ]);
        $familyId = $data['family_id'] ?? $existing?->family_id;
        $this->canUseFamily($request, $familyId);
        if (array_key_exists('diner_profile_ids', $data)) {
            $data['diner_profile_ids'] = $this->validatedDiners($familyId, $data['diner_profile_ids'] ?? []);
        }
        // Changing a shared meal's date or diners can change its child-safety
        // and dietary eligibility even when the recipe itself is unchanged.
        $mustRecheckRecipe = array_key_exists('recipe_id', $data)
            || ($familyId && (array_key_exists('planned_date', $data) || array_key_exists('diner_profile_ids', $data)));
        if ($mustRecheckRecipe) {
            $recipeId = $data['recipe_id'] ?? $existing?->recipe_id;
            $isSafe = $this->safeRecipesFor(
                $request,
                $familyId,
                $data['diner_profile_ids'] ?? $existing?->diner_profile_ids ?? [],
                Carbon::parse($data['planned_date'] ?? $existing?->planned_date ?? now()),
            )->contains('id', $recipeId);
            if (! $isSafe) {
                throw ValidationException::withMessages(['recipe_id' => ['This recipe conflicts with the active diner profile or dietary restrictions.']]);
            }
        }
        if (! $partial && ! array_key_exists('servings', $data)) {
            $data['servings'] = max(1, count($data['diner_profile_ids'] ?? []));
        }

        return $data;
    }

    /** A shared meal must not silently double-book a linked member's private plan. */
    private function assertNoPersonalDinerConflict(?int $familyId, array $dinerIds, string $plannedDate, string $mealType, ?int $ignorePlanId = null): void
    {
        if (! $familyId || ! $dinerIds) {
            return;
        }

        $userIds = HouseholdProfile::selectableForFamily($familyId)
            ->whereIn('id', $dinerIds)
            ->whereNotNull('user_id')
            ->pluck('user_id');
        if ($userIds->isEmpty()) {
            return;
        }

        $conflict = MealPlan::query()
            ->where('status', 'scheduled')
            ->whereNull('family_id')
            ->whereIn('user_id', $userIds)
            ->whereDate('planned_date', $plannedDate)
            ->where('meal_type', $mealType)
            ->when($ignorePlanId, fn ($query) => $query->whereKeyNot($ignorePlanId))
            ->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['personal_conflicts' => ['A selected diner already has a personal meal in this date and meal slot. Change the household meal before saving.']]);
        }
    }

    private function owns(Request $request, MealPlan $plan): void
    {
        if ($plan->family_id) {
            $this->canUseFamily($request, $plan->family_id);
        } else {
            abort_unless($plan->user_id === $request->user()->id, 403);
        }
    }

    private function visibleTo(Request $request)
    {
        $familyIds = FamilyMember::where('user_id', $request->user()->id)->where('status', 'accepted')->pluck('family_id');

        return MealPlan::where('status', '!=', 'draft')->where(fn ($query) => $query->where(fn ($personal) => $personal->where('user_id', $request->user()->id)->whereNull('family_id'))->orWhereIn('family_id', $familyIds));
    }

    /**
     * A personal plan consumes only its owner's personal pantry. A family plan
     * is intentionally checked only against its shared household pantry, so a
     * member's private groceries are never treated as household stock.
     */
    private function pantryForPlan(Request $request, MealPlan $mealPlan)
    {
        return $this->pantryQueryForPlan($request, $mealPlan)->get();
    }

    private function pantryQueryForPlan(Request $request, MealPlan $mealPlan)
    {
        return PantryItem::whereIn('freshness_status', ['fresh', 'review'])
            ->where(fn ($query) => $mealPlan->family_id
                ? $query->where('family_id', $mealPlan->family_id)
                : $query->where('user_id', $request->user()->id)->whereNull('family_id'));
    }

    /** Turn matcher data into the four UI states used on the meal-details page. */
    private function preflightIngredients(array $match)
    {
        $source = $match['ingredients'] ?? $match['available_ingredients']->concat($match['missing_ingredients']);

        return collect($source)->map(function (array $ingredient) {
            $status = ($ingredient['needs_review'] ?? false) || $ingredient['required_quantity'] === null
                ? 'needs_review'
                : ($ingredient['sufficient'] ? 'ready' : ($ingredient['available'] ? 'low_stock' : 'missing'));

            return $ingredient + ['status' => $status];
        })->values();
    }

    private function dinersForPlan(Request $request, MealPlan $mealPlan)
    {
        if (! $mealPlan->family_id) {
            return collect([[
                'id' => null,
                'name' => $request->user()->name,
                'relation' => 'Self',
            ]]);
        }

        return HouseholdProfile::selectableForFamily($mealPlan->family_id)
            ->whereIn('id', $mealPlan->diner_profile_ids ?? [])
            ->get(['id', 'name', 'relation'])
            ->values();
    }

    private function assertSavedMeal(MealPlan $mealPlan): void
    {
        abort_unless(in_array($mealPlan->status, ['scheduled', 'completed'], true), 422, 'Save this meal plan before viewing or cooking it.');
    }

    /** Create exactly one history row per completed meal plan. */
    private function recordMealHistory(Request $request, MealPlan $mealPlan, ?string $notes = null): void
    {
        MealHistory::firstOrCreate([
            'meal_plan_id' => $mealPlan->id,
        ], [
            'user_id' => $request->user()->id,
            'family_id' => $mealPlan->family_id,
            'recipe_id' => $mealPlan->recipe_id,
            'prepared_at' => now()->toDateString(),
            'servings' => $mealPlan->servings,
            'notes' => $notes,
        ]);
    }

    private function safeRecipesFor(Request $request, ?int $familyId, array $dinerIds, ?Carbon $mealDate = null)
    {
        $profiles = $familyId ? HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $dinerIds)->get() : collect([Profile::where('user_id', $request->user()->id)->first()]);
        $mealDate ??= now();
        $hasYoungChild = $profiles->filter()->contains(fn ($profile) => in_array(
            $this->childPlanner->ageBand($profile->birth_date ?? null, $mealDate),
            ['0-5_months', '6-11_months', '12-23_months', '2-5_years'],
            true,
        ));
        return \App\Services\RecipeCatalog::all()
            ->filter(fn (Recipe $recipe) => $this->foodSafety->recipeIsSafe($recipe, $profiles))
            ->filter(fn (Recipe $recipe) => ! $hasYoungChild || ! $this->hasYoungChildConflict($recipe))
            ->values();
    }

}
