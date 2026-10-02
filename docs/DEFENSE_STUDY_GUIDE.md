# WhatToCook Defense Study Guide

This guide describes behavior currently present in the repository. Use the file and symbol names as the primary defense anchors, then demonstrate the workflows manually instead of memorizing claims that the code does not support.

## 1. System architecture

**What the code does:** The system is an Ionic 8 / Angular 20 Capacitor client backed by a Laravel 13 JSON API. Sanctum bearer tokens protect the API. SQLite is used for local testing; the deployment guide documents production configuration. Recipe discovery, pantry matching, meal planning, shopping lists, cooking, and nutrition are server-backed.

**Important files and symbols:** `frontend/src/app/services/api.service.ts` (`ApiService`), `frontend/src/app/app-routing.module.ts`, `whattocook-backend/routes/api.php`, `whattocook-backend/app/Models`, `whattocook-backend/app/Services`, `docs/ERD.md`, `docs/SYSTEM_LOGIC_MAP.md`.

**Likely panel questions:** Why separate frontend and backend? Which side is authoritative for safety rules? What happens when the API is unavailable?

**Memorize:** "Angular provides the mobile and browser UI, while Laravel owns authentication, validation, data boundaries, matching, planning, and pantry mutations. The client requests and presents decisions; it is not the security boundary."

**Manual task:** Trace one click from the recipe page through `ApiService` to its Laravel route and controller.

**Skill-test exercises:** Add a protected read-only endpoint, trace a failing HTTP request, or explain a request using browser/network logs.

## 2. Angular/Ionic frontend structure

**What the code does:** Feature pages own view state and user interaction. `ApiService` centralizes HTTP calls and the Android base URL. Guards and interceptors/services handle session behavior. Ionic components provide the responsive UI and Capacitor exposes native features.

**Important files and symbols:** `frontend/src/app/recipes/recipes.page.ts`, `recipe-detail/recipe-detail.page.ts`, `meal-plan/meal-plan.page.ts`, `pantry/pantry.page.ts`, `services/api.service.ts`, `guards/auth.guard.ts`, `services/auth.service.ts`.

**Likely panel questions:** Where should API calls live? How does Angular know whether the user is logged in? How does the same code run on Android?

**Memorize:** "Pages coordinate UI state, `ApiService` defines typed HTTP operations, and services such as `AuthService` and `HouseholdContextService` keep cross-page state out of templates. Capacitor selects the Android API base URL when running natively."

**Manual task:** Open a recipe, identify its page method, service method, endpoint, and displayed response field.

**Skill-test exercises:** Add a loading/error/empty state, add a typed API method, or write a Jasmine test for a page action.

## 3. Laravel backend structure

**What the code does:** Routes map requests to controllers. Models represent database records and relationships. Services contain domain calculations such as matching, nutrition, food safety, freshness, and meal-plan generation. Form requests/controllers validate input before mutations.

**Important files and symbols:** `whattocook-backend/routes/api.php`, `app/Http/Controllers/RecipeController.php`, `MealPlanController.php`, `PantryController.php`, `app/Services/RecipeMatcher.php`, `FoodSafetyTaxonomy.php`, `MealPlanGenerationService.php`, `database/migrations`.

**Likely panel questions:** Why not put all business logic in controllers? Where are database relationships defined? How are transactions used?

**Memorize:** "Controllers are transport adapters. Shared domain rules live in services, models express relationships, and migrations define durable schema. Mutations that must remain consistent use validation and, where appropriate, database transactions."

**Manual task:** Use `php artisan route:list` and locate the recipe, meal-plan, pantry, and shopping-list endpoints.

**Skill-test exercises:** Add a model relationship, write a migration, or extract repeated controller logic into a service.

## 4. Authentication and route guards

**What the code does:** Registration/login return a Sanctum bearer token. `ApiService` sends it in the `Authorization` header. Angular route guards prevent protected screens from opening without a token. Laravel `auth:sanctum` protects API routes, while ownership checks protect user-created recipes and personal resources.

