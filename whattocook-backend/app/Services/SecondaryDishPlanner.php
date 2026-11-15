<?php

namespace App\Services;

use App\Models\Recipe;

class SecondaryDishPlanner
{
    public function forRecipe(Recipe $main): array
    {
        $mainIngredients = $main->ingredients->pluck('name')->map(fn ($name) => strtolower((string) $name));
        $text = strtolower($main->name.' '.$main->instructions);
        $mainMethod = match (true) {
            str_contains($text, 'grill'), str_contains($text, 'inasal') => 'grill',
            str_contains($text, 'fry') => 'fry',
            str_contains($text, 'kinilaw'), str_contains($text, 'raw') => 'no_cook',
            str_contains($text, 'steam') => 'steam',
            str_contains($text, 'boil'), str_contains($text, 'simmer') => 'boil',
            str_contains($text, 'saute'), str_contains($text, 'stir-fry') => 'saute',
            default => 'other',
        };
        $dishes = [
            ['name' => 'Ensaladang Pipino at Kamatis', 'type' => 'salad', 'main_ingredient' => 'cucumber', 'method' => 'no_cook', 'ingredients' => [['name' => 'cucumber', 'quantity' => '1', 'unit' => 'pc'], ['name' => 'tomatoes', 'quantity' => '2', 'unit' => 'pcs'], ['name' => 'calamansi', 'quantity' => '2', 'unit' => 'pcs']], 'steps' => ['Wash and slice the vegetables.', 'Toss with calamansi juice and serve fresh.']],
            ['name' => 'Steamed Pechay', 'type' => 'vegetable', 'main_ingredient' => 'pechay', 'method' => 'steam', 'ingredients' => [['name' => 'pechay', 'quantity' => '1', 'unit' => 'bundle'], ['name' => 'garlic', 'quantity' => '2', 'unit' => 'cloves']], 'steps' => ['Wash and trim the pechay.', 'Steam until just tender and top with chopped garlic.']],
            ['name' => 'Nilagang Sayote at Karot', 'type' => 'vegetable soup', 'main_ingredient' => 'sayote', 'method' => 'boil', 'ingredients' => [['name' => 'sayote', 'quantity' => '1', 'unit' => 'pc'], ['name' => 'carrot', 'quantity' => '1', 'unit' => 'pc'], ['name' => 'water', 'quantity' => '3', 'unit' => 'cups']], 'steps' => ['Slice the vegetables.', 'Boil in water until tender and season lightly.']],
            ['name' => 'Ginisang Kangkong', 'type' => 'vegetable', 'main_ingredient' => 'kangkong', 'method' => 'saute', 'ingredients' => [['name' => 'kangkong', 'quantity' => '1', 'unit' => 'bundle'], ['name' => 'garlic', 'quantity' => '3', 'unit' => 'cloves'], ['name' => 'water', 'quantity' => '2', 'unit' => 'tbsp']], 'steps' => ['Wash and cut the kangkong.', 'Soften garlic in a pan, add kangkong and water, and cook until wilted.']],
            ['name' => 'Grilled Talong', 'type' => 'vegetable', 'main_ingredient' => 'eggplant', 'method' => 'grill', 'ingredients' => [['name' => 'eggplant', 'quantity' => '2', 'unit' => 'pcs'], ['name' => 'calamansi', 'quantity' => '2', 'unit' => 'pcs']], 'steps' => ['Grill the eggplants until soft.', 'Cool, peel, and serve with calamansi.']],
        ];
        $preferredMethods = match ($mainMethod) {
            'grill', 'fry', 'saute' => ['boil', 'no_cook', 'steam'],
            'boil' => ['no_cook', 'steam', 'saute'],
            'no_cook' => ['steam', 'boil', 'saute'],
            'steam' => ['saute', 'no_cook', 'boil'],
            default => ['no_cook', 'steam', 'boil', 'saute'],
        };
        usort($dishes, fn (array $a, array $b) => array_search($a['method'], $preferredMethods, true) <=> array_search($b['method'], $preferredMethods, true));
        foreach ($dishes as $dish) {
            $overlaps = $mainIngredients->contains(fn ($name) => str_contains($name, $dish['main_ingredient']));
            if ($dish['method'] !== $mainMethod && ! $overlaps) {
                unset($dish['main_ingredient'], $dish['method']);
                return $dish;
            }
        }

        return $dishes[0];
    }
}
