import { ActivatedRoute, Router } from '@angular/router';
import { of, Subject, throwError } from 'rxjs';
import {
  ApiService,
  HouseholdProfile,
  MealPlan,
  MealPlanBatchResponse,
  MealPlanIngredientStatus,
} from '../services/api.service';
import { PlanReviewPage } from './plan-review.page';
import { PantryChangeService } from '../services/pantry-change.service';

describe('PlanReviewPage', () => {
  let api: jasmine.SpyObj<ApiService>;
  let router: jasmine.SpyObj<Router>;
  let pantryChanges: jasmine.SpyObj<PantryChangeService>;
  let component: PlanReviewPage;

  const diner: HouseholdProfile = { id: 31, family_id: 7, name: 'Dad', relation: 'Father' };
  const meal: MealPlan = {
    id: 81,
    recipe_id: 14,
    family_id: 7,
    planned_date: '2099-08-03',
    meal_type: 'dinner',
    status: 'draft',
    servings: 2,
    diner_profile_ids: [diner.id],
    recipe: { id: 14, name: 'Adobong Manok', ingredients: [] },
  };
  const shortage: MealPlanIngredientStatus = {
    name: 'Chicken',
    unit: 'kg',
    required_quantity: 1,
    pantry_quantity: 0.25,
    missing_quantity: 0.75,
    status: 'low_stock',
  };

  const response = (status: 'draft' | 'saved' = 'draft'): MealPlanBatchResponse => ({
    batch: { id: 17, user_id: 1, family_id: 7, start_date: '2099-08-03', end_date: '2099-08-09', status },
    meal_plans: [meal],
    summary: { meal_count: 1, ready_count: 0, ingredients: [shortage], shortages: [shortage], needs_review: [] },
    conflicts: [],
  });

  beforeEach(() => {
    api = jasmine.createSpyObj<ApiService>('ApiService', [
      'updateMealPlanBatchMeal',
      'addMealPlanBatchShortagesToShoppingList',
      'addMealPlanBatchPurchasedItems',
      'saveMealPlanBatch',
    ]);
    router = jasmine.createSpyObj<Router>('Router', ['navigate', 'navigateByUrl']);
    pantryChanges = jasmine.createSpyObj<PantryChangeService>('PantryChangeService', ['publishAddedItems']);
    const route = { snapshot: { paramMap: { get: () => '17' } } } as unknown as ActivatedRoute;
    component = new PlanReviewPage(api, route, router, pantryChanges);
    component.batchId = 17;
    component.response = response();
    component.diners = [diner];
  });

  it('updates one draft meal with its date, recipe, diners, and servings', () => {
    api.updateMealPlanBatchMeal.and.returnValue(of(response()));
    component.openMealEditor(meal);
    component.editor!.recipeId = 15;
    component.editor!.plannedDate = '2099-08-04';
    component.editor!.servings = 3;

    component.saveMealChange();

    expect(api.updateMealPlanBatchMeal).toHaveBeenCalledWith(17, meal.id, {
      recipe_id: 15,
      planned_date: '2099-08-04',
      meal_type: 'dinner',
      servings: 3,
      diner_profile_ids: [diner.id],
    });
    expect(component.editor).toBeUndefined();
  });

  it('adds confirmed bought shortages to the pantry and uses the returned recheck', () => {
    const refreshed = response();
    refreshed.summary = { meal_count: 1, ready_count: 1, ingredients: [], shortages: [], needs_review: [] };
    api.addMealPlanBatchPurchasedItems.and.returnValue(of({ items: [{ id: 1, name: 'Chicken' }], preview: refreshed }));

    component.openPurchasedItems();
    component.savePurchasedItems();

    expect(api.addMealPlanBatchPurchasedItems).toHaveBeenCalledWith(17, [{
      name: 'Chicken', quantity: 0.75, unit: 'kg', storage_type: 'unknown',
    }]);
    expect(component.purchaseDrafts).toBeUndefined();
    expect(component.response?.summary.ready_count).toBe(1);
    expect(component.message).toBe('Added 1 purchased item to the pantry. Ingredient availability has been checked again.');
    expect(pantryChanges.publishAddedItems).toHaveBeenCalledWith([{ id: 1, name: 'Chicken' }], 7);
  });

  it('keeps purchase selections and quantities when adding purchased items fails', () => {
    api.addMealPlanBatchPurchasedItems.and.returnValue(throwError(() => ({ error: { message: 'Pantry is unavailable.' } })));
    component.openPurchasedItems();
    component.purchaseDrafts![0].quantity = 0.5;

    component.savePurchasedItems();

    expect(component.purchaseDrafts?.[0]).toEqual(jasmine.objectContaining({ name: 'Chicken', quantity: 0.5, selected: true }));
    expect(component.purchaseMessage).toBe('Pantry is unavailable.');
    expect(component.addingPurchasedItems).toBeFalse();
    expect(pantryChanges.publishAddedItems).not.toHaveBeenCalled();
  });

  it('does not submit purchased items twice while the request is running', () => {
    const pending = new Subject<{ items: never[]; preview: MealPlanBatchResponse }>();
    api.addMealPlanBatchPurchasedItems.and.returnValue(pending);
    component.openPurchasedItems();

    component.savePurchasedItems();
    component.savePurchasedItems();

    expect(api.addMealPlanBatchPurchasedItems).toHaveBeenCalledTimes(1);
    pending.complete();
  });

  it('saves with the selected conflict resolution and returns to the planner', () => {
    component.replaceConflicts = true;
    api.saveMealPlanBatch.and.returnValue(of(response('saved')));

    component.savePlan();

    expect(api.saveMealPlanBatch).toHaveBeenCalledWith(17, { conflict_action: 'replace_conflicting' });
    expect(router.navigate).toHaveBeenCalledWith(['/tabs/meal-plan'], jasmine.objectContaining({
      replaceUrl: true,
      queryParams: jasmine.objectContaining({ refresh: jasmine.any(Number) }),
    }));
  });

  it('falls back to the planner URL when the primary navigation is rejected', async () => {
    component.replaceConflicts = false;
    api.saveMealPlanBatch.and.returnValue(of(response('saved')));
    router.navigate.and.returnValue(Promise.resolve(false));

    component.savePlan();
    await Promise.resolve();

    expect(router.navigateByUrl).toHaveBeenCalledWith('/tabs/meal-plan', { replaceUrl: true });
  });

  it('adds shortages to the shopping list only once during a review', () => {
    api.addMealPlanBatchShortagesToShoppingList.and.returnValue(of({ items: [], message: 'Added.' }));

    component.addShortagesToList();
    component.addShortagesToList();

    expect(api.addMealPlanBatchShortagesToShoppingList).toHaveBeenCalledTimes(1);
    expect(component.shortagesAdded).toBeTrue();
    expect(component.message).toBe('Added.');
  });
});
