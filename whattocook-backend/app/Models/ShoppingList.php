<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingList extends Model
{
    protected $fillable = ['user_id', 'family_id', 'ingredient_name', 'quantity', 'unit', 'purchase_quantity', 'purchase_unit', 'purchase_note', 'is_purchased'];
    protected function casts(): array { return ['is_purchased' => 'boolean']; }
}
