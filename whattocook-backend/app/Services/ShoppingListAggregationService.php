<?php

namespace App\Services;

use App\Models\ShoppingList;

/** Keeps one open shopping entry per canonical ingredient, unit and scope. */
class ShoppingListAggregationService
{
    public function __construct(private ShoppingQuantitySuggestionService $suggestions) {}

    public function add(int $userId, ?int $familyId, string $name, mixed $quantity, ?string $unit): ShoppingList
    {
        // Callers that accept manual input canonicalize it before reaching this
        // service. Recipe/plan output keeps its display name and unit intact.
        $name = trim($name);
        $unit = $unit === null || trim($unit) === '' ? null : trim($unit);

        $item = ShoppingList::query()
            ->where('user_id', $userId)
            ->where('family_id', $familyId)
            ->where('is_purchased', false)
            ->whereRaw('LOWER(ingredient_name) = ?', [strtolower($name)])
            ->whereRaw('LOWER(COALESCE(unit, \'\')) = ?', [strtolower($unit ?? '')])
            ->first();

        if (! $item) {
            $suggestion = $this->suggestions->suggest($quantity, $unit);
            return ShoppingList::create([
                'user_id' => $userId, 'family_id' => $familyId, 'ingredient_name' => $name,
                'quantity' => $quantity === null ? null : (string) $quantity, 'unit' => $unit,
                'purchase_quantity' => $suggestion['quantity'] === null ? null : (string) $suggestion['quantity'],
                'purchase_unit' => $suggestion['unit'], 'purchase_note' => $suggestion['note'], 'is_purchased' => false,
            ]);
        }

        // A non-measurable request remains a single reviewable list entry;
        // never guess a quantity by mixing it with a measurable one.
        if (is_numeric($item->quantity) && is_numeric($quantity)) {
            $total = round((float) $item->quantity + (float) $quantity, 3);
            $suggestion = $this->suggestions->suggest($total, $unit);
            $item->update([
                'quantity' => (string) $total,
                'purchase_quantity' => $suggestion['quantity'] === null ? null : (string) $suggestion['quantity'],
                'purchase_unit' => $suggestion['unit'], 'purchase_note' => $suggestion['note'],
            ]);
        }

        return $item->fresh();
    }
}
