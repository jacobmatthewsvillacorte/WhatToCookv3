<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealPlan extends Model
{
    protected $fillable = ['user_id', 'family_id', 'meal_plan_batch_id', 'recipe_id', 'planned_date', 'meal_type', 'status', 'servings', 'serving_equivalents', 'diner_profile_ids', 'child_meal_plan', 'selection_reason', 'completed_at', 'completion_method', 'consumed_items'];

    protected function casts(): array
    {
        return ['planned_date' => 'date', 'servings' => 'integer', 'serving_equivalents' => 'decimal:2', 'diner_profile_ids' => 'array', 'child_meal_plan' => 'array', 'selection_reason' => 'array', 'completed_at' => 'datetime', 'consumed_items' => 'array'];
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
