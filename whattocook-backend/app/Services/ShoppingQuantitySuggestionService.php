<?php

namespace App\Services;

class ShoppingQuantitySuggestionService
{
    public function suggest(mixed $quantity, ?string $unit): array
    {
        if (! is_numeric($quantity) || trim((string) $unit) === '') {
            return ['quantity' => null, 'unit' => null, 'note' => 'Amount to confirm'];
        }

        $amount = (float) $quantity;
        $normalised = strtolower(trim((string) $unit));
        $normalised = match ($normalised) {
            'gram', 'grams' => 'g', 'kilogram', 'kilograms', 'kilo', 'kilos' => 'kg',
            'milliliter', 'milliliters', 'millilitre', 'millilitres' => 'ml',
            'liter', 'liters', 'litre', 'litres' => 'l',
            'piece', 'pieces', 'pc', 'pcs', 'egg', 'eggs' => 'pieces',
            'can', 'cans' => 'cans', 'pack', 'packs', 'packet', 'packets', 'sachet', 'sachets' => 'packs',
            'bottle', 'bottles' => 'bottles',
            default => $normalised,
        };

        if (in_array($normalised, ['pieces', 'cans', 'packs', 'bottles'], true)) {
            return ['quantity' => (float) ceil($amount), 'unit' => $normalised, 'note' => 'Rounded up to whole purchase units'];
        }
        if (in_array($normalised, ['g', 'kg'], true)) {
            return $normalised === 'kg' && $amount < 1
                ? ['quantity' => (float) (ceil($amount * 1000 / 10) * 10), 'unit' => 'g', 'note' => 'Rounded to a practical weight']
                : ['quantity' => (float) ceil(($normalised === 'kg' ? $amount * 1000 : $amount) / 10) * 10, 'unit' => 'g', 'note' => 'Rounded to a practical weight'];
        }
        if (in_array($normalised, ['ml', 'l', 'tsp', 'tbsp', 'cup'], true)) {
            $millilitres = match ($normalised) {
                'l' => $amount * 1000, 'tsp' => $amount * 5, 'tbsp' => $amount * 15, 'cup' => $amount * 240, default => $amount,
            };
            return ['quantity' => (float) (ceil($millilitres / 10) * 10), 'unit' => 'ml', 'note' => 'Approximate liquid quantity; adjust to the package available'];
        }

        return ['quantity' => null, 'unit' => null, 'note' => 'Amount to confirm'];
    }
}