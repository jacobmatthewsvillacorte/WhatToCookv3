<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\HouseholdProfileController;
use App\Http\Controllers\IngredientCatalogController;
use App\Http\Controllers\MealHistoryController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealPlanBatchController;
use App\Http\Controllers\NutritionController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\PantryInputController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeEngagementController;
use App\Http\Controllers\ShoppingListController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['throttle:api', 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::get('/ingredients/search', [IngredientCatalogController::class, 'search']);
    Route::post('/ingredients/resolve', [IngredientCatalogController::class, 'resolve']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/families', [FamilyController::class, 'index']);
    Route::post('/families', [FamilyController::class, 'store']);
    Route::post('/families/join', [FamilyController::class, 'join']);
    Route::get('/family-invitations', [FamilyController::class, 'invitations']);
    Route::post('/family-invitations/{familyMember}/accept', [FamilyController::class, 'acceptInvitation']);
    Route::get('/families/{family}', [FamilyController::class, 'show']);
    Route::post('/families/{family}/members', [FamilyController::class, 'addMember']);
    Route::delete('/families/{family}/members/{user}', [FamilyController::class, 'removeMember']);
    Route::get('/families/{family}/household-profiles', [HouseholdProfileController::class, 'index']);
    Route::post('/families/{family}/household-profiles', [HouseholdProfileController::class, 'store']);
    Route::get('/families/{family}/household-profiles/{householdProfile}', [HouseholdProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/families/{family}/household-profiles/{householdProfile}', [HouseholdProfileController::class, 'update']);
    Route::delete('/families/{family}/household-profiles/{householdProfile}', [HouseholdProfileController::class, 'destroy']);

    // Pantry routes
    Route::get('/pantry', [PantryController::class, 'index']);
    Route::post('/pantry', [PantryController::class, 'store']);
    Route::put('/pantry/{id}', [PantryController::class, 'update']);
    Route::post('/pantry/{id}/package-conversion', [PantryController::class, 'confirmPackageConversion']);
    Route::patch('/pantry/{id}/freshness', [PantryController::class, 'updateFreshness']);
    Route::delete('/pantry/{id}', [PantryController::class, 'destroy']);
    Route::post('/pantry-inputs/barcode', [PantryInputController::class, 'barcode']);
    Route::post('/pantry-inputs/voice', [PantryInputController::class, 'voice']);
    Route::post('/pantry-inputs/receipt', [PantryInputController::class, 'receipt']);
    Route::post('/pantry-inputs/receipt-text', [PantryInputController::class, 'receiptText']);

    Route::get('/recipes/recommendations', [RecipeController::class, 'recommendations']);
    Route::get('/recipes/{recipe}/match', [RecipeController::class, 'match']);
    Route::get('/recipes/{recipe}/nutrition', [RecipeController::class, 'nutrition']);
    Route::put('/recipes/{recipe}/ingredients/{ingredientId}/nutrition', [RecipeController::class, 'linkIngredientNutrition'])->whereNumber('ingredientId');
    Route::post('/recipes/{recipe}/shopping-list', [ShoppingListController::class, 'generate']);
    Route::apiResource('recipes', RecipeController::class);
    Route::get('/nutrition/search', [NutritionController::class, 'search']);
    Route::post('/nutrition/foods', [NutritionController::class, 'storeLocal']);
    Route::get('/nutrition/foods/{fdcId}', [NutritionController::class, 'show'])->whereNumber('fdcId');
    Route::post('/nutrition/foods/{fdcId}/cache', [NutritionController::class, 'cache'])->whereNumber('fdcId');
    Route::get('/favorites', [RecipeEngagementController::class, 'favorites']);
    Route::post('/recipes/{recipe}/favorite', [RecipeEngagementController::class, 'favorite']);
    Route::delete('/recipes/{recipe}/favorite', [RecipeEngagementController::class, 'unfavorite']);
    Route::get('/recipes/{recipe}/reviews', [RecipeEngagementController::class, 'reviews']);
    Route::put('/recipes/{recipe}/review', [RecipeEngagementController::class, 'review']);
    Route::delete('/reviews/{review}', [RecipeEngagementController::class, 'deleteReview']);
    Route::post('/meal-plan-batches/generate', [MealPlanBatchController::class, 'generate']);
    Route::get('/meal-plan-batches/{mealPlanBatch}', [MealPlanBatchController::class, 'show']);
    Route::patch('/meal-plan-batches/{mealPlanBatch}/meals/{mealPlan}', [MealPlanBatchController::class, 'updateMeal']);
    Route::post('/meal-plan-batches/{mealPlanBatch}/shopping-list', [MealPlanBatchController::class, 'addShortagesToShoppingList']);
    Route::post('/meal-plan-batches/{mealPlanBatch}/purchased-items', [MealPlanBatchController::class, 'addPurchasedItems']);
    Route::post('/meal-plan-batches/{mealPlanBatch}/save', [MealPlanBatchController::class, 'save']);
    Route::delete('/meal-plan-batches/{mealPlanBatch}', [MealPlanBatchController::class, 'discard']);
    // Kept for API compatibility; the app now uses a reviewable draft batch.
    Route::post('/meal-plans/generate', [MealPlanController::class, 'generate']);
    Route::get('/meal-plans/nutrition', [MealPlanController::class, 'nutritionSummary']);
    Route::apiResource('meal-plans', MealPlanController::class)->except(['show']);
    Route::get('/meal-plans/{mealPlan}/preflight', [MealPlanController::class, 'preflight']);
    Route::post('/meal-plans/{mealPlan}/shopping-list', [MealPlanController::class, 'addShortagesToShoppingList']);
    Route::post('/meal-plans/{mealPlan}/complete', [MealPlanController::class, 'complete']);
    Route::post('/meal-plans/{mealPlan}/complete-without-deduction', [MealPlanController::class, 'completeWithoutDeduction']);
    Route::get('/meal-plans/{mealPlan}', [MealPlanController::class, 'show']);
    Route::apiResource('meal-history', MealHistoryController::class)->except(['show']);
    Route::delete('/shopping-list/purchased', [ShoppingListController::class, 'destroyPurchased']);
    Route::apiResource('shopping-list', ShoppingListController::class)->except(['show']);
    Route::post('/shopping-list/{shoppingList}/confirm-purchase', [ShoppingListController::class, 'confirmPurchase']);
});
