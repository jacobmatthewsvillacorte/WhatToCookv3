<?php

namespace Tests\Feature;

use App\Models\PantryItem;
use App\Models\IngredientCatalog;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmedPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        IngredientCatalog::updateOrCreate(['canonical_name' => 'rice'], ['aliases' => ['bigas', 'kanin'], 'category' => 'grains', 'default_units' => ['kg', 'g'], 'is_approved' => true]);
        IngredientCatalog::updateOrCreate(['canonical_name' => 'eggs'], ['aliases' => ['itlog'], 'category' => 'dairy', 'default_units' => ['pieces'], 'is_approved' => true]);
    }

    public function test_a_purchased_checkbox_never_creates_stock_without_explicit_purchase_confirmation(): void
    {
        $user = User::factory()->create();
        $item = ShoppingList::create(['user_id' => $user->id, 'ingredient_name' => 'rice', 'quantity' => '1', 'unit' => 'kg']);

        $this->actingAs($user, 'sanctum')->putJson("/api/shopping-list/{$item->id}", ['is_purchased' => true])->assertOk();

        $this->assertDatabaseCount('pantry_items', 0);
    }

    public function test_unpurchased_shopping_duplicates_use_canonical_aliases_and_normalised_units(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/shopping-list', ['ingredient_name' => 'rice', 'quantity' => '1', 'unit' => 'kg'])->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/shopping-list', ['ingredient_name' => 'bigas', 'quantity' => '500', 'unit' => 'kilogram'])->assertOk();

        $this->assertDatabaseCount('shopping_lists', 1);
        $this->assertDatabaseHas('shopping_lists', ['ingredient_name' => 'rice', 'unit' => 'kg', 'quantity' => '501']);
    }

    public function test_confirmed_purchase_normalises_an_alias_and_unit_then_merges_the_matching_personal_lot(): void
    {
        $user = User::factory()->create();
        $shopping = ShoppingList::create(['user_id' => $user->id, 'ingredient_name' => 'bigas', 'quantity' => '500', 'unit' => 'grams']);
        PantryItem::create([
            'user_id' => $user->id, 'name' => 'rice', 'quantity' => '1', 'quantity_value' => 1, 'unit' => 'g',
            'purchase_source' => 'unknown', 'storage_type' => 'unknown', 'expiry_date' => now()->addMonths(6)->toDateString(),
            'is_expiry_estimated' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/shopping-list/{$shopping->id}/confirm-purchase", [
            'confirmed' => true, 'quantity' => 500, 'unit' => 'grams', 'purchase_date' => now()->toDateString(),
        ]);

        $response->assertOk()->assertJsonPath('pantry_item.name', 'rice')->assertJsonPath('pantry_item.unit', 'g');
        $this->assertDatabaseCount('pantry_items', 1);
        $this->assertDatabaseHas('pantry_items', ['name' => 'rice', 'quantity_value' => 501]);
        $this->assertDatabaseHas('pantry_items', ['name' => 'rice', 'expiry_date' => null]);
        $this->assertDatabaseHas('shopping_lists', ['id' => $shopping->id, 'is_purchased' => true]);
    }

    public function test_confirmed_family_purchase_creates_only_shared_family_stock_with_estimated_expiry_defaults(): void
    {
        $owner = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Santos'])->json();
        $shopping = ShoppingList::create(['user_id' => $owner->id, 'family_id' => $family['id'], 'ingredient_name' => 'itlog', 'quantity' => '12', 'unit' => 'piraso']);

        $this->actingAs($owner, 'sanctum')->postJson("/api/shopping-list/{$shopping->id}/confirm-purchase", [
            'confirmed' => true, 'quantity' => 12, 'unit' => 'piraso', 'purchase_source' => 'wet_market',
        ])->assertOk()->assertJsonPath('pantry_item.family_id', $family['id'])->assertJsonPath('pantry_item.name', 'eggs')
            ->assertJsonPath('pantry_item.unit', 'pieces')->assertJsonPath('pantry_item.is_expiry_estimated', true);

        $this->assertDatabaseMissing('pantry_items', ['user_id' => $owner->id, 'family_id' => null, 'name' => 'eggs']);
    }

    public function test_purchase_cannot_be_confirmed_twice(): void
    {
        $user = User::factory()->create();
        $shopping = ShoppingList::create(['user_id' => $user->id, 'ingredient_name' => 'rice', 'quantity' => '1', 'unit' => 'kg']);
        $payload = ['confirmed' => true, 'quantity' => 1, 'unit' => 'kg'];

        $this->actingAs($user, 'sanctum')->postJson("/api/shopping-list/{$shopping->id}/confirm-purchase", $payload)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/shopping-list/{$shopping->id}/confirm-purchase", $payload)->assertUnprocessable();
        $this->assertDatabaseCount('pantry_items', 1);
    }

    public function test_bought_items_can_be_deleted_in_bulk_without_touching_unpurchased_or_other_users_items(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ownedBought = ShoppingList::create(['user_id' => $user->id, 'ingredient_name' => 'rice', 'is_purchased' => true]);
        $ownedOpen = ShoppingList::create(['user_id' => $user->id, 'ingredient_name' => 'eggs', 'is_purchased' => false]);
        $otherBought = ShoppingList::create(['user_id' => $other->id, 'ingredient_name' => 'oil', 'is_purchased' => true]);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/shopping-list/purchased')
            ->assertOk()->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('shopping_lists', ['id' => $ownedBought->id]);
        $this->assertDatabaseHas('shopping_lists', ['id' => $ownedOpen->id]);
        $this->assertDatabaseHas('shopping_lists', ['id' => $otherBought->id]);
    }

    public function test_bought_items_can_be_deleted_in_the_active_family_scope_only(): void
    {
        $owner = User::factory()->create();
        $otherFamilyOwner = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Household'])->assertCreated()->json();
        $otherFamily = $this->actingAs($otherFamilyOwner, 'sanctum')->postJson('/api/families', ['name' => 'Other'])->assertCreated()->json();
        $familyBought = ShoppingList::create(['user_id' => $owner->id, 'family_id' => $family['id'], 'ingredient_name' => 'rice', 'is_purchased' => true]);
        $otherBought = ShoppingList::create(['user_id' => $otherFamilyOwner->id, 'family_id' => $otherFamily['id'], 'ingredient_name' => 'oil', 'is_purchased' => true]);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/shopping-list/purchased')
            ->assertOk()->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('shopping_lists', ['id' => $familyBought->id]);
        $this->assertDatabaseHas('shopping_lists', ['id' => $otherBought->id]);
    }
}
