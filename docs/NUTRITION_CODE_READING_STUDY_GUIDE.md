# Nutrition code reading study guide

This guide follows nutrition values from the admin recipe form to the Laravel calculation, then separates that recipe calculation from the Daily Serving Plan. Read the files in order and use the questions at the end to check your understanding.

## First, keep the two calculations separate

| Feature | What it answers | Main calculation |
| --- | --- | --- |
| Recipe nutrition | How much nutrition is in one recipe serving? | Sum linked ingredient nutrients by weight, then divide by recipe servings. |
| Daily Serving Plan | What daily target and food-group servings are estimated for one person? | Estimate calories from profile data, then divide group calorie allocations by selected food calories per serving. |

The recipe editor does not ask an admin to enter calorie or macro values. It calculates them after save. The Daily Serving Plan is also computed; it asks for personal details such as age, sex, height, weight, activity, and goal, not a calorie number.

## Read the recipe nutrition path

Start in the backend directory: `whattocook-backend`.

### 1. Follow the admin form

Open [`resources/views/admin/recipes/form.blade.php`](../whattocook-backend/resources/views/admin/recipes/form.blade.php).

- Find the ingredient rows. Each row has the recipe ingredient name, quantity/unit, a USDA search field, an FDC ID, and an ingredient weight in grams.
- Find the “Nutrition per serving” note near the end of the form. It explains that nutrition is calculated on save; the form has no editable calorie or macro inputs.
- The browser search calls the admin nutrition search route. Search results are choices; selecting one fills the FDC ID. The search itself does not calculate the recipe total.

Ask yourself: why is the explicit gram weight separate from the recipe quantity and unit? Because a quantity such as “1 tsp” does not reliably tell the server how many grams of a specific food it represents.

### 2. Follow the route into the controller

Open [`routes/web.php`](../whattocook-backend/routes/web.php) and locate the `admin.nutrition.search` route and the `admin.recipes` resource routes. They are inside the authenticated admin group.

Then read [`app/Http/Controllers/AdminRecipeController.php`](../whattocook-backend/app/Http/Controllers/AdminRecipeController.php):

- `nutritionSearch()` validates the query and asks `UsdaFoodDataService` for normalized USDA search results.
- `store()` and `update()` validate the recipe and ingredients, save the recipe, then call `RecipeNutritionService::updateRecipeMacros()`.
- `nutritionLinkedIngredients()` caches a selected FDC record through `UsdaFoodDataService::cacheFood()` and stores its local `nutrition_food_id` link on the ingredient.

Follow the calls in that order. The browser sends the FDC ID and gram amount; it does not submit authoritative computed nutrition totals.

### 3. Read where USDA values come from

Open [`app/Services/UsdaFoodDataService.php`](../whattocook-backend/app/Services/UsdaFoodDataService.php).

- `cacheFood()` fetches the chosen USDA food, normalizes its nutrients, and stores the record in `nutrition_foods`.
- `normalizeNutrients()` stores values per 100 g and separately records `available_nutrients`. A displayed zero can be a real zero, so code must check availability before treating the value as known.
- USDA calls need `USDA_API_KEY` in the backend `.env`. If it is missing, USDA search is unavailable; the browser should show a clear service error.

Trace the schema through [`app/Models/NutritionFood.php`](../whattocook-backend/app/Models/NutritionFood.php) and the migration that creates `nutrition_foods` and links it to recipe ingredients.

### 4. Trace the actual recipe math

Open [`app/Services/RecipeNutritionService.php`](../whattocook-backend/app/Services/RecipeNutritionService.php).

1. `calculate()` loads each ingredient’s linked nutrition food.
2. `gramsFor()` uses the explicit `nutrition_grams` value when present. Otherwise, it converts supported mass units such as g, kg, mg, oz, and lb. It does not guess a gram value for tsp, cup, piece, or other unsupported units.
3. Each nutrient total is scaled from the USDA per-100-g value:

   `ingredient nutrient = nutrient per 100 g × ingredient grams ÷ 100`

