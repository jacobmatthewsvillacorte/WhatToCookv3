<?php

namespace App\Services;

use App\Models\NutritionFood;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UsdaFoodDataService
{
    public function search(string $query, int $pageSize = 10): array
    {
        return $this->client()->get('/foods/search', [
            'query' => $query,
            'pageSize' => $pageSize,
        ])->throw()->json();
    }

    public function food(int $fdcId): array
    {
        return $this->client()->get("/food/{$fdcId}")->throw()->json();
    }

    /** Fetch and normalize USDA values to the application's per-100 g format. */
    public function cacheFood(int $fdcId): NutritionFood
    {
        $raw = $this->food($fdcId);
        $nutrients = $this->normalizeNutrients($raw['foodNutrients'] ?? []);

        return NutritionFood::updateOrCreate(['fdc_id' => $fdcId], [
            'description' => $raw['description'] ?? "USDA food {$fdcId}",
            'normalized_name' => Str::lower(trim($raw['description'] ?? "usda-{$fdcId}")),
            'source' => 'usda',
            'nutrients' => $nutrients,
            'raw_data' => $raw,
            'fetched_at' => now(),
        ]);
    }

    public function normalizedSearch(string $query, int $pageSize = 10): array
    {
        $data = $this->search($query, $pageSize);

        return $this->normalizedFoods($data);
    }

    public function normalizedFoods(array $data): array
    {
        return collect($data['foods'] ?? [])->map(fn (array $food) => [
            'fdc_id' => $food['fdcId'] ?? null,
            'description' => $food['description'] ?? null,
            'data_type' => $food['dataType'] ?? null,
            'brand_owner' => $food['brandOwner'] ?? null,
            'nutrients_per_100g' => $this->normalizeNutrients($food['foodNutrients'] ?? []),
        ])->values()->all();
    }

    public function normalizeNutrients(array $foodNutrients): array
    {
        // Zero is valid; it must not stand in for a value USDA did not provide.
        $result = ['calories' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'fiber' => 0, 'sodium' => 0, 'sugar' => 0, 'available_nutrients' => []];
        foreach ($foodNutrients as $item) {
            $name = strtolower($item['nutrient']['name'] ?? $item['nutrientName'] ?? '');
            $id = $item['nutrient']['id'] ?? $item['nutrientId'] ?? null;
            $value = $item['amount'] ?? $item['value'] ?? 0;
            $key = match (true) {
                $id === 1008 || str_contains($name, 'energy') => 'calories',
                $id === 1003 || $name === 'protein' => 'protein',
                $id === 1005 || str_contains($name, 'carbohydrate') => 'carbs',
                $id === 1004 || str_contains($name, 'total lipid') => 'fat',
                $id === 1079 || str_contains($name, 'fiber') => 'fiber',
                $id === 1093 || $name === 'sodium, na' || $name === 'sodium' => 'sodium',
                $id === 2000 || str_contains($name, 'sugars, total') => 'sugar',
                default => null,
            };
            if ($key !== null) {
                $result[$key] = (float) $value;
                $result['available_nutrients'][] = $key;
            }
        }

        $result['available_nutrients'] = array_values(array_unique($result['available_nutrients']));

        return $result;
    }

    private function client()
    {
        $key = config('services.usda.key');
        abort_unless(filled($key), 503, 'USDA nutrition service is not configured.');

        return Http::baseUrl(config('services.usda.base_url'))
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 200)
            ->withOptions(['verify' => config('services.usda.verify_ssl')])
            ->withQueryParameters(['api_key' => $key]);
    }
}
