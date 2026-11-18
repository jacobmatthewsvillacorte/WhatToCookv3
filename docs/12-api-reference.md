# API reference

**Read this on day 3.** Source of truth: `whattocook-backend/routes/api.php`. The frontend wrapper for every endpoint is a method on `ApiService` (`frontend/src/app/services/api.service.ts`); wire types are in `api.models.ts`.

## Conventions

- Base URL `/api`. JSON in and out. Auth is a Sanctum bearer token: `Authorization: Bearer <token>`.
- Public: `POST /register`, `POST /login` (throttled to 5 per minute per email and IP). Everything else needs the token and is throttled to 60 per minute per user.
- `family_id` on a request switches to family scope. The caller must be an accepted member or the API returns **403**. Without it, the request is personal scope.
- Validation errors are **422** with Laravel's `errors` object. An expired token is **401**, which the frontend interceptor turns into a logout.
- `GET /up` is the health check.

## Endpoints by area

### Auth and profile

| Method and path | Controller | Purpose |
| --- | --- | --- |
| `POST /register`, `POST /login` | `AuthController` | Create account / issue token |
| `POST /logout`, `GET /user` | `AuthController` | Revoke token / current user |
| `GET, PUT /profile` | `ProfileController` | Allergies, diet, likes, dislikes, health conditions, visibility |

### Families and household profiles

| Method and path | Purpose |
| --- | --- |
| `GET, POST /families` | List mine / create |
| `POST /families/join` | Join by `join_code` |
| `GET /family-invitations`, `POST /family-invitations/{id}/accept` | Pending invites |
| `GET /families/{family}` | Detail with members |
| `POST /families/{family}/members`, `DELETE /families/{family}/members/{user}` | Invite by email / remove |
| `GET, POST /families/{family}/household-profiles`, `GET, PUT/PATCH, DELETE .../{id}` | Diners, including children without accounts (`HouseholdProfileController`) |

### Pantry

| Method and path | Purpose |
| --- | --- |
| `GET /pantry` (`?family_id=` or `?personal=1`) | Items in scope |
| `POST /pantry`, `PUT /pantry/{id}`, `DELETE /pantry/{id}` | CRUD. Freshness is estimated by `PantryFreshnessService` |
| `PATCH /pantry/{id}/freshness` | `still_fresh`, `spoiled`, `used`, `discarded`, `undo_used` |
| `POST /pantry/{id}/package-conversion` | Confirm `1 pack = N g` |
| `POST /pantry-inputs/barcode`, `/voice`, `/receipt`, `/receipt-text` | Produce reviewable candidates (`PantryInputService`); nothing is saved |
| `GET /ingredients/search`, `POST /ingredients/resolve` | Approved catalogue lookup (`IngredientCatalogController`) |

### Recipes, nutrition, engagement

| Method and path | Purpose |
| --- | --- |
| `GET /recipes` | Search (`q`, `meal_type`, `difficulty`, `max_time`, `region`, `include_match`, `per_page`). Safety filtered |
| `GET /recipes/recommendations` | All safe recipes ranked by pantry match |
| `GET /recipes/{recipe}`, `/match`, `/nutrition` | Detail / pantry match / nutrition totals |
| `POST, PUT, DELETE /recipes...` | Admin-style recipe CRUD via `apiResource` |
| `POST /recipes/{recipe}/image` | Upload image |
| `PUT /recipes/{recipe}/ingredients/{id}/nutrition` | Link ingredient to a USDA food |
| `POST /recipes/{recipe}/shopping-list` | Add missing ingredients to the shopping list |
| `GET /nutrition/search`, `GET /nutrition/foods/{fdcId}`, `POST /nutrition/foods/{fdcId}/cache`, `POST /nutrition/foods` | USDA search and local cache (`NutritionController`) |
| `GET /favorites`, `POST, DELETE /recipes/{recipe}/favorite` | Favourites |
| `GET /recipes/{recipe}/reviews`, `PUT /recipes/{recipe}/review`, `DELETE /reviews/{review}` | Reviews |

### Meal planning (current flow): batches

| Method and path | Purpose |
| --- | --- |
| `POST /meal-plan-batches/generate` | Create a **draft** batch for a date range |
| `GET /meal-plan-batches/{id}` | Drafts, summary, shortages, conflicts |
| `PATCH /meal-plan-batches/{id}/meals/{mealPlan}` | Swap recipe, date, servings or diners on a draft |
| `POST /meal-plan-batches/{id}/shopping-list` | Add shortages to the shopping list |
| `POST /meal-plan-batches/{id}/purchased-items` | Record bought items into the pantry, then re-check |
| `POST /meal-plan-batches/{id}/save` | Drafts become scheduled (`conflict_action`) |
| `DELETE /meal-plan-batches/{id}` | Discard the draft |

### Meal plans (scheduled meals and cooking)

| Method and path | Purpose |
| --- | --- |
| `GET, POST /meal-plans`, `PUT, DELETE /meal-plans/{id}`, `GET /meal-plans/{id}` | CRUD |
| `GET /meal-plans/nutrition` | Nutrition summary over a range |
| `GET /meal-plans/{id}/preflight` | Per ingredient Ready / Low stock / Missing / Needs review |
| `POST /meal-plans/{id}/shopping-list` | Shortages to shopping |
| `POST /meal-plans/{id}/complete` | Cook, deduct pantry, write history |
| `POST /meal-plans/{id}/complete-without-deduction` | Write history only |

### Shopping and history

| Method and path | Purpose |
| --- | --- |
| `GET, POST, PUT, DELETE /shopping-list` | CRUD |
| `DELETE /shopping-list/purchased` | Clear purchased items |
| `POST /shopping-list/{id}/confirm-purchase` | Record the purchase into the pantry (`ConfirmedPurchaseService`) |
| `GET, POST, PUT, DELETE /meal-history` | History |

## Typical call sequence (the happy path on the wire)

```text
POST /login                           -> token
GET  /families                        -> choose scope
POST /pantry-inputs/voice             -> candidates -> POST /pantry (after user confirms)
POST /meal-plan-batches/generate      -> draft + summary
POST /meal-plan-batches/{id}/shopping-list
POST /meal-plan-batches/{id}/save
GET  /meal-plans/{id}/preflight
POST /meal-plans/{id}/complete
```

## Shared controller code

Rules that every controller must agree on live in two traits, not in copies:

| Trait (`app/Http/Controllers/Concerns/`) | Provides | Used by |
| --- | --- | --- |
| `AuthorisesFamilyAccess` | `canUseFamily($request, $familyId)`: 403 unless the caller is an accepted member | Pantry, Recipe, MealPlan, MealPlanBatch, ShoppingList, MealHistory |
| `PlansForHousehold` | `validatedDiners`, `servingEquivalent`, `hasYoungChildConflict`, `pantryFor` (active-scope usable pantry) | Recipe, MealPlan, MealPlanBatch |

When you need a new family-scoped endpoint, `use` the trait and call `canUseFamily` first. Do not write a new membership query.

## Removed

`POST /meal-plans/generate` (the pre-batch generator) was deleted. Its safety tests now run against `POST /meal-plan-batches/generate` in `MealPlanningApiTest`.

## Known debt

- `FamilyController` and `HouseholdProfileController` still run their own membership checks (they authorise on the family model, not a request `family_id`). Fold them into `AuthorisesFamilyAccess` only if their error messages can stay the same.
- `MealPlanController::pantryForPlan` is a plan-scoped variant of `pantryFor`; the two differ on purpose (a plan carries its own `family_id`).
- List endpoints (`shopping-list`, `meal-history`, `pantry`) are not paginated. Fine at household scale.
