<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->string('purchase_quantity')->nullable()->after('quantity');
            $table->string('purchase_unit')->nullable()->after('unit');
            $table->string('purchase_note')->nullable()->after('purchase_unit');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn(['purchase_quantity', 'purchase_unit', 'purchase_note']);
        });
    }
};