**Important files and symbols:** `frontend/src/app/services/auth.service.ts`, `frontend/src/app/guards/auth.guard.ts`, `whattocook-backend/app/Http/Controllers/AuthController.php`, `routes/api.php`, `RecipeController::update`, `RecipeController::uploadImage`.

**Likely panel questions:** Is the guard enough for security? How is a user's recipe protected from another user?

**Memorize:** "The Angular guard improves navigation, but Laravel is the real boundary. Every protected request carries a Sanctum token, and the server checks authenticated identity and ownership before modifying private records."

**Manual task:** Test a protected request as guest, owner, and different authenticated user.

**Skill-test exercises:** Add an ownership test, handle a 401 by logging out, or explain why client-only hiding is insufficient.

## 5. API communication

**What the code does:** `ApiService` creates typed GET/POST/PUT/PATCH/DELETE calls and attaches the bearer token. Multipart methods use `FormData` and intentionally do not set a manual content type so the browser supplies the boundary.

**Important files and symbols:** `frontend/src/app/services/api.service.ts`, `ApiService.baseUrl`, `options()`, `uploadRecipeImage`, backend `routes/api.php`, `config/cors.php`.

**Likely panel questions:** Why use `FormData`? How are validation errors surfaced? How does Android reach the backend?

**Memorize:** "The service is the frontend API contract. JSON is used for normal resources; `FormData` is used for binary uploads. Laravel returns validation errors and the page decides how to present them without trusting client validation."

**Manual task:** Inspect one request's method, URL, headers, payload, and response in browser dev tools.

**Skill-test exercises:** Add a typed endpoint, convert a JSON upload to multipart, or display a server validation message.

## 6. Personal versus family data scope

**What the code does:** Personal pantry queries filter by the signed-in user and `family_id = null`. Family pantry queries filter by an accepted family membership and the selected family ID. Recommendation safety rules use either the user's `Profile` or family `HouseholdProfile` records.

**Important files and symbols:** `RecipeController::pantryFor`, `pantryForSearch`, `canUseFamily`, `profileBlockedIngredients`, `familyBlockedIngredients`, `HouseholdContextService`, `PantryController`.

**Likely panel questions:** Can one family member see another person's personal pantry? What prevents an arbitrary family ID?

**Memorize:** "Personal and shared stock are separate query scopes. A family ID is not trusted just because it is supplied by the client; Laravel verifies accepted membership before using it."

**Manual task:** Create two users and a family, then prove personal discovery and family discovery use different pantry records.

**Skill-test exercises:** Add a cross-user isolation test, enforce membership on a new endpoint, or diagnose a scope leak.

## 7. Pantry and recipe matching

**What the code does:** `RecipeMatcher` compares normalized recipe ingredients with fresh/review pantry items, handles units and package conversions, and returns available, missing, and needs-review ingredients plus a match percentage. Food safety filtering runs before discovery results are returned.

**Important files and symbols:** `app/Services/RecipeMatcher.php`, `RecipeController::index`, `recommendations`, `PantryFreshnessService`, `IngredientCatalogService`, recipe/pantry models.

**Likely panel questions:** Why is a recipe not always a binary match? How are units and packages handled? Where are allergies enforced?

**Memorize:** "Matching is a domain calculation, not a string check. It distinguishes ready, low-stock, missing, and needs-review items, while server-side safety filtering prevents unsafe recipes from being recommended."

**Manual task:** Add pantry items with a partial quantity and inspect the resulting match and missing list.

**Skill-test exercises:** Add a unit case, test package conversion, or prevent a false match such as `egg` matching `eggplant`.

## 8. Meal-plan generation

**What the code does:** The batch flow accepts a date range, meal types, diners, attendance, time and leftover preferences, and child modes. The backend generates a reviewable draft, reports conflicts and a summary, and only saves after confirmation.

**Important files and symbols:** `MealPlanBatchController`, `MealPlanGenerationService`, `MealPlanBatch`, `MealPlan`, frontend `meal-plan.page.ts`, `ApiService.generateMealPlanBatch` and `saveMealPlanBatch`.

**Likely panel questions:** Why generate a draft first? How are family conflicts detected? Is the result random?

