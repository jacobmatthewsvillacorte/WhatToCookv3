<?php

namespace App\Services;

use App\Models\PantryItem;
use App\Models\IngredientPackageConversion;
use App\Models\Recipe;
use Illuminate\Support\Collection;

class RecipeMatcher
{
    private const SUBSTITUTES = [
        'calamansi' => ['lemon', 'lime'],
        'soy sauce' => ['tamari', 'coconut aminos'],
        'fish sauce' => ['soy sauce', 'salt'],
        'coconut milk' => ['evaporated milk', 'coconut cream'],
        'pork' => ['chicken', 'firm tofu'],
        'chicken' => ['pork', 'firm tofu'],
        'egg' => ['silken tofu', 'flax egg'],
    ];

    public function match(Recipe $recipe, Collection $pantry, int|float|null $plannedServings = null): array
    {
        $scale = $plannedServings === null ? 1.0 : $plannedServings / max(1, (int) ($recipe->servings ?: 1));
        $ingredients = $recipe->ingredients->map(function ($ingredient) use ($pantry, $scale) {
            $key = $this->normalise($ingredient->name);
            $required = ($this->number($ingredient->quantity) ?? null);
            $required = $required === null ? null : round($required * $scale, 3);
            $availability = $this->availabilityFor($ingredient->name, $ingredient->unit, $pantry);
            $hasStock = $availability['has_stock'];
            $inStock = $availability['quantity'];
            // We recognise the ingredient, but packs cannot be compared safely
            // with grams (or kg with pieces) without a package weight/count.
            $needsReview = $hasStock && $required !== null && ! $availability['has_compatible_unit'];
            // A recipe without a measurable quantity is considered covered when the ingredient exists.
            $sufficient = $hasStock && ! $needsReview && ($required === null || $inStock >= $required);
            $shortfall = $required === null || $needsReview ? null : max(0, round($required - $inStock, 3));

            return [
                'name' => $ingredient->name,
                'quantity' => $ingredient->quantity,
                'required_quantity' => $required,
                'unit' => $ingredient->unit,
                'available' => $hasStock,
                'sufficient' => $sufficient,
                'pantry_quantity' => $hasStock ? $inStock : null,
                'missing_quantity' => $shortfall,
                'needs_review' => $needsReview,
                'pantry_units' => $availability['pantry_units'],
                'package_items' => $availability['package_items'],
                'substitutes' => $this->substitutesFor($key),
            ];
        });

        $matched = $ingredients->where('sufficient', true)->count();
        $total = $ingredients->count();

        return [
            'recipe' => $recipe,
            'match_percentage' => $total ? (int) round(($matched / $total) * 100) : 0,
            'ingredients' => $ingredients->values(),
            'available_ingredients' => $ingredients->where('sufficient', true)->values(),
            'needs_review_ingredients' => $ingredients->where('needs_review', true)->values(),
            'missing_ingredients' => $ingredients->where('sufficient', false)->where('needs_review', false)->values(),
        ];
    }

    /** Returns stock in the recipe ingredient's unit for plan preview and cooking preflight. */
    public function availabilityFor(string $ingredientName, ?string $unit, Collection $pantry): array
    {
        $key = $this->normalise($ingredientName);
        $matchingItems = $pantry->filter(fn (PantryItem $item) => $this->isIngredientMatch($key, $this->normalise($item->name)));
        $compatibleItems = $this->matchingPantryItemsFor($ingredientName, $unit, $pantry);

        return [
            'has_stock' => $matchingItems->isNotEmpty(),
            'quantity' => $compatibleItems->sum(fn (PantryItem $item) => $this->quantityInUnit($item, $unit)),
            'has_compatible_unit' => $compatibleItems->isNotEmpty(),
            'pantry_units' => $matchingItems->pluck('unit')->filter()->unique()->values()->all(),
            'package_items' => $matchingItems->filter(fn (PantryItem $item) => ! $this->unitsAreCompatible($unit, $item->unit))
                ->map(fn (PantryItem $item) => ['id' => $item->id, 'name' => $item->name, 'quantity' => $item->quantity_value, 'unit' => $item->unit])->values()->all(),
        ];
    }

    /**
     * Returns pantry lots that can cover a recipe ingredient in the requested
     * unit. Keeping this public lets cooking deduct the same stock that the
     * preflight matcher reported, including split lots and kg/g conversions.
     */
    public function matchingPantryItemsFor(string $ingredientName, ?string $unit, Collection $pantry): Collection
    {
        $key = $this->normalise($ingredientName);

        return $pantry
            ->filter(fn (PantryItem $item) => $this->isIngredientMatch($key, $this->normalise($item->name)))
            ->filter(fn (PantryItem $item) => $this->unitsAreCompatible($unit, $item->unit) || $this->packageConversionFor($item, $unit) !== null)
            ->values();
    }

