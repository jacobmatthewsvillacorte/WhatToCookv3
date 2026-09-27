<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ingredient_catalog', function (Blueprint $table) {
            $table->string('food_group')->nullable()->after('category');
        });

        DB::table('ingredient_catalog')->whereIn('canonical_name', ['rice', 'brown rice', 'white rice', 'potatoes', 'pasta', 'bihon noodles'])->update(['food_group' => 'rice_staple']);
        DB::table('ingredient_catalog')->whereIn('canonical_name', ['eggs', 'chicken', 'chicken breast', 'chicken thighs', 'pork', 'beef', 'tuna', 'canned tuna', 'canned sardines'])->update(['food_group' => 'ulam_protein']);
        DB::table('ingredient_catalog')->whereIn('canonical_name', ['tomatoes', 'carrots', 'onion', 'cabbage', 'pechay'])->update(['food_group' => 'vegetable']);
        DB::table('ingredient_catalog')->whereIn('canonical_name', ['banana', 'apple', 'orange', 'mango', 'papaya', 'pineapple', 'watermelon'])->update(['food_group' => 'fruit']);
        DB::table('ingredient_catalog')->whereIn('canonical_name', ['milk', 'cheese', 'yogurt'])->update(['food_group' => 'dairy']);
    }

    public function down(): void
    {
        Schema::table('ingredient_catalog', fn (Blueprint $table) => $table->dropColumn('food_group'));
    }
};