**Memorize:** "Generation is reviewable: the server produces candidate meals and a summary, the user can edit or resolve conflicts, and saving is a separate operation. This makes planning safer than silently committing a generated schedule."

**Manual task:** Generate a short family plan, change one meal, resolve a conflict, and save it.

**Skill-test exercises:** Add a generation constraint, test a conflict, or preserve existing plans when `replace_existing` is false.

## 9. Ingredient preflight

**What the code does:** Before cooking, the meal-plan preflight calculates pantry scope, match percentage, diner context, ingredient statuses, and whether pantry deduction is currently allowed.

**Important files and symbols:** `MealPlanController::preflight`, `MealPlanPreflight` response shape in `api.service.ts`, `meal-details.page.ts`, `cooking.page.ts`.

**Likely panel questions:** Why check twice, during discovery and before cooking? What happens when stock changed after planning?

**Memorize:** "Discovery is advisory, but preflight is the final readiness check. It recalculates against current stock immediately before a pantry mutation."

**Manual task:** Change pantry quantity after planning, recheck preflight, and observe the changed status.

**Skill-test exercises:** Add a needs-review case, block deduction when stock is insufficient, or test personal/family preflight separation.

## 10. Shopping-list behavior

**What the code does:** Missing recipe or meal-plan ingredients can be added to the appropriate shopping list. Confirmed purchases create pantry items and update the shopping item. Purchased entries can be removed separately.

**Important files and symbols:** `ShoppingListController`, `MealPlanController::addShortagesToShoppingList`, `ShoppingListController::confirmPurchase`, `shopping-list.page.ts`, `ApiService` shopping methods.

**Likely panel questions:** Does adding to the list change the pantry? What happens after purchase? Are family lists shared?

**Memorize:** "A shortage is a planning signal, not pantry stock. The pantry changes when a purchase is confirmed or an item is manually added, and the relevant personal or family scope is retained."

**Manual task:** Add shortages, confirm one purchase, and verify both shopping and pantry states.

**Skill-test exercises:** Prevent duplicate shortages, test purchase conversion, or handle a failed purchase without losing the list item.

## 11. Cooking and pantry deduction

**What the code does:** Cooking mode loads preflight, presents step-by-step instructions, tracks local progress and a timer, then either completes with a server-side pantry deduction or explicitly completes without deduction. The backend records completion/history.

**Important files and symbols:** `cooking.page.ts`, `CookingProgressService`, `MealPlanController::complete`, `completeWithoutDeduction`, `PantryFreshnessService`, `MealHistoryController`.

**Likely panel questions:** Why offer completion without deduction? How do you avoid deducting twice?

**Memorize:** "Deduction is explicit and server-controlled. If ingredients were supplied outside the tracked pantry, the user can record the meal without changing stock; the meal still enters history."

**Manual task:** Cook a planned meal once with deduction and once through the outside-supply path using separate plans.

**Skill-test exercises:** Test idempotency, reject deduction when preflight is not ready, or verify history after completion.

## 12. Testing strategy

**What the code does:** Backend feature tests use RefreshDatabase and in-memory SQLite conventions. Frontend tests use Jasmine/Karma and test page behavior with mocked services. Tests cover authorization, validation, safety, matching, planning, and UI state.

**Important files and symbols:** `whattocook-backend/tests/Feature`, `frontend/src/app/**/*.spec.ts`, `phpunit.xml`, `frontend/karma.conf.js`, `RecipeImageUploadTest`.

**Likely panel questions:** What belongs in unit versus feature tests? What security cases are mandatory?

**Memorize:** "Feature tests verify HTTP contracts and database effects; frontend tests verify page decisions and service interactions. High-risk paths need both happy-path and denial tests, especially ownership, scope, validation, and pantry mutation."

**Manual task:** Run one focused backend test, one focused frontend test, then the complete suites.

**Skill-test exercises:** Add a regression test for a reported bug or make a flaky asynchronous UI test deterministic.

## 13. Android Capacitor networking

