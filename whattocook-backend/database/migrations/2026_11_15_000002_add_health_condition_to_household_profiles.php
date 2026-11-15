<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_profiles', function (Blueprint $table) {
            $table->string('health_condition', 30)->default('none');
        });
    }

    public function down(): void
    {
        Schema::table('household_profiles', fn (Blueprint $table) => $table->dropColumn('health_condition'));
    }
};
