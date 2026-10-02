<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Recipe extends Model
{
    protected $fillable = ['name', 'description', 'instructions', 'cooking_tips', 'region', 'prep_time', 'cook_time', 'servings', 'meal_type', 'difficulty', 'image', 'image_source_url', 'image_attribution', 'calories', 'protein', 'carbs', 'fat', 'created_by'];

    protected $hidden = ['image_source_url', 'image_attribution'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return Str::startsWith($this->image, 'recipes/')
            ? url('/storage/'.$this->image)
            : null;
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