**What the code does:** `ApiService.baseUrl` selects `environment.androidApiBaseUrl` on Android and the normal API URL elsewhere. Capacitor synchronizes the Angular build into Android. A local Android device cannot use `localhost` to reach the developer machine; the configured LAN address or deployed API must be reachable.

**Important files and symbols:** `frontend/src/environments/environment.ts`, `environment.prod.ts`, `frontend/capacitor.config.ts`, `ApiService.baseUrl`, `scripts/configure-production-api.mjs`, Android network config.

**Likely panel questions:** Why does browser localhost work while Android fails? What address should a physical phone use?

**Memorize:** "On a phone, localhost means the phone. Development requires a reachable host-machine LAN address and firewall access, while release uses the deployed API URL. Capacitor receives the selected URL through the Angular environment configuration."

**Manual task:** Run the API on the LAN interface, install the debug app, and test login and one recipe request.

**Skill-test exercises:** Diagnose a connection refused error, switch environments, or verify the generated Android assets after `cap sync`.

## 14. Release HTTPS and cleartext security

**What the code does:** `.env.example` documents production `APP_URL`, `APP_FORCE_HTTPS`, and HTTPS-only CORS origins. Release traffic should use HTTPS. Cleartext HTTP may be useful only for controlled local development and should not be enabled broadly in production.

**Important files and symbols:** `whattocook-backend/.env.example`, `config/cors.php`, `AppServiceProvider`, `docs/DEPLOYMENT_GUIDE.md`, Capacitor/Android configuration.

**Likely panel questions:** Why is HTTPS required? What does CORS protect? Is CORS an authentication mechanism?

**Memorize:** "HTTPS protects tokens and uploaded data in transit. CORS limits browser origins but does not replace authentication or authorization. Production uses an HTTPS API, HTTPS allowlisted origins, disabled debug mode, and no broad cleartext exception."

**Manual task:** Inspect the production build configuration and verify no release API URL is HTTP.

**Skill-test exercises:** Reject an insecure origin, fix a CORS mismatch, or explain an Android cleartext failure without weakening release security.

## 15. Error handling and future improvements

**What the code does:** Pages expose loading, empty, validation, and connection states. Backend validation returns structured errors; controllers use authorization failures and service-level errors. Current roadmap items include offline support, mobile end-to-end tests, broader nutrition coverage, substitutions, budget/waste insights, and licensed image improvements.

**Important files and symbols:** page `message`/loading states, `ApiService`, `docs/PRODUCT_ROADMAP.md`, `docs/TESTING_GUIDE.md`, `docs/DEPLOYMENT_GUIDE.md`.

**Likely panel questions:** What is the next technical improvement? What would you monitor in production?

**Memorize:** "The current system fails visibly and preserves user data where possible. The next improvements are offline resilience, mobile end-to-end coverage, stronger image/licence management, and operational monitoring for API errors and storage failures."

**Manual task:** Disable the backend during recipe discovery and verify the user sees a recoverable error state.

**Skill-test exercises:** Add retry/backoff, centralize HTTP error handling, or create an end-to-end test for a critical journey.

## One-day emergency schedule

- **08:00-09:00:** Draw the architecture from Angular page to `ApiService`, route, controller, service, model, and database.
- **09:00-10:00:** Demonstrate registration, login, token use, route guard, logout, and owner denial.
- **10:00-11:30:** Run pantry input, add personal and family stock, then compare recipe matching.
- **11:30-12:30:** Practice allergy/dietary safety and explain why it is server-side.
- **13:30-15:00:** Generate, edit, preflight, and save a meal-plan batch; inspect shortages.
- **15:00-16:00:** Confirm a purchase, cook with deduction, then explain the no-deduction path.
- **16:00-17:00:** Run focused and complete tests; rehearse one failing-test diagnosis.
- **17:00-18:00:** Test Android networking, inspect HTTPS/CORS settings, and rehearse the upload architecture.
- **18:00-19:00:** Deliver the mock defense aloud using the scripts above; write down any answer that depends on an unverified assumption.

## Mock defense

