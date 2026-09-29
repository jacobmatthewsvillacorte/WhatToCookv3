<?php

namespace App\Http\Controllers;

use App\Models\FamilyMember;
use App\Models\HouseholdProfile;
use App\Models\MealPlan;
use App\Models\MealPlanBatch;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\PantryFreshnessService;
use App\Services\ChildMealPlanner;
use App\Services\RecipeMatcher;
use App\Services\DeterministicMealRanker;
use App\Services\FoodSafetyTaxonomy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A generated plan is intentionally a draft first. The user reviews meals
 * and stock before this controller turns anything into a saved schedule.
 */
class MealPlanBatchController extends Controller
{
    public function __construct(
        private readonly FoodSafetyTaxonomy $foodSafety,
        private readonly ChildMealPlanner $childPlanner,
    ) {}
    public function generate(Request $request, RecipeMatcher $matcher, ChildMealPlanner $childPlanner, DeterministicMealRanker $ranker)
    {
        $data = $this->generationData($request);
        $familyId = $data['family_id'] ?? null;
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        $batch = DB::transaction(function () use ($request, $data, $familyId, $start, $end, $childPlanner, $ranker) {
            $batch = MealPlanBatch::create([
                'user_id' => $request->user()->id,
                'family_id' => $familyId,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => 'draft',
                'generation_options' => collect($data)->only(['meal_types', 'diner_profile_ids', 'attendance_by_date', 'child_meal_modes', 'servings', 'cooking_time_budget', 'time_preference', 'leftover_strategy'])->all(),
            ]);
            $chosen = [];
            $pantry = $this->pantryFor($request, $familyId);
            $history = MealPlan::where('status', 'scheduled')->where('user_id', $request->user()->id)->where('planned_date', '>=', now()->subDays(14))->get();
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $dateKey = $date->toDateString();
                $dinerIds = $familyId ? ($data['attendance_by_date'][$dateKey] ?? $data['diner_profile_ids']) : [];
                $profiles = $familyId ? HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $dinerIds)->get() : collect();
                $childPlan = $familyId ? $childPlanner->plan($profiles, $date, $data['child_meal_modes'] ?? []) : null;
                $profilesForDay = $familyId
                    ? HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $dinerIds)->get()
                    : collect([$request->user()->profile()->firstOrCreate([])]);
                $recipes = $ranker->rank(Recipe::with('ingredients')->get(), $profilesForDay, $pantry, $history, $data, $chosen, $date);
                if ($recipes->isEmpty()) {
                    throw ValidationException::withMessages(['recipes' => ["No safe recipes are available for {$dateKey} and its selected diners."]]);
                }
                $shared = null;
                foreach ($data['meal_types'] as $mealIndex => $mealType) {
                    // Reuse one ulam across a day's requested meals; rice/side remains an editable serving choice.
                    $recipe = ($data['leftover_strategy'] ?? 'avoid_leftovers') === 'reuse_ulam' && $shared ? $shared : $recipes->first();
                    $shared ??= $recipe;
                    $chosen[] = $recipe->id;
                    $why = $recipe->why_chosen;
                    if ($mealIndex > 0 && ($data['leftover_strategy'] ?? '') === 'reuse_ulam') $why[] = 'Reuses today\'s ulam to reduce cooking and food waste; serve with rice or a side.';
                    if (($data['leftover_strategy'] ?? '') === 'main_with_rice_side') $why[] = 'Planned as a main dish; pair it with rice or a simple side.';
                    MealPlan::create([
                        'user_id' => $request->user()->id,
                        'family_id' => $familyId,
                        'meal_plan_batch_id' => $batch->id,
                        'recipe_id' => $recipe->id,
                        'planned_date' => $dateKey,
                        'meal_type' => $mealType,
                        'status' => 'draft',
                        'servings' => $familyId ? max(1, (int) ceil($childPlan['serving_equivalents'])) : (int) $data['servings'],
                        'serving_equivalents' => $familyId ? $childPlan['serving_equivalents'] : (float) $data['servings'],
                        'diner_profile_ids' => $dinerIds ?: null,
                        'child_meal_plan' => $childPlan,
                        'selection_reason' => $why,
                    ]);
                }
            }

