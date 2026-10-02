<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('recipes')
            ->where(function ($query) {
                $query->where('image', 'like', 'http://%')
                    ->orWhere('image', 'like', 'https://%');
            })
            ->update([
                'image' => null,
                'image_source_url' => null,
                'image_attribution' => null,
            ]);
    }

    public function down(): void
    {
        // External image URLs are intentionally not restored.
    }
};