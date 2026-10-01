import { ActivatedRoute, Router } from '@angular/router';
import { ApiService, MealPlan, MealPlanPreflight } from '../services/api.service';
import { MealDetailsPage } from './meal-details.page';

describe('MealDetailsPage', () => {
  let api: jasmine.SpyObj<ApiService>;
  let router: jasmine.SpyObj<Router>;
  let component: MealDetailsPage;

  const familyPlan: MealPlan = {
    id: 42,
    recipe_id: 9,
    family_id: 7,
    planned_date: '2099-08-03',
    meal_type: 'dinner',
    status: 'scheduled',
    servings: 3,
    diner_profile_ids: [31],
    recipe: { id: 9, name: 'Chicken Tinola', ingredients: [] },
  };

  beforeEach(() => {
    api = jasmine.createSpyObj<ApiService>('ApiService', [
      'mealPlan',
      'mealPlanPreflight',
      'householdProfiles',
      'addMealPlanShortagesToShoppingList',
      'cookMealPlan',
      'completeMealPlanWithoutDeduction',
    ]);
    router = jasmine.createSpyObj<Router>('Router', ['navigate', 'navigateByUrl']);
    const route = { snapshot: { paramMap: { get: () => '42' } } } as unknown as ActivatedRoute;
    component = new MealDetailsPage(api, route, router);
  });

  it('opens the exact shared pantry and provides a path back to a family meal', () => {
    component.plan = familyPlan;

    component.openPantry();

    expect(router.navigate).toHaveBeenCalledWith(['/tabs/pantry'], {
      queryParams: {
        scope: 'family',
        family_id: 7,
        return_to_meal_id: 42,
      },
    });
  });

  it('launches cooking mode for an unfinished meal', () => {
    component.plan = familyPlan;

    component.startCooking();

    expect(router.navigate).toHaveBeenCalledWith(['/cooking', 42]);
  });

  it('shows all preflight ingredient states and blocks a cook action until stock is ready', () => {
    const preflight: MealPlanPreflight = {
      meal_plan: familyPlan,
      recipe: familyPlan.recipe!,
      diners: [{ id: 31, name: 'Dad', relation: 'Father' }],
      pantry_scope: 'family',
      can_cook_from_pantry: false,
      can_mark_cooked_without_deduction: true,
      match_percentage: 50,
      ingredients: [
        { name: 'Chicken', available: true, sufficient: true, status: 'ready', required_quantity: 1, pantry_quantity: 1, unit: 'kg' },
        { name: 'Ginger', available: true, sufficient: false, status: 'low_stock', required_quantity: 2, pantry_quantity: 1, missing_quantity: 1, unit: 'thumb' },
        { name: 'Fish sauce', available: false, sufficient: false, status: 'missing', required_quantity: 1, pantry_quantity: null, missing_quantity: 1, unit: 'tbsp' },
        { name: 'Water', available: true, sufficient: false, status: 'needs_review' },
      ],
      ingredients_by_status: { ready: [], low_stock: [], missing: [], needs_review: [] },
    };
    component.preflight = preflight;

    expect(component.ingredientChecks.map(item => component.ingredientLabel(item))).toEqual([
      'Missing', 'Low stock', 'Review quantity', 'Ready',
    ]);
    component.requestCook();

    expect(component.confirmingCook).toBeFalse();
    expect(component.message).toContain('Ginger needs 1');
    expect(component.message).toContain('Fish sauce is missing');
    expect(component.ingredientStatus(preflight.ingredients[3])).toBe('medium');
  });

  it('explains the pantry impact of each swap choice', () => {
    expect(component.swapPantryLabel({ recipe: { id: 4, name: 'Mongo' }, match_percentage: 100, available_ingredients: [], missing_ingredients: [] })).toBe('Pantry ready');
    expect(component.swapPantryLabel({ recipe: { id: 5, name: 'Sinigang' }, match_percentage: 70, available_ingredients: [], missing_ingredients: [{ name: 'Tamarind', substitutes: [] }, { name: 'Pork', substitutes: [] }] })).toBe('2 ingredients to buy');
  });
});
