<?php

namespace App\Services;

use App\Models\PantryItem;
use App\Models\ShoppingList;
use Illuminate\Validation\ValidationException;

class ConfirmedPurchaseService
{
    public function __construct(
        private IngredientCatalogService $catalog,
        private PantryFreshnessService $freshness,
    ) {}

    /**
     * Create stock only after the caller has explicitly confirmed the editable
     * purchase details. Compatible purchases are added to the same pantry lot.
     */
    public function record(ShoppingList $shoppingItem, array $data, int $userId): PantryItem
    {
        $name = $this->catalog->approvedCanonicalName($data['name'] ?? $shoppingItem->ingredient_name);
        if ($name === null) {
            throw ValidationException::withMessages(['name' => ['Choose a recognised ingredient before confirming this purchase.']]);
        }

        $unit = $this->normaliseUnit($data['unit'] ?? $shoppingItem->unit);
        if ($unit === '') {
            throw ValidationException::withMessages(['unit' => ['Confirm a unit before adding stock.']]);
        }
        $quantity = (float) ($data['quantity'] ?? $shoppingItem->quantity);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Confirm a positive quantity before adding stock.']]);
        }

        $source = $data['purchase_source'] ?? 'unknown';
        $storage = $data['storage_type'] ?? 'unknown';
        $purchaseDate = $data['purchase_date'] ?? now()->toDateString();
        $printedExpiry = $data['expiry_date'] ?? null;
        if ($printedExpiry !== null && $printedExpiry < $purchaseDate) {
            throw ValidationException::withMessages(['expiry_date' => ['The expiry date must be on or after the purchase date.']]);
        }
        $estimate = $this->freshness->estimate($name, $unit, $storage, $source);
        $expiry = $printedExpiry ?: $estimate['expiry_date'];

        // Keep lots with different expiry/source details separate so freshness
        // prompts remain accurate. Matching units still consolidate duplicates.
        $lotQuery = PantryItem::query()
            ->when(
                $shoppingItem->family_id === null,
                fn ($q) => $q->where('user_id', $userId)->whereNull('family_id'),
                fn ($q) => $q->where('family_id', $shoppingItem->family_id)
            )
            ->whereRaw('lower(name) = ?', [strtolower($name)])
            ->whereRaw('lower(unit) = ?', [$unit])
            ->whereIn('freshness_status', ['fresh', 'review']);

        // Undated shelf-stable staples are one stock pool. Dated products keep
        // separate lots so their expiry information remains meaningful.
        if ($expiry === null) {
            $lotQuery->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('is_expiry_estimated', true));
        } else {
            $lotQuery->whereDate('expiry_date', $expiry)
                ->where('purchase_source', $source)
                ->where('storage_type', $storage);
        }
        $lot = $lotQuery->lockForUpdate()->first();

        if ($lot) {
            $total = round((float) $lot->quantity_value + $quantity, 3);
            $lot->update([
                'quantity_value' => $total,
                'quantity' => (string) $total,
                'expiry_date' => $expiry,
                'freshness_review_date' => $expiry === null ? null : $lot->freshness_review_date,
                'freshness_status' => $expiry === null ? 'fresh' : $lot->freshness_status,
            ]);
            return $lot->fresh();
        }

        return PantryItem::create([
            'user_id' => $userId,
            'family_id' => $shoppingItem->family_id,
            'name' => $name,
            'quantity' => (string) $quantity,
            'quantity_value' => $quantity,
            'unit' => $unit,
            'purchase_date' => $purchaseDate,
            'purchase_source' => $source,
            'storage_type' => $storage,
            'freshness_condition' => $data['freshness_condition'] ?? 'unknown',
            'expiry_date' => $expiry,
            'freshness_review_date' => $data['freshness_review_date'] ?? ($printedExpiry ? $printedExpiry : $estimate['review_date']),
            'freshness_status' => $printedExpiry ? 'fresh' : $estimate['status'],
            'freshness_confidence' => $printedExpiry ? 'high' : $estimate['confidence'],
            'is_expiry_estimated' => ! (bool) $printedExpiry,
        ]);
    }

    public function normaliseUnit(?string $unit): string
    {
        $unit = strtolower(trim((string) $unit));
        return match ($unit) {
            'gram', 'grams' => 'g',
            'kilogram', 'kilograms', 'kilo', 'kilos' => 'kg',
            'liter', 'liters', 'litre', 'litres' => 'l',
            'milliliter', 'milliliters', 'millilitre', 'millilitres' => 'ml',
            'piece', 'pieces', 'pc', 'pcs', 'piraso', 'pirasos' => 'pieces',
            'can', 'cans', 'lata', 'latas' => 'cans',
            'pack', 'packs', 'packet', 'packets', 'sachet', 'sachets', 'pakete' => 'packs',
            'bottle', 'bottles', 'botelya', 'botelyas' => 'bottles',
            default => $unit,
        };
    }
}
