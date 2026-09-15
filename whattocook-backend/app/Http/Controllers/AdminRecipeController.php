<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\IngredientCatalogService;
use App\Services\RecipeNutritionService;
use App\Services\UsdaFoodDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminRecipeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $recipes = Recipe::query()
            ->withCount('ingredients')
            ->when($search !== '', fn ($query) => $query->where(function ($recipes) use ($search) {
                $recipes->where('name', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%")
                    ->orWhere('meal_type', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.recipes.index', compact('recipes', 'search'));
    }

    public function create(): View
    {
        return view('admin.recipes.create', [
            'recipe' => new Recipe,
            'ingredients' => [['name' => '', 'quantity' => '', 'unit' => '', 'is_substitute' => false]],
        ]);
    }

    public function store(Request $request, UsdaFoodDataService $usda, RecipeNutritionService $nutrition): RedirectResponse
    {
        $data = $this->validated($request);

        $recipe = DB::transaction(function () use ($data, $request, $usda) {
            $recipe = Recipe::create(collect($data)->except('ingredients')->all() + [
                'created_by' => $request->user()->id,
            ]);
            $recipe->ingredients()->createMany($this->nutritionLinkedIngredients($data['ingredients'], $usda));

            return $recipe;
        });
        $nutrition->updateRecipeMacros($recipe);

        return redirect()->route('admin.recipes.edit', $recipe)
            ->with('success', 'Recipe created. You can continue editing it below.');
    }

    public function edit(Recipe $recipe): View
    {
        return view('admin.recipes.edit', [
            'recipe' => $recipe->load('ingredients.nutritionFood'),
            'ingredients' => $recipe->ingredients
                ->map(fn ($ingredient) => array_merge(
                    $ingredient->only(['name', 'quantity', 'unit', 'nutrition_grams', 'is_substitute']),
                    ['nutrition_fdc_id' => $ingredient->nutritionFood?->fdc_id]
                ))
                ->all(),
        ]);
    }

    public function update(Request $request, Recipe $recipe, UsdaFoodDataService $usda, RecipeNutritionService $nutrition): RedirectResponse
    {
        $data = $this->validated($request, $recipe);

        DB::transaction(function () use ($data, $recipe, $usda) {
            $recipe->update(collect($data)->except('ingredients')->all());
            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany($this->nutritionLinkedIngredients($data['ingredients'], $usda));
        });
        $nutrition->updateRecipeMacros($recipe->fresh());

        return redirect()->route('admin.recipes.edit', $recipe)
            ->with('success', 'Recipe updated.');
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        $recipe->delete();

        return redirect()->route('admin.recipes.index')->with('success', 'Recipe deleted.');
    }

    /** Search FoodData Central without exposing the USDA key to the browser. */
    public function nutritionSearch(Request $request, UsdaFoodDataService $usda): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'min:2', 'max:255']]);

        return response()->json(['foods' => $usda->normalizedSearch($data['query'], 8)]);
    }

    private function validated(Request $request, ?Recipe $recipe = null): array
    {
        $ingredients = collect($request->input('ingredients', []))
            ->filter(fn ($ingredient) => filled($ingredient['name'] ?? null))
            ->values()
            ->all();
        $request->merge(['ingredients' => $ingredients]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'instructions' => ['required', 'string'],
            'cooking_tips' => ['nullable', 'string'],
            'region' => ['nullable', 'string', 'max:255'],
            'prep_time' => ['nullable', 'integer', 'min:0'],
            'cook_time' => ['nullable', 'integer', 'min:0'],
            'servings' => ['nullable', 'integer', 'min:1'],
            'meal_type' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'url', 'max:2048', 'required_with:image_source_url,image_attribution'],
            'image_source_url' => ['nullable', 'url', 'max:2048', 'required_with:image_attribution'],
            'image_attribution' => ['nullable', 'string', 'max:500', 'required_with:image_source_url'],
            'calories' => ['nullable', 'numeric', 'min:0'],
            'protein' => ['nullable', 'numeric', 'min:0'],
            'carbs' => ['nullable', 'numeric', 'min:0'],
            'fat' => ['nullable', 'numeric', 'min:0'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.quantity' => ['nullable', 'string', 'max:255'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:255'],
            'ingredients.*.nutrition_fdc_id' => ['nullable', 'integer', 'min:1', 'required_with:ingredients.*.nutrition_grams'],
            'ingredients.*.nutrition_grams' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'required_with:ingredients.*.nutrition_fdc_id'],
            'ingredients.*.is_substitute' => ['nullable', 'boolean'],
        ]);

        $existing = Recipe::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($recipe, fn ($query) => $query->whereKeyNot($recipe->id))
            ->exists();
        if ($existing) {
            throw ValidationException::withMessages(['name' => ['A recipe with this name already exists.']]);
        }

        $catalog = app(IngredientCatalogService::class);
        $seen = [];
        foreach ($data['ingredients'] as $index => &$ingredient) {
            $canonicalName = $catalog->approvedCanonicalName($ingredient['name']);
            if ($canonicalName === null) {
                throw ValidationException::withMessages(["ingredients.$index.name" => ['Choose an ingredient from the approved pantry catalogue.']]);
            }
            $key = mb_strtolower($canonicalName);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["ingredients.$index.name" => ["Duplicate ingredient; it is already listed as {$canonicalName}."]]);
            }
            $seen[$key] = true;
            $ingredient['name'] = $canonicalName;
        }
        unset($ingredient);

        return $data;
    }

    private function nutritionLinkedIngredients(array $ingredients, UsdaFoodDataService $usda): array
    {
        return collect($ingredients)->map(function (array $ingredient) use ($usda) {
            $fdcId = $ingredient['nutrition_fdc_id'] ?? null;
            unset($ingredient['nutrition_fdc_id']);

            if ($fdcId !== null) {
                $ingredient['nutrition_food_id'] = $usda->cacheFood((int) $fdcId)->id;
            }

            return $ingredient;
        })->all();
    }
}
