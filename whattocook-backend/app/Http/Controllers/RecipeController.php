<?php

namespace App\Http\Controllers;

use App\Models\FamilyMember;
use App\Models\HouseholdProfile;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Models\Recipe;
use App\Services\RecipeMatcher;
use App\Services\RecipeNutritionService;
use App\Services\UsdaFoodDataService;
use App\Services\FoodSafetyTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class RecipeController extends Controller
{
    public function __construct(private readonly FoodSafetyTaxonomy $foodSafety) {}
    public function match(Request $request, Recipe $recipe, RecipeMatcher $matcher)
    {
        $familyId = $request->validate(['family_id' => 'nullable|integer|exists:families,id'])['family_id'] ?? null;
        if ($familyId !== null) {
            abort_unless(FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(), 403);
        }

        return response()->json($matcher->match($recipe->load('ingredients'), $this->pantryFor($request, $familyId)));
    }

    public function recommendations(Request $request, RecipeMatcher $matcher)
    {
        $familyId = $request->validate(['family_id' => 'nullable|integer|exists:families,id'])['family_id'] ?? null;
        if ($familyId !== null) {
            abort_unless(FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(), 403);
        }

        $pantry = $this->pantryFor($request, $familyId);
        $blockedIngredients = $familyId === null
            ? $this->profileBlockedIngredients(Profile::where('user_id', $request->user()->id)->first())
            : $this->familyBlockedIngredients($familyId);
        $results = Recipe::with('ingredients')->get()
            ->filter(fn (Recipe $recipe) => ! $this->foodSafety->recipeConflicts($recipe, $blockedIngredients))
            ->map(fn (Recipe $recipe) => $matcher->match($recipe, $pantry))
            ->sortByDesc('match_percentage')
            ->values();

        return response()->json(['recommendations' => $results]);
    }

    private function pantryFor(Request $request, ?int $familyId)
    {
        return PantryItem::where(fn ($query) => $familyId === null
            ? $query->where('user_id', $request->user()->id)->whereNull('family_id')
            : $query->where('family_id', $familyId))
            ->whereIn('freshness_status', ['fresh', 'review'])
            ->get();
    }

    private function familyBlockedIngredients(int $familyId): array
    {
        return HouseholdProfile::where('family_id', $familyId)->get()
            ->flatMap(function (HouseholdProfile $profile) {
                return $this->foodSafety->blockedTerms([$profile]);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function profileBlockedIngredients(?Profile $profile): array
    {
        if (! $profile) {
            return [];
        }
        return $this->foodSafety->blockedTerms([$profile])->all();
    }

    /**
     * Search the menu in the caller's active scope.
     *
     * `include_match=1` is used by the mobile menu finder. It keeps the
     * normal paginator shape but turns each result into a pantry-match card.
     */
    public function index(Request $request, RecipeMatcher $matcher)
    {
        $filters = $request->validate([
            'region' => 'nullable|string|max:100',
            'q' => 'nullable|string|max:100',
            'meal_type' => 'nullable|string|max:100',
            'difficulty' => 'nullable|string|max:100',
            'max_time' => 'nullable|integer|min:1|max:1440',
            'per_page' => 'nullable|integer|min:1|max:50',
            'family_id' => 'nullable|integer|exists:families,id',
            'include_match' => 'nullable|boolean',
        ]);
        $search = trim($filters['q'] ?? '');
        $familyId = $filters['family_id'] ?? null;
        $this->canUseFamily($request, $familyId);
        $blockedIngredients = $familyId === null
            ? $this->profileBlockedIngredients(Profile::where('user_id', $request->user()->id)->first())
            : $this->familyBlockedIngredients($familyId);

        $recipes = Recipe::with('ingredients')
            ->when($filters['region'] ?? null, fn ($q, $region) => $q->where('region', $region))
            ->when($filters['meal_type'] ?? null, fn ($q, $mealType) => $q->where('meal_type', $mealType))
            ->when($filters['difficulty'] ?? null, fn ($q, $difficulty) => $q->where('difficulty', $difficulty))
            ->when($filters['max_time'] ?? null, fn ($q, $maxTime) => $q->whereRaw('COALESCE(prep_time, 0) + COALESCE(cook_time, 0) <= ?', [$maxTime]))
            ->when($search !== '', fn ($q) => $q->where(function ($recipes) use ($search) {
                $recipes->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('meal_type', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%")
                    ->orWhereHas('ingredients', fn ($ingredients) => $ingredients->where('name', 'like', "%{$search}%"));
            }));

        // Safety must use the same word-aware taxonomy as recommendations and
        // plans (for example, vegan excludes "egg" but not "eggplant").
        $perPage = $filters['per_page'] ?? 20;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $safeRecipes = $recipes->orderBy('name')->get()
            ->filter(fn (Recipe $recipe) => ! $this->foodSafety->recipeConflicts($recipe, $blockedIngredients))
            ->values();
        $results = new LengthAwarePaginator($safeRecipes->forPage($page, $perPage)->values(), $safeRecipes->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => $request->query(),
        ]);

        if (! ($filters['include_match'] ?? false)) {
            return response()->json($results);
        }

        // Discovery intentionally keeps inventories separate: family mode
        // evaluates only shared household stock, while personal mode evaluates
        // only the signed-in user's personal stock.
        $pantry = $this->pantryForSearch($request, $familyId);
        $results->setCollection($results->getCollection()
            ->map(fn (Recipe $recipe) => $matcher->match($recipe, $pantry))
            ->values());

        return response()->json($results);
    }

    private function canUseFamily(Request $request, ?int $familyId): void
    {
        if ($familyId !== null) {
            abort_unless(FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(), 403);
        }
    }

    private function pantryForSearch(Request $request, ?int $familyId)
    {
        return PantryItem::whereIn('freshness_status', ['fresh', 'review'])
            ->when(
                $familyId === null,
                fn ($items) => $items->where('user_id', $request->user()->id)->whereNull('family_id'),
                fn ($items) => $items->where('family_id', $familyId),
            )
            ->get();
    }

    public function show(Recipe $recipe)
    {
        return response()->json($recipe->load('ingredients'));
    }

    public function nutrition(Recipe $recipe, RecipeNutritionService $nutrition)
    {
        return response()->json($nutrition->calculate($recipe));
    }

    public function linkIngredientNutrition(Request $request, Recipe $recipe, int $ingredientId, UsdaFoodDataService $usda, RecipeNutritionService $nutrition)
    {
        abort_unless($recipe->created_by === $request->user()->id, 403);
        $ingredient = $recipe->ingredients()->findOrFail($ingredientId);
        $data = $request->validate([
            'nutrition_food_id' => 'nullable|integer|exists:nutrition_foods,id|required_without:fdc_id',
            'fdc_id' => 'nullable|integer|min:1|required_without:nutrition_food_id',
            'nutrition_grams' => 'required|numeric|gt:0|max:100000',
        ]);
        $foodId = $data['nutrition_food_id'] ?? $usda->cacheFood($data['fdc_id'])->id;
        $ingredient->update(['nutrition_food_id' => $foodId, 'nutrition_grams' => $data['nutrition_grams']]);

        return response()->json($nutrition->updateRecipeMacros($recipe->fresh()));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $recipe = Recipe::create($data + ['created_by' => $request->user()->id]);
        $recipe->ingredients()->createMany($data['ingredients'] ?? []);

        return response()->json($recipe->load('ingredients'), 201);
    }

    public function update(Request $request, Recipe $recipe)
    {
        abort_unless($recipe->created_by === $request->user()->id, 403);
        $data = $this->validated($request, true);
        $recipe->update(collect($data)->except('ingredients')->all());
        if (array_key_exists('ingredients', $data)) {
            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany($data['ingredients']);
        }

        return response()->json($recipe->load('ingredients'));
    }

    public function uploadImage(Request $request, Recipe $recipe)
    {
        abort_unless($recipe->created_by === $request->user()->id, 403);

        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $oldImage = $recipe->image;
        $recipe->update(['image' => $data['image']->store('recipes', 'public')]);
        $this->deleteStoredImage($oldImage);

        return response()->json($recipe->fresh()->load('ingredients'));
    }

    public function destroy(Request $request, Recipe $recipe)
    {
        abort_unless($recipe->created_by === $request->user()->id, 403);
        $recipe->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : 'required|';

        return $request->validate([
            'name' => $prefix.'string|max:255', 'description' => 'nullable|string', 'instructions' => ($partial ? 'sometimes|' : 'required|').'string', 'cooking_tips' => 'nullable|string', 'region' => 'nullable|string|max:255',
            'prep_time' => 'nullable|integer|min:0', 'cook_time' => 'nullable|integer|min:0', 'servings' => 'nullable|integer|min:1',
            'meal_type' => 'nullable|string|max:255', 'difficulty' => 'nullable|string|max:255',
            'calories' => 'nullable|numeric|min:0', 'protein' => 'nullable|numeric|min:0', 'carbs' => 'nullable|numeric|min:0', 'fat' => 'nullable|numeric|min:0',
            'ingredients' => ($partial ? 'sometimes|' : 'required|').'array|min:1', 'ingredients.*.name' => 'required_with:ingredients|string|max:255',
            'ingredients.*.quantity' => 'nullable|string|max:255', 'ingredients.*.unit' => 'nullable|string|max:255', 'ingredients.*.nutrition_food_id' => 'nullable|integer|exists:nutrition_foods,id', 'ingredients.*.nutrition_grams' => 'nullable|numeric|gt:0|max:100000', 'ingredients.*.is_substitute' => 'nullable|boolean',
        ]);
    }

    private function deleteStoredImage(?string $image): void
    {
        if ($image && str_starts_with($image, 'recipes/')) {
            Storage::disk('public')->delete($image);
        }
    }
}
