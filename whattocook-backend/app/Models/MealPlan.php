<?php

namespace App\Models;

use App\Services\SecondaryDishPlanner;
use Illuminate\Database\Eloquent\Model;

class MealPlan extends Model
{
    protected $appends = ['meal_name', 'main_dish'];
    protected $fillable = ['user_id', 'family_id', 'meal_plan_batch_id', 'recipe_id', 'planned_date', 'meal_type', 'status', 'servings', 'serving_equivalents', 'diner_profile_ids', 'child_meal_plan', 'selection_reason', 'carb_choice', 'include_carb', 'carb_side', 'secondary_dish', 'estimated_carbs', 'diabetic_friendly_note', 'completed_at', 'completion_method', 'consumed_items'];

    protected function casts(): array
    {
        return ['planned_date' => 'date', 'servings' => 'integer', 'serving_equivalents' => 'decimal:2', 'diner_profile_ids' => 'array', 'child_meal_plan' => 'array', 'selection_reason' => 'array', 'include_carb' => 'boolean', 'carb_side' => 'array', 'secondary_dish' => 'array', 'completed_at' => 'datetime', 'consumed_items' => 'array'];
    }

    public function getMealNameAttribute(): ?string
    {
        return $this->recipe?->name;
    }

    public function getMainDishAttribute(): ?array
    {
        $recipe = $this->recipe;
        if (! $recipe) {
            return null;
        }

        return [
            'name' => $recipe->name,
            'ingredients' => $recipe->ingredients->map(fn ($ingredient) => [
                'name' => $ingredient->name,
                'quantity' => $ingredient->quantity,
                'unit' => $ingredient->unit,
            ])->values(),
            'steps' => collect(preg_split('/\r\n|\r|\n/', (string) $recipe->instructions))->map(fn ($step) => trim(preg_replace('/^\d+[.)]\s*/', '', $step)))->filter()->values(),
        ];
    }

    public function getSecondaryDishAttribute($value): ?array
    {
        if ($value) {
            return is_array($value) ? $value : json_decode($value, true);
        }
        if (! $this->family_id || ! $this->recipe) {
            return null;
        }

        return app(SecondaryDishPlanner::class)->forRecipe($this->recipe);
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function batch()
    {
        return $this->belongsTo(MealPlanBatch::class, 'meal_plan_batch_id');
    }
}