4. Ingredient values are added to recipe totals.
5. The recipe serving count is clamped to at least one, then every total is divided by it:

   `per-serving nutrient = recipe total ÷ recipe servings`

   For example, if all linked ingredients total 1,280 kcal and the recipe serves 4, the result is 320 kcal per serving.

6. Missing food links, unknown gram conversions, or missing USDA nutrient coverage are reported through `unmatched_ingredients`, `unknown_nutrients`, `is_nutrition_complete`, and `data_status`. Read these fields alongside the numbers; an incomplete total is only a partial estimate.
7. `updateRecipeMacros()` stores per-serving calories, protein, carbs, and fat on the recipe record.

The admin form currently explains this calculation but does not render the newly computed result after save. The persisted values can be inspected on the recipe record or through the recipe nutrition endpoint; a future UI change can display them in the editor.

### 5. Follow the API read path

For the JSON calculation, inspect the nutrition methods in [`app/Http/Controllers/RecipeController.php`](../whattocook-backend/app/Http/Controllers/RecipeController.php) and the corresponding routes in `routes/api.php`:

- The recipe nutrition endpoint calls `RecipeNutritionService::calculate()` and returns totals, per-serving values, and completeness metadata.
- The ingredient-link endpoint can cache a selected FDC food and record its gram amount, then recalculates the recipe.

This is a separate read/update path from clicking Save in the admin recipe editor.

## Read the Daily Serving Plan path

Open [`app/Services/DailyServingPlanner.php`](../whattocook-backend/app/Services/DailyServingPlanner.php), then [`app/Http/Controllers/Admin/DailyServingPlanController.php`](../whattocook-backend/app/Http/Controllers/Admin/DailyServingPlanController.php), and the admin view at `resources/views/admin/nutrition/daily-plan.blade.php`.

- The planner gets age, sex, weight, height, activity, goal, and health conditions from a household profile or ad-hoc form values.
- It calculates BMR using Mifflin–St Jeor, applies an activity multiplier, adjusts for the goal, and applies a 1,200 kcal floor.
- It splits the calorie target into macro targets and assigns calorie shares to rice/staple, ulam/protein, fruit, and vegetable groups. A diabetic condition uses a lower carbohydrate macro ratio and a lower rice/staple calorie share.
- For each group, USDA food data is used to calculate calories in the chosen serving size. The group’s target calories divided by calories per serving gives estimated servings per day.
- Missing food records or missing required nutrient coverage produce an explicit incomplete group with no fabricated serving count.

This path estimates a daily target for one person. It does not divide recipe nutrition by a recipe’s servings count.

## Use tests as executable examples

Read these tests after the implementation files:

- [`tests/Feature/NutritionApiTest.php`](../whattocook-backend/tests/Feature/NutritionApiTest.php): recipe total/per-serving scaling, incomplete links and units, nutrient coverage, and USDA caching.
- [`tests/Feature/AdminRecipeManagementTest.php`](../whattocook-backend/tests/Feature/AdminRecipeManagementTest.php): admin recipe form and save behavior.
- [`tests/Feature/DailyServingPlanTest.php`](../whattocook-backend/tests/Feature/DailyServingPlanTest.php): daily target, diabetic adjustment, incomplete food data, and admin access.

To trace a value while reading, pick a test assertion such as `per_serving.calories`, search for that JSON key, follow the called service method, and then follow its input back to the model/migration. This is usually faster and more reliable than starting from a broad repository search.

## Check your understanding

1. A recipe has 800 kcal total and serves 4. What is stored as its calories per serving?
2. A USDA item has 200 kcal per 100 g and the recipe uses 50 g. How many calories does that ingredient add?
3. Why does a missing `available_nutrients` entry matter even when the nutrient array contains a numeric zero?
4. What happens when an ingredient has quantity `1 tsp` but no explicit gram weight?
5. Which inputs change a person’s daily calorie target, and which data source supplies calories for selected foods?

Answers: 200 kcal; 100 kcal; zero may mean “not supplied,” not a verified zero; the conversion is unknown and the result is marked incomplete; profile/ad-hoc inputs drive the daily estimate while cached USDA records supply food nutrient values.