    /** Convert a pantry item's quantity into the requested recipe unit. */
    public function quantityInUnit(PantryItem $item, ?string $unit): float
    {
        if ($conversion = $this->packageConversionFor($item, $unit)) {
            return $this->convertQuantity((float) ($item->quantity_value ?? $this->number($item->quantity) ?? 0) * (float) $conversion->amount_per_package, $conversion->amount_unit, $unit);
        }
        return $this->convertQuantity(
            (float) ($item->quantity_value ?? $this->number($item->quantity) ?? 0),
            $item->unit,
            $unit,
        );
    }

    /** Convert a recipe amount back to the pantry item's stored unit for deduction. */
    public function quantityFromUnit(float $quantity, ?string $fromUnit, PantryItem $item): float
    {
        if ($conversion = $this->packageConversionFor($item, $fromUnit)) {
            $inConversionUnit = $this->convertQuantity($quantity, $fromUnit, $conversion->amount_unit);
            return $inConversionUnit / (float) $conversion->amount_per_package;
        }
        return $this->convertQuantity($quantity, $fromUnit, $item->unit);
    }

    /**
     * Convert a quantity between compatible units. Callers use this only after
     * matchingPantryItemsFor has confirmed the units are compatible.
     */
    public function convertQuantity(float $quantity, ?string $fromUnit, ?string $toUnit): float
    {
        if (! $fromUnit || ! $toUnit || $this->normalise($fromUnit) === $this->normalise($toUnit)) {
            return $quantity;
        }
        $from = $this->unitFactor($fromUnit);
        $to = $this->unitFactor($toUnit);

        return $from !== null && $to !== null ? $quantity * ($from / $to) : $quantity;
    }

    private function normalise(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    private function number(null|string|int|float $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $value, $match)) {
            return (float) $match[1] / (float) $match[2];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s+(\d+)\s*\/\s*(\d+)$/', $value, $match)) {
            return (float) $match[1] + ((float) $match[2] / (float) $match[3]);
        }

        return preg_match('/\d+(?:\.\d+)?/', $value, $match) ? (float) $match[0] : null;
    }

    private function isIngredientMatch(string $recipeIngredient, string $pantryIngredient): bool
    {
        return $recipeIngredient === $pantryIngredient
            || str_contains($recipeIngredient, $pantryIngredient)
            || str_contains($pantryIngredient, $recipeIngredient)
            || collect(explode(' ', $recipeIngredient))->contains(fn (string $word) => strlen($word) > 2 && in_array($word, explode(' ', $pantryIngredient), true));
    }

    private function substitutesFor(string $ingredient): array
    {
        foreach (self::SUBSTITUTES as $name => $substitutes) {
            if ($ingredient === $name || str_contains($ingredient, $name)) {
                return $substitutes;
            }
        }

        return [];
    }

    private function unitsAreCompatible(?string $recipeUnit, ?string $pantryUnit): bool
    {
        if (! $recipeUnit || ! $pantryUnit) {
            return true;
        }

        return $this->unitGroup($recipeUnit) === $this->unitGroup($pantryUnit);
    }

    private function packageConversionFor(PantryItem $item, ?string $recipeUnit): ?IngredientPackageConversion
    {
        if (! $recipeUnit || $this->unitsAreCompatible($recipeUnit, $item->unit)) return null;
        return IngredientPackageConversion::query()
            ->where('user_id', $item->user_id)
            ->where('family_id', $item->family_id)
            ->where('ingredient_name', $this->normalise($item->name))
            ->where('package_unit', $this->normalise((string) $item->unit))
            ->get()
            ->first(fn (IngredientPackageConversion $conversion) => $this->unitsAreCompatible($recipeUnit, $conversion->amount_unit));
    }

    private function unitGroup(string $unit): string
    {
        $unit = $this->normalise($unit);
        if (in_array($unit, ['g', 'gram', 'grams', 'kg', 'kilogram', 'kilograms'], true)) {
            return 'weight';
        }
        if (in_array($unit, ['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters', 'l', 'litre', 'litres', 'liter', 'liters'], true)) {
            return 'volume';
        }
        if (in_array($unit, ['cup', 'cups', 'c', 'tbsp', 'tablespoon', 'tablespoons', 'tsp', 'teaspoon', 'teaspoons'], true)) {
            return 'volume';
        }
        if (in_array($unit, ['pc', 'pcs', 'piece', 'pieces', 'item', 'items'], true)) {
            return 'count';
        }

        return $unit;
    }

    private function unitFactor(string $unit): ?float
    {
        return match ($this->normalise($unit)) {
            'kg', 'kilogram', 'kilograms', 'l', 'litre', 'litres', 'liter', 'liters' => 1000,
            'g', 'gram', 'grams', 'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters' => 1,
            'cup', 'cups', 'c' => 240,
            'tbsp', 'tablespoon', 'tablespoons' => 15,
            'tsp', 'teaspoon', 'teaspoons' => 5,
            default => null,
        };
    }
}
