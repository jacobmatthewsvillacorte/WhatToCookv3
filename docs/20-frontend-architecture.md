# Frontend architecture

**Read this on day 5.** Stack: Ionic 8, Angular 20, Capacitor (Android). One lazy-loaded NgModule per page.

## Read in this order

1. `src/main.ts`, `src/app/app.module.ts` - bootstrap, HTTP interceptor.
2. `src/app/app-routing.module.ts`, `tabs/tabs-routing.module.ts` - every screen and its guard.
3. `services/api.models.ts` then `services/api.service.ts` - the whole backend contract.
4. `services/auth.service.ts`, `services/household-context.service.ts` - who am I, which household am I acting for.
5. Pages in user-journey order (below).

## Screen map

```text
/auth                      login / register
/onboarding                profile: allergies, diet, likes
/tabs/dashboard            overview and shortcuts
/tabs/pantry               stock, add by manual/barcode/voice/receipt, freshness actions
/tabs/recipes              search and recommendations
/tabs/meal-plan            calendar of scheduled meals
/tabs/more                 links to the pages below
/recipes/:id               recipe detail, nutrition, reviews, favourite
/plan-setup                choose dates, meals, diners, time budget
/plan-review/:id           review a DRAFT batch: shortages, swaps, save
/meal-details/:id          a scheduled meal and its preflight
/cooking/:id               step-by-step cooking, timer, finish
/shopping-list  /meal-history  /favorites
/family  /profile  /settings
```

All routes except `/auth` use `AuthGuard` (`guards/auth.guard.ts`), which redirects to `/auth` when there is no token.

## Core flow in code

```text
plan-setup.page.ts   -> api.generateMealPlanBatch()  -> navigate /plan-review/:batchId
plan-review.page.ts  -> api.mealPlanBatch(), updateMealPlanBatchMeal(), addMealPlanBatchShortagesToShoppingList(),
                        addMealPlanBatchPurchasedItems(), saveMealPlanBatch()  -> /tabs/meal-plan
meal-details.page.ts -> api.mealPlanPreflight()      -> /cooking/:id
cooking.page.ts      -> api.cookMealPlan() | completeMealPlanWithoutDeduction()  -> /meal-details/:id
```

## Shared UI

`src/app/shared/state/state.component.ts` is a standalone component, `<app-state>`, for loading, error and empty blocks. Import `StateComponent` in a page module's `imports`. Everything else shared is CSS (`wtc-*` classes in `global.scss`), not components.

## Services

| Service | Responsibility |
| --- | --- |
| `ApiService` | The only place that builds URLs and attaches `Authorization`. One method per endpoint (see [12-api-reference.md](12-api-reference.md)) |
| `api.models.ts` | Wire types. Re-exported from `api.service.ts` so existing imports work |
| `AuthService` | Login, register, logout, token, `isAuthenticated`, handles 401 |
| `auth-error.interceptor.ts` | On 401 with a bearer token, calls `AuthService.handleUnauthorized()` |
| `HouseholdContextService` | Active scope (personal or a family), per signed-in user. Pages subscribe to `state$` |
| `PantryChangeService` | Tells the pantry page to refresh after purchases are recorded elsewhere |
| `PantryInputCacheService` | Caches barcode lookups |
| `CookingProgressService` | Stores the current cooking step per user and meal on the device |
| `FreshnessReminderService` | Local reminders for items to review |
| `ExportService` | Download or print shopping lists and plans |
| `ThemeService` | Dark mode toggle (`ion-palette-dark` on body) |

**Rule:** pages never call `HttpClient` directly. Add an endpoint to `ApiService`, and its types to `api.models.ts`.

## Scope handling

Every page that reads pantry, plans or shopping asks `HouseholdContextService` for the active family, and passes `family_id` to the API only in family mode. Switching scope must reload data. If a page shows another household's data after a switch, it is not subscribed to `state$`.

## Environments and API URL

`src/environments/environment.ts` (browser and Android debug) and `environment.prod.ts`. `ApiService.baseUrl` picks `androidApiBaseUrl` when running on Android. Release builds get their URL from `scripts/configure-production-api.mjs`.

## Tests

`*.spec.ts` beside the file. `npm run test:ci` runs 65 specs in headless Chrome. Page specs import the page's module dependencies, not the real backend (`HttpClientTestingModule`).

## Adding a screen (checklist)

1. `ionic generate page my-page` (module, routing, page).
2. Register the route in `app-routing.module.ts` with `AuthGuard`.
3. Use `wtc-*` classes for layout and states ([21-ui-design-system.md](21-ui-design-system.md)).
4. Add API methods to `ApiService`; never call `HttpClient` in the page.
5. Add a spec, then a line in this document.

## Cleanup done in this pass

- Removed the unused `explore-container` starter component and its imports from dashboard, pantry and recipes.
- Moved all interfaces out of `api.service.ts` into `api.models.ts`.
- Removed the unused `generateMealPlans` client method (backend route still exists, see doc 13).

## Known debt

- `ApiService` is one class with about 75 methods. Splitting it by domain (pantry, recipes, plans, shopping, family) is mechanical; keep `ApiService` as a facade so pages do not change.
- `plan-review` (about 450 lines) and `plan-setup` (about 400 lines) hold a lot of view logic; extract child components when you next touch them.
- The auth token is stored in `localStorage` and read on every request.
