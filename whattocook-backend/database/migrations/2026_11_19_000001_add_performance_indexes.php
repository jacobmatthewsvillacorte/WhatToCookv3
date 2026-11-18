<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys do not get indexes automatically on SQLite. These cover the
 * lookups used by the list/read endpoints. See docs/10-database-and-indexes.md.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private function indexes(): array
    {
        return [
            'ingredients' => ['ingredients_recipe_id_idx' => ['recipe_id']],
            'pantry_items' => ['pantry_items_scope_freshness_idx' => ['user_id', 'family_id', 'freshness_status']],
            'meal_plans' => [
                'meal_plans_user_id_idx' => ['user_id'],
                'meal_plans_family_id_idx' => ['family_id'],
                'meal_plans_recipe_id_idx' => ['recipe_id'],
                'meal_plans_batch_id_idx' => ['meal_plan_batch_id'],
            ],
            'shopping_lists' => ['shopping_lists_scope_idx' => ['user_id', 'family_id']],
            'family_members' => ['family_members_user_status_idx' => ['user_id', 'status']],
            'meal_history' => ['meal_history_scope_idx' => ['user_id', 'family_id']],
            'recipe_reviews' => ['recipe_reviews_recipe_id_idx' => ['recipe_id']],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasColumns($table, $columns) || $this->exists($table, $name)) {
                    continue;
                }
                Schema::table($table, fn ($t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && $this->exists($table, $name)) {
                    Schema::table($table, fn ($t) => $t->dropIndex($name));
                }
            }
        }
    }

    private function exists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $name);
    }
}
;
