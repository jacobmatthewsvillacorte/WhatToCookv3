<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipeImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_owner_can_upload_an_image_and_receive_a_public_url(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $recipe = Recipe::create(['name' => 'Adobo', 'instructions' => 'Cook.', 'created_by' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->post("/api/recipes/{$recipe->id}/image", [
            'image' => $this->validPng(),
        ]);

        $response->assertOk()->assertJsonPath('image_url', $recipe->fresh()->image_url);
        $this->assertStringStartsWith('recipes/', $recipe->fresh()->image);
        Storage::disk('public')->assertExists($recipe->fresh()->image);
    }

    public function test_guest_cannot_upload_a_recipe_image(): void
    {
        $recipe = Recipe::create(['name' => 'Adobo', 'instructions' => 'Cook.']);

        $this->post("/api/recipes/{$recipe->id}/image", [
            'image' => UploadedFile::fake()->create('adobo.png', 10, 'image/png'),
        ])->assertUnauthorized();
    }

    public function test_non_owner_cannot_upload_a_recipe_image(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $recipe = Recipe::create(['name' => 'Adobo', 'instructions' => 'Cook.', 'created_by' => $owner->id]);

        $this->actingAs($otherUser, 'sanctum')->post("/api/recipes/{$recipe->id}/image", [
            'image' => UploadedFile::fake()->create('adobo.png', 10, 'image/png'),
        ])->assertForbidden();
    }

    public function test_upload_rejects_non_image_files_and_files_over_five_mb(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $recipe = Recipe::create(['name' => 'Adobo', 'instructions' => 'Cook.', 'created_by' => $user->id]);

        $this->actingAs($user, 'sanctum')->post("/api/recipes/{$recipe->id}/image", [
            'image' => UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->actingAs($user, 'sanctum')->post("/api/recipes/{$recipe->id}/image", [
            'image' => UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_external_recipe_images_are_not_exposed_after_local_only_migration(): void
    {
        $recipe = Recipe::create([
            'name' => 'Sinigang',
            'instructions' => 'Cook.',
            'image' => 'https://images.example.test/sinigang.jpg',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/recipes/{$recipe->id}")
            ->assertOk()
            ->assertJsonPath('image', 'https://images.example.test/sinigang.jpg')
            ->assertJsonPath('image_url', null);
    }

    public function test_missing_recipe_image_returns_a_null_url_for_the_frontend_fallback(): void
    {
        $recipe = Recipe::create(['name' => 'Plain recipe', 'instructions' => 'Cook.']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/recipes/{$recipe->id}")
            ->assertOk()
            ->assertJsonPath('image', null)
            ->assertJsonPath('image_url', null);
    }

    private function validPng(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('adobo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));
    }
}
