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
            $required = $required === null ? null : $required * $scale;
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
        $matchingItems = $this->matchingLots($ingredientName, $pantry);
        $compatibleItems = array_values(array_filter(
            $matchingItems,
            fn (PantryItem $item) => $this->unitsAreCompatible($unit, $item->unit) || $this->packageConversionFor($item, $unit) !== null,
        ));
        $quantity = 0.0;
        foreach ($compatibleItems as $item) {
            $quantity += $this->quantityInUnit($item, $unit);
        }

        $packageItems = [];
        $units = [];
        foreach ($matchingItems as $item) {
            if ($item->unit) {
                $units[$item->unit] = $item->unit;
            }
            if (! $this->unitsAreCompatible($unit, $item->unit)) {
                $packageItems[] = ['id' => $item->id, 'name' => $item->name, 'quantity' => $item->quantity_value, 'unit' => $item->unit];
            }
        }

        return [
            'has_stock' => $matchingItems !== [],
            'quantity' => $quantity,
            'has_compatible_unit' => $compatibleItems !== [],
            'pantry_units' => array_values($units),
            'package_items' => $packageItems,
        ];
    }

    /**
     * Pantry lots whose name matches the ingredient. The pantry is indexed once
     * (normalised name + word list as plain arrays) because this runs for every
     * recipe ingredient x every pantry row; Eloquent attribute access and
     * collection closures in that inner loop were the dominant latency cost.
     *
     * @return array<int, PantryItem>
     */
    private function matchingLots(string $ingredientName, Collection $pantry): array
    {
        $key = $this->normalise($ingredientName);
        $words = array_filter(explode(' ', $key), fn (string $word) => strlen($word) > 2);
        $matches = [];
        foreach ($this->indexPantry($pantry) as [$item, $name, $nameWords]) {
            if ($key === $name || str_contains($key, $name) || str_contains($name, $key) || array_intersect($words, $nameWords) !== []) {
                $matches[] = $item;
            }
        }

        return $matches;
    }

    /** @var array<int, array{0: Collection, 1: int, 2: array}> Pantry indexes keyed by collection object id. */
    private array $pantryIndexes = [];

    private function indexPantry(Collection $pantry): array
    {
        $id = spl_object_id($pantry);
        $cached = $this->pantryIndexes[$id] ?? null;
        // Re-index if this id now belongs to a different or changed collection.
        if ($cached === null || $cached[0] !== $pantry || $cached[1] !== $pantry->count()) {
            $rows = [];
            foreach ($pantry as $item) {
                $name = $this->normalise((string) $item->name);
                $rows[] = [$item, $name, explode(' ', $name)];
            }
            $cached = $this->pantryIndexes[$id] = [$pantry, $pantry->count(), $rows];
        }

        return $cached[2];
    }

    /**
     * Returns pantry lots that can cover a recipe ingredient in the requested
     * unit. Keeping this public lets cooking deduct the same stock that the
     * preflight matcher reported, including split lots and kg/g conversions.
     */
    public function matchingPantryItemsFor(string $ingredientName, ?string $unit, Collection $pantry): Collection
    {
        return collect($this->matchingLots($ingredientName, $pantry))
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

    /** @var array<string, string> Memo: matching normalises the same few names thousands of times per request. */
    private array $normalised = [];

    private function normalise(string $name): string
    {
        return $this->normalised[$name] ??= strtolower(trim(preg_replace('/\s+/', ' ', $name)));
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

    /** Package conversions already loaded by this instance, keyed by scope|ingredient|package unit. */
    private array $conversionCache = [];

    private function packageConversionFor(PantryItem $item, ?string $recipeUnit): ?IngredientPackageConversion
    {
        if (! $recipeUnit || $this->unitsAreCompatible($recipeUnit, $item->unit)) return null;

        // One query per (scope, ingredient, package unit) per request instead of
        // one per pantry item per recipe ingredient.
        $key = implode('|', [$item->user_id, $item->family_id, $this->normalise($item->name), $this->normalise((string) $item->unit)]);
        $this->conversionCache[$key] ??= IngredientPackageConversion::query()
            ->where('user_id', $item->user_id)
            ->where('family_id', $item->family_id)
            ->where('ingredient_name', $this->normalise($item->name))
            ->where('package_unit', $this->normalise((string) $item->unit))
            ->get();

        return $this->conversionCache[$key]
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

        return $unit;
    }

    private function unitFactor(string $unit): ?float
    {
        return match ($this->normalise($unit)) {
            'kg', 'kilogram', 'kilograms', 'l', 'litre', 'litres', 'liter', 'liters' => 1000,
            'g', 'gram', 'grams', 'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters' => 1,
            default => null,
        };
    }
}
