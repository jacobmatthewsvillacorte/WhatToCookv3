import { Component } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService, HouseholdProfile, MealPlan, MealPlanIngredientCheck, MealPlanPreflight, Recommendation } from '../services/api.service';

@Component({
  selector: 'app-meal-details',
  templateUrl: './meal-details.page.html',
  styleUrls: ['./meal-details.page.scss'],
  standalone: false,
})
export class MealDetailsPage {
  plan?: MealPlan;
  preflight?: MealPlanPreflight;
  diners: Array<Pick<HouseholdProfile, 'id' | 'name' | 'relation'>> = [];
  loading = false;
  checking = false;
  actionPending = false;
  addingToList = false;
  confirmingCook = false;
  confirmingWithoutDeduction = false;
  showSwap = false;
  swapRecipes: Recommendation[] = [];
  selectedSwapRecipeId?: number;
  swapping = false;
  showExtra = false;
  extraName = '';
  extraQuantity?: number;
  extraUnit = '';
  addingExtra = false;
  message = '';

  readonly cookAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Cook & deduct pantry', role: 'confirm', handler: () => this.cookFromPantry() },
  ];
  readonly withoutDeductionAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Mark cooked', role: 'confirm', handler: () => this.markCookedWithoutDeduction() },
  ];

  constructor(private api: ApiService, private route: ActivatedRoute, private router: Router) {}

  ionViewWillEnter(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!Number.isInteger(id) || id < 1) {
      this.router.navigateByUrl('/tabs/meal-plan', { replaceUrl: true });
      return;
    }
    this.load(id);
  }

  get isCompleted(): boolean {
    return !!this.plan?.completed_at;
  }

  get ingredientChecks(): MealPlanIngredientCheck[] {
    return [...(this.preflight?.ingredients || [])]
      .sort((left, right) => Number(left.sufficient) - Number(right.sufficient) || left.name.localeCompare(right.name));
  }

  get dinerNames(): string {
    if (!this.plan?.family_id) return 'Personal profile';
    const names = this.diners
      .filter(diner => (this.plan?.diner_profile_ids || []).includes(diner.id))
      .map(diner => diner.name);
    return names.length ? names.join(', ') : 'No diners selected';
  }

  get pantryReadinessMessage(): string {
    const items = this.ingredientChecks.filter(item => item.status !== 'ready');
    if (!items.length) return 'All required ingredients are ready in the pantry.';
    return `Not ready: ${items.map(item => item.status === 'needs_review' ? `${item.name} needs review` : `${item.name} ${item.status === 'low_stock' ? `needs ${item.missing_quantity ?? 'more'}` : 'is missing'}`).join('; ')}.`;
  }

  load(planId = this.plan?.id): void {
    if (!planId) return;
    this.loading = true;
    this.message = '';
    this.preflight = undefined;
    this.api.mealPlan(planId).subscribe({
      next: plan => {
        this.plan = plan;
        this.loadDiners(plan);
        this.loadSwapOptions(plan);
        if (plan.completed_at) {
          this.loading = false;
          return;
        }
        this.checkIngredients();
      },
      error: error => {
        this.loading = false;
        this.message = this.errorMessage(error, 'Could not load this planned meal.');
      },
    });
  }

  checkIngredients(): void {
    if (!this.plan || this.isCompleted) return;
    this.checking = true;
    this.api.mealPlanPreflight(this.plan.id).subscribe({
      next: preflight => {
        this.preflight = preflight;
        this.plan = { ...preflight.meal_plan, recipe: preflight.recipe };
        this.diners = preflight.diners || this.diners;
        this.checking = false;
        this.loading = false;
      },
      error: error => {
        this.checking = false;
        this.loading = false;
        this.message = this.errorMessage(error, 'Could not check pantry stock for this meal.');
      },
    });
  }

  addShortagesToList(): void {
    if (!this.plan || this.addingToList || !this.preflight?.ingredients_by_status.low_stock.length && !this.preflight?.ingredients_by_status.missing.length) return;
    this.addingToList = true;
    this.message = '';
    this.api.addMealPlanShortagesToShoppingList(this.plan.id).subscribe({
      next: result => {
        this.addingToList = false;
        this.message = result.message || 'Missing ingredients were added to the shopping list.';
      },
      error: error => {
        this.addingToList = false;
        this.message = this.errorMessage(error, 'Could not add missing ingredients to the shopping list.');
      },
    });
  }

  openPantry(): void {
    if (!this.plan) return;
    this.router.navigate(['/tabs/pantry'], {
      queryParams: {
        scope: this.plan.family_id ? 'family' : 'personal',
        family_id: this.plan.family_id || undefined,
        return_to_meal_id: this.plan.id,
      },
    });
  }

  editInPlanner(): void {
    this.router.navigateByUrl('/tabs/meal-plan');
  }

  startCooking(): void {
    if (this.plan && !this.isCompleted) this.router.navigate(['/cooking', this.plan.id]);
  }

  openSwap(): void {
    if (!this.plan || this.isCompleted) return;
    this.showSwap = true;
    this.selectedSwapRecipeId = this.plan.recipe_id;
  }

  swapMeal(): void {
    if (!this.plan || !this.selectedSwapRecipeId || this.selectedSwapRecipeId === this.plan.recipe_id) {
      this.showSwap = false;
      return;
    }
    this.swapping = true;
    this.api.updateMealPlan(this.plan.id, { recipe_id: this.selectedSwapRecipeId }).subscribe({
      next: () => { this.swapping = false; this.showSwap = false; this.load(this.plan?.id); },
      error: error => { this.swapping = false; this.message = this.errorMessage(error, 'Could not swap this meal.'); },
    });
  }

  addExtraToList(): void {
    if (!this.plan || !this.extraName.trim() || !this.extraQuantity || this.extraQuantity <= 0 || !this.extraUnit.trim()) return;
    this.addingExtra = true;
    this.api.addShoppingItem({ ingredient_name: this.extraName.trim(), quantity: String(this.extraQuantity), unit: this.extraUnit.trim(), family_id: this.plan.family_id || null, is_purchased: false }).subscribe({
      next: () => { this.addingExtra = false; this.showExtra = false; this.extraName = ''; this.extraQuantity = undefined; this.extraUnit = ''; this.message = 'Extra ingredient added to the matching shopping list.'; },
      error: error => { this.addingExtra = false; this.message = this.errorMessage(error, 'Could not add the extra ingredient.'); },
    });
  }

  requestCook(): void {
    if (this.actionPending) return;
    if (!this.preflight?.can_cook_from_pantry) {
      this.message = `${this.pantryReadinessMessage} Add or update the ingredients before deducting pantry stock.`;
      return;
    }
    this.confirmingCook = true;
  }

  requestMarkCookedWithoutDeduction(): void {
    if (this.isCompleted || this.actionPending) return;
    this.confirmingWithoutDeduction = true;
  }

  cookFromPantry(): void {
    if (!this.plan || this.actionPending) return;
    this.confirmingCook = false;
    this.actionPending = true;
    this.message = '';
    this.api.cookMealPlan(this.plan.id).subscribe({
      next: result => {
        this.plan = result.meal_plan;
        this.preflight = undefined;
        this.actionPending = false;
        this.message = result.message || 'Meal cooked and pantry stock updated.';
      },
      error: error => {
        this.actionPending = false;
        this.message = this.errorMessage(error, 'Could not cook this meal. Check the ingredient status and try again.');
        this.checkIngredients();
      },
    });
  }

  markCookedWithoutDeduction(): void {
    if (!this.plan || this.actionPending) return;
    this.confirmingWithoutDeduction = false;
    this.actionPending = true;
    this.message = '';
    this.api.completeMealPlanWithoutDeduction(this.plan.id).subscribe({
      next: result => {
        this.plan = result.meal_plan;
        this.preflight = undefined;
        this.actionPending = false;
        this.message = result.message || 'Meal marked as cooked; pantry stock was not changed.';
      },
      error: error => {
        this.actionPending = false;
        this.message = this.errorMessage(error, 'Could not mark this meal as cooked.');
      },
    });
  }

  ingredientStatus(check: MealPlanIngredientCheck): 'success' | 'warning' | 'danger' | 'medium' {
    switch (check.status) {
      case 'ready': return 'success';
      case 'low_stock': return 'warning';
      case 'needs_review': return 'medium';
      default: return 'danger';
    }
  }

  ingredientLabel(check: MealPlanIngredientCheck): string {
    switch (check.status) {
      case 'ready': return 'Ready';
      case 'low_stock': return 'Low stock';
      case 'needs_review': return 'Review quantity';
      default: return 'Missing';
    }
  }

  ingredientDetail(check: MealPlanIngredientCheck): string {
    const unit = check.unit ? ` ${check.unit}` : '';
    if (check.required_quantity === null || check.required_quantity === undefined) {
      return check.available ? 'In the pantry; check the recipe quantity before cooking.' : 'Check the recipe quantity before cooking.';
    }
    const pantry = check.pantry_quantity ?? 0;
    const missing = check.missing_quantity ?? 0;
    if (check.sufficient) return `Need ${check.required_quantity}${unit}; ${pantry}${unit} is available.`;
    return `Need ${check.required_quantity}${unit}; ${pantry}${unit} available; ${missing}${unit} still needed.`;
  }

  dateLabel(value?: string): string {
    if (!value) return '';
    const date = new Date(`${value.slice(0, 10)}T12:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  }

  mealTypeLabel(value?: string): string {
    return value ? `${value.charAt(0).toUpperCase()}${value.slice(1)}` : 'Meal';
  }

  swapPantryLabel(item: Recommendation): string {
    const missing = item.missing_ingredients?.length || 0;
    const review = item.needs_review_ingredients?.length || 0;
    if (!missing && !review) return 'Pantry ready';
    return missing ? `${missing} ingredient${missing === 1 ? '' : 's'} to buy` : 'Check pantry quantity';
  }

  private loadDiners(plan: MealPlan): void {
    this.diners = [];
    if (!plan.family_id) return;
    this.api.householdProfiles(plan.family_id).subscribe({
      next: result => this.diners = result.household_profiles,
      error: () => this.diners = [],
    });
  }

  private loadSwapOptions(plan: MealPlan): void {
    this.swapRecipes = [];
    this.api.recommendations(plan.family_id || undefined).subscribe({
      next: result => this.swapRecipes = result.recommendations.filter(item => item.recipe.id !== plan.recipe_id),
      error: () => this.swapRecipes = [],
    });
  }

  private errorMessage(error: { error?: { message?: string; errors?: Record<string, string[]> } }, fallback: string): string {
    const validationMessage = error?.error?.errors
      ? Object.values(error.error.errors).reduce<string[]>((messages, current) => messages.concat(current), [])[0]
      : undefined;
    return validationMessage || error?.error?.message || fallback;
  }
}