            return $batch;
        });

        return response()->json($this->present($request, $batch, $matcher), 201);
    }

    public function show(Request $request, MealPlanBatch $mealPlanBatch, RecipeMatcher $matcher)
    {
        $this->authorise($request, $mealPlanBatch);

        return response()->json($this->present($request, $mealPlanBatch, $matcher));
    }

    public function updateMeal(Request $request, MealPlanBatch $mealPlanBatch, MealPlan $mealPlan, RecipeMatcher $matcher, ChildMealPlanner $childPlanner)
    {
        $this->authoriseDraft($request, $mealPlanBatch);
        abort_unless($mealPlan->meal_plan_batch_id === $mealPlanBatch->id && $mealPlan->status === 'draft', 404);
        $data = $request->validate([
            'recipe_id' => 'sometimes|integer|exists:recipes,id',
            'planned_date' => 'sometimes|date|after_or_equal:'.$mealPlanBatch->start_date->toDateString().'|before_or_equal:'.$mealPlanBatch->end_date->toDateString(),
            'meal_type' => 'sometimes|string|in:breakfast,lunch,dinner',
            'servings' => 'sometimes|integer|min:1|max:100',
            'diner_profile_ids' => 'sometimes|nullable|array',
            'diner_profile_ids.*' => 'integer|distinct',
            'child_meal_modes' => 'sometimes|array',
            'child_meal_modes.*' => 'in:family_meal_with_adaptation,separate_child_meal,exclude',
        ]);
        $familyId = $mealPlanBatch->family_id;
        $diners = array_key_exists('diner_profile_ids', $data)
            ? $this->validatedDiners($familyId, $data['diner_profile_ids'] ?? [])
            : ($mealPlan->diner_profile_ids ?? []);
        if ($familyId && ! $diners) {
            throw ValidationException::withMessages(['diner_profile_ids' => ['Choose at least one household diner.']]);
        }
        $recipeId = $data['recipe_id'] ?? $mealPlan->recipe_id;
        if (! $this->rankedSafeRecipes($request, $familyId, $diners, Carbon::parse($data['planned_date'] ?? $mealPlan->planned_date))->contains('id', $recipeId)) {
            throw ValidationException::withMessages(['recipe_id' => ['This recipe conflicts with the diners or dietary restrictions for this meal.']]);
        }
        $childPlan = null;
        if ($familyId) {
            $planDate = Carbon::parse($data['planned_date'] ?? $mealPlan->planned_date);
            $profiles = HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $diners)->get();
            $childPlan = $childPlanner->plan($profiles, $planDate, $mealPlanBatch->generation_options['child_meal_modes'] ?? []);
        }
        $mealPlan->update($data + [
            'diner_profile_ids' => $diners ?: null,
            'child_meal_plan' => $childPlan,
            'serving_equivalents' => $childPlan['serving_equivalents'] ?? ($data['servings'] ?? $mealPlan->servings),
        ]);

        return response()->json($this->present($request, $mealPlanBatch->fresh(), $matcher));
    }

    public function addShortagesToShoppingList(Request $request, MealPlanBatch $mealPlanBatch, RecipeMatcher $matcher, \App\Services\ShoppingListAggregationService $shopping)
    {
        $this->authorise($request, $mealPlanBatch);
        $summary = $this->summary($request, $mealPlanBatch, $matcher);
        $items = collect($summary['shortages'])->map(function (array $shortage) use ($request, $mealPlanBatch, $shopping) {
            return $shopping->add($request->user()->id, $mealPlanBatch->family_id, $shortage['name'], $shortage['missing_quantity'], $shortage['unit'] ?? null);
        })->values();

        return response()->json(['items' => $items, 'message' => 'Plan shortages were added to the shopping list.'], 201);
    }

    /** Add newly purchased ingredients as real, freshness-tracked pantry lots. */
    public function addPurchasedItems(Request $request, MealPlanBatch $mealPlanBatch, PantryFreshnessService $freshness, RecipeMatcher $matcher)
    {
        $this->authoriseDraft($request, $mealPlanBatch);
        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|gt:0|max:999999999.999',
            'items.*.unit' => 'required|string|max:50',
            'items.*.purchase_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date|after_or_equal:items.*.purchase_date',
            'items.*.storage_type' => 'nullable|in:room_temperature,refrigerated,frozen,other,unknown',
        ]);
        $items = DB::transaction(function () use ($data, $request, $mealPlanBatch, $freshness) {
            return collect($data['items'])->map(function (array $item) use ($request, $mealPlanBatch, $freshness) {
                $source = 'unknown';
                $storageType = $item['storage_type'] ?? 'unknown';
                $estimate = $freshness->estimate($item['name'], $item['unit'], $storageType, $source);
                // Optional validated fields are omitted from Laravel's validated data.
                // Read them defensively so purchases without a printed expiry date work.
                $expiryDate = $item['expiry_date'] ?? null;

                return PantryItem::create([
                    'user_id' => $request->user()->id,
                    // A batch always defines the pantry scope: null is personal;
                    // a family ID is the household's shared pantry.
                    'family_id' => $mealPlanBatch->family_id,
                    'name' => $item['name'],
                    'quantity' => (string) $item['quantity'],
                    'quantity_value' => $item['quantity'],
                    'unit' => $item['unit'],
                    'purchase_date' => $item['purchase_date'] ?? now()->toDateString(),
                    'purchase_source' => $source,
                    'storage_type' => $storageType,
                    'freshness_condition' => 'unknown',
                    'expiry_date' => $expiryDate ?? $estimate['expiry_date'],
                    'freshness_review_date' => $expiryDate ?? $estimate['review_date'],
                    'freshness_status' => $expiryDate ? 'fresh' : $estimate['status'],
                    'freshness_confidence' => $expiryDate ? 'high' : $estimate['confidence'],
                    'is_expiry_estimated' => $expiryDate === null,
                ]);
            })->values();
        });

        return response()->json(['items' => $items, 'preview' => $this->present($request, $mealPlanBatch->fresh(), $matcher)], 201);
    }

    public function save(Request $request, MealPlanBatch $mealPlanBatch, RecipeMatcher $matcher)
    {
        $this->authoriseDraft($request, $mealPlanBatch);
        $data = $request->validate([
            'conflict_action' => 'nullable|in:keep_existing,replace_conflicting',
            'add_shortages_to_shopping_list' => 'nullable|boolean',
        ]);
        $drafts = $mealPlanBatch->mealPlans()->with('recipe.ingredients')->where('status', 'draft')->get();
        abort_if($drafts->isEmpty(), 422, 'This draft has no meals to save.');
        foreach ($drafts as $draft) {
            if (! $this->rankedSafeRecipes($request, $mealPlanBatch->family_id, $draft->diner_profile_ids ?? [])->contains('id', $draft->recipe_id)) {
                throw ValidationException::withMessages(['recipe_id' => ["{$draft->recipe->name} is no longer safe for its selected diners."]]);
            }
        }
        $summary = $this->summary($request, $mealPlanBatch, $matcher);

        DB::transaction(function () use ($request, $mealPlanBatch, $drafts, $data, $summary) {
            $personalConflicts = $this->personalDinerConflictsFor($mealPlanBatch, $drafts);
            if ($personalConflicts->isNotEmpty()) {
                throw ValidationException::withMessages(['personal_conflicts' => ['A selected diner already has a personal meal in one or more of these slots. Change the household draft before saving.']]);
            }
            $conflicts = $this->conflictsFor($request, $mealPlanBatch, $drafts);
            if ($conflicts->isNotEmpty()) {
                if (($data['conflict_action'] ?? 'keep_existing') !== 'replace_conflicting') {
                    throw ValidationException::withMessages(['conflicts' => ['Some slots are already scheduled. Choose which existing meals to replace before saving.']]);
                }
                if ($conflicts->contains(fn (MealPlan $plan) => $plan->completed_at !== null)) {
                    throw ValidationException::withMessages(['conflicts' => ['A completed meal cannot be replaced.']]);
                }
                $conflicts->each->delete();
            }
            if ($data['add_shortages_to_shopping_list'] ?? false) {
                foreach ($summary['shortages'] as $shortage) {
                    app(\App\Services\ShoppingListAggregationService::class)->add(
                        $request->user()->id, $mealPlanBatch->family_id, $shortage['name'], $shortage['missing_quantity'], $shortage['unit'] ?? null,
                    );
                }
            }
            $mealPlanBatch->mealPlans()->where('status', 'draft')->update(['status' => 'scheduled']);
            $mealPlanBatch->update(['status' => 'saved', 'saved_at' => now()]);
        });

        return response()->json(['batch' => $mealPlanBatch->fresh(), 'meal_plans' => $mealPlanBatch->mealPlans()->with('recipe.ingredients')->get(), 'summary' => $summary, 'message' => 'Meal plan saved.']);
    }

    public function discard(Request $request, MealPlanBatch $mealPlanBatch)
    {
        $this->authoriseDraft($request, $mealPlanBatch);
        DB::transaction(function () use ($mealPlanBatch) {
            $mealPlanBatch->mealPlans()->where('status', 'draft')->delete();
            $mealPlanBatch->update(['status' => 'discarded', 'discarded_at' => now()]);
        });

        return response()->noContent();
    }

    private function present(Request $request, MealPlanBatch $batch, RecipeMatcher $matcher): array
    {
        $batch->load(['mealPlans' => fn ($query) => $query->with('recipe.ingredients')->orderBy('planned_date')->orderBy('meal_type')]);

        return [
            'batch' => $batch,
            'meal_plans' => $batch->mealPlans,
            'summary' => $this->summary($request, $batch, $matcher),
            'conflicts' => $this->conflictsFor($request, $batch, $batch->mealPlans)->values(),
            // Never expose a household member's personal recipe. The UI only
            // needs to know which selected diner and time slot must be changed.
            'personal_conflicts' => $this->personalDinerConflictsFor($batch, $batch->mealPlans)->values(),
        ];
    }

    private function summary(Request $request, MealPlanBatch $batch, RecipeMatcher $matcher): array
    {
        $plans = $batch->mealPlans()->with('recipe.ingredients')->where('status', 'draft')->orderBy('planned_date')->orderBy('meal_type')->get();
        $pantry = $this->pantryFor($request, $batch->family_id);
        $requirements = [];
        foreach ($plans as $plan) {
            foreach ($plan->recipe->ingredients as $ingredient) {
                $quantity = $this->quantity($ingredient->quantity);
                $scale = $this->servingEquivalent($plan) / max(1, (int) ($plan->recipe->servings ?: 1));
                $required = $quantity === null ? null : $quantity * $scale;
                $normalised = $this->normaliseRequirement($ingredient->name, $ingredient->unit, $required);
                $key = $normalised['key'];
                $requirements[$key] ??= ['name' => $ingredient->name, 'unit' => $normalised['unit'], 'required_quantity' => 0.0, 'needs_review' => false, 'substitutes' => []];
                if ($required === null) {
                    $requirements[$key]['needs_review'] = true;
                } else {
                    $requirements[$key]['required_quantity'] += $normalised['quantity'];
                }
                $single = $matcher->match($plan->recipe, $pantry, $this->servingEquivalent($plan));
                $match = collect($single['missing_ingredients'])->firstWhere('name', $ingredient->name);
                if ($match) {
                    $requirements[$key]['substitutes'] = $match['substitutes'] ?? [];
                }
            }
        }
        $items = collect($requirements)->map(function (array $requirement) use ($matcher, $pantry) {
            $availability = $matcher->availabilityFor($requirement['name'], $requirement['unit'], $pantry);
            $missing = $requirement['needs_review'] ? null : max(0, round($requirement['required_quantity'] - $availability['quantity'], 3));
            return $requirement + [
                'required_quantity' => $requirement['needs_review'] ? null : round($requirement['required_quantity'], 3),
                'pantry_quantity' => $availability['has_stock'] ? round($availability['quantity'], 3) : null,
                'missing_quantity' => $missing,
                'status' => $requirement['needs_review'] ? 'needs_review' : ($missing > 0 ? ($availability['has_stock'] ? 'low_stock' : 'missing') : 'ready'),
            ];
        })->values();

        return [
            'meal_count' => $plans->count(),
            'ready_count' => $items->where('status', 'ready')->count(),
            'ingredients' => $items,
            'shortages' => $items->whereIn('status', ['low_stock', 'missing'])->values(),
            'needs_review' => $items->where('status', 'needs_review')->values(),
        ];
    }

    private function generationData(Request $request): array
    {
        $data = $request->validate([
            'family_id' => 'nullable|integer|exists:families,id', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date',
            'meal_types' => 'required|array|min:1', 'meal_types.*' => 'string|in:breakfast,lunch,dinner',
            'diner_profile_ids' => 'sometimes|array', 'diner_profile_ids.*' => 'integer|distinct',
            // The client already prevents duplicate selections, but a stale UI must
            // not turn a harmless duplicate diner ID into a raw validation error.
            // validatedDiners() below canonicalizes each date's list safely.
            'attendance_by_date' => 'sometimes|array', 'attendance_by_date.*' => 'array', 'attendance_by_date.*.*' => 'integer',
            'child_meal_modes' => 'sometimes|array', 'child_meal_modes.*' => 'in:family_meal_with_adaptation,separate_child_meal,exclude',
            'servings' => 'nullable|integer|min:1|max:100',
            'cooking_time_budget' => 'nullable|string|in:15,30,45,60,90+',
            'time_preference' => 'nullable|string|in:strict,flexible',
            'leftover_strategy' => 'nullable|string|in:avoid_leftovers,reuse_ulam,main_with_rice_side',
        ]);
        $familyId = $data['family_id'] ?? null;
        $this->canUseFamily($request, $familyId);
        $data['diner_profile_ids'] = $this->validatedDiners($familyId, $data['diner_profile_ids'] ?? []);
        if ($familyId && ! $data['diner_profile_ids']) {
            throw ValidationException::withMessages(['diner_profile_ids' => ['Choose at least one household diner.']]);
        }
        foreach ($data['attendance_by_date'] ?? [] as $date => $dinerIds) {
            if (! Carbon::parse($date)->betweenIncluded(Carbon::parse($data['start_date']), Carbon::parse($data['end_date']))) {
                throw ValidationException::withMessages(['attendance_by_date' => ['Attendance dates must be inside the plan range.']]);
            }
            $data['attendance_by_date'][$date] = $this->validatedDiners($familyId, $dinerIds);
        }
        if (Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 55) {
            throw ValidationException::withMessages(['end_date' => ['A draft plan can cover up to 56 days (8 weeks).']]);
        }
        $data['servings'] ??= max(1, count($data['diner_profile_ids']));
        $data['cooking_time_budget'] ??= '60';
        $data['time_preference'] ??= 'flexible';
        $data['leftover_strategy'] ??= 'avoid_leftovers';

        return $data;
    }

    private function conflictsFor(Request $request, MealPlanBatch $batch, $drafts)
    {
        $keys = collect($drafts)->map(fn (MealPlan $plan) => $plan->planned_date->toDateString().'|'.$plan->meal_type)->all();
        return MealPlan::query()->where('status', 'scheduled')
            ->where('family_id', $batch->family_id)
            ->when($batch->family_id === null, fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereBetween('planned_date', [$batch->start_date, $batch->end_date])
            ->get()->filter(fn (MealPlan $plan) => in_array($plan->planned_date->toDateString().'|'.$plan->meal_type, $keys, true))->values();
    }

    /** Personal schedules remain private, but prevent double-booking a selected linked diner. */
    private function personalDinerConflictsFor(MealPlanBatch $batch, $drafts)
    {
        if (! $batch->family_id) {
            return collect();
        }

        $drafts = collect($drafts)->filter(fn (MealPlan $plan) => $plan->status === 'draft')->values();
        $profileIds = $drafts->flatMap(fn (MealPlan $plan) => $plan->diner_profile_ids ?? [])->unique()->values();
        if ($drafts->isEmpty() || $profileIds->isEmpty()) {
            return collect();
        }

        $profiles = HouseholdProfile::selectableForFamily($batch->family_id)
            ->whereIn('id', $profileIds)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'name'])
            ->keyBy('id');
        if ($profiles->isEmpty()) {
            return collect();
        }

        $slotProfiles = [];
        foreach ($drafts as $draft) {
            foreach ($draft->diner_profile_ids ?? [] as $profileId) {
                $profile = $profiles->get($profileId);
                if ($profile) {
                    $slotProfiles[$profile->user_id.'|'.$draft->planned_date->toDateString().'|'.$draft->meal_type] = $profile;
                }
            }
        }

        return MealPlan::query()
            ->where('status', 'scheduled')
            ->whereNull('family_id')
            ->whereIn('user_id', $profiles->pluck('user_id')->unique())
            ->whereBetween('planned_date', [$batch->start_date, $batch->end_date])
            ->get(['user_id', 'planned_date', 'meal_type'])
            ->map(function (MealPlan $plan) use ($slotProfiles) {
                $key = $plan->user_id.'|'.$plan->planned_date->toDateString().'|'.$plan->meal_type;
                $profile = $slotProfiles[$key] ?? null;
                if (! $profile) {
                    return null;
                }

                return [
                    'diner_profile_id' => $profile->id,
                    'diner_name' => $profile->name,
                    'planned_date' => $plan->planned_date->toDateString(),
                    'meal_type' => $plan->meal_type,
                ];
            })
            ->filter()
            ->unique(fn (array $conflict) => $conflict['diner_profile_id'].'|'.$conflict['planned_date'].'|'.$conflict['meal_type'])
            ->values();
    }

    private function pantryFor(Request $request, ?int $familyId)
    {
        return PantryItem::whereIn('freshness_status', ['fresh', 'review'])
            ->where(fn ($query) => $familyId === null
                ? $query->where('user_id', $request->user()->id)->whereNull('family_id')
                : $query->where('family_id', $familyId))
            ->get();
    }

    private function rankedSafeRecipes(Request $request, ?int $familyId, array $dinerIds, ?Carbon $mealDate = null)
    {
        $profiles = $familyId
            ? HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $dinerIds)->get()
            : collect([$request->user()->profile()->firstOrCreate([])]);
        $likes = $profiles->flatMap(fn ($profile) => $profile->likes ?? [])->map(fn ($value) => strtolower($value))->filter()->unique();
        $dislikes = $profiles->flatMap(fn ($profile) => $profile->dislikes ?? [])->map(fn ($value) => strtolower($value))->filter()->unique();
        $pantryNames = $this->pantryFor($request, $familyId)->pluck('name')->map(fn ($value) => strtolower($value));

        $mealDate ??= now();
        $hasYoungChild = $profiles->contains(fn ($profile) => in_array(
            $this->childPlanner->ageBand($profile->birth_date, $mealDate),
            ['0-5_months', '6-11_months', '12-23_months', '2-5_years'],
            true,
        ));

        return Recipe::with('ingredients')->get()
            ->filter(fn (Recipe $recipe) => $this->foodSafety->recipeIsSafe($recipe, $profiles))
            ->filter(fn (Recipe $recipe) => ! $hasYoungChild || ! $this->hasYoungChildConflict($recipe))
            ->map(function (Recipe $recipe) use ($likes, $dislikes, $pantryNames) {
            $terms = $recipe->ingredients->pluck('name')->push($recipe->name)->map(fn ($value) => strtolower($value));
            $hits = $recipe->ingredients->filter(fn ($ingredient) => $pantryNames->contains(fn ($item) => str_contains($item, strtolower($ingredient->name)) || str_contains(strtolower($ingredient->name), $item)))->count();
            $score = $recipe->ingredients->isEmpty() ? 0 : ($hits / $recipe->ingredients->count()) * 40;
            $score += $likes->filter(fn ($like) => $terms->contains(fn ($term) => str_contains($term, $like)))->count() * 25;
            $score -= $dislikes->filter(fn ($dislike) => $terms->contains(fn ($term) => str_contains($term, $dislike)))->count() * 20;
            $recipe->generation_score = $score;
            return $recipe;
        })->sortByDesc('generation_score')->values();
    }

    private function hasYoungChildConflict(Recipe $recipe): bool
    {
        return $recipe->ingredients->pluck('name')->push($recipe->name)
            ->contains(fn ($term) => str_contains(strtolower((string) $term), 'alcohol') || str_contains(strtolower((string) $term), 'siling labuyo'));
    }

    private function validatedDiners(?int $familyId, array $dinerIds): array
    {
        $dinerIds = array_values(array_unique(array_map('intval', $dinerIds)));
        if (! $dinerIds) return [];
        if (! $familyId || HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $dinerIds)->count() !== count($dinerIds)) {
            throw ValidationException::withMessages(['diner_profile_ids' => ['Each diner must belong to the selected household.']]);
        }
        return $dinerIds;
    }

    private function canUseFamily(Request $request, ?int $familyId): void
    {
        if ($familyId) abort_unless(FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(), 403);
    }

    private function authorise(Request $request, MealPlanBatch $batch): void
    {
        if ($batch->family_id) {
            $this->canUseFamily($request, $batch->family_id);
        } else {
            abort_unless($batch->user_id === $request->user()->id, 403);
        }
        if ($batch->status === 'draft') abort_unless($batch->user_id === $request->user()->id, 403);
    }

    private function authoriseDraft(Request $request, MealPlanBatch $batch): void
    {
        $this->authorise($request, $batch);
        abort_unless($batch->status === 'draft', 422, 'This plan is no longer a draft.');
    }

    private function quantity($value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float) $value;
        $value = trim((string) $value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $value, $match)) {
            return (float) $match[1] / (float) $match[2];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s+(\d+)\s*\/\s*(\d+)$/', $value, $match)) {
            return (float) $match[1] + ((float) $match[2] / (float) $match[3]);
        }

        return preg_match('/\d+(?:\.\d+)?/', $value, $match) ? (float) $match[0] : null;
    }

    private function servingEquivalent(MealPlan $plan): float
    {
        return (float) ($plan->serving_equivalents ?? $plan->servings);
    }

    /** Aggregate equivalent mass/volume units before comparing the full draft to pantry stock. */
    private function normaliseRequirement(string $name, ?string $unit, ?float $quantity): array
    {
        $normalisedUnit = strtolower(trim($unit ?? ''));
        $factor = match ($normalisedUnit) {
            'kg', 'kilogram', 'kilograms', 'l', 'litre', 'litres', 'liter', 'liters' => 1000.0,
            default => 1.0,
        };
        $canonicalUnit = match ($normalisedUnit) {
            'kg', 'kilogram', 'kilograms', 'g', 'gram', 'grams' => 'g',
            'l', 'litre', 'litres', 'liter', 'liters', 'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters' => 'ml',
            default => $unit,
        };

        return [
            'key' => strtolower(trim($name)).'|'.strtolower(trim($canonicalUnit ?? '')),
            'unit' => $canonicalUnit,
            'quantity' => $quantity === null ? null : $quantity * $factor,
        ];
    }
}
