<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->json('carb_side')->nullable();
            $table->string('estimated_carbs')->nullable();
            $table->text('diabetic_friendly_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', fn (Blueprint $table) => $table->dropColumn(['carb_side', 'estimated_carbs', 'diabetic_friendly_note']));
    }
};