**Q: What is your system's central value?**  
**A:** "It turns tracked household inventory into safer, more practical meal decisions: match recipes to available stock, identify shortages, plan meals, and record cooking with optional pantry deduction."

**Q: Where is authorization enforced?**  
**A:** "At two levels: Angular guards improve navigation, while Laravel Sanctum middleware and controller/service ownership and family-membership checks enforce access on the server."

**Q: Why can the same recipe produce different recommendations?**  
**A:** "Recommendations use the active pantry scope and dietary safety context. Personal mode and family mode intentionally query different stock and profiles."

**Q: Why is preflight needed if matching already happened?**  
**A:** "Pantry state can change between discovery and cooking. Preflight is the final server-side check immediately before a stock mutation."

**Q: How do you handle a missing ingredient?**  
**A:** "The matcher reports it, the user can add the shortage to a shopping list, and a confirmed purchase can create pantry stock. The app never pretends a shopping-list entry is already available."

**Q: How is pantry deduction made safe?**  
**A:** "The user confirms cooking, the backend verifies readiness and scope, and the completion endpoint performs the mutation. There is also an explicit outside-supply completion path that does not alter stock."

**Q: How are uploaded images stored?**  
**A:** "The authorized owner sends a multipart image to Laravel. Laravel validates it, stores it under `storage/app/public/recipes` with a generated name, stores only the key in `recipes.image`, and returns `image_url`. User uploads are runtime data, not source code."

**Q: Why keep external image URLs?**  
**A:** "Existing seeded recipes use attributed external images. Keeping them preserves compatibility and attribution while new uploads use controlled local storage."

**Q: What is a known limitation?**  
**A:** "The mobile app currently browses and cooks recipes but does not expose a user recipe-create form. The API upload method is ready for an authorized user-owned recipe, while the existing admin form provides the file chooser today."

**Q: What would you improve next?**  
**A:** "I would add mobile end-to-end coverage, offline synchronization, storage cleanup jobs and image metadata/rights workflows, then add observability around API, storage, and deduction failures."

## Weaknesses to inspect before defending

- Confirm the production Android API URL and verify it is HTTPS and reachable from the target device.
- Run `php artisan storage:link` in the deployment environment and verify `APP_URL` produces usable public image URLs.
- Check that the production web server serves `public/storage` and limits upload request size consistently with Laravel's 5 MB rule.
- Review external image licences and attribution for every seeded image; external loading remains dependent on third-party availability.
- Be precise that there is no current mobile recipe-authoring page; do not claim the API upload method is already wired to a mobile form.
- Run the complete test suites and inspect any pre-existing failures separately from the image work.
- Consider cleanup and retention policy for replaced/deleted uploaded images if the catalog grows.
- Consider adding image dimensions, EXIF stripping, content scanning, and a cloud object store for production scale.
- Confirm that family and personal test data cannot cross scopes before demonstrating the app.

## Complete user journey

1. A user registers or logs in; Laravel returns a Sanctum token and Angular stores it for authenticated API calls.
2. The authenticated user opens Pantry and adds items manually or through barcode, voice, or receipt review. Each item belongs to the personal pantry unless a validated family scope is selected.
3. The user opens recipe discovery. Laravel verifies the selected family membership, loads only the active pantry scope, applies server-side safety rules, and uses `RecipeMatcher` to classify ingredients.
4. The user opens a recipe detail page, adjusts servings, reviews ingredients and nutrition, and can add shortages to the correct shopping list.
5. The user creates or opens a meal plan. Batch generation produces a reviewable draft; saving persists the selected meals and conflicts are surfaced rather than hidden.
6. Before cooking, meal details call preflight to recalculate ingredient readiness against current pantry stock. The user can shop, update pantry, or explicitly choose outside-supply cooking.
7. Guided cooking shows the recipe image with a fallback, step-by-step instructions, timer, and ingredient checklist.
8. On completion, the user either confirms pantry deduction or marks the meal cooked without changing pantry stock. Laravel records meal history and the UI returns to the dashboard.
9. For images, an authorized owner can upload a validated local image through the multipart endpoint. Existing external images continue to render with attribution; missing or failed images use the bundled fallback.
