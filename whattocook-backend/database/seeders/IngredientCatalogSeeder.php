<?php

namespace Database\Seeders;

use App\Models\IngredientCatalog;
use App\Models\Ingredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class IngredientCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'rice',
                ['brown rice', 'white rice', 'bigas', 'kanin'],
                'grains',
                ['kg', 'g', 'cup'],
            ],
            [
                'milk',
                ['gatas'],
                'dairy',
                ['litre', 'ml', 'boxes'],
            ],
            [
                'eggs',
                ['egg', 'itlog'],
                'dairy',
                ['pieces'],
            ],
            [
                'onion',
                ['onions', 'sibuyas'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'cabbage',
                ['repolyo'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'cheese',
                ['cheeses', 'keso'],
                'dairy',
                ['g', 'packs', 'slices'],
            ],
            [
                'chicken',
                ['manok'],
                'meat',
                ['kg', 'g'],
            ],
            [
                'tomatoes',
                ['tomato', 'kamatis'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'carrots',
                ['carrot', 'karot'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'bihon noodles',
                ['bihon', 'pancit bihon'],
                'noodles',
                ['g', 'packs'],
            ],
            [
                'canned sardines',
                [
                    'sardines',
                    'sardine',
                    'de latang sardinas',
                    'latang sardinas',
                ],
                'canned goods',
                ['cans'],
            ],
            [
                'coconut milk',
                ['gata'],
                'canned goods',
                ['cans', 'ml'],
            ],
            [
                'tuna',
                [
                    'canned tuna',
                    'de latang tuna',
                    'latang tuna',
                ],
                'canned goods',
                ['cans'],
            ],
            [
                'salt',
                ['asin'],
                'seasoning',
                ['g', 'tsp'],
            ],
            [
                'yogurt',
                [],
                'dairy',
                ['cup', 'g'],
            ],
            [
                'pechay',
                ['petsay'],
                'produce',
                ['bundle'],
            ],
            [ 
                'pork',
                ['baboy'],
                'meat',
                ['kg', 'g'],
            ],
            [
                'garlic',
                ['bawang'],
                'produce',
                ['cloves', 'g'],
            ],
            [
                'potatoes',
                ['potato', 'patatas'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'cucumber',
                ['pipino'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'bell pepper',
                ['capsicum', 'siling pangsigang', 'siling pangluto'],
                'produce',
                ['pieces', 'g'],
            ],
            [
                'soy sauce',
                ['toyo'],
                'seasoning',
                ['ml', 'tbsp'],
            ],
            [
                'vinegar',
                ['suka'],
                'seasoning',
                ['ml', 'tbsp'],
            ],
            [
                'sugar',
                ['asukal'],
                'seasoning',
                ['g', 'tbsp'],
            ],
            [
                'flour',
                ['harina'],
                'baking',
                ['g', 'cup'],
            ],
            [
                'butter',
                ['manteka'],
                'dairy',
                ['g', 'tbsp'],
            ],
            [
                'oil',
                ['Cooking oil', 'mantika', 'vegetable oil', 'olive oil'],
                'seasoning',
                ['ml', 'tbsp', 'tsp'],
            ],
        ];

        foreach ($items as [$name, $aliases, $category, $units]) {
            IngredientCatalog::updateOrCreate(
                ['canonical_name' => $name],
                [
                    'aliases' => $aliases,
                    'category' => $category,
                    'is_approved' => true,
                    'default_units' => $units,
                ]
            );
        }

        Ingredient::select('name', 'unit')
            ->distinct()
            ->get()
            ->each(function ($ingredient) {
                $name = Str::lower(trim($ingredient->name));

                if ($name !== '') {
                    IngredientCatalog::firstOrCreate(
                        ['canonical_name' => $name],
                        [
                            'aliases' => [],
                            'category' => 'recipe ingredient',
                            'is_approved' => true,
                            'default_units' => array_values(
                                array_filter([$ingredient->unit])
                            ),
                        ]
                    );
                }
            });
    }
}