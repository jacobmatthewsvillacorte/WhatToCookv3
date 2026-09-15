<?php

namespace Tests\Feature;

use App\Models\IngredientCatalog;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminRecipeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin/recipes')
            ->assertRedirect(route('admin.login'));

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Recipe Admin');
    }

    public function test_non_admins_cannot_open_the_recipe_manager(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/recipes')
            ->assertForbidden();
    }

    public function test_an_admin_can_sign_in_and_create_a_recipe_with_ingredients(): void
    {
        $admin = $this->admin();
        $this->approve('chicken', ['chicken breast']);

        $this->actingAs($admin)
            ->get('/admin/recipes')
            ->assertOk()
            ->assertSee('Recipe library');

        $this->get('/admin/recipes/create')
            ->assertOk()
            ->assertSee('Ingredients');

        $this->post('/admin/logout');

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.recipes.index'));
        $this->assertAuthenticatedAs($admin);

        $this->post('/admin/recipes', $this->recipePayload())
            ->assertRedirect();

        $recipe = Recipe::where('name', 'Admin Chicken Adobo')->firstOrFail();
        $this->assertSame($admin->id, $recipe->created_by);
        $this->assertDatabaseHas('ingredients', [
            'recipe_id' => $recipe->id,
            'name' => 'chicken',
            'quantity' => '500',
            'unit' => 'g',
        ]);
        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'image_source_url' => 'https://images.example.test/adobo',
            'image_attribution' => 'WhatToCook test photographer (CC BY 4.0)',
        ]);
    }

    public function test_an_admin_can_update_and_delete_a_recipe_created_by_someone_else(): void
    {
        $admin = $this->admin();
        $author = User::factory()->create();
        $recipe = Recipe::create([
            'name' => 'Original recipe',
            'instructions' => 'Original instructions.',
            'created_by' => $author->id,
        ]);
        $recipe->ingredients()->create(['name' => 'Old ingredient']);
        $this->approve('garlic');

        $this->actingAs($admin)
            ->put("/admin/recipes/{$recipe->id}", $this->recipePayload([
                'name' => 'Updated recipe',
                'ingredients' => [['name' => 'Garlic', 'quantity' => '4', 'unit' => 'cloves']],
            ]))
            ->assertRedirect(route('admin.recipes.edit', $recipe));

        $this->assertDatabaseHas('recipes', ['id' => $recipe->id, 'name' => 'Updated recipe']);
        $this->assertDatabaseMissing('ingredients', ['recipe_id' => $recipe->id, 'name' => 'Old ingredient']);
        $this->assertDatabaseHas('ingredients', ['recipe_id' => $recipe->id, 'name' => 'garlic']);

        $this->actingAs($admin)
            ->delete("/admin/recipes/{$recipe->id}")
            ->assertRedirect(route('admin.recipes.index'));

        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
    }

    public function test_admin_recipes_require_unique_approved_catalogue_ingredients(): void
    {
        $admin = $this->admin();
        $this->approve('chicken', ['chicken breast']);
        Recipe::create(['name' => 'Chicken Adobo', 'instructions' => 'Cook.']);

        $this->actingAs($admin)
            ->from('/admin/recipes/create')
            ->post('/admin/recipes', $this->recipePayload([
                'name' => ' chicken adobo ',
                'ingredients' => [['name' => 'Chicken breast', 'quantity' => '500', 'unit' => 'g']],
            ]))
            ->assertRedirect('/admin/recipes/create')
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->from('/admin/recipes/create')
            ->post('/admin/recipes', $this->recipePayload([
                'name' => 'Catalogue validation',
                'ingredients' => [['name' => 'Unapproved ingredient', 'quantity' => '1', 'unit' => 'cup']],
            ]))
            ->assertRedirect('/admin/recipes/create')
            ->assertSessionHasErrors('ingredients.0.name');

        $this->actingAs($admin)
            ->from('/admin/recipes/create')
            ->post('/admin/recipes', $this->recipePayload([
                'name' => 'Duplicate ingredients',
                'ingredients' => [
                    ['name' => 'Chicken', 'quantity' => '500', 'unit' => 'g'],
                    ['name' => 'Chicken breast', 'quantity' => '100', 'unit' => 'g'],
                ],
            ]))
            ->assertRedirect('/admin/recipes/create')
            ->assertSessionHasErrors('ingredients.1.name');
    }

    public function test_an_admin_can_search_usda_without_exposing_the_api_key_to_the_browser(): void
    {
        $admin = $this->admin();
        config()->set('services.usda.key', 'test-key');
        Http::fake(['https://api.nal.usda.gov/fdc/v1/foods/search*' => Http::response([
            'foods' => [['fdcId' => 123, 'description' => 'Chicken thigh', 'dataType' => 'Foundation']],
        ])]);

        $this->actingAs($admin)
            ->getJson('/admin/nutrition/search?query=chicken')
            ->assertOk()
            ->assertJsonPath('foods.0.fdc_id', 123)
            ->assertJsonPath('foods.0.description', 'Chicken thigh');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api_key=test-key'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function recipePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Admin Chicken Adobo',
            'description' => 'A savoury chicken dish.',
            'instructions' => 'Brown the chicken, then simmer it.',
            'cooking_tips' => 'Rest before serving.',
            'region' => 'Filipino',
            'meal_type' => 'dinner',
            'difficulty' => 'easy',
            'prep_time' => 15,
            'cook_time' => 35,
            'servings' => 4,
            'image' => 'https://example.test/chicken-adobo.jpg',
            'image_source_url' => 'https://images.example.test/adobo',
            'image_attribution' => 'WhatToCook test photographer (CC BY 4.0)',
            'calories' => 420,
            'protein' => 31,
            'carbs' => 8,
            'fat' => 27,
            'ingredients' => [
                ['name' => 'Chicken', 'quantity' => '500', 'unit' => 'g'],
            ],
        ], $overrides);
    }

    private function approve(string $name, array $aliases = []): void
    {
        IngredientCatalog::updateOrCreate(['canonical_name' => $name], [
            'aliases' => $aliases,
            'category' => 'test',
            'is_approved' => true,
            'default_units' => ['g'],
        ]);
    }
}
