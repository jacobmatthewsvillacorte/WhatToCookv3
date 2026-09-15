<?php

namespace Tests\Feature;

use App\Models\HouseholdProfile;
use App\Models\PantryItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PantryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_return_json_401_instead_of_a_login_redirect(): void
    {
        $this->getJson('/api/nutrition/search?query=chicken')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_a_user_can_register_and_receive_an_access_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'test@example.com')
            ->assertJsonStructure(['token']);
    }

    public function test_a_user_can_store_an_expiring_pantry_item(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
            'name' => 'Milk',
            'quantity' => '1',
            'unit' => 'litre',
            'purchase_date' => '2026-07-20',
            'expiry_date' => '2026-07-25',
        ]);

        $response->assertCreated()->assertJsonPath('item.expiry_date', '2026-07-25T00:00:00.000000Z');
        $this->assertDatabaseHas('pantry_items', [
            'name' => 'Milk', 'quantity_value' => 1, 'purchase_source' => 'unknown',
            'is_expiry_estimated' => false, 'expiry_date' => '2026-07-25 00:00:00',
        ]);
    }

    public function test_pantry_updates_cannot_change_an_items_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = PantryItem::create(['user_id' => $owner->id, 'name' => 'Rice']);

        $this->actingAs($owner, 'sanctum')->putJson("/api/pantry/{$item->id}", [
            'name' => 'Brown rice',
            'user_id' => $otherUser->id,
        ])->assertOk();

        $this->assertDatabaseHas('pantry_items', [
            'id' => $item->id,
            'user_id' => $owner->id,
            'name' => 'Brown rice',
        ]);
    }

    public function test_profiles_are_saved_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/profile', [
            'allergies' => ['peanuts'],
            'dietary_restrictions' => ['vegetarian'],
            'visible_to_family' => ['allergies' => false],
        ])->assertOk()->assertJsonPath('allergies.0', 'peanuts');

        $this->assertDatabaseHas('profiles', ['user_id' => $user->id]);
    }

    public function test_family_members_can_view_the_shared_pantry_with_a_default_expiry_date(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        try {
            $owner = User::factory()->create();
            $member = User::factory()->create();
            $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Santos Family'])->json();

            $invitation = $this->actingAs($owner, 'sanctum')->postJson("/api/families/{$family['id']}/members", ['email' => $member->email, 'role' => 'member'])->assertCreated()->json('invitation');
            $this->actingAs($member, 'sanctum')->postJson("/api/family-invitations/{$invitation['id']}/accept")->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson('/api/pantry', ['name' => 'Eggs', 'quantity' => 12, 'unit' => 'pieces', 'family_id' => $family['id']])->assertCreated();

            $this->actingAs($member, 'sanctum')->getJson('/api/pantry')
                ->assertOk()->assertJsonPath('0.name', 'Eggs')->assertJsonPath('0.expiry_date', '2026-07-22T00:00:00.000000Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_items_without_a_printed_expiry_are_prompted_for_review_the_next_day_and_can_be_extended(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        try {
            $user = User::factory()->create();
            $item = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
                'name' => 'Tomatoes', 'quantity' => 4, 'unit' => 'pieces',
                'purchase_source' => 'wet_market', 'storage_type' => 'room_temperature',
                'freshness_condition' => 'good',
            ])->assertCreated()->json('item');

            $this->assertSame('2026-07-22T00:00:00.000000Z', $item['expiry_date']);
            $this->assertSame('fresh', $item['freshness_status']);
            $this->assertTrue($item['is_expiry_estimated']);
            $this->assertDatabaseHas('pantry_items', ['id' => $item['id'], 'freshness_confidence' => 'low']);

            $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item['id']}/freshness", [
                'action' => 'still_fresh', 'review_date' => '2026-07-25',
            ])->assertOk()->assertJsonPath('item.freshness_status', 'fresh')
                ->assertJsonPath('item.expiry_date', '2026-07-22T00:00:00.000000Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_still_fresh_sets_tomorrows_expiry_for_dated_and_undated_unexpired_items(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        try {
            $user = User::factory()->create();
            $undated = PantryItem::create([
                'user_id' => $user->id, 'name' => 'Eggs', 'quantity' => '6', 'quantity_value' => 6,
                'unit' => 'pieces', 'freshness_status' => 'fresh', 'is_expiry_estimated' => false,
            ]);
            $dated = PantryItem::create([
                'user_id' => $user->id, 'name' => 'Milk', 'quantity' => '1', 'quantity_value' => 1,
                'unit' => 'litre', 'freshness_status' => 'fresh', 'expiry_date' => '2026-07-22',
            ]);

            foreach ([$undated, $dated] as $item) {
                $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item->id}/freshness", ['action' => 'still_fresh'])
                    ->assertOk()
                    ->assertJsonPath('item.freshness_status', 'fresh')
                    ->assertJsonPath('item.expiry_date', '2026-07-22T00:00:00.000000Z')
                    ->assertJsonPath('item.freshness_review_date', '2026-07-22T00:00:00.000000Z');
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_undated_shelf_stable_stock_stays_fresh_and_adds_to_the_existing_quantity(): void
    {
        $user = User::factory()->create();
        $first = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
            'name' => 'Rice', 'quantity' => 4, 'unit' => 'kg',
        ])->assertCreated()->json('item');

        $this->assertNull($first['expiry_date']);
        $this->assertNull($first['freshness_review_date']);

        $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
            'name' => 'Rice', 'quantity' => 10, 'unit' => 'kg',
        ])->assertCreated()->assertJsonPath('item.quantity_value', '14.000');

        $this->assertDatabaseCount('pantry_items', 1);
        $this->assertDatabaseHas('pantry_items', ['id' => $first['id'], 'name' => 'Rice', 'quantity_value' => 14, 'expiry_date' => null]);
    }

    public function test_an_expired_item_cannot_be_marked_still_fresh(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        try {
            $user = User::factory()->create();
            $item = PantryItem::create([
                'user_id' => $user->id, 'name' => 'Milk', 'quantity' => '1', 'quantity_value' => 1,
                'unit' => 'litre', 'freshness_status' => 'review', 'expiry_date' => '2026-07-20',
            ]);

            $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item->id}/freshness", ['action' => 'still_fresh'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('action');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_source_aware_estimates_keep_sari_sari_packaged_goods_reviewable_and_fresh_goods_conservative(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        try {
            $user = User::factory()->create();

            $packaged = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
                'name' => 'Canned Sardines', 'quantity' => 2, 'unit' => 'cans',
                'purchase_source' => 'sari_sari_store',
            ])->assertCreated()->json('item');

            $fresh = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
                'name' => 'Pechay', 'quantity' => 1, 'unit' => 'bundle',
                'purchase_source' => 'sari_sari_store',
            ])->assertCreated()->json('item');

            $this->assertSame('2027-01-21T00:00:00.000000Z', $packaged['expiry_date']);
            $this->assertSame('2026-08-21T00:00:00.000000Z', $packaged['freshness_review_date']);
            $this->assertSame('fresh', $packaged['freshness_status']);
            $this->assertSame('low', $packaged['freshness_confidence']);
            $this->assertSame('2026-07-22T00:00:00.000000Z', $fresh['freshness_review_date']);
            $this->assertSame('fresh', $fresh['freshness_status']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_printed_expiry_added_during_update_replaces_the_estimate(): void
    {
        $user = User::factory()->create();
        $item = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', [
            'name' => 'Tomatoes', 'quantity' => 4, 'unit' => 'pieces',
        ])->assertCreated()->json('item');

        $this->actingAs($user, 'sanctum')->putJson("/api/pantry/{$item['id']}", [
            'expiry_date' => '2026-12-31',
        ])->assertOk()
            ->assertJsonPath('item.expiry_date', '2026-12-31T00:00:00.000000Z')
            ->assertJsonPath('item.freshness_review_date', '2026-12-31T00:00:00.000000Z')
            ->assertJsonPath('item.freshness_status', 'fresh')
            ->assertJsonPath('item.freshness_confidence', 'high')
            ->assertJsonPath('item.is_expiry_estimated', false);
    }

    public function test_pantry_items_require_a_positive_quantity_and_unit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry', ['name' => 'Rice', 'quantity' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'unit']);
    }

    public function test_due_items_are_asked_for_freshness_review_not_automatically_marked_spoiled(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        try {
            $user = User::factory()->create();
            $item = PantryItem::create([
                'user_id' => $user->id, 'name' => 'Yogurt', 'quantity' => '1', 'quantity_value' => 1,
                'unit' => 'cup', 'expiry_date' => '2026-07-22', 'freshness_review_date' => '2026-07-22',
                'freshness_status' => 'fresh', 'is_expiry_estimated' => false,
            ]);

            $this->actingAs($user, 'sanctum')->getJson('/api/pantry')
                ->assertOk()->assertJsonPath('0.id', $item->id)->assertJsonPath('0.freshness_status', 'review');
            $this->assertDatabaseHas('pantry_items', ['id' => $item->id, 'freshness_status' => 'fresh']);

            $this->artisan('pantry:refresh-freshness')->assertSuccessful();
            $this->assertDatabaseHas('pantry_items', ['id' => $item->id, 'freshness_status' => 'review']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_user_can_create_a_recipe_with_instructions_and_nutrition(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Chicken Adobo',
            'region' => 'Filipino',
            'instructions' => 'Simmer the chicken in the sauce until tender.',
            'cooking_tips' => 'Rest before serving.',
            'calories' => 420.5,
            'protein' => 31,
            'carbs' => 8,
            'fat' => 29,
            'ingredients' => [['name' => 'Chicken', 'quantity' => '1', 'unit' => 'kg']],
        ])->assertCreated()->assertJsonPath('instructions', 'Simmer the chicken in the sauce until tender.');

        $this->assertDatabaseHas('recipes', ['name' => 'Chicken Adobo', 'region' => 'Filipino']);
    }

    public function test_recipe_recommendations_show_missing_ingredients_and_can_generate_a_shopping_list(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry', ['name' => 'Chicken', 'quantity' => '1', 'unit' => 'kg']);
        $recipe = $this->actingAs($user, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Chicken Adobo', 'instructions' => 'Cook.',
            'ingredients' => [
                ['name' => 'Chicken', 'quantity' => '1', 'unit' => 'kg'],
                ['name' => 'Soy sauce', 'quantity' => '1', 'unit' => 'cup'],
            ],
        ])->json();

        $this->actingAs($user, 'sanctum')->getJson('/api/recipes/recommendations')
            ->assertOk()->assertJsonPath('recommendations.0.match_percentage', 50)
            ->assertJsonPath('recommendations.0.missing_ingredients.0.name', 'Soy sauce');

        $this->actingAs($user, 'sanctum')->postJson("/api/recipes/{$recipe['id']}/shopping-list")
            ->assertCreated()->assertJsonPath('items.0.ingredient_name', 'Soy sauce');
    }

    public function test_recipe_matching_sums_stock_converts_units_and_reports_the_actual_deficit(): void
    {
        $user = User::factory()->create();
        PantryItem::create(['user_id' => $user->id, 'name' => 'Chicken breast', 'quantity' => '500', 'quantity_value' => 500, 'unit' => 'g', 'freshness_status' => 'fresh']);
        PantryItem::create(['user_id' => $user->id, 'name' => 'Chicken thighs', 'quantity' => '0.5', 'quantity_value' => .5, 'unit' => 'kg', 'freshness_status' => 'fresh']);
        $recipe = $this->actingAs($user, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Chicken Dish', 'instructions' => 'Cook.',
            'ingredients' => [
                ['name' => 'Chicken', 'quantity' => '1', 'unit' => 'kg'],
                ['name' => 'Soy sauce', 'quantity' => '1/2', 'unit' => 'cup'],
            ],
        ])->json();

        $this->actingAs($user, 'sanctum')->getJson("/api/recipes/{$recipe['id']}/match")
            ->assertOk()
            ->assertJsonPath('match_percentage', 50)
            ->assertJsonPath('available_ingredients.0.pantry_quantity', 1)
            ->assertJsonPath('missing_ingredients.0.missing_quantity', 0.5)
            ->assertJsonPath('missing_ingredients.0.substitutes.0', 'tamari');

        $this->actingAs($user, 'sanctum')->postJson("/api/recipes/{$recipe['id']}/shopping-list")
            ->assertCreated()->assertJsonPath('items.0.quantity', '0.5');
    }

    public function test_recipe_matching_marks_recognised_stock_with_incompatible_units_for_review(): void
    {
        $user = User::factory()->create();
        PantryItem::create(['user_id' => $user->id, 'name' => 'Bihon', 'quantity' => '4', 'quantity_value' => 4, 'unit' => 'packs', 'freshness_status' => 'fresh']);
        $recipe = $this->actingAs($user, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Pancit Bihon', 'instructions' => 'Cook.',
            'ingredients' => [['name' => 'Bihon noodles', 'quantity' => '250', 'unit' => 'g']],
        ])->json();

        $this->actingAs($user, 'sanctum')->getJson("/api/recipes/{$recipe['id']}/match")
            ->assertOk()
            ->assertJsonPath('needs_review_ingredients.0.name', 'Bihon noodles')
            ->assertJsonPath('needs_review_ingredients.0.pantry_units.0', 'packs')
            ->assertJsonCount(0, 'missing_ingredients');
    }

    public function test_confirmed_package_weight_is_reused_for_recipe_matching_and_not_shopped(): void
    {
        $user = User::factory()->create();
        $item = PantryItem::create(['user_id' => $user->id, 'name' => 'Bihon noodles', 'quantity' => '4', 'quantity_value' => 4, 'unit' => 'packs', 'freshness_status' => 'fresh']);
        $recipe = $this->actingAs($user, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Bihon meal', 'instructions' => 'Cook.',
            'ingredients' => [['name' => 'Bihon noodles', 'quantity' => '750', 'unit' => 'g']],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/pantry/{$item->id}/package-conversion", ['amount_per_package' => 250, 'amount_unit' => 'g'])
            ->assertOk()->assertJsonPath('conversion.amount_per_package', '250.000');
        $this->actingAs($user, 'sanctum')->getJson("/api/recipes/{$recipe['id']}/match")
            ->assertOk()->assertJsonPath('match_percentage', 100)->assertJsonCount(0, 'needs_review_ingredients')->assertJsonCount(0, 'missing_ingredients');
        $this->actingAs($user, 'sanctum')->postJson("/api/recipes/{$recipe['id']}/shopping-list")
            ->assertCreated()->assertJsonCount(0, 'items');
    }

    public function test_family_grocery_lists_use_shared_pantry_and_are_visible_to_other_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Shared Grocery'])->json();
        $invitation = $this->actingAs($owner, 'sanctum')->postJson("/api/families/{$family['id']}/members", ['email' => $member->email, 'role' => 'member'])->json('invitation');
        $this->actingAs($member, 'sanctum')->postJson("/api/family-invitations/{$invitation['id']}/accept");
        PantryItem::create(['user_id' => $owner->id, 'family_id' => $family['id'], 'name' => 'Rice', 'quantity' => '1', 'quantity_value' => 1, 'unit' => 'kg', 'freshness_status' => 'fresh']);
        $recipe = $this->actingAs($owner, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Rice Meal', 'instructions' => 'Cook.',
            'ingredients' => [['name' => 'Rice', 'quantity' => '2', 'unit' => 'kg']],
        ])->json();

        $this->actingAs($owner, 'sanctum')->postJson("/api/recipes/{$recipe['id']}/shopping-list", ['family_id' => $family['id']])
            ->assertCreated()->assertJsonPath('items.0.quantity', '1');
        $this->actingAs($member, 'sanctum')->getJson('/api/shopping-list')
            ->assertOk()->assertJsonPath('0.ingredient_name', 'Rice');
    }

    public function test_household_recommendations_use_shared_stock_and_exclude_member_allergies(): void
    {
        $owner = User::factory()->create();
        $family = $this->actingAs($owner, 'sanctum')->postJson('/api/families', ['name' => 'Santos Household'])->json();
        PantryItem::create(['user_id' => $owner->id, 'family_id' => $family['id'], 'name' => 'Chicken', 'quantity' => '1', 'quantity_value' => 1, 'unit' => 'kg', 'freshness_status' => 'fresh']);
        HouseholdProfile::create(['family_id' => $family['id'], 'name' => 'Ana', 'allergies' => ['peanut']]);

        $safe = $this->actingAs($owner, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Chicken Tinola', 'instructions' => 'Cook.',
            'ingredients' => [['name' => 'Chicken', 'quantity' => '1', 'unit' => 'kg']],
        ])->json();
        $this->actingAs($owner, 'sanctum')->postJson('/api/recipes', [
            'name' => 'Peanut Chicken', 'instructions' => 'Cook.',
            'ingredients' => [['name' => 'Peanut', 'quantity' => '1', 'unit' => 'cup']],
        ])->assertCreated();

        $this->actingAs($owner, 'sanctum')->getJson("/api/recipes/recommendations?family_id={$family['id']}")
            ->assertOk()->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.recipe.id', $safe['id'])
            ->assertJsonPath('recommendations.0.match_percentage', 100);
    }

    public function test_household_pantry_scope_excludes_personal_and_other_household_stock(): void
    {
        $user = User::factory()->create();
        $first = $this->actingAs($user, 'sanctum')->postJson('/api/families', ['name' => 'First'])->json();
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/families', ['name' => 'Second'])->json();
        PantryItem::create(['user_id' => $user->id, 'family_id' => $first['id'], 'name' => 'Rice']);
        PantryItem::create(['user_id' => $user->id, 'family_id' => $second['id'], 'name' => 'Pasta']);
        PantryItem::create(['user_id' => $user->id, 'name' => 'Salt']);

        $this->actingAs($user, 'sanctum')->getJson("/api/pantry?family_id={$first['id']}")
            ->assertOk()->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Rice'])
            ->assertJsonMissing(['name' => 'Salt'])
            ->assertJsonMissing(['name' => 'Pasta']);
    }

    public function test_usage_reason_is_stored_without_crossing_pantry_ownership(): void
    {
        $user = User::factory()->create();
        $item = PantryItem::create(['user_id' => $user->id, 'name' => 'Rice', 'quantity' => '2', 'quantity_value' => 2, 'unit' => 'kg', 'freshness_status' => 'fresh']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item->id}/freshness", [
            'action' => 'used', 'used_quantity' => 0.5, 'usage_reason' => 'Dinner prep',
        ])->assertOk()->assertJsonPath('item.quantity_value', '1.500')->assertJsonPath('item.last_usage_reason', 'Dinner prep');

        $this->assertDatabaseHas('pantry_items', ['id' => $item->id, 'family_id' => null, 'last_usage_reason' => 'Dinner prep']);
    }

    public function test_an_authenticated_user_can_search_usda_food_data(): void
    {
        $user = User::factory()->create();
        config()->set('services.usda.key', 'test-key');
        config()->set('services.usda.base_url', 'https://api.nal.usda.gov/fdc/v1');
        Http::fake(['https://api.nal.usda.gov/fdc/v1/foods/search*' => Http::response(['foods' => [['fdcId' => 123, 'description' => 'Chicken breast']]], 200)]);

        $this->actingAs($user, 'sanctum')->getJson('/api/nutrition/search?query=chicken')
            ->assertOk()->assertJsonPath('foods.0.fdcId', 123);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'query=chicken') && str_contains($request->url(), 'api_key=test-key'));
    }

    public function test_phone_inputs_create_reviewable_drafts_without_adding_pantry_stock(): void
    {
        $user = User::factory()->create();
        Http::fake(['https://world.openfoodfacts.org/api/v2/product/*' => Http::response([
            'product' => ['product_name' => 'Coconut Milk', 'quantity' => '400 ml'],
        ])]);
        Storage::fake('local');

        $this->actingAs($user, 'sanctum')->postJson('/api/pantry-inputs/barcode', ['barcode' => '4800123456789'])
            ->assertOk()->assertJsonPath('needs_review', true)->assertJsonPath('candidates.0.name', 'Coconut Milk')
            ->assertJsonPath('candidates.0.quantity', '400')->assertJsonPath('candidates.0.unit', 'ml');
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry-inputs/voice', ['transcript' => '2 cans sardines'])
            ->assertOk()->assertJsonPath('candidates.0.name', 'Sardines')->assertJsonPath('candidates.0.quantity', '2');
        $this->actingAs($user, 'sanctum')->post('/api/pantry-inputs/receipt', [
            'receipt' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'), 'recognized_text' => "1 kg rice\nTOTAL 100",
        ])->assertCreated()->assertJsonPath('candidates.0.name', 'Rice')->assertJsonPath('candidates.0.unit', 'kg');

        $this->assertDatabaseCount('pantry_items', 0);
    }

    public function test_chair_is_rejected_server_side_and_cannot_be_saved_to_the_pantry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/ingredients/resolve', ['name' => 'chair'])
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry', ['name' => 'chair', 'quantity' => 1, 'unit' => 'pieces'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseMissing('pantry_items', ['name' => 'chair']);
    }

    public function test_bihon_requires_an_alias_confirmation_before_the_canonical_name_can_be_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/ingredients/resolve', ['name' => 'bihon'])
            ->assertOk()->assertJsonPath('status', 'suggested')->assertJsonPath('suggestion.canonical_name', 'bihon noodles');
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry', ['name' => 'bihon', 'quantity' => 1, 'unit' => 'packs'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry', ['name' => 'bihon noodles', 'quantity' => 1, 'unit' => 'packs'])
            ->assertCreated()->assertJsonPath('item.name', 'bihon noodles');
    }

    public function test_voice_and_receipt_candidates_are_split_and_invalid_terms_are_rejected_not_review_drafts(): void
    {
        $user = User::factory()->create();
        $voice = $this->actingAs($user, 'sanctum')->postJson('/api/pantry-inputs/voice', ['transcript' => '2 eggs and bihon and chair'])
            ->assertOk()->assertJsonCount(1, 'accepted')->assertJsonCount(1, 'suggested')->assertJsonCount(1, 'rejected')
            ->assertJsonPath('rejected.0.name', 'Chair')->assertJsonPath('suggested.0.suggestion.canonical_name', 'bihon noodles')->json();
        $this->assertCount(2, $voice['candidates']);
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry-inputs/receipt-text', ['text' => "Tuna\nChair"])
            ->assertOk()->assertJsonCount(1, 'accepted')->assertJsonCount(1, 'rejected')->assertJsonCount(1, 'candidates');
        $this->assertDatabaseCount('pantry_items', 0);
    }

    public function test_voice_input_parses_multiple_spoken_items_and_canned_goods_get_an_estimated_longer_shelf_life(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/pantry-inputs/voice', [
            'transcript' => 'I have two cans of coconut milk and 2 packs of spicy canton, 2 cans of sardines tuna',
        ])->assertOk()->assertJsonPath('candidates.0.name', 'Coconut Milk')->assertJsonPath('candidates.0.quantity', '2')
            ->assertJsonPath('candidates.0.unit', 'cans')->assertJsonPath('candidates.1.name', 'Spicy Canton')->assertJsonCount(3, 'candidates');

        $item = $this->actingAs($user, 'sanctum')->postJson('/api/pantry', ['name' => 'Canned Tuna', 'quantity' => 5, 'unit' => 'cans'])
            ->assertCreated()->json('item');
        $this->assertSame('fresh', $item['freshness_status']);
        $this->assertTrue($item['is_expiry_estimated']);
    }

    public function test_pantry_usage_deducts_only_the_amount_used_and_a_fully_used_item_can_be_undone(): void
    {
        $user = User::factory()->create();
        $item = PantryItem::create(['user_id' => $user->id, 'name' => 'Eggs', 'quantity' => '5', 'quantity_value' => 5, 'unit' => 'pieces', 'freshness_status' => 'fresh']);
        $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item->id}/freshness", ['action' => 'used', 'used_quantity' => 2])
            ->assertOk()->assertJsonPath('item.quantity_value', '3.000')->assertJsonPath('item.freshness_status', 'fresh');
        $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item->id}/freshness", ['action' => 'used', 'used_quantity' => 3])
            ->assertOk()->assertJsonPath('item.freshness_status', 'used');
        $this->actingAs($user, 'sanctum')->patchJson("/api/pantry/{$item->id}/freshness", ['action' => 'undo_used'])
            ->assertOk()->assertJsonPath('item.quantity_value', '3.000')->assertJsonPath('item.freshness_status', 'fresh');
    }
}
