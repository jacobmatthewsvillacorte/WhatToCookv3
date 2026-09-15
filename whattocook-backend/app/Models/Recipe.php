<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = ['name', 'description', 'instructions', 'cooking_tips', 'region', 'prep_time', 'cook_time', 'servings', 'meal_type', 'difficulty', 'image', 'image_source_url', 'image_attribution', 'calories', 'protein', 'carbs', 'fat', 'created_by'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return filter_var($this->image, FILTER_VALIDATE_URL)
            ? $this->image
            : asset('storage/'.ltrim($this->image, '/'));
    }

    public function ingredients()
    {
        return $this->hasMany(Ingredient::class);
    }

    public function favorites()
    {
        return $this->hasMany(RecipeFavorite::class);
    }

    public function reviews()
    {
        return $this->hasMany(RecipeReview::class);
    }
}
