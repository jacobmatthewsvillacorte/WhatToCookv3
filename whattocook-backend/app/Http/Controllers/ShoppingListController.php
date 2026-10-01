<?php

namespace App\Http\Controllers;

use App\Models\FamilyMember;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\RecipeMatcher;
use App\Services\ConfirmedPurchaseService;
use App\Services\ShoppingListAggregationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShoppingListController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($this->visibleTo($request)->get());
    }

    public function destroyPurchased(Request $request)
    {
        $deleted = $this->visibleTo($request)->where('is_purchased', true)->delete();

        return response()->json(['deleted' => $deleted, 'message' => $deleted ? "Deleted {$deleted} bought item".($deleted === 1 ? '' : 's').'.' : 'There are no bought items to delete.']);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);

        $item = app(ShoppingListAggregationService::class)->add(
            $request->user()->id, $data['family_id'] ?? null, $data['ingredient_name'], $data['quantity'] ?? null, $data['unit'] ?? null,
        );

        return response()->json($item, $item->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, ShoppingList $shoppingList)
    {
        $this->owns($request, $shoppingList);
        $shoppingList->update($this->data($request, true));

        return response()->json($shoppingList);
    }

    public function confirmPurchase(Request $request, ShoppingList $shoppingList, ConfirmedPurchaseService $purchases)
    {
        $this->owns($request, $shoppingList);
        $data = $request->validate([
            // This intentional acknowledgement is the safety boundary: a
            // purchased checkbox never creates pantry stock by itself.
            'confirmed' => ['required', 'accepted'],
            'name' => 'sometimes|string|max:255', 'quantity' => 'sometimes|numeric|gt:0|max:999999999.999',
            'unit' => 'sometimes|string|max:50', 'purchase_date' => 'sometimes|date', 'expiry_date' => 'sometimes|nullable|date',
            'freshness_review_date' => 'sometimes|nullable|date',
            'purchase_source' => ['sometimes', Rule::in(['supermarket', 'sari_sari_store', 'wet_market', 'homegrown', 'leftover', 'unknown'])],
            'storage_type' => ['sometimes', Rule::in(['room_temperature', 'refrigerated', 'frozen', 'other', 'unknown'])],
            'freshness_condition' => ['sometimes', Rule::in(['fresh', 'good', 'uncertain', 'unknown'])],
        ]);

        return DB::transaction(function () use ($request, $shoppingList, $data, $purchases) {
            $item = ShoppingList::lockForUpdate()->findOrFail($shoppingList->id);
            abort_if($item->is_purchased, 422, 'This purchase was already confirmed.');
            $pantry = $purchases->record($item, $data, $request->user()->id);
            $item->update(['is_purchased' => true]);
            return response()->json(['shopping_item' => $item->fresh(), 'pantry_item' => $pantry, 'message' => 'Purchase confirmed and added to the correct pantry.']);
        });
    }

    public function destroy(Request $request, ShoppingList $shoppingList)
    {
        $this->owns($request, $shoppingList);
        $shoppingList->delete();

        return response()->noContent();
    }

    public function generate(Request $request, Recipe $recipe, RecipeMatcher $matcher, ShoppingListAggregationService $shopping)
    {
        $familyId = $request->validate(['family_id' => 'nullable|integer|exists:families,id'])['family_id'] ?? null;
        $this->canUseFamily($request, $familyId);
        $pantry = PantryItem::where(fn ($query) => $familyId === null
            ? $query->where('user_id', $request->user()->id)->whereNull('family_id')
            : $query->where('family_id', $familyId))
            ->whereIn('freshness_status', ['fresh', 'review'])
            ->get();
        $match = $matcher->match($recipe->load('ingredients'), $pantry);
        $created = collect($match['missing_ingredients'])->map(function ($ingredient) use ($request, $familyId, $shopping) {
            $quantity = $ingredient['missing_quantity'] ?? $ingredient['quantity'];
            return $shopping->add($request->user()->id, $familyId, $ingredient['name'], $quantity, $ingredient['unit'] ?? null);
        });

        return response()->json(['items' => $created->values(), 'message' => 'Missing ingredients added to your shopping list.'], 201);
    }

    private function data(Request $request, bool $partial = false): array
    {
        $p = $partial ? 'sometimes|' : 'required|';
        $data = $request->validate(['ingredient_name' => $p.'string|max:255', 'quantity' => 'sometimes|nullable|string|max:255', 'unit' => 'sometimes|nullable|string|max:255', 'is_purchased' => 'sometimes|boolean', 'family_id' => 'sometimes|nullable|exists:families,id']);
        $this->canUseFamily($request, $data['family_id'] ?? null);

        if (array_key_exists('ingredient_name', $data)) {
            $data['ingredient_name'] = app(\App\Services\IngredientCatalogService::class)->approvedCanonicalName($data['ingredient_name']) ?? trim($data['ingredient_name']);
        }
        if (array_key_exists('unit', $data) && $data['unit'] !== null) {
            $data['unit'] = app(ConfirmedPurchaseService::class)->normaliseUnit($data['unit']);
        }

        return $data;
    }

    private function owns(Request $request, ShoppingList $item): void
    {
        if ($item->family_id) {
            $this->canUseFamily($request, $item->family_id);

            return;
        } abort_unless($item->user_id === $request->user()->id, 403);
    }

    private function canUseFamily(Request $request, ?int $familyId): void
    {
        if ($familyId) {
            abort_unless(FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(), 403);
        }
    }

    private function visibleTo(Request $request)
    {
        $familyIds = FamilyMember::where('user_id', $request->user()->id)->where('status', 'accepted')->pluck('family_id');

        return ShoppingList::where(fn ($query) => $query->where('user_id', $request->user()->id)->whereNull('family_id')->orWhereIn('family_id', $familyIds));
    }
}
